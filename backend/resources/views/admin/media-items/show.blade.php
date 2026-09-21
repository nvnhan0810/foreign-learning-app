@extends('admin.layout')

@section('title', $mediaItem->title)
@section('heading', $mediaItem->title)

@section('content')
<div class="toolbar">
    <a href="{{ route('admin.media-items.edit', $mediaItem) }}" class="btn btn-sm">Sửa</a>
    @if ($mediaItem->analysis_status !== 'processing')
        <form action="{{ route('admin.media-items.process', $mediaItem) }}" method="POST" class="inline-form">
            @csrf
            <button type="submit" class="btn btn-sm btn-secondary">Phân tích lại</button>
        </form>
    @endif
    <form action="{{ route('admin.media-items.regenerate-assessments', $mediaItem) }}" method="POST" class="inline-form">
        @csrf
        <button type="submit" class="btn btn-sm btn-secondary">Tạo lại ngân hàng câu hỏi</button>
    </form>
    <form action="{{ route('admin.media-items.destroy', $mediaItem) }}" method="POST" class="inline-form" onsubmit="return confirm('Xóa media và tất cả bài quiz/test/exam?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
    </form>
</div>

<div class="grid-2">
    <div class="card">
        <h3 style="margin-top:0">Thông tin</h3>
        <dl class="detail-list">
            <dt>User</dt><dd>{{ $mediaItem->user?->email }}</dd>
            <dt>Loại</dt><dd><span class="badge">{{ $mediaItem->type }}</span></dd>
            <dt>URL</dt><dd><a href="{{ $mediaItem->url }}" target="_blank" rel="noopener">{{ Str::limit($mediaItem->url, 60) }}</a></dd>
            @if ($mediaItem->source_id)
                <dt>YouTube ID</dt><dd>{{ $mediaItem->source_id }}</dd>
            @endif
            @if ($mediaItem->audio_path)
                <dt>File audio</dt><dd><code>{{ $mediaItem->audio_path }}</code></dd>
            @endif
            <dt>Ngôn ngữ</dt><dd>{{ $mediaItem->language }}</dd>
            <dt>Tần suất</dt><dd>{{ $mediaItem->frequency }}</dd>
            <dt>Độ khó</dt><dd><span class="badge badge-difficulty-{{ $mediaItem->difficulty }}">{{ $mediaItem->difficultyLabel() }}</span></dd>
            <dt>Trạng thái phân tích</dt>
            <dd><span class="badge badge-{{ $mediaItem->analysis_status }}">{{ $mediaItem->analysis_status }}</span></dd>
            @if ($mediaItem->analyzed_at)
                <dt>Phân tích lúc</dt><dd>{{ $mediaItem->analyzed_at->format('d/m/Y H:i') }}</dd>
            @endif
            @if ($mediaItem->analysis_error)
                <dt>Lỗi</dt><dd class="text-danger">{{ $mediaItem->analysis_error }}</dd>
            @endif
            @if ($mediaItem->notes)
                <dt>Ghi chú</dt><dd>{{ $mediaItem->notes }}</dd>
            @endif
        </dl>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Phân tích nội dung</h3>
            @if ($mediaItem->analysis_payload)
            @php $a = $mediaItem->analysis_payload; @endphp
            <p><strong>Tóm tắt:</strong> {{ $a['summary'] ?? '—' }}</p>
            <p><strong>Độ khó:</strong> {{ $a['difficulty'] ?? '—' }}</p>
            @php
                $contentSource = $a['content_source'] ?? 'transcript';
                $contentSourceLabels = [
                    'transcript' => 'Phụ đề / transcript video',
                    'metadata' => 'Chỉ metadata YouTube (title + mô tả)',
                    'notes' => 'Ghi chú admin',
                    'title_only' => 'Chỉ tiêu đề',
                ];
            @endphp
            <p>
                <strong>Nguồn nội dung:</strong>
                {{ $contentSourceLabels[$contentSource] ?? $contentSource }}
            </p>
            @if (!empty($a['topics']))
                <p><strong>Chủ đề:</strong> {{ implode(', ', $a['topics']) }}</p>
            @endif
            @if (!empty($a['key_vocabulary']))
                <p><strong>Từ vựng chủ đề:</strong></p>
                <ul>
                    @foreach (array_slice($a['key_vocabulary'], 0, 10) as $v)
                        <li><strong>{{ $v['word'] ?? '' }}</strong> — {{ $v['definition'] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif
            @if (!empty($a['vocabulary_import']))
                @php $vi = $a['vocabulary_import']; @endphp
                <p class="muted">
                    Đã thêm {{ $vi['imported'] ?? 0 }} từ vào danh sách ôn tập của user
                    @if (!empty($vi['skipped']))
                        ({{ $vi['skipped'] }} từ đã có sẵn)
                    @endif
                </p>
            @endif
            <p class="muted">Nguồn phân tích: {{ $a['source'] ?? 'unknown' }}</p>
        @else
            <p class="muted">Chưa có phân tích.</p>
        @endif
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0">Ngân hàng câu hỏi</h3>
    <dl class="detail-list">
        <dt>Trạng thái</dt>
        <dd><span class="badge badge-{{ $mediaItem->question_bank_status ?? 'pending' }}">{{ $mediaItem->question_bank_status ?? 'pending' }}</span></dd>
        <dt>Số câu hỏi</dt>
        <dd>{{ $mediaItem->question_bank_count ?? 0 }}</dd>
    </dl>

    <p class="muted" style="margin-top:12px">
        Quiz / Test / Exam được random từ ngân hàng khi user bắt đầu làm bài
        (quiz: {{ config('listening.assessments.quiz.question_count') }} câu,
        test: {{ config('listening.assessments.test.question_count') }},
        exam: {{ config('listening.assessments.exam.question_count') }}).
    </p>

    @if ($mediaItem->isAnalysisReady())
        <form action="{{ route('admin.media-items.regenerate-assessments', $mediaItem) }}" method="POST" class="inline-form" style="margin-top:12px">
            @csrf
            <button type="submit" class="btn btn-sm btn-secondary">Tạo lại ngân hàng câu hỏi</button>
        </form>
    @endif
</div>

@include('admin.partials.question-bank-table', [
    'questions' => $mediaItem->listeningQuestions,
    'panelId' => 'media-question-bank-panel',
])

<div class="card">
    <h3 style="margin-top:0">Phiên làm bài gần đây</h3>
    <table>
        <thead>
            <tr>
                <th>Loại</th>
                <th>Tiêu đề</th>
                <th>Câu hỏi</th>
                <th>Thời gian</th>
                <th>Lượt làm</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($mediaItem->listeningAssessments->sortByDesc('created_at')->take(10) as $assessment)
                <tr>
                    <td><span class="badge">{{ $assessment->type }}</span></td>
                    <td>{{ $assessment->title }}</td>
                    <td>{{ $assessment->question_count }}</td>
                    <td>{{ $assessment->time_limit_minutes }} phút</td>
                    <td>{{ $assessment->attempts_count ?? 0 }}</td>
                    <td>
                        <a href="{{ route('admin.listening-assessments.show', $assessment) }}" class="btn btn-sm">Xem</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Chưa có phiên làm bài nào.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($mediaItem->transcript || !empty($mediaItem->analysis_payload['source_content']))
<div class="card">
    <h3 style="margin-top:0">
        @if ($mediaItem->transcript)
            Transcript
        @else
            Nội dung phân tích (metadata)
        @endif
    </h3>
    <div class="transcript-box">{{ $mediaItem->transcript ?? $mediaItem->analysis_payload['source_content'] ?? '' }}</div>
</div>
@endif

<p><a href="{{ route('admin.media-items.index') }}" class="btn btn-secondary">← Danh sách media</a></p>
@endsection
