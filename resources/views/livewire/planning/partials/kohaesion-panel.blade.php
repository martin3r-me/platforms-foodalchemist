{{-- R6.1 Zusammenhalt der Menüfolge (Pairing-Graph, menuCohesion).
     Erwartet: $menueKohaesion (nullable — null = noch nicht geprüft) + Knopf ruft
     kohaesionPruefen() am Host. Ehrlich: unbewertete Paare werden benannt, nie versteckt.
     Schwellen wie PairingService (≥60 gut, ≥35 schwach, sonst kritisch). fa-pass: nur Tokens. --}}
<div class="fa-surface flex flex-col gap-3 px-4 py-3" data-kohaesion-panel>
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Zusammenhalt der Menüfolge</h3>
        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">aus dem Aroma-Netz</span>
        <x-fa::button size="sm" :icon="$menueKohaesion === null ? 'heroicon-m-play' : 'heroicon-m-arrow-path'" wire:click="kohaesionPruefen" class="ml-auto" data-kohaesion-pruefen>
            {{ $menueKohaesion === null ? 'Zusammenhalt prüfen' : 'Erneut prüfen' }}
        </x-fa::button>
    </div>

    @if($menueKohaesion !== null)
        @if($menueKohaesion['zu_wenig'] ?? false)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Es braucht mindestens zwei Gerichte. Erst den Aufbau befüllen.</p>
        @else
            @php
                $score = (int) $menueKohaesion['score'];
                $scoreFarbe = $score >= 60 ? 'text-[var(--fa-ok)]' : ($score >= 35 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-crit)]');
                $warnung = $menueKohaesion['warnung'] ?? null;
            @endphp
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-[length:var(--fa-text-2xl)] font-semibold tabular-nums {{ $scoreFarbe }}" data-kohaesion-score>{{ $score }}</span>
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">von 100, {{ $menueKohaesion['rated_pairs'] }} von {{ $menueKohaesion['total_pairs'] }} Gericht-Paaren bewertet ({{ $menueKohaesion['coverage_pct'] }} % im Aroma-Netz)</span>
            </div>
            @if($warnung !== null)
                @php $warnTon = $warnung['stufe'] === 'gut' ? 'ok' : ($warnung['stufe'] === 'schwach' ? 'warn' : 'crit'); @endphp
                <x-fa::notice :tone="$warnTon" data-kohaesion-warnung data-kohaesion-stufe="{{ $warnung['stufe'] }}">{{ $warnung['text'] }}</x-fa::notice>
            @endif
            @if($menueKohaesion['weakest_pair'] !== null)
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Schwächstes Paar: <span class="font-medium text-[var(--fa-ink)]">{{ $menueKohaesion['weakest_pair']['a'] }}</span> und <span class="font-medium text-[var(--fa-ink)]">{{ $menueKohaesion['weakest_pair']['b'] }}</span> <span class="tabular-nums">({{ $menueKohaesion['weakest_pair']['score'] }}, {{ $menueKohaesion['weakest_pair']['type'] }})</span></p>
            @endif
            @if(($menueKohaesion['komponenten'] ?? []) !== [])
                <div class="flex flex-wrap gap-1.5">
                    @foreach($menueKohaesion['komponenten'] as $k)
                        <x-fa::badge :tone="($k['is_orphan'] ?? false) && $k['fit'] === null ? 'warn' : 'neutral'" :icon="($k['is_orphan'] ?? false) && $k['fit'] === null ? 'heroicon-m-exclamation-triangle' : null"
                            title="{{ ($k['is_orphan'] ?? false) && $k['fit'] === null ? 'Das Aroma-Netz kennt dieses Gericht nicht (keine bewerteten Verbindungen)' : $k['rated_links'] . ' bewertete Verbindungen' }}">
                            {{ \Illuminate\Support\Str::limit($k['label'], 28) }}@if($k['fit'] !== null)<span class="tabular-nums font-semibold">{{ $k['fit'] }}</span>@endif
                        </x-fa::badge>
                    @endforeach
                </div>
            @endif
            @if(($menueKohaesion['unrated_pairs'] ?? []) !== [])
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ count($menueKohaesion['unrated_pairs']) }} {{ count($menueKohaesion['unrated_pairs']) === 1 ? 'Paar hat' : 'Paare haben' }} keine Daten im Aroma-Netz. Das heißt: unbewertet, nicht schlecht.</p>
            @endif
        @endif
    @endif
</div>
