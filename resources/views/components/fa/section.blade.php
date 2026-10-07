{{-- x-fa::section — Abschnitt mit Titel. variant: card (eigene Fläche) · plain (nur Titel + Inhalt, für Detail-Spalten).
     Slots: actions (rechts im Kopf). meta: kurzer Zusatz neben dem Titel. --}}
@props(['title' => null, 'icon' => null, 'meta' => null, 'description' => null, 'variant' => 'card'])
<section {{ $attributes->merge(['class' => $variant === 'card' ? 'fa-surface p-4 flex flex-col gap-3 min-w-0' : 'flex flex-col gap-3 min-w-0 py-4 border-t border-[var(--fa-line)] first:border-t-0 first:pt-0']) }}>
    @if($title || isset($actions))
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">
                    @if($icon)@svg($icon, 'w-[18px] h-[18px] shrink-0 text-[var(--fa-ink-3)]')@endif
                    <span class="truncate">{{ $title }}</span>
                    @if($meta !== null && $meta !== '')<span class="text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)] tabular-nums">{{ $meta }}</span>@endif
                </h3>
                @if($description)<p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[65ch]">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="shrink-0 flex items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    {{ $slot }}
</section>
