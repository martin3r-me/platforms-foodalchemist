{{-- Angebote-Editor (Fullscreen-Dark, pro Angebot) — Anfrage · Aufbau · Kalkulation · Gerüst · Kunde. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="angebot-editor" fullscreen dark-canvas title="Angebot bearbeiten"
    :title-name="$angebot->name ?? null">
    <x-slot:actions>
        @if($angebot)
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-angebot-speichern>Speichern</button>
            {{-- Workflow-Übergänge --}}
            @foreach($angebot->status->uebergaenge() as $next)
                <button type="button" wire:click="statusSetzen('{{ $next->value }}')" class="{{ $btnGhostXs }}" data-angebot-status="{{ $next->value }}">→ {{ $next->label() }}</button>
            @endforeach
            <span class="text-gray-300">|</span>
            <a href="{{ route('foodalchemist.angebote.karte', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Schöne Angebots-Karte (Kundenausgabe, Druck/PDF)">@svg('heroicon-o-printer', 'w-3.5 h-3.5 inline align-text-bottom') Druck/Karte</a>
            <a href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Schlichtes Angebots-Dokument (Druck/PDF)">Dokument</a>
            {{-- Stufe 3 — Angebot → Produktion (concept × Pax → Produktionsauftrag am Event-Tag). --}}
            <button type="button" wire:click="anProduktion" class="{{ $btnGhostXs }}"
                    title="Angebot in die Produktion übergeben — danach im Tagesplan planbar" data-angebot-produktion>→ Produktion</button>
            <button type="button" wire:click="loeschen" wire:confirm="Angebot löschen?" class="{{ $btnGhostXs }} text-red-600" data-angebot-loeschen>Löschen</button>
        @endif
    </x-slot:actions>

    @if($angebot)
        <x-slot:kpiHeader>
            @php($k = $kalkulation)
            @php($voll = $k && ! $k['leer'])
            @php($weTone = match($wareneinsatzAmpel ?? 'unbekannt') { 'gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', default => null })
            <x-foodalchemist::kpi-tiles marker="angebot-kpis" :tiles="[
                ['kpi' => 'vkpp', 'label' => '€ / Person', 'tone' => 'accent',
                 'value' => $voll ? number_format((float) $k['vk_pro_person'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'pax', 'label' => 'Pax', 'value' => (string) ($k['pax'] ?? ($angebot->personen ?: '—'))],
                ['kpi' => 'gesamt', 'label' => 'Gesamt VK',
                 'value' => $voll ? number_format((float) $k['gesamt_vk'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'we', 'label' => 'Wareneinsatz',
                 'tone' => $weTone,
                 'title' => ($voll && $k['wareneinsatz_pct'] !== null) ? 'Ziel des Teams: ' . number_format((float) $zielWareneinsatzPct, 1, ',', '.') . ' %' : null,
                 'value' => ($voll && $k['wareneinsatz_pct'] !== null) ? number_format((float) $k['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—'],
                ['kpi' => 'status', 'label' => 'Status', 'value' => $angebot->status->label()],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($angebot === null)
        <p class="pt-4 text-[12px] text-gray-500">Kein Angebot geladen.</p>
    @else
    <x-foodalchemist::editor-tabs marker="angebot" wire-key="angebot-tabs-{{ $angebot->id }}" :init="'anfrage'"
        :tabs="['anfrage' => 'Anfrage', 'aufbau' => 'Aufbau', 'kalkulation' => 'Kalkulation', 'geruest' => 'Gerüst', 'kunde' => 'Kunde & Business-Case']">

        {{-- ═══ Tab: ANFRAGE ═══ --}}
        <div x-show="tab === 'anfrage'" x-cloak class="pt-4">
        <x-foodalchemist::modal-section title="Anfrage / Briefing">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
                <div><label class="{{ $label }}">Pax</label><input type="number" min="0" wire:model="form.personen" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="treibt den Auto-Gesamtpreis" /></div>
                <div><label class="{{ $label }}">Event-Datum</label><input type="date" wire:model="form.event_date" class="{{ $input }}" /></div>
                <div class="md:col-span-2"><label class="{{ $label }}">Anlass</label><input type="text" wire:model="form.occasion" class="{{ $input }}" placeholder="Hochzeit, Firmenfeier …" /></div>
                <div><label class="{{ $label }}">Budget €</label><input type="number" step="0.01" wire:model="form.budget" class="{{ $input }} text-right tabular-nums" /></div>
                <div><label class="{{ $label }}">Gültig bis</label><input type="date" wire:model="form.valid_until" class="{{ $input }}" /></div>
                <div class="md:col-span-2"><label class="{{ $label }}">Location</label><input type="text" wire:model="form.location" class="{{ $input }}" /></div>
                <div class="md:col-span-2"><label class="{{ $label }}">Diät / Allergien</label><input type="text" wire:model="form.diet_requirement" class="{{ $input }}" /></div>
            </div>
            <div class="mt-3"><label class="{{ $label }}">Briefing</label><textarea rows="4" wire:model="form.brief" class="{{ $input }}"></textarea></div>
        </x-foodalchemist::modal-section>
        </div>

        {{-- ═══ Tab: AUFBAU (Foodbook-Komposition: Kapitel + Header + Concept/Format-Picker) ═══ --}}
        <div x-show="tab === 'aufbau'" x-data="{ bau: true }" x-cloak class="pt-4 space-y-3">
            @php($kapitelListe = $komposition['kapitel'] ?? [])
            @php($quelleBadge = ['konzept' => ['Konzept-Wording', 'text-violet-600', 'bg-violet-500'], 'standard' => ['VK-Wording (Standard)', 'text-gray-500', 'bg-gray-400'], 'name' => ['Wording fehlt — interner Name', 'text-amber-600', 'bg-amber-500']])

            {{-- Toolbar --}}
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="kapitelNeu" class="{{ $btnGhostXs }}" data-angebot-kapitel-neu>+ Kapitel</button>
                    <button type="button" wire:click="vollKaskadeStarten" wire:loading.attr="disabled" wire:target="vollKaskadeStarten"
                            class="{{ $btnGhostXs }} text-violet-600" data-angebot-vollkaskade>
                        <span wire:loading.remove wire:target="vollKaskadeStarten">@svg('heroicon-o-bolt', 'w-3.5 h-3.5 inline align-text-bottom') Voll-Kaskade (KI)</span>
                        <span wire:loading wire:target="vollKaskadeStarten">Starte …</span>
                    </button>
                </div>
                <div class="inline-flex rounded-lg bg-black/5 p-0.5 text-xs">
                    <button type="button" @click="bau = true" :class="bau ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-3 py-1 rounded-md">Bearbeiten</button>
                    <button type="button" @click="bau = false" :class="!bau ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-3 py-1 rounded-md">Menü-Ansicht</button>
                </div>
            </div>

            {{-- ─── BEARBEITEN ─── --}}
            <div x-show="bau" x-cloak class="space-y-3">
                @forelse($kapitelListe as $kap)
                    <section wire:key="okap-{{ $kap['id'] }}" class="rounded-xl bg-gray-500/[0.05] border border-black/5 p-3" data-angebot-kapitel="{{ $kap['id'] }}">
                        <div class="flex items-center gap-2 pb-2">
                            @if($kap['ist_format'])
                                <span class="{{ $pill }} {{ $variantPill['primary'] }} shrink-0">@svg('heroicon-o-rectangle-stack', 'w-3.5 h-3.5 inline-block align-middle') Format</span>
                            @endif
                            <input type="text" value="{{ $kap['title_intern'] }}" wire:change="kapitelUmbenennen({{ $kap['id'] }}, $event.target.value)" class="{{ $input }} !py-1 flex-1 font-semibold" title="Interner Kapitel-Titel" />
                            @if($kap['ist_format'])
                                <select wire:change="formatPreisModus({{ $kap['id'] }}, $event.target.value)" class="{{ $input }} !py-1 !w-auto text-xs shrink-0" title="Wie das Format in den Preis einfällt">
                                    <option value="additiv" @selected($kap['format_price_mode'] === 'additiv')>additiv (Σ)</option>
                                    <option value="alternativen" @selected($kap['format_price_mode'] === 'alternativen')>Alternativen</option>
                                </select>
                            @endif
                            <span class="text-[11px] text-gray-500 tabular-nums shrink-0">
                                @if($kap['ist_format'] && $kap['format_price_mode'] === 'alternativen' && $kap['preis_range'])
                                    {{ $kap['preis_range']['min'] !== null ? number_format((float) $kap['preis_range']['min'], 2, ',', '.') : '—' }}–{{ $kap['preis_range']['max'] !== null ? number_format((float) $kap['preis_range']['max'], 2, ',', '.') : '—' }} €/P
                                @elseif($kap['vk_pro_person'] !== null)
                                    {{ number_format((float) $kap['vk_pro_person'], 2, ',', '.') }} €/P
                                @endif
                            </span>
                            <button type="button" wire:click="kapitelWeg({{ $kap['id'] }})" wire:confirm="Kapitel entfernen?" class="text-gray-500 hover:text-red-500 shrink-0" title="Kapitel entfernen">✕</button>
                        </div>

                        @if($kap['ist_format'])
                            {{-- Editionen live aus dem Format (read-only im Angebot) --}}
                            <div class="space-y-1 text-xs">
                                @foreach($kap['editionen'] as $ed)
                                    <div class="flex items-center justify-between gap-2 px-2 py-1 rounded-lg bg-white/50">
                                        <span class="truncate {{ $ed['typ'] !== 'concept' ? 'text-gray-500 italic' : '' }}">{{ $ed['typ'] === 'concept' ? $ed['name'] : ('— ' . ($ed['name'] ?: $ed['typ'])) }}</span>
                                        @if(($ed['preis_pp'] ?? null) !== null)<span class="text-gray-500 tabular-nums shrink-0">{{ number_format((float) $ed['preis_pp'], 2, ',', '.') }} €</span>@endif
                                    </div>
                                @endforeach
                                <p class="text-[10px] text-gray-400">Inhalt live aus dem Format — Editionen im Format-Editor ändern.</p>
                            </div>
                        @else
                            {{-- Blöcke des Kapitels --}}
                            <div class="space-y-1">
                                @foreach($kap['bloecke'] as $b)
                                    <div wire:key="oblk-{{ $b['id'] }}" class="flex items-center gap-2 px-2 py-1 rounded-lg bg-black/[0.05] text-xs" data-angebot-block="{{ $b['id'] }}">
                                        @if($b['ist_header'])
                                            <span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">Header</span>
                                            <input type="text" value="{{ $b['label'] }}" wire:change="blockLabel({{ $b['id'] }}, $event.target.value)" class="{{ $input }} !py-0.5 flex-1" />
                                        @elseif($b['type'] === 'concept_ref')
                                            <span class="flex-1 min-w-0 truncate">{{ $b['label'] }}</span>
                                            <span class="text-gray-500 tabular-nums shrink-0">{{ number_format((float) $b['preis_pp'], 2, ',', '.') }} €/P</span>
                                        @else
                                            <span class="flex-1 min-w-0 truncate text-gray-500 italic">{{ $b['label'] ?: '(Text)' }}</span>
                                        @endif
                                        <button type="button" wire:click="blockWeg({{ $b['id'] }})" class="text-gray-500 hover:text-red-500 shrink-0" title="Entfernen">✕</button>
                                    </div>
                                @endforeach
                            </div>
                            {{-- Add-Leiste je Kapitel --}}
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <button type="button" wire:click="pickerOeffnen({{ $kap['id'] }})" class="{{ $btnGhostXs }} text-violet-600" data-angebot-concept-open="{{ $kap['id'] }}">+ Concept</button>
                                <button type="button" wire:click="blockHeaderAdd({{ $kap['id'] }})" class="{{ $btnGhostXs }}">+ Header</button>
                                <button type="button" wire:click="blockTextAdd({{ $kap['id'] }})" class="{{ $btnGhostXs }}">+ Text</button>
                            </div>
                            {{-- Inline-Concept-Picker (nur für dieses Kapitel) --}}
                            @if($pickerChapterId === $kap['id'])
                                <div class="mt-2 border-t border-white/10 pt-2" data-angebot-concept-picker="{{ $kap['id'] }}">
                                    <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen … (mit + einsetzen)" class="{{ $input }} w-full mb-2" />
                                    @php($facettenAktiv = collect($conceptFacetten)->filter(fn ($v) => $v !== null)->isNotEmpty())
                                    <div class="flex flex-wrap gap-1 mb-2">
                                        <button type="button" wire:click="resetConceptFacetten" class="{{ $pill }} {{ ! $facettenAktiv ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                                        @foreach($facetteServierformen as $sf)
                                            <button type="button" wire:key="apf-sf-{{ $sf->id }}" wire:click="toggleConceptFacet('servierform', {{ $sf->id }})" class="{{ $pill }} {{ $conceptFacetten['servierform'] === $sf->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sf->label }}</button>
                                        @endforeach
                                        @foreach($facetteEventtypen as $et)
                                            <button type="button" wire:key="apf-ev-{{ $et->id }}" wire:click="toggleConceptFacet('eventtyp', {{ $et->id }})" class="{{ $pill }} {{ $conceptFacetten['eventtyp'] === $et->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $et->name }}</button>
                                        @endforeach
                                    </div>
                                    <div class="max-h-56 overflow-y-auto space-y-0.5">
                                        @forelse($katalogTreffer as $kt)
                                            <button type="button" wire:key="apc-{{ $kap['id'] }}-{{ $kt->id }}" wire:click="blockConceptAdd({{ $kap['id'] }}, {{ $kt->id }})"
                                                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                                <span class="truncate text-gray-900">+ {{ $kt->name }}</span>
                                                <span class="text-gray-500 tabular-nums shrink-0">{{ $kt->price_per_person_cache !== null ? number_format((float) $kt->price_per_person_cache, 2, ',', '.') . ' €' : '' }}</span>
                                            </button>
                                        @empty
                                            <p class="text-[11px] text-gray-500 px-2 py-2">Keine Concepts für diese Auswahl.</p>
                                        @endforelse
                                    </div>
                                </div>
                            @endif
                        @endif
                    </section>
                @empty
                    <p class="text-[11px] text-gray-500">Noch kein Aufbau. „+ Kapitel" anlegen, dann je Kapitel „+ Concept"/„+ Header" — oder unten „+ Format als Kapitel" einsetzen.</p>
                @endforelse

                {{-- Format als Kapitel einsetzen (Kapitel-Ebene, lebend) --}}
                <div x-data="{ fp: false }" class="rounded-xl border border-dashed border-black/10 p-3">
                    <button type="button" @click="fp = !fp" class="{{ $btnGhostXs }} text-violet-600" data-angebot-format-open>@svg('heroicon-o-rectangle-stack', 'w-3.5 h-3.5 inline align-text-bottom') + Format als Kapitel</button>
                    <div x-show="fp" x-cloak class="mt-2" data-angebot-format-picker>
                        <input type="search" wire:model.live.debounce.300ms="formatSuche" placeholder="Format suchen … (z. B. Tagesveranstaltung)" class="{{ $input }} w-full mb-2" />
                        <div class="max-h-56 overflow-y-auto space-y-0.5">
                            @forelse($formatTreffer as $ft)
                                <button type="button" wire:key="afmt-{{ $ft->id }}" wire:click="formatEinsetzen({{ $ft->id }})"
                                        class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                    <span class="truncate text-gray-900">+ {{ $ft->name }}</span>
                                    <span class="text-gray-500 truncate shrink-0">{{ $ft->consumer_name }}</span>
                                </button>
                            @empty
                                <p class="text-[11px] text-gray-500 px-2 py-2">Keine Formate angelegt.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- ─── MENÜ-ANSICHT (Gäste-Sicht, read-only) ─── --}}
            <div x-show="!bau" x-cloak class="space-y-3" data-angebot-menue>
                @forelse($kapitelListe as $kap)
                    <section wire:key="omen-{{ $kap['id'] }}" class="rounded-xl bg-gray-500/[0.05] border border-black/5 p-3">
                        <div class="flex items-baseline gap-2 pb-2">
                            @if($kap['ist_format'])<span class="{{ $pill }} {{ $variantPill['primary'] }} shrink-0">Format</span>@endif
                            <h4 class="text-sm font-semibold text-gray-900">{{ $kap['title'] }}</h4>
                            <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">
                                @if($kap['ist_format'] && $kap['format_price_mode'] === 'alternativen' && $kap['preis_range'])
                                    {{ $kap['preis_range']['min'] !== null ? number_format((float) $kap['preis_range']['min'], 2, ',', '.') : '—' }}–{{ $kap['preis_range']['max'] !== null ? number_format((float) $kap['preis_range']['max'], 2, ',', '.') : '—' }} €/P
                                @elseif($kap['vk_pro_person'] !== null)
                                    {{ number_format((float) $kap['vk_pro_person'], 2, ',', '.') }} €/P
                                @endif
                            </span>
                        </div>
                        @if($kap['text'])<p class="text-[13px] text-gray-600 italic leading-snug pb-2 -mt-1">{{ $kap['text'] }}</p>@endif

                        @php($gruppen = $kap['ist_format'] ? $kap['editionen'] : $kap['bloecke'])
                        @foreach($gruppen as $g)
                            @php($istEdition = $kap['ist_format'])
                            @php($istHeader = $istEdition ? in_array($g['typ'] ?? '', ['header','text','spacer'], true) : ($g['ist_header'] ?? false))
                            @php($gTitel = $istEdition ? ($g['name'] ?? '') : ($g['label'] ?? ''))
                            @php($gZeilen = $g['gerichte'] ?? [])
                            @php($gVk = $istEdition ? ($g['preis_pp'] ?? null) : ($g['preis_pp'] ?? null))
                            @php($gEk = $g['ek_pp'] ?? null)
                            @if($istHeader && empty($gZeilen))
                                <div class="pt-1"><span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ $gTitel }}</span></div>
                            @else
                                <div class="rounded-lg bg-white/50 border border-black/5 px-3 py-2 mb-1.5">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-[13px] font-semibold text-gray-900">{{ $gTitel }}</span>
                                        <span class="text-[11px] text-gray-500 tabular-nums shrink-0">
                                            @if($gVk !== null)VK/P {{ number_format((float) $gVk, 2, ',', '.') }} €@endif
                                            @if($gEk !== null) · EK {{ number_format((float) $gEk, 2, ',', '.') }} €@endif
                                            @if($gVk !== null && (float) $gVk > 0 && $gEk !== null) · {{ number_format((float) $gEk / (float) $gVk * 100, 1, ',', '.') }} %@endif
                                        </span>
                                    </div>
                                    @foreach($gZeilen as $z)
                                        @php($src = $z['source'] ?? null)
                                        @php($qb = $src !== null ? ($quelleBadge[$src] ?? $quelleBadge['name']) : null)
                                        <div class="flex items-center gap-1.5 mt-1 text-[12px]" style="padding-left: {{ (int) ($z['einrueckung'] ?? 0) * 12 }}px">
                                            @if(($z['type'] ?? '') === 'header')
                                                <span class="font-semibold uppercase tracking-wider text-[10.5px] text-gray-500">{{ $z['text'] }}</span>
                                            @elseif(($z['type'] ?? '') === 'paket')
                                                <span class="font-medium text-gray-700">{{ $z['text'] }}</span>
                                            @else
                                                @if($qb)<span class="w-1.5 h-1.5 rounded-full {{ $qb[2] }} shrink-0" title="{{ $qb[0] }}"></span>@endif
                                                <span class="{{ $src === 'name' ? 'italic text-amber-700' : 'text-gray-800' }}">{{ $z['text'] }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </section>
                @empty
                    <p class="text-xs text-gray-500 py-8 text-center">Noch kein Aufbau — links in „Bearbeiten" Kapitel + Concepts/Formate einsetzen.</p>
                @endforelse

                <div class="flex flex-wrap gap-3 text-[11px] text-gray-600 pt-1 border-t border-black/5">
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span>Konzept-Wording</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>VK-Wording Standard</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>kein Wording — Handlungsbedarf</span>
                    <span class="ml-auto italic">Kette: Foodbook-Override → Konzept → Standard → Name</span>
                </div>
            </div>
        </div>

        {{-- ═══ Tab: KALKULATION ═══ --}}
        <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 space-y-4">
            @if($kalkulation)
            <x-foodalchemist::modal-section title="Kalkulation (Pax × Aufbau)">
                @if($kalkulation['leer'])
                    <p class="text-[11px] text-gray-500">Noch kein Menü (Tab „Aufbau"). Pax: {{ $kalkulation['pax'] ?: '—' }}.</p>
                @else
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-3">
                        <div class="{{ $row }}"><span class="{{ $dt }}">€/Person</span><span class="{{ $dd }} tabular-nums">{{ number_format($kalkulation['vk_pro_person'],2,',','.') }} €</span></div>
                        <div class="{{ $row }}"><span class="{{ $dt }}">Pax</span><span class="{{ $dd }} tabular-nums">{{ $kalkulation['pax'] ?: '—' }}</span></div>
                        <div class="{{ $row }}"><span class="{{ $dt }}">Wareneinsatz</span><span class="{{ $dd }} tabular-nums">{{ $kalkulation['wareneinsatz_pct'] !== null ? number_format($kalkulation['wareneinsatz_pct'],1,',','.').' %' : '—' }}</span></div>
                        <div class="{{ $row }}"><span class="{{ $dt }}">HK2/P</span><span class="{{ $dd }} tabular-nums">{{ number_format($kalkulation['hk2_pro_person'],2,',','.') }} €</span></div>
                    </div>
                    <div class="flex items-center justify-between rounded-lg bg-violet-500/10 px-3 py-2 mt-2">
                        <span class="text-xs text-gray-600">Gesamt · {{ $kalkulation['price_mode']==='auto' ? 'auto' : 'fixiert' }}</span>
                        <span class="text-sm font-semibold tabular-nums text-gray-900">{{ number_format($kalkulation['gesamt_vk'],2,',','.') }} €</span>
                    </div>
                    <div class="text-[11px] text-gray-500 text-right mt-1">Deckungsbeitrag {{ number_format($kalkulation['gesamt_db'],2,',','.') }} € · EK {{ number_format($kalkulation['gesamt_ek'],2,',','.') }} €</div>
                    @if(count($kalkulation['alternativen'] ?? []))
                        <div class="mt-2 text-[11px] text-gray-500">
                            <span class="font-medium">Alternativ-Formate (nicht im Gesamtpreis):</span>
                            @foreach($kalkulation['alternativen'] as $alt)
                                <span class="inline-block ml-1">{{ $alt['name'] }} ({{ $alt['min'] !== null ? number_format((float)$alt['min'],2,',','.') : '—' }}–{{ $alt['max'] !== null ? number_format((float)$alt['max'],2,',','.') : '—' }} €/P)</span>
                            @endforeach
                        </div>
                    @endif
                    @if($kalkulation['pax'] > 0)
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3 text-xs">
                            <div><span class="block text-[10px] text-gray-500">Mindestpreis</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['mindestpreis'],2,',','.') }} €</span></div>
                            <div><span class="block text-[10px] text-gray-500">Zielpreis</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['zielpreis'],2,',','.') }} €</span></div>
                            <div><span class="block text-[10px] text-gray-500">Zielpreis / Person</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['zielpreis_pro_person'],2,',','.') }} €</span></div>
                            <div><span class="block text-[10px] text-gray-500">Aktive Personenzeit</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['aktive_personenminuten'] / 60,2,',','.') }} h</span></div>
                        </div>
                        @if($kalkulation['unwirtschaftlich'])
                            <div class="mt-3 rounded-lg border border-amber-400/50 bg-amber-500/10 px-3 py-2 text-xs text-amber-700">
                                Der Angebotspreis liegt {{ number_format($kalkulation['zielabweichung'],2,',','.') }} € unter dem Zielpreis. Der Preis wurde nicht automatisch erhöht.
                            </div>
                        @endif
                        @if(count($kalkulation['warnungen']))
                            <p class="mt-2 text-[10px] text-amber-700">{{ implode(' · ', $kalkulation['warnungen']) }}</p>
                        @endif
                    @endif
                @endif
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div><label class="{{ $label }}">Preis-Modus</label>
                        <select wire:model="form.price_mode" class="{{ $input }}"><option value="auto">Auto (Pax × Aufbau)</option><option value="fixed">Fixiert</option></select></div>
                    @if(in_array($form['price_mode'] ?? 'auto', ['fixed', 'manuell'], true))
                        <div><label class="{{ $label }}">Gesamtpreis € (manuell)</label>
                            <input type="number" step="0.01" wire:model="form.total_price" class="{{ $input }} text-right tabular-nums" /></div>
                        <div class="col-span-2"><label class="{{ $label }}">Begründung</label>
                            <div class="flex gap-2"><input type="text" wire:model="form.price_override_reason" class="{{ $input }}" placeholder="Warum weicht der Angebotspreis ab?" />
                            <button type="button" wire:click="speichern" class="{{ $btnGhostXs }} text-violet-600 shrink-0">Fixpreis übernehmen</button></div></div>
                    @else
                        <div class="flex items-end"><button type="button" wire:click="speichern" class="{{ $btnGhostXs }} text-violet-600">Auto-Preis übernehmen</button></div>
                    @endif
                </div>
            </x-foodalchemist::modal-section>
            @endif

            {{-- Mengen-Hochrechnung für die Pax --}}
            @if($kalkulation && ! $kalkulation['leer'] && $kalkulation['pax'] > 0 && count($kalkulation['mengen']))
            <x-foodalchemist::modal-section title="Mengen für {{ $kalkulation['pax'] }} Pax">
                <div class="space-y-0.5 max-h-64 overflow-y-auto">
                    @foreach($kalkulation['mengen'] as $m)
                        <div wire:key="mng-{{ $loop->index }}" class="flex items-center justify-between gap-2 text-[11px]">
                            <span class="truncate text-gray-600">{{ $m['gericht'] ?? '—' }}</span>
                            <span class="tabular-nums text-gray-600 shrink-0">{{ $m['gesamt_menge'] !== null ? rtrim(rtrim(number_format($m['gesamt_menge'],2,',','.'),'0'),',').' '.($m['unit'] ?? '') : '—' }}</span>
                        </div>
                    @endforeach
                </div>
            </x-foodalchemist::modal-section>
            @endif
        </div>

        {{-- ═══ Tab: GERÜST (UX-Ausbau: Slots VOR der Voll-Kaskade prüfen/bauen) ═══ --}}
        <div x-show="tab === 'geruest'" x-cloak class="pt-4 space-y-4">
            <x-foodalchemist::modal-section title="Planungs-Gerüst (Slots für die Voll-Kaskade)">
                <p class="text-[11px] text-gray-500 mb-2">Lege die Slots fest (z. B. Vorspeise · Hauptgang · Dessert) — die Voll-Kaskade erzeugt je Slot ein Menü-Konzept und referenziert es ans Angebot. Ohne Gerüst strukturiert die Voll-Kaskade automatisch aus Anlass/Gäste.</p>
                <div class="flex gap-2 mb-2">
                    <input type="text" wire:model="neuerSlot" wire:keydown.enter="geruestSlotNeu" placeholder="Slot-Label (z. B. Hauptgang) …" class="{{ $input }}" data-angebot-slot-input />
                    <button type="button" wire:click="geruestSlotNeu" class="{{ $btnGhostXs }} shrink-0" data-angebot-slot-neu>+ Slot</button>
                    <button type="button" wire:click="geruestKickoff" wire:loading.attr="disabled" wire:target="geruestKickoff" class="{{ $btnGhostXs }} shrink-0 text-violet-600" data-angebot-geruest-kickoff>
                        <span wire:loading.remove wire:target="geruestKickoff">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline align-text-bottom') KI-Vorschlag</span>
                        <span wire:loading wire:target="geruestKickoff">…</span>
                    </button>
                </div>
                <div class="space-y-1.5">
                    @forelse($geruestSlots as $slot)
                        <div wire:key="ang-slot-{{ $slot->id }}" class="flex items-center gap-2 px-2 py-1 rounded-lg bg-black/[0.05] text-xs">
                            <span class="flex-1 truncate text-gray-800">{{ $slot->label }}</span>
                            @if($slot->target_count)<span class="text-gray-500">×{{ $slot->target_count }}</span>@endif
                            @if($slot->price_anchor !== null)<span class="text-gray-500 tabular-nums">{{ number_format((float) $slot->price_anchor, 2, ',', '.') }} €</span>@endif
                            <button type="button" wire:click="geruestSlotLoeschen({{ $slot->id }})" class="text-gray-500 hover:text-red-500 shrink-0" title="Slot löschen">✕</button>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-500">Noch keine Slots. „+ Slot" oder „KI-Vorschlag" anlegen — dann Voll-Kaskade (Aufbau-Tab).</p>
                    @endforelse
                </div>
            </x-foodalchemist::modal-section>
        </div>

        {{-- ═══ Tab: KUNDE & BUSINESS-CASE ═══ --}}
        <div x-show="tab === 'kunde'" x-cloak class="pt-4 space-y-4">
            <x-foodalchemist::modal-section title="Kunde (CRM)">
                <x-foodalchemist::crm-kunde-picker
                    :ausgabe="$angebot" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Business-Case (Canvas)">
                @include('foodalchemist::livewire.canvas.partials.board')
            </x-foodalchemist::modal-section>
        </div>
    </x-foodalchemist::editor-tabs>
    @endif
</x-foodalchemist::modal>
