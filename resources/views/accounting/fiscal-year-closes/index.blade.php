@extends('layout')
@section('title', 'إقفال السنة المالية')
@section('page_title', 'إقفال السنة المالية')
@section('breadcrumb', 'المحاسبة / إقفال السنة المالية')
@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">سجل السنوات المالية المقفلة نهائياً. لا يمكن تعديل الإقفال أو إعادة فتحه.</p>
        <a href="{{ route('accounting-pages.fiscal-year-closes.create') }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">إقفال سنة مالية</a>
    </div>
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[760px] text-right text-sm">
            <thead class="bg-slate-50"><tr><th class="p-4">الفترة</th><th class="p-4">العملة</th><th class="p-4">حساب الأرباح المحتجزة</th><th class="p-4">تاريخ الإقفال</th><th class="p-4">القيد الختامي</th><th class="p-4">التفاصيل</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($closes as $close)
                    <tr>
                        <td class="p-4" dir="ltr">{{ $close->start_date->toDateString() }} → {{ $close->end_date->toDateString() }}</td>
                        <td class="p-4">{{ $close->currency }}</td>
                        <td class="p-4">{{ $close->retainedEarningsAccount?->code }} — {{ $close->retainedEarningsAccount?->name }}</td>
                        <td class="p-4" dir="ltr">{{ $close->closed_at->format('Y-m-d H:i:s.u') }}</td>
                        <td class="p-4">
                            @if($close->journalEntry)
                                <a class="text-indigo-700 underline" href="{{ route('accounting-pages.journals.show', $close->journalEntry->id) }}">#{{ $close->journalEntry->id }} — {{ $close->journalEntry->reference }}</a>
                            @else
                                <span class="text-slate-600">لم يلزم إنشاء قيد ختامي لأن أرصدة الإيرادات والمصروفات المطلوب إقفالها كانت صفراً.</span>
                            @endif
                        </td>
                        <td class="p-4"><a class="text-indigo-700 underline" href="{{ route('accounting-pages.fiscal-year-closes.show', $close->id) }}">عرض الإقفال</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-slate-500">لا توجد سنوات مالية مقفلة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
