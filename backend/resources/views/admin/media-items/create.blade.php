@extends('admin.layout')

@section('title', 'Thêm video / MP3')
@section('heading', 'Thêm video / MP3')

@section('content')
<div class="card">
    <form method="POST" action="{{ route('admin.media-items.store') }}" enctype="multipart/form-data" id="media-form">
        @csrf

        <div class="form-group">
            <label for="user_id">Người dùng *</label>
            <select name="user_id" id="user_id" required>
                <option value="">— Chọn user —</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>
                        {{ $user->email }} ({{ $user->name }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label for="title">Tiêu đề *</label>
            <input type="text" name="title" id="title" value="{{ old('title') }}" required>
        </div>

        <div class="form-group">
            <label for="type">Loại *</label>
            <select name="type" id="type" required>
                <option value="youtube" @selected(old('type') === 'youtube')>YouTube URL</option>
                <option value="mp3" @selected(old('type') === 'mp3')>MP3 upload</option>
                <option value="audio" @selected(old('type') === 'audio')>Audio upload</option>
            </select>
        </div>

        <div class="form-group" id="url-group">
            <label for="url">YouTube URL *</label>
            <input type="url" name="url" id="url" value="{{ old('url') }}" placeholder="https://www.youtube.com/watch?v=...">
        </div>

        <div class="form-group" id="audio-group" style="display:none">
            <label for="audio">File audio *</label>
            <input type="file" name="audio" id="audio" accept="audio/*,.mp3,.wav,.m4a">
            <p class="muted">Tối đa {{ config('listening.max_audio_size_mb') }} MB. Nên kèm transcript bên dưới nếu không dùng YouTube captions.</p>
        </div>

        <div class="form-group">
            <label for="language">Ngôn ngữ</label>
            <input type="text" name="language" id="language" value="{{ old('language', 'en') }}" maxlength="10">
        </div>

        <div class="form-group">
            <label for="frequency">Tần suất nhắc nghe</label>
            <select name="frequency" id="frequency">
                <option value="daily" @selected(old('frequency') === 'daily')>Hàng ngày</option>
                <option value="weekly" @selected(old('frequency', 'weekly') === 'weekly')>Hàng tuần</option>
                <option value="monthly" @selected(old('frequency') === 'monthly')>Hàng tháng</option>
            </select>
        </div>

        <div class="form-group">
            <label for="difficulty">Độ khó</label>
            <select name="difficulty" id="difficulty">
                <option value="beginner" @selected(old('difficulty') === 'beginner')>Cơ bản</option>
                <option value="intermediate" @selected(old('difficulty', 'intermediate') === 'intermediate')>Trung cấp</option>
                <option value="advanced" @selected(old('difficulty') === 'advanced')>Nâng cao</option>
            </select>
        </div>

        <div class="form-group">
            <label for="transcript">Transcript (tuỳ chọn)</label>
            <textarea name="transcript" id="transcript" placeholder="Dán transcript nếu upload MP3...">{{ old('transcript') }}</textarea>
        </div>

        <div class="form-group">
            <label for="notes">Ghi chú</label>
            <textarea name="notes" id="notes">{{ old('notes') }}</textarea>
        </div>

        <div class="form-group checkbox">
            <label>
                <input type="hidden" name="auto_process" value="0">
                <input type="checkbox" name="auto_process" value="1" {{ old('auto_process', '1') ? 'checked' : '' }}>
                Tự động phân tích và tạo quiz / test / exam
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Tạo</button>
            <a href="{{ route('admin.media-items.index') }}" class="btn btn-secondary">Huỷ</a>
        </div>
    </form>
</div>

<script>
document.getElementById('type').addEventListener('change', toggleFields);
function toggleFields() {
    const type = document.getElementById('type').value;
    const isYoutube = type === 'youtube';
    document.getElementById('url-group').style.display = isYoutube ? 'block' : 'none';
    document.getElementById('audio-group').style.display = isYoutube ? 'none' : 'block';
    document.getElementById('url').required = isYoutube;
    document.getElementById('audio').required = !isYoutube;
}
toggleFields();
</script>
@endsection
