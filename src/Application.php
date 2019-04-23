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

namespace think\worker;

use think\App;
use think\exception\Handle;
use think\exception\HttpException;
use Workerman\Protocols\Http as WorkerHttp;

/**
 * Worker应用对象
 */
class Application extends App
{
    /**
     * 处理Worker请求
     * @access public
     * @param  \Workerman\Connection\TcpConnection   $connection
     * @param  void
     */
    public function worker($connection)
    {
        try {
            $this->beginTime = microtime(true);
            $this->beginMem  = memory_get_usage();
            $this->db->clearQueryTimes();

            $response = $this->http->run();

            ob_start();
            $response->send();
            $this->http->end($response);

            $content = ob_get_clean() ?: '';

            $this->httpResponseCode($response->getCode());

            foreach ($response->getHeader() as $name => $val) {
                // 发送头部信息
                WorkerHttp::header($name . (!is_null($val) ? ':' . $val : ''));
            }

            $connection->send($content);
        } catch (HttpException | \Exception | \Throwable $e) {
            $this->exception($connection, $e);
        }
    }

    /**
     * 是否运行在命令行下
     * @return bool
     */
    public function runningInConsole()
    {
        return false;
    }

    protected function httpResponseCode($code = 200)
    {
        WorkerHttp::header('HTTP/1.1', true, $code);
    }

    protected function exception($connection, $e)
    {
        if ($e instanceof \Exception) {
            $handler = $this->make(Handle::class);
            $handler->report($e);

            $resp    = $handler->render($this->request, $e);
            $content = $resp->getContent();
            $code    = $resp->getCode();

            $this->httpResponseCode($code);
            $connection->send($content);
        } else {
            $this->httpResponseCode(500);
            $connection->send($e->getMessage());
        }
    }

}
