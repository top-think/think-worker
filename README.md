ThinkPHP Workerman 扩展
===============

交流群：981069000 [![点击加群](https://pub.idqqimg.com/wpa/images/group.png "点击加群")](https://qm.qq.com/q/A8YNpzrzC8)

## 安装
```
composer require topthink/think-worker
```

## 说明

本扩展基于 Workerman 5，支持 Linux 与 Windows 平台。

## 使用方法

### 启动与控制

~~~bash
php think worker                # 前台启动（默认 action 为 start）
php think worker start -d       # 守护进程启动
php think worker stop           # 停止（-g 优雅停止，等待请求处理完成）
php think worker restart -d     # 重启
php think worker reload         # 平滑重启（重载业务代码，不断开连接；-g 优雅模式）
php think worker status         # 查看进程状态（-d 实时刷新）
php think worker connections    # 查看当前连接
~~~

守护进程、停止、平滑重启、状态查询依赖 Linux 信号机制，**仅 Linux 下可用**；Windows 下仅支持 `start`。

启动后通过浏览器直接访问当前应用：

~~~
http://localhost:8080
~~~

### 调试输出

控制器或路由中使用 `echo`、`var_dump` 等输出时，内容会**打印到命令行窗口**（不混入 HTTP 响应体），与 webman 行为一致。`dump()` 函数同样输出到命令行。

支持的特性：

- `ETag` / `Last-Modified` 协商缓存（304）
- `Range` 分段请求（206 / 416）
- MIME 类型按扩展名映射，未知类型回退 `finfo` 检测
- 路径穿越防护（`../`、`..%2F`、`\0` 等返回 404）
- 点文件（`.htaccess`、`.git` 等）拒绝访问
- 敏感扩展名黑名单（`php`、`sql`、`env`、`ini`、`log` 等）不作为静态资源返回

在 `config/worker.php` 中配置：

```php
'static' => [
    'enable'            => true,
    'public_path'       => root_path('public'),
    'forbid_extensions' => ['php', 'sql', 'sqlite', 'db', 'env', 'ini', 'log', 'bak', 'sh', 'bat', 'htaccess', 'config'],
    // public 下已存在的独立 php 脚本按 FPM 方式执行
    'public_scripts'    => true,
],
```

文件不存在或命中黑名单时交给应用路由处理。

### 文件上传

兼容 Workerman 的上传机制，无需额外处理。`think\Request` 已自动绑定为 `think\worker\Request`，`move()` 等方法在 worker 模式下可用，单文件与多文件上传均支持。

### 热更新

```php
'hot_update' => [
    'enable'  => env('APP_DEBUG', false),
    'name'    => ['*.php'],
    'include' => [app_path(), config_path(), root_path('route')],
    'exclude' => [],
],
```

开启后修改 `include` 目录内的 PHP 文件会自动重载（增删改均触发）。

### 队列支持

使用方法见 [think-queue](https://github.com/top-think/think-queue)

以下配置代替think-queue里的最后一步:`监听任务并执行`,无需另外起进程执行队列

```php
return [
    // ...
    'queue'      => [
        'enable'  => true,
        //键名是队列名称
        'workers' => [
            //下面参数是不设置时的默认配置
            'default'            => [
                'delay'      => 0,
                'sleep'      => 3,
                'tries'      => 0,
                'timeout'    => 60,
                'worker_num' => 1,
            ],
            //使用@符号后面可指定队列使用驱动
            'default@connection' => [
                //此处可不设置任何参数，使用上面的默认配置
            ],
        ],
    ],
    // ...
];
```

### websocket

> 使用路由调度的方式，可以让不同路径的websocket服务响应不同的事件

#### 配置

```
worker.websocket.enable = true 时开启
```

#### 路由定义
```php
Route::get('path1','controller/action1');
Route::get('path2','controller/action2');
```

#### 控制器

```php
use \think\worker\Websocket;
use \think\worker\websocket\Frame;

class Controller {

    public function action1(){

        return (new \think\worker\response\Websocket())
            ->onOpen(...)
            ->onMessage(function(Websocket $websocket, Frame $frame){
                ...
            })
            ->onClose(...);
    }

    public function action2(){

        return (new \think\worker\response\Websocket())
            ->onOpen(...)
            ->onMessage(function(Websocket $websocket, Frame $frame){
               ...
            })
            ->onClose(...);
    }
}
```

## 自定义worker
监听`worker.init`事件 注入`Manager`对象，调用addWorker方法添加
~~~php
use think\worker\Manager;
use \think\worker\Worker;

//...

public function handle(Manager $manager){
   $worker = $manager->addWorker(function(Worker $worker){
        //..其他回调或处理
        //动态添加监听可参考 https://www.workerman.net/doc/workerman/worker/listen.html
    });
}

//...
~~~

## 平台差异

| 能力 | Linux | Windows |
|---|---|---|
| 进程模型 | fork 多进程，`worker_num` 生效 | 每类 worker 一个独立进程 |
| 守护/停止/平滑重启/状态 | 支持 | 不支持（仅前台 start） |
| 进程间通信（conduit） | `unix://` 域套接字 | `tcp://`（默认 `127.0.0.1:9999`） |
| 热更新 | 支持 | 支持（重载标记机制） |
| 静态文件 / 文件上传 | 支持 | 支持 |

## 生产环境建议

- 使用 `php think worker start -d` 守护方式运行，或使用 supervisor/systemd 管理进程。
- 修改 `.env` 或配置文件后需重启 worker 才能生效（常驻进程不会自动重读）。
- 生产环境建议前置 nginx 做静态文件分发与 HTTPS 终结，worker 只处理动态请求。
