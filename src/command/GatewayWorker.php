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

use GatewayWorker\BusinessWorker;
use GatewayWorker\Gateway;
use GatewayWorker\Register;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Config;

/**
 * Worker 命令行类
 */
class GatewayWorker extends Command
{
    public function configure()
    {
        $this->setName('gatewayworker')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->setDescription('GatewayWorker Server for ThinkPHP');
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

        $output->writeln('Starting GatewayWorker server...');
        $option = Config::pull('gatewayworker');

        $host = !empty($option['host']) ? $option['host'] : '0.0.0.0';
        $port = !empty($option['port']) ? $option['port'] : '1236';

        $this->start($host, $port, $option);
    }

    public function register($host, $port)
    {
        // 初始化register
        new Register('text://' . $host . ':' . $port);
    }

    public function businessWorker($host, $port, $option = [])
    {
        // 初始化 bussinessWorker 进程
        $worker = new BusinessWorker();

        $worker->name            = 'BusinessWorker';
        $worker->registerAddress = $host . ':' . $port;
        $worker->eventHandler    = !empty($option['event_handler']) ? $option['event_handler'] : '\think\worker\Events';

        $this->option($worker, $option);
    }

    public function gateway($host, $port, $option = [])
    {
        // 初始化 gateway 进程
        $protocol = !empty($option['protocol']) ? $option['protocol'] : 'websocket';
        $host     = !empty($option['host']) ? $option['host'] : '0.0.0.0';
        $port     = !empty($option['port']) ? $option['port'] : '2347';

        $gateway = new Gateway($protocol . '://' . $host . ':' . $port, isset($option['context']) ? $option['context'] : []);

        $gateway->name                 = 'Gateway';
        $gateway->pingInterval         = 30;
        $gateway->pingNotResponseLimit = 0;
        $gateway->pingData             = '{"type":"ping"}';
        $gateway->registerAddress      = $host . ':' . $port;

        $this->option($gateway, $option);
    }

    /**
     * 设置参数
     * @access protected
     * @param  Worker   $worker Worker对象
     * @param  array    $option 参数
     * @return void
     */
    protected function option($worker, array $option)
    {
        // 设置参数
        if (!empty($option)) {
            foreach ($option as $key => $val) {
                $worker->$key = $val;
            }
        }
    }

    /**
     * 启动
     * @access public
     * @return void
     */
    public function start($host, $port, $option = [])
    {
        $this->register($host, $port);
        $this->businessWorker($host, $port, isset($option['businessWorker']) ? $option['businessWorker'] : []);
        $this->gateway($host, $port, isset($option['gateway']) ? $option['gateway'] : []);

        Worker::runAll();
    }
}
