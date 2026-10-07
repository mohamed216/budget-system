@extends('layout')
@section('title', 'قائمة المركز المالي')
@section('page_title', 'قائمة المركز المالي')
@section('breadcrumb', 'المحاسبة / قائمة المركز المالي')
@section('content')
<div class="space-y-6">
    <form method="GET" action="{{ route('accounting-pages.balance-sheet') }}" class="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-5">
        <label class="text-sm">كما في تاريخ<input type="date" name="as_of" value="{{ old('as_of', $asOf) }}" required class="mt-1 block rounded-lg border border-slate-300 p-2.5">@error('as_of')<span class="block text-red-700">{{ $message }}</span>@enderror</label>
        <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-white">عرض قائمة المركز المالي</button>
    </form>

    @if($conflict)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-800">{{ $conflict }}</div>
    @endif

    @if($report)
        <p class="text-sm text-slate-600">العملة: <strong>{{ $report['currency'] }}</strong> — تشمل القائمة القيود المرحلة حتى التاريخ المحدد.</p>
        <div class="grid gap-6 lg:grid-cols-3">
            @foreach(['assets' => 'الأصول', 'liabilities' => 'الالتزامات', 'equity' => 'حقوق الملكية'] as $section => $title)
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-label="{{ $title }}">
                    <h2 class="border-b border-slate-200 bg-slate-50 p-4 font-bold">{{ $title }}</h2>
                    <table class="w-full text-right text-sm">
                        <thead><tr><th class="p-4">الحساب</th><th class="p-4">الرصيد</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($report[$section] as $account)
                                <tr><td class="p-4"><span class="font-mono" dir="ltr">{{ $account['code'] }}</span> — {{ $account['name'] }}</td><td dir="ltr" class="p-4 text-right font-mono {{ str_starts_with($account['amount'], '-') ? 'text-red-700' : '' }}">{{ $account['amount'] }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="p-4 text-center text-slate-500">لا توجد حسابات في هذا القسم.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach(['assets' => 'إجمالي الأصول', 'liabilities' => 'إجمالي الالتزامات', 'equity' => 'إجمالي حقوق الملكية', 'unclosed_cumulative_profit_loss' => 'الربح / الخسارة التراكمية غير المقفلة', 'liabilities_equity_and_unclosed_profit_loss' => 'الالتزامات + حقوق الملكية + الربح/الخسارة غير المقفلة', 'equation_difference' => 'فرق المعادلة'] as $key => $label)
                @php($amount = $report['totals'][$key])
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p dir="ltr" class="mt-2 text-right font-mono text-xl {{ str_starts_with($amount, '-') ? 'text-red-700' : 'text-slate-800' }}">{{ $amount }}</p></div>
            @endforeach
        </div>
    @endif
</div>
@endsection
