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
    {{ $slot }}

    <livewire:notifications.notices.index />

    @livewireScripts
</body>
</html>
