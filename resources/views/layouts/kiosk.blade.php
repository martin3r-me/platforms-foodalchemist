<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full overflow-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Platform') }} · Küchenmonitor</title>

    <x-ui-styles />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-950 text-white overflow-hidden" data-foodalchemist-kiosk-layout>
    {{-- Marke (Dominique 2026-10-05): mittig im freien Kopfbereich, aus der Ferne lesbar, nicht klickbar.
         Zeichen in der Fassung für dunklen Grund + Wortmarke als Text (die Bild-Wortmarke hat schwarze Schrift).
         Erst ab xl — darunter braucht der Kopf die Breite für Titel und Knöpfe. --}}
    <div class="pointer-events-none fixed top-4 left-1/2 -translate-x-1/2 z-10 hidden xl:flex items-center gap-3" data-kiosk-marke aria-hidden="true">
        <img src="{{ asset('brand/fa-mark-on-dark.png') }}" alt="" width="40" height="40" class="w-10 h-10">
        <span class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight text-white">Food<span class="text-indigo-300">.Alchemist</span></span>
    </div>

    {{ $slot }}

    <livewire:notifications.notices.index />

    @livewireScripts
</body>
</html>
