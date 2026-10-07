@extends('layout')
@section('title', 'قائمة الدخل')
@section('page_title', 'قائمة الدخل')
@section('breadcrumb', 'المحاسبة / قائمة الدخل')
@section('content')
<div class="space-y-6">
    <form method="GET" action="{{ route('accounting-pages.income-statement') }}" class="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-5">
        <label class="text-sm">من تاريخ<input type="date" name="date_from" value="{{ old('date_from', $dates['date_from']) }}" required class="mt-1 block rounded-lg border border-slate-300 p-2.5">@error('date_from')<span class="block text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">إلى تاريخ<input type="date" name="date_to" value="{{ old('date_to', $dates['date_to']) }}" required class="mt-1 block rounded-lg border border-slate-300 p-2.5">@error('date_to')<span class="block text-red-700">{{ $message }}</span>@enderror</label>
        <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-white">عرض قائمة الدخل</button>
    </form>

    @if($conflict)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-800">{{ $conflict }}</div>
    @endif

    @if($report)
        <p class="text-sm text-slate-600">العملة: <strong>{{ $report['currency'] }}</strong> — تشمل القائمة القيود المرحلة فقط.</p>
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach(['revenue_accounts' => 'الإيرادات', 'expense_accounts' => 'المصروفات'] as $section => $title)
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-label="{{ $title }}">
                    <h2 class="border-b border-slate-200 bg-slate-50 p-4 font-bold">{{ $title }}</h2>
                    <table class="w-full text-right text-sm">
                        <thead><tr><th class="p-4">الحساب</th><th class="p-4">المبلغ</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($report[$section] as $account)
                                <tr><td class="p-4"><span class="font-mono" dir="ltr">{{ $account['code'] }}</span> — {{ $account['name'] }}</td><td dir="ltr" class="p-4 text-right font-mono {{ str_starts_with($account['amount'], '-') ? 'text-red-700' : '' }}">{{ $account['amount'] }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="p-4 text-center text-slate-500">لا توجد حركة مرحلة خلال الفترة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach(['total_revenue' => 'إجمالي الإيرادات', 'total_expense' => 'إجمالي المصروفات', 'net_profit_loss' => 'صافي الربح / الخسارة'] as $key => $label)
                @php($amount = $report['totals'][$key])
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p dir="ltr" class="mt-2 text-right font-mono text-xl {{ str_starts_with($amount, '-') ? 'text-red-700' : 'text-emerald-700' }}">{{ $amount }}</p>
                    @if($key === 'net_profit_loss')<p class="mt-1 text-sm">{{ str_starts_with($amount, '-') ? 'خسارة' : ($amount === '0.00' ? 'تعادل' : 'ربح') }}</p>@endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
