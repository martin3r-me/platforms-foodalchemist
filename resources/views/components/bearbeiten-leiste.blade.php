{{-- Spec 65 · Bearbeiten-Leiste für Editoren mit MitBearbeitungssperre (Platz: Aktionen im Editor-Kopf).
     $zustand = $this->sperrZustand(): modus neu | lesen | bearbeiten | fremd.
     - lesen:      Knopf „Bearbeiten" (sperrt für alle anderen)
     - fremd:      Hinweis „Wird von … bearbeitet (seit …)", Owner/Admin: „Sperre lösen"
     - bearbeiten: „Abbrechen" + der Slot (der Speichern-Knopf des Editors); Eingaben verlängern die Sperre
     - neu / aus:  nur der Slot (Neuanlage braucht keine Sperre; aus = Schalter foodalchemist.bearbeitungssperre)
     Speichern beendet die Bearbeitung (der Editor ruft bearbeitenBeenden() nach Erfolg). --}}
@props(['zustand', 'sofort' => false])   {{-- sofort: Detailspalte — Aktionen schreiben direkt, Abschluss heißt „Fertig" --}}
@php
    $modus = $zustand['modus'] ?? 'neu';
    $fremd = $zustand['fremd'] ?? null;
    $seit = $fremd !== null ? \Illuminate\Support\Carbon::parse($fremd['seit'], 'UTC')->setTimezone('Europe/Berlin')->format('H:i')   /* DB = UTC, Anzeige Wanduhr Küche */ : null;
@endphp
<div class="flex flex-wrap items-center gap-2" data-bearbeiten-leiste data-modus="{{ $modus }}">
    @if($modus === 'lesen')
        <x-fa::button :size="$sofort ? 'sm' : 'md'" variant="primary" icon="heroicon-m-pencil-square" wire:click="bearbeitenStarten" data-bearbeiten-starten>Bearbeiten</x-fa::button>
    @elseif($modus === 'fremd')
        <span class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-bearbeiten-fremd>
            @svg('heroicon-m-lock-closed', 'w-4 h-4')
            Wird von {{ $fremd['name'] ?? 'jemand anderem' }} bearbeitet (seit {{ $seit }})
        </span>
        @if($zustand['admin'] ?? false)
            <x-fa::button size="sm" variant="ghost" wire:click="sperreLoesen" wire:confirm="Sperre von {{ $fremd['name'] ?? 'unbekannt' }} lösen? Nicht gespeicherte Änderungen dort gehen verloren." data-sperre-loesen>Sperre lösen</x-fa::button>
        @endif
    @else
        @if($modus === 'bearbeiten')
            {{-- Aktivität (Tippen/Klicken im Editor) verlängert die Sperre höchstens einmal je Minute --}}
            <span class="hidden" x-data x-on:input.window.throttle.60000ms="$wire.sperreHerzschlag()" x-on:click.window.throttle.60000ms="$wire.sperreHerzschlag()"></span>
            @if($sofort)
                <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="bearbeitenFertig" title="Änderungen sind gespeichert — Bearbeitung beenden, Datensatz für andere freigeben" data-bearbeiten-fertig>Fertig</x-fa::button>
            @else
                <x-fa::button variant="ghost" wire:click="bearbeitenAbbrechen" wire:confirm="Bearbeitung abbrechen? Nicht gespeicherte Änderungen gehen verloren." data-bearbeiten-abbrechen>Abbrechen</x-fa::button>
            @endif
        @endif
        @if(! ($sofort && $modus === 'bearbeiten'))
            {{ $slot }}
        @endif
    @endif
</div>
