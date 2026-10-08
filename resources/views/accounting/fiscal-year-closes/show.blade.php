@extends('layout')
@section('title', 'تفاصيل إقفال السنة المالية')
@section('page_title', 'إقفال السنة المالية #'.$close->id)
@section('breadcrumb', 'المحاسبة / إقفال السنة المالية / التفاصيل')
@section('content')
<div class="space-y-6">
    <a href="{{ route('accounting-pages.fiscal-year-closes.index') }}" class="text-sm text-indigo-700 underline">جميع السنوات المقفلة</a>
    <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">هذه السنة المالية مقفلة نهائياً وللقراءة فقط. لا يمكن تعديلها أو حذفها أو إعادة فتحها في هذه المرحلة.</p>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">الفترة</dt><dd class="mt-1 font-semibold" dir="ltr">{{ $close->start_date->toDateString() }} → {{ $close->end_date->toDateString() }}</dd></div>
            <div><dt class="text-slate-500">العملة</dt><dd class="mt-1 font-semibold">{{ $close->currency }}</dd></div>
            <div><dt class="text-slate-500">حساب الأرباح المحتجزة</dt><dd class="mt-1 font-semibold">{{ $close->retainedEarningsAccount?->code }} — {{ $close->retainedEarningsAccount?->name }}</dd></div>
            <div><dt class="text-slate-500">تاريخ الإقفال</dt><dd class="mt-1 font-semibold" dir="ltr">{{ $close->closed_at->format('Y-m-d H:i:s.u') }}</dd></div>
        </dl>
        <div class="mt-5 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-800">
            @if($close->journalEntry)
                القيد الختامي: <a class="font-semibold underline" href="{{ route('accounting-pages.journals.show', $close->journalEntry->id) }}">#{{ $close->journalEntry->id }} — {{ $close->journalEntry->reference }} — عرض القيد</a>
            @else
                لم يلزم إنشاء قيد ختامي لأن أرصدة الإيرادات والمصروفات المطلوب إقفالها كانت صفراً.
            @endif
        </div>
    </section>
</div>
@endsection
