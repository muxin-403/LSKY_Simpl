<?php

namespace App\Http\Requests\Admin;

use App\Enums\StrategyKey;
use App\Http\Requests\FormRequest;

class StrategyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * 注意：这里**没有** configs.url / configs.queries —— 本 fork 的图片一律经本站代理
     * 输出（`Image::url()` 恒为 `/{key}.{ext}`），策略不再需要任何「访问域名」。
     *
     * @return array
     */
    public function rules()
    {
        $array = [
            'groups' => 'array',
            'name' => 'required|max:60',
            'intro' => 'max:2000',
            // 驱动只剩本地与 WebDav：不限制的话，手搓请求传 key=2..8/10 会一路走到
            // ImageService 的 default 分支，变成运行期才炸的脏数据。
            'key' => 'required|integer|in:'.StrategyKey::Local.','.StrategyKey::Webdav,
        ];

        return array_merge($array, match((int)$this->input('key')) {
            StrategyKey::Local => [
                'configs.root' => ['max:1000', function ($attribute, $value, $fail) {
                    if ($value) {
                        if (! is_dir($value)) {
                            return $fail('存储路径不存在');
                        }
                        if (! is_writeable($value)) {
                            return $fail('存储路径没有写入权限');
                        }
                    }
                }],
            ],
            StrategyKey::Webdav => [
                'configs.base_uri' => ['required', 'url'],
                'configs.username' => '',
                'configs.password' => '',
                // 认证方式由 Strategy::saving() 归一化到 1/2/4，这里不做 in 校验：
                // 历史数据里的空值一旦被 in:1,2,4 挡住，表单就再也保存不了了。
                'configs.auth_type' => '',
                'configs.prefix' => '',
            ],
            default => [],
        });
    }

    public function attributes()
    {
        $array = [
            'name' => '名称',
            'intro' => '简介',
            'key' => '策略',
        ];

        return array_merge($array, match((int)$this->input('key')) {
            StrategyKey::Local => [
                'configs.root' => '根目录路径',
            ],
            StrategyKey::Webdav => [
                'configs.base_uri' => '连接地址',
                'configs.username' => '用户名',
                'configs.password' => '密码',
                'configs.auth_type' => '认证方式',
                'configs.prefix' => '前缀',
            ],
            default => [],
        });
    }
}
