<?php
namespace think\worker;

use Workerman\Worker as WorkerServer;

class Worker extends Server
{
    protected $app;

    /**
     * 架构函数
     * @access public
     */
    public function __construct($host, $port)
    {
        $this->worker = new WorkerServer('http://' . $host . ':' . $port);

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
        WorkerServer::runAll();
    }

    public function stop()
    {
        WorkerServer::stopAll();
    }
}
