{{-- Board-Karte (Status-Kanban). Erwartet die Session-Variable + Helfer-Closures aus index.blade.
     Titel-Klick waehlt (Details rechts), Stift oeffnet den Editor. Emoji-frei (Heroicons/Text).
     Zwei HTML-Fallen fuer Livewires libxml-Root-Check (sonst multiple-root-elements-Fehler):
     a) Klickbarer Titelbereich ist ein div mit wire:click, kein button-Element (ein button darf
        keine Block-Elemente enthalten, sonst bricht der Parser die Verschachtelung auf).
     b) heroicons in den Aktions-Buttons inline lassen, nicht auf eine eigene Zeile umbrechen. --}}
<div wire:key="board-{{ $s->id }}" class="{{ $card }} p-3 {{ (($active->id ?? null) === $s->id) ? 'ring-2 ring-violet-400' : '' }}" data-planung-karte="{{ $s->id }}" data-planung-karte-aktiv="{{ (($active->id ?? null) === $s->id) ? '1' : '0' }}">
    <div role="button" tabindex="0" wire:click="waehle({{ $s->id }})" class="cursor-pointer hover:opacity-80 transition-opacity">
        <div class="flex items-start justify-between gap-2">
            <span class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">@svg($typIcon($s), 'w-3.5 h-3.5 shrink-0 text-gray-400'){{ $anzeigeTitel($s) }}</span>
            @if($kaskadeLaeuft($s->id))<span class="shrink-0 w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse mt-1" title="läuft"></span>@endif
        </div>
        <div class="mt-2 flex flex-wrap gap-1">
            {!! $ausgabeChip($s->id) !!}
            @if($s->category) {!! $chip($s->category, 'bg-sky-500/10 text-sky-700') !!} @endif
        </div>
        @php $fort = $kaskadeFortschritt($s->id); @endphp
        @if($fort !== '')<p class="mt-1.5 text-[10px] text-gray-500 truncate" data-planung-fortschritt>{{ $fort }}</p>@endif
    </div>
    <div class="mt-2 flex items-center gap-1 border-t border-black/5 pt-2" data-planung-karten-aktionen>
        <button type="button" wire:click="oeffne({{ $s->id }})" class="{{ $btnGhostXs }}" title="Im Editor öffnen" data-planung-karte-oeffnen>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')</button>
        <button type="button" wire:click="planungDuplizieren({{ $s->id }})" class="{{ $btnGhostXs }}" title="Duplizieren" data-planung-karte-duplizieren>@svg('heroicon-o-document-duplicate', 'w-3.5 h-3.5')</button>
        <button type="button" wire:click="planungVerwerfen({{ $s->id }})" wire:confirm="Diese Planung verwerfen? Aktive Generierungen dieser Planung werden ebenfalls gestoppt. (reversibel — Soft-Delete)" class="{{ $btnGhostXs }} !text-rose-600" title="Verwerfen und laufende Generierung stoppen" data-planung-karte-verwerfen>@svg('heroicon-o-trash', 'w-3.5 h-3.5')</button>
    </div>
</div>
