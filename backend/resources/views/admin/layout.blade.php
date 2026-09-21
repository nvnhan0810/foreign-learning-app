<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — FLC</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="brand">FLC Admin</div>
        <nav>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Tổng quan</a>
            <a href="{{ route('admin.allowed-emails.index') }}" class="{{ request()->routeIs('admin.allowed-emails.*') ? 'active' : '' }}">Allowlist email</a>
            <a href="{{ route('admin.settings.edit') }}" class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">Cài đặt</a>
            <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">Người dùng</a>
            <a href="{{ route('admin.dictionary.index') }}" class="{{ request()->routeIs('admin.dictionary.*') ? 'active' : '' }}">Từ điển</a>
            <a href="{{ route('admin.media-items.index') }}" class="{{ request()->routeIs('admin.media-items.*') ? 'active' : '' }}">Video / MP3</a>
            <a href="{{ route('admin.listening-assessments.index') }}" class="{{ request()->routeIs('admin.listening-assessments.*') ? 'active' : '' }}">Quiz / Test / Exam</a>
        </nav>
    </aside>
    <main class="admin-main">
        <div class="admin-topbar">
            <h1>@yield('heading', 'Admin')</h1>
            <div>
                <span class="muted">{{ session('admin_name') }} ({{ session('admin_email') }})</span>
                <form action="{{ route('admin.logout') }}" method="POST" class="inline-form" style="margin-left:12px">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Đăng xuất</button>
                </form>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error">
                <ul style="margin:0;padding-left:18px">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</div>
<script>
document.querySelectorAll('[data-answer-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var panel = document.getElementById(btn.getAttribute('data-answer-toggle'));
        if (!panel) return;

        var hidden = panel.classList.toggle('answers-hidden');
        btn.setAttribute('aria-pressed', hidden ? 'false' : 'true');
        btn.setAttribute('title', hidden ? 'Hiện đáp án' : 'Ẩn đáp án');

        var label = btn.querySelector('.answer-toggle-label');
        if (label) {
            label.textContent = hidden ? 'Hiện đáp án' : 'Ẩn đáp án';
        }
    });
});
</script>
</body>
</html>
