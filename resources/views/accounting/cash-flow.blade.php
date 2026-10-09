@extends('layout')
@section('title', 'قائمة التدفقات النقدية')
@section('page_title', 'قائمة التدفقات النقدية')
@section('breadcrumb', 'المحاسبة / قائمة التدفقات النقدية')
@section('content')
<div class="space-y-6">
    <form method="GET" action="{{ route('accounting-pages.cash-flow') }}" class="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-5">
        <label class="text-sm">من تاريخ<input type="date" name="start_date" value="{{ old('start_date', $dates['start_date']) }}" required class="mt-1 block rounded-lg border border-slate-300 p-2.5">@error('start_date')<span class="block text-red-700">{{ $message }}</span>@enderror</label>
        <label class="text-sm">إلى تاريخ<input type="date" name="end_date" value="{{ old('end_date', $dates['end_date']) }}" required class="mt-1 block rounded-lg border border-slate-300 p-2.5">@error('end_date')<span class="block text-red-700">{{ $message }}</span>@enderror</label>
        <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-white">عرض قائمة التدفقات النقدية</button>
    </form>

    @if($conflict)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-800">{{ $conflict }}</div>
    @endif

    @if($report)
        <p class="text-sm text-slate-600">العملة: <strong>{{ $report['currency'] }}</strong> — تشمل القائمة القيود المرحلة فقط.</p>
        @unless($report['has_designated_cash_accounts'])
            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sky-900">لم تُحدَّد حسابات نقدية أو ما يعادلها بعد. جميع القيم النقدية في هذه القائمة صفر.</div>
        @endunless
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach([
                'beginning_cash' => 'الرصيد النقدي الافتتاحي',
                'opening_balance_adjustments' => 'تعديلات الأرصدة الافتتاحية',
                'operating_activities' => 'الأنشطة التشغيلية',
                'investing_activities' => 'الأنشطة الاستثمارية',
                'financing_activities' => 'الأنشطة التمويلية',
                'net_cash_flow' => 'صافي التدفق النقدي',
                'net_change_in_cash' => 'صافي التغير في النقد',
                'ending_cash' => 'الرصيد النقدي الختامي',
            ] as $field => $label)
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <dt class="text-sm text-slate-500">{{ $label }}</dt>
                    <dd dir="ltr" class="mt-2 text-right font-mono text-xl {{ str_starts_with($report[$field], '-') ? 'text-red-700' : 'text-slate-800' }}">{{ $report[$field] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
@endsection
