<?php

namespace think\worker\file;

use think\exception\FileException;
use think\File;

/**
 * Workerman 环境下的上传文件对象。
 *
 * Workerman 通过 tempnam() 落盘的上传文件不是 PHP-FPM 意义上的"HTTP 上传文件"，
 * is_uploaded_file()/move_uploaded_file() 对其恒返回 false，
 * 因此构造时强制 test=true 绕过校验，move() 改用 rename（跨盘符时 copy+unlink 兜底）。
 */
class UploadedFile extends \think\file\UploadedFile
{
    /** 父类 $error 为 private，这里自存一份供子类判断 */
    protected int $uploadError;

    public function __construct(string $path, string $originalName, ?string $mimeType = null, ?int $error = null)
    {
        $this->uploadError = $error ?: UPLOAD_ERR_OK;

        parent::__construct($path, $originalName, $mimeType, $error, true);
    }

    public function isValid(): bool
    {
        return UPLOAD_ERR_OK === $this->uploadError && is_file($this->getPathname());
    }

    /**
     * 移动文件到目标目录。
     */
    public function move(string $directory, ?string $name = null): File
    {
        if (!$this->isValid()) {
            throw new FileException($this->getErrorMessage());
        }

        $target = $this->getTargetFile($directory, $name);

        set_error_handler(function ($type, $msg) use (&$error) {
            $error = $msg;
        });

        try {
            // rename 处理同盘移动；跨盘符（如 C 盘 temp 到 D 盘项目）rename 失败，用 copy+unlink 兜底
            $moved = @rename($this->getPathname(), (string) $target)
                || (@copy($this->getPathname(), (string) $target) && @unlink($this->getPathname()));
        } finally {
            restore_error_handler();
        }

        if (!$moved) {
            throw new FileException(sprintf('Could not move the file "%s" to "%s" (%s)', $this->getPathname(), $target, strip_tags($error)));
        }

        @chmod((string) $target, 0666 & ~umask());

        return $target;
    }
}
