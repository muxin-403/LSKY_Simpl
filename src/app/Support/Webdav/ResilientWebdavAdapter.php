<?php

namespace App\Support\Webdav;

use App\Exceptions\StorageHttpException;
use League\Flysystem\Config;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Sabre\DAV\Client;
use Sabre\HTTP\ClientHttpException;
use Throwable;

/**
 * 「先 PUT、建目录只作兜底」的 WebDAV 适配器。
 *
 * ── 为什么不用上游的 WebDAVAdapter 写 ────────────────────────────────────────
 * 上游 upload() 的顺序是 **先自建父目录、再 PUT**：
 *
 *     upload() → createParentDirFor() → directoryExists()  (PROPFIND)
 *                                    → createDirectory()  (逐级 PROPFIND + MKCOL)
 *              → PUT
 *
 * 也就是说：**只要 PROPFIND / MKCOL 有一个不顺，PUT 根本不会发出去**，哪怕服务端
 * 完全能接受这个文件。2026-10-22 的线上故障就是这么来的 —— 6 次上传全部倒在
 * `UnableToCreateDirectory`（MKCOL 非 201）和 `UnableToCheckExistence`（PROPFIND
 * 返回错误状态）上，日志里连一个字节都没写到远端。事后用同一套凭据直连复现：
 *
 *     PROPFIND /dav/            → 404（几十秒后同样的请求变 207）
 *     MKCOL    /dav/probe/      → 409（重试一次变 201）
 *     PUT      /dav/a/b/c.jpg   → 201，父目录 a/b 由 Cloudreve 自动补齐
 *
 * 即：**服务端的目录类操作会间歇性抽风，写入本身一直是好的**。而 flysystem 把
 * 「能不能写」这件事错误地绑定到了「能不能建目录」上。
 *
 * ── 这个适配器做的事 ────────────────────────────────────────────────────────
 *  1. 直接 PUT，完全绕开 createParentDirFor()。Cloudreve / Nextcloud 这类服务端
 *     会自己补齐父目录；本地磁盘策略本来也不需要预建目录。
 *  2. 只有 PUT 真的回 404/409（标准 WebDAV 的「父集合不存在」）时，才补一次 MKCOL
 *     再重试 —— 而且 **MKCOL 失败不算失败**，因为目标目录可能本来就存在，能否写成
 *     只由后面那次 PUT 决定（报告里「建目录失败时降级为直接 PUT」那条，落在这里）。
 *  3. 对「一时抽风」的状态码（404/408/423/429/5xx）做有限重试，且每次重试前把流
 *     倒回去。PUT 是幂等的，多打一次没有副作用。
 *  4. 最终失败时把状态码装进异常（见 StorageHttpException / UnableToWriteFile 的
 *     message），让上层既能写进日志，也能给用户一句能照着排错的话。
 *
 * 读、删、列目录等其余行为一律沿用上游实现，本类只覆盖写。
 */
class ResilientWebdavAdapter extends WebDAVAdapter
{
    /** 同一次写入最多几次 PUT（1 次正常 + 最多 2 次重试）。 */
    private const MAX_ATTEMPTS = 3;

    /**
     * 认为「重试有意义」的状态码。
     *  404 —— 反向代理/后端瞬时没找到路由（实测过：同一个 PROPFIND 先 404 后 207）
     *  408 / 504 —— 超时；423 —— 资源被锁；429 —— 限流；5xx —— 服务端/后端存储抽风
     * 不含 401/403/507：那是「密码错 / 没权限 / 容量满」，重试只会拖时间。
     */
    private const RETRYABLE_STATUSES = [404, 408, 423, 429, 500, 502, 503, 504];

    /** 重试基础等待（毫秒），按尝试次数线性递增：200ms、400ms。 */
    private const RETRY_BASE_DELAY_MS = 200;

    private Client $davClient;

    private PathPrefixer $pathPrefixer;

    public function __construct(
        Client $client,
        string $prefix = '',
        string $visibilityHandling = self::ON_VISIBILITY_THROW_ERROR,
    ) {
        parent::__construct($client, $prefix, $visibilityHandling);

        // 上游把 $client / $prefixer 存成 private，子类取不到 —— 自己留一份。
        $this->davClient = $client;
        $this->pathPrefixer = new PathPrefixer($prefix);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->put($path, $contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->put($path, $contents);
    }

    /**
     * @param  string  $path  相对路径（不含 prefix，prefix 由 PathPrefixer 拼）
     * @param  resource|string  $contents
     *
     * @throws UnableToWriteFile
     */
    private function put(string $path, mixed $contents): void
    {
        $location = $this->encodePath($this->pathPrefixer->prefixPath($path));
        // 非 seekable 的流（socket / 一次性管道）重试时会从「已读完」的位置继续发，
        // 结果是一个 0 字节的文件 —— 比失败更糟。这种流只给它一次机会。
        $retryable = $this->isRewindable($contents);
        $attempt = 0;
        $parentsEnsured = false;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            $this->rewindContents($contents);

            try {
                $status = (int) ($this->davClient->request('PUT', $location, $contents)['statusCode'] ?? 0);
            } catch (ClientHttpException $e) {
                // 4xx/5xx：Sabre 直接抛异常，状态码在异常里
                $status = $e->getHttpStatus();
            } catch (Throwable $e) {
                // 网络层（连不上 / 超时 / 传一半断了）：没有状态码，只能整体重试
                if ($retryable && $attempt < self::MAX_ATTEMPTS) {
                    $this->waitBeforeRetry($attempt);
                    continue;
                }

                throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
            }

            if ($status >= 200 && $status < 300) {
                return;
            }

            // 父目录真的不存在（标准 WebDAV 回 409，部分服务端回 404）：补建一次目录再试。
            // 这里不 backoff —— 这不是「抽风」，是确定的下一步动作。
            if (! $parentsEnsured && (404 === $status || 409 === $status) && $attempt < self::MAX_ATTEMPTS) {
                $parentsEnsured = true;
                $this->ensureParentDirectory($path);
                continue;
            }

            if ($retryable && $attempt < self::MAX_ATTEMPTS && in_array($status, self::RETRYABLE_STATUSES, true)) {
                $this->waitBeforeRetry($attempt);
                continue;
            }

            throw UnableToWriteFile::atLocation(
                $path,
                "WebDAV PUT 返回 HTTP {$status}（已尝试 {$attempt} 次）",
                // 把状态码放进异常链：日志与用户提示都靠它区分「没权限 / 容量满 / 被限流」
                new StorageHttpException($status),
            );
        }

        // 循环里的每条分支都以 return / throw 结束，这里只是兜底，防止以后改动留下「静默成功」。
        throw UnableToWriteFile::atLocation($path, 'WebDAV PUT 未能在重试次数内完成');
    }

    /**
     * 逐级补建父目录。**建目录失败一律吞掉**：目标目录可能本来就在（Cloudreve 这类
     * 服务端会自己补齐），成败只由紧接着那次 PUT 判定 —— 这正是要从上游「MKCOL 一失败
     * 就抛 UnableToCreateDirectory」里解放出来的那部分行为。
     */
    private function ensureParentDirectory(string $path): void
    {
        $dirname = dirname($path);

        if ('.' === $dirname || '' === $dirname || '/' === $dirname) {
            return;
        }

        try {
            $this->createDirectory($dirname, new Config());
        } catch (Throwable $e) {
            // 有意静默：真正的判定权交给后面的 PUT（失败时它会带上状态码抛出去）。
        }
    }

    /**
     * 重试前把内容流倒回开头，否则第二次 PUT 发出去的是剩余 0 字节（文件会静默变小）。
     */
    private function rewindContents(mixed $contents): void
    {
        if ($this->isRewindable($contents)) {
            @rewind($contents);
        }
    }

    /**
     * 内容能否被倒回去重发（字符串永远可以；流要看 seekable）。
     */
    private function isRewindable(mixed $contents): bool
    {
        if (! is_resource($contents)) {
            return true;
        }

        return ! empty(stream_get_meta_data($contents)['seekable']);
    }

    private function waitBeforeRetry(int $attempt): void
    {
        usleep(self::RETRY_BASE_DELAY_MS * $attempt * 1000);
    }
}
