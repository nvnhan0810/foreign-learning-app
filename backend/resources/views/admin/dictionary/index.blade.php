@extends('admin.layout')

@section('title', 'Từ điển')
@section('heading', 'Từ điển')

@section('content')
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
    <form class="search-bar" method="GET" style="margin:0;flex:1">
        <input type="search" name="q" value="{{ $search }}" placeholder="Tìm từ / cụm từ...">
        <select name="curated">
            <option value="" @selected($curated === '')>Tất cả</option>
            <option value="1" @selected($curated === '1')>Đã curate</option>
            <option value="0" @selected($curated === '0')>Chưa curate</option>
        </select>
        <button type="submit" class="btn">Tìm</button>
    </form>
    <a href="{{ route('admin.dictionary.create') }}" class="btn">+ Thêm từ</a>
</div>

<table>
    <thead>
        <tr>
            <th>Từ / cụm từ</th>
            <th>Nghĩa</th>
            <th>Lưu từ</th>
            <th>Curated</th>
            <th>Nguồn</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td><strong>{{ $entry->word }}</strong></td>
                <td>{{ $entry->meanings_count }}</td>
                <td>{{ $entry->save_count }}</td>
                <td>
                    @if ($entry->is_curated)
                        <span class="badge badge-ready">Yes</span>
                    @else
                        <span class="badge badge-pending">No</span>
                    @endif
                </td>
                <td class="muted">{{ $entry->source }}</td>
                <td>
                    <a href="{{ route('admin.dictionary.edit', $entry) }}" class="btn btn-sm">Sửa</a>
                    <form action="{{ route('admin.dictionary.destroy', $entry) }}" method="POST" class="inline-form" onsubmit="return confirm('Xóa khỏi từ điển?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="muted">Chưa có từ nào. User Lưu từ hoặc admin thêm mới.</td>
            </tr>
        @endforelse
    </tbody>
</table>
{{ $entries->links() }}
@endsection
