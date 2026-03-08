@extends('layout')
@section('title', 'لوحة التحكم')
@section('content')
<h2 class="mb-4">لوحة التحكم</h2>

<div class="row">
    <div class="col-md-4">
        <div class="card text-center p-4">
            <h5>الرصيد الإجمالي</h5>
            <h2 class="text-primary">{{ number_format($totalBalance, 2) }}</h2>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-4">
            <h5>إجمالي الدخل</h5>
            <h2 class="income">+{{ number_format($totalIncome, 2) }}</h2>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-4">
            <h5>إجمالي المصروفات</h5>
            <h2 class="expense">-{{ number_format($totalExpense, 2) }}</h2>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">آخر المعاملات</div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>الفئة</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentTransactions as $t)
                        <tr>
                            <td>{{ $t->date }}</td>
                            <td>{{ $t->category->name }}</td>
                            <td class="{{ $t->type }}">{{ $t->type === 'income' ? '+' : '-' }}{{ number_format($t->amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">ملخص الشهر</div>
            <div class="card-body">
                <p>صافي الميزانية: <strong>{{ number_format($totalIncome - $totalExpense, 2) }}</strong></p>
            </div>
        </div>
    </div>
</div>
@endsection
