<?php

namespace think\worker\concerns;

use RuntimeException;
use think\App;
use think\worker\Ipc;
use think\worker\Watcher;
use think\worker\Worker;
use Workerman\Redis\Client;
use Workerman\Timer;

/**
 * Trait InteractsWithServer
 * @property App $container
 */
trait InteractsWithServer
{
    /** @var Ipc */
    protected $ipc;

    protected $workerId = null;
    protected $stopping = false;

    public function addWorker(callable $func, $name = 'none', int $count = 1, ?string $socket = null, array $socketContext = []): Worker
    {
        $worker = $socket ? new Worker($socket, $socketContext) : new Worker();

        $worker->name  = $name;
        $worker->count = $count;

        $worker->onWorkerStart = function (Worker $worker) use ($func) {
            $this->clearCache();
            $this->prepareApplication();

            $this->conduit->connect();

            $this->workerId = $this->ipc->listenMessage();

            $this->triggerEvent('workerStart', $worker);

            // Windows 下热更新：reloadable worker 轮询重载标记，命中则退出交由 master 重启
            if (DIRECTORY_SEPARATOR !== '/' && $this->getConfig('hot_update.enable', false)) {
                $this->watchWinReload();
            }

            // Windows 下检测 master 存活，master 被强杀时子进程自动退出，避免孤儿进程占用端口
            if (DIRECTORY_SEPARATOR !== '/') {
                $this->watchMasterAlive();
            }

            $func($worker);
        };

        $worker->onWorkerReload = function () {
            $this->stopping = true;
        };

        return $worker;
    }

    public function getWorkerId()
    {
        return $this->workerId;
    }

    public function isStopping()
    {
        return $this->stopping;
    }

    /**
     * 启动服务
     * @param string $command Workerman 控制命令：start|stop|restart|reload|status|connections，可带 -d/-g 模式
     */
    public function start(string $command = 'start'): void
    {
        if (DIRECTORY_SEPARATOR === '/') {
            $this->startLinux($command);
        } else {
            $this->startWindows($command);
        }
    }

    /**
     * Linux 下启动：单文件多 worker + fork。
     */
    protected function startLinux(string $command = 'start'): void
    {
        // argv 为 ['think','worker',...]，不含 Workerman 子命令，
        // 通过 $command 注入，由 Workerman 原生 parseCommand 解析出 start/stop/reload 等动作
        Worker::$command = $command;

        $this->initialize();
        $this->prepareIpc();
        $this->triggerEvent('init');

        //热更新
        if ($this->getConfig('hot_update.enable', false)) {
            $this->addHotUpdateWorker();
        }

        Worker::runAll();
    }

    /**
     * Windows 下启动：为每个 worker 生成独立启动文件并以多进程方式拉起，由 Workerman master 监控。
     */
    protected function startWindows(string $command = 'start'): void
    {
        // Workerman 的 stop/reload/status 依赖信号与 pid 文件，Windows 下不可用
        if (!str_starts_with(trim($command), 'start')) {
            throw new RuntimeException('Only "start" is supported on Windows; stop/reload/status require Linux signals.');
        }

        // 使用随机 token 而非 PID：Windows PID 会被复用，复用碰撞会导致孤儿子进程误判 master 存活
        $masterToken = bin2hex(random_bytes(8));
        $heartbeat   = $this->winHeartbeatFile();

        // 心跳、pid 等文件均写入 runtime/win，目录可能被缓存清理删除，先确保存在
        $this->ensureWinRuntimeDir();

        $this->guardAgainstDuplicateInstance($heartbeat);

        // 先写入心跳再拉起子进程，避免子进程启动瞬间误判master已死
        file_put_contents($heartbeat, $masterToken);
        file_put_contents($this->winConsolePidFile($masterToken), (string) getmypid());

        // 清理历史启动遗留的 pid 文件，避免随启动次数无限累积
        $this->purgeStaleWinPidFiles($masterToken);

        $files = $this->createWindowsStartFiles($masterToken);

        $this->launchWindows($files, $heartbeat);
    }

    /**
     * 确保 runtime/win 目录存在（runtime 缓存清理可能将其删除）。
     */
    protected function ensureWinRuntimeDir(): void
    {
        $dir = dirname($this->winHeartbeatFile());

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * 拒绝重复启动：心跳新鲜且对应的控制进程仍存活，说明已有实例在运行。
     */
    protected function guardAgainstDuplicateInstance(string $heartbeat): void
    {
        clearstatcache(true, $heartbeat);

        // 心跳已过期，旧实例要么正常退出要么已被清理
        if (!is_file($heartbeat) || time() - (int) filemtime($heartbeat) >= 15) {
            return;
        }

        $token = trim((string) file_get_contents($heartbeat));
        $pid   = $this->readWinPidFile($this->winConsolePidFile($token));

        if ($pid > 0 && $this->isWinPhpProcessAlive($pid)) {
            throw new RuntimeException("think-worker already running (master pid {$pid}), stop it before starting a new one.");
        }
    }

    protected function winConsolePidFile(string $token): string
    {
        return runtime_path() . 'win' . DIRECTORY_SEPARATOR . 'console_' . $token . '.pid';
    }

    /**
     * 清理历史启动遗留的 pid 文件，仅保留当前 token 的两个文件。
     */
    protected function purgeStaleWinPidFiles(string $currentToken): void
    {
        $dir = dirname($this->winHeartbeatFile());

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.pid') ?: [] as $file) {
            $name = basename($file);

            if ($name !== "console_{$currentToken}.pid" && $name !== "master_{$currentToken}.pid") {
                @unlink($file);
            }
        }
    }

    protected function readWinPidFile(string $file): int
    {
        clearstatcache(true, $file);

        if (!is_file($file)) {
            return 0;
        }

        return (int) file_get_contents($file);
    }

    protected function isWinPhpProcessAlive(int $pid): bool
    {
        exec(sprintf('tasklist /FI "PID eq %d" /FI "IMAGENAME eq php.exe" 2>nul', $pid), $output);

        return stripos(implode("\n", $output), 'php.exe') !== false;
    }

    protected function createWindowsStartFiles(string $masterToken): array
    {
        $root   = $this->container->getRootPath();
        $winDir = $root . 'runtime' . DIRECTORY_SEPARATOR . 'win' . DIRECTORY_SEPARATOR;

        if (!is_dir($winDir)) {
            mkdir($winDir, 0777, true);
        }

        $files = [];

        foreach ($this->workerTypes() as $type) {
            $safeType = str_replace([':', '\\', '/', '*', '?', '"', '<', '>', '|'], '_', $type);
            $file     = $winDir . 'start_' . $safeType . '.php';

            file_put_contents($file, $this->buildWindowsStartFile($root, $type, $masterToken));

            $files[] = $file;
        }

        return $files;
    }

    protected function buildWindowsStartFile(string $root, string $type, string $masterToken): string
    {
        $rootLiteral  = var_export(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, true);
        $typeLiteral  = var_export($type, true);
        $tokenLiteral = var_export($masterToken, true);

        return <<<PHP
<?php
// auto-generated by think-worker, do not edit
chdir(__DIR__ . '/../../');

// 沿目录向上查找 vendor/autoload.php
\$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file(\$autoload)) {
    \$dir = dirname(__DIR__);
    while (\$dir !== dirname(\$dir)) {
        \$candidate = \$dir . '/vendor/autoload.php';
        if (is_file(\$candidate)) {
            \$autoload = \$candidate;
            break;
        }
        \$dir = dirname(\$dir);
    }
}
require \$autoload;

\$app = new \\think\\App({$rootLiteral});
\$app->initialize();

if (!defined('THINK_WORKER_MASTER_TOKEN')) {
    define('THINK_WORKER_MASTER_TOKEN', {$tokenLiteral});
}

if (!in_array('-q', \$argv, true)) {
    // 本进程将成为 Workerman master（负责监控并重启各 -q 子进程），
    // 记录 PID 供孤儿子进程在 master 心跳丢失时回杀，避免重启风暴
    file_put_contents(__DIR__ . '/master_' . THINK_WORKER_MASTER_TOKEN . '.pid', (string) getmypid());
}

\$manager = new \\think\\worker\\Manager(\$app);
\$manager->prepareWorker({$typeLiteral});

\\think\\worker\\Worker::runAll();
PHP;
    }

    protected function launchWindows(array $files, string $heartbeat): void
    {
        $command = array_merge([PHP_BINARY], $files);

        $descriptorSpec = [STDIN, STDOUT, STDOUT];
        $process        = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start worker processes');
        }

        // master 心跳：周期性刷新心跳文件，子进程据此感知 master 是否存活
        while (true) {
            if (!proc_get_status($process)['running']) {
                break;
            }

            touch($heartbeat);
            usleep(500000);
        }

        $exitCode = proc_close($process);

        // 不删除心跳文件：master 死后 mtime 停止刷新，孤儿子进程检测到后自行退出
        exit($exitCode);
    }

    protected function prepareIpc()
    {
        $this->ipc = $this->container->make(Ipc::class);
    }

    public function sendMessage($workerId, $message)
    {
        $this->ipc->sendMessage($workerId, $message);
    }

    /**
     * 热更新
     */
    protected function addHotUpdateWorker()
    {
        $worker = new Worker();

        $worker->name       = 'hot update';
        $worker->reloadable = false;

        $worker->onWorkerStart = function () {
            // Windows 下检测 master 存活（hot update 进程不经 addWorker 创建，需单独注册）
            if (DIRECTORY_SEPARATOR !== '/') {
                $this->watchMasterAlive();
            }

            $watcher = $this->container->make(Watcher::class);
            $watcher->watch(function () {
                if (DIRECTORY_SEPARATOR === '/') {
                    posix_kill(posix_getppid(), SIGUSR1);
                } else {
                    $this->sendWinReload();
                }
            });
        };
    }

    /**
     * Windows 下写入重载标记（内容为时间戳），reloadable worker 轮询到后退出由 master 重启。
     */
    protected function sendWinReload()
    {
        file_put_contents($this->winReloadFlag(), (string) microtime(true));
    }

    /**
     * 轮询重载标记：记录本进程已处理过的时间戳，仅对更新的标记重载。
     * 不删除标记文件，避免多个 worker 进程竞争 unlink 导致部分进程错过重载；
     * 启动时把现存标记视为已处理，防止重启后读到旧标记陷入无限重载。
     */
    protected function watchWinReload()
    {
        $flag = $this->winReloadFlag();

        clearstatcache(true, $flag);

        // 启动时已存在的标记属于上一轮重载，视为已处理
        $lastFlag = is_file($flag) ? (float) file_get_contents($flag) : 0.0;

        Timer::add(1, function () use ($flag, &$lastFlag) {
            if (!is_file($flag)) {
                return;
            }

            clearstatcache(true, $flag);

            $flagTime = (float) file_get_contents($flag);

            if ($flagTime > $lastFlag) {
                $lastFlag = $flagTime;
                Worker::stopAll(0, 'reload');
            }
        });
    }

    protected function winReloadFlag()
    {
        return runtime_path() . 'win' . DIRECTORY_SEPARATOR . 'reload.flag';
    }

    /**
     * Windows 下检测 master 存活：
     * 心跳文件不存在、token 不匹配（已被新 master 替换）或 mtime 超时（master 被强杀）时自动退出。
     */
    protected function watchMasterAlive()
    {
        // 直接运行生成的启动文件（无 master）时不检测
        if (!defined('THINK_WORKER_MASTER_TOKEN')) {
            return;
        }

        $heartbeat = $this->winHeartbeatFile();

        Timer::add(3, function () use ($heartbeat) {
            clearstatcache(true, $heartbeat);

            $alive = is_file($heartbeat)
                && trim((string) file_get_contents($heartbeat)) === THINK_WORKER_MASTER_TOKEN
                && time() - (int) filemtime($heartbeat) < 15;

            if (!$alive) {
                // 先终止 Workerman master：master 被强杀时它仍会不断重启因心跳丢失而退出的
                // 子进程，形成重启风暴；必须先让它停止监控再退出
                $this->killWinMaster();
                Worker::stopAll(0, 'master process lost');
            }
        });
    }

    /**
     * 终止孤儿 Workerman master 进程（按启动文件记录的 PID，仅限 php 进程，防 PID 复用误杀）。
     */
    protected function killWinMaster(): void
    {
        $pidFile = dirname($this->winHeartbeatFile()) . DIRECTORY_SEPARATOR
            . 'master_' . THINK_WORKER_MASTER_TOKEN . '.pid';

        $pid = $this->readWinPidFile($pidFile);

        if ($pid > 0) {
            exec(sprintf('taskkill /FI "IMAGENAME eq php.exe" /F /PID %d 2>nul', $pid));
        }

        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    protected function winHeartbeatFile()
    {
        return runtime_path() . 'win' . DIRECTORY_SEPARATOR . 'master.heartbeat';
    }

    /**
     * 清除apc、op缓存
     */
    protected function clearCache()
    {
        if (extension_loaded('apc')) {
            apc_clear_cache();
        }

        if (extension_loaded('Zend OPcache')) {
            opcache_reset();
        }
    }

}