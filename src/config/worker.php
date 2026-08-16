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
        'enable'            => true,
        'public_path'       => root_path('public'),
        // 禁止作为静态资源返回的扩展名，其余 public 下的文件均可直接访问
        'forbid_extensions' => ['php', 'sql', 'sqlite', 'db', 'env', 'ini', 'log', 'bak', 'sh', 'bat', 'htaccess', 'config'],
        // public 下已存在的独立 php 脚本（如 install.php 安装向导）按 FPM 方式执行，与 php-fpm 部署行为一致
        'public_scripts'    => true,
    ],
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        'include' => [app_path(), config_path(), root_path('route')],
        'exclude' => [],
    ],
];
