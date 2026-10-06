@extends('layout')
@section('title', 'تفاصيل القيد')
@section('page_title', 'تفاصيل القيد #'.$journal->id)
@section('breadcrumb', 'المحاسبة / القيود اليومية / التفاصيل')
@section('content')
<div class="space-y-6">
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><span class="rounded-full px-3 py-1 {{ $journal->isPosted() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $journal->isPosted() ? 'مرحل — للقراءة فقط' : 'مسودة' }}</span><a href="{{ route('accounting-pages.journals.index') }}" class="text-sm text-indigo-700">جميع القيود</a></div>
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">التاريخ</dt><dd dir="ltr" class="mt-1 text-right font-semibold">{{ $journal->entry_date->format('Y-m-d') }}</dd></div>
            <div><dt class="text-slate-500">المرجع</dt><dd class="mt-1 break-words font-semibold">{{ $journal->reference ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">العملة</dt><dd class="mt-1 font-semibold">{{ $journal->currency }}</dd></div>
            <div><dt class="text-slate-500">الإصدار</dt><dd class="mt-1 font-semibold">{{ $journal->version }}</dd></div>
            @if($journal->isPosted())<div><dt class="text-slate-500">وقت الترحيل</dt><dd dir="ltr" class="mt-1 text-right">{{ $journal->posted_at->format('Y-m-d H:i:s.u') }}</dd></div>@endif
        </dl><p class="mt-4 whitespace-pre-wrap break-words text-sm">{{ $journal->description ?? '—' }}</p>
        @if($journal->isDraft())<div class="mt-5 flex flex-wrap gap-3">
            <a href="{{ route('accounting-pages.journals.edit', $journal->id) }}" class="rounded-xl border border-indigo-200 px-4 py-2 text-indigo-700">تعديل المسودة</a>
            <form method="POST" action="{{ route('accounting-pages.journals.post', $journal->id) }}" onsubmit="return confirm('ترحيل القيد؟ بعد الترحيل لا يمكن تعديله أو حذفه.')">@csrf<button class="rounded-xl bg-emerald-600 px-4 py-2 text-white">ترحيل القيد</button></form>
            <form method="POST" action="{{ route('accounting-pages.journals.destroy', $journal->id) }}" onsubmit="return confirm('حذف المسودة وسطورها؟')">@csrf @method('DELETE')<button class="rounded-xl bg-red-50 px-4 py-2 text-red-700">حذف المسودة</button></form>
        </div>@endif
    </section>
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[600px] text-right text-sm">
        <thead class="bg-slate-50"><tr>@foreach(['السطر', 'الحساب', 'مدين', 'دائن', 'الوصف'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead>
        <tbody class="divide-y divide-slate-100">@forelse($journal->lines as $line)<tr><td class="p-4">{{ $line->line_number }}</td><td class="p-4">{{ $line->chartAccount->code }} — {{ $line->chartAccount->name }}</td><td class="p-4 font-mono text-emerald-700" dir="ltr">{{ $line->debit }}</td><td class="p-4 font-mono text-red-700" dir="ltr">{{ $line->credit }}</td><td class="break-words p-4">{{ $line->description ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="p-10 text-center text-slate-500">لا توجد سطور في المسودة.</td></tr>@endforelse</tbody>
    </table></div>
</div>
@endsection
