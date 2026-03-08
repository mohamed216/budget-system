@extends('layout')
@section('title', 'الفئات')
@section('content')
<h2>الفئات</h2>
<form action="{{ route('categories.store') }}" method="POST" class="row g-3 mb-4">
    @csrf
    <div class="col-md-4"><input type="text" name="name" class="form-control" placeholder="اسم الفئة" required></div>
    <div class="col-md-4">
        <select name="type" class="form-select">
            <option value="income">دخل</option>
            <option value="expense">مصروف</option>
        </select>
    </div>
    <div class="col-md-4"><button type="submit" class="btn btn-primary w-100">إضافة</button></div>
</form>
<table class="table table-striped">
    <thead><tr><th>الاسم</th><th>النوع</th><th></th></tr></thead>
    <tbody>
        @foreach($categories as $category)
        <tr>
            <td>{{ $category->name }}</td>
            <td>{{ $category->type === 'income' ? 'دخل' : 'مصروف' }}</td>
            <td>
                <form action="{{ route('categories.destroy', $category->id) }}" method="POST">@csrf @method('DELETE')<button type="submit" class="btn btn-danger btn-sm">حذف</button></form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
