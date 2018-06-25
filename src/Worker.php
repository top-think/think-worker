<?php
namespace think\swoole;

use think\worker\Application;
use think\worker\Server;
use Workerman\Worker;

class Worker extends Server
{
    protected $app;

    /**
     * 架构函数
     * @access public
     */
    public function __construct($host, $port)
    {
        $this->worker = new Worker('http://' . $host . ':' . $port);

        // 设置回调
        foreach ($this->event as $event) {
            if (method_exists($this, $event)) {
                $this->worker->$event = [$this, $event];
            }
        }
    }

    public function option(array $option)
    {
        // 设置参数
        if (!empty($option)) {
            foreach ($option as $key => $val) {
                $this->worker->$key = $val;
            }
        }
    }

    public function onWorkerStart($worker)
    {
        $this->app = new Application;
        $this->app->initialize();
    }

    public function onMessage($connection, $data)
    {
        $this->app->worker($connection, $data);
    }

    public function start()
    {
        Worker::runAll();
    }

    public function stop()
    {
        Worker::stopAll();
    }    
}
