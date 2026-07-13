<div class="max-w-7xl mx-auto px-4 py-6">
    @php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
    @php($wfarbe = fn ($w) => $w === null ? 'secondary' : ($w <= 30 ? 'success' : ($w <= 35 ? 'warning' : 'danger')))
    @php($dietLabel = ['fleisch' => 'Fleisch', 'fisch' => 'Fisch', 'vegi' => 'Vegetarisch', 'vegan' => 'Vegan', 'neutral' => 'Neutral', 'allergie' => 'Allergiker'])
    @php($dietFarbe = ['vegan' => 'success', 'vegi' => 'success', 'fisch' => 'info', 'fleisch' => 'secondary', 'neutral' => 'secondary', 'allergie' => 'warning'])
    @php($allergenLabel = ['gluten' => 'Gluten', 'crustaceans' => 'Krebstiere', 'eggs' => 'Eier', 'fish' => 'Fisch', 'peanuts' => 'Erdnüsse', 'soy' => 'Soja', 'milk' => 'Milch', 'tree_nuts' => 'Schalenfrüchte', 'celery' => 'Sellerie', 'mustard' => 'Senf', 'sesame' => 'Sesam', 'sulphites' => 'Sulfite', 'lupin' => 'Lupinen', 'molluscs' => 'Weichtiere'])
    @php($trefferGesamt = collect($kapitel)->sum('n_treffer'))

    {{-- ── Kopf: Titel · Pax · Gesamt-KPIs · Sprünge ────────────────────────── --}}
    <div class="relative overflow-hidden {{ $card }} mb-4">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="{{ $label }}">Foodbook · interne Ansicht</div>
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $fb->label }}</h1>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ count($kapitel) }} Kapitel{{ $fb->jahr ? ' · ' . $fb->jahr : '' }} · Live-Preise aus dem Resolver
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('foodalchemist.foodbooks.index', ['fb' => $fb->id]) }}" class="{{ $btnGhostXs }}">✎ Editor</a>
                    <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}" class="{{ $btnGhostXs }}">📄 Kunden-Dokument</a>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-4 mt-4">
                <label class="flex flex-col gap-1">
                    <span class="{{ $label }}">Personen</span>
                    <input type="number" min="1" wire:model.live.debounce.400ms="pax" placeholder="—" class="{{ $input }} w-24" />
                </label>
                <div class="flex flex-wrap gap-2">
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <div class="{{ $label }}">VK / Person</div>
                        <div class="text-lg font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</div>
                    </div>
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <div class="{{ $label }}">EK / Person</div>
                        <div class="text-lg font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($gesamt['ek_per_person'], 2, ',', '.') }} €</div>
                    </div>
                    @php($wGes = $gesamt['vk_pro_person'] > 0 ? round($gesamt['ek_per_person'] / $gesamt['vk_pro_person'] * 100, 1) : null)
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <div class="{{ $label }}">Wareneinsatz</div>
                        <div class="text-lg font-semibold tabular-nums"><span class="{{ $pill }} {{ $variantPill[$wfarbe($wGes)] }}">{{ $wGes !== null ? number_format($wGes, 1, ',', '.') . ' %' : '—' }}</span></div>
                    </div>
                    @if($gesamt['gesamt_vk'] !== null)
                        <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                            <div class="{{ $label }}">Gesamt · {{ $gesamt['personen'] }} P</div>
                            <div class="text-lg font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($gesamt['gesamt_vk'], 2, ',', '.') }} €</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── Filter-Leiste (Slice 2): Volltext · Diät · allergenfrei ───────────── --}}
    <div class="relative overflow-hidden {{ $card }} mb-4">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-3 space-y-3">
            <div class="flex flex-wrap items-end gap-4">
                <label class="flex flex-col gap-1 flex-1 min-w-[200px]">
                    <span class="{{ $label }}">Suche (Gericht / Titel)</span>
                    <input type="text" wire:model.live.debounce.400ms="q" placeholder="tippen zum Filtern …" class="{{ $input }}" />
                </label>
                <div class="flex flex-col gap-1">
                    <span class="{{ $label }}">Diät</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($dietFormen as $d)
                            <label class="{{ $pill }} cursor-pointer {{ in_array($d, $diaet, true) ? $variantPill[$dietFarbe[$d] ?? 'primary'] : $variantPill['secondary'] }}">
                                <input type="checkbox" class="sr-only" wire:model.live="diaet" value="{{ $d }}">{{ $dietLabel[$d] ?? $d }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <span class="{{ $label }}">Ohne Allergen</span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($allergenKeys as $a)
                        <label class="{{ $pill }} cursor-pointer {{ in_array($a, $ohne, true) ? $variantPill['danger'] : $variantPill['secondary'] }}">
                            <input type="checkbox" class="sr-only" wire:model.live="ohne" value="{{ $a }}">{{ $allergenLabel[$a] ?? $a }}
                        </label>
                    @endforeach
                </div>
            </div>
            @if(count($servier_formen ?? []))
                <div class="flex flex-col gap-1">
                    <span class="{{ $label }}">Servierform</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($servier_formen as $sf)
                            <label class="{{ $pill }} cursor-pointer {{ in_array($sf->id, $formen) ? $variantPill['info'] : $variantPill['secondary'] }}">
                                <input type="checkbox" class="sr-only" wire:model.live="formen" value="{{ $sf->id }}">{{ $sf->label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            @if($filter_aktiv)
                <div class="flex items-center gap-3 pt-1">
                    <span class="text-[11px] text-gray-600 dark:text-gray-400">{{ $trefferGesamt }} Gericht-Treffer · Preise = ganzes Kapitel (Filter ist Sicht-Linse)</span>
                    <button type="button" wire:click="filterZuruecksetzen" class="{{ $btnGhostXs }}">Filter zurücksetzen</button>
                </div>
            @endif
        </div>
    </div>

    @if(count($kapitel) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400 py-10 text-center">Noch keine Kapitel in diesem Foodbook.</p>
    @else
    <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-4">
        {{-- ── Kapitel-Baum-Navigation (sticky) ─────────────────────────────── --}}
        <nav class="hidden lg:block">
            <div class="sticky top-4 {{ $card }} px-3 py-3">
                <div class="{{ $label }} mb-2 px-1">Kapitel</div>
                <ul class="space-y-0.5">
                    @foreach($kapitel as $k)
                        <li>
                            <a href="#kap-{{ $k['id'] }}" style="padding-left: {{ $k['depth'] * 12 + 4 }}px"
                               class="block py-1 pr-2 text-xs rounded hover:bg-violet-500/5 truncate {{ $filter_aktiv && $k['n_treffer'] === 0 ? 'text-gray-300 dark:text-gray-600' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $k['title'] ?: '(ohne Titel)' }}
                                @if($filter_aktiv)<span class="text-gray-500 dark:text-gray-400"> · {{ $k['n_treffer'] }}</span>@elseif($k['agg']['vk_pro_person'] > 0)<span class="text-gray-500 dark:text-gray-400"> · {{ number_format($k['agg']['vk_pro_person'], 2, ',', '.') }} €</span>@endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </nav>

        {{-- ── Kapitel-Inhalt ───────────────────────────────────────────────── --}}
        <div class="space-y-3">
            @foreach($kapitel as $k)
                @php($wKap = $k['agg']['food_cost_percent'])
                <section id="kap-{{ $k['id'] }}" x-data="{ open: true }" class="relative overflow-hidden {{ $card }}" style="margin-left: {{ $k['depth'] * 16 }}px">
                    <div class="{{ $cardAccent }}"></div>
                    <button type="button" @click="open = !open" class="w-full flex items-center justify-between gap-3 px-5 py-3 text-left">
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="text-gray-500 dark:text-gray-400 text-xs transition-transform" :class="open || 'rotate-[-90deg]'">▾</span>
                            <span class="font-medium tracking-tight text-gray-900 dark:text-gray-100 truncate">{{ $k['title'] ?: '(ohne Titel)' }}</span>
                            <span class="text-[11px] text-gray-500 dark:text-gray-400 shrink-0">{{ $filter_aktiv ? $k['n_treffer'] . ' Treffer' : count($k['bloecke']) }}</span>
                        </span>
                        <span class="flex items-center gap-2 shrink-0 text-xs tabular-nums">
                            <span class="text-gray-600 dark:text-gray-400">VK {{ number_format($k['agg']['vk_pro_person'], 2, ',', '.') }} €</span>
                            <span class="text-gray-500 dark:text-gray-400">EK {{ number_format($k['agg']['ek_per_person'], 2, ',', '.') }} €</span>
                            <span class="{{ $pill }} {{ $variantPill[$wfarbe($wKap)] }}">{{ $wKap !== null ? number_format($wKap, 1, ',', '.') . ' %' : '—' }}</span>
                        </span>
                    </button>

                    <div x-show="open" x-cloak class="px-5 pb-3">
                        @php($sichtbareBloecke = $filter_aktiv ? collect($k['bloecke'])->reject(fn ($b) => $b['ist_header'] && $k['n_treffer'] === 0)->all() : $k['bloecke'])
                        @if($filter_aktiv && $k['n_treffer'] === 0)
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 py-2 italic">Keine Treffer in diesem Kapitel.</p>
                        @else
                            @forelse($sichtbareBloecke as $b)
                                <div class="py-1.5 border-t border-black/5 dark:border-white/10 {{ $b['ist_header'] ? 'mt-1' : '' }}">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2 min-w-0">
                                            @unless($b['ist_header'])
                                                <span class="{{ $pill }} {{ $variantPill[$b['type'] === 'concept_ref' ? 'primary' : 'info'] }} shrink-0">{{ $b['type'] === 'concept_ref' ? 'Konzept' : 'Gericht' }}</span>
                                            @endunless
                                            <span class="truncate {{ $b['ist_header'] ? 'font-semibold text-gray-700 dark:text-gray-200' : 'text-gray-800 dark:text-gray-100' }}">{{ $b['label'] }}</span>
                                            @if(($b['n_gesamt'] ?? 0) > 0 && $filter_aktiv && $b['n_sichtbar'] < $b['n_gesamt'])
                                                <span class="text-[10px] text-gray-500 dark:text-gray-400 shrink-0">{{ $b['n_sichtbar'] }}/{{ $b['n_gesamt'] }}</span>
                                            @endif
                                        </div>
                                        @unless($b['ist_header'])
                                            <div class="flex items-center gap-2 shrink-0 text-xs tabular-nums text-gray-600 dark:text-gray-400">
                                                @if($b['pauschal'] > 0)
                                                    <span title="Pauschalpreis">{{ number_format($b['pauschal'], 2, ',', '.') }} € pausch.</span>
                                                @else
                                                    <span title="VK / Person">{{ number_format($b['vk_pp'], 2, ',', '.') }} €</span>
                                                    <span class="text-gray-500 dark:text-gray-400" title="EK / Person">EK {{ number_format($b['ek_pp'], 2, ',', '.') }} €</span>
                                                    <span class="{{ $pill }} {{ $variantPill[$wfarbe($b['wpct'])] }}" title="Wareneinsatz">{{ $b['wpct'] !== null ? number_format($b['wpct'], 1, ',', '.') . ' %' : '—' }}</span>
                                                @endif
                                            </div>
                                        @endunless
                                    </div>
                                    {{-- Drill-down: Gerichte eines Konzept-Blocks (Diät-Badge) --}}
                                    @if(count($b['gerichte'] ?? []))
                                        <div class="flex flex-wrap gap-1 mt-1 pl-1">
                                            @foreach($b['gerichte'] as $g)
                                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="{{ $g['diet'] ? ($dietLabel[$g['diet']] ?? $g['diet']) : '' }}">
                                                    @if($g['diet'])<span class="opacity-60 mr-0.5">{{ strtoupper(substr($dietLabel[$g['diet']] ?? $g['diet'], 0, 1)) }}</span>@endif{{ $g['name'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 py-2">Keine sichtbaren Blöcke.</p>
                            @endforelse
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>
    @endif
</div>
