{{-- #1003 Grundprodukt am Handy (Handy\GpAnsicht), nur lesen. Ziel der GP-Links im Rezept- und Gericht-Handbuch.
     Aufbau: Name → Warengruppe/Zustand → Hauptartikel (Lead) mit Preis je Einheit → Artikel-Liste → Allergene + Kern-Anker →
     Geschmacksprofil (7 Achsen, 0–1). Kein Desktop-Link (Dominique 02.11.: Antippen springt nur hierher). Allergene im 4-Wert-Modell: unbekannt nie als „frei“ werten.
     Fremd oder unsichtbar: nur „nicht verfügbar“. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $karte = 'rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)]';
    $h2 = 'text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]';
    $zahl = fn ($wert) => number_format((float) $wert, 2, ',', '.');
    $preisJe = fn ($la) => $la->vergleichspreis !== null ? $zahl($la->vergleichspreis['value']) . ' ' . $la->vergleichspreis['unit'] : null;
    $gebindePreis = fn ($la) => $la->aktiver_preis !== null ? $zahl($la->aktiver_preis) . ' €' : null;
@endphp
<div class="min-h-full bg-[var(--fa-ground)]" data-fa-handy-gp="{{ $gp?->id ?? 'fehlt' }}">
    <x-foodalchemist::shell.page-navbar title="Grundprodukt" icon="heroicon-o-cube" />

    <div class="mx-auto max-w-2xl px-4 py-4 flex flex-col gap-5">
        @if($gp === null)
            <x-fa::empty title="Grundprodukt nicht verfügbar" data-fa-handy-gp-fehlt>Es ist gelöscht oder für dein Team nicht sichtbar.</x-fa::empty>
        @else
            @php
                $namen = \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE;
                $werte = collect($allergene)->map(fn ($a) => $a['value']->value);
                $enthalten = $werte->filter(fn ($w) => $w === 'enthalten')->keys()->map(fn ($k) => $namen[$k] ?? $k);
                $spuren = $werte->filter(fn ($w) => $w === 'spuren')->keys()->map(fn ($k) => $namen[$k] ?? $k);
                $unbekannt = $werte->filter(fn ($w) => $w === 'unbekannt')->count();
            @endphp
            <header class="flex flex-col gap-2">
                <h1 class="text-[length:var(--fa-text-2xl)] font-semibold leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $gp->name }}</h1>
                <div class="flex flex-wrap items-center gap-1.5">
                    <x-fa::status :value="$gp->status" />
                    <x-fa::badge tone="info" data-fa-handy-gp-wg>{{ $gp->commodity_group?->name ?? $gp->commodity_group_code ?? 'Ohne Warengruppe' }}</x-fa::badge>
                    @if(filled($gp->condition))<x-fa::badge data-fa-handy-gp-zustand>{{ $gp->condition }}</x-fa::badge>@endif
                </div>
            </header>

            {{-- Hauptartikel: der effektive Lead des Teams (wie am Computer), Preis je Einheit --}}
            <section class="flex flex-col gap-1.5" data-fa-handy-gp-lead>
                <h2 class="{{ $h2 }}">Hauptartikel</h2>
                @if($lead !== null)
                    <div class="{{ $karte }} flex items-start gap-3 px-3 py-2.5">
                        <a href="{{ route('foodalchemist.suppliers.index', ['artikel' => $lead->id]) }}" wire:navigate class="min-w-0 flex-1">
                            <p class="font-medium text-[var(--fa-ink)] wrap-anywhere">{{ $lead->designation }}</p>
                            <p class="{{ $leise }}">{{ $lead->supplier_name ?? 'Lieferant unbekannt' }}</p>
                        </a>
                        <div class="shrink-0 text-right">
                            @if($preisJe($lead) !== null)
                                <p class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]" data-fa-handy-gp-preis>{{ $preisJe($lead) }}</p>
                                @if($gebindePreis($lead) !== null)<p class="{{ $leise }} tabular-nums">{{ $gebindePreis($lead) }} je Gebinde</p>@endif
                            @elseif($gebindePreis($lead) !== null)
                                <p class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]" data-fa-handy-gp-preis>{{ $gebindePreis($lead) }}</p>
                                <p class="{{ $leise }}">ohne Preis je kg</p>
                            @else
                                <p class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-warn)]" data-fa-handy-gp-preis-fehlt>Preis fehlt</p>
                            @endif
                        </div>
                    </div>
                @elseif($gp->requires_la)
                    <p class="text-[var(--fa-warn)]" data-fa-handy-gp-preis-fehlt>Kein Artikel verknüpft. Preis fehlt.</p>
                @else
                    <p class="{{ $leise }}">Braucht keinen Lieferantenartikel.</p>
                @endif
            </section>

            {{-- Lieferantenartikel: kurze Liste in der Rangfolge des Teams --}}
            @if($kette->isNotEmpty())
                <section class="flex flex-col gap-1.5" data-fa-handy-gp-artikel="{{ $kette->count() }}">
                    <h2 class="{{ $h2 }}">{{ $kette->count() }} Lieferantenartikel</h2>
                    <ul class="fa-surface divide-y divide-[var(--fa-line)] overflow-hidden">
                        @foreach($kette->take(\Platform\FoodAlchemist\Livewire\Handy\GpAnsicht::ARTIKEL_MAX) as $la)
                            <li wire:key="hgp-la-{{ $la->id }}">
                                <a href="{{ route('foodalchemist.suppliers.index', ['artikel' => $la->id]) }}" wire:navigate class="flex items-center gap-3 px-4 min-h-[52px] py-2 {{ $la->locked ? 'opacity-60' : '' }}">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-[length:var(--fa-text-base)] text-[var(--fa-ink)] break-words">{{ $la->designation }}</span>
                                        <span class="block {{ $leise }} truncate">{{ $la->supplier_name ?? 'Lieferant unbekannt' }}{{ $la->locked ? ' · gesperrt' : '' }}</span>
                                    </span>
                                    <span class="shrink-0 text-right tabular-nums {{ ($preisJe($la) ?? $gebindePreis($la)) !== null ? 'text-[var(--fa-ink-2)]' : 'text-[var(--fa-warn)]' }}">{{ $preisJe($la) ?? $gebindePreis($la) ?? 'Preis fehlt' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if($kette->count() > \Platform\FoodAlchemist\Livewire\Handy\GpAnsicht::ARTIKEL_MAX)
                        <p class="{{ $leise }}">und {{ $kette->count() - \Platform\FoodAlchemist\Livewire\Handy\GpAnsicht::ARTIKEL_MAX }} weitere</p>
                    @endif
                </section>
            @endif

            <section class="space-y-1.5" data-fa-handy-gp-allergene>
                <h2 class="{{ $h2 }}">Allergene</h2>
                @if($enthalten->isNotEmpty())
                    <p><span class="font-medium text-[var(--fa-crit)]">Enthält:</span> <span class="text-[var(--fa-ink)]">{{ $enthalten->implode(', ') }}</span></p>
                @endif
                @if($spuren->isNotEmpty())
                    <p><span class="font-medium text-[var(--fa-warn)]">Spuren:</span> <span class="text-[var(--fa-ink)]">{{ $spuren->implode(', ') }}</span></p>
                @endif
                @if($unbekannt > 0)
                    <p class="text-[var(--fa-warn)]" data-fa-allergene-unbekannt>{{ $unbekannt }} von 14 ohne Angabe — nicht als frei werten.</p>
                @elseif($enthalten->isEmpty() && $spuren->isEmpty())
                    <p class="text-[var(--fa-ink-2)]">Keines der 14 Hauptallergene enthalten.</p>
                @endif
            </section>

            <section class="space-y-1.5" data-fa-handy-gp-anker>
                <h2 class="{{ $h2 }}">Kern-Anker</h2>
                @if($kernAnker->isEmpty())
                    <p class="{{ $leise }}">Noch kein Aroma-Anker.</p>
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($kernAnker as $a)
                            <x-fa::badge tone="accent" wire:key="hgp-anker-{{ $a->id }}">{{ $a->display_de }}</x-fa::badge>
                        @endforeach
                    </div>
                @endif
            </section>

            @if($geschmack !== null)
                <section class="space-y-1.5" data-fa-handy-gp-geschmack>
                    <h2 class="{{ $h2 }}">Geschmack</h2>
                    <div class="{{ $karte }} flex flex-col gap-2 px-3 py-2.5">
                        @foreach($geschmack as $achse => $wert)
                            <div class="flex items-center gap-3" wire:key="hgp-g-{{ $achse }}" data-fa-handy-gp-achse="{{ $achse }}">
                                <span class="w-16 shrink-0 {{ $leise }}">{{ \Platform\FoodAlchemist\Services\SensorikService::DIM_LABEL[$achse] ?? $achse }}</span>
                                <div class="flex-1 h-2 rounded-full bg-[var(--fa-line)] overflow-hidden"><div class="h-full rounded-full bg-[var(--fa-accent)]" style="width: {{ (int) round($wert * 100) }}%"></div></div>
                                <span class="w-10 shrink-0 text-right tabular-nums {{ $leise }}">{{ number_format($wert, 2, ',', '') }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    </div>
</div>
