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
                    <button type="button" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-rezept-bearbeiten>Bearbeiten</button>
                    <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-zutaten-bearbeiten>Zutaten</button>
                    <button type="button" wire:click="neuBerechnen" class="{{ $btnGhostXs }}" title="GL-02-Pipeline + Eltern-Propagation" data-recompute-btn>↻</button>
                    <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">{{ $rezept->kategorie?->bezeichnung ?? '—' }} · {{ $rezept->recipe_key }}</p>
        </div>

        {{-- KPI-Karte (EK/kg · EK · Yield · Konfidenz) --}}
        <div class="grid grid-cols-5 gap-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-kpi-karte>
            @foreach([
                ['EK/kg', $rezept->ek_per_kg_eur !== null ? number_format((float) $rezept->ek_per_kg_eur, 2, ',', '.') . ' €' : '—'],
                ['EK', $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—'],
                ['Yield', $rezept->yield_kg !== null ? number_format((float) ($rezept->yield_kg_manual ?? $rezept->yield_kg), 3, ',', '.') . ' kg' : '—'],
                ['Konfidenz', $rezept->allergene_konfidenz],
                ['mit Preis', ($rezept->ek_n_ingredients_priced ?? '—') . '/' . ($rezept->ek_n_ingredients_total ?? '—')],
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

        {{-- Screen-5: 14 EU-Allergene + 18 LMIV-Zusatzstoffe (Aggregat-Spalten, GL-01/09) --}}
        <div data-rezept-allergene>
            <p class="{{ $dt }} mb-1">Allergene <span class="normal-case">({{ $rezept->allergene_konfidenz }})</span></p>
            <div class="flex flex-wrap gap-1">
                @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $feld => $lbl)
                    @php($wert = $rezept->{"allergen_{$feld}"})
                    <span class="{{ $pill }} {{ ['enthalten' => $variantPill['danger'], 'spuren' => $variantPill['warning'], 'nicht_enthalten' => $variantPill['secondary']][$wert] ?? $variantPill['secondary'] . ' opacity-40 italic' }}"
                          title="{{ $wert }}">{{ explode(' ', $lbl)[0] }}</span>
                @endforeach
            </div>
        </div>
        @php($zusatzJa = collect(array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE))->filter(fn ($st) => (int) ($rezept->{"zusatz_{$st}"} ?? 0) === 3))
        @if($zusatzJa->isNotEmpty())
            <div data-rezept-zusatzstoffe>
                <p class="{{ $dt }} mb-1">Zusatzstoffe (LMIV)</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($zusatzJa as $stoff)
                        <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE[$stoff] }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- M4-10: ↑-Navigation — Rezepte, die dieses als Sub-Rezept nutzen --}}
        @if($eltern->isNotEmpty())
            <div data-eltern>
                <p class="{{ $dt }} mb-1">Verwendet in ({{ $eltern->count() }})</p>
                <div class="space-y-0.5">
                    @foreach($eltern as $parent)
                        <button type="button" wire:key="el-{{ $parent->id }}" wire:click="zeige({{ $parent->id }})"
                                class="block w-full text-left text-xs text-sky-600 dark:text-sky-400 hover:underline truncate" data-eltern-link>
                            ↑ {{ $parent->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

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

        {{-- M5-04: Kern-Anker (★-Chips, Cap 5, Verknüpfen-Flow) + Kohäsion lazy --}}
        <div data-kern-anker>
            <button type="button" wire:click="toggleSektion('anker')"
                    class="w-full flex items-center justify-between py-1 text-xs font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Kern-Anker ({{ $kernAnker->count() }}/5)</span>
                <span>{{ ($offen['anker'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            <div class="flex flex-wrap gap-1 mt-1">
                @foreach($kernAnker as $anker)
                    <span wire:key="ka-{{ $anker->id }}" class="{{ $pill }} {{ $variantPill['primary'] }} group" title="{{ $anker->quelle }}{{ $anker->ai_confidence !== null ? ' ' . round($anker->ai_confidence * 100) . '%' : '' }}">
                        ★ {{ $anker->display_de }}
                        <button type="button" wire:click="ankerLoesen({{ $anker->id }})" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                    </span>
                @endforeach
            </div>
            @if($offen['anker'] ?? false)
                @if($fehlerAnker !== null)<p class="text-xs text-rose-500 mt-1" data-anker-fehler>{{ $fehlerAnker }}</p>@endif
                <div class="relative mt-1.5">
                    <input type="search" wire:model.live.debounce.300ms="ankerSuche" placeholder="Anker verknüpfen …" class="{{ $input }} !py-1" data-anker-suche />
                    @foreach($ankerKandidaten as $kandidat)
                        <button type="button" wire:key="ak-{{ $kandidat->id }}" wire:click="ankerVerknuepfen({{ $kandidat->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-xs text-gray-700 dark:text-gray-200 hover:bg-violet-500/10">{{ $kandidat->display_de }} <span class="text-gray-400">{{ $kandidat->slug }}</span></button>
                    @endforeach
                </div>
                @if($kohaesion !== null)
                    <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 text-xs space-y-0.5" data-kohaesion>
                        <p class="text-gray-900 dark:text-gray-100">Aroma-Kohäsion: <span class="font-medium">{{ $kohaesion['score'] }}</span>
                            · min {{ $kohaesion['min_score'] }} · Coverage {{ $kohaesion['coverage_pct'] }} % ({{ $kohaesion['rated_pairs'] }}/{{ $kohaesion['total_pairs'] }})
                            @if($kohaesion['coverage_pct'] < 30)<span class="text-amber-500">· dünne Datenlage</span>@endif
                        </p>
                        @if($kohaesion['weakest_pair'] !== null)
                            <p class="text-gray-400">Schwächstes Glied: {{ $kohaesion['weakest_pair']['a'] }} ↔ {{ $kohaesion['weakest_pair']['b'] }} ({{ $kohaesion['weakest_pair']['score'] }}, {{ $kohaesion['weakest_pair']['typ'] }})</p>
                        @endif
                        @php($orphans = collect($kohaesion['komponenten'])->filter(fn ($k) => $k['is_orphan']))
                        @if($orphans->isNotEmpty())
                            <p class="text-amber-600 dark:text-amber-400">Ausreißer: {{ $orphans->pluck('label')->implode(', ') }}</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        {{-- M5-05: Pairing-Chips + verwandte Rezepte (lazy) --}}
        <div data-pairing-sektion>
            <button type="button" wire:click="toggleSektion('pairing')"
                    class="w-full flex items-center justify-between py-1 text-xs font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Pairings</span>
                <span>{{ ($offen['pairing'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if($pairings !== null)
                <div class="flex flex-wrap gap-1 mt-1" data-pairing-chips>
                    @foreach($pairings as $p)
                        <span wire:key="pp-{{ $loop->index }}" class="{{ $pill }} {{ ['klassisch' => $variantPill['success'], 'verbund' => $variantPill['info'], 'trinitas' => $variantPill['primary'], 'kontrast' => $variantPill['warning']][$p->typ] ?? $variantPill['secondary'] }}"
                              title="{{ $p->typ }} · {{ $p->konfidenz }}">{{ $p->display_de }}</span>
                    @endforeach
                </div>
                @if($verwandte->isNotEmpty())
                    <p class="{{ $dt }} mt-2 mb-1">Verwandte Rezepte</p>
                    <div class="space-y-0.5" data-verwandte>
                        @foreach($verwandte as $v)
                            <button type="button" wire:key="vw-{{ $v['recipe_id'] }}" wire:click="zeige({{ $v['recipe_id'] }})"
                                    class="block w-full text-left text-xs text-sky-600 dark:text-sky-400 hover:underline truncate"
                                    title="{{ implode(', ', $v['shared_slugs']) }}">{{ $v['name'] }} <span class="text-gray-400">· {{ $v['shared'] }} gemeinsam</span></button>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- M5-05-Nachtrag: Aroma-Nachbarn (Klassiker/Signature, % = Ø-Kantenstärke) --}}
        <div data-nachbarn-sektion>
            <button type="button" wire:click="toggleSektion('nachbarn')"
                    class="w-full flex items-center justify-between py-1 text-xs font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Aroma-Nachbarn</span>
                <span>{{ ($offen['nachbarn'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if($nachbarn !== null)
                @foreach(['klassiker' => 'Klassiker', 'signature' => 'Signature'] as $modus => $lbl)
                    @if(count($nachbarn[$modus]) > 0)
                        <p class="{{ $dt }} mt-1.5 mb-1">{{ $lbl }}</p>
                        <div class="flex flex-wrap gap-1" data-nachbarn-{{ $modus }}>
                            @foreach($nachbarn[$modus] as $n)
                                <span wire:key="nb-{{ $modus }}-{{ $n['anker_id'] }}" class="{{ $pill }} {{ $modus === 'klassiker' ? $variantPill['success'] : $variantPill['info'] }}"
                                      title="trifft {{ $n['cover'] }} Anker · Grad {{ $n['degree'] }}{{ $n['degree'] > 100 ? ' (Allrounder)' : '' }}">{{ $n['slug'] }} {{ $n['mean_w'] }} %</span>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>

        {{-- M4-12: Workflow-Aktionen --}}
        <div class="flex flex-wrap items-center gap-1.5 border-t border-black/5 dark:border-white/10 pt-2" data-workflow>
            @foreach(['draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigeben'] as $wert => $lbl)
                @if($rezept->status->value !== $wert)
                    <button type="button" wire:click="statusSetzen('{{ $wert }}')" class="{{ $btnGhostXs }}" data-status-btn="{{ $wert }}">→ {{ $lbl }}</button>
                @endif
            @endforeach
            <button type="button" wire:click="duplizieren" class="{{ $btnGhostXs }}" data-duplizieren-btn>Duplizieren</button>
            <button type="button" wire:click="templateToggle" class="{{ $btnGhostXs }} {{ $rezept->is_template ? 'text-violet-600 dark:text-violet-400' : '' }}" data-template-btn>
                {{ $rezept->is_template ? '★ Template' : 'Als Template' }}
            </button>
        </div>

        <p class="text-[11px] text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            Nährwerte {{ $rezept->nutri_kcal_per_100g !== null ? number_format((float) $rezept->nutri_kcal_per_100g, 0, ',', '.') . ' kcal/100 g (' . $rezept->nutri_konfidenz . ')' : '—' }}
            · v{{ $rezept->version }}{{ $rezept->arbeitszeit_min ? ' · ' . $rezept->arbeitszeit_min . ' min' : '' }}
        </p>
    @endif
</div>
