{{-- Board-Kopf (persistent, live): Zustand der Hintergrund-Erstellung + Status-Zähler + je laufendem
     Lauf ein Fortschrittsbalken. Erwartet $workerState, $workerAlter, $kaskaden, $sessions + Helfer-Closures.
     Live-Poll sitzt am Board-Container (gated auf $irgendeinLaeuft). Nur Tokens, damit hell und dunkel stimmen. --}}
@php
    // Status-Zähler über die (bereits links gefilterte) Session-Menge.
    $zaehler = collect($sessions)->countBy(fn ($s) => $kaskaden[(int) $s->id]['status'] ?? 'entwurf');
    // Ampel: gesund = zuletzt frisch gesehen, still = lange nichts, unbekannt = kein Signal.
    $ampel = [
        'gesund' => ['ok', 'bg-[var(--fa-ok)]', 'Hintergrund-Erstellung aktiv'],
        'still' => ['warn', 'bg-[var(--fa-warn)]', 'Hintergrund-Erstellung ruht'],
        'unbekannt' => ['neutral', 'bg-[var(--fa-ink-3)]', 'Hintergrund-Erstellung: Zustand unbekannt'],
    ];
    [$ampelTon, $punkt, $ampelLabel] = $ampel[$workerState] ?? $ampel['unbekannt'];
    $alterText = is_numeric($workerAlter ?? null)
        ? ($workerAlter < 90 ? 'vor ' . (int) $workerAlter . ' s' : 'vor ' . (int) round($workerAlter / 60) . ' min')
        : null;
    // Laufende Läufe mit Fortschritts-Bruch (Summe fertig / Summe total über alle Stufen).
    $laufende = collect($sessions)->filter(fn ($s) => ($kaskaden[(int) $s->id]['status'] ?? '') === 'läuft')->values();
    $nLaeuft = (int) ($zaehler['läuft'] ?? 0);
    $nPruefen = (int) ($zaehler['prüfen'] ?? 0);
    $nFertig = (int) ($zaehler['fertig'] ?? 0);
    $nFehler = (int) ($zaehler['fehlgeschlagen'] ?? 0);
@endphp

<div class="fa-surface p-3 flex flex-col gap-3 min-w-0" data-planung-worker-kopf>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 min-w-0">
        {{-- Zustand der Hintergrund-Erstellung --}}
        <x-fa::badge :tone="$ampelTon" data-planung-worker-ampel="{{ $workerState }}">
            <span class="w-2 h-2 rounded-full shrink-0 {{ $punkt }} {{ $workerState === 'gesund' ? 'animate-pulse' : '' }}" aria-hidden="true"></span>
            {{ $ampelLabel }}@if($alterText)<span class="font-normal opacity-80">, {{ $alterText }}</span>@endif
        </x-fa::badge>

        {{-- Status-Zähler: gleiche Farben wie die Punkte der Board-Spalten --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums" data-planung-worker-zaehler>
            <span><strong class="font-semibold text-[var(--fa-info)]">{{ $nLaeuft }}</strong> {{ $nLaeuft === 1 ? 'läuft' : 'laufen' }}</span>
            <span><strong class="font-semibold text-[var(--fa-warn)]">{{ $nPruefen }}</strong> zu prüfen</span>
            <span><strong class="font-semibold text-[var(--fa-ok)]">{{ $nFertig }}</strong> fertig</span>
            @if($nFehler > 0)<span><strong class="font-semibold text-[var(--fa-crit)]">{{ $nFehler }}</strong> fehlgeschlagen</span>@endif
        </div>

        {{-- Spec 53 / Paket C: globaler KI-Status (Team-Aggregat, unabhängig vom Session-Filter oben). --}}
        @include('foodalchemist::livewire.planung.partials.ki-status-leiste')
    </div>

    {{-- Fortschritt je laufendem Lauf --}}
    @if($laufende->count() > 0)
        <div class="flex flex-col gap-2 border-t border-[var(--fa-line)] pt-3" data-planung-worker-laeufe>
            @foreach($laufende as $s)
                @php
                    $stufen = $kaskaden[(int) $s->id]['stufen'] ?? [];
                    $total = collect($stufen)->sum('total');
                    $fertig = collect($stufen)->sum('fertig');
                    $prozent = $total > 0 ? (int) round($fertig / $total * 100) : 0;
                    $fort = $kaskadeFortschritt($s->id);
                    // Spec 53 / Paket C: aktuelle Phase des Laufs (jüngster Step mit gesetzter Phase).
                    $phaseAktuell = $kaskaden[(int) $s->id]['phase'] ?? null;
                @endphp
                {{-- <div wire:click>, kein <button>: enthält Block-Elemente (Balken/Text) → sonst bricht
                     der HTML-Parser die Verschachtelung (Livewire „multiple root elements"). --}}
                <div role="button" tabindex="0" wire:click="oeffne({{ $s->id }})"
                     class="group w-full text-left cursor-pointer rounded-[var(--fa-radius-control)] px-2 py-1.5 -mx-2 hover:bg-[var(--fa-hover)]"
                     data-planung-worker-lauf="{{ $s->id }}" title="Planung öffnen">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <span class="min-w-0 flex-1 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] break-words group-hover:text-[var(--fa-accent)]">{{ $anzeigeTitel($s) }}</span>
                        <span class="shrink-0 inline-flex items-center gap-3">
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $fertig }} von {{ $total }} fertig</span>
                            <button type="button" wire:click.stop="laufAbbrechen({{ (int) ($kaskaden[(int) $s->id]['run_id'] ?? 0) }})"
                                    wire:confirm="Diese laufende Planung stoppen? Die Hintergrund-Erstellung für alle anderen Planungen läuft weiter."
                                    class="inline-flex items-center gap-1 h-7 px-2 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]"
                                    title="Nur diese Planung stoppen" data-planung-lauf-stoppen>
                                @svg('heroicon-m-stop', 'w-3.5 h-3.5') Stoppen
                            </button>
                        </span>
                    </div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-[var(--fa-neutral-soft)] overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $prozent }}">
                        <div class="h-full rounded-full bg-[var(--fa-info)] transition-all" style="width: {{ $prozent }}%"></div>
                    </div>
                    @if($fort !== '')<p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">{{ $fort }}</p>@endif
                    @if($phaseAktuell)<p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-info)] break-words" data-planung-worker-phase="{{ $s->id }}">{{ $phaseAktuell }}</p>@endif
                </div>
            @endforeach
        </div>
    @endif
</div>
