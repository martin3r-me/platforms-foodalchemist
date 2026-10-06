{{-- Board-Karte (Status-Spalten). Erwartet die Session-Variable + Helfer-Closures aus index.blade.
     Klick auf den Kopf wählt (Details rechts), „Öffnen" öffnet den Editor. Heroicons, keine Emoji.
     Zwei HTML-Fallen für Livewires libxml-Root-Check (sonst multiple-root-elements-Fehler):
     a) Klickbarer Titelbereich ist ein div mit wire:click, kein button-Element (ein button darf
        keine Block-Elemente enthalten, sonst bricht der Parser die Verschachtelung auf).
     b) heroicons in den Aktions-Buttons inline lassen, nicht auf eine eigene Zeile umbrechen.
     fa-pass: Tokens (hell + Werkbank), Titel darf zweizeilig umbrechen statt abgeschnitten zu werden,
     Verwerfen rechts abgesetzt in Rot. --}}
@php
    $istAktiv = ($active->id ?? null) === $s->id;
    $fort = $kaskadeFortschritt($s->id);
@endphp
<div wire:key="board-{{ $s->id }}" class="rounded-[var(--fa-radius-surface)] bg-[var(--fa-surface)] border {{ $istAktiv ? 'border-[var(--fa-accent)] ring-2 ring-[var(--fa-accent-line)]' : 'border-[var(--fa-line)] hover:border-[var(--fa-line-strong)]' }} transition-colors" data-planung-karte="{{ $s->id }}" data-planung-karte-aktiv="{{ $istAktiv ? '1' : '0' }}">
    <div role="button" tabindex="0" wire:click="waehle({{ $s->id }})" x-on:click="$store.ui?.mSet('activity_planung', 'open', true)" wire:keydown.enter="waehle({{ $s->id }})" class="cursor-pointer px-3 pt-3 pb-2 flex flex-col gap-2" title="Details anzeigen">
        <div class="flex items-start gap-2 min-w-0">
            @svg($typIcon($s), 'w-4 h-4 mt-0.5 shrink-0 text-[var(--fa-ink-3)]')
            <span class="flex-1 min-w-0 text-[length:var(--fa-text-md)] font-semibold leading-snug text-[var(--fa-ink)] line-clamp-2 break-words" title="{{ $anzeigeTitel($s) }}">{{ $anzeigeTitel($s) }}</span>
            @if($kaskadeLaeuft($s->id))<span class="shrink-0 mt-1.5 w-2 h-2 rounded-full bg-[var(--fa-warn)] animate-pulse" title="läuft gerade"></span>@endif
        </div>
        <div class="flex flex-wrap gap-1">
            {!! $ausgabeChip($s->id) !!}
            @if($s->category) {!! $chip($s->category, 'info') !!} @endif
        </div>
        @if($fort !== '')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums break-words" data-planung-fortschritt>{{ $fort }}</p>@endif
    </div>
    <div class="flex items-center gap-0.5 border-t border-[var(--fa-line)] px-1.5 py-1" data-planung-karten-aktionen>
        <x-fa::button variant="ghost" size="sm" icon="heroicon-o-pencil-square" wire:click="oeffne({{ $s->id }})" title="Im Editor öffnen" data-planung-karte-oeffnen>Öffnen</x-fa::button>
        <x-fa::icon-button size="sm" icon="heroicon-o-document-duplicate" label="Planung duplizieren" wire:click="planungDuplizieren({{ $s->id }})" data-planung-karte-duplizieren />
        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Planung verwerfen und laufende Erstellung stoppen" class="ml-auto"
            wire:click="planungVerwerfen({{ $s->id }})" wire:confirm="Diese Planung verwerfen? Laufende Erstellungen dieser Planung werden ebenfalls gestoppt. Sie wird archiviert, nicht endgültig gelöscht." data-planung-karte-verwerfen />
    </div>
</div>
