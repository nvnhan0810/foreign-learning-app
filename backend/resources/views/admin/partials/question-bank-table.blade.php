@php
    $panelId = $panelId ?? 'question-bank-panel';
@endphp

<div class="card answers-panel answers-hidden" id="{{ $panelId }}">
    <div class="answers-panel-header">
        <h3 style="margin-top:0">Danh sách câu hỏi ({{ $questions->count() }})</h3>
        <button
            type="button"
            class="answer-toggle-btn"
            data-answer-toggle="{{ $panelId }}"
            aria-pressed="false"
            title="Hiện đáp án"
        >
            <svg class="icon-eye icon-eye-show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                <path fill="currentColor" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
            </svg>
            <svg class="icon-eye icon-eye-hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                <path fill="currentColor" d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78 3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
            </svg>
            <span class="answer-toggle-label">Hiện đáp án</span>
        </button>
    </div>

    @if ($questions->isEmpty())
        <p class="muted">Chưa có câu hỏi nào trong ngân hàng.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Loại</th>
                    <th>Câu hỏi</th>
                    <th>Đáp án đúng</th>
                    <th>Giải thích</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($questions as $q)
                    <tr>
                        <td>{{ $q->order }}</td>
                        <td><span class="badge">{{ $q->question_type }}</span></td>
                        <td>
                            {{ $q->prompt }}
                            @if ($q->options)
                                <ul class="options-list">
                                    @foreach ($q->options as $opt)
                                        <li @if($opt === $q->correct_answer) data-correct="1" @endif>{{ $opt }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="answer-cell">
                            <div class="answer-block">
                                <span class="answer-text"><strong>{{ $q->correct_answer }}</strong></span>
                            </div>
                        </td>
                        <td class="answer-cell">
                            <div class="answer-block">
                                <span class="answer-text muted">{{ $q->explanation }}</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
