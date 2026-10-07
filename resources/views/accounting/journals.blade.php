@extends('layout')
@section('title', 'القيود اليومية')
@section('page_title', 'القيود اليومية')
@section('breadcrumb', 'المحاسبة / القيود اليومية')
@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3"><p class="text-sm text-slate-500">المسودات قابلة للتعديل؛ القيود المرحلة ثابتة.</p><a href="{{ route('accounting-pages.journals.create') }}" class="rounded-xl bg-indigo-600 px-5 py-3 text-white">مسودة جديدة</a></div>
<div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
    <table class="w-full min-w-[650px] text-right text-sm"><thead class="bg-slate-50"><tr>@foreach(['التاريخ', 'المرجع', 'الوصف', 'الحالة', 'الإصدار', 'التفاصيل'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead>
        <tbody class="divide-y divide-slate-100">@forelse($journals as $journal)<tr>
            <td class="p-4" dir="ltr">{{ $journal->entry_date->format('Y-m-d') }}</td><td class="p-4">{{ $journal->reference ?? '—' }}</td><td class="max-w-xs break-words p-4">{{ $journal->description ?? '—' }}</td>
            <td class="p-4"><span class="rounded-full px-3 py-1 {{ $journal->isPosted() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $journal->isPosted() ? 'مرحل' : 'مسودة' }}</span>@if($journal->reversal_of_id !== null)<span class="mr-2 text-xs text-indigo-700">قيد عكسي</span>@elseif($journal->reversal)<span class="mr-2 text-xs text-amber-800">معكوس</span>@endif</td>
            <td class="p-4">{{ $journal->version }}</td><td class="p-4"><a href="{{ route('accounting-pages.journals.show', $journal->id) }}" class="text-indigo-700">عرض القيد</a></td>
        </tr>@empty<tr><td colspan="6" class="p-10 text-center text-slate-500">لا توجد قيود يومية. يمكنك إنشاء مسودة فارغة أو غير متوازنة.</td></tr>@endforelse</tbody>
    </table>
</div>
@endsection
