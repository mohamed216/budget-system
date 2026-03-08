@extends('layout')
@section('title', 'المعاملات')
@section('content')
<h2>المعاملات</h2>
<form action="{{ route('transactions.store') }}" method="POST" class="row g-3 mb-4 p-3 bg-light rounded">
    @csrf
    <div class="col-md-2">
        <select name="type" class="form-select" required>
            <option value="income">دخل</option>
            <option value="expense">مصروف</option>
        </select>
    </div>
    <div class="col-md-2">
        <select name="account_id" class="form-select" required>
            @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select name="category_id" class="form-select" required>
            @foreach($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-2"><input type="number" name="amount" class="form-control" placeholder="المبلغ" required></div>
    <div class="col-md-2"><input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required></div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">إضافة</button></div>
</form>
<table class="table table-striped">
    <thead><tr><th>التاريخ</th><th>الحساب</th><th>الفئة</th><th>النوع</th><th>المبلغ</th></tr></thead>
    <tbody>
        @foreach($transactions as $t)
        <tr>
            <td>{{ $t->date }}</td>
            <td>{{ $t->account->name }}</td>
            <td>{{ $t->category->name }}</td>
            <td>{{ $t->type === 'income' ? 'دخل' : 'مصروف' }}</td>
            <td class="{{ $t->type }}">{{ $t->type === 'income' ? '+' : '-' }}{{ number_format($t->amount, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
