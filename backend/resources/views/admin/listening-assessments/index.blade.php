@extends('admin.layout')

@section('title', 'Quiz / Test / Exam')
@section('heading', 'Quiz / Test / Exam')

@section('content')
<div class="toolbar">
    <a href="{{ route('admin.media-items.index') }}" class="btn btn-secondary">← Video / MP3</a>
</div>

<form class="search-bar" method="GET">
    <input type="search" name="q" value="{{ $search }}" placeholder="Tìm tiêu đề hoặc media...">
    <select name="type">
        <option value="">Tất cả loại</option>
        <option value="quiz" @selected($type === 'quiz')>Quiz</option>
        <option value="test" @selected($type === 'test')>Test</option>
        <option value="exam" @selected($type === 'exam')>Exam</option>
    </select>
    <select name="status">
        <option value="">Tất cả trạng thái</option>
        <option value="generating" @selected($status === 'generating')>Generating</option>
        <option value="ready" @selected($status === 'ready')>Ready</option>
        <option value="failed" @selected($status === 'failed')>Failed</option>
    </select>
    <button type="submit" class="btn">Lọc</button>
</form>

<table>
    <thead>
        <tr>
            <th>Loại</th>
            <th>Tiêu đề</th>
            <th>Media</th>
            <th>User</th>
            <th>Câu hỏi</th>
            <th>Thời gian</th>
            <th>Trạng thái</th>
            <th>Lượt làm</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($assessments as $a)
            <tr>
                <td><span class="badge">{{ $a->type }}</span></td>
                <td><a href="{{ route('admin.listening-assessments.show', $a) }}">{{ $a->title }}</a></td>
                <td>
                    @if ($a->mediaItem)
                        <a href="{{ route('admin.media-items.show', $a->mediaItem) }}">{{ Str::limit($a->mediaItem->title, 40) }}</a>
                    @else
                        —
                    @endif
                </td>
                <td>{{ $a->user?->email }}</td>
                <td>{{ $a->question_count }}</td>
                <td>{{ $a->time_limit_minutes }} phút</td>
                <td><span class="badge badge-{{ $a->status }}">{{ $a->status }}</span></td>
                <td>{{ $a->attempts_count }}</td>
                <td>
                    <a href="{{ route('admin.listening-assessments.show', $a) }}" class="btn btn-sm">Xem</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">Chưa có phiên làm bài nào.</td></tr>
        @endforelse
    </tbody>
</table>
{{ $assessments->links() }}
@endsection
