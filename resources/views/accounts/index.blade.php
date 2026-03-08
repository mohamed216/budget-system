@extends('layout')
@section('title', 'الحسابات')
@section('content')
<h2>الحسابات</h2>
<form action="{{ route('accounts.store') }}" method="POST" class="row g-3 mb-4">
    @csrf
    <div class="col-md-3"><input type="text" name="name" class="form-control" placeholder="اسم الحساب" required></div>
    <div class="col-md-3">
        <select name="type" class="form-select">
            <option value="cash">نقدي</option>
            <option value="bank">بنك</option>
            <option value="wallet">محفظة</option>
        </select>
    </div>
    <div class="col-md-3"><input type="number" name="balance" class="form-control" placeholder="الرصيد" required></div>
    <div class="col-md-3"><button type="submit" class="btn btn-primary w-100">إضافة</button></div>
</form>
<table class="table table-striped">
    <thead><tr><th>الاسم</th><th>النوع</th><th>الرصيد</th><th></th></tr></thead>
    <tbody>
        @foreach($accounts as $account)
        <tr>
            <td>{{ $account->name }}</td>
            <td>{{ $account->type }}</td>
            <td>{{ number_format($account->balance, 2) }}</td>
            <td>
                <form action="{{ route('accounts.destroy', $account->id) }}" method="POST">@csrf @method('DELETE')<button type="submit" class="btn btn-danger btn-sm">حذف</button></form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
