<?php

namespace App\Http\Controllers;

use App\Enums\GroupConfigKey;
use App\Enums\StrategyKey;
use App\Enums\UserStatus;
use App\Enums\Watermark\Mode;
use App\Exceptions\UploadException;
use App\Http\Result;
use App\Models\Group;
use App\Models\Image;
use App\Models\User;
use App\Services\ImageService;
use App\Utils;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Intervention\Image\Facades\Image as InterventionImage;
use League\Flysystem\FilesystemException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests, Result;

    public function install(Request $request): View|Response
    {
        if (file_exists(base_path('installed.lock'))) {
            if ($request->expectsJson()) {
                return $this->fail('Already installed. if you want to reinstall, please remove installed.lock file.');
            }
            abort(404);
        }

        $extensions = collect([
            ['name' => 'BCMath', 'intro' => '数学精度处理拓展'],
            ['name' => 'Ctype', 'intro' => '字符类型检测拓展'],
            ['name' => 'DOM', 'intro' => 'DOM 对象模型，用于处理文档元素'],
            ['name' => 'Fileinfo', 'intro' => '获取文件信息拓展'],
            ['name' => 'JSON', 'intro' => 'JavaScript 对象符号（JSON）'],
            ['name' => 'Mbstring', 'intro' => '多字节字符串处理拓展'],
            ['name' => 'OpenSSL', 'intro' => '加密拓展'],
            ['name' => 'PCRE', 'intro' => '正则表达式拓展'],
            ['name' => 'PDO', 'intro' => '数据库拓展'],
            ['name' => 'Tokenizer', 'intro' => '令牌处理拓展'],
            ['name' => 'XML', 'intro' => 'Xml 解析器'],
            ['name' => 'Imagick', 'intro' => '高性能图片处理拓展'],
        ])->transform(function ($item) {
            $item['result'] = extension_loaded(strtolower($item['name']));
            return $item;
        })->push([
            'name' => 'putenv、getenv 函数',
            'intro' => '设置和获取环境变量函数',
            'result' => function_exists('putenv') && function_exists('getenv'),
        ])->push([
            'name' => 'exec、shell_exec 函数',
            'intro' => '执行外部命令',
            'result' => function_exists('exec') && function_exists('shell_exec'),
        ])->push([
            'name' => 'chmod、chown、fileperms 函数',
            'intro' => '设置和获取文件、文件夹权限函数',
            'result' => function_exists('chmod') && function_exists('chown') && function_exists('fileperms'),
        ])->push([
            'name' => 'PHP >= 8.0.2',
            'intro' => '最低要求 PHP 8.0.2 版本',
            'result' => phpversion() >= 8,
        ]);

        $status = ! $extensions->where('result', false)->isNotEmpty();

        if ($request->method() === 'POST') {
            try {
                $request->validate([
                    'account.email' => 'required|email',
                    'account.password' => 'required|between:8,32'
                ], [], [
                    'account.email' => '管理员账号邮箱',
                    'account.password' => '管理员账号密码'
                ]);

                $data = collect($request->only([
                    'connection', 'host', 'port', 'database', 'username', 'password',
                ]))->transform(fn($item, $key) => ['--'.$key => $item])->collapse();
                $output = new BufferedOutput();
                $exitCode = Artisan::call('lsky:install', $data->toArray(), $output);
                if ($exitCode) {
                    throw new \Exception(str_replace(PHP_EOL, '<br/>', $output->fetch()));
                }
                $user = new User([
                    'name' => '超级管理员',
                    'email' => $request->input('account.email'),
                    'password' => Hash::make($request->input('account.password')),
                    'registered_ip' => $request->ip(),
                ]);
                $user->group_id = Group::query()->first()['id'];
                $user->is_adminer = true;
                $user->status = UserStatus::Normal;
                $user->email_verified_at = date('Y-m-d H:i:s');
                $user->save();
            } catch (\Throwable $e) {
                @unlink(base_path('installed.lock'));
                Utils::e($e, '执行安装程序时出现异常');
                return $this->fail($e->getMessage());
            }
            return $this->success();
        }

        return view('install', compact('extensions', 'status'));
    }

    public function upload(Request $request, ImageService $service): Response
    {
        try {
            $image = $service->store($request);
        } catch (UploadException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            Utils::e($e, 'Web 上传文件时发生异常');
            if (config('app.debug')) {
                return $this->fail($e->getMessage());
            }
            return $this->fail('服务异常，请稍后再试');
        }
        return $this->success('上传成功', $image->setAppends(['pathname', 'links'])->only(
            'id', 'pathname', 'origin_name', 'size', 'mimetype', 'md5', 'sha1', 'links'
        ));
    }

    /**
     * 图片代理输出：`GET /{key}.{extension}` 的唯一处理器。
     *
     * 全站图片都走这里（`Image::url()` 恒返回本站地址），所以这条路径是全站最大流量入口。
     * 两个分支：
     *  - 需要「完整字节串」时（动态水印重绘 / psd、tif、bmp 转码，以及 WebDAV 开了服务端
     *    缓存的场景）走 read() + 可选缓存；
     *  - 其余一律 readStream() + fpassthru 流式输出，避免把整张图读进 PHP 内存
     *    （默认 memory_limit 只有 64M，一张 100MB 的图足以打爆 worker）。
     */
    public function output(Request $request, ImageService $service): Response|StreamedResponse
    {
        /** @var Image $image */
        $image = Image::query()
            ->with(['group', 'strategy'])
            ->where('key', $request->route('key'))
            ->where('extension', strtolower($request->route('extension')))
            ->firstOr(fn() => abort(404));

        // 策略可能已被删除（StrategyController::delete 会把 strategy_id 置 null），
        // 这种图取不到后端适配器 —— 直接 404，否则 filesystem() 会抛 TypeError（500）。
        if (is_null($image->strategy) || is_null($image->group)) {
            abort(404);
        }

        $extension = $image->extension;
        $configs = $image->group->configs;

        $headers = $this->imageHeaders($image);

        // 条件请求：ETag 命中直接 304，省掉一次后端读取（Cloudreve 回源是实打实的开销）。
        if ($request->headers->get('If-None-Match') === $headers['ETag']) {
            return response('', 304, ['ETag' => $headers['ETag'], 'Cache-Control' => $headers['Cache-Control']]);
        }

        $mimetype = self::MIME_TYPES[$extension] ?? 'application/octet-stream';
        $watermarkConfigs = collect($configs->get(GroupConfigKey::WatermarkConfigs));

        $needWatermark = $configs->get(GroupConfigKey::IsEnableWatermark)
            && $watermarkConfigs->get('mode', Mode::Overlay) == Mode::Dynamic
            && ! in_array($extension, ['ico', 'gif']);
        $needTranscode = in_array($extension, ['psd', 'tif', 'bmp']);

        $cacheTtl = (int)$configs->get(GroupConfigKey::ImageCacheTtl, 0);
        // 只给远端存储做服务端缓存：本地策略直接流式读盘，缓存只会白占一份磁盘。
        $useCache = $cacheTtl > 0 && $image->strategy->key === StrategyKey::Webdav;

        if ($needWatermark || $needTranscode || $useCache) {
            // 解码大图是内存大户，与 ImageService::makeThumbnail() 保持同一档位。
            @ini_set('memory_limit', '512M');

            $cacheKey = "image_{$image->key}";
            $contents = $useCache ? Cache::get($cacheKey) : null;

            if (is_null($contents)) {
                try {
                    $contents = $image->filesystem()->read($image->pathname);
                } catch (FilesystemException $e) {
                    Utils::e($e, '图片输出时出现异常');
                    abort(404);
                }

                // 水印与转码都放在写缓存**之前**：否则缓存里躺的是原图，每次请求都要
                // 重新转一次码，缓存等于白做（旧实现的顺序就是反的）。
                if ($needWatermark) {
                    $quality = $configs->get(GroupConfigKey::ImageSaveQuality, 75);
                    $contents = $service->stickWatermark($contents, $watermarkConfigs)
                        ->encode($extension, $quality)->getEncoded();
                }

                if ($needTranscode) {
                    $contents = InterventionImage::make($contents)->encode('png')->getEncoded();
                    $mimetype = 'image/png';
                }

                if ($useCache) {
                    Cache::put($cacheKey, $contents, $cacheTtl);
                }
            } elseif ($needTranscode) {
                // 缓存里存的已经是转码后的 png
                $mimetype = 'image/png';
            }

            return response($contents, 200, $headers + ['Content-Type' => $mimetype]);
        }

        // 流式分支：必须在 return 之前把流打开 —— 远端 401/404 还能 abort(404)，
        // 放进回调里再抛异常时响应头已经发出去了，只能吐半个文件。
        try {
            $stream = $image->filesystem()->readStream($image->pathname);
        } catch (FilesystemException $e) {
            Utils::e($e, '图片输出时出现异常');
            abort(404);
        }

        return response()->stream(function () use ($stream) {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers + ['Content-Type' => $mimetype]);
    }

    /**
     * 扩展名 → Content-Type 白名单。
     *
     * 刻意不用库里存的 `mimetype`：同源代理下，一个伪装成 jpg 的 HTML 会变成同源 XSS，
     * 白名单 + nosniff 才挡得住（扩展名本身还受 routes/image.php 的后缀过滤）。
     */
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'psd' => 'image/vnd.adobe.photoshop',
    ];

    /**
     * 代理响应的缓存头。
     *
     * 不能指望路由上的 `cache.headers` 中间件：Laravel 9 的 SetCacheHeaders 在
     * `! $response->getContent()` 时直接返回，而 StreamedResponse::getContent() 恒为 false
     * ⇒ 流式响应拿不到任何缓存头。所以这里显式给。
     */
    private function imageHeaders(Image $image): array
    {
        return [
            'ETag' => '"'.$image->md5.'"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
