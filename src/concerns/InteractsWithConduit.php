<?php

namespace think\worker\concerns;

use think\worker\Conduit;

trait InteractsWithConduit
{
    /** @var Conduit */
    protected $conduit;

    /**
     * Linux 下：建立 conduit 客户端并注册 conduit server worker。
     */
    protected function prepareConduit(): void
    {
        $this->prepareConduitClient();
        $this->conduit->prepare();
    }

    /**
     * 建立 conduit 客户端（每个 worker 进程都需要）。
     */
    protected function prepareConduitClient(): void
    {
        $this->conduit = $this->container->make(Conduit::class);
        $this->onEvent('workerStart', function () {
            $this->app->instance(Conduit::class, $this->conduit);
        });
    }

    /**
     * 注册 conduit server worker（仅 conduit 独立进程需要）。
     */
    protected function prepareConduitServer(): void
    {
        $this->conduit = $this->container->make(Conduit::class);
        $worker = $this->conduit->prepare();

        // Windows 下检测 master 存活：conduit worker 不经 addWorker 创建，
        // 缺失该检测时 master 被强杀后 conduit 进程会成为孤儿并一直占用端口
        if (DIRECTORY_SEPARATOR !== '/' && $worker instanceof \think\worker\Worker) {
            $worker->onWorkerStart = function () {
                $this->watchMasterAlive();
            };
        }
    }
}