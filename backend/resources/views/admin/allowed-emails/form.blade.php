@extends('admin.layout')

@section('title', $entry->id ? 'Sửa allowlist' : 'Thêm allowlist')
@section('heading', $entry->id ? 'Sửa allowlist' : 'Thêm allowlist')

@section('content')
<div class="card">
    <form method="POST" action="{{ $entry->id ? route('admin.allowed-emails.update', $entry->id) : route('admin.allowed-emails.store') }}">
        @csrf
        @if ($entry->id)
            @method('PUT')
        @endif

        <div class="form-group">
            <label for="pattern">Pattern email *</label>
            <input type="text" name="pattern" id="pattern" value="{{ old('pattern', $entry->pattern) }}" required placeholder="user@gmail.com hoặc *@company.com">
        </div>

        <div class="form-group">
            <label for="label">Nhãn (tuỳ chọn)</label>
            <input type="text" name="label" id="label" value="{{ old('label', $entry->label) }}" placeholder="VD: Team A">
        </div>

        <div class="form-group checkbox">
            <label>
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $entry->isActive ?? true) ? 'checked' : '' }}>
                Đang bật (cho phép đăng nhập)
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Lưu</button>
            <a href="{{ route('admin.allowed-emails.index') }}" class="btn btn-secondary">Huỷ</a>
        </div>
    </form>
</div>
@endsection
