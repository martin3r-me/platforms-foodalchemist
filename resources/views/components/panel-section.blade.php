{{--
    FA einklappbare Panel-Sektion — Disclosure-Zeile: Icon links, Titel (sentence-case)
    + optionaler Count/Sub, Chevron rechts, Hairline-Trenner oben, Hover-Highlight.
    Kapselt das dreifach duplizierte toggleSektion-Muster der Detail-Panels.

    wire:click läuft gegen die umschließende Livewire-Komponente — deren toggleSektion()
    muss den target-Key in ihrer Whitelist führen.

    fa-pass (2026-10-05): Tokens; Props/Slots und toggleSektion unverändert.

    Props: title, target, open, count, sub, icon (heroicon-o-…).
    Slots:
      default  → Body, rendert nur wenn :open (eingerückt unter dem Titel).
      actions  → rechts neben dem Kopf (z. B. „Netz").
      preview  → statt Body, wenn NICHT offen (kompakte Zusammenfassung).
--}}
@props(['title', 'target', 'open' => false, 'count' => null, 'sub' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'border-t border-[var(--fa-line)]']) }}>
    <div class="flex items-center gap-1">
        <button type="button" wire:click="toggleSektion('{{ $target }}')" aria-expanded="{{ $open ? 'true' : 'false' }}"
                class="group flex-1 min-w-0 flex items-center gap-2.5 py-2.5 text-left transition-colors">
            @if($icon)<span class="shrink-0 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-accent)] transition-colors">@svg($icon, 'w-4 h-4')</span>@endif
            <span class="min-w-0 truncate text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $title }}</span>
            @if($count !== null)<span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $count }}</span>@endif
            @if($sub !== null)<span class="min-w-0 truncate text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $sub }}</span>@endif
            <span class="ml-auto shrink-0 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-ink)] transition-colors">@svg($open ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right', 'w-4 h-4')</span>
        </button>
        @isset($actions)<div class="shrink-0">{{ $actions }}</div>@endisset
    </div>
    @if($open)
        <div class="pb-3 pl-6">{{ $slot }}</div>
    @elseif(isset($preview))
        <div class="pb-3 pl-6">{{ $preview }}</div>
    @endif
</div>
