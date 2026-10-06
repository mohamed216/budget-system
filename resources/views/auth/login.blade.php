<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>تسجيل الدخول - نظام الميزانية</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100">

<div class="flex min-h-screen items-center justify-center px-4 py-10">

    <div class="w-full max-w-md">

        {{-- Logo --}}
        <div class="mb-8 text-center">

            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600 text-3xl text-white shadow-lg">
                💰
            </div>

            <h1 class="text-2xl font-bold text-slate-900">
                نظام الميزانية
            </h1>

            <p class="mt-2 text-sm text-slate-500">
                سجّل الدخول لإدارة أموالك بسهولة
            </p>

        </div>


        {{-- Login card --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">

            <div class="mb-6">
                <h2 class="text-xl font-bold text-slate-900">
                    تسجيل الدخول
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    أدخل بيانات حسابك للمتابعة
                </p>
            </div>


            {{-- Errors --}}
            @if($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">

                    <div class="mb-2 flex items-center gap-2 font-semibold">
                        <span>⚠️</span>
                        <span>تعذر تسجيل الدخول</span>
                    </div>

                    <ul class="list-inside list-disc space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>

                </div>
            @endif


            <form
                action="{{ route('login.store') }}"
                method="POST"
                class="space-y-5">

                @csrf


                {{-- Email --}}
                <div>

                    <label
                        for="email"
                        class="mb-2 block text-sm font-medium text-slate-700">
                        البريد الإلكتروني
                    </label>

                    <div class="relative">

                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                            ✉️
                        </span>

                        <input
                            id="email"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            required
                            autofocus
                            placeholder="name@example.com"
                            class="w-full rounded-xl border border-slate-300 bg-white py-3 pr-11 pl-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100"
                        >

                    </div>

                    @error('email')
                        <p class="mt-2 text-xs text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>


                {{-- Password --}}
                <div>

                    <div class="mb-2 flex items-center justify-between">

                        <label
                            for="password"
                            class="block text-sm font-medium text-slate-700">
                            كلمة المرور
                        </label>

                    </div>

                    <div class="relative">

                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                            🔒
                        </span>

                        <input
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            placeholder="أدخل كلمة المرور"
                            class="w-full rounded-xl border border-slate-300 bg-white py-3 pr-11 pl-12 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100"
                        >

                        <button
                            type="button"
                            onclick="togglePassword()"
                            class="absolute inset-y-0 left-0 flex items-center px-4 text-slate-400 transition hover:text-slate-700"
                            aria-label="إظهار كلمة المرور">
                            👁️
                        </button>

                    </div>

                    @error('password')
                        <p class="mt-2 text-xs text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>


                {{-- Submit --}}
                <button
                    type="submit"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 active:scale-[0.99]">

                    <span>دخول</span>
                    <span>←</span>

                </button>

            </form>

        </div>


        {{-- Footer --}}
        <p class="mt-6 text-center text-xs text-slate-400">
            نظام الميزانية © {{ date('Y') }}
        </p>

    </div>

</div>


<script>
    function togglePassword() {
        const password = document.getElementById('password');

        password.type = password.type === 'password'
            ? 'text'
            : 'password';
    }
</script>

</body>
</html>
