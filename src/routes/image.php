<?php

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Route;

// 图片统一走本站代理（全站图片地址都是 `/{key}.{ext}`，见 Image::url()）。
//
// 这里**不再**套 `cache.headers` 中间件：它在 `! $response->getContent()` 时直接 return，
// 而代理走的是 StreamedResponse（getContent() 恒为 false），缓存头根本落不到响应上；
// 缓存头（Cache-Control / ETag / nosniff）与 304 判断统一由 Controller::output() 自己处理，
// 这样「流式」与「整串」两条分支的行为才一致。
$extensions = config('convention.group.accepted_file_suffixes');
$extensions = array_merge(array_map('strtoupper', $extensions), array_map('strtolower', $extensions));

Route::any('{key}.{extension}', [
    Controller::class, 'output',
])->where('extension', implode('|', $extensions));
