@extends('admin.layout')

@section('title', 'Sửa media')
@section('heading', 'Sửa media: '.$mediaItem->title)

@section('content')
<div class="card">
    <p class="muted">User hiện tại: {{ $mediaItem->user?->email }}</p>
    <form method="POST" action="{{ route('admin.media-items.update', $mediaItem) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="user_id">Người dùng *</label>
            <select name="user_id" id="user_id" required>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(old('user_id', $mediaItem->user_id) == $user->id)>
                        {{ $user->email }} ({{ $user->name }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label for="title">Tiêu đề</label>
            <input type="text" name="title" id="title" value="{{ old('title', $mediaItem->title) }}" required>
        </div>

        <div class="form-group">
            <label for="url">URL</label>
            <input type="url" name="url" id="url" value="{{ old('url', $mediaItem->url) }}" required>
        </div>

        <div class="form-group">
            <label for="type">Loại</label>
            <select name="type" id="type">
                <option value="youtube" @selected(old('type', $mediaItem->type) === 'youtube')>YouTube</option>
                <option value="mp3" @selected(old('type', $mediaItem->type) === 'mp3')>MP3</option>
                <option value="audio" @selected(old('type', $mediaItem->type) === 'audio')>Audio</option>
            </select>
        </div>

        <div class="form-group">
            <label for="language">Ngôn ngữ</label>
            <input type="text" name="language" id="language" value="{{ old('language', $mediaItem->language) }}" maxlength="10">
        </div>

        <div class="form-group">
            <label for="frequency">Tần suất nhắc nghe</label>
            <select name="frequency" id="frequency">
                <option value="daily" @selected(old('frequency', $mediaItem->frequency) === 'daily')>Hàng ngày</option>
                <option value="weekly" @selected(old('frequency', $mediaItem->frequency) === 'weekly')>Hàng tuần</option>
                <option value="monthly" @selected(old('frequency', $mediaItem->frequency) === 'monthly')>Hàng tháng</option>
            </select>
        </div>

        <div class="form-group">
            <label for="difficulty">Độ khó</label>
            <select name="difficulty" id="difficulty">
                <option value="beginner" @selected(old('difficulty', $mediaItem->difficulty) === 'beginner')>Cơ bản</option>
                <option value="intermediate" @selected(old('difficulty', $mediaItem->difficulty) === 'intermediate')>Trung cấp</option>
                <option value="advanced" @selected(old('difficulty', $mediaItem->difficulty) === 'advanced')>Nâng cao</option>
            </select>
        </div>

        <div class="form-group">
            <label for="transcript">Transcript</label>
            <textarea name="transcript" id="transcript">{{ old('transcript', $mediaItem->transcript) }}</textarea>
        </div>

        <div class="form-group">
            <label for="notes">Ghi chú</label>
            <textarea name="notes" id="notes">{{ old('notes', $mediaItem->notes) }}</textarea>
        </div>

        <div class="form-group checkbox">
            <label>
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $mediaItem->is_active) ? 'checked' : '' }}>
                Đang bật nhắc nghe
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Lưu</button>
            <a href="{{ route('admin.media-items.show', $mediaItem) }}" class="btn btn-secondary">Huỷ</a>
        </div>
    </form>
</div>
@endsection
