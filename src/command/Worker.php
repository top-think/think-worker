<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\worker\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\worker\Worker as HttpServer;

class Worker extends Command
{
    protected $http;

    public function configure()
    {
        $this->setName('worker')
            ->addArgument('run', Argument::REQUIRED, "start|stop")
            ->addOption('host', 'H', Option::VALUE_OPTIONAL,
                'The host to server the application on', '0.0.0.0')
            ->addOption('port', 'p', Option::VALUE_OPTIONAL,
                'The port to server the application on', 2346)
            ->setDescription('Built-in Workerman HTTP Server for ThinkPHP');
    }

    public function execute(Input $input, Output $output)
    {
        if (!$this->http) {
            $host = $input->getOption('host');
            $port = $input->getOption('port');

            $option = Config::pull('worker');

            $this->http = new HttpServer($host, $port);
            $this->http->option($option);
        }

        $run = $input->getArgument('run');

        $this->http->$run();
    }

}
