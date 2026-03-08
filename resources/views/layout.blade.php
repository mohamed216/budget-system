<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'نظام الميزانية')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Tahoma', sans-serif; }
        .sidebar { min-height: 100vh; background: #2c3e50; }
        .sidebar a { color: #ecf0f1; text-decoration: none; padding: 12px 20px; display: block; }
        .sidebar a:hover { background: #34495e; }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .income { color: #27ae60; }
        .expense { color: #e74c3c; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 sidebar p-0">
                <h4 class="text-white p-3">💰 الميزانية</h4>
                <a href="{{ route('dashboard') }}"><i class="fas fa-home"></i> الرئيسية</a>
                <a href="{{ route('accounts.index') }}"><i class="fas fa-wallet"></i> الحسابات</a>
                <a href="{{ route('categories.index') }}"><i class="fas fa-tags"></i> الفئات</a>
                <a href="{{ route('transactions.index') }}"><i class="fas fa-exchange-alt"></i> المعاملات</a>
                <a href="{{ route('budgets.index') }}"><i class="fas fa-chart-pie"></i> الميزانية</a>
            </div>
            <div class="col-md-10 p-4">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @yield('content')
            </div>
        </div>
    </div>
</body>
</html>
