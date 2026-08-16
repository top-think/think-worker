<?php

namespace think\worker\watcher;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Workerman\Timer;

class Scan implements Driver
{
    protected $finder;

    protected $files = [];

    public function __construct($directory, $exclude, $name)
    {
        $this->finder = new Finder();
        $this->finder
            ->files()
            ->name($name)
            ->in($directory)
            ->exclude($exclude);
    }

    protected function findFiles()
    {
        $files = [];
        /** @var SplFileInfo $f */
        foreach ($this->finder as $f) {
            $files[$f->getRealpath()] = $f->getMTime();
        }
        return $files;
    }

    public function watch(callable $callback)
    {
        $this->files = $this->findFiles();

        Timer::add(2, function () use ($callback) {
            $files = $this->findFiles();

            // 文件数量变化说明有文件被删除，同样需要重载
            if (count($files) !== count($this->files)) {
                call_user_func($callback);
                $this->files = $files;
                return;
            }

            foreach ($files as $path => $time) {
                if (empty($this->files[$path]) || $this->files[$path] != $time) {
                    call_user_func($callback);
                    break;
                }
            }

            $this->files = $files;
        });
    }
}
