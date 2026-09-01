{{-- Board-Worker-Kopf (persistent, live). Globale Worker-Ampel + Status-Zähler + je laufendem Lauf
     ein Stufen-Fortschritt. Erwartet $workerState, $workerAlter, $kaskaden, $sessions + Helfer-Closures.
     Emoji-frei. Live-Poll sitzt am Board-Container (gated auf $irgendeinLaeuft). --}}
@php
    // Status-Zähler über die (bereits links gefilterte) Session-Menge.
    $zaehler = collect($sessions)->countBy(fn ($s) => $kaskaden[(int) $s->id]['status'] ?? 'entwurf');
    // Ampel: gesund = Worker frisch gesehen, still = lange nichts, unbekannt = kein Signal.
    $ampel = [
        'gesund' => ['bg-emerald-500', 'text-emerald-700', 'bg-emerald-500/10', 'Worker aktiv'],
        'still' => ['bg-amber-500', 'text-amber-700', 'bg-amber-500/10', 'Worker still'],
        'unbekannt' => ['bg-gray-400', 'text-gray-500', 'bg-black/[0.04]', 'Worker-Status unbekannt'],
    ];
    [$punkt, $txt, $bg, $ampelLabel] = $ampel[$workerState] ?? $ampel['unbekannt'];
    $alterText = is_numeric($workerAlter ?? null)
        ? ($workerAlter < 90 ? 'vor ' . (int) $workerAlter . ' s' : 'vor ' . (int) round($workerAlter / 60) . ' min')
        : null;
    // Laufende Läufe mit Fortschritts-Bruch (Summe fertig / Summe total über alle Stufen).
    $laufende = collect($sessions)->filter(fn ($s) => ($kaskaden[(int) $s->id]['status'] ?? '') === 'läuft')->values();
@endphp

<div class="{{ $card }} p-3 mb-3" data-planung-worker-kopf>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        {{-- Globale Ampel --}}
        <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-semibold {{ $bg }} {{ $txt }}" data-planung-worker-ampel="{{ $workerState }}">
            <span class="w-2 h-2 rounded-full {{ $punkt }} {{ $workerState === 'gesund' ? 'animate-pulse' : '' }}"></span>
            {{ $ampelLabel }}@if($alterText) <span class="font-normal opacity-70">· {{ $alterText }}</span>@endif
        </span>
        {{-- Status-Zähler --}}
        <div class="flex items-center gap-3 text-[11px] text-gray-500" data-planung-worker-zaehler>
            <span><strong class="text-amber-600">{{ $zaehler['läuft'] ?? 0 }}</strong> läuft</span>
            <span><strong class="text-violet-600">{{ $zaehler['prüfen'] ?? 0 }}</strong> zu prüfen</span>
            <span><strong class="text-emerald-600">{{ $zaehler['fertig'] ?? 0 }}</strong> fertig</span>
            @if(($zaehler['fehlgeschlagen'] ?? 0) > 0)<span><strong class="text-rose-600">{{ $zaehler['fehlgeschlagen'] }}</strong> fehlgeschlagen</span>@endif
        </div>
    </div>

    {{-- Fortschritt je laufendem Lauf --}}
    @if($laufende->count() > 0)
        <div class="mt-3 space-y-2 border-t border-black/5 pt-3" data-planung-worker-laeufe>
            @foreach($laufende as $s)
                @php
                    $stufen = $kaskaden[(int) $s->id]['stufen'] ?? [];
                    $total = collect($stufen)->sum('total');
                    $fertig = collect($stufen)->sum('fertig');
                    $prozent = $total > 0 ? (int) round($fertig / $total * 100) : 0;
                @endphp
                {{-- <div wire:click>, kein <button>: enthält Block-Elemente (Balken/Text) → sonst bricht
                     der HTML-Parser die Verschachtelung (Livewire „multiple root elements"). --}}
                <div role="button" tabindex="0" wire:click="oeffne({{ $s->id }})" class="w-full text-left group cursor-pointer" data-planung-worker-lauf="{{ $s->id }}" title="Im Editor öffnen">
                    <div class="flex items-center justify-between gap-2 text-[11px]">
                        <span class="font-medium text-gray-700 truncate group-hover:text-violet-700">{{ $anzeigeTitel($s) }}</span>
                        <span class="shrink-0 inline-flex items-center gap-2">
                            <span class="text-gray-400 tabular-nums">{{ $fertig }}/{{ $total }}</span>
                            <button type="button" wire:click.stop="laufAbbrechen({{ (int) ($kaskaden[(int) $s->id]['run_id'] ?? 0) }})"
                                    wire:confirm="Diese laufende Planung stoppen? Der globale Worker bleibt aktiv."
                                    class="text-rose-600 hover:text-rose-700 font-medium" title="Nur diese Planung stoppen" data-planung-lauf-stoppen>
                                Stoppen
                            </button>
                        </span>
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-black/[0.06] overflow-hidden">
                        <div class="h-full rounded-full bg-amber-400 transition-all" style="width: {{ $prozent }}%"></div>
                    </div>
                    @php $fort = $kaskadeFortschritt($s->id); @endphp
                    @if($fort !== '')<p class="mt-1 text-[10px] text-gray-400 truncate">{{ $fort }}</p>@endif
                </div>
            @endforeach
        </div>
    @endif
</div>
