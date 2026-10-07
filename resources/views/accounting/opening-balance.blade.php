@extends('layout')
@section('title', 'تفاصيل الأرصدة الافتتاحية')
@section('page_title', 'الأرصدة الافتتاحية #'.$batch->id)
@section('breadcrumb', 'المحاسبة / الأرصدة الافتتاحية / التفاصيل')
@section('content')
<div class="space-y-6">
    <a href="{{ route('accounting-pages.opening-balances.index') }}" class="text-sm text-indigo-700 underline">جميع الأرصدة الافتتاحية</a>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap justify-between gap-3"><span class="rounded-full px-3 py-1 {{ $batch->isPosted() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">الحالة: {{ $batch->isPosted() ? 'مرحل — للقراءة فقط' : 'مسودة' }}</span></div>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">تاريخ الافتتاح</dt><dd dir="ltr" class="mt-1 text-right font-semibold">{{ $batch->opening_date->format('Y-m-d') }}</dd></div>
            <div><dt class="text-slate-500">العملة</dt><dd class="mt-1 font-semibold">{{ $batch->currency }}</dd></div>
            @if($batch->isPosted())<div><dt class="text-slate-500">وقت الترحيل</dt><dd dir="ltr" class="mt-1 text-right">{{ $batch->posted_at?->format('Y-m-d H:i:s.u') }}</dd></div>@endif
        </dl>
        @if($batch->isPosted())
            <p class="mt-4 rounded-xl bg-indigo-50 p-3 text-sm text-indigo-800">القيد المرتبط: #{{ $batch->journal_entry_id }}
                @if($batch->journalEntry)<a class="font-semibold underline" href="{{ route('accounting-pages.journals.show', $batch->journalEntry->id) }}">{{ $batch->journalEntry->reference }} — عرض القيد</a>@endif
            </p>
        @endif
    </section>
    @if($batch->isDraft())
        <form method="POST" action="{{ route('accounting-pages.opening-balances.update', $batch->id) }}" class="space-y-5" data-journal-form data-opening-balance-form>
            @csrf @method('PUT')
            <h3 class="text-lg font-bold">تعديل المسودة</h3>
            @include('accounting.opening-balance-fields')
            <button class="rounded-xl bg-indigo-600 px-5 py-3 text-white">حفظ المسودة</button>
        </form>
        <div class="flex flex-wrap gap-3">
            <form method="POST" action="{{ route('accounting-pages.opening-balances.post', $batch->id) }}" onsubmit="return confirm('ترحيل الأرصدة الافتتاحية؟ سيُنشأ قيد يومية مرحل، ولن يمكن تعديل الدفعة أو حذفها بعد ذلك.')">@csrf<button class="rounded-xl bg-emerald-600 px-4 py-2 text-white">ترحيل الأرصدة الافتتاحية</button></form>
            <form method="POST" action="{{ route('accounting-pages.opening-balances.destroy', $batch->id) }}" onsubmit="return confirm('حذف مسودة الأرصدة الافتتاحية وسطورها؟')">@csrf @method('DELETE')<button class="rounded-xl bg-red-50 px-4 py-2 text-red-700">حذف المسودة</button></form>
        </div>
    @else
        <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="w-full min-w-[600px] text-right text-sm">
                <thead class="bg-slate-50"><tr><th class="p-4">الحساب</th><th class="p-4">مدين</th><th class="p-4">دائن</th></tr></thead>
                <tbody class="divide-y divide-slate-100">@foreach($batch->lines as $line)<tr><td class="p-4">{{ $line->chartAccount?->code }} — {{ $line->chartAccount?->name }}</td><td class="p-4 font-mono" dir="ltr">{{ $line->debit }}</td><td class="p-4 font-mono" dir="ltr">{{ $line->credit }}</td></tr>@endforeach</tbody>
            </table>
        </section>
    @endif
</div>
@endsection
