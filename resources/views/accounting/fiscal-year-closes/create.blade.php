@extends('layout')
@section('title', 'إقفال سنة مالية')
@section('page_title', 'إقفال سنة مالية')
@section('breadcrumb', 'المحاسبة / إقفال السنة المالية / جديد')
@section('content')
<div class="space-y-6">
    <a href="{{ route('accounting-pages.fiscal-year-closes.index') }}" class="text-sm text-indigo-700 underline">جميع السنوات المقفلة</a>
    <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">إقفال السنة المالية نهائي ولا يمكن إعادة فتحها في هذه المرحلة. ستُمنع كتابة قيود أو أرصدة افتتاحية في الفترة المقفلة. عالج المسودات داخل الفترة قبل الإقفال.</p>
    <form method="POST" action="{{ route('accounting-pages.fiscal-year-closes.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm">تاريخ البداية
                <input type="text" name="start_date" required inputmode="numeric" placeholder="YYYY-MM-DD" dir="ltr" value="{{ old('start_date') }}" class="w-full rounded-xl border border-slate-300 p-3 text-right">
            </label>
            <label class="space-y-1 text-sm">تاريخ النهاية
                <input type="text" name="end_date" required inputmode="numeric" placeholder="YYYY-MM-DD" dir="ltr" value="{{ old('end_date') }}" class="w-full rounded-xl border border-slate-300 p-3 text-right">
            </label>
            <label class="space-y-1 text-sm">العملة
                <input type="text" name="currency" required dir="ltr" value="{{ old('currency', config('accounting.currency')) }}" class="w-full rounded-xl border border-slate-300 p-3 text-right">
            </label>
            <label class="space-y-1 text-sm">حساب الأرباح المحتجزة
                <select name="retained_earnings_account_id" required class="w-full rounded-xl border border-slate-300 p-3">
                    <option value="">اختر حساباً نشطاً من حقوق الملكية</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('retained_earnings_account_id') === (string) $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <button type="submit" class="mt-5 rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white">إقفال السنة المالية نهائياً</button>
    </form>
</div>
@endsection
