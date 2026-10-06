@extends('layout')

@section('title', 'المعاملات')
@section('page_title', 'المعاملات المالية')
@section('breadcrumb', 'الإدارة المالية / المعاملات')

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">المعاملات المالية</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">سجّل الدخل والمصروفات وتابع تفاصيل معاملاتك المالية.</p>
    </div>

    <section aria-labelledby="transaction-form-heading" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-5 sm:px-6">
            <h2 id="transaction-form-heading" class="text-lg font-semibold text-slate-900">إضافة معاملة</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">اختر الحساب وفئة متوافقة مع نوع المعاملة.</p>
        </div>
        <form action="{{ route('transactions.store') }}" method="POST" class="grid grid-cols-1 gap-5 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
            @csrf
            <div class="min-w-0">
                <label for="type" class="mb-2 block text-sm font-medium text-slate-700">نوع المعاملة</label>
                <select id="type" name="type" required aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" @error('type') aria-describedby="type-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('type'), 'border-slate-300' => ! $errors->has('type')])>
                    <option value="income" @selected(old('type', 'income') === 'income')>دخل</option>
                    <option value="expense" @selected(old('type', 'income') === 'expense')>مصروف</option>
                </select>
                @error('type')
                    <p id="type-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="account_id" class="mb-2 block text-sm font-medium text-slate-700">الحساب</label>
                <select id="account_id" name="account_id" required aria-invalid="{{ $errors->has('account_id') ? 'true' : 'false' }}" @error('account_id') aria-describedby="account_id-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('account_id'), 'border-slate-300' => ! $errors->has('account_id')])>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
                @error('account_id')
                    <p id="account_id-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="category_id" class="mb-2 block text-sm font-medium text-slate-700">الفئة</label>
                <select id="category_id" name="category_id" required aria-invalid="{{ $errors->has('category_id') ? 'true' : 'false' }}" @error('category_id') aria-describedby="category_id-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('category_id'), 'border-slate-300' => ! $errors->has('category_id')])>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('category_id')
                    <p id="category_id-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="amount" class="mb-2 block text-sm font-medium text-slate-700">المبلغ</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" placeholder="المبلغ" value="{{ old('amount') }}" required aria-invalid="{{ $errors->has('amount') ? 'true' : 'false' }}" @error('amount') aria-describedby="amount-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('amount'), 'border-slate-300' => ! $errors->has('amount')])>
                @error('amount')
                    <p id="amount-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="date" class="mb-2 block text-sm font-medium text-slate-700">التاريخ</label>
                <input id="date" name="date" type="date" value="{{ old('date', date('Y-m-d')) }}" required aria-invalid="{{ $errors->has('date') ? 'true' : 'false' }}" @error('date') aria-describedby="date-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('date'), 'border-slate-300' => ! $errors->has('date')])>
                @error('date')
                    <p id="date-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0 sm:col-span-2 xl:col-span-3">
                <label for="description" class="mb-2 block text-sm font-medium text-slate-700">الوصف (اختياري)</label>
                <textarea id="description" name="description" rows="3" placeholder="تفاصيل المعاملة" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" @error('description') aria-describedby="description-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('description'), 'border-slate-300' => ! $errors->has('description')])>{{ old('description') }}</textarea>
                @error('description')
                    <p id="description-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="border-t border-slate-100 pt-5 sm:col-span-2 sm:flex sm:justify-end xl:col-span-3">
                <button type="submit" class="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:w-auto">إضافة المعاملة</button>
            </div>
        </form>
    </section>

    <section aria-labelledby="transaction-list-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-4 py-5 sm:px-6">
            <div>
                <h2 id="transaction-list-heading" class="text-lg font-semibold text-slate-900">المعاملات المسجلة</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">تفاصيل الدخل والمصروفات الخاصة بحسابك.</p>
            </div>
            <span class="shrink-0 rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700" aria-label="عدد المعاملات">{{ $transactions->count() }}</span>
        </div>
        @if($transactions->isEmpty())
            <div class="px-5 py-12 text-center sm:px-6">
                <div aria-hidden="true" class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-2xl">↔</div>
                <h3 class="font-semibold text-slate-900">لا توجد معاملات لعرضها</h3>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">ستظهر المعاملات هنا عند تسجيلها ضمن النتائج الحالية.</p>
            </div>
        @else
            <table role="table" class="block w-full text-right text-sm lg:table">
                <caption class="sr-only">المعاملات المالية وتفاصيل الحساب والفئة والتاريخ والوصف</caption>
                <thead role="rowgroup" class="hidden border-b border-slate-200 bg-slate-50 text-xs text-slate-500 lg:table-header-group">
                    <tr role="row">
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">التاريخ</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الحساب</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الفئة</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">النوع</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">المبلغ</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الوصف</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:table-row-group lg:divide-y lg:divide-slate-100 lg:p-0">
                    @foreach($transactions as $transaction)
                        <tr role="row" class="grid min-w-0 grid-cols-2 rounded-xl border border-slate-200 transition hover:bg-slate-50 lg:table-row lg:rounded-none lg:border-0">
                            <th scope="row" role="rowheader" class="col-span-2 block px-4 py-4 font-medium text-slate-900 lg:table-cell lg:px-6">
                                <span class="mb-1 block text-xs font-normal text-slate-500 lg:hidden">التاريخ</span>
                                <span dir="ltr" class="inline-block whitespace-nowrap">{{ $transaction->date->format('Y-m-d') }}</span>
                            </th>
                            <td role="cell" class="block min-w-0 break-words px-4 py-3 text-slate-700 lg:table-cell lg:px-6 lg:py-4">
                                <span class="mb-1 block text-xs text-slate-500 lg:hidden">الحساب</span>
                                {{ $transaction->account->name }}
                            </td>
                            <td role="cell" class="block min-w-0 break-words px-4 py-3 text-slate-700 lg:table-cell lg:px-6 lg:py-4">
                                <span class="mb-1 block text-xs text-slate-500 lg:hidden">الفئة</span>
                                {{ $transaction->category->name }}
                            </td>
                            <td role="cell" class="block px-4 py-3 lg:table-cell lg:px-6 lg:py-4">
                                <span class="mb-1 block text-xs text-slate-500 lg:hidden">النوع</span>
                                <span @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $transaction->type === 'income', 'bg-rose-50 text-rose-700' => $transaction->type !== 'income'])>{{ $transaction->type === 'income' ? 'دخل' : 'مصروف' }}</span>
                            </td>
                            <td role="cell" class="block px-4 py-3 lg:table-cell lg:px-6 lg:py-4">
                                <span class="mb-1 block text-xs text-slate-500 lg:hidden">المبلغ</span>
                                <span dir="ltr" @class(['inline-block whitespace-nowrap font-semibold tabular-nums', 'text-emerald-700' => $transaction->type === 'income', 'text-rose-700' => $transaction->type !== 'income'])>{{ $transaction->type === 'income' ? '+' : '-' }}{{ number_format($transaction->amount, 2) }}</span>
                            </td>
                            <td role="cell" class="col-span-2 block min-w-0 break-words border-t border-slate-100 px-4 py-3 text-slate-700 lg:table-cell lg:max-w-xs lg:border-0 lg:px-6 lg:py-4">
                                <span class="mb-1 block text-xs text-slate-500 lg:hidden">الوصف</span>
                                @if(filled($transaction->description))
                                    <span class="whitespace-pre-line">{{ $transaction->description }}</span>
                                @else
                                    <span aria-label="لا يوجد وصف" class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
@endsection
