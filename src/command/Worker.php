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

namespace think\swoole\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Config;
use think\worker\Worker as HttpServer;

class Worker extends Command
{
    protected $http;

    public function configure()
    {
        $this->setName('swoole')
            ->addArgument('run', Argument::REQUIRED, "The name of the class")
            ->addOption('host', 'H', Option::VALUE_OPTIONAL,
                'The host to server the application on', '0.0.0.0')
            ->addOption('port', 'r', Option::VALUE_OPTIONAL,
                'The port to server the application on', 2346)
            ->addOption('path', 'p', Option::VALUE_OPTIONAL,
                'The document root of the application', App::getRootPath() . 'application')
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

        $output->writeln(sprintf('SwooleServer is started On <http://%s:%s/>', $host, $port));
        $output->writeln(sprintf('You can exit with <info>`CTRL-C`</info>'));

        $run = $input->getArgument('run');

        $this->http->$run();
    }

}
