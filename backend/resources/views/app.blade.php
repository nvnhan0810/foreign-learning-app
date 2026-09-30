<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1, user-scalable=no">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="theme-color" content="#4361ee">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="FLC">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/images/android-chrome-192x192.png">
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="user-body min-h-dvh {{ ($isFlcApp ?? false) ? 'flc-app' : '' }}">
    @inertia
</body>
</html>
