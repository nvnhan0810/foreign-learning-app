@extends('admin.layout')

@section('title', 'Tổng quan')
@section('heading', 'Tổng quan')

@section('content')
<div class="stats">
    <div class="stat">
        <strong>{{ $stats['users'] }}</strong>
        <span class="muted">Người dùng</span>
    </div>
    <div class="stat">
        <strong>{{ $stats['vocabularies'] }}</strong>
        <span class="muted">Từ vựng</span>
    </div>
    <div class="stat">
        <strong>{{ $stats['media_items'] }}</strong>
        <span class="muted">Video / MP3</span>
    </div>
    <div class="stat">
        <strong>{{ $stats['listening_assessments'] }}</strong>
        <span class="muted">Quiz / Test / Exam</span>
    </div>
    <div class="stat">
        <strong>{{ $stats['allowlist'] }}</strong>
        <span class="muted">Email allowlist (DB)</span>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <h3 style="margin-top:0">Truy cập nhanh</h3>
    <p>
        <a href="{{ route('admin.dictionary.create') }}" class="btn">Thêm từ / cụm từ</a>
        <a href="{{ route('admin.allowed-emails.create') }}" class="btn">Thêm email allowlist</a>
        <a href="{{ route('admin.media-items.create') }}" class="btn">Thêm video / MP3</a>
        <a href="{{ route('admin.listening-assessments.index') }}" class="btn btn-secondary">Quiz / Test / Exam</a>
        <a href="{{ route('admin.settings.edit') }}" class="btn btn-secondary">Chỉnh cài đặt / thông báo extension</a>
    </p>
</div>
@endsection
