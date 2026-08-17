<?php

namespace think\worker\conduit\driver;

use Exception;
use Fiber;
use think\worker\conduit\Driver;
use think\worker\conduit\driver\socket\Command;
use think\worker\conduit\driver\socket\Event;
use think\worker\conduit\driver\socket\Result;
use think\worker\conduit\driver\socket\Server;
use think\worker\Manager;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Frame;
use Workerman\Timer;

class Socket extends Driver
{
    protected $id = 0;
    protected $domain;

    /** @var AsyncTcpConnection|null */
    protected $connection   = null;
    protected $reconnectTimer;
    protected $pingInterval = 55;

    /** @var array<int, array{0: Fiber, 1: int}> */
    protected $suspenders = [];
    protected $events     = [];

    public function __construct(protected Manager $manager)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            $filename = runtime_path() . 'conduit.sock';
            @unlink($filename);
            $this->domain = "unix://{$filename}";
        } else {
            $host = $this->manager->getConfig('conduit.host', '127.0.0.1');
            $port = (int) $this->manager->getConfig('conduit.port', 9999);
            $this->domain = "tcp://{$host}:{$port}";
        }
    }

    public function prepare()
    {
        //启动服务端
        return Server::run($this->domain);
    }

    public function connect()
    {
        $fiber            = $this->currentFiber();
        $this->connection = $this->createConnection($fiber);
        Fiber::suspend();

        Timer::add($this->pingInterval, function () {
            if ($this->connection) {
                $this->connection->send('');
            }
        });

        Timer::add(1, function () {
            //检查是否超时
            foreach ($this->suspenders as $id => $suspender) {
                if (time() - $suspender[1] > 10) {
                    $suspender[0]->throw(new Exception('conduit connection is timeout'));
                    unset($this->suspenders[$id]);
                }
            }
        });
    }

    public function get(string $name)
    {
        return $this->sendAndRecv(Command::create('get', $name));
    }

    public function set(string $name, $value)
    {
        $this->send(Command::create('set', $name, $value));
    }

    public function inc(string $name, int $step = 1)
    {
        return $this->sendAndRecv(Command::create('inc', $name, $step));
    }

    public function sAdd(string $name, ...$value)
    {
        $this->send(Command::create('sAdd', $name, $value));
    }

    public function sRem(string $name, $value)
    {
        $this->send(Command::create('sRem', $name, $value));
    }

    public function sMembers(string $name)
    {
        return $this->sendAndRecv(Command::create('sMembers', $name));
    }

    public function publish(string $name, $value)
    {
        $this->send(Command::create('publish', $name, $value));
    }

    public function subscribe(string $name, $callback)
    {
        $this->send(Command::create('subscribe', $name));
        $this->events[$name] = $callback;
    }

    protected function sendAndRecv(Command $command)
    {
        $fiber = $this->currentFiber();

        $id = $this->id++;

        $command->id = $id;

        $this->suspenders[$id] = [$fiber, time()];

        $this->send($command);

        return Fiber::suspend();
    }

    protected function send(Command $command)
    {
        if (!$this->connection) {
            throw new Exception('conduit connection is disconnected');
        }

        $this->connection->send(serialize($command));
    }

    protected function createConnection(?Fiber $fiber = null)
    {
        $connection = new AsyncTcpConnection($this->domain);

        $connection->protocol = Frame::class;

        $connection->onConnect = function () use ($fiber) {
            $this->clearTimer();
            if ($fiber) {
                $fiber->resume();
            }
            //补订阅
            foreach ($this->events as $name => $callback) {
                $this->send(Command::create('subscribe', $name));
            }
        };

        $connection->onMessage = function ($connection, $buffer) {
            /** @var Result|Event $result */
            $result = unserialize($buffer);

            if ($result instanceof Event) {
                if (isset($this->events[$result->name])) {
                    $this->events[$result->name]($result->data);
                }
            } elseif (isset($result->id) && isset($this->suspenders[$result->id])) {
                [$fiber] = $this->suspenders[$result->id];
                $fiber->resume($result->data);
                unset($this->suspenders[$result->id]);
            }
        };

        $connection->onClose = function () {
            $this->connection = null;
            //重连
            $this->clearTimer();
            $this->reconnectTimer = Timer::add(1, function () {
                $this->connection = $this->createConnection();
            });
        };

        $connection->connect();

        return $connection;
    }

    protected function currentFiber(): Fiber
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new Exception('conduit must be used inside a coroutine/fiber');
        }
        return $fiber;
    }

    protected function clearTimer()
    {
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
    }
}