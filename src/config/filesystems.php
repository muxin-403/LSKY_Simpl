<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "webdav"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        // 本地存储策略的默认落盘位置。
        // 注意：本 fork 的图片一律经 `/{key}.{ext}` 由 PHP 代理输出，所以这里**不需要**
        // url（不再有 /i 软链直连），前端拿到的是本站地址。
        'uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/uploads'),
            'visibility' => 'public',
        ],
    ],
];
