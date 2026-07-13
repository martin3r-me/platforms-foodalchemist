{{-- M4-05: Rezept-DetailPanel — KPI-Karte, Beschreibung, Zutaten read-only mit GP-Links + EK je Zeile, Diät-Sektion, Eignungen, Equipment --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
{{-- GP-Modal-Muster: section='ersatz'|'eignung' rendert NUR die eine Kartei (Eigenschaften-Tab im Modal) — ohne Panel-Chrome --}}
@php($nurErsatz = ($section ?? null) === 'ersatz')
@php($nurEignung = ($section ?? null) === 'eignung')
@php($nurSektion = $nurErsatz || $nurEignung)

<div class="{{ $nurSektion ? 'space-y-2' : 'p-4 space-y-4 min-h-full bg-gray-500/[0.04] dark:bg-white/[0.02]' }}" data-rezept-panel>
    @if($rezept === null)
        <div class="text-center text-xs text-gray-500 dark:text-gray-400 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Rezept in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        {{-- Eingebettet als Editor-Kartei: Kopf/KPI/Beschreibung/Zutaten/Fotos sind dort schon da → ausblenden. --}}
        @unless($embedded ?? false)
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-[15px] font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $rezept->name }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-rezept-bearbeiten>Bearbeiten</button>
                    <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-zutaten-bearbeiten>Zutaten</button>
                    <button type="button" wire:click="neuBerechnen" class="{{ $btnGhostXs }}" title="GL-02-Pipeline + Eltern-Propagation" data-recompute-btn>↻</button>
                    <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ $rezept->category?->label ?? '—' }} · {{ $rezept->recipe_key }}</p>
        </div>

        {{-- KPI-Karte (EK/kg · EK · Yield · Konfidenz) --}}
        <div class="grid grid-cols-5 gap-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-kpi-karte>
            @foreach([
                ['EK/kg', $rezept->ek_per_kg_eur !== null ? number_format((float) $rezept->ek_per_kg_eur, 2, ',', '.') . ' €' : '—'],
                ['EK', $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—'],
                ['Yield', $rezept->yield_kg !== null ? number_format((float) ($rezept->yield_kg_manual ?? $rezept->yield_kg), 3, ',', '.') . ' kg' : '—'],
                ['Konfidenz', $rezept->allergens_confidence],
                ['mit Preis', ($rezept->ek_n_ingredients_priced ?? '—') . '/' . ($rezept->ek_n_ingredients_total ?? '—')],
            ] as [$lbl, $wert])
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $lbl }}</p>
                    <p class="text-xs font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $wert }}</p>
                </div>
            @endforeach
        </div>
        @if($rezept->yield_kg_manual !== null)
            <p class="text-[11px] text-amber-600 dark:text-amber-400 -mt-2">Yield manuell überschrieben (Auto: {{ number_format((float) $rezept->yield_kg, 3, ',', '.') }} kg)</p>
        @endif

        @if($rezept->description)
            <p class="text-[11px] text-gray-600 dark:text-gray-300 leading-relaxed" data-description>{{ $rezept->description }}</p>
        @endif

        {{-- Zutaten read-only: GP-Links (Kontext-Erhalt: ?gp=), Lineage kursiv, EK je Zeile --}}
        <div data-zutaten>
            <p class="{{ $dt }} mb-1">Zutaten ({{ $rezept->ingredients->count() }})</p>
            <div class="space-y-0.5">
                @foreach($rezept->ingredients as $z)
                    <div wire:key="z-{{ $z->id }}" class="flex items-baseline gap-2 text-[11px] py-0.5 border-b border-black/5 dark:border-white/5 last:border-0 {{ $z->is_optional ? 'opacity-60' : '' }}">
                        <span class="text-gray-600 dark:text-gray-400 tabular-nums shrink-0 w-20 text-right">{{ rtrim(rtrim(number_format((float) $z->quantity, 2, ',', '.'), '0'), ',') }}{{ $z->quantity_max !== null ? '–' . rtrim(rtrim(number_format((float) $z->quantity_max, 2, ',', '.'), '0'), ',') : '' }} {{ $z->unit?->slug }}</span>
                        <span class="min-w-0 flex-1">
                            @if($z->gp !== null)
                                <a href="{{ route('foodalchemist.gps.index', ['gp' => $z->gp_id]) }}" class="text-violet-600 dark:text-violet-400 hover:underline">{{ $z->gp->name }}</a>
                            @elseif($z->referencedRecipe !== null)
                                <button type="button" wire:click="zeige({{ $z->referenced_recipe_id }})" class="text-sky-600 dark:text-sky-400 hover:underline" title="Sub-Rezept">↳ {{ $z->referencedRecipe->name }}</button>
                            @else
                                <span class="text-gray-500 dark:text-gray-400" title="ungemappt">{{ $z->display_name ?? $z->raw_text }}</span>
                            @endif
                            <span class="block text-[10px] text-gray-500 dark:text-gray-400 italic truncate" title="{{ $z->raw_text }}">{{ $z->raw_text }}</span>
                        </span>
                        <span class="shrink-0 tabular-nums {{ isset($zeilenEk[$z->id]) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400' }}" data-zeilen-ek>
                            {{ isset($zeilenEk[$z->id]) ? number_format($zeilenEk[$z->id], 2, ',', '.') . ' €' : '—' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- R6: Step-by-Step-Fotos (Pflege im Voll-Editor, Zubereitungs-Sektion) --}}
        @if($schrittFotos->isNotEmpty())
            <div data-panel-schritt-fotos>
                <p class="{{ $dt }} mb-1">📷 Schritt-Fotos</p>
                <div class="space-y-1.5">
                    @foreach($schrittFotos as $schritt => $fotos)
                        <div class="flex items-start gap-2" wire:key="psfg-{{ $schritt }}">
                            <span class="shrink-0 w-16 text-[10px] text-gray-500 dark:text-gray-400 pt-1">{{ $schritt === 0 ? 'allgemein' : "Schritt {$schritt}" }}</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($fotos as $foto)
                                    <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                                         class="w-20 h-14 object-cover rounded border border-black/10 dark:border-white/10" loading="lazy" wire:key="psf-{{ $foto->id }}" />
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @endunless
        @unless($nurSektion)
        {{-- R6: Diät · Allergene · Zusatzstoffe — geteiltes Partial (auch im VK-Panel) --}}
        @include('foodalchemist::livewire.recipes.partials.deklaration')

        {{-- M4-10: ↑-Navigation — Rezepte, die dieses als Sub-Rezept nutzen --}}
        @if($eltern->isNotEmpty())
            <div data-eltern>
                <p class="{{ $dt }} mb-1">Verwendet in ({{ $eltern->count() }})</p>
                <div class="space-y-0.5">
                    @foreach($eltern as $parent)
                        {{-- M9-05-Rest: VK-Eltern öffnen den VK-Editor als Modal, Basis-Eltern hüpfen im Panel --}}
                        <button type="button" wire:key="el-{{ $parent->id }}"
                                @if($parent->is_sales_recipe) wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $parent->id }} })"
                                @else wire:click="zeige({{ $parent->id }})" @endif
                                class="block w-full text-left text-[11px] text-sky-600 dark:text-sky-400 hover:underline truncate" data-eltern-link>
                            {{ $parent->is_sales_recipe ? '💶' : '↑' }} {{ $parent->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @endunless
        {{-- Eignung — klickbare Toggle-Chips (M9-01k-Service). Im Modal lebt die Kartei im
             Eigenschaften-Tab (section='eignung'), standalone (Sidebar) bleibt sie inline. --}}
        @if($nurEignung || ! ($embedded ?? false))
        @php($eignungVokab = \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular())
        @php($eignungAktiv = [
            'level' => $rezept->levelSuitabilities->keyBy('level_slug'),
            'sektor' => $rezept->sectorSuitabilities->keyBy('sector_slug'),
        ])
        <div data-eignungen>
            @unless($nurEignung){{-- im Eigenschaften-Tab liefert die modal-section den Titel --}}
            <p class="{{ $dt }} mb-1">Eignung</p>
            @endunless
            @if($fehlerEignung !== null)<p class="text-[11px] text-rose-500 mb-1" data-eignung-fehler>{{ $fehlerEignung }}</p>@endif
            <div class="space-y-1.5">
                @foreach(['level' => 'Niveau', 'sektor' => 'Sektor'] as $typ => $typLabel)
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400 w-12 shrink-0">{{ $typLabel }}</span>
                        @foreach($eignungVokab[$typ]['slugs'] as $slug)
                            @php($eintrag = $eignungAktiv[$typ][$slug] ?? null)
                            <button type="button" wire:key="eig-{{ $typ }}-{{ $slug }}" wire:click="eignungToggle('{{ $typ }}', '{{ $slug }}')"
                                    class="{{ $pill }} transition-colors {{ $eintrag !== null
                                        ? ($typ === 'level' ? $variantPill['info'] : $variantPill['primary'])
                                        : 'border border-black/10 dark:border-white/15 text-gray-500 dark:text-gray-400 hover:text-gray-600 dark:hover:text-gray-300' }}"
                                    title="{{ $eintrag !== null
                                        ? 'geeignet · ' . $eintrag->source . ($eintrag->ai_confidence !== null ? ' ' . round($eintrag->ai_confidence * 100) . '%' : '') . ' — Klick entfernt'
                                        : 'Klick markiert als geeignet' }}"
                                    data-eignung-chip="{{ $typ }}-{{ $slug }}">{{ $slug }}</button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        @endif
        {{-- Ersatz-Logik: make-or-buy — dieses Rezept ↔ Fertig-GP / Alternativ-Rezept.
             Im Modal lebt die Kartei im Eigenschaften-Tab (section='ersatz'); im Details-Embed
             darum ausgeblendet. Standalone (Browser-Sidebar) bleibt sie inline. --}}
        @if($nurErsatz || ! ($embedded ?? false))
        <div data-sektion="ersatz">
            @unless($nurErsatz){{-- im Eigenschaften-Tab liefert die modal-section den Titel --}}
            <p class="{{ $dt }} mb-1">Ersatz <span class="normal-case">(make-or-buy · fertig ↔ selbst)</span></p>
            @endunless
            <div class="space-y-1">
                @forelse($ersatz as $e)
                    <div class="flex items-center gap-2 text-[11px]" wire:key="rq-equiv-{{ $e->id }}">
                        <span class="{{ $pill }} {{ $e->gegen_kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }} shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" title="{{ $e->gegen_name }}">{{ $e->gegen_name }}</span>
                        @if((float) $e->umrechnungsfaktor !== 1.0)<span class="text-gray-500 dark:text-gray-400 tabular-nums shrink-0">×{{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                        <button type="button" wire:click="ersatzLoesen({{ $e->id }})" class="{{ $btnGhostXs }} text-rose-500 shrink-0" title="Ersatz-Verknüpfung lösen">✕</button>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-500 dark:text-gray-400" data-ersatz-leer>— kein Ersatz hinterlegt —</p>
                @endforelse
                <div class="pt-1" data-ersatz-verknuepfen>
                    <input type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="+ Ersatz verknüpfen — Fertig-GP/Rezept suchen …" class="{{ $input }} !py-1" data-ersatz-suche />
                    @foreach($ersatzKandidaten as $k)
                        <button type="button" wire:key="rq-ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})"
                                class="w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors duration-150 flex items-center gap-1.5">
                            <span class="{{ $pill }} {{ $k->kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $k->kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
        {{-- G/H: im Modal-Details-Tab redundant (Equipment → Zubereitung-Tab; Kern-Anker/Pairings/Nachbarn → Aromen/Pairing-Panel).
             Nur in der Browser-Sidebar (standalone) zeigen — dort bleibt auch das Anker-Verknüpfen/-Lösen verfügbar. --}}
        @unless($embedded ?? false)
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
                    class="w-full flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 hover:text-violet-500 transition-colors">
                <span>Kern-Anker ({{ $kernAnker->count() }}/5)</span>
                <span>{{ ($offen['anker'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            <div class="flex flex-wrap gap-1 mt-1">
                @foreach($kernAnker as $anker)
                    <span wire:key="ka-{{ $anker->id }}" class="{{ $pill }} {{ $variantPill['primary'] }} group" title="{{ $anker->source }}{{ $anker->ai_confidence !== null ? ' ' . round($anker->ai_confidence * 100) . '%' : '' }}">
                        ★ {{ $anker->display_de }}
                        <button type="button" wire:click="ankerLoesen({{ $anker->id }})" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                    </span>
                @endforeach
            </div>
            @if($offen['anker'] ?? false)
                @if($fehlerAnker !== null)<p class="text-[11px] text-rose-500 mt-1" data-anker-fehler>{{ $fehlerAnker }}</p>@endif
                <div class="relative mt-1.5">
                    <input type="search" wire:model.live.debounce.300ms="ankerSuche" placeholder="Anker verknüpfen …" class="{{ $input }} !py-1" data-anker-suche />
                    @foreach($ankerKandidaten as $kandidat)
                        <button type="button" wire:key="ak-{{ $kandidat->id }}" wire:click="ankerVerknuepfen({{ $kandidat->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10">{{ $kandidat->display_de }} <span class="text-gray-500 dark:text-gray-400">{{ $kandidat->slug }}</span></button>
                    @endforeach
                </div>
                @if($kohaesion !== null)
                    <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 text-[11px] space-y-0.5" data-kohaesion>
                        <p class="text-gray-900 dark:text-gray-100">Aroma-Kohäsion: <span class="font-medium">{{ $kohaesion['score'] }}</span>
                            · min {{ $kohaesion['min_score'] }} · Coverage {{ $kohaesion['coverage_pct'] }} % ({{ $kohaesion['rated_pairs'] }}/{{ $kohaesion['total_pairs'] }})
                            @if($kohaesion['coverage_pct'] < 30)<span class="text-amber-500">· dünne Datenlage</span>@endif
                        </p>
                        @if($kohaesion['weakest_pair'] !== null)
                            <p class="text-gray-500 dark:text-gray-400">Schwächstes Glied: {{ $kohaesion['weakest_pair']['a'] }} ↔ {{ $kohaesion['weakest_pair']['b'] }} ({{ $kohaesion['weakest_pair']['score'] }}, {{ $kohaesion['weakest_pair']['type'] }})</p>
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
            <div class="flex items-center gap-2">
                <button type="button" wire:click="toggleSektion('pairing')"
                        class="flex-1 flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 hover:text-violet-500 transition-colors">
                    <span>Pairings</span>
                    <span>{{ ($offen['pairing'] ?? false) ? '▾' : '▸' }}</span>
                </button>
                <button type="button" wire:click="$dispatch('aroma-netz.oeffnen', { recipeId: {{ $rezept->id }} })"
                        class="{{ $btnGhostXs }} shrink-0" title="Aroma-Netz: Anker, Brücken und verwandte Rezepte als Graph" data-aroma-netz-btn>🕸 Aroma-Netz</button>
            </div>
            @if($pairings !== null)
                <div class="flex flex-wrap gap-1 mt-1" data-pairing-chips>
                    @foreach($pairings as $p)
                        <span wire:key="pp-{{ $p->id }}-{{ $p->type }}" class="{{ $pill }} group {{ ['erprobt' => $variantPill['success'], 'aroma' => $variantPill['success'], 'verbund' => $variantPill['info'], 'trinitas' => $variantPill['primary'], 'kontrast' => $variantPill['warning']][$p->type] ?? $variantPill['secondary'] }}"
                              title="{{ $p->type }} · {{ $p->confidence }}{{ $p->created_via === 'manual' ? ' · manuell' : '' }}">{{ $p->display_de }}@if($p->created_via === 'manual')<span class="opacity-60"> ✎</span>@endif
                            <button type="button" wire:click="pairingLoesen({{ $p->id }}, '{{ $p->type }}')" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                        </span>
                    @endforeach
                </div>
                {{-- manuelles Pairing verknüpfen (Typ wählbar, created_via='manual') --}}
                <div class="mt-1.5 flex items-center gap-1" data-pairing-add>
                    <select wire:model="pairingTyp" class="{{ $input }} !py-0.5 !text-[11px] !w-24 shrink-0">
                        <option value="erprobt">erprobt</option>
                        <option value="aroma">aroma</option>
                        <option value="kontrast">kontrast</option>
                    </select>
                    <input type="search" wire:model.live.debounce.300ms="pairingSuche" placeholder="Pairing verknüpfen …" class="{{ $input }} !py-1 flex-1" data-pairing-suche />
                </div>
                @foreach($pairingKandidaten as $kandidat)
                    <button type="button" wire:key="pk-{{ $kandidat->id }}" wire:click="pairingVerknuepfen({{ $kandidat->id }})"
                            class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-emerald-500/10" data-pairing-kandidat="{{ $kandidat->id }}">{{ $kandidat->display_de }} <span class="text-gray-500 dark:text-gray-400">{{ $kandidat->slug }}</span></button>
                @endforeach
                @if($verwandte->isNotEmpty())
                    <p class="{{ $dt }} mt-2 mb-1">Verwandte Rezepte</p>
                    <div class="space-y-0.5" data-verwandte>
                        @foreach($verwandte as $v)
                            <button type="button" wire:key="vw-{{ $v['recipe_id'] }}" wire:click="zeige({{ $v['recipe_id'] }})"
                                    class="block w-full text-left text-[11px] text-sky-600 dark:text-sky-400 hover:underline truncate"
                                    title="{{ implode(', ', $v['shared_slugs']) }}">{{ $v['name'] }} <span class="text-gray-500 dark:text-gray-400">· {{ $v['shared'] }} gemeinsam</span></button>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- M5-05-Nachtrag: Aroma-Nachbarn (Klassiker/Signature, % = Ø-Kantenstärke) --}}
        <div data-nachbarn-sektion>
            <button type="button" wire:click="toggleSektion('nachbarn')"
                    class="w-full flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 hover:text-violet-500 transition-colors">
                <span>Aroma-Nachbarn</span>
                <span>{{ ($offen['nachbarn'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if($nachbarn !== null)
                @foreach(['klassiker' => 'Klassiker', 'signature' => 'Signature'] as $modus => $lbl)
                    @if(count($nachbarn[$modus]) > 0)
                        <p class="{{ $dt }} mt-1.5 mb-1">{{ $lbl }}</p>
                        <div class="flex flex-wrap gap-1" data-nachbarn-{{ $modus }}>
                            @foreach($nachbarn[$modus] as $n)
                                <span wire:key="nb-{{ $modus }}-{{ $n['anchor_id'] }}" class="{{ $pill }} {{ $modus === 'klassiker' ? $variantPill['success'] : $variantPill['info'] }}"
                                      title="trifft {{ $n['cover'] }} Anker · Grad {{ $n['degree'] }}{{ $n['degree'] > 100 ? ' (Allrounder)' : '' }}">{{ $n['slug'] }} {{ $n['mean_w'] }} %</span>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>
        @endunless

        {{-- M4-12: Workflow-Aktionen --}}
        @unless($nurSektion)
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

        <p class="text-[11px] text-gray-500 dark:text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            Nährwerte {{ $rezept->nutri_kcal_per_100g !== null ? number_format((float) $rezept->nutri_kcal_per_100g, 0, ',', '.') . ' kcal/100 g (' . $rezept->nutri_confidence . ')' : '—' }}
            · v{{ $rezept->version }}{{ $rezept->work_time_min ? ' · ' . $rezept->work_time_min . ' min' : '' }}
        </p>
        @endunless
    @endif
</div>
