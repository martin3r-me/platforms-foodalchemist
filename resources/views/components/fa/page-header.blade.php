{{-- x-fa::page-header — Kopf einer Inhaltsseite: Titel, optional Untertitel/Zähler, Aktionen rechts.
     Hauptaktion (variant=primary) steht ganz rechts. --}}
@props(['title', 'subtitle' => null])
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="min-w-0">
        <h1 class="text-[length:var(--fa-text-3xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)]">{{ $title }}</h1>
        @if($subtitle)<p class="mt-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] tabular-nums">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
