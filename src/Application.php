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
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Log;
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
            ob_start();

            Log::clear();

            if ($this->config->get('session.auto_start')) {
                WorkerHttp::sessionStart();
            }

            // 销毁当前请求对象实例
            $this->delete('think\Request');
            // 更新请求对象实例
            $this->route->setRequest($this->request);

            if ($this->isMulti()) {
                $this->namespace = null;
                $this->appPath   = null;
                // 应用初始化
                $this->initialize();
            } else {
                $this->beginTime = microtime(true);
                $this->beginMem  = memory_get_usage();
            }

            // 数据库初始化
            Db::init();

            $response = $this->http->run();
            $response->send();
            $content = ob_get_clean() ?: '';

            // Trace调试注入
            if ($this->env->get('app_trace', $this->config->get('app.app_trace'))) {
                $this->debug->inject($response, $content);
            }

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
            $handler = $this->error_handle;
            $handler->report($e);

            $resp    = $handler->render($e);
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
