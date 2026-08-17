<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\worker\command;

use think\console\Command;
use think\console\input\Argument;
use think\console\input\Option;
use think\worker\Manager;

/**
 * Worker Server 命令行类
 *
 * 用法：
 *   php think worker                前台启动
 *   php think worker start -d       守护进程启动
 *   php think worker stop [-g]      停止（-g 优雅停止）
 *   php think worker restart [-d]   重启
 *   php think worker reload [-g]    平滑重启（重载业务代码）
 *   php think worker status [-d]    查看状态（-d 实时刷新）
 *   php think worker connections    查看连接
 */
class Server extends Command
{
    protected $config = [];

    public function configure()
    {
        $this->setName('worker')
            ->setDescription('Workerman Server for ThinkPHP')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|reload|status|connections', 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->addOption('gracefully', 'g', Option::VALUE_NONE, 'Graceful stop/reload');
    }

    public function handle(Manager $manager)
    {
        $action = $this->input->getArgument('action');

        if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'status', 'connections'], true)) {
            $this->output->writeln('<error>Unknown action: ' . $action . '</error>');
            $this->output->writeln('Available actions: start, stop, restart, reload, status, connections');
            return 1;
        }

        $mode = '';
        if ($this->input->getOption('daemon')) {
            $mode = '-d';
        } elseif ($this->input->getOption('gracefully')) {
            $mode = '-g';
        }

        $manager->start(trim($action . ' ' . $mode));
    }

}
