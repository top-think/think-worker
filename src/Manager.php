<?php

namespace think\worker;

use InvalidArgumentException;
use think\worker\concerns\InteractsWithConduit;
use think\worker\concerns\InteractsWithHttp;
use think\worker\concerns\InteractsWithQueue;
use think\worker\concerns\InteractsWithServer;
use think\worker\concerns\WithApplication;
use think\worker\concerns\WithContainer;

class Manager
{
    use InteractsWithServer,
        InteractsWithHttp,
        InteractsWithQueue,
        InteractsWithConduit,
        WithApplication,
        WithContainer;

    /**
     * Linux 下创建全部 worker（单文件多 worker + fork）。
     */
    protected function initialize(): void
    {
        $this->prepareHttp();
        $this->prepareQueue();
        $this->prepareConduit();
    }

    /**
     * 返回本轮应启用的 worker 类型列表。
     */
    public function workerTypes(): array
    {
        $types = [];

        if ($this->getConfig('http.enable', true)) {
            $types[] = 'http';
        }

        if ($this->getConfig('conduit.enable', true)) {
            $types[] = 'conduit';
        }

        if ($this->getConfig('queue.enable', false)) {
            foreach (array_keys($this->getConfig('queue.workers', [])) as $queue) {
                $types[] = 'queue:' . $queue;
            }
        }

        if ($this->getConfig('hot_update.enable', false)) {
            $types[] = 'hot_update';
        }

        return $types;
    }

    /**
     * 只创建指定类型的单个 worker，供 Windows 独立启动文件使用。
     */
    public function prepareWorker(string $type): void
    {
        $this->prepareIpc();

        if ($type !== 'conduit') {
            $this->prepareConduitClient();
        }

        switch ($type) {
            case 'http':
                $this->prepareHttp();
                break;
            case 'conduit':
                $this->prepareConduitServer();
                break;
            case 'hot_update':
                $this->addHotUpdateWorker();
                break;
            default:
                if (str_starts_with($type, 'queue:')) {
                    $this->prepareQueue(substr($type, 6));
                } else {
                    throw new InvalidArgumentException("Unknown worker type: {$type}");
                }
        }
    }
}