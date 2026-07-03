{{-- M10R-3 / Doc 15 §10.4: Voll-Editor-Modal (VK-Stil) — Kopf + Tabs (Aufbau/Nährwerte/Allergene/Kalkulation/Notizen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($item = $concept ?? $paket)
@php($titel = $item?->name ?? 'Editor')
@php($tabAktiv = 'border-violet-500 text-violet-700 dark:text-violet-300')
@php($tabIdle = 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300')
@php($konfPill = ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']])
{{-- Phase 5: Typ-Farbe als Inline-Style (dynamisch aus Settings) — Text = Hex, Hintergrund = Hex+1a (10%). --}}
@php($typStyle = fn (string $t) => isset($typFarben[$t]) ? 'color:' . $typFarben[$t] . ';background-color:' . $typFarben[$t] . '1a' : '')

<div>
    <x-foodalchemist::modal name="concepter-editor" :title="$titel" fullscreen>
        <x-slot:actions>
            @if($paket && $rueckSprungConceptId)
                <button type="button" wire:click="zurueckZumConcept" class="{{ $btnGhost }}" title="Paket sichern und zurück ins Concept">← Speichern &amp; zurück zum Concept</button>
            @endif
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
            @if($concept && ! $concept->is_vorlage)
                <button type="button" wire:click="alsVorlage" class="{{ $btnGhost }}">Als Vorlage speichern</button>
            @endif
            @if($fehler)<span class="{{ $pill }} {{ $variantPill['danger'] }}">{{ $fehler }}</span>@endif
            @if($bewertung)
                @php($scorePill = $bewertung['score'] >= 80 ? $variantPill['success'] : ($bewertung['score'] >= 50 ? $variantPill['warning'] : $variantPill['danger']))
                <span class="{{ $pill }} {{ $scorePill }}" title="Menü-Bewertung (Anteil bestandener Checks)">Score {{ $bewertung['score'] }}</span>
            @endif
        </x-slot:actions>

        {{-- Phase 1: Live-Kosten-Streifen fix im Modal-Kopf (immer sichtbar, alle Tabs) --}}
        @if($kalkulation)
            <x-slot:kpiHeader>
                @php($stripVk = $concept ? ($cockpit['preis_pro_person'] ?? 0) : ($paket?->preis_pro_person !== null ? (float) $paket->preis_pro_person : null))
                @php($stripEk = (float) ($kalkulation['hk1_pro_person'] ?? 0))
                @php($stripWpct = ($stripVk !== null && $stripVk > 0) ? $stripEk / $stripVk * 100 : null)
                <div class="grid grid-cols-3 md:grid-cols-7 gap-2">
                    <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                        <span class="text-[10px] uppercase tracking-wider text-violet-600 dark:text-violet-400">VK €/Person</span>
                        <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ $stripVk !== null ? number_format((float) $stripVk, 2, ',', '.') . ' €' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Wareneinsatz/Pers.</span>
                        <p class="text-sm font-semibold tabular-nums">{{ number_format($stripEk, 2, ',', '.') }} €</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Wareneinsatz %</span>
                        <p class="text-sm font-semibold tabular-nums">{{ $stripWpct !== null ? number_format($stripWpct, 1, ',', '.') . ' %' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">HK2 (Vollkosten)</span>
                        <p class="text-sm font-semibold tabular-nums">{{ number_format((float) ($kalkulation['hk2_pro_person'] ?? 0), 2, ',', '.') }} €</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">VK-Vorschlag</span>
                        <p class="text-sm font-semibold tabular-nums text-violet-700 dark:text-violet-300">{{ number_format((float) ($kalkulation['vk_vorschlag'] ?? 0), 2, ',', '.') }} €</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Deckungsbeitrag</span>
                        <p class="text-sm font-semibold tabular-nums {{ ($kalkulation['db_eur'] ?? 0) < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $kalkulation['db_eur'] !== null ? number_format((float) $kalkulation['db_eur'], 2, ',', '.') . ' €' : '—' }}</p>
                    </div>
                    @isset($aggregat['gewicht_pro_person_g'])
                        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-kpi-gewicht>
                            <span class="{{ $dt }}">Gewicht/P</span>
                            <p class="text-sm font-semibold tabular-nums">{{ number_format((float) $aggregat['gewicht_pro_person_g'], 0, ',', '.') }} g{!! ($aggregat['gewicht_vollstaendig'] ?? true) ? '' : ' <span class="text-amber-500 font-normal" title="≥1 Position ohne Portionsgewicht — Gewicht unvollständig">~</span>' !!}</p>
                        </div>
                    @endisset
                </div>
            </x-slot:kpiHeader>
        @endif

        @if($item === null)
            <p class="text-sm text-gray-400 py-10 text-center">Nichts geladen.</p>
        @else
            {{-- ── Kopf ──────────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <div class="md:col-span-2">
                    <label class="{{ $label }}">Bezeichnung (intern)</label>
                    <input type="text" wire:model="form.name" class="{{ $input }}" />
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">Konsumentenbezeichnung</label>
                    <input type="text" wire:model="form.konsumenten_name" class="{{ $input }}" placeholder="z. B. „Sommerliche Vorspeisen-Auswahl"" />
                </div>
                <div>
                    <label class="{{ $label }}">Klasse</label>
                    <input type="text" wire:model="form.klasse" list="concepter-klassen" class="{{ $input }}" placeholder="frei/wählbar" />
                    <datalist id="concepter-klassen">@foreach($klassen as $k)<option value="{{ $k }}"></option>@endforeach</datalist>
                </div>
                <div>
                    <label class="{{ $label }}">Niveau</label>
                    <select wire:model="form.niveau" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['klassisch' => 'klassisch', 'gehoben' => 'gehoben', 'haute' => 'haute'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </div>

                @if($concept)
                    <div>
                        <label class="{{ $label }}">Anlass</label>
                        <input type="text" wire:model="form.anlass" class="{{ $input }}" placeholder="z. B. Sommerfest" />
                    </div>
                    <div>
                        <label class="{{ $label }}">Kategorie</label>
                        <select wire:model="form.category_id" class="{{ $input }}">
                            <option value="">— ohne —</option>
                            @foreach($kategorienFlat as $kat)<option value="{{ $kat['id'] }}">{{ $kat['label'] }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Servierform</label>
                        <select wire:model="form.servierform_id" class="{{ $input }}" title="Steuert die Darreichungs-Auflösung der Gerichte (Slot → passende Variante)">
                            <option value="">—</option>
                            @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->bezeichnung }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Eventtyp</label>
                        <select wire:model="form.eventtyp_id" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">
                            @foreach(['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Geschmack</label>
                        <select wire:model="form.geschmacksrichtung" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label class="{{ $label }}">Rolle</label>
                        <input type="text" wire:model="form.rolle" list="concepter-rollen" class="{{ $input }}" placeholder="z. B. Vorspeise" />
                        <datalist id="concepter-rollen">@foreach($rollen as $r)<option value="{{ $r }}"></option>@endforeach</datalist>
                    </div>
                @endif
            </div>

            @if($concept)
                {{-- Facetten: Einsatzmomente + Saisons (mehrfach, Umbau-Spec Phase 4b) --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $label }} !mb-0 mr-1">Einsatzmoment</span>
                        @foreach($einsatzmomente as $em)
                            <button type="button" wire:click="toggleFacette('einsatzmoment_ids', {{ $em->id }})"
                                class="{{ $pill }} {{ in_array($em->id, $form['einsatzmoment_ids'] ?? []) ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $em->name }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $label }} !mb-0 mr-1">Saison</span>
                        @foreach($saisons as $sa)
                            <button type="button" wire:click="toggleFacette('saison_ids', {{ $sa->id }})"
                                class="{{ $pill }} {{ in_array($sa->id, $form['saison_ids'] ?? []) ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sa->name }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Tab-Nav ───────────────────────────────────────────────── --}}
            <div class="flex gap-4 border-b border-black/5 dark:border-white/10 mt-1">
                @php($editorTabs = ['aufbau' => 'Aufbau'])
                @if($concept)@php($editorTabs['konzept'] = 'Konzept')@endif
                {{-- 'allergene'-Key bleibt stabil, Label seit 2026-07-02 „Deklaration" (Diät-Rollup + Nährwerte/Person — Parität zu Rezept-/VK-Modal) --}}
                @php($editorTabs['allergene'] = 'Deklaration')
                @php($editorTabs['kalkulation'] = 'Kalkulation')
                @if($concept)@php($editorTabs['geschirr'] = 'Geschirr')@endif
                @if($concept)@php($editorTabs['sensorik'] = 'Sensorik')@endif
                @php($editorTabs['notizen'] = 'Notizen')
                @foreach($editorTabs as $k => $l)
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="px-1 py-2 text-xs font-medium border-b-2 -mb-px transition-colors {{ $tab === $k ? $tabAktiv : $tabIdle }}">{{ $l }}</button>
                @endforeach
            </div>

            {{-- ── Tab: AUFBAU ───────────────────────────────────────────── --}}
            @if($tab === 'aufbau')
                {{-- Live-Kosten-Streifen ist jetzt fix im Modal-Kopf (Phase 1, x-slot:kpiHeader). --}}
                @if($concept)
                {{-- x-data hält den Drag-Zustand: dragTyp/dragId = Liste→einfügen, dragSlotId = Position umsortieren. --}}
                <div class="flex gap-3 items-start" x-data="{ dragTyp: null, dragId: null, dragSlotId: null }">
                {{-- Phase 3: linke Spalte — Basisrezepte als Position einfügen (sticky Panel wie zutaten-kern) --}}
                <aside class="w-72 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] dark:bg-white/[0.05] border border-black/5 dark:border-white/10 p-2.5 sticky top-0 self-start max-h-[70vh]" data-konzept-basisliste>
                    {{-- Umschalter: Basisrezept ⇄ Paket (Pakete bei 300+ über Such-/Filter-Liste einfügen) --}}
                    <div class="flex gap-1 mb-1.5" data-linke-liste-umschalter>
                        <button type="button" wire:click="$set('linkeListe', 'basisrezept')" class="{{ $pill }} {{ $linkeListe === 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Basisrezept</button>
                        <button type="button" wire:click="$set('linkeListe', 'paket')" class="{{ $pill }} {{ $linkeListe === 'paket' ? $variantPill['primary'] : $variantPill['secondary'] }}">Paket</button>
                    </div>
                    @if($linkeListe === 'paket')
                        <p class="{{ $dt }} mb-1">Pakete ({{ $paketListe->count() }})</p>
                        <input type="search" wire:model.live.debounce.300ms="basisSuche" placeholder="Paket suchen (Name/Rolle) …" class="{{ $input }} !py-0.5 !text-[11px] mb-1" />
                        <select wire:model.live="paketKlasse" class="{{ $input }} !py-0.5 !text-[11px] mb-1.5" data-paket-filter-klasse>
                            <option value="">Alle Klassen</option>
                            @foreach($paketKlassenListe as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                        </select>
                        <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1" data-konzept-paketliste>
                            @forelse($paketListe as $pk)
                                <div wire:key="kpk-{{ $pk->id }}" draggable="true" @dragstart="dragTyp = 'paket'; dragId = {{ $pk->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px] cursor-grab active:cursor-grabbing">
                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider {{ $variantPill['info'] }}">PK</span>
                                    <span class="min-w-0 flex-1 break-words leading-snug text-gray-700 dark:text-gray-200" title="{{ $pk->name }}{{ $pk->rolle ? ' · ' . $pk->rolle : '' }}">{{ $pk->name }}</span>
                                    <span class="shrink-0 text-[10px] text-gray-400 tabular-nums">{{ $pk->preis_pro_person !== null ? number_format((float) $pk->preis_pro_person, 2, ',', '.') . ' €' : '' }}</span>
                                    <button type="button" wire:click="positionEinfuegen('paket', {{ $pk->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                                </div>
                            @empty
                                <p class="text-[10px] text-gray-400 px-1">— keine Treffer —</p>
                            @endforelse
                        </div>
                    @else
                        <p class="{{ $dt }} mb-1">Basisrezepte ({{ $basisListe->count() }})</p>
                        <input type="search" wire:model.live.debounce.300ms="basisSuche" placeholder="Basisrezept suchen …" class="{{ $input }} !py-0.5 !text-[11px] mb-1" />
                        <div class="space-y-1 mb-1.5">
                            <select wire:model.live="basisHg" class="{{ $input }} !py-0.5 !text-[11px]" data-basis-filter-hg>
                                <option value="">Alle Hauptgruppen</option>
                                @foreach($basisHauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->bezeichnung }}</option>@endforeach
                            </select>
                            <select wire:model.live="basisKat" class="{{ $input }} !py-0.5 !text-[11px]" data-basis-filter-kat @disabled($basisKategorien->isEmpty())>
                                <option value="">Alle Kategorien</option>
                                @foreach($basisKategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->bezeichnung }}</option>@endforeach
                            </select>
                            <select wire:model.live="basisNiveau" class="{{ $input }} !py-0.5 !text-[11px]" data-basis-filter-niveau>
                                <option value="">Jedes Niveau</option>
                                @foreach($basisNiveaus as $n)<option value="{{ $n['slug'] }}">{{ $n['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1">
                            @forelse($basisListe as $br)
                                <div wire:key="kbr-{{ $br->id }}" draggable="true" @dragstart="dragTyp = 'basisrezept'; dragId = {{ $br->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px] cursor-grab active:cursor-grabbing">
                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('basisrezept') }}">BR</span>
                                    <span class="min-w-0 flex-1 break-words leading-snug text-gray-700 dark:text-gray-200" title="{{ $br->name }}">{{ $br->name }}</span>
                                    <span class="shrink-0 text-[10px] text-gray-400 tabular-nums">{{ $br->ek_total_eur !== null ? number_format((float) $br->ek_total_eur, 2, ',', '.') . ' €' : '' }}</span>
                                    <button type="button" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $br->id }} })" class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Rezept einsehen">📖</button>
                                    <button type="button" wire:click="positionEinfuegen('basisrezept', {{ $br->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                                </div>
                            @empty
                                <p class="text-[10px] text-gray-400 px-1">— keine Treffer —</p>
                            @endforelse
                        </div>
                    @endif
                </aside>
                <div class="flex-1 min-w-0 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">Positionen</h3>
                        <div class="flex items-center gap-2">
                            {{-- Einfügen läuft über die Listen links/rechts (wie im Gerichte-Editor) + „+ Paket"/Struktur oben. --}}
                            @if($einfuegenNachId !== null)
                                <span class="inline-flex items-center gap-1 text-[11px] text-violet-600 dark:text-violet-400" data-einfuege-ziel>
                                    📍 Einfügen unter markierter Zeile
                                    <button type="button" wire:click="$set('einfuegenNachId', null)" class="underline decoration-dotted hover:text-violet-800">ans Ende</button>
                                </span>
                            @endif
                        </div>
                    </div>
                    {{-- Kombi-Suche (wie Gerichte-Editor): filtert BEIDE Seiten-Listen; Übernehmen per „+"/Drag in den Spalten. --}}
                    <input type="search" wire:model.live.debounce.300ms="kombiSuche" data-konzept-kombisuche
                           placeholder="Suchen — filtert Basisrezepte/Pakete UND Gerichte … (Übernehmen per + in den Spalten)"
                           class="{{ $input }} !py-2" />
                    {{-- B3: Struktur-Blöcke (freie Gliederung OHNE Paket) + „+ Paket" (= bepreister Abschnitt) --}}
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $label }} mr-1">Struktur:</span>
                        <button type="button" wire:click="neuesPaketAlsPosition" class="{{ $btnGhostXs }} !text-violet-600 dark:!text-violet-400 !border-violet-500/30" title="Neues Paket als Abschnitt anlegen, einfügen und öffnen">+ Paket</button>
                        <button type="button" wire:click="blockHinzu('header')" class="{{ $btnGhostXs }}">+ Header</button>
                        <button type="button" wire:click="blockHinzu('text')" class="{{ $btnGhostXs }}">+ Text</button>
                        <button type="button" wire:click="blockHinzu('spacer')" class="{{ $btnGhostXs }}">+ Leerzeile</button>
                    </div>
                    {{-- B4: aus markierten Gericht-/Basisrezept-Positionen ein Paket bilden --}}
                    @if(count($auswahl) > 0)
                        <div class="flex items-center gap-2 rounded-xl border border-violet-500/30 bg-violet-500/5 px-3 py-2" data-paket-bilden>
                            <span class="text-xs font-medium text-violet-700 dark:text-violet-300 shrink-0">{{ count($auswahl) }} markiert →</span>
                            <input type="text" wire:model="paketName" wire:keydown.enter="paketBilden" placeholder="Paket-Name (z. B. Grill-Hauptgang) …" class="{{ $input }} flex-1" />
                            <button type="button" wire:click="paketBilden" class="{{ $btnPrimary }}">Paket bilden</button>
                            <button type="button" wire:click="$set('auswahl', [])" class="{{ $btnGhostXs }}">Abbrechen</button>
                        </div>
                    @endif
                    <div class="overflow-x-auto">
                    <table class="{{ $table }} border-collapse">
                        <thead><tr class="text-left">
                            @foreach(['#' => 'w-px', 'Menge' => 'w-px', 'Einheit' => 'w-px', 'Verknüpfung / Beschreibung' => 'w-full', 'Rolle' => 'w-px', '€/P' => 'w-px', 'EK €' => 'w-px', 'W%' => 'w-px', '' => 'w-px'] as $kopf => $w)
                                <th class="{{ $th }} !px-2 {{ $w }}">{{ $kopf }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                        @forelse($concept->slots as $slot)
                            @php($istStruktur = in_array($slot->type, ['text', 'spacer', 'header', 'header_preis']))
                            @php($ekz = $cockpitZeilen[$slot->id]['ek'] ?? null)
                            @php($vkz = $cockpitZeilen[$slot->id]['preis'] ?? null)
                            @php($wpct = ($vkz && (float) $vkz > 0 && $ekz !== null) ? ((float) $ekz / (float) $vkz * 100) : null)
                            <tr wire:key="erow-{{ $slot->id }}"
                                @dragover.prevent
                                @drop.prevent="if (dragId) { $wire.positionDrop(dragTyp, dragId, {{ $slot->id }}); } else if (dragSlotId && dragSlotId !== {{ $slot->id }}) { $wire.positionVerschieben(dragSlotId, {{ $slot->id }}); } dragTyp = null; dragId = null; dragSlotId = null"
                                class="{{ $tr }} {{ $istStruktur ? 'bg-violet-500/[0.03]' : '' }} {{ $slot->paket_id ? 'bg-violet-500/[0.06] border-t-2 !border-t-violet-500/30' : '' }} {{ $einfuegenNachId === $slot->id ? 'border-b-2 !border-b-violet-400' : '' }}">
                                <td class="{{ $td }} !px-1.5 !py-0.5 whitespace-nowrap align-top">
                                    {{-- Ziehgriff: Position per Drag umsortieren (▲▼ bleibt als zuverlässige Alternative) --}}
                                    <span class="inline-block cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-500 select-none align-middle mr-0.5" draggable="true"
                                          @dragstart="dragSlotId = {{ $slot->id }}; $event.dataTransfer.effectAllowed = 'move'" @dragend="dragSlotId = null" title="ziehen zum Umsortieren">⠿</span>
                                    <span class="inline-flex flex-col align-middle leading-none">
                                        <button type="button" wire:click="slotHoch({{ $slot->id }})" class="text-[9px] text-gray-500 hover:text-violet-500 leading-none" title="hoch">▲</button>
                                        <button type="button" wire:click="slotRunter({{ $slot->id }})" class="text-[9px] text-gray-500 hover:text-violet-500 leading-none" title="runter">▼</button>
                                    </span>
                                    @if(! $istStruktur && $slot->vk_recipe_id)
                                        <input type="checkbox" wire:click="toggleAuswahl({{ $slot->id }})" @checked(in_array($slot->id, $auswahl)) class="ml-1 align-middle rounded border-gray-300 text-violet-500 focus:ring-violet-500/30" title="für „Paket bilden“ markieren" />
                                    @endif
                                </td>
                                @if($istStruktur)
                                    <td class="{{ $td }} !px-2" colspan="7">
                                        @if($slot->type === 'spacer')
                                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">Leerzeile</span>
                                            <select wire:model="blockForm.{{ $slot->id }}.hoehe" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-32 ml-1">
                                                @foreach(['klein', 'mittel', 'gross'] as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                                            </select>
                                        @elseif($slot->type === 'text')
                                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">Text</span>
                                            <input type="text" wire:model.blur="blockForm.{{ $slot->id }}.text_inhalt" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-full mt-1" placeholder="Freitext …" />
                                        @else
                                            <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $slot->type === 'header_preis' ? 'Header + Preis' : 'Header' }}</span>
                                            <input type="text" wire:model.blur="blockForm.{{ $slot->id }}.titel" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-full mt-1 font-medium" placeholder="Überschrift …" />
                                            @php($ss = $sektionSumme['h' . $slot->id] ?? null)
                                            @if($ss && $ss['n'] > 0)
                                                <div class="mt-1 text-[11px] text-violet-600 dark:text-violet-400 tabular-nums">{{ $ss['n'] }} {{ $ss['n'] === 1 ? 'Position' : 'Positionen' }} · Σ EK {{ number_format($ss['ek'], 2, ',', '.') }} € · {{ number_format($ss['vk'], 2, ',', '.') }} €/P</div>
                                            @endif
                                            @if($slot->type === 'header_preis')
                                                <span class="inline-flex items-center gap-1 mt-1">
                                                    <input type="number" step="0.01" min="0" wire:model.blur="blockForm.{{ $slot->id }}.preis_wert" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-24 text-right tabular-nums" placeholder="€" />
                                                    <select wire:model="blockForm.{{ $slot->id }}.preis_basis" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-28">
                                                        @foreach(['person' => '/Person', 'pauschal' => 'pauschal', 'staffel' => 'Staffel'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                                    </select>
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                @else
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->vk_recipe_id)
                                            <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.menge" wire:change="mengeSpeichern({{ $slot->id }})" class="{{ $input }} !w-16 text-right tabular-nums" placeholder="1" />
                                        @else<span class="text-gray-300">—</span>@endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->vk_recipe_id)
                                            <select wire:model="slotForm.{{ $slot->id }}.einheit_vocab_id" wire:change="mengeSpeichern({{ $slot->id }})" class="{{ $input }} !w-24">
                                                <option value="">—</option>
                                                @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                                            </select>
                                        @else<span class="text-gray-300">—</span>@endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->paket_id && $slot->paket)
                                            {{-- Paket = Abschnitts-Header (Gerichte stehen als eingerückte Zeilen darunter) --}}
                                            <span class="{{ $pill }} {{ $variantPill['info'] }}">📦 Paket</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $slot->paket->name }}</span>
                                            <button type="button" wire:click="paketOeffnen({{ $slot->paket_id }})" class="text-gray-400 hover:text-violet-500 align-middle" title="Paket öffnen / bearbeiten">📦</button>
                                            @if($slot->paket->klasse)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $slot->paket->klasse }}</span>@endif
                                            <span class="text-gray-400 text-[11px] tabular-nums">{{ $slot->paket->preis_pro_person !== null ? number_format((float) $slot->paket->preis_pro_person, 2, ',', '.') . ' €/P' : '' }}</span>
                                        @elseif($slot->vk_recipe_id && $slot->gericht)
                                            @php($g = $slot->gericht)
                                            @php($enthaelt = collect(['Schwein' => $g->spec_contains_pork, 'Rind' => $g->spec_contains_beef])->filter()->keys()->all())
                                            @php($allTitle = 'Allergene / Diät' . (count($enthaelt) ? ' — enthält ' . implode(', ', $enthaelt) : '') . ' · Konfidenz ' . ($g->allergene_konfidenz ?? 'unbekannt'))
                                            <span class="{{ $pill }} font-medium" style="{{ $typStyle($slot->type === 'basisrezept' ? 'basisrezept' : 'gericht') }}">{{ $slot->type === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</span>
                                            <span class="text-sm font-medium">{{ $g->name }}</span>
                                            @if($g->speisenKlasse)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $g->speisenKlasse->bezeichnung }}</span>@endif
                                            @if($g->spec_is_vegan)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>@elseif($g->spec_is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg.</span>@endif
                                            <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-medium align-middle {{ count($enthaelt) ? 'bg-amber-500/20 text-amber-700 dark:text-amber-300' : 'bg-black/5 dark:bg-white/10 text-gray-400' }}" title="{{ $allTitle }}">A</span>
                                            {{-- Phase 6: einsehen — Basisrezept → Rezept-Fenster, VK-Gericht → Gericht-Fenster (über dem Editor) --}}
                                            <button type="button" @click="Livewire.dispatch('{{ $slot->type === 'basisrezept' ? 'recipe-modal' : 'vk-modal' }}.oeffnen', { id: {{ $slot->vk_recipe_id }} })" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="{{ $slot->type === 'basisrezept' ? 'Rezept' : 'Gericht' }} einsehen">{{ $slot->type === 'basisrezept' ? '📖' : '🍽️' }}</button>
                                            {{-- Concept-Wording: Brand-Voice-Anzeigename je Position (leer = Standardname; ✨ oben füllt alle) --}}
                                            <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.wording" wire:change="wordingSpeichern({{ $slot->id }})" class="{{ $input }} !py-0.5 !text-[11px] italic mt-1 w-full" placeholder="Anzeigename im Konzept-Wording … (leer = „{{ $g->name }}“)" data-slot-wording />
                                        @else
                                            <span class="text-xs text-gray-400">leer — links/rechts aus den Listen einfügen</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top"><input type="text" wire:model.blur="slotForm.{{ $slot->id }}.rolle" wire:change="slotSpeichern({{ $slot->id }})" class="{{ $input }} !w-28" placeholder="Rolle" /></td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top">{{ $vkz !== null ? number_format((float) $vkz, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top">{{ $ekz !== null ? number_format((float) $ekz, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top text-gray-400">{{ $wpct !== null ? number_format($wpct, 1, ',', '.') . '%' : '—' }}</td>
                                @endif
                                <td class="{{ $td }} !px-2 text-right whitespace-nowrap align-top">
                                    @if(! $istStruktur)
                                        <label class="inline-flex items-center gap-0.5 text-[10px] text-gray-400 mr-1" title="Pflicht-Position">
                                            <input type="checkbox" wire:model="slotForm.{{ $slot->id }}.is_pflicht" wire:change="slotSpeichern({{ $slot->id }})" class="rounded border-gray-300 !w-3 !h-3" />P
                                        </label>
                                        <button type="button" wire:click="fillToggle({{ $slot->id }})" class="text-gray-400 hover:text-violet-500 text-[11px]" title="Befüllung ändern">⚙</button>
                                    @endif
                                    <button type="button" wire:click="zielSetzen({{ $slot->id }})" class="text-[11px] ml-1 align-middle {{ $einfuegenNachId === $slot->id ? 'text-violet-600 dark:text-violet-400' : 'text-gray-300 hover:text-violet-500' }}" title="{{ $einfuegenNachId === $slot->id ? 'Einfügeziel aktiv — nächste Position landet hier darunter (Klick = abwählen)' : 'Hier einfügen — die nächste neue Position landet unter dieser Zeile' }}">📍</button>
                                    <button type="button" wire:click="slotRaus({{ $slot->id }})" class="text-gray-400 hover:text-red-500 ml-1" title="entfernen">✕</button>
                                </td>
                            </tr>
                            {{-- Paket-Position = Abschnitt: seine Gerichte stehen immer read-only als eingerückte Zeilen darunter --}}
                            @if($slot->paket_id && $slot->paket)
                                <tr wire:key="epaket-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 align-top">
                                        <div class="ml-2 rounded-lg border border-gray-900/15 dark:border-white/15 bg-black/[0.02] dark:bg-white/[0.03] divide-y divide-black/5 dark:divide-white/10">
                                            @forelse($slot->paket->gerichte as $pg)
                                                <div wire:key="epaketg-{{ $slot->id }}-{{ $pg->id }}" class="flex items-center gap-2 px-3 py-1 text-[11px]">
                                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gericht') }}">G</span>
                                                    <span class="flex-1 min-w-0 break-words leading-snug text-gray-700 dark:text-gray-200">{{ $pg->gericht?->name ?? '—' }}</span>
                                                    <span class="shrink-0 text-gray-400 tabular-nums">{{ $pg->menge !== null ? rtrim(rtrim(number_format((float) $pg->menge, 2, ',', '.'), '0'), ',') . '×' : '' }}</span>
                                                    <span class="shrink-0 text-gray-400 tabular-nums w-16 text-right">{{ $pg->gericht?->vk_netto !== null ? number_format((float) $pg->gericht->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                                    @if($pg->vk_recipe_id)<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->vk_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="Gericht einsehen">🍽️</button>@endif
                                                </div>
                                            @empty
                                                <p class="px-3 py-1.5 text-[11px] text-gray-400">Paket ohne Gerichte — im Paket-Editor pflegen.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                            @if(! $istStruktur && ($fillOpenId === $slot->id || (! $slot->paket_id && ! $slot->vk_recipe_id)))
                                <tr wire:key="efill-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 bg-black/[0.02] dark:bg-white/[0.03]">
                                        <div class="flex flex-wrap items-center gap-2 pt-1">
                                            <select x-on:change="$wire.fuellePaket({{ $slot->id }}, $event.target.value); $event.target.value=''" class="{{ $input }} w-56">
                                                <option value="">↹ Paket (Rolle {{ $slot->rolle ?: '–' }}) …</option>
                                                @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                                    <option value="{{ $b->id }}">{{ $b->name }}{{ $b->preis_pro_person !== null ? ' (' . number_format((float) $b->preis_pro_person, 2, ',', '.') . ' €)' : '' }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="gerichtPicker({{ $slot->id }})" class="{{ $btnGhostXs }}">Gericht / Basisrezept …</button>
                                            <button type="button" wire:click="neuesPaketImSlot({{ $slot->id }})" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Inline ein neues Paket schnüren">+ neues Paket</button>
                                            @if($slot->paket_id || $slot->vk_recipe_id)
                                                <button type="button" wire:click="slotLeeren({{ $slot->id }})" class="text-[11px] text-gray-400 hover:text-red-500">leeren</button>
                                            @endif
                                        </div>
                                        @if($fillSlotId === $slot->id)
                                            <div class="space-y-1 pl-1 mt-2">
                                                <div class="flex gap-1.5">
                                                    <button type="button" wire:click="pickTypWaehle('gericht')" class="{{ $pill }} {{ $pickTyp === 'gericht' ? $variantPill['primary'] : $variantPill['secondary'] }}">Gericht (VK)</button>
                                                    <button type="button" wire:click="pickTypWaehle('basisrezept')" class="{{ $pill }} {{ $pickTyp === 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Basisrezept</button>
                                                </div>
                                                @if($pickTyp === 'gericht')
                                                    @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'gerichtSuche'])
                                                @else
                                                    <input type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Basisrezept suchen …" class="{{ $input }}" />
                                                @endif
                                                @if($kandidaten->isNotEmpty())
                                                    <div class="space-y-0.5 max-h-56 overflow-y-auto">
                                                        @foreach($kandidaten as $kand)
                                                            <button type="button" wire:key="ek-{{ $slot->id }}-{{ $kand->id }}" wire:click="fuelleGericht({{ $slot->id }}, {{ $kand->id }}, '{{ $pickTyp }}')" class="w-full flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                                                <span class="truncate">{{ $kand->name }}</span>
                                                                <span class="text-gray-400 tabular-nums shrink-0">@if($pickTyp === 'gericht'){{ $kand->vk_netto !== null ? number_format((float) $kand->vk_netto, 2, ',', '.') . ' €' : '' }}@else{{ $kand->ek_total_eur !== null ? 'EK ' . number_format((float) $kand->ek_total_eur, 2, ',', '.') . ' €' : '' }}@endif</span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @elseif($gerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '')
                                                    <p class="text-[11px] text-gray-400 px-2 py-1">Keine Treffer für diese Auswahl.</p>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="9" class="text-xs text-gray-400 py-4 text-center">Noch keine Positionen — links/rechts aus den Listen mit „+" einfügen (oder ziehen), Abschnitte über „+ Paket".</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>{{-- /mittlere Spalte --}}
                {{-- Phase 3: rechte Spalte — VK-Gerichte als Position einfügen (VK-Baum-Filter + Liste) --}}
                <aside class="w-72 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] dark:bg-white/[0.05] border border-black/5 dark:border-white/10 p-2.5 sticky top-0 self-start max-h-[70vh]" data-konzept-gerichtliste>
                    <p class="{{ $dt }} mb-1">VK-Gerichte ({{ $gerichtListe->count() }})</p>
                    @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'gerichtSuche'])
                    <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1 mt-1.5">
                        @forelse($gerichtListe as $gr)
                            <div wire:key="kgr-{{ $gr->id }}" draggable="true" @dragstart="dragTyp = 'gericht'; dragId = {{ $gr->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px] cursor-grab active:cursor-grabbing">
                                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gericht') }}">G</span>
                                <span class="min-w-0 flex-1 break-words leading-snug text-gray-700 dark:text-gray-200" title="{{ $gr->name }}">{{ $gr->name }}</span>
                                <span class="shrink-0 text-[10px] text-gray-400 tabular-nums">{{ $gr->vk_netto !== null ? number_format((float) $gr->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                <button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $gr->id }} })" class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Gericht einsehen">🍽️</button>
                                <button type="button" wire:click="positionEinfuegen('gericht', {{ $gr->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                            </div>
                        @empty
                            <p class="text-[10px] text-gray-400 px-1">— keine Treffer —</p>
                        @endforelse
                    </div>
                </aside>
                </div>{{-- /3-Spalten-Flex --}}
                @else
                    {{-- Paket: Gerichte schnüren — Mitte (Inhalt) + rechte VK-Gerichte-Filter-Spalte (wie Concept-Aufbau, Dominique 2026-06-17) --}}
                    <div class="flex gap-3 items-start">
                    <div class="flex-1 min-w-0 space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">Posten im Paket</h3>
                        <span class="text-[11px] text-gray-400">Gerichte (VK) + Basisrezepte (Menge = g/Person).</span>
                    </div>
                    <div class="space-y-1">
                        @forelse($paket->gerichte as $pg)
                            @php($istBasis = ! ($pg->gericht?->ist_verkaufsrezept ?? true))
                            <div wire:key="epg-{{ $pg->id }}" class="flex items-center gap-2 rounded-lg border border-black/5 dark:border-white/10 px-3 py-1.5">
                                <span class="flex flex-col -my-0.5 shrink-0">
                                    <button type="button" wire:click="gerichtHoch({{ $pg->id }})" class="text-gray-400 hover:text-violet-500 leading-none">▲</button>
                                    <button type="button" wire:click="gerichtRunter({{ $pg->id }})" class="text-gray-400 hover:text-violet-500 leading-none">▼</button>
                                </span>
                                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle($istBasis ? 'basisrezept' : 'gericht') }}">{{ $istBasis ? 'BR' : 'G' }}</span>
                                <span class="flex-1 min-w-0 truncate text-sm">{{ $pg->gericht?->name ?? '—' }}</span>
                                @if($pg->vk_recipe_id)<button type="button" @click="Livewire.dispatch('{{ $istBasis ? 'recipe-modal.oeffnen' : 'vk-modal.oeffnen' }}', { id: {{ $pg->vk_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="{{ $istBasis ? 'Basisrezept' : 'Gericht' }} einsehen">{{ $istBasis ? '📋' : '🍽️' }}</button>@endif
                                <span class="text-[10px] text-gray-400">{{ $istBasis ? 'g/Person' : 'Menge/Person' }}</span>
                                <input type="number" step="0.01" min="0" wire:model.blur="" value="{{ $pg->menge }}" wire:change="gerichtMengeSpeichern({{ $pg->id }}, $event.target.value)" class="{{ $input }} w-24 text-right tabular-nums" />
                                <span class="text-gray-400 text-xs tabular-nums w-16 text-right">@if($istBasis){{ $pg->gericht?->ek_total_eur !== null ? 'EK ' . number_format((float) $pg->gericht->ek_total_eur, 2, ',', '.') . ' €' : '' }}@else{{ $pg->gericht?->vk_netto !== null ? number_format((float) $pg->gericht->vk_netto, 2, ',', '.') . ' €' : '' }}@endif</span>
                                <button type="button" wire:click="gerichtRaus({{ $pg->vk_recipe_id }})" class="text-gray-400 hover:text-red-500 px-1" title="entfernen">✕</button>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 py-4 text-center">Noch keine Posten. Rechts Gericht oder Basisrezept suchen und hinzufügen.</p>
                        @endforelse
                    </div>
                    </div>{{-- /Mitte Paket-Inhalt --}}
                    {{-- rechte Spalte: roomy VK-Gerichte-Filter + Liste (wie Concept-Aufbau) --}}
                    <aside class="w-80 shrink-0 hidden lg:flex flex-col rounded-xl bg-gray-500/[0.07] dark:bg-white/[0.05] border border-black/5 dark:border-white/10 p-2.5 sticky top-0 self-start max-h-[70vh]" data-paket-gerichtliste>
                    <p class="{{ $dt }} mb-1">Posten hinzufügen</p>
                    <div class="flex items-center gap-1 mb-1.5">
                        <button type="button" wire:click="$set('paketQuelle', 'gericht')" class="{{ $pill }} {{ $paketQuelle !== 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Gericht</button>
                        <button type="button" wire:click="$set('paketQuelle', 'basisrezept')" class="{{ $pill }} {{ $paketQuelle === 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Basisrezept</button>
                    </div>
                    {{-- Park-Flow (Politur): suchen → [+] parken → Menge bzw. g/Person → Enter → ✓-Flash --}}
                    <div class="space-y-1 flex-1 min-h-0 overflow-y-auto" x-data="{
                            geparkt: null, menge: '', flash: false,
                            park(id, name) { this.geparkt = { id, name }; this.menge = ''; this.$nextTick(() => this.$refs.menge && this.$refs.menge.focus()); },
                            einfuegen() { if (!this.geparkt) return; this.$wire.gerichtHinzu(this.geparkt.id, this.menge); this.geparkt = null; this.menge = ''; this.flash = true; setTimeout(() => { this.flash = false; }, 1400); },
                         }">
                        <div x-show="geparkt === null">
                            @if($paketQuelle === 'basisrezept')
                                <input type="search" wire:model.live.debounce.300ms="paketGerichtSuche" placeholder="Basisrezept suchen …" class="{{ $input }} w-full" />
                            @else
                                @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'paketGerichtSuche'])
                            @endif
                            <p class="text-[10px] text-gray-400 mt-0.5">Treffer: <span class="text-violet-500 font-bold">+</span> parken → {{ $paketQuelle === 'basisrezept' ? 'g/Person' : 'Menge/Person' }} → Enter.</p>
                            @if($paketKandidaten->isNotEmpty())
                                <div class="space-y-0.5 max-h-56 overflow-y-auto mt-1">
                                    @foreach($paketKandidaten as $kand)
                                        <div wire:key="epk-{{ $paketQuelle }}-{{ $kand->id }}" class="flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">
                                            <span class="truncate">{{ $kand->name }}</span>
                                            <span class="flex items-center gap-2 shrink-0">
                                                <span class="text-gray-400 tabular-nums">@if($paketQuelle === 'basisrezept'){{ $kand->ek_total_eur !== null ? 'EK ' . number_format((float) $kand->ek_total_eur, 2, ',', '.') . ' €' : '' }}@else{{ $kand->vk_netto !== null ? number_format((float) $kand->vk_netto, 2, ',', '.') . ' €' : '' }}@endif</span>
                                                <button type="button" @click="park({{ $kand->id }}, @js($kand->name))" class="text-violet-500 font-bold px-1" title="parken">+</button>
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif($paketGerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '')
                                <p class="text-[11px] text-gray-400 px-2 py-1 mt-1">Keine Treffer für diese Auswahl.</p>
                            @endif
                        </div>
                        <div x-show="geparkt !== null" x-cloak class="flex items-center gap-2" data-park-zeile>
                            <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $paketQuelle === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</span>
                            <span class="flex-1 truncate text-sm" x-text="geparkt?.name"></span>
                            <input type="number" step="0.01" min="0" x-ref="menge" x-model="menge" @keydown.enter.prevent="einfuegen()" placeholder="{{ $paketQuelle === 'basisrezept' ? 'g/Person' : 'Menge/Person' }}" class="{{ $input }} w-32 text-right tabular-nums" />
                            <button type="button" @click="einfuegen()" class="{{ $btnGhostXs }} text-emerald-600">Einfügen ⏎</button>
                            <button type="button" @click="geparkt = null" class="{{ $btnGhostXs }}">✕</button>
                        </div>
                        <p x-show="flash" x-cloak class="text-[11px] text-emerald-600 dark:text-emerald-400">✓ hinzugefügt</p>
                    </div>
                    </aside>{{-- /rechte VK-Gerichte-Spalte --}}
                    </div>{{-- /Paket-Flex --}}
                @endif
            @endif

            {{-- ── Tab: DEKLARATION (Diät-Rollup + Nährwerte/Person — zusammengelegt 2026-07-02, Parität zu Rezept-/VK-Modal) ── --}}
            @if($tab === 'allergene')
                @if($aggregat && $aggregat['allergene']['n_gerichte'] > 0)
                    <span class="{{ $label }}">Aggregiert aus {{ $aggregat['allergene']['n_gerichte'] }} Gerichten (kein manuelles Gruppieren)</span>
                    <div class="flex flex-wrap gap-1.5">
                        @if($aggregat['allergene']['is_vegan'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>
                        @elseif($aggregat['allergene']['is_vegetarian'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegetarisch</span>@endif
                        @if($aggregat['allergene']['is_gluten_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">glutenfrei</span>@endif
                        @if($aggregat['allergene']['is_lactose_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">laktosefrei</span>@endif
                        @if($aggregat['allergene']['is_halal'])<span class="{{ $pill }} {{ $variantPill['info'] }}">halal</span>@endif
                        @if($aggregat['allergene']['contains_pork'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Schwein</span>@endif
                        @if($aggregat['allergene']['contains_beef'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Rind</span>@endif
                        <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['konfidenz']] ?? $variantPill['secondary'] }}">Konf. {{ $aggregat['allergene']['konfidenz'] }}</span>
                    </div>
                @else
                    <p class="text-sm text-gray-400 py-6 text-center">Noch keine Gerichte für den Allergen-Rollup.</p>
                @endif

                <div class="border-t border-black/5 dark:border-white/10 mt-4 pt-3 space-y-2">
                @if($aggregat && $aggregat['naehrwerte']['kcal'] !== null)
                    <div class="flex items-center justify-between">
                        <span class="{{ $label }}">Nährwerte / Person (aus den Gerichten · Portionsgramm)</span>
                        <span class="{{ $pill }} {{ $konfPill[$aggregat['naehrwerte']['konfidenz']] ?? $variantPill['secondary'] }}">Konf. {{ $aggregat['naehrwerte']['konfidenz'] }}</span>
                    </div>
                    <div class="grid grid-cols-7 gap-2">
                        @foreach(['kcal' => 'kcal', 'protein_g' => 'Eiweiß (g)', 'fett_g' => 'Fett (g)', 'gesfett_g' => 'dav. ges. (g)', 'kh_g' => 'KH (g)', 'zucker_g' => 'dav. Zucker (g)', 'salz_g' => 'Salz (g)'] as $k => $l)
                            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 text-center">
                                <p class="text-base font-semibold tabular-nums">{{ $aggregat['naehrwerte'][$k] !== null ? number_format((float) $aggregat['naehrwerte'][$k], $k === 'kcal' ? 0 : 1, ',', '.') : '—' }}</p>
                                <p class="text-[10px] text-gray-400 uppercase">{{ $l }}</p>
                            </div>
                        @endforeach
                    </div>
                    @unless($aggregat['naehrwerte']['vollstaendig'])
                        <p class="text-[11px] text-amber-600 dark:text-amber-400">⚠ Nur {{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }}/{{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichte haben Nährwert + Portionsgramm — Werte sind eine Untergrenze.</p>
                    @endunless
                @else
                    <p class="text-sm text-gray-400 py-6 text-center">Keine Nährwerte — den Gerichten fehlen Werte oder Portionsgramm.</p>
                @endif
                </div>
            @endif

            {{-- ── Tab: KALKULATION ──────────────────────────────────────── --}}
            @if($tab === 'kalkulation')
                {{-- Concept-VK: automatisch (Σ Positionen) ODER manuell (z. B. Lunchbuffet, Preis auf EK-Basis) --}}
                @if($concept)
                    <div class="rounded-xl border border-black/5 dark:border-white/10 p-3 space-y-2" data-concept-vk>
                        <div class="flex items-center justify-between">
                            <span class="{{ $label }}">VK-Preis / Person</span>
                            <div class="flex gap-1">
                                <button type="button" wire:click="setPreisModus('auto')" class="{{ $pill }} {{ ($form['preis_modus'] ?? 'auto') === 'auto' ? $variantPill['primary'] : $variantPill['secondary'] }}">automatisch (Summe)</button>
                                <button type="button" wire:click="setPreisModus('manuell')" class="{{ $pill }} {{ ($form['preis_modus'] ?? 'auto') === 'manuell' ? $variantPill['primary'] : $variantPill['secondary'] }}">manuell</button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
                            <span class="text-gray-500 dark:text-gray-400">Berechnete Summe: <span class="tabular-nums font-medium text-gray-900 dark:text-gray-100">{{ number_format((float) ($cockpit['summe_pro_person'] ?? 0), 2, ',', '.') }} €</span></span>
                            <span class="text-gray-500 dark:text-gray-400">Wareneinsatz: <span class="tabular-nums">{{ number_format((float) ($cockpit['ek_pro_person'] ?? 0), 2, ',', '.') }} €</span></span>
                        </div>
                        @if(($form['preis_modus'] ?? 'auto') === 'manuell')
                            <div class="flex items-center gap-2">
                                <label class="{{ $label }}">Fixer VK / Person</label>
                                <input type="number" step="0.01" min="0" wire:model.blur="form.preis_pro_person_manuell" wire:change="speichern" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 24,90" />
                                <span class="text-[11px] text-gray-400">überschreibt die Summe — EK bleibt als Basis sichtbar</span>
                            </div>
                        @endif
                    </div>
                @endif
                {{-- M-K1/Doc 16: Herstellkosten-Wasserfall (WE → +Blöcke → HK2 → VK-Vorschlag) --}}
                @if($kalkulation)
                    <div class="rounded-xl border border-black/5 dark:border-white/10 p-3 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="{{ $label }}">Herstellkosten (HK2) — Aufschlüsselung / {{ $concept ? 'Person' : 'Person' }}</span>
                            <span class="text-[11px] text-gray-400">Marge {{ rtrim(rtrim(number_format((float) $kalkulation['marge_pct'], 2, ',', '.'), '0'), ',') }} %</span>
                        </div>
                        @foreach($kalkulation['bloecke'] as $blk)
                            <div class="flex items-center justify-between text-xs py-0.5 {{ $blk['key'] === 'we' ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300' }}">
                                <span>{{ $blk['key'] === 'we' ? '' : '+ ' }}{{ $blk['label'] }}</span>
                                <span class="tabular-nums">{{ number_format((float) $blk['betrag'], 2, ',', '.') }} €</span>
                            </div>
                        @endforeach
                        <div class="flex items-center justify-between text-xs py-1 border-t border-black/5 dark:border-white/10 font-semibold text-gray-900 dark:text-gray-100">
                            <span>= HK2</span><span class="tabular-nums">{{ number_format((float) $kalkulation['hk2_pro_person'] ?? $kalkulation['hk2_pro_portion'] ?? 0, 2, ',', '.') }} €</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">VK-Vorschlag (HK2 × Marge)</span>
                            <span class="tabular-nums text-violet-700 dark:text-violet-300 font-medium">{{ number_format((float) $kalkulation['vk_vorschlag'], 2, ',', '.') }} €</span>
                        </div>
                        @if($kalkulation['db_eur'] !== null)
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-500">Deckungsbeitrag (gesetzter VK − HK2)</span>
                                <span class="tabular-nums {{ $kalkulation['db_eur'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format((float) $kalkulation['db_eur'], 2, ',', '.') }} €{{ $kalkulation['db_pct'] !== null ? ' · ' . number_format((float) $kalkulation['db_pct'], 1, ',', '.') . ' %' : '' }}</span>
                            </div>
                        @endif
                        <p class="text-[10px] text-gray-400 pt-0.5">Blöcke pflegst du in Einstellungen → Kalkulation.</p>
                    </div>
                @endif

                {{-- Wareneinsatz je Position — woraus sich die Kosten zusammensetzen (wie die Zutatenliste beim Gericht) --}}
                @if($concept && $cockpit)
                    <div class="rounded-xl border border-black/5 dark:border-white/10 p-3">
                        <p class="{{ $label }} mb-1.5">Wareneinsatz je Position / Person</p>
                        <table class="w-full text-xs">
                            <thead><tr class="text-gray-400 text-[10px] uppercase tracking-wider">
                                <th class="text-left font-medium py-1">Position</th>
                                <th class="text-right font-medium">Wareneinsatz</th>
                                <th class="text-right font-medium">VK</th>
                                <th class="text-right font-medium">W-%</th>
                            </tr></thead>
                            <tbody>
                            @foreach($cockpit['zeilen'] as $z)
                                @php($zw = (($z['preis'] ?? 0) > 0 && $z['ek'] !== null) ? $z['ek'] / $z['preis'] * 100 : null)
                                <tr class="border-t border-black/5 dark:border-white/10">
                                    <td class="py-1">@if($z['rolle'])<span class="text-gray-400">{{ $z['rolle'] }}:</span> @endif{{ $z['label'] }}</td>
                                    <td class="text-right tabular-nums">{{ $z['ek'] !== null ? number_format((float) $z['ek'], 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ $z['preis'] !== null ? number_format((float) $z['preis'], 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums">{{ $zw !== null ? number_format($zw, 1, ',', '.') . ' %' : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-black/10 dark:border-white/15 font-semibold text-gray-900 dark:text-gray-100">
                                    <td class="py-1">Summe / Person</td>
                                    <td class="text-right tabular-nums">{{ number_format((float) $cockpit['ek_pro_person'], 2, ',', '.') }} €</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ number_format((float) $cockpit['preis_pro_person'], 2, ',', '.') }} €</td>
                                    <td class="text-right tabular-nums">{{ $cockpit['preis_pro_person'] > 0 ? number_format($cockpit['ek_pro_person'] / $cockpit['preis_pro_person'] * 100, 1, ',', '.') . ' %' : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @elseif($paket)
                    <div class="rounded-xl border border-black/5 dark:border-white/10 p-3">
                        <p class="{{ $label }} mb-1.5">Wareneinsatz je Posten / Person</p>
                        <table class="w-full text-xs">
                            <thead><tr class="text-gray-400 text-[10px] uppercase tracking-wider">
                                <th class="text-left font-medium py-1">Posten</th>
                                <th class="text-right font-medium">Menge</th>
                                <th class="text-right font-medium">Wareneinsatz</th>
                                <th class="text-right font-medium">VK</th>
                            </tr></thead>
                            <tbody>
                            @forelse($paket->gerichte as $pg)
                                @php($istBasis = ! ($pg->gericht?->ist_verkaufsrezept ?? true))
                                @php($faktor = $pg->menge !== null ? (float) $pg->menge : 1.0)
                                @php($yieldG = (float) ($pg->gericht?->yield_kg ?? 0) * 1000)
                                @php($postenEk = $istBasis
                                    ? (($pg->gericht?->ek_total_eur !== null && $yieldG > 0 && $pg->menge !== null) ? (float) $pg->gericht->ek_total_eur * ((float) $pg->menge / $yieldG) : null)
                                    : ($pg->gericht?->ek_total_eur !== null ? (float) $pg->gericht->ek_total_eur * $faktor : null))
                                <tr class="border-t border-black/5 dark:border-white/10">
                                    <td class="py-1">{{ $pg->gericht?->name ?? '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ $pg->menge !== null ? (rtrim(rtrim(number_format($faktor, 2, ',', '.'), '0'), ',') . ($istBasis ? ' g' : '')) : ($istBasis ? '— g' : '1') }}</td>
                                    <td class="text-right tabular-nums">{{ $postenEk !== null ? number_format($postenEk, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ $istBasis ? '—' : ($pg->gericht?->vk_netto !== null ? number_format((float) $pg->gericht->vk_netto * $faktor, 2, ',', '.') . ' €' : '—') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-2 text-center text-gray-400">Noch keine Posten.</td></tr>
                            @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-black/10 dark:border-white/15 font-semibold text-gray-900 dark:text-gray-100">
                                    <td class="py-1">Summe / Person</td>
                                    <td></td>
                                    <td class="text-right tabular-nums">{{ $paket->ek_pro_person !== null ? number_format((float) $paket->ek_pro_person, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ $aggregat !== null ? number_format((float) $aggregat['vk_summe'], 2, ',', '.') . ' €' : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif

                @if($concept && $cockpit)
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                        <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                            <span class="text-[10px] uppercase tracking-wider text-violet-600 dark:text-violet-400">€/Person</span>
                            <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ number_format($cockpit['preis_pro_person'], 2, ',', '.') }} €</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                            <span class="{{ $dt }}">Wareneinsatz/Pers.</span>
                            <p class="text-xs font-semibold tabular-nums">{{ $kalkulation !== null ? number_format((float) $kalkulation['hk1_pro_person'], 2, ',', '.') . ' €' : '—' }}</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                            <span class="{{ $dt }}">Wareneinsatz %</span>
                            <p class="text-xs font-semibold tabular-nums">{{ ($kalkulation !== null && $cockpit['preis_pro_person'] > 0) ? number_format($kalkulation['hk1_pro_person'] / $cockpit['preis_pro_person'] * 100, 1, ',', '.') . ' %' : '—' }}</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                            <span class="{{ $dt }}">Arbeitszeit</span>
                            <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? $aggregat['arbeitszeit_min'] . ' min' : '—' }}</p>
                        </div>
                        <div>
                            <label class="{{ $label }}">Zielpreis €/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.zielpreis_pro_person" class="{{ $input }} text-right tabular-nums" />
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="button" wire:click="zielpreisToggle" class="{{ $btnGhost }} {{ $zielModus ? 'text-violet-600 dark:text-violet-400' : '' }}">🎯 Zielpreis-Konfigurator</button>
                    </div>
                    @if($zielModus)
                        <div class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-3 space-y-2">
                            <div class="flex items-end gap-2 flex-wrap">
                                <div>
                                    <label class="{{ $label }}">Komm auf €/Person</label>
                                    <input type="number" step="0.01" min="0" wire:model="zielPreis" wire:keydown.enter="zielpreisBerechnen" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 36,00" />
                                </div>
                                <button type="button" wire:click="zielpreisBerechnen" class="{{ $btnPrimary }}">Vorschlag</button>
                                <span class="text-[11px] text-gray-400">Tauscht Pakete derselben Rolle; feste Gerichte = Fixkosten.</span>
                            </div>
                            @if($zielVorschlag)
                                <div class="text-xs space-y-1 pt-1 border-t border-violet-500/20">
                                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                                        <span><span class="{{ $label }}">Aktuell</span> {{ number_format($zielVorschlag['aktuell'], 2, ',', '.') }} €</span>
                                        <span><span class="{{ $label }}">Vorschlag</span> <span class="font-semibold">{{ number_format($zielVorschlag['preis'], 2, ',', '.') }} €</span></span>
                                        <span><span class="{{ $label }}">Tausche</span> {{ $zielVorschlag['aenderungen'] }}</span>
                                    </div>
                                    <div class="flex gap-2 pt-1">
                                        <button type="button" wire:click="zielpreisUebernehmen" @disabled($zielVorschlag['aenderungen'] === 0) class="{{ $btnPrimary }}">Übernehmen</button>
                                        <button type="button" wire:click="$set('zielVorschlag', null)" class="{{ $btnGhost }}">Verwerfen</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                @elseif($paket)
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <label class="{{ $label }}">Preis-Modus</label>
                            <select wire:model.live="form.preis_modus" class="{{ $input }}">
                                <option value="manuell">manuell (Buffet)</option>
                                <option value="auto">auto (Σ Gerichte)</option>
                            </select>
                        </div>
                        <div>
                            <label class="{{ $label }}">€/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.preis_pro_person" @disabled($form['preis_modus'] === 'auto') class="{{ $input }} text-right tabular-nums" />
                        </div>
                        <div>
                            <label class="{{ $label }}">EK/Person <span class="text-gray-400 normal-case">· aus Gerichten</span></label>
                            <input type="number" step="0.0001" min="0" wire:model="form.ek_pro_person" disabled class="{{ $input }} text-right tabular-nums opacity-70" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Wareneinsatz % <span class="text-gray-400 normal-case">· abgeleitet</span></label>
                            <input type="number" step="0.1" min="0" wire:model="form.wareneinsatz_prozent" disabled class="{{ $input }} text-right tabular-nums opacity-70" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="neuBerechnen" class="{{ $btnGhost }}">↻ EK aus Gerichten neu berechnen</button>
                        <span class="text-[10px] text-gray-400">Kosten folgen den Gerichten; nur der €/Person ist im manuell-Modus (Buffet) frei.</span>
                    </div>
                @endif
            @endif

            {{-- ── Tab: NOTIZEN ──────────────────────────────────────────── --}}
            @if($tab === 'notizen')
                @if($concept)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Schreibstil (Wording fürs ganze Konzept)</label>
                            <div class="flex items-center gap-2">
                                <select wire:model="form.schreibstil_id" class="{{ $input }}">
                                    <option value="">— neutral —</option>
                                    @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                                </select>
                                <button type="button" wire:click="wordingGenerieren" class="{{ $btnGhostXs }} shrink-0 text-violet-600 dark:text-violet-400" title="Wording übers ganze Konzept erzeugen: pro Position einen Brand-Voice-Namen + Konzept-Einleitung (echter Text mit LLM-Key)" data-ki-concept-wording>✨ Wording (ganzes Konzept)</button>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-0.5">Erzeugt stimmig je Position einen Anzeigenamen + die Konzept-Einleitung. Foodbook kann den Stil je Kunde überschreiben. Text-Veredelung mit LLM-Key.</p>
                        </div>
                        <div>
                            <label class="{{ $label }}">Diät-Vorgabe (KI-Brief)</label>
                            <input type="text" wire:model="form.diaet_vorgabe" class="{{ $input }}" placeholder="z. B. „je Gang ≥1 vegan"" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Struktur-Vorgabe</label>
                            <input type="text" wire:model="form.struktur_vorgabe" class="{{ $input }}" placeholder="z. B. „3-Gang" / „Buffet: Salat+HG+Dessert"" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="{{ $label }}">Saison</label>
                                <input type="text" wire:model="form.saison" class="{{ $input }}" />
                            </div>
                            <div>
                                <label class="{{ $label }}">Zielgruppe/Sektor (frei)</label>
                                <input type="text" wire:model="form.zielgruppe" class="{{ $input }}" />
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Sektor-Eignung (mehrwertig, wie VK-Rezept)</label>
                            <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                                @foreach($sektorSlugs as $slug)
                                    <span wire:key="sek-{{ $slug }}" class="{{ $pill }} {{ $variantPill['info'] }} inline-flex items-center gap-1">
                                        {{ $slug }}
                                        <button type="button" wire:click="sektorRaus(@js($slug))" class="hover:text-red-500" title="entfernen">✕</button>
                                    </span>
                                @endforeach
                                <input type="text" wire:model="neuerSektor" wire:keydown.enter.prevent="sektorHinzu" placeholder="Sektor + Enter (z. B. Kita, Klinik, Catering) …" class="{{ $input }} w-56" />
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Brief / Kontext (KI-Eingabe)</label>
                            <textarea wire:model="form.brief" rows="2" class="{{ $input }}" placeholder="Freitext-Brief für die KI-Komposition (LLM-Key folgt)"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Konsumenten-Zusatztext</label>
                            <textarea wire:model="form.zusatztext" rows="2" class="{{ $input }}"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Interne Notiz</label>
                            <textarea wire:model="form.note" rows="2" class="{{ $input }}"></textarea>
                        </div>
                    </div>
                @else
                    <div class="space-y-3">
                        <div>
                            <label class="{{ $label }}">Beschreibung</label>
                            <textarea wire:model="form.beschreibung" rows="2" class="{{ $input }}"></textarea>
                        </div>
                        <div>
                            <label class="{{ $label }}">Interne Notiz</label>
                            <textarea wire:model="form.note" rows="2" class="{{ $input }}"></textarea>
                        </div>
                    </div>
                @endif
            @endif

            {{-- ── Tab: KONZEPT (#389/Canvas — kreatives Foodkonzept) ── --}}
            @if($tab === 'konzept')
                @if($concept)
                    <p class="text-[11px] text-gray-400 mb-2">Das kreative Foodkonzept (Leitidee, Inszenierung, Geschmackswelten) — fließt als Kontext in alle KI-Texte dieses Konzepts. Stil/Geschmack erbt es aus der Team-Food-DNA.</p>
                    @include('foodalchemist::livewire.canvas.partials.board')
                @endif
            @endif

            {{-- ── Tab: SENSORIK (Geschmacks-Balance + Textur über die Concept-Gerichte) ── --}}
            @if($tab === 'sensorik')
                @if($concept && $sensorik)
                    @if($sensorik['leer'])
                        <p class="text-xs text-gray-400 py-6 text-center">Noch keine Gerichte mit Sensorik-Daten — erst im Aufbau Gerichte/Basisrezepte einfügen.</p>
                    @else
                        @php($dimLabel = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'])
                        <p class="text-[11px] text-gray-400 mb-2">Aggregiert über {{ $sensorik['abdeckung']['gesamt'] }} Grundprodukte ({{ $sensorik['abdeckung']['mit'] }} mit Sensorik-Daten) — MAX je Dimension = „im Teller vorhanden?". Quelle: Sensorik-Graph.</p>

                        <div class="relative overflow-hidden {{ $card }} mb-3">
                            <div class="{{ $cardAccent }}"></div>
                            <div class="px-5 py-4 space-y-2">
                                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Geschmacks-Balance</h3>
                                @foreach($dimLabel as $d => $l)
                                    @php($v = (float) ($sensorik['geschmack'][$d] ?? 0))
                                    @php($istDom = in_array($d, $sensorik['dominant'], true))
                                    @php($istLueck = in_array($d, $sensorik['luecken'], true))
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] w-14 shrink-0 {{ $istLueck ? 'text-gray-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $l }}</span>
                                        <div class="flex-1 h-2 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                                            <div class="h-full rounded-full {{ $istDom ? 'bg-violet-500' : ($istLueck ? 'bg-gray-300 dark:bg-gray-600' : 'bg-violet-400/60') }}" style="width: {{ (int) round($v * 100) }}%"></div>
                                        </div>
                                        <span class="text-[11px] w-8 text-right tabular-nums text-gray-500">{{ number_format($v, 2, ',', '.') }}</span>
                                    </div>
                                @endforeach
                                @if(count($sensorik['dominant']) || count($sensorik['luecken']))
                                    <div class="flex flex-wrap gap-1 pt-1">
                                        @foreach($sensorik['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['success'] }}">dominant: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                                        @foreach($sensorik['luecken'] as $d)<span class="{{ $pill }} {{ $variantPill['warning'] }}">Lücke: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="relative overflow-hidden {{ $card }} mb-3">
                            <div class="{{ $cardAccent }}"></div>
                            <div class="px-5 py-4 space-y-2">
                                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Textur-Profil</h3>
                                @if(count($sensorik['textur']))
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
                                    </div>
                                @else
                                    <p class="text-[11px] text-gray-400">Keine Textur-Daten.</p>
                                @endif
                                @if($sensorik['monotonie'])
                                    <p class="text-[11px] text-amber-600 dark:text-amber-400">⚠ {{ $sensorik['monotonie'] }}</p>
                                @endif
                            </div>
                        </div>

                        {{-- Kontrast/Komplettierung kommt aus dem Anker-Graph (Pairing), nicht aus dem Grundgeschmack. --}}
                    @endif
                @endif
            @endif

            {{-- ── Tab: GESCHIRR (#388 — direktes Geschirr + Alternative je Gericht) ── --}}
            @if($tab === 'geschirr')
                @if($concept)
                    @php($gerichtSlots = $concept->slots->filter(fn ($s) => $s->vk_recipe_id !== null || in_array($s->type, ['gericht', 'basisrezept'], true)))
                    <p class="text-[11px] text-gray-400 mb-2">Pro Gericht ein Haupt-Geschirr + optional eine Alternative (z. B. anderer Leih-Caterer). Pflege den Geschirr-Katalog unter <span class="font-medium">Stammdaten → Geschirr</span>.</p>
                    @forelse($gerichtSlots as $slot)
                        <div wire:key="geschirr-slot-{{ $slot->id }}" class="rounded-lg border border-black/5 dark:border-white/10 px-3 py-2 mb-2">
                            <p class="text-xs font-medium text-gray-900 dark:text-gray-100 mb-1.5 truncate">{{ $slot->wording ?: ($slot->gericht?->name ?: ($slot->titel ?: 'Position')) }}</p>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach(['haupt' => 'Haupt-Geschirr', 'alt' => 'Alternative'] as $rolle => $rolleLabel)
                                    @php($item = $rolle === 'haupt' ? $slot->geschirrItem : $slot->geschirrAltItem)
                                    <div>
                                        <label class="{{ $label }} block mb-0.5">{{ $rolleLabel }}</label>
                                        @if($item)
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs text-gray-800 dark:text-gray-200 truncate" title="{{ $item->bezeichnung }}">{{ $item->bezeichnung }}</span>
                                                @if($item->leihpreis !== null)<span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">{{ number_format((float) $item->leihpreis, 2, ',', '.') }} €</span>@endif
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <button type="button" wire:click="geschirrPicker({{ $slot->id }}, '{{ $rolle }}')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">ändern</button>
                                                <button type="button" wire:click="geschirrEntfernen({{ $slot->id }}, '{{ $rolle }}')" class="{{ $btnGhostXs }} text-rose-500">entfernen</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="geschirrPicker({{ $slot->id }}, '{{ $rolle }}')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">+ Geschirr wählen</button>
                                        @endif

                                        @if($geschirrPickSlotId === $slot->id && $geschirrPickRolle === $rolle)
                                            <div class="mt-1.5 rounded-lg border border-violet-500/30 bg-violet-500/[0.04] p-2" wire:key="geschirr-pick-{{ $slot->id }}-{{ $rolle }}">
                                                <input type="search" wire:model.live.debounce.300ms="geschirrSuche" placeholder="Geschirr suchen …" class="{{ $input }} !py-1 mb-1" autofocus />
                                                <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                                    @forelse($geschirrKandidaten as $kandidat)
                                                        <button type="button" wire:key="gk-{{ $slot->id }}-{{ $rolle }}-{{ $kandidat->id }}"
                                                                wire:click="geschirrWaehle({{ $slot->id }}, '{{ $rolle }}', {{ $kandidat->id }})"
                                                                class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors">
                                                            {{ $kandidat->bezeichnung }}
                                                            <span class="text-gray-400">· {{ $kandidat->supplier?->name }}{{ $kandidat->leihpreis !== null ? ' · ' . number_format((float) $kandidat->leihpreis, 2, ',', '.') . ' €' : '' }}</span>
                                                        </button>
                                                    @empty
                                                        <p class="text-[11px] text-gray-400 px-2 py-1">{{ trim($geschirrSuche) === '' ? 'Tippen zum Suchen …' : 'Kein Geschirr gefunden.' }}</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 py-6 text-center">Noch keine Gerichte im Konzept — erst im Aufbau-Tab Gerichte/Basisrezepte einfügen.</p>
                    @endforelse
                @endif
            @endif
        @endif
    </x-foodalchemist::modal>
</div>
