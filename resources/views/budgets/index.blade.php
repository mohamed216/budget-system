@extends('layout')
@section('title', 'الميزانية')
@section('content')
<h2>الميزانية الشهرية</h2>
<form action="{{ route('budgets.store') }}" method="POST" class="row g-3 mb-4 p-3 bg-light rounded">
    @csrf
    <div class="col-md-3">
        <select name="category_id" class="form-select" required>
            @foreach($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3"><input type="number" name="amount" class="form-control" placeholder="المبلغ" required></div>
    <div class="col-md-2">
        <select name="month" class="form-select">
            @for($m=1;$m<=12;$m++)><option value="{{ $m }}" {{ $m==date('m')?'selected':'' }}>{{ $m }}</option>@endfor
        </select>
    </div>
    <div class="col-md-2">
        <select name="year" class="form-select">
            @for($y=2024;$y<=2027;$y++)><option value="{{ $y }}" {{ $y==date('Y')?'selected':'' }}>{{ $y }}</option>@endfor
        </select>
    </div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">حفظ</button></div>
</form>
<table class="table table-striped">
    <thead><tr><th>الفئة</th><th>الميزانية</th><th>الشهر</th><th>السنة</th><th></th></tr></thead>
    <tbody>
        @foreach($budgets as $budget)
        <tr>
            <td>{{ $budget->category->name }}</td>
            <td>{{ number_format($budget->amount, 2) }}</td>
            <td>{{ $budget->month }}</td>
            <td>{{ $budget->year }}</td>
            <td>
                <form action="{{ route('budgets.destroy', $budget->id) }}" method="POST">@csrf @method('DELETE')<button type="submit" class="btn btn-danger btn-sm">حذف</button></form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
