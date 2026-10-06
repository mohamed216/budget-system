@extends('layout')
@section('title', 'دليل الحسابات')
@section('page_title', 'دليل الحسابات المحاسبي')
@section('breadcrumb', 'المحاسبة / دليل الحسابات')
@section('content')
@php($types = ['asset' => 'أصول', 'liability' => 'التزامات', 'equity' => 'حقوق ملكية', 'revenue' => 'إيرادات', 'expense' => 'مصروفات'])
<div class="space-y-6">
    <p class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-800">هذا الدليل مستقل عن حسابات النقد والبنوك والمحافظ التشغيلية. الأرصدة المحاسبية مشتقة من القيود المرحلة.</p>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-4 text-lg font-bold">{{ $editing ? 'تعديل حساب' : 'إضافة حساب محاسبي' }}</h3>
        <form method="POST" action="{{ $editing ? route('accounting-pages.chart.update', $editing->id) : route('accounting-pages.chart.store') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @csrf
            @if($editing) @method('PUT') @endif
            <label class="space-y-1 text-sm">الرمز
                <input name="code" dir="ltr" required maxlength="32" value="{{ old('code', $editing?->code) }}" class="block w-full rounded-lg border border-slate-300 p-2.5">
                @error('code') <span class="text-red-700">{{ $message }}</span> @enderror
            </label>
            <label class="space-y-1 text-sm">الاسم
                <input name="name" required maxlength="255" value="{{ old('name', $editing?->name) }}" class="block w-full rounded-lg border border-slate-300 p-2.5">
                @error('name') <span class="text-red-700">{{ $message }}</span> @enderror
            </label>
            <label class="space-y-1 text-sm">النوع
                <select name="type" required class="block w-full rounded-lg border border-slate-300 p-2.5">
                    @foreach($types as $value => $label) <option value="{{ $value }}" @selected(old('type', $editing?->type ?? 'asset') === $value)>{{ $label }}</option> @endforeach
                </select>
                @error('type') <span class="text-red-700">{{ $message }}</span> @enderror
            </label>
            <label class="space-y-1 text-sm">الحساب الأب
                <select name="parent_id" class="block w-full rounded-lg border border-slate-300 p-2.5">
                    <option value="">بدون حساب أب</option>
                    @foreach($accounts as $account)
                        @if($account->id !== $editing?->id) <option value="{{ $account->id }}" @selected((string) old('parent_id', $editing?->parent_id) === (string) $account->id)>{{ $account->code }} — {{ $account->name }}</option> @endif
                    @endforeach
                </select>
                @error('parent_id') <span class="text-red-700">{{ $message }}</span> @enderror
            </label>
            <label class="space-y-1 text-sm">الحالة
                <select name="is_active" class="block w-full rounded-lg border border-slate-300 p-2.5">
                    <option value="1" @selected((string) old('is_active', $editing?->is_active ?? true) === '1')>نشط</option>
                    <option value="0" @selected(!old('is_active', $editing?->is_active ?? true))>غير نشط</option>
                </select>
                @error('is_active') <span class="text-red-700">{{ $message }}</span> @enderror
            </label>
            <div class="flex items-end gap-3">
                <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-white hover:bg-indigo-700">حفظ الحساب</button>
                @if($editing) <a href="{{ route('accounting-pages.chart.index') }}" class="text-sm text-slate-600">إلغاء</a> @endif
            </div>
        </form>
    </section>
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[680px] text-right text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr>@foreach(['الرمز', 'الاسم', 'النوع', 'الحالة', 'الحساب الأب', 'الإجراءات'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($accounts as $account)
                    <tr class="{{ $account->is_active ? '' : 'bg-slate-50 text-slate-500' }}">
                        <td class="p-4 font-mono" dir="ltr">{{ $account->code }}</td><td class="p-4 font-semibold">{{ $account->name }}</td><td class="p-4">{{ $types[$account->type] }}</td>
                        <td class="p-4"><span class="rounded-full px-2 py-1 {{ $account->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ $account->is_active ? 'نشط' : 'غير نشط' }}</span></td>
                        <td class="p-4">{{ $accounts->firstWhere('id', $account->parent_id)?->code ?? '—' }}</td>
                        <td class="p-4"><div class="flex items-center gap-3"><a class="text-indigo-700" href="{{ route('accounting-pages.chart.edit', $account->id) }}">تعديل</a>
                            <form action="{{ route('accounting-pages.chart.destroy', $account->id) }}" method="POST" onsubmit="return confirm('حذف هذا الحساب؟ لا يمكن حذف حساب مرتبط بقيود أو حسابات فرعية.')">@csrf @method('DELETE')<button class="text-red-700">حذف</button></form>
                        </div></td>
                    </tr>
                @empty <tr><td colspan="6" class="p-10 text-center text-slate-500">لا توجد حسابات محاسبية. أضف أول حساب من النموذج أعلاه.</td></tr> @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
