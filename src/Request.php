<?php

namespace think\worker;

use think\File;
use think\worker\file\UploadedFile;

/**
 * Workerman 环境下的请求对象。
 *
 * 覆盖 dealUploadFile：将上传文件包装为 think\worker\file\UploadedFile，
 * 使 is_uploaded_file()/move_uploaded_file() 语义在 Workerman 下依然成立，
 * request()->file() 可与 PHP-FPM 环境一致地使用（含 move/Filesystem::putFile 等）。
 *
 * 覆盖 file：dealUploadFile 的结果按请求缓存。ThinkPHP 原生实现每次调用 file()
 * 都从原始数组重新构造对象，一旦某个文件已被 move() 移走，再次调用会对已不存在的
 * 临时路径重新构造并抛出 FileNotFound（在 Workerman Fiber 上下文中会导致进程崩溃）。
 */
class Request extends \think\Request
{
    /** @var array|false|null dealUploadFile 结果缓存，false 表示尚未转换 */
    protected $uploadedFiles = false;

    public function file(string $name = '')
    {
        if ($this->uploadedFiles === false) {
            $this->uploadedFiles = parent::file('') ?: [];
        }

        $files = $this->uploadedFiles;

        if (empty($files)) {
            return '' === $name ? [] : null;
        }

        if (str_contains($name, '.')) {
            [$name, $sub] = explode('.', $name);
        }

        if ('' === $name) {
            return $files;
        } elseif (isset($sub) && isset($files[$name][$sub])) {
            return $files[$name][$sub];
        }

        return $files[$name] ?? null;
    }

    protected function dealUploadFile(array $files, string $name): array
    {
        $array = [];
        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                $item  = [];
                $keys  = array_keys($file);
                $count = count($file['name']);

                for ($i = 0; $i < $count; $i++) {
                    if ($file['error'][$i] > 0) {
                        if ($name == $key) {
                            $this->throwUploadFileError($file['error'][$i]);
                        } else {
                            continue;
                        }
                    }

                    $temp['key'] = $key;

                    foreach ($keys as $_key) {
                        $temp[$_key] = $file[$_key][$i];
                    }

                    $item[] = new UploadedFile($temp['tmp_name'], $temp['name'], $temp['type'], $temp['error']);
                }

                $array[$key] = $item;
            } else {
                if ($file instanceof File) {
                    $array[$key] = $file;
                } else {
                    if ($file['error'] > 0) {
                        if ($key == $name) {
                            $this->throwUploadFileError($file['error']);
                        } else {
                            continue;
                        }
                    }

                    $array[$key] = new UploadedFile($file['tmp_name'], $file['name'], $file['type'], $file['error']);
                }
            }
        }

        return $array;
    }
}
