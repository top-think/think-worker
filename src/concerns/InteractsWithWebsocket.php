<?php

namespace think\worker\concerns;

use think\App;
use think\Event;
use think\helper\Arr;
use think\Http;
use think\worker\message\PushMessage;
use think\worker\Websocket;
use think\worker\contract\websocket\HandlerInterface;
use think\worker\websocket\Frame;
use think\worker\websocket\Handler;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkerRequest;
use Workerman\Protocols\Http\Response;

trait InteractsWithWebsocket
{

    protected $messageSender = [];

    protected function prepareWebsocket()
    {
        $this->onEvent('workerStart', function () {
            $handlerClass = $this->getConfig('websocket.handler', Handler::class);
            $this->app->bind(HandlerInterface::class, $handlerClass);

            $this->onEvent('message', function ($message) {
                if ($message instanceof PushMessage) {
                    if (isset($this->messageSender[$message->to])) {
                        $this->messageSender[$message->to]($message->data);
                    }
                }
            });
        });
    }

    public function onHandShake(TcpConnection $connection, WorkerRequest $wkRequest)
    {
        $this->runInSandbox(function (App $app, Http $http, Event $event) use ($connection, $wkRequest) {
            $request = $this->prepareRequest($wkRequest, $connection);

            $response = $http->run($request);
            if (!$response instanceof \think\worker\response\Websocket) {
                $connection->close();
                return;
            }

            $event->subscribe([$response]);
            $this->upgrade($connection, $wkRequest);

            $websocket = $app->make(Websocket::class, [$connection], true);
            $app->instance(Websocket::class, $websocket);

            $id = "{$this->workerId}.{$connection->id}";

            $websocket->setSender($id);
            $websocket->join($id);

            $handler = $app->make(HandlerInterface::class);

            $this->messageSender[$connection->id] = function ($data) use ($connection, $handler) {
                $connection->send($handler->encodeMessage($data));
            };

            try {
                $handler->onOpen($request);
            } catch (Throwable $e) {
                $this->logServerError($e);
            }
        }, $connection);
    }

    public function onMessage(TcpConnection $connection, Frame $frame)
    {
        $this->runInSandbox(function (App $app) use ($frame) {
            $handler = $app->make(HandlerInterface::class);
            try {
                $handler->onMessage($frame);
            } catch (Throwable $e) {
                $this->logServerError($e);
            }
        }, $connection);
    }

    public function onClose(TcpConnection $connection)
    {
        $this->runInSandbox(function (App $app) use ($connection) {
            if ($app->exists(Websocket::class)) {
                $websocket = $app->make(Websocket::class);
                $handler   = $app->make(HandlerInterface::class);
                try {
                    $handler->onClose();
                } catch (Throwable $e) {
                    $this->logServerError($e);
                }

                // leave all rooms
                $websocket->leave();

                unset($this->messageSender[$connection->id]);

                $websocket->setConnected(false);
            }
        }, $connection);
    }

    protected function isWebsocketRequest(WorkerRequest $request)
    {
        $header = $request->header();
        return strcasecmp(Arr::get($header, 'connection', ''), 'upgrade') === 0 &&
            strcasecmp(Arr::get($header, 'upgrade', ''), 'websocket') === 0;
    }

    protected function upgrade(TcpConnection $connection, WorkerRequest $request)
    {
        $key = $request->header('Sec-WebSocket-Key');

        $headers = [
            'Upgrade'               => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Accept'  => base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ];

        if ($protocol = $request->header('Sec-Websocket-Protocol')) {
            $headers['Sec-WebSocket-Protocol'] = $protocol;
        }

        $response = new Response(101, $headers);

        $connection->send($response);

        // Websocket data buffer.
        $connection->context->websocketDataBuffer = '';
        // Current websocket frame length.
        $connection->context->websocketCurrentFrameLength = 0;
        // Current websocket frame data.
        $connection->context->websocketCurrentFrameBuffer = '';

        $connection->context->websocketHandshake = true;
    }
}
