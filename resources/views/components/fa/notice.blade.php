{{-- x-fa::notice — Hinweisfläche (z. B. „Kein Hintergrund-Worker aktiv"). tone: info · warn · crit · ok.
     title optional, Inhalt als Slot, actions-Slot rechts. --}}
@props(['tone' => 'info', 'title' => null])
@php
    $farbe = [
        'ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]',
        'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]',
        'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]',
    ][$tone] ?? 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]';
    $symbol = ['ok' => 'heroicon-o-check-circle', 'warn' => 'heroicon-o-exclamation-triangle', 'crit' => 'heroicon-o-exclamation-circle'][$tone] ?? 'heroicon-o-information-circle';
@endphp
<div role="{{ $tone === 'crit' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => "flex items-start gap-2.5 px-3.5 py-3 rounded-[var(--fa-radius-surface)] $farbe"]) }}>
    @svg($symbol, 'w-5 h-5 shrink-0 mt-px')
    <div class="min-w-0 flex-1 text-[length:var(--fa-text-md)]">
        @if($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div class="text-[var(--fa-ink)]">{{ $slot }}</div>
    </div>
    @isset($actions)<div class="shrink-0 flex items-center gap-2">{{ $actions }}</div>@endisset
</div>
