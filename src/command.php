<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace think;

Console::addDefaultCommands([
    '\\think\\worker\\command\\GatewayWorker',
    '\\think\\worker\\command\\Server',
    '\\think\\worker\\command\\Worker',
]);

Facade::bind([
    worker\facade\Application::class => worker\Application::class,
    worker\facade\Http::class        => worker\Http::class,
]);

// 指定日志类驱动
Loader::addClassMap([
    'think\\log\\driver\\File' => __DIR__ . '/log/File.php',
]);
