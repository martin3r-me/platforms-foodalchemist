{{-- R9 (Jarvis-Vorbild): GP-DetailPanel — ALLES direkt sichtbar (keine Klapp-Sektionen),
     ★ Lead direkt setzbar, Verwendungs-Liste (M9-05 GP-Blickwinkel). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04] dark:bg-white/[0.02]" data-gp-panel>
    @if($gp === null)
        <div class="text-center text-sm text-gray-400 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Grundprodukt in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $gp->name }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    @if($kannKuratieren)
                        <button type="button" wire:click="$dispatch('gp-modal.oeffnen', { id: {{ $gp->id }} })" class="{{ $btnGhostXs }}" data-gp-bearbeiten>Bearbeiten</button>
                    @endif
                    <span class="{{ $pill }} font-medium {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">{{ $gp->status->label() }}</span>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-0.5 font-mono">{{ $gp->hauptzutat_slug ?? '—' }}</p>
        </div>

        @if($fehler !== null)
            <p class="text-xs text-rose-600 dark:text-rose-400" data-la-fehler>{{ $fehler }}</p>
        @endif

        {{-- Stammdaten-Raster: Label + Wert je Zelle LINKS-bündig (R9: das alte rechtsbündige Raster wirkte zerrissen) --}}
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2" data-stammdaten>
            @foreach([
                ['Warengruppe', $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—'],
                ['Sub-Kategorie', $gp->sub_kategorie ?? '—'],
                ['Zustand', $gp->zustand ?? '—'],
                ['Zähleinheit', $gp->preferredCountUnit?->name ?? '—'],
                ['Garverlust-Default', $gp->garverlust_default_pct !== null ? number_format((float) $gp->garverlust_default_pct, 1, ',', '.') . ' %' : '—'],
                ['Stück-Gewicht', $gp->stk_default_g !== null ? number_format((float) $gp->stk_default_g, 0, ',', '.') . ' g' : '—'],
            ] as [$lbl, $wert])
                <div class="min-w-0">
                    <dt class="{{ $dt }}">{{ $lbl }}</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100 truncate" title="{{ $wert }}">{{ $wert }}</dd>
                </div>
            @endforeach
        </dl>

        @if($gp->is_derivat || $gp->is_platzhalter)
            <div class="flex gap-1.5">
                @if($gp->is_derivat)<span class="{{ $pill }} {{ $variantPill['info'] }}">Derivat{{ $gp->derivatVon ? ' von ' . $gp->derivatVon->name : '' }}</span>@endif
                @if($gp->is_platzhalter)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Platzhalter</span>@endif
            </div>
        @endif

        {{-- Eigenschaften-Tags (Jarvis: alle Tags, ja = grün, nein = durchgestrichen, unbewertet = gedimmt) --}}
        <div data-tags>
            <p class="{{ $dt }} mb-1">Eigenschaften</p>
            <div class="flex flex-wrap gap-1">
                @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag)
                    @php($wert = $gp->{$tag})
                    <span class="{{ $pill }} {{ $wert === true ? $variantPill['success'] : ($wert === false ? $variantPill['secondary'] . ' line-through' : $variantPill['secondary'] . ' opacity-40 italic') }}"
                          title="{{ $wert === null ? 'unbewertet' : ($wert ? 'ja' : 'nein') }}">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                @endforeach
            </div>
        </div>

        {{-- ★ VERKNÜPFTE LIEFERANTENARTIKEL — Lead per Stern-Klick (Jarvis), Aktionen beim Hover --}}
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="las">
            <p class="{{ $dt }} mb-1">Lieferantenartikel ({{ $gp->n_las_total }})</p>
            <div class="space-y-1">
                @forelse($kette ?? [] as $rang => $la)
                    <div wire:key="la-{{ $la->id }}"
                         class="group rounded-lg px-2 py-1.5 -mx-2 {{ $la->id === $effektiverLeadId ? 'bg-orange-500/10 border border-orange-500/30' : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }} {{ $la->gesperrt ? 'opacity-50' : '' }}"
                         data-la-zeile="{{ $la->id }}">
                        <div class="flex items-start gap-1.5">
                            {{-- R9: ★ direkt klickbar = globalen Lead setzen (vorher nur im Hover-Menü versteckt) --}}
                            <button type="button" wire:click="leadSetzen({{ $la->id }})"
                                    class="shrink-0 text-sm leading-5 transition-colors {{ $la->id === $effektiverLeadId ? 'text-orange-500' : 'text-gray-300 dark:text-gray-600 hover:text-orange-400' }}"
                                    title="{{ $la->id === $effektiverLeadId ? 'Effektiver Lead (Team-Sicht)' : 'Klick: als Lead setzen (GL-03, Kurations-Team)' }}" data-lead-stern>★</button>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $la->designation }}">{{ $la->designation }}</p>
                                <p class="text-[11px] text-gray-400 truncate">
                                    {{ $la->supplier_name ?? '—' }}
                                    @if($gp->lead_la_supplier_item_id === $la->id) · <span title="globaler Default-Lead (GL-03)">global ★</span>@endif
                                    {{ $la->ist_stamm ? '· Stamm' : '' }}
                                    @if($la->is_discontinued) · <span class="text-rose-400">ausgelistet</span>@endif
                                    @if($la->gepinnt) · <span class="text-violet-500">gepinnt</span>@endif
                                    @if($la->gesperrt) · <span class="text-rose-500">gesperrt</span>@endif
                                </p>
                            </div>
                            <span class="shrink-0 text-xs tabular-nums {{ $la->vergleichspreis !== null ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }}" data-la-preis>
                                @if($la->vergleichspreis !== null)
                                    {{ number_format($la->vergleichspreis['wert'], 2, ',', '.') }} {{ $la->vergleichspreis['einheit'] }}
                                @elseif($la->aktiver_preis !== null)
                                    <span title="Gebinde-Preis — kein Vergleichspreis, qty fehlt (GL-03 A-2)">{{ number_format((float) $la->aktiver_preis, 2, ',', '.') }} € ⚠</span>
                                @else
                                    —
                                @endif
                            </span>
                        </div>
                        <div class="hidden group-hover:flex items-center gap-1 mt-1 ml-5" data-la-aktionen>
                            <button type="button" wire:click="pinToggle({{ $la->id }}, {{ $la->gepinnt ? 'false' : 'true' }})" class="{{ $btnGhostXs }}">{{ $la->gepinnt ? 'Pin lösen' : 'Pinnen' }}</button>
                            <button type="button" wire:click="sperreToggle({{ $la->id }}, {{ $la->gesperrt ? 'false' : 'true' }})" class="{{ $btnGhostXs }}">{{ $la->gesperrt ? 'Entsperren' : 'Sperren' }}</button>
                            @if($kannKuratieren)
                                <button type="button" wire:click="loesen({{ $la->id }})" wire:confirm="LA vom GP lösen? War er Lead, wird sofort neu gewählt (GL-03 I4)." class="{{ $btnGhostXs }} text-rose-500">Lösen</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">Keine LAs verknüpft{{ !$gp->requires_la ? ' — bewusst LA-frei' : '' }}.</p>
                @endforelse

                @if($kannKuratieren)
                    <div class="pt-1" data-la-verknuepfen>
                        <input type="search" wire:model.live.debounce.300ms="laSuche"
                               placeholder="+ LA verknüpfen — Bezeichnung suchen …" class="{{ $input }} !py-1" />
                        @foreach($verknuepfbare as $kandidat)
                            <button type="button" wire:key="vk-{{ $kandidat->id }}" wire:click="verknuepfe({{ $kandidat->id }})"
                                    class="w-full text-left px-2 py-1 rounded text-xs text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors duration-150">
                                {{ $kandidat->designation }} <span class="text-gray-400">· {{ $kandidat->supplier_name ?? '—' }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ALLERGENE (effektiv, GL-01) — immer sichtbar --}}
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="allergene">
            <p class="{{ $dt }} mb-1">Allergene <span class="normal-case">(effektiv)</span>
                @if($allergenKonfidenz !== null)
                    <span class="ml-1 font-semibold normal-case {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$allergenKonfidenz['konfidenz']] ?? 'text-gray-400' }}">{{ strtoupper($allergenKonfidenz['konfidenz']) }}</span>
                    <span class="normal-case text-gray-400 font-normal"> · aus {{ $allergenKonfidenz['n_las_mit_daten'] }}/{{ $gp->n_las_total }} LAs</span>
                    @if($allergenKonfidenz['needs_review'])<span class="{{ $pill }} {{ $variantPill['danger'] }} ml-1" title="enthalten ↔ nicht_enthalten ohne spuren-Mittelweg: {{ implode(', ', $allergenKonfidenz['konflikt_felder']) }}">Review nötig</span>@endif
                @endif
            </p>
            @if($allergene !== null)
                <div class="grid grid-cols-2 gap-x-4 gap-y-0.5" data-allergen-grid>
                    @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $feld => $label)
                        @php($wert = $allergene[$feld]['wert']->value)
                        <span class="text-sm px-1.5 py-0.5 rounded {{ [
                                'enthalten' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 font-medium',
                                'spuren' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                'nicht_enthalten' => 'text-gray-400',
                            ][$wert] ?? 'text-gray-400 italic opacity-60' }}"
                              title="{{ $label }} — {{ str_replace('_', ' ', $wert) }}{{ $allergene[$feld]['quelle'] === 'override' ? ' · manueller Override' : ($allergene[$feld]['quelle'] === 'mutter' ? ' · live vom Mutter-GP' : '') }}">
                            {{ explode(' ', $label)[0] }}@if($allergene[$feld]['quelle'] === 'override') ✎@elseif($allergene[$feld]['quelle'] === 'mutter') ↑@endif
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ZUSATZSTOFFE (LMIV, GL-09) — immer sichtbar --}}
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="zusatzstoffe">
            <p class="{{ $dt }} mb-1">Zusatzstoffe <span class="normal-case">(LMIV, aggregiert aus LAs)</span></p>
            @if($zusatzstoffe !== null)
                @php($ja = collect($zusatzstoffe)->filter(fn ($v) => $v === 3))
                @if($ja->isNotEmpty())
                    <div class="flex flex-wrap gap-1 mb-1" data-zusatz-ja>
                        @foreach($ja as $stoff => $v)
                            <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE[$stoff] }}</span>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-gray-400">{{ $ja->count() }}× ja · {{ collect($zusatzstoffe)->filter(fn ($v) => $v === 1)->count() }}× explizit ohne · {{ collect($zusatzstoffe)->filter(fn ($v) => $v === 0 || $v === null)->count() }}× keine Angabe</p>
                @else
                    <p class="text-xs text-gray-400" data-zusatz-leer>— keine Zusatzstoff-Daten in verknüpften LAs —</p>
                @endif
            @endif
        </div>

        {{-- NÄHRWERTE (Ø je 100 g, GL-08) — immer sichtbar --}}
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="naehrwerte">
            <p class="{{ $dt }} mb-1">Nährwerte <span class="normal-case">(Ø aus LAs, je 100 g)</span></p>
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
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-xs text-gray-900 dark:text-gray-100 tabular-nums">
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
                <p class="text-xs text-gray-400" data-naehrwerte-leer>— keine Nährwert-Daten —</p>
            @endif
        </div>

        {{-- M9-05 (GP-Blickwinkel): Verwendung in Rezepten — Klick öffnet den Editor als Modal --}}
        <div class="border-t border-black/5 dark:border-white/10 pt-2" data-sektion="verwendungen">
            <p class="{{ $dt }} mb-1">Verwendet in Rezepten ({{ $verwendungen->count() }}{{ $verwendungen->count() === 30 ? '+' : '' }})</p>
            @forelse($verwendungen as $v)
                <button type="button" wire:key="verw-{{ $v->id }}"
                        wire:click="$dispatch('{{ $v->ist_verkaufsrezept ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $v->id }} })"
                        class="block w-full text-left text-xs text-sky-600 dark:text-sky-400 hover:underline truncate py-0.5" data-verwendung-link>
                    {{ $v->ist_verkaufsrezept ? '💶' : '📖' }} {{ $v->name }}
                </button>
            @empty
                <p class="text-xs text-gray-400" data-verwendungen-leer>— in keinem Rezept eingesetzt —</p>
            @endforelse
        </div>

        <p class="text-[11px] text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            UUID {{ $gp->uuid }}@if($gp->team_id === null) · global/BHG-kuratiert (D1)@endif
        </p>
    @endif
</div>
