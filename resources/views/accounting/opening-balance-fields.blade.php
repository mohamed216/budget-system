@php
    $rows = old('lines', $batch?->lines->map(fn ($line) => $line->only(['chart_account_id', 'debit', 'credit']))->all() ?? [[], []]);
    $rows = is_array($rows) ? array_filter($rows, 'is_array') : [];
@endphp
<section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
    <label class="text-sm">تاريخ الافتتاح
        <input type="date" name="opening_date" required value="{{ old('opening_date', $batch?->opening_date?->format('Y-m-d') ?? now()->toDateString()) }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2.5">
        @error('opening_date') <span class="text-red-700">{{ $message }}</span> @enderror
    </label>
    <label class="text-sm">العملة
        <input type="text" name="currency" readonly value="{{ old('currency', $batch?->currency ?? config('accounting.currency')) }}" dir="ltr" class="mt-1 block w-full rounded-lg border border-slate-300 bg-slate-50 p-2.5">
        @error('currency') <span class="text-red-700">{{ $message }}</span> @enderror
    </label>
</section>
<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h3 class="font-bold">سطور الأرصدة الافتتاحية</h3><p class="mt-1 text-sm text-slate-500">أدخل سطرين على الأقل. يجب أن يتساوى مجموع المدين والدائن؛ لا تتم الموازنة تلقائياً.</p></div>
        <button type="button" data-add-line class="rounded-xl border border-indigo-200 px-4 py-2 text-indigo-700">إضافة سطر</button>
    </div>
    @if($accounts->where('is_active', true)->count() < 2)<p class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">تحتاج إلى حسابين نشطين مختلفين على الأقل لإنشاء أرصدة افتتاحية.</p>@endif
    <noscript><p class="mb-4 text-sm text-amber-800">تتطلب إضافة السطور وإزالتها JavaScript. يمكنك إدخال السطرين المعروضين بدونها.</p></noscript>
    <div data-lines class="space-y-4">@foreach($rows as $index => $line) @include('accounting.opening-balance-line', ['index' => $index, 'line' => $line]) @endforeach</div>
    <p data-empty-lines class="py-6 text-center text-slate-500" @if(count($rows)) hidden @endif>لا توجد سطور؛ أضف سطرين على الأقل.</p>
    @error('lines') <p class="mt-3 text-sm text-red-700">{{ $message }}</p> @enderror
    <template data-line-template>@include('accounting.opening-balance-line', ['index' => '__INDEX__', 'line' => []])</template>
</section>
<script>
document.querySelectorAll('[data-opening-balance-form]').forEach((form) => {
    form.addEventListener('change', (event) => {
        const selected = event.target;
        if (!selected.matches('select[data-field="chart_account_id"]')) return;
        selected.setCustomValidity('');
        if (selected.value && [...form.querySelectorAll('select[data-field="chart_account_id"]')]
            .some((other) => other !== selected && other.value === selected.value)) {
            selected.value = '';
            selected.setCustomValidity('الحساب مكرر داخل الدفعة. اختر حساباً آخر.');
            selected.reportValidity();
        }
    });
});
</script>
