{{-- Spec 53 / Paket C — globaler KI-Status: EIN Aggregat über alle Schritte des Teams (nicht nur des
     offenen Laufs), damit sichtbar bleibt, dass im Hintergrund noch etwas läuft, auch wenn gerade
     kein Lauf im Editor offen ist. Erwartet $kiStatus (PlanningCascadeService::kiStatusFuerTeam) +
     $workerState (WorkerHealthService::status, schon in Index::render() berechnet). Optional
     $klickbar=true schaltet auf den Reiter „Fortschritt" um (nur sinnvoll, wo `tab` im Alpine-Scope existiert).
     fa-pass: Tokens statt Grau-Palette (Werkbank-tauglich); die doppelte class-Angabe im klickbaren
     Fall (der Browser nahm nur die erste) ist zusammengeführt. --}}
@php
    // Fail-soft: ein späterer Include-Ort ohne $kiStatus im Scope soll still nichts rendern
    // (@if unten), statt mit "Undefined variable" zu sterben.
    $kiStatus = $kiStatus ?? null;
    $klickbar = $klickbar ?? false;
    $ampelPunkt = ['gesund' => 'bg-[var(--fa-ok)]', 'still' => 'bg-[var(--fa-warn)]', 'unbekannt' => 'bg-[var(--fa-ink-3)]'][$workerState ?? 'unbekannt'] ?? 'bg-[var(--fa-ink-3)]';
    $leisteKlasse = 'inline-flex flex-wrap items-center gap-1.5 h-7 px-2.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]'
        . ($klickbar ? ' cursor-pointer hover:bg-[var(--fa-hover)] transition-colors' : '');
@endphp
@if($kiStatus !== null && (($kiStatus['wartend'] ?? 0) > 0 || ($kiStatus['laufend'] ?? 0) > 0 || ($kiStatus['fehler_24h'] ?? 0) > 0))
    <div
        @if($klickbar) role="button" tabindex="0" x-on:click="tab='worker'" x-on:keydown.enter="tab='worker'" title="Fortschritt öffnen" @endif
        class="{{ $leisteKlasse }}"
        data-planung-ki-status-leiste
    >
        <span class="w-2 h-2 rounded-full {{ $ampelPunkt }} {{ ($workerState ?? null) === 'gesund' ? 'animate-pulse' : '' }}"></span>
        <span>
            @if(($kiStatus['wartend'] ?? 0) > 0)
                <strong class="font-semibold text-[var(--fa-warn)]">{{ $kiStatus['wartend'] }}</strong> KI-Aufgabe{{ $kiStatus['wartend'] === 1 ? '' : 'n' }} wartet{{ $kiStatus['wartend'] === 1 ? '' : 'en' }}
            @endif
            @if(($kiStatus['wartend'] ?? 0) > 0 && ($kiStatus['laufend'] ?? 0) > 0) · @endif
            @if(($kiStatus['laufend'] ?? 0) > 0)
                <strong class="font-semibold text-[var(--fa-warn)]">{{ $kiStatus['laufend'] }}</strong> läuf{{ $kiStatus['laufend'] === 1 ? 't' : 'en' }}
            @endif
        </span>
        @if(!empty($kiStatus['aktuelle_phase']))
            <span class="text-[var(--fa-ink-3)] truncate max-w-[16rem]" title="{{ $kiStatus['aktuelle_phase'] }}">· {{ $kiStatus['aktuelle_phase'] }}</span>
        @endif
        @if(($kiStatus['fehler_24h'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1 font-medium text-[var(--fa-crit)]">
                @svg('heroicon-m-exclamation-triangle', 'w-3.5 h-3.5') {{ $kiStatus['fehler_24h'] }} Fehler in 24 Stunden
            </span>
        @endif
        @if(is_int($kiStatus['queue'] ?? null) && $kiStatus['queue'] > 0)
            <span class="text-[var(--fa-ink-3)]" title="Aufträge in der Warteschlange, über alle Teams">· {{ $kiStatus['queue'] }} in der Warteschlange</span>
        @endif
    </div>
@endif
