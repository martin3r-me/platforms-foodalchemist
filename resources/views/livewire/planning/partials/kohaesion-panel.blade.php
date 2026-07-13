{{-- R6.1 Kohäsions-Beweis über die Menüfolge (Pairing-Graph, menuCohesion).
     Erwartet: $menueKohaesion (nullable — null = noch nicht geprüft) + Button ruft
     kohaesionPruefen() am Host. Ehrlich: unbewertete Paare werden benannt, nie versteckt. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="relative overflow-hidden {{ $card }}" data-kohaesion-panel>
    <div class="{{ $cardAccent }}"></div>
    <div class="px-4 py-3 space-y-2">
        <div class="flex items-center gap-2">
            <span class="text-[11px] uppercase tracking-wider text-gray-400">Kohäsion der Menüfolge (Pairing-Graph)</span>
            <button type="button" wire:click="kohaesionPruefen" class="{{ $btnGhostXs }} ml-auto" data-kohaesion-pruefen>
                {{ $menueKohaesion === null ? 'Kohäsion prüfen' : '↻ neu prüfen' }}
            </button>
        </div>

        @if($menueKohaesion !== null)
            @if($menueKohaesion['zu_wenig'] ?? false)
                <p class="text-[11px] text-gray-400">Mindestens 2 Gerichte nötig — erst den Aufbau befüllen.</p>
            @else
                @php($score = (int) $menueKohaesion['score'])
                @php($scoreFarbe = $score >= 60 ? 'text-emerald-600 dark:text-emerald-400' : ($score >= 35 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400'))
                <div class="flex items-baseline gap-3">
                    <span class="text-2xl font-semibold tabular-nums {{ $scoreFarbe }}" data-kohaesion-score>{{ $score }}</span>
                    <span class="text-[11px] text-gray-400">Score · {{ $menueKohaesion['rated_pairs'] }}/{{ $menueKohaesion['total_pairs'] }} Gericht-Paare bewertet ({{ $menueKohaesion['coverage_pct'] }} % Graph-Abdeckung)</span>
                </div>
                @if($menueKohaesion['weakest_pair'] !== null)
                    <p class="text-[11px] text-gray-500">Schwächstes Paar: <span class="font-medium">{{ $menueKohaesion['weakest_pair']['a'] }}</span> ↔ <span class="font-medium">{{ $menueKohaesion['weakest_pair']['b'] }}</span> ({{ $menueKohaesion['weakest_pair']['score'] }}, {{ $menueKohaesion['weakest_pair']['type'] }})</p>
                @endif
                @if(($menueKohaesion['komponenten'] ?? []) !== [])
                    <div class="flex flex-wrap gap-1">
                        @foreach($menueKohaesion['komponenten'] as $k)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] bg-black/[0.03] dark:bg-white/5 text-gray-500" title="{{ $k['rated_links'] }} bewertete Verbindungen">
                                {{ \Illuminate\Support\Str::limit($k['label'], 28) }}
                                @if($k['fit'] !== null)<span class="tabular-nums font-medium">{{ $k['fit'] }}</span>@elseif($k['is_orphan'] ?? false)<span class="text-amber-500" title="Graph sieht dieses Gericht nicht (keine bewerteten Verbindungen)">⚠</span>@endif
                            </span>
                        @endforeach
                    </div>
                @endif
                @if(($menueKohaesion['unrated_pairs'] ?? []) !== [])
                    <p class="text-[10px] text-gray-400">{{ count($menueKohaesion['unrated_pairs']) }} Paar(e) ohne Graph-Daten — ehrlich unbewertet, nicht schlecht.</p>
                @endif
            @endif
        @endif
    </div>
</div>
