<?php

namespace App\Services;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Green\Green;
use App\Enums\ConfigKey;
use App\Enums\GroupConfigKey;
use App\Enums\ImagePermission;
use App\Enums\Scan\AliyunOption;
use App\Enums\Scan\NsfwJsOption;
use App\Enums\Scan\TencentOption;
use App\Enums\Strategy\LocalOption;
use App\Enums\Strategy\WebDavOption;
use App\Enums\StrategyKey;
use App\Enums\UserConfigKey;
use App\Enums\UserStatus;
use App\Enums\Watermark\FontOption;
use App\Enums\Watermark\ImageOption;
use App\Enums\Watermark\Mode;
use App\Exceptions\UploadException;
use App\Models\Group;
use App\Models\Image;
use App\Models\Strategy;
use App\Models\User;
use App\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image as InterventionImage;
use Intervention\Image\Imagick\Font;
use Intervention\Image\ImageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Sabre\DAV\Client;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ims\V20201229\ImsClient;
use TencentCloud\Ims\V20201229\Models\ImageModerationRequest;

class ImageService
{
    /**
     * @param  Request  $request
     * @return Image
     * @throws UploadException
     */
    public function store(Request $request): Image
    {
        $file = $request->file('file');

        if (Auth::guest() && !Utils::config(ConfigKey::IsAllowGuestUpload, true)) {
            throw new UploadException('管理员关闭了游客上传');
        }

        $image = new Image();
        /** @var User|null $user */
        $user = $request->user();

        /** @var Group $group */
        $group = ! is_null($user) ? $user->group : Group::query()->where('is_guest', true)->first();
        // 组配置
        $configs = $group->configs;
        // 存储策略
        $strategies = $group->strategies()->get();
        if ($strategies->isEmpty()) {
            throw new UploadException('没有可用的存储，请联系管理员。');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $configs->get(GroupConfigKey::AcceptedFileSuffixes))) {
            throw new UploadException('不支持的文件类型');
        }

        if ($file->getSize() / 1024 > $configs->get(GroupConfigKey::MaximumFileSize)) {
            throw new UploadException("图片大小超出限制");
        }

        // 图片所属组
        $image->group_id = $group->id;
        // 图片默认权限
        $image->permission = ImagePermission::Private;

        if ($request->has('strategy_id')) {
            if (! $strategy = $strategies->find($request->input('strategy_id'))) {
                throw new UploadException('选定的策略不存在');
            }
        } else {
            // 没有指定则选择第一个策略
            $strategy = $strategies->first();
        }

        $image->strategy_id = $strategy->id;

        // 默认存储策略
        if (! is_null($user)) {
            if (Utils::config(ConfigKey::IsUserNeedVerify) && ! $user->email_verified_at) {
                throw new UploadException('账户未验证');
            }

            if ($user->status !== UserStatus::Normal) {
                throw new UploadException('账号状态异常');
            }

            if ($file->getSize() / 1024 + $user->images()->sum('size') > $user->capacity) {
                throw new UploadException('存储空间不足');
            }

            // 图片保存至默认相册(若有)
            if ($albumId = $user->configs->get(UserConfigKey::DefaultAlbum)) {
                if ($user->albums()->where('id', $albumId)->exists()) {
                    $image->album_id = $albumId;
                }
            }

            $image->user_id = $user->id;
            // 用户设置的图片默认权限
            $image->permission = $user->configs->get(UserConfigKey::DefaultPermission, ImagePermission::Private);
        }

        // 上传频率限制
        $this->rateLimiter($configs, $request);

        // 图片处理，跳过 ico gif
        if (! in_array($extension, ['ico', 'gif'])) {
            // 图片保存质量与格式
            $quality = $configs->get(GroupConfigKey::ImageSaveQuality, 75);
            $format = $configs->get(GroupConfigKey::ImageSaveFormat);
            if ($quality < 100 || $format) {
                // 获取拓展名，判断是否需要转换
                $format = $format ?: $extension;
                $filename = Str::replaceLast($extension, $format, $file->getClientOriginalName());
                $handleImage = InterventionImage::make($file)->save('tmp_' . md5_file($file->getRealPath()), $quality);
                $file = new UploadedFile($handleImage->basePath(), $filename, $handleImage->mime());
                // 重新设置拓展名
                $extension = $format;
                $handleImage->destroy();
            }

            // 是否启用水印，覆盖原图片
            if (
                $configs->get(GroupConfigKey::IsEnableWatermark) &&
                collect($configs->get(GroupConfigKey::WatermarkConfigs))->get('mode', Mode::Overlay) == Mode::Overlay
            ) {
                $watermarkImage = $this->stickWatermark($file, collect($configs->get(GroupConfigKey::WatermarkConfigs)));
                $watermarkImage->save();
                $file = new UploadedFile($watermarkImage->basePath(), $file->getClientOriginalName(), $file->getMimeType());
                $watermarkImage->destroy();
            }
        }

        $filename = $this->replacePathname(
            $configs->get(GroupConfigKey::PathNamingRule).'/'.$configs->get(GroupConfigKey::FileNamingRule), $file,
        );
        $pathname = $filename.".{$extension}";

        [$width, $height] = @getimagesize($file->getRealPath()) ?: [400, 400];

        $image->fill([
            'md5' => md5_file($file->getRealPath()),
            'sha1' => sha1_file($file->getRealPath()),
            'path' => $configs->get(GroupConfigKey::PathNamingRule) ? dirname($pathname) : '',
            'name' => basename($pathname),
            'origin_name' => $file->getClientOriginalName(),
            'size' => $file->getSize() / 1024,
            'mimetype' => $file->getMimeType(),
            'extension' => strtolower($extension),
            'width' => $width,
            'height' => $height,
            'is_unhealthy' => false,
            'uploaded_ip' => $request->ip(),
        ]);

        $filesystem = new Filesystem($this->getAdapter($strategy));

        // 检测该策略是否存在该图片，有则只创建记录不保存文件
        /** @var Image $existing */
        $existing = Image::query()->when($image->strategy_id, function (Builder $builder, $id) {
            $builder->where('strategy_id', $id);
        })->where('md5', $image->md5)->where('sha1', $image->sha1)->first();
        if (is_null($existing)) {
            $handle = fopen($file, 'r');
            try {
                $filesystem->writeStream($pathname, $handle);
            } catch (FilesystemException $e) {
                Utils::e($e, '保存图片时出现异常');
                throw new UploadException(config('app.debug', false) ? $e->getMessage() : '图片上传失败');
            }
            if (is_resource($handle)) @fclose($handle);
        } else {
            $image->fill($existing->only('path', 'name'));
        }

        // 增加当前用户的图片数量和相册图片数量
        $updateNum = function ($method) use ($user, $image) {
            DB::transaction(function () use ($method, $user, $image) {
                if (! is_null($user)) {
                    $user->{$method}('image_num');
                }
                if (! is_null($image->album)) {
                    $image->album->{$method}('image_num');
                }
            });
        };

        try {
            // 图片记录保存失败，删除物理文件
            if (! $image->save()) {
                if (is_null($existing)) $filesystem->delete($image->pathname);
                throw new \Exception('图片记录保存失败');
            }
            $updateNum('increment');
        } catch (\Throwable $e) {
            Utils::e($e, '保存图片记录时出现异常');
            throw new UploadException('图片记录保存失败');
        }

        // 图片检测，跳过 tif、ico、psd 格式
        if ($configs->get(GroupConfigKey::IsEnableScan) && ! in_array($extension, ['psd', 'ico', 'tif'])) {
            $scanConfigs = $configs->get(GroupConfigKey::ScanConfigs);
            if ($this->scan(
                driver: $scanConfigs['driver'],
                configs: collect($scanConfigs['drivers'][$scanConfigs['driver']]),
                image: $image,
                file: $file,
            )) {
                // 标记 or 删除
                if ($configs->get(GroupConfigKey::ScannedAction) === 'delete') {
                    $image->delete();
                    $updateNum('decrement');
                    throw new UploadException('图片涉嫌违规，禁止上传。');
                } else {
                    $image->is_unhealthy = true;
                    $image->save();
                }
            }
        }

        $this->makeThumbnail($image, $file);

        // 上传完成后删除临时文件
        unlink($file->getPathname());

        return $image;
    }

    /**
     * 按策略构造 flysystem 适配器。
     *
     * $httpOptions 是给「能配超时的驱动」留的口子 —— 当前只落在 WebDAV（Sabre 客户端的
     * curl 选项 connect_timeout / timeout）：批量导出这种长响应里，一张图卡住就会占住一个
     * worker，需要靠超时把它掐掉。传空数组时行为与从前**完全一致**。
     */
    public function getAdapter(Strategy $strategy, array $httpOptions = []): FilesystemAdapter
    {
        $configs = $strategy->configs;
        return match ($strategy->key) {
            StrategyKey::Local => new LocalFilesystemAdapter(
                location: $configs->get(LocalOption::Root) ?: config('filesystems.disks.uploads.root')
            ),
            StrategyKey::Webdav => $this->makeWebdavAdapter($configs, $httpOptions),
            // 库里若残留已下线驱动的策略（key=2..8/10），这里必须给出明确异常，
            // 而不是让 match 抛 UnhandledMatchError（后者连 message 都没有）。
            default => throw new \RuntimeException("不支持的存储策略类型：{$strategy->key}"),
        };
    }

    /**
     * 构造 WebDAV 适配器。这里必须兜住三个坑，否则接 Cloudreve（或任何标准 WebDAV 服务）
     * 都会「策略保存成功但图片传不上去」：
     *
     *  1. baseUri 必须以 `/` 结尾。Sabre 用 RFC 3986 的相对解析（Uri\resolve）把
     *     相对路径拼到基地址上，基地址不带尾斜杠时最后一段会被当成文件名丢掉 ——
     *     `https://host/dav` + `2026/10/a.jpg` 会变成 `https://host/2026/10/a.jpg`。
     *     Cloudreve 官方给的地址正好是不带尾斜杠的 `https://域名/dav`。
     *  2. auth_type 为空时**不能**把 0 传进去。Sabre 用 `isset($settings['authType'])`
     *     判断，0 也满足 isset，于是 CURLOPT_HTTPAUTH = 0（等价匿名请求），必然 401；
     *     省略该键才会回退到 CURLAUTH_BASIC|CURLAUTH_DIGEST 自动协商。
     *  3. prefix 两端斜杠交给 PathPrefixer 处理，这里先 trim 掉，避免库里存进噪音。
     */
    private function makeWebdavAdapter(Collection $configs, array $httpOptions = []): WebDAVAdapter
    {
        $settings = [
            'baseUri' => rtrim((string)$configs->get(WebDavOption::BaseUri), '/').'/',
            'userName' => (string)$configs->get(WebDavOption::Username),
            'password' => (string)$configs->get(WebDavOption::Password),
        ];

        if ($authType = (int)$configs->get(WebDavOption::AuthType)) {
            $settings['authType'] = $authType;
        }

        $client = new Client($settings);
        // 远端不可达时 curl 默认会一直等，一个 worker 就这么被占死。
        $client->addCurlSetting(CURLOPT_CONNECTTIMEOUT, (int)($httpOptions['connect_timeout'] ?? 10));
        if (! empty($httpOptions['timeout'])) {
            $client->addCurlSetting(CURLOPT_TIMEOUT, (int)$httpOptions['timeout']);
        }
        // 连接建立后长时间没数据（对端假死）也要能自己断开。
        $client->addCurlSetting(CURLOPT_LOW_SPEED_LIMIT, 1024);
        $client->addCurlSetting(CURLOPT_LOW_SPEED_TIME, 60);

        return new WebDAVAdapter($client, trim((string)$configs->get(WebDavOption::Prefix), '/'));
    }

    /**
     * @throws UploadException
     */
    protected function rateLimiter(Collection $configs, Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        $carbon = Carbon::now();
        $array = [
            'minute' => ['key' => GroupConfigKey::LimitPerMinute, 'str' => '分钟'],
            'hours' => ['key' => GroupConfigKey::LimitPerHour, 'str' => '小时'],
            'days' => ['key' => GroupConfigKey::LimitPerDay, 'str' => '天'],
            'weeks' => ['key' => GroupConfigKey::LimitPerWeek, 'str' => '周'],
            'months' => ['key' => GroupConfigKey::LimitPerMonth, 'str' => '月'],
        ];

        foreach ($array as $key => $item) {
            $value = $configs->get($item['key'], 0);
            $count = Image::query()->whereBetween('created_at', [
                $carbon->parse("-1 {$key}"), $carbon,
            ])->when(! is_null($user), function (Builder $builder) use ($user) {
                $builder->where('user_id', $user->id);
            }, function (Builder $builder) use ($request) {
                $builder->where('uploaded_ip', $request->ip())->whereNull('user_id');
            })->count();

            if ($value && $count >= $value) {
                throw new UploadException("每{$item['str']}内你最多可以上传 {$value} 张图片");
            }
        }
    }

    /**
     * 检测图片是否违规
     *
     * @param $driver
     * @param  Collection  $configs
     * @param  Image  $image
     * @param  UploadedFile $file
     * @return bool true=违规
     * @throws UploadException
     */
    public function scan($driver, Collection $configs, Image $image, UploadedFile $file): bool
    {
        $flag = false;
        try {
            if ($driver === 'tencent') {
                // 图片大小不得超过 5MB
                if ($file->getSize() >= 5242880) {
                    return false;
                }

                $cred = new Credential($configs->get(TencentOption::SecretId), $configs->get(TencentOption::SecretKey));
                $httpProfile = new HttpProfile();
                $httpProfile->setEndpoint($configs->get(TencentOption::Endpoint));
                $clientProfile = new ClientProfile();
                $clientProfile->setHttpProfile($httpProfile);
                $client = new ImsClient($cred, $configs->get(TencentOption::Region), $clientProfile);
                $req = new ImageModerationRequest();
                $params = [
                    "FileContent" => base64_encode($file->getContent()),
                ];
                if ($configs->get(TencentOption::BizType)) {
                    $params['BizType'] = $configs->get(TencentOption::BizType);
                }
                $req->fromJsonString(json_encode($params));
                $resp = $client->ImageModeration($req);

                if ($resp->getSuggestion() === 'Block') {
                    $flag = true;
                }
            }

            if ($driver === 'aliyun') {
                // 20 mb以内、宽高不超过 30000px
                if ($file->getSize() >= 20971520 || $image->width >= 30000 || $image->height >= 30000) {
                    return false;
                }

                AlibabaCloud::accessKeyClient(
                    $configs->get(AliyunOption::AccessKeyId),
                    $configs->get(AliyunOption::AccessKeySecret),
                )->regionId($configs->get(AliyunOption::RegionId))->asDefaultClient();
                $task = ['dataId' => $image->id, 'url' => $image->url];
                $response = Green::v20180509()->imageSyncScan()->body(json_encode([
                    'tasks' => [$task],
                    'scenes' => $configs->get(AliyunOption::Scenes),
                    'bizType' => $configs->get(AliyunOption::BizType)
                ]))->request();
                if (200 != $response->get('code')) {
                    throw new \Exception("detect not success. code:" . $response->get('code'));
                }
                if ($result = $response->data[0] ?? null) {
                    if (200 == $result->code) {
                        foreach ($result->results as $item) {
                            if ($item->suggestion === 'block') {
                                $flag = true;
                            }
                        }
                    }
                }
            }

            if ($driver === 'nsfwjs') {
                // 不支持 bmp 格式
                if ($image->extension === 'bmp') {
                    return false;
                }
                $response = Http::timeout(180)->withOptions(['timeout' => 180])->attach(
                    $configs->get(NsfwJsOption::AttrName, 'image'), $file->getContent(), $file->getClientOriginalName(),
                )->post($configs->get(NsfwJsOption::ApiUrl));
                $ratio = $configs->get(NsfwJsOption::Threshold, 60) / 100;

                if ($response->json('hentai', 0.00) >= $ratio) {
                    $flag = true;
                }

                if ($response->json('porn', 0.00) >= $ratio) {
                    $flag = true;
                }

                if ($response->json('sexy', 0.00) >= $ratio) {
                    $flag = true;
                }
            }
        } catch (\Throwable $e) {
            throw new UploadException('Scan: '.$e->getMessage());
        }

        return $flag;
    }

    /**
     * 合成水印
     *
     * @param  mixed  $image
     * @param  Collection  $configs
     * @return \Intervention\Image\Image
     */
    public function stickWatermark(mixed $image, Collection $configs): \Intervention\Image\Image
    {
        $driver = $configs->get('driver');
        $options = collect($configs->get("drivers")[$driver]);
        $image = InterventionImage::make($image);

        $position = $options->get(FontOption::Position, 'bottom-right');
        $offsetX = (int) $options->get(FontOption::X, 10);
        $offsetY = (int) $options->get(FontOption::Y, 10);

        $watermark = $this->getWatermark($driver, $options);

        // 原图宽高
        $imageWidth = $image->width();
        $imageHeight = $image->height();

        // 水印画布宽高
        $watermarkWidth = $watermark->width();
        $watermarkHeight = $watermark->height();

        if ($position === 'tiled') {
            // 平铺水印
            for ($x = 0; $x < $imageWidth; $x++) {
                for ($y = 0; $y < $imageHeight; $y++) {
                    $image->insert($watermark, '', $x, $y);
                    $y += $watermarkHeight + $offsetY;
                }
                $x += $watermarkWidth + $offsetX;
            }
        } else {
            $image->insert($watermark, $position, $offsetX, $offsetY);
        }

        return $image;
    }

    /**
     * 生成缩略图，缩略图目录必须在 public 目录下，且该目录必须存在
     *
     * @param mixed $image 图片数据
     * @param mixed $data 物理图片数据
     * @param int $max 最大宽高
     * @param bool $force 是否强制覆盖
     * @return void
     */
    public function makeThumbnail(Image $image, mixed $data, int $max = 400, bool $force = false): void
    {
        $pathname = public_path($image->getThumbnailPathname());

        if (! file_exists($pathname) || $force) {
            try {
                // 创建文件夹
                if (! is_dir(dirname($pathname))) {
                    @mkdir(dirname($pathname));
                }

                // 生成缩略图（原图缩放后统一编码为 png）
                @ini_set('memory_limit', '512M');

                $img = InterventionImage::make($data);

                $width = $w = $image->width;
                $height = $h = $image->height;

                if ($w > $max && $h > $max) {
                    $scale = min($max / $w, $max / $h);
                    $width  = (int)($w * $scale);
                    $height = (int)($h * $scale);
                }

                $img->fit($width, $height, fn($constraint) => $constraint->upsize())->encode('png', 60)->save($pathname);
                $img->destroy();
            } catch (\Throwable $e) {
                Utils::e($e, '生成缩略图时出现异常');
            }
        }
    }

    /**
     * 获取水印画布
     *
     * @param  string  $driver
     * @param  Collection  $options
     * @return \Intervention\Image\Image
     */
    private function getWatermark(string $driver, Collection $options): \Intervention\Image\Image
    {
        $manager = new ImageManager(config('image'));

        if ($driver === 'image') {
            $watermark = $manager->make(storage_path('app/public/'.trim($options->get(ImageOption::Image), '/')));
            $opacity = (int) $options->get(ImageOption::Opacity, 0);
            $rotate = (int) $options->get(ImageOption::Rotate, 0);
            $width = $options->get(ImageOption::Width, 0);
            $height = $options->get(ImageOption::Height, 0);

            if ($opacity && $opacity != 100) {
                $watermark->opacity((int) min($opacity, 100));
            }

            if ($rotate) {
                $watermark->rotate($rotate);
            }

            if ($width + $height > 0) {
                $watermark->resize($width ?: null, $height ?: null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }
            return $watermark;
        } else {
            $text = $options->get(FontOption::Text, Utils::config(ConfigKey::AppName));
            $font = new Font(urldecode($text));
            $font->valign('top')
                ->file(storage_path('app/public/'.trim($options->get(FontOption::Font), '/')))
                ->size((int) $options->get(FontOption::Size, 50))
                ->angle((int) $options->get(FontOption::Angle, 0))
                ->color($options->get(FontOption::Color, '000000')); // 十六进制 or rgba
            $box = $font->getBoxSize();
            $canvas = $manager->canvas($box['width'], $box['height']);
            $font->applyToImage($canvas);
            return $manager->make($canvas);
        }
    }

    protected function replacePathname(string $pathname, UploadedFile $file): string
    {
        $array = [
            '{Y}' => date('Y'),
            '{y}' => date('y'),
            '{m}' => date('m'),
            '{d}' => date('d'),
            '{timestamp}' => time(),
            '{uniqid}' => uniqid(),
            '{md5}' => md5(microtime().Str::random()),
            '{md5-16}' => substr(md5(microtime().Str::random()), 0, 16),
            '{str-random-16}' => Str::random(),
            '{str-random-10}' => Str::random(10),
            '{filename}' => $this->sanitizeFilename(Str::replaceLast('.'.$file->getClientOriginalExtension(), '', $file->getClientOriginalName())),
            '{uid}' => Auth::check() ? Auth::id() : 0,
        ];
        return str_replace(array_keys($array), array_values($array), $pathname);
    }

    /**
     * 清掉「出现在 URL 里有特殊含义、或会污染文件系统/HTTP 头」的字符。
     *
     * 背景：命名规则替换出来的结果会被直接拼成存储路径，再由 Image::url() 原样拼成直链
     * —— 全程没有 rawurlencode。文件名里带 ? 时，这个 ? 会被浏览器和服务端当作**查询串
     * 起点**，服务端拿到的文件名只剩 ? 之前的部分（实测：请求 /a?b.jpg，服务端去找文件
     * "a"，而磁盘上的文件叫 "a?b.jpg"）⇒ 直链 404、图片永远打不开。# 更彻底：浏览器把
     * 它当锚点，请求根本不会发出去。% 会被当作百分号编码解析。
     *
     * 这些字符在正常文件名里不会出现（Windows/macOS 的文件系统本身就禁止 ? # % 等），
     * 所以对正常文件名这是**恒等变换** —— 只有通过 API/脚本构造出来的畸形名字会被替换掉，
     * 不改变任何现有图片的命名结果与链接形态。
     *
     * 注意：这里只替换危险字符，不做全量转义/编码。转义会把老图与新图的路径规则分开
     * （新图 URL 变成 %3F 这类形态），而现有行为必须保持原样。
     */
    protected function sanitizeFilename(string $name): string
    {
        // URL 特殊字符 + 文件系统/HTTP 头里有风险的字符 → 下划线
        $name = str_replace(
            ['?', '#', '%', '+', '/', '\\', '"', "'", '<', '>', '|', '*', ':', ' '],
            '_',
            $name
        );

        // 控制字符（\n \r \t \0 等）→ 下划线，避免污染 HTTP 头与日志
        return preg_replace('/[\x00-\x1F\x7F]/', '_', $name);
    }
}
