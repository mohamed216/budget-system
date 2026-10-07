@php($line = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $line))
<div data-line class="grid items-end gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="text-sm">الحساب
        <select data-field="chart_account_id" name="lines[{{ $index }}][chart_account_id]" required class="mt-1 block w-full rounded-lg border border-slate-300 p-2">
            <option value="">اختر الحساب</option>
            @foreach($accounts as $account)
                <option value="{{ $account->id }}" @selected((string) ($line['chart_account_id'] ?? '') === (string) $account->id) @disabled(!$account->is_active)>{{ $account->code }} — {{ $account->name }}{{ $account->is_active ? '' : ' (غير نشط)' }}</option>
            @endforeach
        </select>
        @error('lines.'.$index.'.chart_account_id') <span class="text-red-700">{{ $message }}</span> @enderror
    </label>
    @foreach(['debit' => 'مدين', 'credit' => 'دائن'] as $field => $label)
        <label class="text-sm">{{ $label }}
            <input data-field="{{ $field }}" type="text" inputmode="decimal" dir="ltr" name="lines[{{ $index }}][{{ $field }}]" required value="{{ $line[$field] ?? '0.00' }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2">
            @error('lines.'.$index.'.'.$field) <span class="text-red-700">{{ $message }}</span> @enderror
        </label>
    @endforeach
    <button type="button" data-remove-line class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">إزالة السطر</button>
</div>
