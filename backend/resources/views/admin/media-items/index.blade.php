@extends('admin.layout')

@section('title', 'Video / MP3')
@section('heading', 'Video / MP3 — Listening')

@section('content')
<div class="toolbar">
    <a href="{{ route('admin.media-items.create') }}" class="btn">+ Thêm video / MP3</a>
    <a href="{{ route('admin.listening-assessments.index') }}" class="btn btn-secondary">Quiz / Test / Exam</a>
</div>

<form class="search-bar" method="GET">
    <input type="search" name="q" value="{{ $search }}" placeholder="Tìm tiêu đề hoặc URL...">
    <select name="type">
        <option value="">Tất cả loại</option>
        <option value="youtube" @selected($type === 'youtube')>YouTube</option>
        <option value="mp3" @selected($type === 'mp3')>MP3</option>
        <option value="audio" @selected($type === 'audio')>Audio</option>
    </select>
    <select name="analysis_status">
        <option value="">Tất cả trạng thái</option>
        <option value="pending" @selected($analysisStatus === 'pending')>Pending</option>
        <option value="processing" @selected($analysisStatus === 'processing')>Processing</option>
        <option value="ready" @selected($analysisStatus === 'ready')>Ready</option>
        <option value="failed" @selected($analysisStatus === 'failed')>Failed</option>
    </select>
    <button type="submit" class="btn">Lọc</button>
</form>

<table>
    <thead>
        <tr>
            <th>Tiêu đề</th>
            <th>User</th>
            <th>Loại</th>
            <th>Độ khó</th>
            <th>Phân tích</th>
            <th>Quiz / Test / Exam</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($mediaItems as $m)
            <tr>
                <td>
                    <a href="{{ route('admin.media-items.show', $m) }}"><strong>{{ $m->title }}</strong></a>
                    @if ($m->source_id)
                        <br><span class="muted">ID: {{ $m->source_id }}</span>
                    @endif
                </td>
                <td>{{ $m->user?->email }}</td>
                <td><span class="badge">{{ $m->type }}</span></td>
                <td><span class="badge badge-difficulty-{{ $m->difficulty }}">{{ $m->difficultyLabel() }}</span></td>
                <td>
                    <span class="badge badge-{{ $m->analysis_status }}">{{ $m->analysis_status }}</span>
                </td>
                <td>
                    @php
                        $byType = $m->listeningAssessments->keyBy('type');
                    @endphp
                    @foreach (['quiz', 'test', 'exam'] as $t)
                        @if ($a = $byType->get($t))
                            <a href="{{ route('admin.listening-assessments.show', $a) }}" class="badge badge-{{ $a->status === 'ready' ? 'ready' : 'pending' }}" title="{{ $a->title }}">
                                {{ $t }} ({{ $a->question_count }})
                            </a>
                        @else
                            <span class="muted">{{ $t }}: —</span>
                        @endif
                    @endforeach
                </td>
                <td>
                    <a href="{{ route('admin.media-items.show', $m) }}" class="btn btn-sm">Chi tiết</a>
                    <a href="{{ route('admin.media-items.edit', $m) }}" class="btn btn-sm btn-secondary">Sửa</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Chưa có media nào.</td></tr>
        @endforelse
    </tbody>
</table>
{{ $mediaItems->links() }}
@endsection
