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

namespace think\worker\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use Workerman\Worker;

/**
 * Worker 命令行类
 */
class Server extends Command
{
    protected $config = [];

    public function configure()
    {
        $this->setName('worker:server')
            ->addOption('host', 'H', Option::VALUE_NONE,
                'The host to workerman server')
            ->addOption('port', 'p', Option::VALUE_NONE,
                'The port to workerman server')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the workerman server in daemon mode.')
            ->setDescription('Workerman Server for ThinkPHP');
    }

    public function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');

        $this->config = Config::pull('worker_server');

        if (!empty($this->config['controller'])) {
            $command = 'php public/index.php ' . $this->config['controller'] . ' ' . $action;
            passthru($command);
            return;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            if (!in_array($action, ['start', 'stop', 'reload', 'restart', 'status'])) {
                $output->writeln("Invalid argument action:{$action}, Expected start|stop|restart|reload|status .");
                exit(1);
            }

            global $argv;
            array_shift($argv);
            array_shift($argv);
            array_unshift($argv, 'think', $action);
        } elseif ('start' != $action) {
            $output->writeln("Not Support action:{$action} on Windows.");
            exit(1);
        }

        $output->writeln('Starting Workerman server...');

        if (!empty($this->config['socket'])) {
            $socket = $this->config['socket'];
        } else {
            if ($this->input->hasOption('host')) {
                $host = $this->input->getOption('host');
            } else {
                $host = !empty($this->config['host']) ? $this->config['host'] : '0.0.0.0';
            }

            if ($this->input->hasOption('port')) {
                $port = $this->input->getOption('port');
            } else {
                $port = !empty($this->config['port']) ? $this->config['port'] : 2345;
            }

            $protocol = !empty($this->config['protocol']) ? $this->config['protocol'] : 'http';
            $socket   = $protocol . '://' . $host . ':' . $port;
        }

        if (isset($this->config['context'])) {
            $context = $this->config['context'];
            unset($this->config['context']);
        } else {
            $context = [];
        }

        $worker = new Worker($socket, $context);

        // 开启守护进程模式
        if ($this->input->hasOption('daemon')) {
            $worker->setStaticOption('daemonize', true);
        }

        if (!empty($this->config['ssl'])) {
            $this->config['transport'] = 'ssl';
            unset($this->config['ssl']);
        }

        // 设置服务器参数
        foreach ($this->config as $name => $val) {
            if (in_array($name, ['stdoutFile', 'daemonize', 'pidFile', 'logFile'])) {
                Worker::${$name} = $val;
            } else {
                $worker->$name = $val;
            }
        }

        // Run worker
        Worker::runAll();
    }

}
