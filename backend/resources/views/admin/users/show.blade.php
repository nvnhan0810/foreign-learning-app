@extends('admin.layout')

@section('title', $user->name)
@section('heading', 'Người dùng: '.$user->name)

@section('content')
<div class="card">
    <p><strong>Email:</strong> {{ $user->email }}</p>
    <p><strong>Google ID:</strong> {{ $user->google_id ?? '—' }}</p>
    <p><strong>Tham gia:</strong> {{ $user->created_at }}</p>
    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" onsubmit="return confirm('Xóa user và toàn bộ dữ liệu?')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-danger">Xóa người dùng</button>
    </form>
</div>

<h3>Từ vựng ({{ $user->vocabularies->count() }})</h3>
<table>
    <thead><tr><th>Từ</th><th>Quiz</th><th></th></tr></thead>
    <tbody>
        @foreach ($user->vocabularies as $v)
            <tr>
                <td>{{ $v->dictionaryEntry?->word ?? '—' }}</td>
                <td>{{ $v->times_quizzed }}</td>
                <td>
                    @if ($v->dictionaryEntry)
                        <a href="{{ route('admin.dictionary.edit', $v->dictionaryEntry) }}">Từ điển</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<h3>Media ({{ $user->mediaItems->count() }})</h3>
<table>
    <thead><tr><th>Tiêu đề</th><th>Tần suất</th><th></th></tr></thead>
    <tbody>
        @foreach ($user->mediaItems as $m)
            <tr>
                <td>{{ $m->title }}</td>
                <td>{{ $m->frequency }}</td>
                <td><a href="{{ route('admin.media-items.edit', $m) }}">Sửa</a></td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
