@extends('layout')
@section('title', 'مسودة قيد')
@section('page_title', $journal ? 'تعديل مسودة قيد' : 'مسودة قيد جديدة')
@section('breadcrumb', 'المحاسبة / القيود اليومية / المسودة')
@section('content')
@php
    $rows = old('lines', $journal?->lines->map(fn($line) => $line->only(['chart_account_id', 'debit', 'credit', 'description']))->all() ?? []);
    $rows = is_array($rows) ? array_filter($rows, 'is_array') : [];
@endphp
<form method="POST" action="{{ $journal ? route('accounting-pages.journals.update', $journal->id) : route('accounting-pages.journals.store') }}" class="space-y-6" data-journal-form>
    @csrf
    @if($journal) @method('PUT') <input type="hidden" name="version" value="{{ old('version', $journal->version) }}"> @endif
    <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
        <label class="text-sm">تاريخ القيد<input type="date" name="entry_date" required value="{{ old('entry_date', $journal?->entry_date->format('Y-m-d') ?? now()->toDateString()) }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5">@error('entry_date')<span class="text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">العملة<input name="currency" readonly value="{{ config('accounting.currency') }}" class="mt-1 block w-full rounded-lg border border-slate-300 bg-slate-50 p-2.5" dir="ltr">@error('currency')<span class="text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">المرجع<input name="reference" maxlength="100" value="{{ old('reference', $journal?->reference) }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5">@error('reference')<span class="text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">الوصف<textarea name="description" class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5">{{ old('description', $journal?->description) }}</textarea>@error('description')<span class="text-red-700">{{ $message }}</span>@enderror</label>
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap justify-between gap-3"><div><h3 class="font-bold">سطور القيد</h3><p class="mt-1 text-sm text-slate-500">يمكن حفظ مسودة فارغة أو غير متوازنة. لكل سطر جانب موجب واحد. لا تعرض هذه الصفحة إجماليات محلية؛ الخادم يتحقق من المبالغ بدقة.</p></div><button type="button" data-add-line class="rounded-xl border border-indigo-200 px-4 py-2 text-indigo-700">إضافة سطر</button></div>
        @if($accounts->where('is_active', true)->isEmpty())<p class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">لا توجد حسابات نشطة لإضافة سطور. يمكنك حفظ مسودة فارغة أو إضافة حساب نشط إلى الدليل.</p>@endif
        <noscript><p class="mb-4 text-sm text-amber-800">تحتاج إضافة السطور وإزالتها إلى JavaScript. يبقى حفظ المسودة والسطور الحالية متاحاً.</p></noscript>
        <div data-lines class="space-y-4">@foreach($rows as $index => $line) @include('accounting.line', ['index' => $index, 'line' => $line]) @endforeach</div>
        <p data-empty-lines class="py-6 text-center text-slate-500" @if(count($rows)) hidden @endif>لا توجد سطور في المسودة.</p>
        @error('lines')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
    </section>
    <div class="flex gap-3"><button class="rounded-xl bg-indigo-600 px-5 py-3 text-white">حفظ المسودة</button><a href="{{ $journal ? route('accounting-pages.journals.show', $journal->id) : route('accounting-pages.journals.index') }}" class="rounded-xl border border-slate-300 px-5 py-3">إلغاء</a></div>
    <template data-line-template>@include('accounting.line', ['index' => '__INDEX__', 'line' => []])</template>
</form>
@endsection
