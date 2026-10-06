@extends('layout')

@section('title', 'لوحة التحكم')
@section('page_title', 'لوحة التحكم المالية')
@section('breadcrumb', 'الإدارة المالية / لوحة التحكم')

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">لوحة التحكم المالية</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">نظرة عامة على أرصدتك والدخل والمصروفات لجميع الفترات.</p>
    </div>

    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <dt class="text-sm font-medium text-slate-500">الرصيد الإجمالي</dt>
            <dd dir="ltr" @class(['mt-3 break-words text-right text-3xl font-bold tabular-nums', 'text-indigo-700' => $totalBalance >= 0, 'text-rose-700' => $totalBalance < 0])>{{ number_format($totalBalance, 2) }}</dd>
            <p class="mt-3 text-xs leading-5 text-slate-500">مجموع أرصدة الحسابات</p>
        </div>
        <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <dt class="text-sm font-medium text-slate-500">إجمالي الدخل</dt>
            <dd dir="ltr" class="mt-3 break-words text-right text-3xl font-bold tabular-nums text-emerald-700">+{{ number_format($totalIncome, 2) }}</dd>
            <p class="mt-3 text-xs leading-5 text-slate-500">جميع معاملات الدخل المسجلة</p>
        </div>
        <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <dt class="text-sm font-medium text-slate-500">إجمالي المصروفات</dt>
            <dd dir="ltr" class="mt-3 break-words text-right text-3xl font-bold tabular-nums text-rose-700">-{{ number_format($totalExpense, 2) }}</dd>
            <p class="mt-3 text-xs leading-5 text-slate-500">جميع معاملات المصروفات المسجلة</p>
        </div>
    </dl>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <section aria-labelledby="recent-transactions-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
            <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-4 py-5 sm:px-6">
                <div>
                    <h2 id="recent-transactions-heading" class="text-lg font-semibold text-slate-900">آخر المعاملات</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">أحدث المعاملات مرتبة حسب التاريخ.</p>
                </div>
                <span class="shrink-0 rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700" aria-label="عدد المعاملات المعروضة">{{ $recentTransactions->count() }}</span>
            </div>
            @if($recentTransactions->isEmpty())
                <div class="px-5 py-12 text-center sm:px-6">
                    <div aria-hidden="true" class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-2xl">↔</div>
                    <h3 class="font-semibold text-slate-900">لا توجد معاملات بعد</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-500">ستظهر هنا تفاصيل معاملات الدخل والمصروفات عند تسجيلها.</p>
                </div>
            @else
                <table role="table" class="block w-full text-right text-sm md:table">
                    <caption class="sr-only">آخر المعاملات مع التاريخ والحساب والفئة والنوع والمبلغ</caption>
                    <thead role="rowgroup" class="hidden border-b border-slate-200 bg-slate-50 text-xs text-slate-500 md:table-header-group">
                        <tr role="row">
                            <th scope="col" role="columnheader" class="px-4 py-3 font-semibold">التاريخ</th>
                            <th scope="col" role="columnheader" class="px-4 py-3 font-semibold">الحساب</th>
                            <th scope="col" role="columnheader" class="px-4 py-3 font-semibold">الفئة</th>
                            <th scope="col" role="columnheader" class="px-4 py-3 font-semibold">النوع</th>
                            <th scope="col" role="columnheader" class="px-4 py-3 font-semibold">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 md:table-row-group md:divide-y md:divide-slate-100 md:p-0">
                        @foreach($recentTransactions as $transaction)
                            <tr role="row" class="grid min-w-0 grid-cols-2 rounded-xl border border-slate-200 transition hover:bg-slate-50 md:table-row md:rounded-none md:border-0">
                                <th scope="row" role="rowheader" class="col-span-2 block px-4 py-4 font-medium text-slate-900 md:table-cell">
                                    <span class="mb-1 block text-xs font-normal text-slate-500 md:hidden">التاريخ</span>
                                    <span dir="ltr" class="inline-block whitespace-nowrap">{{ $transaction->date->format('Y-m-d') }}</span>
                                </th>
                                <td role="cell" class="block min-w-0 break-words px-4 py-3 text-slate-700 md:table-cell md:py-4">
                                    <span class="mb-1 block text-xs text-slate-500 md:hidden">الحساب</span>
                                    {{ $transaction->account?->name ?? 'حساب غير متاح' }}
                                </td>
                                <td role="cell" class="block min-w-0 break-words px-4 py-3 text-slate-700 md:table-cell md:py-4">
                                    <span class="mb-1 block text-xs text-slate-500 md:hidden">الفئة</span>
                                    {{ $transaction->category?->name ?? 'فئة غير متاحة' }}
                                </td>
                                <td role="cell" class="block px-4 py-3 md:table-cell md:py-4">
                                    <span class="mb-1 block text-xs text-slate-500 md:hidden">النوع</span>
                                    <span @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $transaction->type === 'income', 'bg-rose-50 text-rose-700' => $transaction->type !== 'income'])>{{ $transaction->type === 'income' ? 'دخل' : 'مصروف' }}</span>
                                </td>
                                <td role="cell" class="block px-4 py-3 md:table-cell md:py-4">
                                    <span class="mb-1 block text-xs text-slate-500 md:hidden">المبلغ</span>
                                    <span dir="ltr" @class(['inline-block whitespace-nowrap font-semibold tabular-nums', 'text-emerald-700' => $transaction->type === 'income', 'text-rose-700' => $transaction->type !== 'income'])>{{ $transaction->type === 'income' ? '+' : '-' }}{{ number_format($transaction->amount, 2) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section aria-labelledby="net-summary-heading" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-4 py-5 sm:px-6">
                <h2 id="net-summary-heading" class="text-lg font-semibold text-slate-900">ملخص الدخل والمصروفات</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">جميع الفترات، وفق المعاملات المسجلة.</p>
            </div>
            <dl class="p-5 sm:p-6">
                <dt class="text-sm font-medium text-slate-500">صافي الدخل بعد المصروفات</dt>
                <dd dir="ltr" @class(['mt-3 break-words text-right text-3xl font-bold tabular-nums', 'text-emerald-700' => $totalIncome - $totalExpense >= 0, 'text-rose-700' => $totalIncome - $totalExpense < 0])>{{ number_format($totalIncome - $totalExpense, 2) }}</dd>
            </dl>
        </section>
    </div>

    <section aria-labelledby="category-summary-heading" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-5 sm:px-6">
            <h2 id="category-summary-heading" class="text-lg font-semibold text-slate-900">ملخص الفئات</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">إجماليات جميع الفترات حسب رقم الفئة.</p>
        </div>
        <div class="grid grid-cols-1 gap-6 p-4 sm:p-6 md:grid-cols-2">
            @foreach(['income' => $incomeByCategory, 'expense' => $expenseByCategory] as $type => $categoryTotals)
                <div class="min-w-0">
                    <h3 @class(['mb-3 text-sm font-semibold', 'text-emerald-700' => $type === 'income', 'text-rose-700' => $type === 'expense'])>{{ $type === 'income' ? 'الدخل حسب الفئة' : 'المصروفات حسب الفئة' }}</h3>
                    @if($categoryTotals->isEmpty())
                        <p class="rounded-xl bg-slate-50 px-4 py-6 text-sm leading-6 text-slate-500">{{ $type === 'income' ? 'لا توجد معاملات دخل لعرض ملخص الفئات.' : 'لا توجد معاملات مصروفات لعرض ملخص الفئات.' }}</p>
                    @else
                        <dl class="divide-y divide-slate-100 rounded-xl border border-slate-200 px-4">
                            @foreach($categoryTotals as $categoryTotal)
                                <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                                    <dt class="text-sm font-medium text-slate-700">الفئة <span dir="ltr" class="inline-block">#{{ $categoryTotal->category_id }}</span></dt>
                                    <dd dir="ltr" @class(['break-words text-sm font-semibold tabular-nums', 'text-emerald-700' => $type === 'income', 'text-rose-700' => $type === 'expense'])>{{ $type === 'income' ? '+' : '-' }}{{ number_format($categoryTotal->total, 2) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection
