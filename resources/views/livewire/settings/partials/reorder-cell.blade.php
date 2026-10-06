{{--
    Umsortier-Bedienelemente: Ziehgriff + Hoch/Runter als zuverlässige Alternative.
    Erwartet (via @include): $id, $upMethod, $downMethod, $first, $last.
    Voraussetzung: Container mit Alpine-Scope `x-data="{ dragId: null }"` + Drop-Handler an der Zeile.
    fa-pass 2026-10-05: Heroicons statt Schriftzeichen (⠿ ▲ ▼), Tokens statt fester Farben.
--}}
<span class="inline-flex items-center gap-0.5 align-middle">
    <span class="inline-flex items-center justify-center w-5 h-6 cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] select-none"
          draggable="true"
          @dragstart="dragId = {{ $id }}; $event.dataTransfer.effectAllowed = 'move'"
          @dragend="dragId = null"
          title="Ziehen zum Umsortieren" aria-hidden="true">@svg('heroicon-m-bars-3', 'w-4 h-4')</span>
    <span class="inline-flex flex-col">
        <button type="button" wire:click="{{ $upMethod }}({{ $id }})" @disabled($first ?? false)
                class="inline-flex items-center justify-center w-5 h-3.5 rounded-sm {{ ($first ?? false) ? 'text-[var(--fa-line-strong)] cursor-not-allowed' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]' }}"
                title="Nach oben" aria-label="Nach oben">@svg('heroicon-m-chevron-up', 'w-3.5 h-3.5')</button>
        <button type="button" wire:click="{{ $downMethod }}({{ $id }})" @disabled($last ?? false)
                class="inline-flex items-center justify-center w-5 h-3.5 rounded-sm {{ ($last ?? false) ? 'text-[var(--fa-line-strong)] cursor-not-allowed' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]' }}"
                title="Nach unten" aria-label="Nach unten">@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</button>
    </span>
</span>
