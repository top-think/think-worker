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

use think\worker\websocket\Handler;

return [
    'http'       => [
        'enable'     => true,
        'host'       => '0.0.0.0',
        'port'       => 8080,
        'worker_num' => 4,
        'options'    => [],
    ],
    'websocket'  => [
        'enable'        => false,
        'handler'       => Handler::class,
        'ping_interval' => 25000,
        'ping_timeout'  => 60000,
    ],
    //队列
    'queue'      => [
        'enable'  => false,
        'workers' => [],
    ],
    //进程间通信
    'conduit'    => [
        'enable' => true,
        'host'   => '127.0.0.1',
        'port'   => 9999,
    ],
    //静态文件
    'static'     => [
        'enable'      => true,
        'public_path' => root_path('public'),
        'extensions'  => ['css', 'js', 'html', 'htm', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'map', 'webp', 'txt'],
    ],
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        'include' => [app_path(), config_path(), root_path('route')],
        'exclude' => [],
    ],
];
