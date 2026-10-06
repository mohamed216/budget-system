@extends('layout')
@section('title', 'ميزان المراجعة')
@section('page_title', 'ميزان المراجعة')
@section('breadcrumb', 'المحاسبة / ميزان المراجعة')
@section('content')
@php($types = ['asset' => 'أصول', 'liability' => 'التزامات', 'equity' => 'حقوق ملكية', 'revenue' => 'إيرادات', 'expense' => 'مصروفات'])
<div class="space-y-6">
    <form method="GET" action="{{ route('accounting-pages.trial') }}" class="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-5">
        <label class="text-sm">حتى تاريخ<input type="date" name="as_of" value="{{ old('as_of', request('as_of')) }}" class="mt-1 block rounded-lg border border-slate-300 p-2.5">@error('as_of')<span class="text-red-700">{{ $message }}</span>@enderror</label><button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-white">عرض الميزان</button>
        <p class="text-sm text-slate-500">{{ config('accounting.currency') }} — القيود المرحلة فقط، بما فيها الحسابات غير النشطة والحسابات دون حركة.</p>
    </form>
    @if($conflict)<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-800"><p class="mb-2 font-bold">تعذر عرض ميزان المراجعة: خلل في توازن الأستاذ</p><p>{{ $conflict }}</p></div>@endif
    @if($report)
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[1000px] text-right text-sm"><thead class="bg-slate-50"><tr>@foreach(['الرمز', 'الاسم', 'النوع', 'إجمالي المدين', 'إجمالي الدائن', 'الصافي الموقّع', 'الرصيد المدين', 'الرصيد الدائن'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100">@forelse($report['accounts'] as $account)<tr><td class="p-4 font-mono" dir="ltr">{{ $account['code'] }}</td><td class="p-4">{{ $account['name'] }}</td><td class="p-4">{{ $types[$account['type']] }}</td>@foreach(['debit_total', 'credit_total', 'signed_net', 'debit_balance', 'credit_balance'] as $key)<td dir="ltr" class="p-4 font-mono {{ str_starts_with($account[$key], '-') ? 'text-red-700' : 'text-slate-800' }}">{{ $account[$key] }}</td>@endforeach</tr>@empty<tr><td colspan="8" class="p-10 text-center text-slate-500">لا توجد حسابات محاسبية لعرض ميزان المراجعة.</td></tr>@endforelse</tbody>
            <tfoot class="border-t border-slate-200 bg-slate-50 font-bold"><tr><th colspan="3" class="p-4">الإجماليات</th><td dir="ltr" class="p-4 font-mono">{{ $report['totals']['total_debits'] }}</td><td dir="ltr" class="p-4 font-mono">{{ $report['totals']['total_credits'] }}</td><td class="p-4">—</td><td dir="ltr" class="p-4 font-mono">{{ $report['totals']['total_debit_balances'] }}</td><td dir="ltr" class="p-4 font-mono">{{ $report['totals']['total_credit_balances'] }}</td></tr></tfoot>
        </table></div>
    @endif
</div>
@endsection
