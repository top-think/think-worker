<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2014 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\worker;

/**
 * Worker应用对象
 */
class Application extends App
{
    /**
     * 处理Worker请求 在run方法之前调用
     * @access public
     * @param  $this
     */
    public function worker()
    {
        // 重置应用的开始时间和内存占用
        $this->beginTime = microtime(true);
        $this->beginMem  = memory_get_usage();

        // 销毁当前请求对象实例
        $this->delete('think\Request');

        // 更新请求对象实例
        $this->route->setRequest($this->request);

        return $this;
    }
}
