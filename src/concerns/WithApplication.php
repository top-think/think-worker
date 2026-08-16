<?php

namespace think\worker\concerns;

use Closure;
use think\App;
use think\worker\App as WorkerApp;
use think\worker\Manager;
use think\worker\Request as WorkerRequest;
use think\worker\Sandbox;
use Throwable;

/**
 * Trait WithApplication
 * @property App $container
 */
trait WithApplication
{
    /**
     * @var WorkerApp|null
     */
    protected $app = null;

    protected function prepareApplication()
    {
        if (!$this->app instanceof WorkerApp) {
            $this->app = new WorkerApp($this->container->getRootPath());

            $this->app->bind(WorkerApp::class, App::class);
            $this->app->bind(Manager::class, $this);

            // 上传文件包装为 worker 版 UploadedFile，move()/putFile() 在 Workerman 下可用
            $this->app->bind('request', WorkerRequest::class);

            $this->app->initialize();
            $this->app->instance('request', $this->container->request);
            $this->prepareConcretes();
        }
    }

    /**
     * 预加载
     */
    protected function prepareConcretes()
    {
        $defaultConcretes = ['db', 'cache', 'event'];

        $concretes = array_merge($defaultConcretes, $this->getConfig('concretes', []));

        foreach ($concretes as $concrete) {
            $this->app->make($concrete);
        }
    }

    public function getApp()
    {
        return $this->app;
    }

    /**
     * 获取沙箱
     * @return Sandbox
     */
    protected function getSandbox()
    {
        return $this->app->make(Sandbox::class);
    }

    /**
     * 在沙箱中执行
     * @param Closure $callable
     */
    public function runInSandbox(Closure $callable, ?object $key = null)
    {
        // PHP 8.5 起 Reflection::setAccessible() 被弃用，think-container 与沙箱所用反射若被
        // ThinkPHP 错误处理器升格为异常会导致请求中断，这里在请求处理期间屏蔽 E_DEPRECATED。
        $errorReporting = error_reporting();
        error_reporting($errorReporting & ~E_DEPRECATED);

        try {
            $this->getSandbox()->run($callable, $key);
        } catch (Throwable $e) {
            $this->logServerError($e);
        } finally {
            error_reporting($errorReporting);
        }
    }
}
