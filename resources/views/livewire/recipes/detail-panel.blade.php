{{-- M4-05: Rezept-DetailPanel. Redesign v3 2026-07-21 (Dominique): Standalone-Sidebar
     nicht ausklappbar, größere Typo, Kosten-Linse (EK/kg-Cockpit → Pairing-Netz → Zutaten →
     Pairings → Allergene → Eignung/Ersatz/Equipment). Editor-Embeds (nurEignung/nurErsatz/
     embedded-Details-Tab) behalten ihre bisherigen Karteien. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($nurErsatz = ($section ?? null) === 'ersatz')
@php($nurEignung = ($section ?? null) === 'eignung')
@php($nurSektion = $nurErsatz || $nurEignung)

<div class="{{ $nurSektion || ($embedded ?? false) ? 'space-y-2' : 'p-4 space-y-4 min-h-full bg-gray-500/[0.04]' }}" data-rezept-panel>
    @if($rezept === null)
        @unless($nurSektion)
            <div class="text-center text-xs text-gray-500 py-12">
                <div class="text-2xl mb-2">⌘</div>
                Rezept in der Tabelle anklicken —<br>Details erscheinen hier.
            </div>
        @endunless

    {{-- ── Editor-Kartei: NUR Eignung (Eigenschaften-Tab, kein Panel-Chrome) ── --}}
    @elseif($nurEignung)
        @php($eignungVokab = \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular())
        @php($eignungAktiv = ['level' => $rezept->levelSuitabilities->keyBy('level_slug'), 'sektor' => $rezept->sectorSuitabilities->keyBy('sector_slug')])
        <div data-eignungen>
            @if($fehlerEignung !== null)<p class="text-[11px] text-rose-500 mb-1" data-eignung-fehler>{{ $fehlerEignung }}</p>@endif
            <div class="space-y-1.5">
                @foreach(['level' => 'Niveau', 'sektor' => 'Sektor'] as $typ => $typLabel)
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-[10px] uppercase tracking-wider text-gray-500 w-12 shrink-0">{{ $typLabel }}</span>
                        @foreach($eignungVokab[$typ]['slugs'] as $slug)
                            @php($eintrag = $eignungAktiv[$typ][$slug] ?? null)
                            <button type="button" wire:key="eig-{{ $typ }}-{{ $slug }}" wire:click="eignungToggle('{{ $typ }}', '{{ $slug }}')"
                                    class="{{ $pill }} transition-colors {{ $eintrag !== null ? ($typ === 'level' ? $variantPill['info'] : $variantPill['primary']) : 'border border-black/10 text-gray-500 hover:text-gray-600' }}"
                                    data-eignung-chip="{{ $typ }}-{{ $slug }}">{{ $slug }}</button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

    {{-- ── Editor-Kartei: NUR Ersatz (Eigenschaften-Tab) ── --}}
    @elseif($nurErsatz)
        <div data-sektion="ersatz">
            <div class="space-y-1">
                @forelse($ersatz as $e)
                    <div class="flex items-center gap-2 text-[11px]" wire:key="rq-equiv-{{ $e->id }}">
                        <span class="{{ $pill }} {{ $e->gegen_kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }} shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-900" title="{{ $e->gegen_name }}">{{ $e->gegen_name }}</span>
                        @if((float) $e->umrechnungsfaktor !== 1.0)<span class="text-gray-500 tabular-nums shrink-0">×{{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                        <button type="button" wire:click="ersatzLoesen({{ $e->id }})" class="{{ $btnGhostXs }} text-rose-500 shrink-0" title="Ersatz-Verknüpfung lösen">✕</button>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-500" data-ersatz-leer>— kein Ersatz hinterlegt —</p>
                @endforelse
                <div class="pt-1" data-ersatz-verknuepfen>
                    <input type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="+ Ersatz verknüpfen — Fertig-GP/Rezept suchen …" class="{{ $input }} !py-1" data-ersatz-suche />
                    @foreach($ersatzKandidaten as $k)
                        <button type="button" wire:key="rq-ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})"
                                class="w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 hover:bg-violet-500/10 flex items-center gap-1.5">
                            <span class="{{ $pill }} {{ $k->kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $k->kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

    {{-- ── Editor „Details"-Tab (embedded, ohne section): reduzierter Subset ── --}}
    @elseif($embedded ?? false)
        @include('foodalchemist::livewire.recipes.partials.deklaration')
        @if($eltern->isNotEmpty())
            <div data-eltern>
                <p class="{{ $dt }} mb-1">Verwendet in ({{ $eltern->count() }})</p>
                <div class="space-y-0.5">
                    @foreach($eltern as $parent)
                        <button type="button" wire:key="el-{{ $parent->id }}"
                                @if($parent->is_sales_recipe) wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $parent->id }} })" @else wire:click="zeige({{ $parent->id }})" @endif
                                class="block w-full text-left text-[11px] text-sky-600 hover:underline truncate" data-eltern-link>{{ $parent->is_sales_recipe ? '💶' : '↑' }} {{ $parent->name }}</button>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="flex flex-wrap items-center gap-1.5 border-t border-black/5 pt-2" data-workflow>
            @foreach(['draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigeben'] as $wert => $lbl)
                @if($rezept->status->value !== $wert)<button type="button" wire:click="statusSetzen('{{ $wert }}')" class="{{ $btnGhostXs }}" data-status-btn="{{ $wert }}">→ {{ $lbl }}</button>@endif
            @endforeach
            <button type="button" wire:click="duplizieren" class="{{ $btnGhostXs }}" data-duplizieren-btn>Duplizieren</button>
            <button type="button" wire:click="templateToggle" class="{{ $btnGhostXs }} {{ $rezept->is_template ? 'text-violet-600' : '' }}" data-template-btn>{{ $rezept->is_template ? '★ Template' : 'Als Template' }}</button>
        </div>

    {{-- ── STANDALONE-Sidebar: v3 Kosten-Linse ── --}}
    @else
        {{-- Kopf --}}
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug">{{ $rezept->name }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-rezept-bearbeiten>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                    <button type="button" wire:click="neuBerechnen" class="{{ $btnGhostXs }}" title="GL-02-Pipeline + Eltern-Propagation" data-recompute-btn>@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')</button>
                    <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 mt-1.5 flex items-center gap-1.5">
                <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $rezept->category?->label ?? '—' }}</span>
                <span class="text-gray-400">{{ $rezept->recipe_key }}</span>
            </p>
        </div>

        {{-- Cockpit (Kosten): EK/kg + Bepreist-Signal + EK gesamt/Yield/Konfidenz --}}
        @php($priced = $rezept->ek_n_ingredients_priced)
        @php($ptotal = $rezept->ek_n_ingredients_total)
        @php($vollstaendig = $priced !== null && $ptotal !== null && $ptotal > 0 && $priced >= $ptotal)
        @php($komplBadge = $vollstaendig ? ['bg-emerald-500/15', 'text-emerald-700', 'bg-emerald-500'] : ['bg-amber-500/15', 'text-amber-700', 'bg-amber-500'])
        @php($konfText = ['high' => 'text-emerald-700', 'medium' => 'text-amber-700', 'low' => 'text-rose-700'][$rezept->allergens_confidence] ?? 'text-gray-500')
        <div class="relative overflow-hidden {{ $card }} px-3.5 py-2.5" data-kpi-karte>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600">EK / kg</span>
                    <p class="text-2xl font-bold text-violet-700 leading-none mt-1 tabular-nums">{{ $rezept->ek_per_kg_eur !== null ? number_format((float) $rezept->ek_per_kg_eur, 2, ',', '.') . ' €' : '—' }}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-0.5 rounded-full {{ $komplBadge[0] }} {{ $komplBadge[1] }}" title="Zutaten mit Preis">
                    <span class="w-1.5 h-1.5 rounded-full {{ $komplBadge[2] }}"></span>{{ ($priced ?? '—') }}/{{ ($ptotal ?? '—') }} bepreist
                </span>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 mt-3 pt-2.5 border-t border-black/5 text-xs">
                <span class="text-gray-500">EK gesamt <span class="text-gray-900 font-medium tabular-nums">{{ $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</span></span>
                <span class="text-gray-500">Yield <span class="text-gray-900 font-medium tabular-nums">{{ $rezept->yield_kg !== null ? number_format((float) ($rezept->yield_kg_manual ?? $rezept->yield_kg), 3, ',', '.') . ' kg' : '—' }}</span></span>
                <span class="text-gray-500">Konfidenz <span class="font-medium {{ $konfText }}">{{ strtoupper($rezept->allergens_confidence) }}</span></span>
            </div>
        </div>
        @if($rezept->yield_kg_manual !== null)
            <p class="text-[11px] text-amber-600 -mt-2">Yield manuell überschrieben (Auto: {{ number_format((float) $rezept->yield_kg, 3, ',', '.') }} kg)</p>
        @endif

        @if($rezept->description)
            <p class="text-[13px] text-gray-600 leading-relaxed" data-description>{{ $rezept->description }}</p>
        @endif

        {{-- Pairing-Netz — Inline-Graph + Anker-Pflege (ÜBER den Zutaten, analog Gericht-Panel) --}}
        <x-foodalchemist::section title="Pairing-Netz" icon="heroicon-o-share"
            :meta="$kohaesion !== null ? 'Kohäsion ' . $kohaesion['score'] . ' · Coverage ' . $kohaesion['coverage_pct'] . ' %' : null" data-kern-anker>
            <x-slot:actions>
                <button type="button" wire:click="$dispatch('pairing-netz.oeffnen', { recipeId: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" title="Voller Graph: verwandte Rezepte + Vorschläge" data-pairing-netz-btn>Netz öffnen @svg('heroicon-o-arrow-up-right', 'w-3.5 h-3.5')</button>
            </x-slot:actions>
            <x-foodalchemist::pairing-netz :recipe-id="$rezept->id" />
            <div class="flex flex-wrap gap-1 mt-2">
                @foreach($kernAnker as $anker)
                    <span wire:key="ka-{{ $anker->id }}" class="{{ $pill }} {{ $variantPill['primary'] }} group" title="{{ $anker->source }}{{ $anker->ai_confidence !== null ? ' ' . round($anker->ai_confidence * 100) . '%' : '' }}">
                        ★ {{ $anker->display_de }}
                        <button type="button" wire:click="ankerLoesen({{ $anker->id }})" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                    </span>
                @endforeach
            </div>
            @if($fehlerAnker !== null)<p class="text-[11px] text-rose-500 mt-1" data-anker-fehler>{{ $fehlerAnker }}</p>@endif
            <div class="relative mt-1.5">
                <input type="search" wire:model.live.debounce.300ms="ankerSuche" placeholder="Anker verknüpfen …" class="{{ $input }} !py-1" data-anker-suche />
                @foreach($ankerKandidaten as $kandidat)
                    <button type="button" wire:key="ak-{{ $kandidat->id }}" wire:click="ankerVerknuepfen({{ $kandidat->id }})" class="block w-full text-left px-2 py-1 rounded text-xs text-gray-700 hover:bg-violet-500/10">{{ $kandidat->display_de }} <span class="text-gray-500">{{ $kandidat->slug }}</span></button>
                @endforeach
            </div>
            @if($kohaesion !== null && $kohaesion['weakest_pair'] !== null)
                <p class="text-[11px] text-gray-500 mt-1.5">Schwächstes Glied: {{ $kohaesion['weakest_pair']['a'] }} ↔ {{ $kohaesion['weakest_pair']['b'] }} ({{ $kohaesion['weakest_pair']['score'] }})</p>
            @endif
        </x-foodalchemist::section>

        {{-- Zutaten — Haupt-Block (Kosten-Essenz): Menge · GP-/Sub-Link · Zeilen-EK --}}
        <x-foodalchemist::section title="Zutaten" icon="heroicon-o-list-bullet" :meta="$rezept->ingredients->count()" data-zutaten>
            <div class="space-y-0.5">
                @foreach($rezept->ingredients as $z)
                    <div wire:key="z-{{ $z->id }}" class="flex items-baseline gap-2 text-[13px] py-1 border-b border-black/5 last:border-0 {{ $z->is_optional ? 'opacity-60' : '' }}">
                        <span class="text-gray-500 tabular-nums shrink-0 w-20 text-right">{{ rtrim(rtrim(number_format((float) $z->quantity, 2, ',', '.'), '0'), ',') }}{{ $z->quantity_max !== null ? '–' . rtrim(rtrim(number_format((float) $z->quantity_max, 2, ',', '.'), '0'), ',') : '' }} {{ $z->unit?->slug }}</span>
                        <span class="min-w-0 flex-1">
                            @if($z->gp !== null)
                                <a href="{{ route('foodalchemist.gps.index', ['gp' => $z->gp_id]) }}" class="text-violet-600 hover:underline">{{ $z->gp->name }}</a>
                            @elseif($z->referencedRecipe !== null)
                                <button type="button" wire:click="zeige({{ $z->referenced_recipe_id }})" class="text-sky-600 hover:underline" title="Sub-Rezept">↳ {{ $z->referencedRecipe->name }}</button>
                            @else
                                <span class="text-gray-500" title="ungemappt">{{ $z->display_name ?? $z->raw_text }}</span>
                            @endif
                            <span class="block text-[10px] text-gray-400 italic truncate" title="{{ $z->raw_text }}">{{ $z->raw_text }}</span>
                        </span>
                        <span class="shrink-0 tabular-nums {{ isset($zeilenEk[$z->id]) ? 'text-gray-900' : 'text-gray-400' }}" data-zeilen-ek>{{ isset($zeilenEk[$z->id]) ? number_format($zeilenEk[$z->id], 2, ',', '.') . ' €' : '—' }}</span>
                    </div>
                @endforeach
            </div>
        </x-foodalchemist::section>

        @if($schrittFotos->isNotEmpty())
            <x-foodalchemist::section title="Schritt-Fotos" icon="heroicon-o-photo" data-panel-schritt-fotos>
                <div class="space-y-1.5">
                    @foreach($schrittFotos as $schritt => $fotos)
                        <div class="flex items-start gap-2" wire:key="psfg-{{ $schritt }}">
                            <span class="shrink-0 w-16 text-[10px] text-gray-500 pt-1">{{ $schritt === 0 ? 'allgemein' : "Schritt {$schritt}" }}</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($fotos as $foto)
                                    <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}" class="w-20 h-14 object-cover rounded border border-black/10" loading="lazy" wire:key="psf-{{ $foto->id }}" />
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-foodalchemist::section>
        @endif

        {{-- Pairings — prominent (Basisrezept-Ebene): Chips + Typ + Verknüpfen --}}
        <x-foodalchemist::section title="Pairings" icon="heroicon-o-arrows-right-left" :meta="$pairings?->count()" data-pairing-sektion>
            @if($pairings !== null && $pairings->isNotEmpty())
                <div class="flex flex-wrap gap-1 mb-1.5" data-pairing-chips>
                    @foreach($pairings as $p)
                        <span wire:key="pp-{{ $p->id }}-{{ $p->type }}" class="{{ $pill }} group {{ ['erprobt' => $variantPill['success'], 'aroma' => $variantPill['success'], 'verbund' => $variantPill['info'], 'trinitas' => $variantPill['primary'], 'kontrast' => $variantPill['warning']][$p->type] ?? $variantPill['secondary'] }}" title="{{ $p->type }} · {{ $p->confidence }}{{ $p->created_via === 'manual' ? ' · manuell' : '' }}">{{ $p->display_de }}@if($p->created_via === 'manual')<span class="opacity-60"> ✎</span>@endif
                            <button type="button" wire:click="pairingLoesen({{ $p->id }}, '{{ $p->type }}')" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="flex items-center gap-1" data-pairing-add>
                <select wire:model="pairingTyp" class="{{ $input }} !py-0.5 !text-[11px] !w-24 shrink-0">
                    <option value="erprobt">erprobt</option>
                    <option value="aroma">aroma</option>
                    <option value="kontrast">kontrast</option>
                </select>
                <input type="search" wire:model.live.debounce.300ms="pairingSuche" placeholder="Pairing verknüpfen …" class="{{ $input }} !py-1 flex-1" data-pairing-suche />
            </div>
            @foreach($pairingKandidaten as $kandidat)
                <button type="button" wire:key="pk-{{ $kandidat->id }}" wire:click="pairingVerknuepfen({{ $kandidat->id }})" class="block w-full text-left px-2 py-1 rounded text-xs text-gray-700 hover:bg-emerald-500/10" data-pairing-kandidat="{{ $kandidat->id }}">{{ $kandidat->display_de }} <span class="text-gray-500">{{ $kandidat->slug }}</span></button>
            @endforeach
        </x-foodalchemist::section>

        {{-- Allergene & Diät — volle Deklaration --}}
        <x-foodalchemist::section title="Allergene & Diät" icon="heroicon-o-beaker" :meta="'Konf. ' . strtoupper($rezept->allergens_confidence)">
            @include('foodalchemist::livewire.recipes.partials.deklaration')
        </x-foodalchemist::section>

        {{-- Eignung — Niveau/Sektor Toggle-Chips --}}
        @php($eignungVokab = \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular())
        @php($eignungAktiv = ['level' => $rezept->levelSuitabilities->keyBy('level_slug'), 'sektor' => $rezept->sectorSuitabilities->keyBy('sector_slug')])
        <x-foodalchemist::section title="Eignung" icon="heroicon-o-user-group" data-eignungen>
            @if($fehlerEignung !== null)<p class="text-[11px] text-rose-500 mb-1" data-eignung-fehler>{{ $fehlerEignung }}</p>@endif
            <div class="space-y-1.5">
                @foreach(['level' => 'Niveau', 'sektor' => 'Sektor'] as $typ => $typLabel)
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-[10px] uppercase tracking-wider text-gray-500 w-12 shrink-0">{{ $typLabel }}</span>
                        @foreach($eignungVokab[$typ]['slugs'] as $slug)
                            @php($eintrag = $eignungAktiv[$typ][$slug] ?? null)
                            <button type="button" wire:key="eig-{{ $typ }}-{{ $slug }}" wire:click="eignungToggle('{{ $typ }}', '{{ $slug }}')"
                                    class="{{ $pill }} transition-colors {{ $eintrag !== null ? ($typ === 'level' ? $variantPill['info'] : $variantPill['primary']) : 'border border-black/10 text-gray-500 hover:text-gray-600' }}"
                                    title="{{ $eintrag !== null ? 'geeignet · ' . $eintrag->source . ($eintrag->ai_confidence !== null ? ' ' . round($eintrag->ai_confidence * 100) . '%' : '') . ' — Klick entfernt' : 'Klick markiert als geeignet' }}"
                                    data-eignung-chip="{{ $typ }}-{{ $slug }}">{{ $slug }}</button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-foodalchemist::section>

        {{-- Ersatz — make-or-buy --}}
        <x-foodalchemist::section title="Ersatz" icon="heroicon-o-scale" meta="make-or-buy · fertig ↔ selbst" data-sektion="ersatz">
            <div class="space-y-1">
                @forelse($ersatz as $e)
                    <div class="flex items-center gap-2 text-[11px]" wire:key="rq-equiv-{{ $e->id }}">
                        <span class="{{ $pill }} {{ $e->gegen_kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }} shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-900" title="{{ $e->gegen_name }}">{{ $e->gegen_name }}</span>
                        @if((float) $e->umrechnungsfaktor !== 1.0)<span class="text-gray-500 tabular-nums shrink-0">×{{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                        <button type="button" wire:click="ersatzLoesen({{ $e->id }})" class="{{ $btnGhostXs }} text-rose-500 shrink-0" title="Ersatz-Verknüpfung lösen">✕</button>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-500" data-ersatz-leer>— kein Ersatz hinterlegt —</p>
                @endforelse
                <div class="pt-1" data-ersatz-verknuepfen>
                    <input type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="+ Ersatz verknüpfen — Fertig-GP/Rezept suchen …" class="{{ $input }} !py-1" data-ersatz-suche />
                    @foreach($ersatzKandidaten as $k)
                        <button type="button" wire:key="rq-ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})" class="w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 hover:bg-violet-500/10 flex items-center gap-1.5">
                            <span class="{{ $pill }} {{ $k->kind === 'recipe' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $k->kind === 'recipe' ? 'Rezept' : 'GP' }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </x-foodalchemist::section>

        @if($rezept->equipment->isNotEmpty())
            <x-foodalchemist::section title="Equipment" icon="heroicon-o-wrench-screwdriver" data-equipment>
                <div class="flex flex-wrap gap-1">
                    @foreach($rezept->equipment as $geraet)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $geraet->name }}</span>@endforeach
                </div>
            </x-foodalchemist::section>
        @endif

        @if($eltern->isNotEmpty())
            <x-foodalchemist::section title="Verwendet in" icon="heroicon-o-link" :meta="$eltern->count()" data-eltern>
                <div class="space-y-0.5">
                    @foreach($eltern as $parent)
                        <button type="button" wire:key="el-{{ $parent->id }}"
                                @if($parent->is_sales_recipe) wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $parent->id }} })" @else wire:click="zeige({{ $parent->id }})" @endif
                                class="block w-full text-left text-[13px] text-sky-600 hover:underline truncate" data-eltern-link>{{ $parent->is_sales_recipe ? '💶' : '↑' }} {{ $parent->name }}</button>
                    @endforeach
                </div>
            </x-foodalchemist::section>
        @endif

        {{-- Workflow + Fuß --}}
        <div class="border-t border-black/5 pt-3 space-y-2" data-workflow>
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach(['draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigeben'] as $wert => $lbl)
                    @if($rezept->status->value !== $wert)<button type="button" wire:click="statusSetzen('{{ $wert }}')" class="{{ $btnGhostXs }}" data-status-btn="{{ $wert }}">→ {{ $lbl }}</button>@endif
                @endforeach
                <button type="button" wire:click="duplizieren" class="{{ $btnGhostXs }}" data-duplizieren-btn>Duplizieren</button>
                <button type="button" wire:click="templateToggle" class="{{ $btnGhostXs }} {{ $rezept->is_template ? 'text-violet-600' : '' }}" data-template-btn>{{ $rezept->is_template ? '★ Template' : 'Als Template' }}</button>
            </div>
            <p class="text-[11px] text-gray-500">Nährwerte {{ $rezept->nutri_kcal_per_100g !== null ? number_format((float) $rezept->nutri_kcal_per_100g, 0, ',', '.') . ' kcal/100 g (' . $rezept->nutri_confidence . ')' : '—' }} · v{{ $rezept->version }}{{ $rezept->work_time_min ? ' · ' . $rezept->work_time_min . ' min' : '' }}</p>
        </div>
    @endif
</div>
