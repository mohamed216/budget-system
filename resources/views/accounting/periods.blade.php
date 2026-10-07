@extends('layout')
@section('title', 'الفترات المحاسبية')
@section('page_title', 'الفترات المحاسبية')
@section('breadcrumb', 'المحاسبة / الفترات المحاسبية')
@section('content')
<div class="space-y-6">
    <p class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-800">تحدد الفترات المغلقة التواريخ التي لا يمكن حفظ قيود جديدة أو ترحيلها فيها. تبقى القيود المرحلة محفوظة كما هي.</p>
    @error('accounting') <p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $message }}</p> @enderror
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-4 text-lg font-bold">إضافة فترة محاسبية</h3>
        <form method="POST" action="{{ route('accounting-pages.periods.store') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @csrf
            <label class="space-y-1 text-sm">تاريخ البداية
                <input type="date" name="start_date" required value="{{ old('_period_id') ? '' : old('start_date') }}" class="block w-full rounded-lg border border-slate-300 p-2.5">
                @if(!old('_period_id')) @error('start_date') <span class="text-red-700">{{ $message }}</span> @enderror @endif
            </label>
            <label class="space-y-1 text-sm">تاريخ النهاية
                <input type="date" name="end_date" required value="{{ old('_period_id') ? '' : old('end_date') }}" class="block w-full rounded-lg border border-slate-300 p-2.5">
                @if(!old('_period_id')) @error('end_date') <span class="text-red-700">{{ $message }}</span> @enderror @endif
            </label>
            <div class="flex items-end"><button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-white hover:bg-indigo-700">إضافة الفترة</button></div>
        </form>
    </section>
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[800px] text-right text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-4">تاريخ البداية</th><th class="p-4">تاريخ النهاية</th><th class="p-4">الحالة</th><th class="p-4">أول إغلاق</th><th class="p-4">الإجراءات</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($periods as $period)
                    <tr>
                        <td class="p-4" dir="ltr">{{ $period->start_date->format('Y-m-d') }}</td>
                        <td class="p-4" dir="ltr">{{ $period->end_date->format('Y-m-d') }}</td>
                        <td class="p-4"><span class="rounded-full px-2 py-1 {{ $period->isOpen() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">{{ $period->isOpen() ? 'مفتوحة' : 'مغلقة' }}</span></td>
                        <td class="p-4" dir="ltr">{{ $period->first_closed_at?->format('Y-m-d H:i:s.u') ?? '—' }}</td>
                        <td class="p-4">
                            <div class="flex flex-wrap items-end gap-3">
                                @can('update', $period)
                                    <form method="POST" action="{{ route('accounting-pages.periods.update', $period->id) }}" class="flex flex-wrap items-end gap-2">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="_period_id" value="{{ $period->id }}">
                                        <label class="text-xs">تاريخ البداية<input type="date" name="start_date" required value="{{ (string) old('_period_id') === (string) $period->id ? old('start_date') : $period->start_date->format('Y-m-d') }}" class="block rounded-lg border border-slate-300 p-2 text-sm"></label>
                                        <label class="text-xs">تاريخ النهاية<input type="date" name="end_date" required value="{{ (string) old('_period_id') === (string) $period->id ? old('end_date') : $period->end_date->format('Y-m-d') }}" class="block rounded-lg border border-slate-300 p-2 text-sm"></label>
                                        <button class="rounded-lg border border-indigo-200 px-3 py-2 text-indigo-700">حفظ التواريخ</button>
                                        @if((string) old('_period_id') === (string) $period->id)
                                            @error('start_date') <span class="text-red-700">{{ $message }}</span> @enderror
                                            @error('end_date') <span class="text-red-700">{{ $message }}</span> @enderror
                                        @endif
                                    </form>
                                @endcan
                                @can('close', $period)
                                    <form method="POST" action="{{ route('accounting-pages.periods.close', $period->id) }}" onsubmit="return confirm('إغلاق هذه الفترة؟ ستُمنع عمليات حفظ القيود وترحيلها بتاريخ يقع ضمنها.')">@csrf<button class="rounded-lg border border-amber-200 px-3 py-2 text-amber-800">إغلاق</button></form>
                                @endcan
                                @can('reopen', $period)
                                    <form method="POST" action="{{ route('accounting-pages.periods.reopen', $period->id) }}" onsubmit="return confirm('إعادة فتح هذه الفترة المحاسبية؟')">@csrf<button class="rounded-lg border border-emerald-200 px-3 py-2 text-emerald-700">إعادة فتح</button></form>
                                @endcan
                                @can('delete', $period)
                                    <form method="POST" action="{{ route('accounting-pages.periods.destroy', $period->id) }}" onsubmit="return confirm('حذف هذه الفترة المحاسبية؟')">@csrf @method('DELETE')<button class="rounded-lg border border-red-200 px-3 py-2 text-red-700">حذف</button></form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty <tr><td colspan="5" class="p-10 text-center text-slate-500">لا توجد فترات محاسبية. أضف أول فترة من النموذج أعلاه.</td></tr> @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
