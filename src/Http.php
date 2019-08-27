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

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Protocols\Http as WorkerHttp;
use Workerman\Worker;

/**
 * Worker http server 命令行服务类
 */
class Http extends Server
{
    protected $app;
    protected $rootPath;
    protected $root;
    protected $appInit;
    protected $monitor;
    protected $lastMtime;
    /** @var array Mime mapping. */
    protected static $mimeTypeMap = [];

    /**
     * 架构函数
     * @access public
     * @param  string $host 监听地址
     * @param  int    $port 监听端口
     * @param  array  $context 参数
     */
    public function __construct($host, $port, $context = [])
    {
        $this->worker = new Worker('http://' . $host . ':' . $port, $context);

        // 设置回调
        foreach ($this->event as $event) {
            if (method_exists($this, $event)) {
                $this->worker->$event = [$this, $event];
            }
        }
    }

    public function setRootPath($path)
    {
        $this->rootPath = $path;
    }

    public function appInit(\Closure $closure)
    {
        $this->appInit = $closure;
    }

    public function setRoot($path)
    {
        $this->root = $path;
    }

    public function setStaticOption($name, $value)
    {
        Worker::${$name} = $value;
    }

    public function setMonitor($interval = 2, $path = [])
    {
        $this->monitor['interval'] = $interval;
        $this->monitor['path']     = (array) $path;
    }

    /**
     * 设置参数
     * @access public
     * @param  array    $option 参数
     * @return void
     */
    public function option(array $option)
    {
        // 设置参数
        if (!empty($option)) {
            foreach ($option as $key => $val) {
                $this->worker->$key = $val;
            }
        }
    }

    /**
     * onWorkerStart 事件回调
     * @access public
     * @param  \Workerman\Worker $worker
     * @return void
     */
    public function onWorkerStart($worker)
    {
        $this->initMimeTypeMap();
        $this->app = new Application($this->rootPath);

        if ($this->appInit) {
            call_user_func_array($this->appInit, [$this->app]);
        }

        $this->app->initialize();

        $this->lastMtime = time();

        $this->app->workerman = $worker;

        $this->app->bind([
            'think\Cookie' => Cookie::class,
        ]);

        if (0 == $worker->id && $this->monitor) {
            $paths = $this->monitor['path'];
            $timer = $this->monitor['interval'] ?: 2;

            Timer::add($timer, function () use ($paths) {
                foreach ($paths as $path) {
                    $dir      = new RecursiveDirectoryIterator($path);
                    $iterator = new RecursiveIteratorIterator($dir);

                    foreach ($iterator as $file) {
                        if (pathinfo($file, PATHINFO_EXTENSION) != 'php') {
                            continue;
                        }

                        if ($this->lastMtime < $file->getMTime()) {
                            echo '[update]' . $file . "\n";
                            posix_kill(posix_getppid(), SIGUSR1);
                            $this->lastMtime = $file->getMTime();
                            return;
                        }
                    }
                }
            });
        }
    }

    /**
     * onMessage 事件回调
     * @access public
     * @param  TcpConnection $connection
     * @param  mixed         $data
     * @return void
     */
    public function onMessage($connection, $data)
    {
        $uri  = parse_url($_SERVER['REQUEST_URI']);
        $path = $uri['path'] ?? '/';

        $file = $this->root . $path;

        if (!is_file($file)) {
            $this->app->worker($connection, $data);
        } else {
            $this->sendFile($connection, $file);
        }
    }

    /**
     * 访问资源文件
     * @access protected
     * @param  TcpConnection $connection
     * @param  string        $file 文件名
     * @return string
     */
    protected function sendFile($connection, $file)
    {
        $info        = stat($file);
        $modifiyTime = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' ' . date_default_timezone_get() : '';

        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info) {
            // Http 304.
            if ($modifiyTime === $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
                // 304
                WorkerHttp::header('HTTP/1.1 304 Not Modified');
                // Send nothing but http headers..
                return $connection->close('');
            }
        }

        $mimeType = $this->getMimeType($file);

        WorkerHttp::header('HTTP/1.1 200 OK');
        WorkerHttp::header('Connection: keep-alive');

        if ($mimeType) {
            WorkerHttp::header('Content-Type: ' . $mimeType);
        } else {
            WorkerHttp::header('Content-Type: application/octet-stream');
            $fileinfo = pathinfo($file);
            $filename = $fileinfo['filename'] ?? '';
            WorkerHttp::header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        if ($modifiyTime) {
            WorkerHttp::header('Last-Modified: ' . $modifiyTime);
        }

        WorkerHttp::header('Content-Length: ' . filesize($file));

        ob_start();
        readfile($file);
        $content = ob_get_clean();

        return $connection->send($content);
    }

    /**
     * 获取文件类型信息
     * @access protected
     * @param  string $filename 文件名
     * @return string
     */
    protected function getMimeType(string $filename)
    {
        $file_info = pathinfo($filename);
        $extension = $file_info['extension'] ?? '';

        if (isset(self::$mimeTypeMap[$extension])) {
            $mime = self::$mimeTypeMap[$extension];
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $filename);
        }

        return $mime;
    }

    /**
     * Init mime map.
     *
     * @return void
     * @throws \Exception
     */
    protected function initMimeTypeMap()
    {
        $mime_file = WorkerHttp::getMimeTypesFile();

        if (!is_file($mime_file)) {
            Worker::log("$mime_file mime.type file not fond");
            return;
        }

        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($items)) {
            Worker::log("get $mime_file mime.type content fail");
            return;
        }

        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type                      = $match[1];
                $workerman_file_extension_var   = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach ($workerman_file_extension_array as $workerman_file_extension) {
                    self::$mimeTypeMap[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }

    /**
     * 启动
     * @access public
     * @return void
     */
    public function start()
    {
        Worker::runAll();
    }

    /**
     * 停止
     * @access public
     * @return void
     */
    public function stop()
    {
        Worker::stopAll();
    }
}
