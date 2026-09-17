{{-- Spec 53 / Paket C — globaler KI-Status: EIN Aggregat über alle Steps des Teams (nicht nur des
     offenen Laufs), damit sichtbar bleibt, dass im Hintergrund noch etwas läuft, auch wenn gerade
     kein Cockpit-Lauf offen ist. Erwartet $kiStatus (PlanningCascadeService::kiStatusFuerTeam) +
     $workerState (WorkerHealthService::status, schon in Index::render() berechnet). Optional
     $klickbar=true schaltet auf den Worker-Tab um (nur sinnvoll, wo `tab` im Alpine-Scope existiert). --}}
@php
    $klickbar = $klickbar ?? false;
    $ampelPunkt = ['gesund' => 'bg-emerald-500', 'still' => 'bg-amber-500', 'unbekannt' => 'bg-gray-400'][$workerState ?? 'unbekannt'] ?? 'bg-gray-400';
@endphp
@if($kiStatus !== null && (($kiStatus['wartend'] ?? 0) > 0 || ($kiStatus['laufend'] ?? 0) > 0 || ($kiStatus['fehler_24h'] ?? 0) > 0))
    <div
        @if($klickbar) role="button" tabindex="0" @click="tab='worker'" class="cursor-pointer" @endif
        class="inline-flex flex-wrap items-center gap-1.5 px-2 py-1 rounded-md text-[11px] bg-black/[0.03] text-gray-600"
        data-planung-ki-status-leiste
        title="Klick öffnet den Worker-Tab"
    >
        <span class="w-1.5 h-1.5 rounded-full {{ $ampelPunkt }} {{ ($workerState ?? null) === 'gesund' ? 'animate-pulse' : '' }}"></span>
        <span>
            @if(($kiStatus['wartend'] ?? 0) > 0)
                <strong class="text-amber-600">{{ $kiStatus['wartend'] }}</strong> KI-Aufgabe{{ $kiStatus['wartend'] === 1 ? '' : 'n' }} wartet{{ $kiStatus['wartend'] === 1 ? '' : 'en' }}
            @endif
            @if(($kiStatus['wartend'] ?? 0) > 0 && ($kiStatus['laufend'] ?? 0) > 0) · @endif
            @if(($kiStatus['laufend'] ?? 0) > 0)
                <strong class="text-amber-600">{{ $kiStatus['laufend'] }}</strong> läuf{{ $kiStatus['laufend'] === 1 ? 't' : 'en' }}
            @endif
        </span>
        @if(!empty($kiStatus['aktuelle_phase']))
            <span class="text-gray-500 truncate max-w-[16rem]">— {{ $kiStatus['aktuelle_phase'] }}</span>
        @endif
        @if(($kiStatus['fehler_24h'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1 text-rose-600 font-medium">
                @svg('heroicon-o-exclamation-triangle', 'w-3 h-3') {{ $kiStatus['fehler_24h'] }} Fehler (24 h)
            </span>
        @endif
        @if(is_int($kiStatus['queue'] ?? null) && $kiStatus['queue'] > 0)
            <span class="text-gray-400" title="Jobs in der Warteschlange (alle Teams, database-Queue)">· Queue {{ $kiStatus['queue'] }}</span>
        @endif
    </div>
@endif
