ThinkPHP 5.0 Workerman 扩展
===============

## 使用方法
首先创建控制器类并继承 think\worker\Server，然后设置属性和添加回调方法

~~~
namespace app\index\controller;

use think\worker\Server;
use app\index\model\User;

class Worker extends Server
{
	protected $socket = 'http://0.0.0.0:2346';

	public function onMessage($connection,$data)
	{
		$user = User::get($data['get']['id']);
		$connection->send(json_encode($user));
	}
}
~~~

> 注意该示例使用了User模型操作仅仅作为参考。

在命令行启动服务端
~~~
php index.php index/Worker/start
~~~

在浏览器中进行客户端测试
http://127.0.0.1:2346/?id=1