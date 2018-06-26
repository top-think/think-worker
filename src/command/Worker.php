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
use Workerman\Worker as WorkerServer;

/**
 * Worker 命令行类
 */
class Worker extends Command
{
    public function configure()
    {
        $this->setName('worker')
            ->addArgument('run', Argument::OPTIONAL, "start|stop", 'start')
            ->addOption('host', 'H', Option::VALUE_OPTIONAL,
                'The host to server the application on', '0.0.0.0')
            ->addOption('port', 'p', Option::VALUE_OPTIONAL,
                'The port to server the application on', 2346)
            ->setDescription('Built-in Workerman HTTP Server for ThinkPHP');
    }

    public function execute(Input $input, Output $output)
    {
        $run = $input->getArgument('run');

        if ('stop' == $run) {
            WorkerServer::stopAll();
        } else {
            $host = $input->getOption('host');
            $port = $input->getOption('port');

            $option = Config::pull('worker');

            $worker = new HttpServer($host, $port);
            $worker->option($option);

            $worker->start();
        }
    }

}
