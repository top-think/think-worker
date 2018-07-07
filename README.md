ThinkPHP 5.1 Workerman 扩展
===============

## 安装
composer require topthink/think-worker

## 使用方法

### HttpServer

在命令行启动服务端
~~~
php think worker
~~~

然后就可以通过浏览器直接访问

~~~
http://localhost:2346
~~~

linux下面可以支持下面指令
~~~
php think worker [start|stop|reload|restart|status]
~~~

workerman的参数可以在应用配置目录下的worker.php里面配置。

由于onWorkerStart运行的时候没有HTTP_HOST，因此最好在应用配置文件中设置app_host

### SocketServer

在命令行启动服务端
~~~
php think worker:server
~~~

然后就可以通过浏览器直接访问

~~~
http://localhost:2345
~~~

如果需要自定义参数，可以在config/worker_server.php中进行配置，包括：

配置参数 | 描述
--- | ---
protocol| 协议
host | 监听地址
port | 监听端口
socket | 完整的socket地址

支持workerman所有的参数。
也支持使用闭包方式定义相关事件回调。

~~~
return [
	'socket' 	=>	'http://127.0.0.1:8000',
	'onMessage'	=>	function($connection, $data) {
		$connection->send(json_encode($data));
	},
];
~~~

也支持使用控制器类作为服务入口文件类。

首先创建控制器类并继承 think\worker\Server，然后设置属性和添加回调方法

~~~
<?php
namespace app\index\controller;

use think\worker\Server;

class Worker extends Server
{
	protected $socket = 'http://0.0.0.0:2346';

	public function onMessage($connection,$data)
	{
		$connection->send(json_encode($data));
	}
}
~~~
支持workerman所有的回调方法定义（回调方法必须是public类型）

在worker_server.php中增加配置参数：
~~~
return [
	'controller'	=>	'index/Worker',
];
~~~

在命令行启动服务端
~~~
php think worker:server
~~~
