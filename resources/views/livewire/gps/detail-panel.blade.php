{{-- M3-03: GP-DetailPanel (rechte Page-Sidebar, P-1) — Stammdaten-Sektion; Allergene/Tags/LAs folgen M3-05/07 --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4" data-gp-panel>
    @if($gp === null)
        <div class="text-center text-sm text-gray-400 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Grundprodukt in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $gp->name }}</h3>
                <span class="{{ $pill }} font-medium shrink-0 {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">{{ $gp->status->label() }}</span>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">{{ $gp->hauptzutat_slug ?? '—' }}</p>
        </div>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-2" data-stammdaten>
            <div><dt class="{{ $dt }}">Warengruppe</dt><dd class="{{ $dd }}">{{ $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Sub-Kategorie</dt><dd class="{{ $dd }}">{{ $gp->sub_kategorie ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Zustand</dt><dd class="{{ $dd }}">{{ $gp->zustand ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Lead-LA</dt><dd class="{{ $dd }} truncate" title="{{ $gp->leadLa?->name }}">{{ $gp->leadLa?->name ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">LAs verknüpft</dt><dd class="{{ $dd }}">{{ $gp->n_las_total }}@if(!$gp->requires_la && $gp->n_las_total === 0) <span class="text-gray-400">(bewusst LA-frei)</span>@endif</dd></div>
            <div><dt class="{{ $dt }}">Zähleinheit</dt><dd class="{{ $dd }}">{{ $gp->preferredCountUnit?->name ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Garverlust-Default</dt><dd class="{{ $dd }}">{{ $gp->garverlust_default_pct !== null ? number_format((float) $gp->garverlust_default_pct, 1, ',', '.') . ' %' : '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Stück-Gewicht</dt><dd class="{{ $dd }}">{{ $gp->stk_default_g !== null ? number_format((float) $gp->stk_default_g, 0, ',', '.') . ' g' : '—' }}</dd></div>
        </dl>

        @if($gp->is_derivat || $gp->is_platzhalter)
            <div class="flex gap-1.5">
                @if($gp->is_derivat)<span class="{{ $pill }} {{ $variantPill['info'] }}">Derivat{{ $gp->derivatVon ? ' von ' . $gp->derivatVon->name : '' }}</span>@endif
                @if($gp->is_platzhalter)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Platzhalter</span>@endif
            </div>
        @endif

        {{-- M3-05: Lazy-Sektionen — erst beim Aufklappen gerechnet (GpAggregateService) --}}
        <div class="border-t border-black/5 dark:border-white/10 -mx-4 px-4 pt-2 space-y-1" data-panel-sektionen>

            {{-- Allergene (GL-01: Override > Mutter > LA-MAX) --}}
            <div data-sektion="allergene">
                <button type="button" wire:click="toggleSektion('allergene')"
                        class="w-full flex items-center justify-between py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150">
                    <span>Allergene <span class="font-normal text-gray-400">(effektiv)</span></span>
                    <span class="text-gray-400 text-xs">{{ ($offen['allergene'] ?? false) ? '▾' : '▸' }}</span>
                </button>
                @if($allergene !== null)
                    <div class="pb-2 space-y-1.5">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="{{ $pill }} {{ ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'none' => $variantPill['secondary']][$allergenKonfidenz['konfidenz']] }}">Konfidenz {{ strtoupper($allergenKonfidenz['konfidenz']) }}</span>
                            <span class="text-[11px] text-gray-400">aggregiert aus {{ $allergenKonfidenz['n_las_mit_daten'] }}/{{ $gp->n_las_total }} LAs</span>
                            @if($allergenKonfidenz['needs_review'])
                                <span class="{{ $pill }} {{ $variantPill['danger'] }}" title="enthalten ↔ nicht_enthalten ohne spuren-Mittelweg: {{ implode(', ', $allergenKonfidenz['konflikt_felder']) }}">Review nötig</span>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 gap-x-3 gap-y-0.5">
                            @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $feld => $label)
                                <div class="flex items-center justify-between gap-1 min-w-0">
                                    <span class="text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $label }}{{ $allergene[$feld]['quelle'] === 'override' ? ' — manueller Override' : ($allergene[$feld]['quelle'] === 'mutter' ? ' — live vom Mutter-GP' : '') }}">
                                        {{ $label }}@if($allergene[$feld]['quelle'] === 'override')<span class="text-violet-500"> ✎</span>@elseif($allergene[$feld]['quelle'] === 'mutter')<span class="text-sky-500"> ↑</span>@endif
                                    </span>
                                    <span class="{{ $pill }} shrink-0 {{ ['enthalten' => $variantPill['danger'], 'spuren' => $variantPill['warning'], 'nicht_enthalten' => $variantPill['success'], 'unbekannt' => $variantPill['secondary']][$allergene[$feld]['wert']->value] }}">{{ $allergene[$feld]['wert']->label() }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Zusatzstoffe (GL-09: LMIV-Pills, Roh-Domäne 3=ja/1=nein/0=k.A./NULL) --}}
            <div data-sektion="zusatzstoffe">
                <button type="button" wire:click="toggleSektion('zusatzstoffe')"
                        class="w-full flex items-center justify-between py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150">
                    <span>Zusatzstoffe <span class="font-normal text-gray-400">(LMIV)</span></span>
                    <span class="text-gray-400 text-xs">{{ ($offen['zusatzstoffe'] ?? false) ? '▾' : '▸' }}</span>
                </button>
                @if($zusatzstoffe !== null)
                    <div class="pb-2">
                        @php($ja = collect($zusatzstoffe)->filter(fn ($v) => $v === 3))
                        @if($ja->isNotEmpty())
                            <div class="flex flex-wrap gap-1 mb-1.5">
                                @foreach($ja as $stoff => $v)
                                    <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE[$stoff] }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-[11px] text-gray-400">
                            {{ $ja->count() }}× ja · {{ collect($zusatzstoffe)->filter(fn ($v) => $v === 1)->count() }}× explizit ohne ·
                            {{ collect($zusatzstoffe)->filter(fn ($v) => $v === 0 || $v === null)->count() }}× keine Angabe
                        </p>
                    </div>
                @endif
            </div>

            {{-- Nährwerte (GL-08 GP-Pfad: Ø je 100 g über aktive LAs) --}}
            <div data-sektion="naehrwerte">
                <button type="button" wire:click="toggleSektion('naehrwerte')"
                        class="w-full flex items-center justify-between py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150">
                    <span>Nährwerte <span class="font-normal text-gray-400">(Ø je 100 g)</span></span>
                    <span class="text-gray-400 text-xs">{{ ($offen['naehrwerte'] ?? false) ? '▾' : '▸' }}</span>
                </button>
                @if($naehrwerte !== null)
                    <div class="pb-2">
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
                                            {{ number_format($naehrwerte[$key]['avg'], $stellen, ',', '.') }} {{ $einheit }}
                                            <span class="text-gray-400">({{ $naehrwerte[$key]['n'] }} LA{{ $naehrwerte[$key]['n'] === 1 ? '' : 's' }})</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        @if($naehrwerte['salt_g']['avg'] !== null)
                            <p class="text-[11px] text-gray-400 mt-1">Salz = Natrium × 2,5 (GL-08)</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if(count($gp->setTags()) > 0)
            <div data-tags>
                <p class="{{ $dt }} mb-1">Eigenschaften</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($gp->setTags() as $tag => $aktiv)
                        <span class="{{ $pill }} {{ $aktiv ? $variantPill['success'] : $variantPill['secondary'] }}">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <p class="text-[11px] text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            UUID {{ $gp->uuid }}@if($gp->team_id === null) · global/BHG-kuratiert (D1)@endif
        </p>
    @endif
</div>
