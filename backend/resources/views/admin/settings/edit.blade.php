@extends('admin.layout')

@section('title', 'Cài đặt')
@section('heading', 'Cài đặt hệ thống')

@section('content')
<div class="card">
    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="app_name">Tên ứng dụng (hiển thị extension)</label>
            <input type="text" name="app_name" id="app_name" value="{{ old('app_name', $app_name) }}">
        </div>

        <div class="form-group">
            <label for="extension_notice">Thông báo extension</label>
            <textarea name="extension_notice" id="extension_notice" placeholder="VD: Bảo trì server ngày 25/05...">{{ old('extension_notice', $extension_notice) }}</textarea>
            <p class="muted">Hiển thị trên popup extension sau khi đồng bộ.</p>
        </div>

        <div class="form-group checkbox">
            <label>
                <input type="hidden" name="vocab_quiz_push_enabled" value="0">
                <input type="checkbox" name="vocab_quiz_push_enabled" value="1" {{ old('vocab_quiz_push_enabled', $vocab_quiz_push_enabled) ? 'checked' : '' }}>
                Bật nhắc quiz từ vựng (FCM) — 11:00 & 20:00 (Asia/Ho_Chi_Minh)
            </label>
            <p class="muted">Gửi push nếu user chưa ôn đủ: trưa (0 lần), tối (≤1 lần trong ngày).</p>
        </div>

        <div class="form-group checkbox">
            <label>
                <input type="hidden" name="allow_all_emails" value="0">
                <input type="checkbox" name="allow_all_emails" value="1" {{ old('allow_all_emails', $allow_all_emails) ? 'checked' : '' }}>
                Cho phép mọi email Google đăng nhập (ghi đè allowlist)
            </label>
            <p class="muted">Ưu tiên sau <code>FLC_ALLOW_ALL_EMAILS</code> trong .env nếu bật ở đó.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Lưu cài đặt</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 style="margin-top:0">Allowlist từ .env (chỉ đọc)</h3>
    <p class="muted"><code>FLC_ALLOWED_EMAILS</code>:</p>
    @if (count($env_allowlist))
        <ul>
            @foreach ($env_allowlist as $item)
                <li><code>{{ $item }}</code></li>
            @endforeach
        </ul>
    @else
        <p class="muted">(trống)</p>
    @endif
    <p class="muted"><code>FLC_ALLOW_ALL_EMAILS</code>: {{ $env_allow_all ? 'true' : 'false' }}</p>
</div>
@endsection
