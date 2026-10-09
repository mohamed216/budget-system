@php($allocation = is_array($allocation) ? $allocation : [])
<div data-allocation class="grid items-end gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach(['debit_line_index' => 'السطر المدين', 'credit_line_index' => 'السطر الدائن'] as $field => $label)
        <label class="text-sm">{{ $label }}<select data-allocation-field="{{ $field }}" name="allocations[{{ $index }}][{{ $field }}]" required class="mt-1 block w-full rounded-lg border border-slate-300 p-2">
            <option value="">اختر السطر</option>
            @foreach(array_values($rows) as $lineIndex => $unused)
                <option value="{{ $lineIndex }}" @selected((string)($allocation[$field] ?? '') === (string)$lineIndex)>سطر {{ $lineIndex + 1 }}</option>
            @endforeach
        </select>@error('allocations.'.$index.'.'.$field)<span class="text-red-700">{{ $message }}</span>@enderror</label>
    @endforeach
    <label class="text-sm">المبلغ<input data-allocation-field="amount" type="text" inputmode="decimal" dir="ltr" name="allocations[{{ $index }}][amount]" required value="{{ $allocation['amount'] ?? '' }}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2">@error('allocations.'.$index.'.amount')<span class="text-red-700">{{ $message }}</span>@enderror</label>
    <div class="flex items-end gap-2"><label class="grow text-sm">النشاط<select data-allocation-field="category" name="allocations[{{ $index }}][category]" class="mt-1 block w-full rounded-lg border border-slate-300 p-2">
        <option value="" @selected(($allocation['category'] ?? null) === null)>تحويل نقدي داخلي</option>
        <option value="operating" @selected(($allocation['category'] ?? null) === 'operating')>تشغيلي</option>
        <option value="investing" @selected(($allocation['category'] ?? null) === 'investing')>استثماري</option>
        <option value="financing" @selected(($allocation['category'] ?? null) === 'financing')>تمويلي</option>
    </select>@error('allocations.'.$index.'.category')<span class="text-red-700">{{ $message }}</span>@enderror</label><button type="button" data-remove-allocation class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">إزالة</button></div>
</div>
