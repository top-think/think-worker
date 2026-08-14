<?php

namespace think\worker\concerns;

use think\helper\Arr;
use think\queue\event\JobFailed;
use think\queue\Worker;
use Workerman\Timer;

trait InteractsWithQueue
{
    protected function createQueueWorkers()
    {
        $workers = $this->getConfig('queue.workers', []);

        foreach ($workers as $queue => $options) {
            $this->createQueueWorker($queue, $options);
        }
    }

    protected function createQueueWorker(string $queue, array $options = [])
    {
        if (strpos($queue, '@') !== false) {
            [$queue, $connection] = explode('@', $queue);
        } else {
            $connection = null;
        }

        $workerNum = Arr::get($options, 'worker_num', 1);

        $this->addWorker(function () use ($options, $connection, $queue) {
            $delay   = Arr::get($options, 'delay', 0);
            $sleep   = Arr::get($options, 'sleep', 3);
            $tries   = Arr::get($options, 'tries', 0);
            $timeout = Arr::get($options, 'timeout', 60);
            $qWorker = $this->app->make(Worker::class);

            if ($this->supportsAsyncSignals()) {
                pcntl_signal(SIGALRM, function () {
                    \think\worker\Worker::stopAll();
                });

                pcntl_alarm($timeout);
            }

            $this->createRunTimer(function () use ($connection, $queue, $delay, $sleep, $tries, $qWorker) {
                $this->runInSandbox(function () use ($connection, $queue, $delay, $sleep, $tries, $qWorker) {
                    $qWorker->runNextJob($connection, $queue, $delay, $sleep, $tries);
                });
                if ($this->supportsAsyncSignals()) {
                    pcntl_alarm(0);
                }
            });
        }, "queue [$queue]", $workerNum);
    }

    protected function supportsAsyncSignals()
    {
        return extension_loaded('pcntl');
    }

    protected function createRunTimer($func)
    {
        Timer::add(0.1, function () use ($func) {
            $func();
            $this->createRunTimer($func);
        }, [], false);
    }

    protected function prepareQueue(?string $key = null)
    {
        if (!$this->getConfig('queue.enable', false)) {
            return;
        }

        $this->listenForEvents();

        $workers = $this->getConfig('queue.workers', []);

        if ($key !== null) {
            $workers = isset($workers[$key]) ? [$key => $workers[$key]] : [];
        }

        foreach ($workers as $queue => $options) {
            $this->createQueueWorker($queue, $options);
        }
    }

    /**
     * 注册事件
     */
    protected function listenForEvents()
    {
        $this->container->event->listen(JobFailed::class, function (JobFailed $event) {
            $this->logFailedJob($event);
        });
    }

    /**
     * 记录失败任务
     * @param JobFailed $event
     */
    protected function logFailedJob(JobFailed $event)
    {
        $this->container['queue.failer']->log(
            $event->connection,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception
        );
    }
}
