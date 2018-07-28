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
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use Workerman\Worker;

/**
 * Worker 命令行类
 */
class GatewayWorker extends Command
{
    public function configure()
    {
        $this->setName('gatewayworker')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('host', 'H', Option::VALUE_OPTIONAL, 'the host of workerman server.', null)
            ->addOption('port', 'p', Option::VALUE_OPTIONAL, 'the port of workerman server.', null)
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the workerman server in daemon mode.')
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
        } else {
            $output->writeln("GatewayWorker Not Support On Windows.");
            exit(1);
        }

        $output->writeln('Starting GatewayWorker server...');
        $option = Config::pull('gatewayworker');

        if ($input->hasOption('host')) {
            $host = $input->getOption('host');
        } else {
            $host = !empty($option['host']) ? $option['host'] : '0.0.0.0';
        }

        if ($input->hasOption('port')) {
            $port = $input->getOption('port');
        } else {
            $port = !empty($option['port']) ? $option['port'] : '2347';
        }

        $this->start($host, $port, $option);
    }

    /**
     * 启动
     * @access public
     * @param  string   $host 监听地址
     * @param  integer  $port 监听端口
     * @param  array    $option 参数
     * @return void
     */
    public function start($host, $port, $option = [])
    {
        $registerAddress = isset($option['registerAddress']) ? $option['registerAddress'] : '127.0.0.1:1236';

        $this->register($registerAddress);
        $this->businessWorker($registerAddress, isset($option['businessWorker']) ? $option['businessWorker'] : []);
        $this->gateway($registerAddress, $host, $port, $option);

        Worker::runAll();
    }

    /**
     * 启动register
     * @access public
     * @param  string   $registerAddress
     * @return void
     */
    public function register($registerAddress)
    {
        // 初始化register
        new Register('text://' . $registerAddress);
    }

    /**
     * 启动businessWorker
     * @access public
     * @param  string   $registerAddress registerAddress
     * @param  array    $option 参数
     * @return void
     */
    public function businessWorker($registerAddress, $option = [])
    {
        // 初始化 bussinessWorker 进程
        $worker = new BusinessWorker();

        $worker->name            = 'BusinessWorker';
        $worker->registerAddress = $registerAddress;
        $worker->eventHandler    = !empty($option['event_handler']) ? $option['event_handler'] : '\think\worker\Events';

        $this->option($worker, $option);
    }

    /**
     * 启动gateway
     * @access public
     * @param  string   $registerAddress registerAddress
     * @param  string   $host 服务地址
     * @param  integer  $port 监听端口
     * @param  array    $option 参数
     * @return void
     */
    public function gateway($registerAddress, $host, $port, $option = [])
    {
        // 初始化 gateway 进程
        $protocol = !empty($option['protocol']) ? $option['protocol'] : 'websocket';

        $gateway = new Gateway($protocol . '://' . $host . ':' . $port, isset($option['context']) ? $option['context'] : []);

        $gateway->name                 = 'Gateway';
        $gateway->lanIp                = '127.0.0.1';
        $gateway->startPort            = 2000;
        $gateway->pingInterval         = 30;
        $gateway->pingNotResponseLimit = 0;
        $gateway->pingData             = '{"type":"ping"}';
        $gateway->registerAddress      = $registerAddress;

        $this->option($gateway, $option);
    }

    /**
     * 设置参数
     * @access protected
     * @param  Worker   $worker Worker对象
     * @param  array    $option 参数
     * @return void
     */
    protected function option($worker, array $option = [])
    {
        // 设置参数
        if (!empty($option)) {
            foreach ($option as $key => $val) {
                $worker->$key = $val;
            }
        }
    }

}
