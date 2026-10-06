@extends('layout')

@section('title', 'الفئات')
@section('page_title', 'الفئات المالية')
@section('breadcrumb', 'الإدارة المالية / الفئات')

@section('content')
@php
    $categoryTypes = ['income' => 'دخل', 'expense' => 'مصروف'];
@endphp

<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">الفئات المالية</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">نظّم معاملاتك باستخدام فئات واضحة للدخل والمصروفات.</p>
    </div>

    <section aria-labelledby="category-form-heading" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-5 sm:px-6">
            <h2 id="category-form-heading" class="text-lg font-semibold text-slate-900">إضافة فئة</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">اختر اسم الفئة ونوعها، وأضف أيقونة اختيارية لتمييزها.</p>
        </div>
        <form action="{{ route('categories.store') }}" method="POST" class="grid grid-cols-1 gap-5 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
            @csrf
            <div class="min-w-0">
                <label for="name" class="mb-2 block text-sm font-medium text-slate-700">اسم الفئة</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required placeholder="اسم الفئة" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" @error('name') aria-describedby="name-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('name'), 'border-slate-300' => ! $errors->has('name')])>
                @error('name')
                    <p id="name-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0">
                <label for="type" class="mb-2 block text-sm font-medium text-slate-700">نوع الفئة</label>
                <select id="type" name="type" aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" @error('type') aria-describedby="type-error" @enderror
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('type'), 'border-slate-300' => ! $errors->has('type')])>
                    @foreach($categoryTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', 'income') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('type')
                    <p id="type-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="min-w-0 sm:col-span-2 xl:col-span-1">
                <label for="icon" class="mb-2 block text-sm font-medium text-slate-700">الأيقونة (اختياري)</label>
                <input id="icon" name="icon" type="text" value="{{ old('icon') }}" placeholder="مثل 🏠" aria-invalid="{{ $errors->has('icon') ? 'true' : 'false' }}" aria-describedby="icon-help{{ $errors->has('icon') ? ' icon-error' : '' }}"
                    @class(['w-full rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200', 'border-red-400' => $errors->has('icon'), 'border-slate-300' => ! $errors->has('icon')])>
                <p id="icon-help" class="mt-2 text-xs leading-5 text-slate-500">يمكنك استخدام رمز تعبيري أو نص قصير.</p>
                @error('icon')
                    <p id="icon-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="border-t border-slate-100 pt-5 sm:col-span-2 sm:flex sm:justify-end xl:col-span-3">
                <button type="submit" class="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:w-auto">إضافة الفئة</button>
            </div>
        </form>
    </section>

    <section aria-labelledby="category-list-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-4 py-5 sm:px-6">
            <div>
                <h2 id="category-list-heading" class="text-lg font-semibold text-slate-900">الفئات المسجلة</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">فئات الدخل والمصروفات الخاصة بحسابك.</p>
            </div>
            <span class="shrink-0 rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700" aria-label="عدد الفئات">{{ $categories->count() }}</span>
        </div>
        @if($categories->isEmpty())
            <div class="px-5 py-12 text-center sm:px-6">
                <div aria-hidden="true" class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-2xl">🏷️</div>
                <h3 class="font-semibold text-slate-900">لا توجد فئات بعد</h3>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">أضف أول فئة دخل أو مصروف باستخدام النموذج أعلاه.</p>
            </div>
        @else
            <table role="table" class="block w-full text-right text-sm md:table">
                <caption class="sr-only">الفئات المالية وأنواعها وأيقوناتها</caption>
                <thead role="rowgroup" class="hidden border-b border-slate-200 bg-slate-50 text-xs text-slate-500 md:table-header-group">
                    <tr role="row">
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الاسم</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">النوع</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 font-semibold">الأيقونة</th>
                        <th scope="col" role="columnheader" class="px-6 py-3 text-left font-semibold">الإجراءات</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 md:table-row-group md:divide-y md:divide-slate-100 md:p-0">
                    @foreach($categories as $category)
                        <tr role="row" class="grid min-w-0 grid-cols-2 rounded-xl border border-slate-200 transition hover:bg-slate-50 md:table-row md:rounded-none md:border-0">
                            <th scope="row" role="rowheader" class="col-span-2 block break-words px-4 py-4 font-medium text-slate-900 md:table-cell md:px-6">{{ $category->name }}</th>
                            <td role="cell" class="block px-4 py-3 md:table-cell md:px-6 md:py-4">
                                <span class="mb-1 block text-xs text-slate-500 md:hidden">النوع</span>
                                <span @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $category->type === 'income', 'bg-rose-50 text-rose-700' => $category->type !== 'income'])>{{ $categoryTypes[$category->type] ?? $category->type }}</span>
                            </td>
                            <td role="cell" class="block min-w-0 px-4 py-3 md:table-cell md:px-6 md:py-4">
                                <span class="mb-1 block text-xs text-slate-500 md:hidden">الأيقونة</span>
                                @if(filled($category->icon))
                                    <span class="inline-flex max-w-full break-words rounded-lg bg-slate-50 px-2.5 py-1 text-sm text-slate-700">{{ $category->icon }}</span>
                                @else
                                    <span aria-label="لا توجد أيقونة" class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td role="cell" class="col-span-2 flex items-center justify-end border-t border-slate-100 px-4 py-3 text-left md:table-cell md:border-0 md:px-6 md:py-4">
                                <form action="{{ route('categories.destroy', $category->id) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذه الفئة؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" aria-label="حذف فئة {{ $category->name }}" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">حذف</button>
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
