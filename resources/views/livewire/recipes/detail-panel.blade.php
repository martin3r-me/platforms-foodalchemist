{{-- M4-05: Rezept-DetailPanel — KPI-Karte, Beschreibung, Zutaten read-only mit GP-Links + EK je Zeile, Diät-Sektion, Eignungen, Equipment --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4" data-rezept-panel>
    @if($rezept === null)
        <div class="text-center text-sm text-gray-400 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Rezept in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $rezept->name }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" wire:click="neuBerechnen" class="{{ $btnGhostXs }}" title="GL-02-Pipeline + Eltern-Propagation" data-recompute-btn>↻</button>
                    <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">{{ $rezept->kategorie?->bezeichnung ?? '—' }} · {{ $rezept->recipe_key }}</p>
        </div>

        {{-- KPI-Karte (EK/kg · EK · Yield · Konfidenz) --}}
        <div class="grid grid-cols-4 gap-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-kpi-karte>
            @foreach([
                ['EK/kg', $rezept->ek_per_kg_eur !== null ? number_format((float) $rezept->ek_per_kg_eur, 2, ',', '.') . ' €' : '—'],
                ['EK', $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—'],
                ['Yield', $rezept->yield_kg !== null ? number_format((float) ($rezept->yield_kg_manual ?? $rezept->yield_kg), 3, ',', '.') . ' kg' : '—'],
                ['Konfidenz', $rezept->allergene_konfidenz],
            ] as [$lbl, $wert])
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider text-gray-400">{{ $lbl }}</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $wert }}</p>
                </div>
            @endforeach
        </div>
        @if($rezept->yield_kg_manual !== null)
            <p class="text-[11px] text-amber-600 dark:text-amber-400 -mt-2">Yield manuell überschrieben (Auto: {{ number_format((float) $rezept->yield_kg, 3, ',', '.') }} kg)</p>
        @endif

        @if($rezept->beschreibung)
            <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed" data-beschreibung>{{ $rezept->beschreibung }}</p>
        @endif

        {{-- Zutaten read-only: GP-Links (Kontext-Erhalt: ?gp=), Lineage kursiv, EK je Zeile --}}
        <div data-zutaten>
            <p class="{{ $dt }} mb-1">Zutaten ({{ $rezept->ingredients->count() }})</p>
            <div class="space-y-0.5">
                @foreach($rezept->ingredients as $z)
                    <div wire:key="z-{{ $z->id }}" class="flex items-baseline gap-2 text-xs py-0.5 border-b border-black/5 dark:border-white/5 last:border-0 {{ $z->is_optional ? 'opacity-60' : '' }}">
                        <span class="text-gray-500 tabular-nums shrink-0 w-20 text-right">{{ rtrim(rtrim(number_format((float) $z->menge, 2, ',', '.'), '0'), ',') }}{{ $z->menge_max !== null ? '–' . rtrim(rtrim(number_format((float) $z->menge_max, 2, ',', '.'), '0'), ',') : '' }} {{ $z->einheit?->slug }}</span>
                        <span class="min-w-0 flex-1">
                            @if($z->gp !== null)
                                <a href="{{ route('foodalchemist.gps.index', ['gp' => $z->gp_id]) }}" class="text-violet-600 dark:text-violet-400 hover:underline">{{ $z->gp->name }}</a>
                            @elseif($z->referencedRecipe !== null)
                                <button type="button" wire:click="zeige({{ $z->referenced_recipe_id }})" class="text-sky-600 dark:text-sky-400 hover:underline" title="Sub-Rezept">↳ {{ $z->referencedRecipe->name }}</button>
                            @else
                                <span class="text-gray-400" title="ungemappt">{{ $z->display_name ?? $z->raw_text }}</span>
                            @endif
                            <span class="block text-[10px] text-gray-400 italic truncate" title="{{ $z->raw_text }}">{{ $z->raw_text }}</span>
                        </span>
                        <span class="shrink-0 tabular-nums {{ isset($zeilenEk[$z->id]) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }}" data-zeilen-ek>
                            {{ isset($zeilenEk[$z->id]) ? number_format($zeilenEk[$z->id], 2, ',', '.') . ' €' : '—' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Diät & Spezifikation (Nachtrag 13_REFERENZ: ✓-Liste aus spec_*) --}}
        <div data-diaet>
            <p class="{{ $dt }} mb-1">Diät & Spezifikation</p>
            <div class="flex flex-wrap gap-1">
                @foreach([
                    'spec_is_vegan' => 'Vegan', 'spec_is_vegetarian' => 'Vegetarisch', 'spec_is_halal' => 'Halal',
                    'spec_is_gluten_free' => 'Glutenfrei', 'spec_is_lactose_free' => 'Laktosefrei',
                    'spec_contains_pork' => 'enth. Schwein', 'spec_contains_beef' => 'enth. Rind',
                ] as $feld => $lbl)
                    @php($wert = $rezept->{$feld})
                    <span class="{{ $pill }} {{ $wert === true ? (str_starts_with($feld, 'spec_contains') ? $variantPill['warning'] : $variantPill['success']) : ($wert === false ? $variantPill['secondary'] : $variantPill['secondary'] . ' opacity-50') }}"
                          title="{{ $wert === null ? 'unbewertet' : ($wert ? 'ja' : 'nein') }}">{{ $wert === true ? '✓ ' : ($wert === false ? '✕ ' : '? ') }}{{ $lbl }}</span>
                @endforeach
            </div>
        </div>

        {{-- Eignungen + Equipment --}}
        @if($rezept->niveauEignungen->isNotEmpty() || $rezept->sektorEignungen->isNotEmpty())
            <div data-eignungen>
                <p class="{{ $dt }} mb-1">Eignung</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($rezept->niveauEignungen as $e)
                        <span class="{{ $pill }} {{ $variantPill['info'] }}" title="Niveau · {{ $e->quelle }}{{ $e->ai_confidence !== null ? ' ' . round($e->ai_confidence * 100) . '%' : '' }}">{{ $e->niveau_slug }}</span>
                    @endforeach
                    @foreach($rezept->sektorEignungen as $e)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="Sektor · {{ $e->quelle }}">{{ $e->sektor_slug }}</span>
                    @endforeach
                </div>
            </div>
        @endif
        @if($rezept->equipment->isNotEmpty())
            <div data-equipment>
                <p class="{{ $dt }} mb-1">Equipment</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($rezept->equipment as $geraet)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $geraet->name }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <p class="text-[11px] text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            Nährwerte {{ $rezept->nutri_kcal_per_100g !== null ? number_format((float) $rezept->nutri_kcal_per_100g, 0, ',', '.') . ' kcal/100 g (' . $rezept->nutri_konfidenz . ')' : '—' }}
            · v{{ $rezept->version }}{{ $rezept->arbeitszeit_min ? ' · ' . $rezept->arbeitszeit_min . ' min' : '' }}
        </p>
    @endif
</div>
