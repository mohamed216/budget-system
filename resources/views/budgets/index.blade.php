@extends('layout')

@section('title', 'الميزانية')
@section('page_title', 'الميزانية الشهرية')
@section('breadcrumb', 'الإدارة المالية / الميزانية')

@section('content')
@php
    $months = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
@endphp

<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">الميزانية الشهرية</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">حدد حدود الإنفاق الشهرية لفئات المصروفات، وعدّلها عند الحاجة.</p>
    </div>

    <section aria-labelledby="budget-form-heading" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-5 sm:px-6">
            <h2 id="budget-form-heading" class="text-lg font-semibold text-slate-900">إضافة أو تحديث ميزانية</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">حفظ ميزانية لنفس الفئة والشهر والسنة يحدّث المبلغ المسجل.</p>
        </div>
        <div class="p-4 sm:p-6">
            @if($categories->isEmpty())
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                    <h3 class="font-semibold text-amber-900">لا توجد فئات مصروفات حتى الآن</h3>
                    <p class="mt-2 text-sm leading-6 text-amber-800">أنشئ فئة من نوع «مصروف» أولاً لتتمكن من إضافة ميزانية شهرية.</p>
                    <a href="{{ route('categories.index') }}" class="mt-4 inline-flex w-full items-center justify-center rounded-lg bg-amber-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700 sm:w-auto">إدارة الفئات</a>
                </div>
            @else
                <form action="{{ route('budgets.store') }}" method="POST" class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @csrf
                    <div class="min-w-0">
                        <label for="category_id" class="mb-2 block text-sm font-medium text-slate-700">فئة المصروف</label>
                        <select id="category_id" name="category_id" required aria-invalid="{{ $errors->has('category_id') ? 'true' : 'false' }}" @error('category_id') aria-describedby="category_id-error" @enderror
                            @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('category_id'), 'border-slate-300' => ! $errors->has('category_id')])>
                            <option value="" @selected(old('category_id', '') === '')>اختر الفئة</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id')
                            <p id="category_id-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="min-w-0">
                        <label for="amount" class="mb-2 block text-sm font-medium text-slate-700">مبلغ الميزانية</label>
                        <input id="amount" name="amount" type="number" value="{{ old('amount') }}" step="0.01" min="0.01" required placeholder="0.00" aria-invalid="{{ $errors->has('amount') ? 'true' : 'false' }}" @error('amount') aria-describedby="amount-error" @enderror
                            @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('amount'), 'border-slate-300' => ! $errors->has('amount')])>
                        @error('amount')
                            <p id="amount-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="min-w-0">
                        <label for="month" class="mb-2 block text-sm font-medium text-slate-700">الشهر</label>
                        <select id="month" name="month" required aria-invalid="{{ $errors->has('month') ? 'true' : 'false' }}" @error('month') aria-describedby="month-error" @enderror
                            @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('month'), 'border-slate-300' => ! $errors->has('month')])>
                            @foreach($months as $number => $name)
                                <option value="{{ $number }}" @selected(old('month', now()->month) == $number)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('month')
                            <p id="month-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="min-w-0">
                        <label for="year" class="mb-2 block text-sm font-medium text-slate-700">السنة</label>
                        <input id="year" name="year" type="number" value="{{ old('year', now()->year) }}" min="1900" max="2100" step="1" required aria-invalid="{{ $errors->has('year') ? 'true' : 'false' }}" @error('year') aria-describedby="year-error" @enderror
                            @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('year'), 'border-slate-300' => ! $errors->has('year')])>
                        @error('year')
                            <p id="year-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="border-t border-slate-100 pt-5 sm:col-span-2 sm:flex sm:justify-end xl:col-span-4">
                        <button type="submit" class="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:w-auto">حفظ الميزانية</button>
                    </div>
                </form>
            @endif
        </div>
    </section>

    <section aria-labelledby="budget-list-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-4 py-5 sm:px-6">
            <div>
                <h2 id="budget-list-heading" class="text-lg font-semibold text-slate-900">الميزانيات المسجلة</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">الميزانيات الشهرية الخاصة بحسابك.</p>
            </div>
            <span class="shrink-0 rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700" aria-label="عدد الميزانيات">{{ $budgets->count() }}</span>
        </div>
        @if($budgets->isEmpty())
            <div class="px-5 py-12 text-center sm:px-6">
                <div aria-hidden="true" class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-2xl">📊</div>
                <h3 class="font-semibold text-slate-900">لا توجد ميزانيات بعد</h3>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                    @if($categories->isEmpty())
                        ابدأ بإنشاء فئة مصروفات، ثم حدد ميزانيتها الشهرية.
                    @else
                        أضف أول ميزانية باستخدام النموذج أعلاه.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[580px] text-right text-sm">
                    <caption class="sr-only">قائمة الميزانيات حسب الفئة والشهر والسنة</caption>
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold sm:px-6">الفئة</th>
                            <th scope="col" class="px-4 py-3 font-semibold sm:px-6">المبلغ</th>
                            <th scope="col" class="px-4 py-3 font-semibold sm:px-6">الشهر</th>
                            <th scope="col" class="px-4 py-3 font-semibold sm:px-6">السنة</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold sm:px-6">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($budgets as $budget)
                            <tr class="transition hover:bg-slate-50">
                                <th scope="row" class="px-4 py-4 font-medium text-slate-900 sm:px-6">{{ $budget->category->name }}</th>
                                <td class="whitespace-nowrap px-4 py-4 font-semibold tabular-nums text-slate-900 sm:px-6"><span dir="ltr">{{ \App\Support\MoneyDisplay::format($budget->amount) }}</span></td>
                                <td class="whitespace-nowrap px-4 py-4 text-slate-600 sm:px-6">{{ $months[$budget->month] ?? $budget->month }}</td>
                                <td class="px-4 py-4 tabular-nums text-slate-600 sm:px-6">{{ $budget->year }}</td>
                                <td class="px-4 py-4 text-left sm:px-6">
                                    <form action="{{ route('budgets.destroy', $budget) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذه الميزانية؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" aria-label="حذف ميزانية {{ $budget->category->name }} لشهر {{ $budget->month }} سنة {{ $budget->year }}" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
