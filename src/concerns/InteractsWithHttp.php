<?php

namespace think\worker\concerns;

use think\App;
use think\Cookie;
use think\Event;
use think\exception\Handle;
use think\helper\Str;
use think\Http;
use think\response\View;
use think\worker\App as WorkerApp;
use think\worker\Http as WorkerHttp;
use think\worker\response\File as FileResponse;
use think\worker\response\Iterator as IteratorResponse;
use think\worker\protocols\FlexHttp;
use think\worker\websocket\Frame;
use think\worker\Worker;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request as WorkerRequest;
use Workerman\Protocols\Http\Response;
use function substr;

/**
 * Trait InteractsWithHttp
 * @property App $app
 * @property App $container
 */
trait InteractsWithHttp
{
    use ModifyProperty, InteractsWithWebsocket;

    protected $wsEnable = false;

    protected function prepareHttp()
    {
        if ($this->getConfig('http.enable', true)) {

            $this->wsEnable = $this->getConfig('websocket.enable', false);

            if ($this->wsEnable) {
                $this->prepareWebsocket();
            }

            $host    = $this->getConfig('http.host');
            $port    = $this->getConfig('http.port');
            $options = $this->getConfig('http.options', []);

            $workerNum = $this->getConfig('http.worker_num', 4);

            $worker = $this->addWorker([$this, 'createHttpServer'], 'http server', $workerNum, "http://{$host}:{$port}", $options);
            $worker->reusePort = true;
        }
    }

    public function createHttpServer(Worker $server)
    {
        $this->preloadHttp();

        $server->protocol = FlexHttp::class;

        $server->reusePort = true;

        $server->onMessage = function (TcpConnection $connection, $data) {
            if ($data instanceof WorkerRequest) {
                if ($this->wsEnable && $this->isWebsocketRequest($data)) {
                    $this->onHandShake($connection, $data);
                } else {
                    $this->onRequest($connection, $data);
                }
            } elseif ($data instanceof Frame) {
                $this->onMessage($connection, $data);
            }
        };

        $server->onClose = function (TcpConnection $connection) {
            if ($this->wsEnable) {
                $this->onClose($connection);
            }
        };

        $server->listen();
    }

    protected function preloadHttp()
    {
        // PHP 8.5 起 ReflectionMethod::setAccessible() 被弃用，think-container 仍会调用，
        // 若被 ThinkPHP 错误处理器转为异常会导致 worker 启动失败，这里临时屏蔽 E_DEPRECATED。
        $errorReporting = error_reporting();
        error_reporting($errorReporting & ~E_DEPRECATED);

        try {
            $this->preloadHttpReflection();
        } finally {
            error_reporting($errorReporting);
        }
    }

    protected function preloadHttpReflection()
    {
        $http = $this->app->http;
        $this->app->invokeMethod([$http, 'loadMiddleware'], [], true);

        if ($this->app->config->get('app.with_route', true)) {
            $this->app->invokeMethod([$http, 'loadRoutes'], [], true);
            $route = clone $this->app->route;
            unset($this->app->route);

            $this->app->resolving(WorkerHttp::class, function ($http, App $app) use ($route) {
                $newRoute = clone $route;
                $this->modifyProperty($newRoute, $app);
                $app->instance('route', $newRoute);
            });
        }

        $middleware = clone $this->app->middleware;
        unset($this->app->middleware);

        $this->app->resolving(WorkerHttp::class, function ($http, App $app) use ($middleware) {
            $newMiddleware = clone $middleware;
            $this->modifyProperty($newMiddleware, $app);
            $app->instance('middleware', $newMiddleware);
        });

        unset($this->app->http);
        $this->app->bind(Http::class, WorkerHttp::class);
    }

    public function onRequest(TcpConnection $connection, WorkerRequest $wkRequest)
    {
        $this->runInSandbox(function (Http $http, Event $event, WorkerApp $app) use ($connection, $wkRequest) {

            $app->setInConsole(false);

            $request = $this->prepareRequest($wkRequest, $connection);

            try {
                $response = $this->handleRequest($http, $request, $wkRequest, $connection);
                $this->prepareResponse($response);
            } catch (Throwable $e) {
                $handle = $app->make(Handle::class);
                $handle->report($e);
                $response = $handle->render($request, $e);
            }

            $this->sendResponse($connection, $request, $response, $app->cookie);

            // Iterator 响应为裸流无长度信息，需断开以标示结束；HEAD 请求 Workerman 无法抑制 body，
            // 同样断开以免 body 字节污染 keep-alive 上的下一个响应；其余响应支持 keep-alive
            if ($response instanceof IteratorResponse
                || strtoupper($request->method()) === 'HEAD') {
                $connection->close();
            }

            $http->end($response);
        });
    }

    protected function handleRequest(Http $http, $request, WorkerRequest $wkRequest, TcpConnection $connection)
    {
        $response = $this->handleStaticFile($request);
        if ($response !== null) {
            return $response;
        }

        // public 下已存在的独立 php 脚本按 FPM 方式执行（如 install.php）
        $response = $this->handlePublicScript($request, $wkRequest, $connection);
        if ($response !== null) {
            return $response;
        }

        // echo/var_dump 等输出打到控制台（与 webman 一致），不混入 HTTP 响应体
        $level = ob_get_level();
        ob_start();

        try {
            $response = $http->run($request);
        } finally {
            // 收集全部嵌套缓冲的输出，异常时也能还原缓冲层级（否则每次异常泄漏一层 ob）
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }

            if ($output !== '') {
                echo $output;
            }
        }

        return $response;
    }

    /**
     * 处理静态文件请求（对齐 webman findFile 逻辑）：
     * public 下命中即返回文件响应，否则返回 null 交给后续处理。
     * @param \think\Request $request
     * @return FileResponse|null
     */
    protected function handleStaticFile($request)
    {
        $staticConfig = $this->getConfig('static', []);

        // 未配置 static 时默认开启，便于开箱即用
        if (array_key_exists('enable', $staticConfig) && empty($staticConfig['enable'])) {
            return null;
        }

        $file = $this->resolvePublicFile($request, $staticConfig);

        if ($file === null) {
            return null;
        }

        // php 永不作为静态文本返回（防源码泄漏），交由 handlePublicScript 决定是否执行
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
            return null;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // 敏感文件不作为静态资源返回，交给应用处理（默认黑名单可配置覆盖）
        $forbidden = $staticConfig['forbid_extensions']
            ?? ['php', 'sql', 'sqlite', 'db', 'env', 'ini', 'log', 'bak', 'sh', 'bat', 'htaccess', 'config'];

        if (in_array($extension, $forbidden, true)) {
            return null;
        }

        return new FileResponse($file);
    }

    /**
     * 以 FPM 兼容方式执行 public 下的独立 php 脚本（如 install.php 等安装向导）。
     * php-fpm 部署下 public 下的 php 文件本就会被执行，这里对齐该行为。
     * @param \think\Request $request
     * @return \think\Response|null
     */
    protected function handlePublicScript($request, WorkerRequest $wkRequest, TcpConnection $connection)
    {
        $staticConfig = $this->getConfig('static', []);

        if (array_key_exists('enable', $staticConfig) && empty($staticConfig['enable'])) {
            return null;
        }

        if (array_key_exists('public_scripts', $staticConfig) && empty($staticConfig['public_scripts'])) {
            return null;
        }

        $file = $this->resolvePublicFile($request, $staticConfig);

        if ($file === null || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }

        return $this->executePublicScript($file, $wkRequest, $connection);
    }

    /**
     * 解析请求路径到 public 下的实际文件：URL 解码、防路径穿越、拒点文件。
     * @param \think\Request $request
     * @return string|null 命中返回绝对路径，否则 null
     */
    protected function resolvePublicFile($request, array $staticConfig): ?string
    {
        $path = '/' . $request->pathinfo();

        // Workerman 的 path() 不做 urldecode，含 %xx 时先解码再检查（与 webman 一致），
        // 否则中文/空格等文件名的资源无法命中；用 rawurldecode 避免 + 被误解码为空格
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = rawurldecode($path);
        }

        // 拒绝路径穿越与非法字符，防止越权访问 public 目录之外的文件
        if ($path === '/'
            || strpos($path, '..') !== false
            || strpos($path, "\\") !== false
            || strpos($path, "\0") !== false) {
            return null;
        }

        // 拒绝点文件（.htaccess、.git 等）
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return null;
            }
        }

        $file = rtrim($staticConfig['public_path'] ?? root_path('public'), '/\\')
            . str_replace('/', DIRECTORY_SEPARATOR, $path);

        clearstatcache(true, $file);

        return is_file($file) ? $file : null;
    }

    /**
     * 在独立子进程中执行独立 php 脚本，完整还原 FPM 的每请求独立进程语义：
     * 脚本内的 exit/die、顶层函数/常量定义互不影响（install.php 等脚本常在顶层
     * 定义全局函数，常驻进程内重复 include 会因重复声明报错），__DIR__ 也与真实路径一致。
     * 请求上下文通过 stdin 以 JSON 传入，由 runtime 下的 runner 脚本还原为超全局变量。
     */
    protected function executePublicScript(string $script, WorkerRequest $wkRequest, TcpConnection $connection)
    {
        $publicPath = rtrim($this->getConfig('static.public_path', root_path('public')), '/\\');
        $scriptName = str_replace(DIRECTORY_SEPARATOR, '/', substr($script, strlen($publicPath)));

        $context = json_encode([
            'get'    => $wkRequest->get(),
            'post'   => $wkRequest->post(),
            'cookie' => $wkRequest->cookie(),
            'files'  => $this->normalizeUploadFiles($wkRequest->file()),
            'server' => array_merge($this->prepareServerVars($wkRequest, $connection), [
                'SCRIPT_NAME'     => $scriptName,
                'SCRIPT_FILENAME' => $script,
                'PHP_SELF'        => $scriptName,
                'DOCUMENT_ROOT'   => $publicPath,
            ]),
            // FPM 下脚本的工作目录为其所在目录，install.php 的 file_put_contents('../.env') 依赖该行为
            'cwd'    => dirname($script),
            'script' => $script,
        ], JSON_UNESCAPED_SLASHES);

        $runner  = $this->ensurePublicScriptRunner();
        $process = proc_open([PHP_BINARY, $runner], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $context);
        fclose($pipes[0]);

        $content = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // 脚本的 warning/notice 输出到 stderr，转发到 worker 控制台（对齐 FPM 错误日志行为）
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($error !== '') {
            echo $error;
        }

        // 异常退出且无输出时返回 500，避免静默返回空白页
        if ($exitCode !== 0 && $content === '') {
            return \think\Response::create('Public script execution failed', 'html', 500);
        }

        // 输出以 { 或 [ 开头视为 JSON（安装向导常以 echo json_encode 返回结果）
        $trimmed = ltrim((string) $content);
        $headers = ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '['))
            ? ['Content-Type' => 'application/json; charset=utf-8']
            : [];

        return \think\Response::create($content, 'html', 200)->header($headers);
    }

    /**
     * 生成（如缺失）public 脚本执行器：从 stdin 读取请求上下文，还原超全局变量后 include 目标脚本。
     */
    protected function ensurePublicScriptRunner(): string
    {
        $runner = runtime_path() . 'public_script_runner.php';

        if (!is_file($runner)) {
            file_put_contents($runner, <<<'PHP'
<?php
// auto-generated by think-worker, do not edit
$ctx = json_decode(stream_get_contents(STDIN), true);

$_GET     = $ctx['get'] ?? [];
$_POST    = $ctx['post'] ?? [];
$_COOKIE  = $ctx['cookie'] ?? [];
$_FILES   = $ctx['files'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);
$_SERVER  = $ctx['server'] ?? [];

chdir($ctx['cwd'] ?? dirname($ctx['script']));

include $ctx['script'];
PHP
            );
        }

        return $runner;
    }

    protected function prepareRequest(WorkerRequest $wkRequest, TcpConnection $connection)
    {
        $header = $wkRequest->header();
        $server = $this->prepareServerVars($wkRequest, $connection);

        // 重新实例化请求对象 处理请求数据
        /** @var \think\Request $request */
        $request = $this->app->make('request', [], true);

        return $request
            ->setMethod($wkRequest->method())
            ->withHeader($header)
            ->withServer($server)
            ->withGet($wkRequest->get())
            ->withPost($wkRequest->post())
            ->withCookie($wkRequest->cookie())
            ->withFiles($this->normalizeUploadFiles($wkRequest->file()))
            ->withInput($wkRequest->rawBody())
            ->setBaseUrl($wkRequest->uri())
            ->setUrl($wkRequest->uri() . (!empty($wkRequest->queryString()) ? '?' . $wkRequest->queryString() : ''))
            ->setPathinfo(ltrim($wkRequest->path(), '/'));
    }

    /**
     * 构造标准 server 变量（REMOTE_ADDR、REQUEST_METHOD 等），供框架请求与 FPM 兼容脚本共用。
     */
    protected function prepareServerVars(WorkerRequest $wkRequest, TcpConnection $connection): array
    {
        $server = [];

        foreach ($wkRequest->header() as $key => $value) {
            $server['http_' . str_replace('-', '_', $key)] = $value;
        }

        // 补全标准 server 变量，ThinkPHP 的 ip()/协议判断等依赖这些值
        $queryString = $wkRequest->queryString();

        $server['REMOTE_ADDR']       = $connection->getRemoteIp();
        $server['REMOTE_PORT']       = $connection->getRemotePort();
        $server['REQUEST_METHOD']    = $wkRequest->method();
        $server['REQUEST_URI']       = $wkRequest->uri();
        $server['QUERY_STRING']      = $queryString;
        $server['REQUEST_TIME']      = time();
        $server['REQUEST_TIME_FLOAT'] = microtime(true);
        $server['SERVER_PROTOCOL']   = 'HTTP/' . $wkRequest->protocolVersion();
        $server['SERVER_NAME']       = $wkRequest->host();
        $server['SERVER_PORT']       = $connection->getLocalPort();

        return $server;
    }

    /**
     * 归一化 Workerman 的上传文件结构为 ThinkPHP 期望的 $_FILES 格式。
     *
     * Workerman 多文件（如 name="photos[]"）返回 [0=>['name'=>...], 1=>[...]] 的列表结构，
     * 而 ThinkPHP dealUploadFile() 期望聚合格式 ['name'=>[...], 'tmp_name'=>[...], ...]；
     * 单文件结构两边一致，直接透传。
     */
    protected function normalizeUploadFiles($files): array
    {
        if (empty($files) || !is_array($files)) {
            return [];
        }

        $result = [];

        foreach ($files as $key => $file) {
            // 数字索引列表 => 多文件，聚合各字段；其余（单文件关联数组）保持原样
            if (is_array($file) && array_is_list($file) && isset($file[0]) && is_array($file[0])) {
                $result[$key] = [
                    'name'      => array_column($file, 'name'),
                    'type'      => array_column($file, 'type'),
                    'tmp_name'  => array_column($file, 'tmp_name'),
                    'error'     => array_column($file, 'error'),
                    'size'      => array_column($file, 'size'),
                    'full_path' => array_column($file, 'full_path'),
                ];
            } else {
                $result[$key] = $file;
            }
        }

        return $result;
    }

    protected function prepareResponse(\think\Response $response)
    {
        switch (true) {
            case $response instanceof View:
                $response->getContent();
                break;
        }
    }

    protected function sendResponse(TcpConnection $connection, \think\Request $request, \think\Response $response, Cookie $cookie)
    {
        $isHead = strtoupper($request->method()) === 'HEAD';

        switch (true) {
            case $response instanceof IteratorResponse:
                $this->sendIterator($connection, $response, $cookie);
                break;
            case $response instanceof FileResponse:
                $this->sendFile($connection, $request, $response, $cookie);
                break;
            default:
                $this->sendContent($connection, $response, $cookie, $isHead);
        }
    }

    protected function sendIterator(TcpConnection $connection, IteratorResponse $response, Cookie $cookie)
    {
        $wkResponse = $this->createResponse($response, $cookie);
        $connection->send($wkResponse);

        foreach ($response as $content) {
            $connection->send($content, true);
        }
    }

    protected function sendFile(TcpConnection $connection, \think\Request $request, FileResponse $response, Cookie $cookie)
    {
        $ifNoneMatch     = $request->header('If-None-Match');
        $ifModifiedSince = $request->header('If-Modified-Since');
        $ifRange         = $request->header('If-Range');

        $code         = $response->getCode();
        $file         = $response->getFile();
        $eTag         = $response->getHeader('ETag');
        $lastModified = $response->getHeader('Last-Modified');

        // 去掉全部引号再比较：Workerman 解析 If-None-Match 时会去掉引号，而响应 ETag 为 W/"hash" 格式
        $stripQuotes = static fn ($v) => str_replace('"', '', (string) $v);
        $notModified = false;
        if ($ifNoneMatch !== null) {
            $notModified = $stripQuotes($ifNoneMatch) === $stripQuotes($eTag);
        } elseif ($ifModifiedSince !== null) {
            $notModified = $ifModifiedSince === $lastModified;
        }

        $fileSize = $file->getSize();
        $offset   = 0;
        // 0 表示返回整个文件，避免被 Workerman 误判为 Range 请求（-1 为真值会触发 206）
        $length   = 0;

        if ($notModified) {
            $code = 304;
        } elseif (!$ifRange || $stripQuotes($ifRange) === $stripQuotes($eTag) || $ifRange === $lastModified) {
            $range = $request->header('Range', '');

            // 仅接受单段且格式合法的 bytes 范围，多段/非数字视为无 Range 返回全文（与 webman 一致）
            if (Str::startsWith($range, 'bytes=')
                && preg_match('/^(\d*)-(\d*)$/', substr($range, 6), $m) === 1
                && ($m[1] !== '' || $m[2] !== '')) {

                [$start, $end] = [$m[1], $m[2]];

                $end = ('' === $end) ? $fileSize - 1 : (int) $end;

                if ('' === $start) {
                    // 后缀范围 bytes=-N
                    $start = $fileSize - $end;
                    $end   = $fileSize - 1;
                } else {
                    $start = (int) $start;
                }

                if ($start < 0 || $start > $end || $start >= $fileSize) {
                    $code = 416;
                    $response->header([
                        'Content-Range' => sprintf('bytes */%s', $fileSize),
                    ]);
                } else {
                    $end = min($end, $fileSize - 1);

                    if ($end - $start < $fileSize - 1) {
                        $offset = $start;
                        $length = $end - $start + 1;
                        $code   = 206;
                    }
                }
            }
        }

        $wkResponse = $this->createResponse($response, $cookie);

        // createResponse 使用响应对象的 code，这里以本地计算的状态码为准（304/206/416 等）
        $wkResponse->withStatus($code);

        if (strtoupper($request->method()) === 'HEAD') {
            // HEAD 只发头不发 body：用 chunked 声明避免 Workerman 追加错误的 Content-Length，
            // 由调用方负责断开连接
            $wkResponse->withoutHeader('Content-Length')->withHeader('Transfer-Encoding', 'chunked');
        } else {
            // Content-Length 与 Accept-Ranges 由 Workerman 根据 offset/length 自动生成，移除已复制的值避免重复
            $wkResponse->withoutHeader('Content-Length')->withoutHeader('Accept-Ranges');

            if ($code >= 200 && $code < 300) {
                $wkResponse->withFile($file->getPathname(), $offset, $length);
            }
        }

        $connection->send($wkResponse);
    }

    protected function sendContent(TcpConnection $connection, \think\Response $response, Cookie $cookie, bool $isHead = false)
    {
        $response->header(['Transfer-Encoding' => 'chunked']);

        $wkResponse = $this->createResponse($response, $cookie);

        $connection->send($wkResponse);

        // HEAD 响应只发头不发 body（RFC 9110），由调用方负责断开连接
        if ($isHead) {
            return;
        }

        $content = $response->getContent();
        if ($content) {
            $contentSize = strlen($content);
            $chunkSize   = 8192;

            if ($contentSize > $chunkSize) {
                $sendSize = 0;
                do {
                    if (!$connection->send(new Chunk(substr($content, $sendSize, $chunkSize)))) {
                        break;
                    }
                } while (($sendSize += $chunkSize) < $contentSize);
            } else {
                $connection->send(new Chunk($content));
            }
        }
        $connection->send(new Chunk(''));
    }

    protected function createResponse(\think\Response $response, Cookie $cookie, $body = '')
    {
        $code   = $response->getCode();
        $header = $response->getHeader();

        $wkResponse = new Response($code, $header, $body);

        foreach ($cookie->getCookie() as $name => $val) {
            [$value, $expire, $option] = $val;
            $wkResponse->cookie($name, $value, $expire, $option['path'], $option['domain'], (bool) $option['secure'], (bool) $option['httponly'], $option['samesite']);
        }

        return $wkResponse;
    }
}
