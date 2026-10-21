<?php

namespace App\Enums;

/**
 * 存储驱动标识。
 *
 * 本 fork 只保留「本地」与「WebDav」两种 —— 其余驱动（S3/OSS/COS/Kodo/USS/SFTP/FTP/Minio）
 * 连同其表单、校验、适配器已全部移除，图片一律经本站代理输出，不再需要任何直连域名。
 *
 * 数值保持与历史一致（Local=1、Webdav=9）：曾用这些 key 落库的策略/备份在导入本版本后
 * 仍能正确解析，不会因为重新编号而指向别的驱动。
 */
final class StrategyKey
{
    /** @var int 本地 */
    const Local = 1;

    /** @var int WebDav */
    const Webdav = 9;
}
