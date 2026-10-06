@extends('layout')
@section('title', 'تعذر تحميل الصفحة')
@section('page_title', 'المحاسبة')
@section('breadcrumb', 'المحاسبة / تعذر تحميل الصفحة')
@section('content')
<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800">
    <h3 class="font-bold">تعذر تحميل الصفحة المحاسبية.</h3>
    <p class="mt-2 text-sm">يرجى المحاولة لاحقاً أو التواصل مع المسؤول. لم يتم عرض تفاصيل قاعدة البيانات.</p>
</div>
@endsection
