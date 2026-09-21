<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập Admin — FLC</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <h1>FLC Admin</h1>
        <p class="muted">Đăng nhập bằng tài khoản Google được cấp quyền admin.</p>

        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <a href="{{ route('admin.auth.sso') }}" class="btn" style="width:100%;margin-top:16px">
            Đăng nhập bằng Google
        </a>

        <p class="muted" style="margin-top:20px;font-size:12px">
            Cấu hình email admin trong <code>FLC_ADMIN_EMAILS</code> (.env)
        </p>
    </div>
</div>
</body>
</html>
