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
use think\worker\Worker as HttpServer;

/**
 * Worker 命令行类
 */
class Worker extends Command
{
    public function configure()
    {
        $this->setName('worker')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->setDescription('Workerman HTTP Server for ThinkPHP');
    }

    public function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');

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

        $output->writeln('Starting Workerman http server...');
        $option = Config::pull('worker');

        $host = !empty($option['host']) ? $option['host'] : '0.0.0.0';
        $port = !empty($option['port']) ? $option['port'] : 2346;

        $worker = new HttpServer($host, $port);
        $worker->option($option);

        if (DIRECTORY_SEPARATOR == '\\') {
            $output->writeln("Workerman http server started: <http://{$host}:{$port}>");
            $output->writeln('You can exit with <info>`CTRL-C`</info>');
        }

        $worker->start();
    }

}
