<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 带 HTTP 状态码的存储端异常。
 *
 * 存在的唯一理由：**状态码是排查存储问题的第一信息，但它一直丢在半路**。
 * 上游 league/flysystem 的 UnableToCreateDirectory 文案里不含 statusCode
 * （`Failed to create directory at: 2026/`），而 Sabre 的 ClientHttpException 又被包在
 * previous 里，日志只打 message 就完全看不到 403/409/429/507 这种决定性区别。
 *
 * 所以适配器把「最终判定」的状态码装进这个异常里，一路（作为 previous 或本体）带到
 * catch，最后由 Utils::httpStatus() 沿着异常链取出来 —— 既写进日志，也用来给用户
 * 一句能照着排错的提示，而不是千篇一律的「图片上传失败」。
 */
class StorageHttpException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct('' === $message ? "Storage responded with HTTP {$status}" : $message, $status, $previous);
    }

    /**
     * HTTP 状态码（401/403/405/409/423/429/5xx…）。
     */
    public function getHttpStatus(): int
    {
        return $this->status;
    }
}
