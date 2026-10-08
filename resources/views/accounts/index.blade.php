@extends('layout')

@section('title', 'الحسابات')
@section('page_title', 'الحسابات المالية')
@section('breadcrumb', 'الإدارة المالية / الحسابات')

@section('content')
@php
    $accountTypes = ['cash' => 'نقدي', 'bank' => 'بنك', 'wallet' => 'محفظة'];
@endphp

<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">الحسابات المالية</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">أضف حساباتك النقدية والبنكية والمحافظ، واطّلع على أرصدتها وعملاتها.</p>
    </div>

    <section aria-labelledby="account-form-heading" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-5 sm:px-6">
            <h2 id="account-form-heading" class="text-lg font-semibold text-slate-900">إضافة حساب</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">حدد نوع الحساب ورصيده عند الإنشاء والعملة المستخدمة.</p>
        </div>
        <form action="{{ route('accounts.store') }}" method="POST" class="grid grid-cols-1 gap-5 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-4">
            @csrf
            <div class="min-w-0">
                <label for="name" class="mb-2 block text-sm font-medium text-slate-700">اسم الحساب</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required placeholder="اسم الحساب" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" @error('name') aria-describedby="name-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('name'), 'border-slate-300' => ! $errors->has('name')])>
                @error('name')
                    <p id="name-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="type" class="mb-2 block text-sm font-medium text-slate-700">نوع الحساب</label>
                <select id="type" name="type" aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" @error('type') aria-describedby="type-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('type'), 'border-slate-300' => ! $errors->has('type')])>
                    @foreach($accountTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', 'cash') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('type')
                    <p id="type-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="balance" class="mb-2 block text-sm font-medium text-slate-700">الرصيد عند الإنشاء</label>
                <input id="balance" name="balance" type="number" value="{{ old('balance') }}" step="0.01" min="0" required placeholder="0.00" aria-invalid="{{ $errors->has('balance') ? 'true' : 'false' }}" @error('balance') aria-describedby="balance-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('balance'), 'border-slate-300' => ! $errors->has('balance')])>
                @error('balance')
                    <p id="balance-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="currency" class="mb-2 block text-sm font-medium text-slate-700">العملة</label>
                <input id="currency" name="currency" type="text" value="{{ old('currency', 'SAR') }}" dir="ltr" aria-invalid="{{ $errors->has('currency') ? 'true' : 'false' }}" aria-describedby="currency-help{{ $errors->has('currency') ? ' currency-error' : '' }}"
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('currency'), 'border-slate-300' => ! $errors->has('currency')])>
                <p id="currency-help" class="mt-2 text-xs leading-5 text-slate-500">رمز من ثلاثة أحرف لاتينية كبيرة، مثل SAR أو USD.</p>
                @error('currency')
                    <p id="currency-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="border-t border-slate-100 pt-5 sm:col-span-2 sm:flex sm:justify-end xl:col-span-4">
                <button type="submit" class="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:w-auto">إضافة الحساب</button>
            </div>
        </form>
    </section>

    <section aria-labelledby="account-list-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-4 py-5 sm:px-6">
            <div>
                <h2 id="account-list-heading" class="text-lg font-semibold text-slate-900">الحسابات المسجلة</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">أرصدة الحسابات مع عرض عملة كل حساب.</p>
            </div>
            <span class="shrink-0 rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700" aria-label="عدد الحسابات">{{ $accounts->count() }}</span>
        </div>
        @if($accounts->isEmpty())
            <div class="px-5 py-12 text-center sm:px-6">
                <div aria-hidden="true" class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-2xl">💳</div>
                <h3 class="font-semibold text-slate-900">لا توجد حسابات بعد</h3>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">أضف أول حساب نقدي أو بنكي أو محفظة باستخدام النموذج أعلاه.</p>
            </div>
        @else
            <table role="table" class="block w-full text-right text-sm md:table">
                <caption class="sr-only">الحسابات المالية وأرصدة كل حساب وعملته</caption>
                <thead role="rowgroup" class="hidden border-b border-slate-200 bg-slate-50 text-xs text-slate-500 md:table-header-group">
                    <tr role="row">
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الاسم</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">النوع</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الرصيد</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">العملة</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 text-left font-semibold">الإجراءات</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 md:table-row-group md:divide-y md:divide-slate-100 md:p-0">
                    @foreach($accounts as $account)
                        <tr role="row" class="grid min-w-0 grid-cols-2 rounded-xl border border-slate-200 transition hover:bg-slate-50 md:table-row md:rounded-none md:border-0">
                            <th scope="row" role="rowheader" class="col-span-2 block break-words px-4 py-4 font-medium text-slate-900 md:table-cell md:px-6">{{ $account->name }}</th>
                            <td role="cell" class="block px-4 py-3 md:table-cell md:px-6 md:py-4">
                                <span class="mb-1 block text-xs text-slate-500 md:hidden">النوع</span>
                                <span class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">{{ $accountTypes[$account->type] ?? $account->type }}</span>
                            </td>
                            <td role="cell" class="block px-4 py-3 md:table-cell md:px-6 md:py-4">
                                <span class="mb-1 block text-xs text-slate-500 md:hidden">الرصيد</span>
                                <span dir="ltr" class="font-semibold tabular-nums text-slate-900">{{ \App\Support\MoneyDisplay::format($account->balance) }}</span>
                            </td>
                            <td role="cell" class="block px-4 py-3 md:table-cell md:px-6 md:py-4">
                                <span class="mb-1 block text-xs text-slate-500 md:hidden">العملة</span>
                                <span dir="ltr" class="font-medium text-slate-600">{{ $account->currency }}</span>
                            </td>
                            <td role="cell" class="flex items-end justify-end px-4 py-3 text-left md:table-cell md:px-6 md:py-4">
                                <form action="{{ route('accounts.destroy', $account->id) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا الحساب؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" aria-label="حذف حساب {{ $account->name }}" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">حذف</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
@endsection
