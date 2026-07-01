{{-- R9 (Jarvis-Vorbild): GP-DetailPanel — ALLES direkt sichtbar (keine Klapp-Sektionen),
     ★ Lead direkt setzbar, Verwendungs-Liste (M9-05 GP-Blickwinkel). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="{{ $embedded ? 'space-y-4' : 'p-4 space-y-4 min-h-full bg-gray-500/[0.04] dark:bg-white/[0.02]' }}" data-gp-panel>
    @if($gp === null)
        <div class="text-center text-xs text-gray-400 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Grundprodukt in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        {{-- R12 (Jarvis): Kopf — Name + Status, slug in grauer Box, Aktionen als eigene Zeile darunter --}}
        @if($section === null)
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-[15px] font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $gp->name }}</h3>
                <span class="{{ $pill }} font-medium shrink-0 {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">{{ $gp->status->label() }}</span>
            </div>
            @if($gp->hauptzutat_slug !== null)
                <span class="inline-block mt-1.5 rounded-md bg-black/5 dark:bg-white/10 px-2 py-0.5 text-[11px] font-mono text-gray-500 dark:text-gray-400" data-gp-slug>{{ $gp->hauptzutat_slug }}</span>
            @endif
            @if($kannKuratieren)
                <div class="mt-2">
                    <button type="button" wire:click="$dispatch('gp-modal.oeffnen', { id: {{ $gp->id }} })" class="{{ $btnGhostXs }}" data-gp-bearbeiten>Bearbeiten</button>
                </div>
            @endif
        </div>
        @endif

        @if($fehler !== null)
            <p class="text-[11px] text-rose-600 dark:text-rose-400" data-la-fehler>{{ $fehler }}</p>
        @endif

        {{-- Stammdaten-Raster: Label + Wert je Zelle LINKS-bündig (R9: das alte rechtsbündige Raster wirkte zerrissen) --}}
        @if($section === null)
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2" data-stammdaten>
            @foreach([
                ['Warengruppe', $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—'],
                ['Sub-Kategorie', $gp->sub_kategorie ?? '—'],
                ['Zustand', $gp->zustand ?? '—'],
                ['Garverlust-Default', $gp->garverlust_default_pct !== null ? number_format((float) $gp->garverlust_default_pct, 1, ',', '.') . ' %' : '—'],
            ] as [$lbl, $wert])
                <div class="min-w-0">
                    <dt class="{{ $dt }}">{{ $lbl }}</dt>
                    <dd class="text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $wert }}">{{ $wert }}</dd>
                </div>
            @endforeach
        </dl>
        @endif

        {{-- R12 (Jarvis): «Natürliche Einheit & Gewicht» prominent statt zweier Raster-Zellen --}}
        @if($section === null || $section === 'naehrwerte')
        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-einheit-gewicht>
            <p class="{{ $dt }} mb-0.5">Natürliche Einheit &amp; Gewicht</p>
            @if($gp->preferredCountUnit !== null || $gp->stk_default_g !== null)
                <p class="text-xs text-gray-900 dark:text-gray-100">
                    1 {{ $gp->preferredCountUnit?->name ?? 'Stück' }}
                    @if($gp->stk_default_g !== null)
                        ≈ <span class="font-semibold">{{ number_format((float) $gp->stk_default_g, 0, ',', '.') }} g</span>
                    @else
                        <span class="text-gray-400">— Gewicht nicht hinterlegt</span>
                    @endif
                </p>
            @else
                <p class="text-xs text-gray-400">— keine Zähleinheit hinterlegt —</p>
            @endif
        </div>
        @endif

        @if($section === null && ($gp->is_derivat || $gp->is_platzhalter))
            <div class="flex gap-1.5">
                @if($gp->is_derivat)<span class="{{ $pill }} {{ $variantPill['info'] }}">Derivat{{ $gp->derivatVon ? ' von ' . $gp->derivatVon->name : '' }}</span>@endif
                @if($gp->is_platzhalter)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Platzhalter</span>@endif
            </div>
        @endif

        {{-- Eigenschaften-Tags (Jarvis: alle Tags, ja = grün, nein = durchgestrichen, unbewertet = gedimmt) --}}
        @if($section === null)
        <div data-tags>
            <p class="{{ $dt }} mb-1">Eigenschaften</p>
            <div class="flex flex-wrap gap-1">
                @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag)
                    @php($wert = $gp->getAttribute("tag_{$tag}"))
                    <span class="{{ $pill }} {{ $wert === true ? $variantPill['success'] : ($wert === false ? $variantPill['secondary'] . ' line-through' : $variantPill['secondary'] . ' opacity-40 italic') }}"
                          title="{{ $wert === null ? 'unbewertet' : ($wert ? 'ja' : 'nein') }}">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- ★ VERKNÜPFTE LIEFERANTENARTIKEL — R12: breite Jarvis-Karten (Lead orange, Preis groß rechts) --}}
        @if($section === null || $section === 'las')
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="las">
            <p class="{{ $dt }} mb-1.5 flex items-center">Lieferantenartikel ({{ $gp->n_las_total }})
                @if($kannKuratieren)
                    <button type="button" wire:click="laVorschlaege" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 ml-auto normal-case"
                            title="Unverknüpfte Artikel finden, die zum GP-Namen passen (deterministischer Token-Match)" data-la-ki-vorschlag>✨ KI-Vorschlag</button>
                @endif
            </p>

            {{-- Preis-Band (günstig → teuer) über die vergleichbaren LAs, Lead-Position markiert --}}
            @if(($preisBand ?? null) !== null && $preisBand['max'] > 0)
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-2.5 py-1.5 mb-2 text-[11px]" data-preis-band>
                    <div class="flex items-baseline justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Preis-Spanne <span class="text-gray-400">({{ $preisBand['n'] }} LA{{ $preisBand['n'] === 1 ? '' : 's' }})</span></span>
                        <span class="tabular-nums text-gray-900 dark:text-gray-100">
                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ number_format($preisBand['min'], 2, ',', '.') }}</span>
                            – {{ number_format($preisBand['max'], 2, ',', '.') }} {{ $preisBand['einheit'] }}
                        </span>
                    </div>
                    @if($preisBand['lead'] !== null && $preisBand['max'] > $preisBand['min'])
                        @php($pos = min(100, max(0, ($preisBand['lead'] - $preisBand['min']) / ($preisBand['max'] - $preisBand['min']) * 100)))
                        <div class="mt-1.5 flex items-center gap-2">
                            <div class="relative flex-1 h-1.5 rounded-full bg-gradient-to-r from-emerald-400/70 via-amber-400/70 to-rose-400/70">
                                <span class="absolute top-1/2 w-2.5 h-2.5 rounded-full bg-orange-500 ring-2 ring-white dark:ring-gray-900" style="left: {{ round($pos) }}%; transform: translate(-50%, -50%);" title="Lead im Preisband"></span>
                            </div>
                            <span class="text-orange-500 tabular-nums shrink-0">Lead {{ number_format($preisBand['lead'], 2, ',', '.') }}</span>
                        </div>
                    @endif
                </div>
            @endif

            <div class="space-y-1.5">
                @forelse($kette ?? [] as $rang => $la)
                    @php($istLead = $la->id === $effektiverLeadId)
                    <div wire:key="la-{{ $la->id }}"
                         class="group rounded-lg border px-2.5 py-2 {{ $istLead ? 'bg-orange-500/10 border-orange-500/40' : 'border-black/5 dark:border-white/10 hover:bg-black/[0.03] dark:hover:bg-white/5' }} {{ $la->gesperrt ? 'opacity-50' : '' }}"
                         data-la-zeile="{{ $la->id }}">
                        <div class="flex items-start gap-2">
                            {{-- R9: ★ direkt klickbar = globalen Lead setzen (vorher nur im Hover-Menü versteckt) --}}
                            <button type="button" wire:click="leadSetzen({{ $la->id }})"
                                    class="shrink-0 text-base leading-5 transition-colors {{ $istLead ? 'text-orange-500' : 'text-gray-300 dark:text-gray-600 hover:text-orange-400' }}"
                                    title="{{ $istLead ? 'Effektiver Lead (Team-Sicht)' : 'Klick: als Lead setzen (GL-03, Kurations-Team)' }}" data-lead-stern>★</button>
                            <div class="min-w-0 flex-1 cursor-pointer" wire:click="$dispatch('item-modal.oeffnen', { id: {{ $la->id }} })"
                                 title="Lieferantenartikel öffnen — Allergene/Preise dort pflegen" data-la-oeffnen>
                                <p class="text-xs font-medium text-gray-900 dark:text-gray-100 leading-snug hover:text-violet-600 dark:hover:text-violet-400 hover:underline" title="{{ $la->designation }}">{{ $la->designation }}</p>
                                <p class="text-[11px] text-gray-400 truncate mt-0.5">
                                    {{ $la->supplier_name ?? '—' }}
                                    @if($la->qty !== null && $la->unit_code !== null) · {{ rtrim(rtrim(number_format((float) $la->qty, 3, ',', '.'), '0'), ',') }} {{ $la->unit_code }}@endif
                                    @if($la->order_number !== null) · Art-Nr {{ $la->order_number }}@endif
                                </p>
                                @if($gp->lead_la_supplier_item_id === $la->id || $la->ist_stamm || $la->is_discontinued || $la->gepinnt || $la->gesperrt)
                                    <p class="text-[11px] truncate">
                                        @if($gp->lead_la_supplier_item_id === $la->id)<span class="text-orange-500" title="globaler Default-Lead (GL-03)">global ★</span>@endif
                                        {{ $la->ist_stamm ? ' · Stamm' : '' }}
                                        @if($la->is_discontinued) · <span class="text-rose-400">ausgelistet</span>@endif
                                        @if($la->gepinnt) · <span class="text-violet-500">gepinnt</span>@endif
                                        @if($la->gesperrt) · <span class="text-rose-500">gesperrt</span>@endif
                                    </p>
                                @endif
                            </div>
                            <div class="shrink-0 text-right" data-la-preis>
                                @if($la->aktiver_preis !== null)
                                    <p class="text-xs font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $la->aktiver_preis, 2, ',', '.') }} €
                                        @isset($preisTrend[$la->id])
                                            @if($preisTrend[$la->id]['plausibel'])
                                                @php($d = $preisTrend[$la->id]['delta_pct'])
                                                <span class="text-[10px] font-medium tabular-nums {{ $d > 0 ? 'text-rose-500' : ($d < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400') }}"
                                                      title="vorher {{ number_format($preisTrend[$la->id]['vorher'], 2, ',', '.') }} €">{{ $d > 0 ? '▲' : ($d < 0 ? '▼' : '') }}{{ number_format(abs($d), 1, ',', '.') }} %</span>
                                            @else
                                                <span class="text-[10px] text-amber-500 cursor-help" title="Vorpreis unplausibel ({{ number_format($preisTrend[$la->id]['vorher'], 2, ',', '.') }} € — vermutl. Platzhalter/Dummy). Preis-Historie prüfen." data-preis-trend-warnung>⚠</span>
                                            @endif
                                        @endisset
                                    </p>
                                    @if($la->vergleichspreis !== null)
                                        <p class="text-[11px] tabular-nums text-gray-400">{{ number_format($la->vergleichspreis['wert'], 2, ',', '.') }} {{ $la->vergleichspreis['einheit'] }}</p>
                                    @else
                                        <p class="text-[11px] text-gray-400" title="kein Vergleichspreis — qty fehlt (GL-03 A-2)">⚠ ohne €/kg</p>
                                    @endif
                                @else
                                    <p class="text-xs text-gray-400">—</p>
                                @endif
                            </div>
                        </div>
                        <div class="hidden group-hover:flex items-center gap-1 mt-1.5 ml-6" data-la-aktionen>
                            <button type="button" wire:click="pinToggle({{ $la->id }}, {{ $la->gepinnt ? 'false' : 'true' }})" class="{{ $btnGhostXs }}">{{ $la->gepinnt ? 'Pin lösen' : 'Pinnen' }}</button>
                            <button type="button" wire:click="sperreToggle({{ $la->id }}, {{ $la->gesperrt ? 'false' : 'true' }})" class="{{ $btnGhostXs }}">{{ $la->gesperrt ? 'Entsperren' : 'Sperren' }}</button>
                            @if($kannKuratieren)
                                <button type="button" wire:click="loesen({{ $la->id }})" wire:confirm="LA vom GP lösen? War er Lead, wird sofort neu gewählt (GL-03 I4)." class="{{ $btnGhostXs }} text-rose-500">✕ Lösen</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400">Keine LAs verknüpft{{ !$gp->requires_la ? ' — bewusst LA-frei' : '' }}.</p>
                @endforelse

                {{-- R12: ✨-Kandidaten — Klick = verknüpfen (GL-03 verknuepfen, Kurations-Gate in der Aktion) --}}
                @if($laKandidaten !== null)
                    <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-2.5 py-2" data-la-kandidaten>
                        <p class="text-[11px] text-gray-900 dark:text-gray-100 mb-1">✨ Passende unverknüpfte Artikel <span class="text-gray-400">· Klick = verknüpfen</span></p>
                        @foreach($laKandidaten as $kandidat)
                            <button type="button" wire:key="lakand-{{ $kandidat['id'] }}" wire:click="verknuepfe({{ $kandidat['id'] }})"
                                    class="w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/15 transition-colors duration-150 flex items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate">{{ $kandidat['designation'] }} <span class="text-gray-400">· {{ $kandidat['supplier'] ?? '—' }}</span></span>
                                <span class="shrink-0 tabular-nums text-violet-500">{{ round($kandidat['score'] * 100) }} %</span>
                            </button>
                        @endforeach
                        <button type="button" wire:click="laVorschlaegeVerwerfen" class="{{ $btnGhostXs }} mt-1" data-la-kandidaten-verwerfen>Verwerfen</button>
                    </div>
                @endif

                @if($kannKuratieren)
                    <div class="pt-1" data-la-verknuepfen>
                        <input type="search" wire:model.live.debounce.300ms="laSuche"
                               placeholder="+ LA verknüpfen — Bezeichnung suchen …" class="{{ $input }} !py-1" />
                        @foreach($verknuepfbare as $kandidat)
                            <button type="button" wire:key="vk-{{ $kandidat->id }}" wire:click="verknuepfe({{ $kandidat->id }})"
                                    class="w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors duration-150">
                                {{ $kandidat->designation }} <span class="text-gray-400">· {{ $kandidat->supplier_name ?? '—' }}</span>
                            </button>
                        @endforeach
                        @if($laSuche !== '' && $verknuepfbare->isEmpty())
                            <p class="text-[11px] text-gray-400 px-2 py-1" data-la-suche-leer>Kein ungemappter Artikel zu „{{ $laSuche }}" — ein LA gehört zu genau einem GP, evtl. ist er schon zugeordnet.</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- R10: GL-07-Vorschlags-Box für ✨-Schätzungen (Allergene/Nährwerte) — bei der passenden Kartei --}}
        @if($kiVorschlag !== null && ($section === null || $section === $kiVorschlag['typ']))
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs" data-gp-ki-vorschlag>
                <p class="text-gray-900 dark:text-gray-100">✨ {{ $kiVorschlag['typ'] === 'allergene' ? 'Allergen-Schätzung' : 'Nährwert-Schätzung' }}
                    <span class="text-[11px] text-gray-400">· {{ round($kiVorschlag['confidence'] * 100) }} % · schreibt als Override (GL-01 Prio 1)</span></p>
                <div class="flex flex-wrap gap-1 mt-1">
                    @foreach($kiVorschlag['werte'] as $feld => $wert)
                        <span class="{{ $pill }} {{ $kiVorschlag['typ'] === 'allergene' ? (['enthalten' => $variantPill['danger'], 'spuren' => $variantPill['warning'], 'nicht_enthalten' => $variantPill['secondary']][$wert] ?? $variantPill['secondary']) : $variantPill['info'] }}"
                              wire:key="kiw-{{ $feld }}">{{ $feld }}: {{ $wert }}</span>
                    @endforeach
                </div>
                <div class="flex gap-1.5 mt-1.5">
                    <button type="button" wire:click="kiUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-gp-ki-uebernehmen>Übernehmen</button>
                    <button type="button" wire:click="kiVerwerfen" class="{{ $btnGhostXs }}" data-gp-ki-verwerfen>Verwerfen</button>
                </div>
            </div>
        @endif

        {{-- ALLERGENE (effektiv, GL-01) — immer sichtbar --}}
        @if($section === null || $section === 'allergene')
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="allergene">
            <p class="{{ $dt }} mb-1 flex items-center gap-2">Allergene <span class="normal-case">(effektiv)</span>
                @if($allergenKonfidenz !== null)
                    <span class="ml-1 font-semibold normal-case {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$allergenKonfidenz['konfidenz']] ?? 'text-gray-400' }}">{{ strtoupper($allergenKonfidenz['konfidenz']) }}</span>
                    <span class="normal-case text-gray-400 font-normal"> · aus {{ $allergenKonfidenz['n_las_mit_daten'] }}/{{ $gp->n_las_total }} LAs</span>
                    @if($allergenKonfidenz['needs_review'])<span class="{{ $pill }} {{ $variantPill['danger'] }} ml-1" title="enthalten ↔ nicht_enthalten ohne spuren-Mittelweg: {{ implode(', ', $allergenKonfidenz['konflikt_felder']) }}">Review nötig</span>@endif
                @endif
                @if($kannKuratieren && ($allergenKonfidenz['n_las_mit_daten'] ?? 0) === 0)
                    <button type="button" wire:click="kiAllergene" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 ml-auto normal-case"
                            title="Ist-Feature: ohne LA-Daten per KI schätzen — Vorschlag, Übernehmen schreibt Override (GL-01)" data-ki-allergene>✨ per KI schätzen</button>
                @endif
            </p>
            @if($allergene !== null)
                {{-- „Zeig was drin ist": nur enthalten/spuren/unbekannt als Badges, Rest als Frei-Zusammenfassung
                     (statt 14 grauer Zeilen). Quelle-Marker: ✎ Override, ↑ live vom Mutter-GP. --}}
                @php($labels = \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE)
                @php($marker = fn ($q) => $q === 'override' ? ' ✎' : ($q === 'mutter' ? ' ↑' : ''))
                @php($enthalten = collect($allergene)->filter(fn ($a) => $a['wert']->value === 'enthalten'))
                @php($spuren = collect($allergene)->filter(fn ($a) => $a['wert']->value === 'spuren'))
                @php($unbekannt = collect($allergene)->filter(fn ($a) => $a['wert']->value === 'unbekannt'))
                @php($freiAnzahl = collect($allergene)->filter(fn ($a) => $a['wert']->value === 'nicht_enthalten')->count())
                @if($enthalten->isEmpty() && $spuren->isEmpty() && $unbekannt->isEmpty())
                    <p class="text-xs text-emerald-600 dark:text-emerald-400" data-allergene-frei>✓ Keine der 14 EU-Allergene deklariert.</p>
                @else
                    <div class="space-y-1.5" data-allergen-grid>
                        @if($enthalten->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="text-[11px] font-medium text-rose-600 dark:text-rose-400 mr-1 shrink-0">Enthält:</span>
                                @foreach($enthalten as $feld => $a)
                                    <span class="{{ $pill }} {{ $variantPill['danger'] }}" title="{{ $labels[$feld] ?? $feld }}{{ $a['quelle'] === 'override' ? ' · manueller Override' : ($a['quelle'] === 'mutter' ? ' · live vom Mutter-GP' : '') }}">{{ $labels[$feld] ?? $feld }}{{ $marker($a['quelle']) }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if($spuren->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="text-[11px] font-medium text-amber-600 dark:text-amber-400 mr-1 shrink-0">Spuren von:</span>
                                @foreach($spuren as $feld => $a)
                                    <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="{{ $labels[$feld] ?? $feld }}{{ $marker($a['quelle']) === ' ✎' ? ' · manueller Override' : '' }}">{{ $labels[$feld] ?? $feld }}{{ $marker($a['quelle']) }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if($unbekannt->isNotEmpty())
                            <p class="text-[11px] text-amber-600 dark:text-amber-400" data-allergene-unbekannt
                               title="Keine LA-Angabe für diese Allergene — LMIV: unbekannt ist NICHT gleich frei von. Per KI schätzen oder LA-Daten ergänzen.">⚠ {{ $unbekannt->count() }} von 14 ohne LA-Angabe (unbekannt)</p>
                        @endif
                        @if($freiAnzahl > 0)
                            <p class="text-[11px] text-gray-400">✓ frei von den übrigen {{ $freiAnzahl }} EU-Allergen{{ $freiAnzahl === 1 ? '' : 'en' }}</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>
        @endif

        {{-- ZUSATZSTOFFE (LMIV, GL-09) — immer sichtbar --}}
        @if($section === null || $section === 'zusatzstoffe')
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="zusatzstoffe">
            <p class="{{ $dt }} mb-1">Zusatzstoffe <span class="normal-case">(LMIV, aggregiert aus LAs)</span></p>
            @if($zusatzstoffe !== null)
                {{-- „Zeig was drin ist" wie bei Allergenen: enthaltene Stoffe vorne, fehlende Angaben als ⚠. --}}
                @php($stoffLabels = \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE)
                @php($zsJa = collect($zusatzstoffe)->filter(fn ($v) => $v === 3))
                @php($zsUnbekannt = collect($zusatzstoffe)->filter(fn ($v) => $v === 0 || $v === null))
                @php($zsFrei = collect($zusatzstoffe)->filter(fn ($v) => $v === 1)->count())
                @if($zsJa->isEmpty() && $zsUnbekannt->isEmpty())
                    <p class="text-xs text-emerald-600 dark:text-emerald-400" data-zusatz-frei>✓ Keine Zusatzstoffe deklariert.</p>
                @else
                    <div class="space-y-1.5">
                        @if($zsJa->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1" data-zusatz-ja>
                                <span class="text-[11px] font-medium text-amber-600 dark:text-amber-400 mr-1 shrink-0">Enthält:</span>
                                @foreach($zsJa as $stoff => $v)
                                    <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $stoffLabels[$stoff] ?? $stoff }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if($zsUnbekannt->isNotEmpty())
                            <p class="text-[11px] text-amber-600 dark:text-amber-400" data-zusatz-unbekannt
                               title="Keine LA-Angabe zu diesen Zusatzstoffen — nicht als frei werten.">⚠ {{ $zsUnbekannt->count() }} ohne LA-Angabe</p>
                        @endif
                        @if($zsFrei > 0)
                            <p class="text-[11px] text-gray-400">✓ frei von {{ $zsFrei }} weiteren</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>
        @endif

        {{-- NÄHRWERTE (Ø je 100 g, GL-08) — immer sichtbar --}}
        @if($section === null || $section === 'naehrwerte')
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="naehrwerte">
            <p class="{{ $dt }} mb-1 flex items-center gap-2">Nährwerte
                <span class="normal-case">({{ ($naehrwerte['quelle'] ?? 'la') === 'la' ? 'Ø aus LAs, je 100 g' : (($naehrwerte['quelle'] ?? '') === 'ki' ? 'KI-Schätzung je 100 g' : 'je 100 g') }})</span>
                @if(($naehrwerte['quelle'] ?? null) === 'ki')
                    <span class="{{ $pill }} {{ $variantPill['info'] }} normal-case" title="KI-geschätzt — keine LA-Daten{{ $gp->nutri_ai_confidence !== null ? ' · ' . round($gp->nutri_ai_confidence * 100) . ' %' : '' }}" data-naehrwerte-ki-marker>✨ KI</span>
                @endif
                @if($kannKuratieren && $naehrwerte !== null && $naehrwerte['energy_kcal']['avg'] === null)
                    <button type="button" wire:click="kiNaehrwerte" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 ml-auto normal-case"
                            title="Ist-Feature: ohne LA-Daten per KI schätzen (nur Panel-Anzeige — fließt NICHT in Rezept-Nährwerte)" data-ki-naehrwerte>✨ per KI schätzen</button>
                @endif
            </p>
            @if($naehrwerte !== null && $naehrwerte['energy_kcal']['avg'] !== null)
                <dl class="space-y-0.5">
                    @foreach([
                        ['energy_kcal', 'Energie', 'kcal', 1],
                        ['protein', 'Eiweiß', 'g', 2],
                        ['fat', 'Fett', 'g', 2],
                        ['carbs_absorbable', 'Kohlenhydrate', 'g', 2],
                        ['salt_g', 'Salz', 'g', 3],
                    ] as [$key, $label, $einheit, $stellen])
                        <div class="flex items-baseline justify-between">
                            <dt class="text-[11px] text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-[11px] text-gray-900 dark:text-gray-100 tabular-nums">
                                @if($naehrwerte[$key]['avg'] !== null)
                                    {{ number_format($naehrwerte[$key]['avg'], $stellen, ',', '.') }} {{ $einheit }} <span class="text-gray-400">({{ $naehrwerte[$key]['n'] }} LA{{ $naehrwerte[$key]['n'] === 1 ? '' : 's' }})</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="text-[11px] text-gray-400" data-naehrwerte-leer>— keine Nährwert-Daten —</p>
            @endif
        </div>
        @endif

        {{-- Ersatz-Logik: make-or-buy / Artikel-Ersatz — Äquivalenz-Katalog am GP --}}
        @if($section === null || $section === 'ersatz')
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="ersatz">
            <p class="{{ $dt }} mb-1">Ersatz-Produkte <span class="normal-case">(make-or-buy · Artikel-Ersatz)</span></p>
            <div class="space-y-1">
                @forelse($ersatz as $e)
                    <div class="flex items-center gap-2 text-[11px]" wire:key="equiv-{{ $e->id }}">
                        <span class="{{ $pill }} {{ $e->gegen_kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }} shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" title="{{ $e->gegen_name }}">{{ $e->gegen_name }}</span>
                        @if((float) $e->umrechnungsfaktor !== 1.0)<span class="text-gray-400 tabular-nums shrink-0">×{{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                        @if($kannKuratieren)<button type="button" wire:click="ersatzLoesen({{ $e->id }})" class="{{ $btnGhostXs }} text-rose-500 shrink-0" title="Ersatz-Verknüpfung lösen">✕</button>@endif
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400" data-ersatz-leer>— kein Ersatz hinterlegt —</p>
                @endforelse

                @if($kannKuratieren)
                    <div class="pt-1" data-ersatz-verknuepfen>
                        <input type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="+ Ersatz verknüpfen — GP/Rezept suchen …" class="{{ $input }} !py-1" />
                        @foreach($ersatzKandidaten as $k)
                            <button type="button" wire:key="ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})"
                                    class="w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors duration-150 flex items-center gap-1.5">
                                <span class="{{ $pill }} {{ $k->kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $k->kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- M9-05 (GP-Blickwinkel): Verwendung in Rezepten — Klick öffnet den Editor als Modal --}}
        @if($section === null || $section === 'las')
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="verwendungen">
            <p class="{{ $dt }} mb-1">Verwendet in Rezepten ({{ $verwendungen->count() }}{{ $verwendungen->count() === 30 ? '+' : '' }})</p>
            @forelse($verwendungen as $v)
                <button type="button" wire:key="verw-{{ $v->id }}"
                        wire:click="$dispatch('{{ $v->ist_verkaufsrezept ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $v->id }} })"
                        class="block w-full text-left text-[11px] text-sky-600 dark:text-sky-400 hover:underline truncate py-0.5" data-verwendung-link>
                    {{ $v->ist_verkaufsrezept ? '💶' : '📖' }} {{ $v->name }}
                </button>
            @empty
                <p class="text-[11px] text-gray-400" data-verwendungen-leer>— in keinem Rezept eingesetzt —</p>
            @endforelse
        </div>
        @endif

        @if($section === null)
        <p class="text-[11px] text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            UUID {{ $gp->uuid }}@if($gp->team_id === null) · global/BHG-kuratiert (D1)@endif
        </p>
        @endif
    @endif
</div>
