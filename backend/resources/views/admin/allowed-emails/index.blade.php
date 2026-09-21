@extends('admin.layout')

@section('title', 'Allowlist email')
@section('heading', 'Allowlist email')

@section('content')
<div style="margin-bottom:16px">
    <a href="{{ route('admin.allowed-emails.create') }}" class="btn">+ Thêm email</a>
</div>

<p class="muted">Pattern: <code>user@gmail.com</code> hoặc <code>*@domain.com</code>. Gộp với danh sách trong <code>FLC_ALLOWED_EMAILS</code> (.env).</p>

<table>
    <thead>
        <tr>
            <th>Pattern</th>
            <th>Nhãn</th>
            <th>Trạng thái</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td><code>{{ $entry->pattern }}</code></td>
                <td>{{ $entry->label ?? '—' }}</td>
                <td>
                    @if ($entry->isActive)
                        <span class="badge">Active</span>
                    @else
                        <span class="badge badge-off">Tắt</span>
                    @endif
                </td>
                <td>
                    <a href="{{ route('admin.allowed-emails.edit', $entry->id) }}" class="btn btn-sm btn-secondary">Sửa</a>
                    <form action="{{ route('admin.allowed-emails.destroy', $entry->id) }}" method="POST" class="inline-form" onsubmit="return confirm('Xóa mục này?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Chưa có mục nào trong database.</td></tr>
        @endforelse
    </tbody>
</table>

{{ $entries->links() }}
@endsection
