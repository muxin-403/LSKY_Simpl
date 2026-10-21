<?php

namespace App\Models;

use App\Enums\Strategy\WebDavOption;
use App\Enums\StrategyKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Sabre\DAV\Client;

/**
 * @property int $id
 * @property int $key
 * @property string $name
 * @property string $intro
 * @property \Illuminate\Support\Collection $configs
 * @property Carbon $updated_at
 * @property Carbon $created_at
 * @property-read Collection $groups
 * @property-read Collection $images
 */
class Strategy extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'intro',
        'configs',
    ];

    protected $attributes = [
        'intro' => '',
    ];

    protected $casts = [
        'id' => 'integer',
        'key' => 'integer',
        'configs' => 'collection',
    ];

    const DRIVERS = [
        StrategyKey::Local => '本地',
        StrategyKey::Webdav => 'WebDav',
    ];

    /**
     * WebDAV 认证方式。不再提供「Auto」：
     * 它的 option value 是空字符串，强转成 int 就是 0，而 Sabre 对 0 的处理是
     * 「显式指定且什么都不做」（CURLOPT_HTTPAUTH = 0），等于匿名请求 —— 接 Cloudreve
     * 这类必须 Basic 认证的服务会直接 401。默认落到 Basic 最贴合实际。
     */
    const WEBDAV_AUTH_TYPES = [
        Client::AUTH_BASIC => 'Basic',
        Client::AUTH_DIGEST => 'Digest',
        Client::AUTH_NTLM => 'Ntlm',
    ];

    protected static function booted()
    {
        static::saving(function (self $strategy) {
            // WebDAV 的两个地址类字段在落库前归一化：base_uri 少了尾斜杠时，Sabre 的
            // 相对路径解析会把最后一段吃掉（详见 ImageService::makeWebdavAdapter）。
            if ($strategy->key == StrategyKey::Webdav) {
                $configs = $strategy->configs ?: collect();
                $configs['base_uri'] = rtrim((string)$configs->get(WebDavOption::BaseUri), '/').'/';
                $configs['prefix'] = trim((string)$configs->get(WebDavOption::Prefix), '/');
                // 认证方式只允许 Basic/Digest/Ntlm，非法值（含历史遗留的空字符串）一律落 Basic。
                $authType = (int)$configs->get(WebDavOption::AuthType);
                $configs['auth_type'] = in_array($authType, [Client::AUTH_BASIC, Client::AUTH_DIGEST, Client::AUTH_NTLM], true)
                    ? $authType
                    : Client::AUTH_BASIC;
                $strategy->configs = $configs;
            }
        });
    }

    public function intro(): Attribute
    {
        return new Attribute(
            set: fn ($value) => $value ?: '',
        );
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_strategy', 'strategy_id', 'group_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'strategy_id', 'id');
    }
}
