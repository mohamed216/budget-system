<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'نظام الميزانية')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">

<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:right-4 focus:top-4 focus:z-[60] focus:rounded-xl focus:bg-indigo-600 focus:px-4 focus:py-3 focus:text-white">انتقل إلى المحتوى</a>

<div class="min-h-screen">

    {{-- Mobile overlay --}}
    <div
        id="sidebar-overlay"
        aria-hidden="true"
        class="fixed inset-0 z-40 hidden bg-slate-900/50 lg:hidden"
        onclick="toggleSidebar()">
    </div>

    {{-- Sidebar --}}
    <aside
        id="sidebar"
        aria-label="القائمة الجانبية"
        class="fixed inset-y-0 right-0 z-50 w-72 max-w-[calc(100vw-2rem)] translate-x-full border-l border-slate-200 bg-white shadow-xl transition-transform duration-300 lg:translate-x-0">

        <div class="flex h-full flex-col overflow-y-auto overscroll-contain">

            {{-- Logo --}}
            <div class="flex shrink-0 items-center gap-3 border-b border-slate-100 px-4 py-5 sm:px-6">
                <div aria-hidden="true" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-xl text-white shadow">
                    💰
                </div>

                <div>
                    <h1 class="text-lg font-bold text-slate-900">
                        نظام الميزانية
                    </h1>

                    <p class="text-xs text-slate-500">
                        إدارة مالية شخصية
                    </p>
                </div>

                <button
                    type="button"
                    onclick="toggleSidebar()"
                    aria-label="إغلاق القائمة"
                    class="mr-auto shrink-0 rounded-lg p-3 text-slate-500 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 lg:hidden">
                    ✕
                </button>
            </div>

            @auth
                {{-- Navigation --}}
                <nav aria-label="التنقل الرئيسي" class="flex-1 space-y-2 px-4 py-6">

                    <p class="mb-3 px-3 text-xs font-semibold text-slate-400">
                        القائمة الرئيسية
                    </p>

                    <a
                        href="{{ route('dashboard') }}"
                        @if(request()->routeIs('dashboard')) aria-current="page" @endif
                        class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600
                        {{ request()->routeIs('dashboard')
                            ? 'bg-indigo-50 text-indigo-700'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                        <span aria-hidden="true" class="text-lg">🏠</span>
                        <span>الرئيسية</span>
                    </a>

                    <a
                        href="{{ route('accounts.index') }}"
                        @if(request()->routeIs('accounts.*')) aria-current="page" @endif
                        class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600
                        {{ request()->routeIs('accounts.*')
                            ? 'bg-indigo-50 text-indigo-700'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                        <span aria-hidden="true" class="text-lg">💳</span>
                        <span>الحسابات</span>
                    </a>

                    <a
                        href="{{ route('categories.index') }}"
                        @if(request()->routeIs('categories.*')) aria-current="page" @endif
                        class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600
                        {{ request()->routeIs('categories.*')
                            ? 'bg-indigo-50 text-indigo-700'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                        <span aria-hidden="true" class="text-lg">🏷️</span>
                        <span>الفئات</span>
                    </a>

                    <a
                        href="{{ route('transactions.index') }}"
                        @if(request()->routeIs('transactions.*')) aria-current="page" @endif
                        class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600
                        {{ request()->routeIs('transactions.*')
                            ? 'bg-indigo-50 text-indigo-700'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                        <span aria-hidden="true" class="text-lg">↔️</span>
                        <span>المعاملات</span>
                    </a>

                    <a
                        href="{{ route('budgets.index') }}"
                        @if(request()->routeIs('budgets.*')) aria-current="page" @endif
                        class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600
                        {{ request()->routeIs('budgets.*')
                            ? 'bg-indigo-50 text-indigo-700'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                        <span aria-hidden="true" class="text-lg">📊</span>
                        <span>الميزانية</span>
                    </a>

                    <p class="border-t border-slate-100 px-3 pt-4 text-xs font-semibold text-slate-400">المحاسبة</p>
                    @foreach(['chart.index' => 'دليل الحسابات', 'journals.index' => 'القيود اليومية', 'periods.index' => 'الفترات المحاسبية', 'ledger' => 'الأستاذ العام', 'trial' => 'ميزان المراجعة'] as $page => $label)
                        <a href="{{ route('accounting-pages.'.$page) }}" @if(request()->routeIs('accounting-pages.'.explode('.', $page)[0].'*')) aria-current="page" @endif
                           class="block rounded-xl px-4 py-2 text-sm font-medium {{ request()->routeIs('accounting-pages.'.explode('.', $page)[0].'*') ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">{{ $label }}</a>
                    @endforeach
                </nav>

                {{-- User section --}}
                <div class="shrink-0 border-t border-slate-100 p-4">

                    <div class="mb-3 flex items-center gap-3 rounded-xl bg-slate-50 p-3">
                        <div aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 font-bold text-indigo-700">
                            {{ mb_substr(auth()->user()->name ?? 'م', 0, 1) }}
                        </div>

                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-800">
                                {{ auth()->user()->name }}
                            </p>

                            <p dir="ltr" class="truncate text-right text-xs text-slate-500">
                                {{ auth()->user()->email }}
                            </p>
                        </div>
                    </div>

                    <form action="{{ route('logout') }}" method="POST">
                        @csrf

                        <button
                            type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium text-slate-600 transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            <span aria-hidden="true">🚪</span>
                            <span>تسجيل الخروج</span>
                        </button>
                    </form>

                </div>
            @endauth

        </div>
    </aside>


    {{-- Main area --}}
    <div class="lg:mr-72">

        {{-- Header --}}
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">

            <div class="mx-auto flex min-h-20 max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">

                <div class="flex min-w-0 items-center gap-3">

                    {{-- Mobile menu --}}
                    <button
                        id="sidebar-toggle"
                        type="button"
                        onclick="toggleSidebar()"
                        aria-label="فتح القائمة"
                        aria-controls="sidebar"
                        aria-expanded="false"
                        class="shrink-0 rounded-xl border border-slate-200 bg-white p-2.5 text-slate-600 shadow-sm hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 lg:hidden">
                        ☰
                    </button>

                    <div class="min-w-0">
                        <p class="break-words text-xs leading-5 text-slate-500">
                            @yield('breadcrumb', 'نظام الميزانية')
                        </p>

                        <h2 class="break-words text-base font-bold leading-7 text-slate-900 sm:text-lg">
                            @yield('page_title', 'لوحة التحكم')
                        </h2>
                    </div>

                </div>

                @auth
                    <div class="hidden min-w-0 max-w-[40%] items-center gap-3 sm:flex">
                        <div class="min-w-0 text-left">
                            <p class="truncate text-sm font-semibold text-slate-800">
                                {{ auth()->user()->name }}
                            </p>

                            <p class="text-xs text-slate-500">
                                حسابك الشخصي
                            </p>
                        </div>

                        <div aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 font-bold text-indigo-700">
                            {{ mb_substr(auth()->user()->name ?? 'م', 0, 1) }}
                        </div>
                    </div>
                @endauth

            </div>

        </header>


        {{-- Content --}}
        <main id="main-content" tabindex="-1" class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">

            {{-- Success --}}
            @if(session('success'))
                <div role="status" class="mb-6 break-words rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true">✓</span>
                        <span>{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            {{-- Errors --}}
            @if($errors->any())
                <div role="alert" class="mb-6 break-words rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-700">
                    <div class="mb-2 flex items-center gap-2 font-semibold">
                        <span aria-hidden="true">⚠️</span>
                        <span>يرجى تصحيح الأخطاء التالية:</span>
                    </div>

                    <ul class="list-inside list-disc space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')

        </main>

    </div>

</div>


<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        sidebar.classList.toggle('translate-x-full');
        overlay.classList.toggle('hidden');
        syncSidebar();
        if (!sidebar.classList.contains('translate-x-full')) {
            sidebar.querySelector('button').focus();
        } else {
            document.getElementById('sidebar-toggle').focus();
        }
    }

    function syncSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mobile = !window.matchMedia('(min-width: 1024px)').matches;
        const open = !sidebar.classList.contains('translate-x-full');
        sidebar.inert = mobile && !open;
        document.getElementById('sidebar-toggle').setAttribute('aria-expanded', String(mobile && open));
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !document.getElementById('sidebar-overlay').classList.contains('hidden')) {
            toggleSidebar();
        }
    });

    window.matchMedia('(min-width: 1024px)').addEventListener('change', function () {
        document.getElementById('sidebar').classList.add('translate-x-full');
        document.getElementById('sidebar-overlay').classList.add('hidden');
        syncSidebar();
    });

    syncSidebar();
</script>

</body>
</html>
