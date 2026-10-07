@extends('layout')
@section('title', 'الأرصدة الافتتاحية')
@section('page_title', 'الأرصدة الافتتاحية')
@section('breadcrumb', 'المحاسبة / الأرصدة الافتتاحية')
@section('content')
<div class="space-y-6">
    <p class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-800">احفظ الأرصدة الافتتاحية المتوازنة كمسودة، ثم رحّلها إلى قيد يومية جديد. بعد الترحيل تصبح للقراءة فقط.</p>
    <form method="POST" action="{{ route('accounting-pages.opening-balances.store') }}" class="space-y-5" data-journal-form data-opening-balance-form>
        @csrf
        <h3 class="text-lg font-bold">مسودة أرصدة افتتاحية جديدة</h3>
        @include('accounting.opening-balance-fields', ['batch' => null])
        <button class="rounded-xl bg-indigo-600 px-5 py-3 text-white">حفظ المسودة</button>
    </form>
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[600px] text-right text-sm">
            <thead class="bg-slate-50"><tr><th class="p-4">الدفعة</th><th class="p-4">تاريخ الافتتاح</th><th class="p-4">العملة</th><th class="p-4">الحالة</th><th class="p-4">التفاصيل</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($batches as $batch)
                    <tr><td class="p-4">#{{ $batch->id }}</td><td class="p-4" dir="ltr">{{ $batch->opening_date->format('Y-m-d') }}</td><td class="p-4">{{ $batch->currency }}</td><td class="p-4"><span class="rounded-full px-2 py-1 {{ $batch->isPosted() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $batch->isPosted() ? 'مرحل' : 'مسودة' }}</span></td><td class="p-4"><a class="text-indigo-700 underline" href="{{ route('accounting-pages.opening-balances.show', $batch->id) }}">عرض الدفعة</a></td></tr>
                @empty <tr><td colspan="5" class="p-8 text-center text-slate-500">لا توجد أرصدة افتتاحية محفوظة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
