@extends('admin.layout')

@section('title', 'Người dùng')
@section('heading', 'Người dùng')

@section('content')
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Tên</th>
            <th>Email</th>
            <th>Từ vựng</th>
            <th>Media</th>
            <th>Đăng ký</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($users as $user)
            <tr>
                <td>{{ $user->id }}</td>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->vocabularies_count }}</td>
                <td>{{ $user->media_items_count }}</td>
                <td>{{ $user->created_at->format('d/m/Y') }}</td>
                <td>
                    <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm">Chi tiết</a>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
{{ $users->links() }}
@endsection
