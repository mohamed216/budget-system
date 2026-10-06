@extends('layout')
@section('title', 'الأستاذ العام')
@section('page_title', 'الأستاذ العام')
@section('breadcrumb', 'المحاسبة / الأستاذ العام')
@section('content')
@php($types = ['asset' => 'أصول', 'liability' => 'التزامات', 'equity' => 'حقوق ملكية', 'revenue' => 'إيرادات', 'expense' => 'مصروفات'])
<div class="space-y-6">
    <form method="GET" action="{{ route('accounting-pages.ledger') }}" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-sm">الحساب<select name="chart_account_id" required class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5"><option value="">اختر الحساب</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('chart_account_id', request('chart_account_id')) === (string)$account->id)>{{ $account->code }} — {{ $account->name }}{{ $account->is_active ? '' : ' (غير نشط)' }}</option>@endforeach</select>@error('chart_account_id')<span class="text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">من تاريخ<input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5">@error('date_from')<span class="text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">إلى تاريخ<input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5">@error('date_to')<span class="text-red-700">{{ $message }}</span>@enderror</label>
        <button class="self-end rounded-xl bg-indigo-600 px-5 py-2.5 text-white">عرض الأستاذ</button>
    </form>
    @if($report)
        <h3 class="font-bold">{{ $report['account']['code'] }} — {{ $report['account']['name'] }} <span class="text-sm font-normal text-slate-500">{{ $types[$report['account']['type']] }} / {{ config('accounting.currency') }}</span></h3>
        <p class="text-sm text-slate-500">الرصيد الموقّع = المدين − الدائن. يشمل التقرير القيود المرحلة فقط.</p>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach(['opening_balance' => 'الرصيد الافتتاحي', 'total_debit' => 'إجمالي المدين', 'total_credit' => 'إجمالي الدائن', 'net_movement' => 'صافي الحركة', 'closing_balance' => 'الرصيد الختامي'] as $key => $label)
                @php($value = $key === 'opening_balance' ? $report[$key] : $report['period'][$key])
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs text-slate-500">{{ $label }}</p><p dir="ltr" class="mt-2 text-right font-mono text-lg {{ str_starts_with($value, '-') ? 'text-red-700' : 'text-emerald-700' }}">{{ $value }}</p></div>
            @endforeach
        </div>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[900px] text-right text-sm"><thead class="bg-slate-50"><tr>@foreach(['التاريخ', 'مرجع القيد', 'وصف القيد', 'وصف السطر', 'مدين', 'دائن', 'الرصيد الجاري'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100">@forelse($report['movements'] as $movement)<tr><td class="p-4" dir="ltr">{{ $movement['entry_date'] }}</td><td class="p-4"><a class="text-indigo-700" href="{{ route('accounting-pages.journals.show', $movement['journal_entry_id']) }}">{{ $movement['reference'] ?? '#'.$movement['journal_entry_id'] }}</a></td><td class="max-w-xs break-words p-4">{{ $movement['journal_description'] ?? '—' }}</td><td class="max-w-xs break-words p-4">{{ $movement['line_description'] ?? '—' }}</td>@foreach(['debit', 'credit', 'running_balance'] as $key)<td dir="ltr" class="p-4 font-mono {{ str_starts_with($movement[$key], '-') ? 'text-red-700' : 'text-slate-800' }}">{{ $movement[$key] }}</td>@endforeach</tr>@empty<tr><td colspan="7" class="p-10 text-center text-slate-500">لا توجد حركات مرحلة خلال الفترة.</td></tr>@endforelse</tbody>
        </table></div>
    @else <p class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500">{{ $accounts->isEmpty() ? 'لا توجد حسابات محاسبية لعرض الأستاذ.' : 'اختر حساباً لعرض حركاته المرحلة.' }}</p> @endif
</div>
@endsection
