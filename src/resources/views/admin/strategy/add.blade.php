@section('title', '创建存储策略')

<x-app-layout>
    <div class="my-6 md:my-9">
        @include('admin.strategy.tips')

        <div class="md:mt-0 md:col-span-2">
            <form action="{{ route('admin.strategy.create') }}" method="POST">
                <div class="overflow-hidden rounded-md">
                    <div class="px-4 py-5 bg-surface sm:p-6 space-y-4 shadow-card">
                        <div class="col-span-6">
                            {{-- 原来这里是原生 <select multiple>：桌面要按住 Ctrl/⌘ 才好多选、手机上基本选不动。
                                 现改成打勾清单（一行一个组，点一下勾上/取消）；组多时框内滚动。
                                 组件：components/group-checklist.blade.php --}}
                            <span class="block text-ink">选择角色组</span>
                            <x-group-checklist />
                        </div>

                        <div class="col-span-6">
                            <label for="name" class="block text-sm font-medium text-ink"><span class="text-danger">*</span>名称</label>
                            <x-input type="text" name="name" id="name" placeholder="请输入策略名称" autocomplete="name" />
                        </div>

                        <div class="col-span-6">
                            <label for="intro" class="block text-sm font-medium text-ink">简介</label>
                            <x-textarea id="intro" name="intro" rows="3" placeholder="请输入简介，可为空"></x-textarea>
                        </div>

                        <div class="col-span-6 sm:col-span-3">
                            <label for="key" class="block text-sm font-medium text-ink"><span class="text-danger">*</span>存储策略</label>
                            <x-select id="key" name="key" autocomplete="key" required>
                                @foreach(\App\Models\Strategy::DRIVERS as $key => $driver)
                                <option value="{{ $key }}" {{ $loop->first ? 'selected' : '' }}>{{ $driver }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div class="col-span-6 mb-4 hidden" data-driver="{{ \App\Enums\StrategyKey::Local }}">
                            <div class="col-span-6 mb-4">
                                <p class="bg-brand p-2 rounded text-sm text-white"><i class="fas fa-info-circle"></i> 图片统一由本站代理输出（形如 <code>/ab12cd.jpg</code>），无需填写访问域名。</p>
                            </div>
                            <div class="col-span-6">
                                <div class="col-span-6 sm:col-span-3 mb-4">
                                    <label for="configs[root]" class="block text-sm font-medium text-ink">存储路径</label>
                                    <x-input type="text" name="configs[root]" id="configs[root]" autocomplete="text" placeholder="图片保存位置，默认：{{ config('filesystems.disks.uploads.root') }}" />
                                    <small class="text-orange-500"><i class="fas fa-exclamation"></i> 存储路径为绝对路径，设置错误或没有读写权限可能会导致图片保存失败。如果存储路径与其他策略相同，那么请注意使用角色组的路径命名规则、文件命名规则来区分不同文件夹，否则可能会因为名称重复而导致图片物理文件被覆盖。</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-span-6 mb-4 hidden" data-driver="{{ \App\Enums\StrategyKey::Webdav }}">
                            <div class="col-span-6 mb-4">
                                <p class="bg-brand p-2 rounded text-sm text-white">
                                    <i class="fas fa-info-circle"></i> 图片统一由本站代理输出，<b>无需填写访问域名</b>。<br>
                                    连接地址请写成以 <code>/</code> 结尾的 WebDAV 根地址，例如 <code>https://cloudreve.example.com/dav/</code>（少了结尾的斜杠会导致上传路径被截断、报 404）。<br>
                                    接 Cloudreve 时：用户名填登录邮箱，密码填「WebDAV 账户」里系统生成的<b>专用密码</b>（不是网页登录密码），并确认该用户组已开启 WebDAV 权限。
                                </p>
                            </div>
                            <div class="col-span-3 sm:col-span-2 mb-4">
                                <label for="configs[base_uri]" class="block text-sm font-medium text-ink"><span class="text-danger">*</span>连接地址</label>
                                <x-input type="url" name="configs[base_uri]" id="configs[base_uri]" placeholder="https://example.com/dav/" />
                            </div>
                            <div class="col-span-3 sm:col-span-2 mb-4">
                                <label for="webdav-configs[auth_type]" class="block text-sm font-medium text-ink"><span class="text-danger">*</span>认证方式</label>
                                <x-select id="webdav-auth-type" name="configs[auth_type]" select2>
                                    @foreach(\App\Models\Strategy::WEBDAV_AUTH_TYPES as $key => $type)
                                        <option value="{{ $key }}" {{ $loop->first ? 'selected' : '' }}>{{ $type }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <div class="col-span-3 sm:col-span-2 mb-4">
                                <label for="webdav-configs[prefix]" class="block text-sm font-medium text-ink">路径前缀</label>
                                <x-input type="text" name="configs[prefix]" id="webdav-configs[prefix]" placeholder="请输入路径前缀"></x-input>
                            </div>
                            <div class="col-span-3 sm:col-span-2 mb-4">
                                <label for="configs[username]" class="block text-sm font-medium text-ink">用户名</label>
                                <x-input type="text" name="configs[username]" id="configs[username]" placeholder="请输入用户名" />
                            </div>
                            <div class="col-span-3 sm:col-span-2 mb-4">
                                <label for="configs[password]" class="block text-sm font-medium text-ink">密码</label>
                                <x-input type="password" name="configs[password]" id="configs[password]" placeholder="请输入密码" />
                            </div>
                        </div>

                    </div>
                    <div class="px-4 py-3 bg-surface-2 text-right sm:px-6">
                        <x-button type="button" class="bg-surface-2" onclick="history.go(-1)">取消</x-button>
                        <x-button>确认创建</x-button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            // 设置选中驱动
            let setSelected = function () {
                $('[data-driver]').each(function () {
                    if ($('#key').val() == $(this).data('driver')) {
                        $(this).show().find('input').attr('disabled', false);
                    } else {
                        $(this).hide().find('input').attr('disabled', true);
                    }
                });
            };

            setSelected();

            $('#key').change(function () {
                setSelected();
            });

            $('form').submit(function (e) {
                e.preventDefault();
                axios.post(this.action, $(this).serialize()).then(response => {
                    if (response.data.status) {
                        toastr.success(response.data.message);
                        setTimeout(function () {
                            window.location.href = '{{ route('admin.strategies') }}';
                        }, 3000);
                    } else {
                        toastr.error(response.data.message);
                    }
                });
            });
        </script>
    @endpush

</x-app-layout>
