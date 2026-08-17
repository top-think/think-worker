<?php

namespace think\worker;

class Worker extends \Workerman\Worker
{
    protected static function init(): void
    {
        static::$pidFile    = runtime_path() . 'worker.pid';
        static::$statusFile = runtime_path() . 'worker.status';
        static::$logFile    = runtime_path() . 'worker.log';
        parent::init();
    }
}
