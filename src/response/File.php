<?php

namespace think\worker\response;

use DateTime;
use RuntimeException;
use SplFileInfo;
use think\Response;
use Workerman\Protocols\Http\Response as WkResponse;

class File extends Response
{
    public const DISPOSITION_ATTACHMENT = 'attachment';
    public const DISPOSITION_INLINE     = 'inline';

    protected $header = [
        'Content-Type'  => 'application/octet-stream',
        'Accept-Ranges' => 'bytes',
    ];

    /**
     * @var SplFileInfo
     */
    protected $file;

    public function __construct($file, ?string $contentDisposition = null, bool $autoEtag = true, bool $autoLastModified = true, bool $autoContentType = true)
    {
        $this->setFile($file, $contentDisposition, $autoEtag, $autoLastModified, $autoContentType);
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file, ?string $contentDisposition = null, bool $autoEtag = true, bool $autoLastModified = true, bool $autoContentType = true)
    {
        if (!$file instanceof SplFileInfo) {
            $file = new SplFileInfo((string) $file);
        }

        if (!$file->isReadable()) {
            throw new RuntimeException('File must be readable.');
        }

        $this->header['Content-Length'] = $file->getSize();

        $this->file = $file;

        if ($autoEtag) {
            $this->setAutoEtag();
        }

        if ($autoLastModified) {
            $this->setAutoLastModified();
        }

        if ($contentDisposition) {
            $this->setContentDisposition($contentDisposition);
        }

        if ($autoContentType) {
            $this->setAutoContentType();
        }

        return $this;
    }

    public function setAutoContentType()
    {
        // 优先按扩展名映射 MIME（与 Workerman/webman 的静态文件服务一致），
        // finfo 会把 CSS 等纯文本文件误判为 text/plain，导致浏览器拒绝加载
        $extension = strtolower($this->file->getExtension());
        $mime      = (new WkResponse())->getMimeType($extension);

        if ($mime !== 'application/octet-stream') {
            $this->header['Content-Type'] = $mime;
            return;
        }

        if (extension_loaded('fileinfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $mimeType = finfo_file($finfo, $this->file->getPathname());
            if ($mimeType) {
                $this->header['Content-Type'] = $mimeType;
            }
        }
    }

    public function setContentDisposition(string $disposition, string $filename = '')
    {
        if ('' === $filename) {
            $filename = $this->file->getFilename();
        }

        $this->header['Content-Disposition'] = "{$disposition}; filename=\"{$filename}\"";

        return $this;
    }

    public function setAutoLastModified()
    {
        $mTime = $this->file->getMTime();
        if ($mTime) {
            $date = DateTime::createFromFormat('U', (string) $mTime);
            $this->lastModified($date->format('D, d M Y H:i:s') . ' GMT');
        }
        return $this;
    }

    /**
     * ETag 静态缓存：path|mtime|size => etag，避免大文件每请求重复计算 sha1
     * @var array<string, string>
     */
    protected static array $etagCache = [];

    public function setAutoEtag()
    {
        $pathname = $this->file->getPathname();
        $cacheKey = $pathname . '|' . $this->file->getMTime() . '|' . $this->file->getSize();

        if (!isset(static::$etagCache[$cacheKey])) {
            if (count(static::$etagCache) > 1024) {
                static::$etagCache = array_slice(static::$etagCache, -512, preserve_keys: true);
            }

            static::$etagCache[$cacheKey] = "W/\"" . sha1_file($pathname) . "\"";
        }

        return $this->eTag(static::$etagCache[$cacheKey]);
    }

    protected function sendData(string $data): void
    {
        readfile($this->file->getPathname());
    }
}
