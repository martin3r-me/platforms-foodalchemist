{{-- #1003 Artikel-Karte am Handy (Stufe 3 der Lieferanten): Titel → Preis → Allergene → Stammdaten. Nur lesen.
     Parameter: $a (FoodAlchemistSupplierItem aus HandyDetailService::artikel, mit aktiver_preis, vergleichspreis,
     allergen_werte). Allergene im 4-Wert-Modell: enthalten · Spuren · nicht enthalten · unbekannt (nie als „frei“ werten). --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $namen = \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE;
    $werte = collect($a->allergen_werte);
    $enthalten = $werte->filter(fn ($w) => $w === 'enthalten')->keys()->map(fn ($k) => $namen[$k] ?? $k);
    $spuren = $werte->filter(fn ($w) => $w === 'spuren')->keys()->map(fn ($k) => $namen[$k] ?? $k);
    $unbekannt = $werte->filter(fn ($w) => ! in_array($w, ['enthalten', 'spuren', 'nicht_enthalten'], true))->count();
    $menge = $a->qty !== null ? rtrim(rtrim(number_format((float) $a->qty, 3, ',', '.'), '0'), ',') . ' ' . ($a->unit_code ?? $a->ordering_unit ?? '') : null;
    $stamm = array_filter([
        'Lieferant' => $a->supplier?->name,
        'Art.-Nr.' => $a->article_number,
        'EAN' => $a->ean_packaging ?: $a->ean_ordering,
        'Gebinde' => $menge,
    ]);
@endphp
<article class="space-y-3" data-fa-handy-artikel-karte="{{ $a->id }}">
    <div>
        <h1 class="text-[length:var(--fa-text-2xl)] font-semibold leading-tight text-[var(--fa-ink)] wrap-anywhere">{{ $a->designation }}</h1>
        <div class="mt-1 flex flex-wrap gap-1.5">
            @if($a->is_discontinued)<x-fa::badge>Ausgelistet</x-fa::badge>
            @elseif($a->lieferstatusInfo())<x-fa::badge tone="warn">{{ $a->lieferstatusInfo()['kurz'] }}</x-fa::badge>
            @else<x-fa::badge tone="ok">Lieferbar</x-fa::badge>@endif
            @if($a->aktion_bis ?? null)<x-fa::badge tone="warn">Aktion bis {{ \Illuminate\Support\Carbon::parse($a->aktion_bis)->subSecond()->setTimezone('Europe/Berlin')->format('d.m.') }}</x-fa::badge>@endif
        </div>
    </div>

    <dl class="grid grid-cols-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-x divide-[var(--fa-line)]" data-fa-artikel-preis>
        <div class="flex flex-col gap-0.5 px-3 py-2.5 min-w-0">
            <dt class="{{ $leise }}">Preis netto</dt>
            <dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums {{ $a->aktiver_preis !== null ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-warn)]' }}">{{ $a->aktiver_preis !== null ? number_format((float) $a->aktiver_preis, 2, ',', '.') . ' €' : 'fehlt' }}</dd>
        </div>
        <div class="flex flex-col gap-0.5 px-3 py-2.5 min-w-0">
            <dt class="{{ $leise }}">Vergleichspreis</dt>
            <dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $a->vergleichspreis !== null ? number_format($a->vergleichspreis['value'], 2, ',', '.') . ' ' . $a->vergleichspreis['unit'] : '–' }}</dd>
        </div>
    </dl>

    <section class="space-y-1.5" data-fa-artikel-allergene>
        <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Allergene</h2>
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

    <section class="space-y-1.5">
        <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Grundprodukt</h2>
        @if($a->structure?->gp)
            <a href="{{ \Platform\FoodAlchemist\Support\Sprungziel::gp($a->structure->gp_id) }}" class="inline-flex items-center gap-1 min-h-11 text-[var(--fa-accent)]" data-fa-artikel-gp>
                {{ $a->structure->gp->name }} @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0')
            </a>
        @else
            <p class="{{ $leise }}">Keinem Grundprodukt zugeordnet.</p>
        @endif
    </section>

    @if($stamm !== [])
        <dl class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-4 py-2">
            @foreach($stamm as $label => $wert)
                <div class="flex items-baseline justify-between gap-3 py-1.5">
                    <dt class="{{ $leise }}">{{ $label }}</dt>
                    <dd class="min-w-0 text-right tabular-nums text-[var(--fa-ink)] wrap-anywhere">{{ $wert }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</article>
