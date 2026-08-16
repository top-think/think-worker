<?php

namespace think\worker\conduit\driver\socket;

use think\worker\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Frame;

class Server
{
    protected $data = [];

    /** @var array<string,TcpConnection[]> */
    protected $subscribers = [];

    public function onMessage(TcpConnection $connection, $buffer)
    {
        if (empty($buffer)) {
            return;
        }
        /** @var Command $command */
        $command = unserialize($buffer);

        $result = Result::create($command->id);

        switch ($command->name) {
            case 'get':
                $result->data = $this->data[$command->key] ?? null;
                break;
            case 'set':
                $this->data[$command->key] = $command->data;
                break;
            case 'inc':
                if (!isset($this->data[$command->key]) || !is_integer($this->data[$command->key])) {
                    $this->data[$command->key] = 0;
                }
                $result->data = $this->data[$command->key] += $command->data ?? 1;
                break;
            case 'sAdd':
                if (!isset($this->data[$command->key]) || !is_array($this->data[$command->key])) {
                    $this->data[$command->key] = [];
                }
                $this->data[$command->key] = array_merge($this->data[$command->key], $command->data);
                break;
            case 'sRem':
                if (!isset($this->data[$command->key]) || !is_array($this->data[$command->key])) {
                    $this->data[$command->key] = [];
                }
                $this->data[$command->key] = array_diff($this->data[$command->key], [$command->data]);
                break;
            case 'sMembers':
                if (!isset($this->data[$command->key]) || !is_array($this->data[$command->key])) {
                    $this->data[$command->key] = [];
                }
                $result->data = $this->data[$command->key];
                break;
            case 'subscribe':
                if (!isset($this->subscribers[$command->key])) {
                    $this->subscribers[$command->key] = [];
                }
                $this->subscribers[$command->key][] = $connection;
                break;
            case 'publish':
                if (!empty($this->subscribers[$command->key])) {
                    foreach ($this->subscribers[$command->key] as $conn) {
                        $conn->send(serialize(Event::create($command->key, $command->data)));
                    }
                }
                break;
        }

        if (isset($result->id)) {
            $connection->send(serialize($result));
        }
    }

    public function onClose(TcpConnection $connection)
    {
        if (!empty($this->subscribers)) {
            foreach ($this->subscribers as $key => $connections) {
                $this->subscribers[$key] = array_udiff($connections, [$connection], function ($a, $b) {
                    return $a <=> $b;
                });
            }
        }
    }

    public static function run($domain)
    {
        //启动服务端
        $server = new self();

        $worker = new Worker($domain);

        $worker->name       = 'conduit';
        $worker->protocol   = Frame::class;
        $worker->reloadable = false;

        $worker->onMessage = [$server, 'onMessage'];
        $worker->onClose   = [$server, 'onClose'];

        return $worker;
    }
}
