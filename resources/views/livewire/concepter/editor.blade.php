{{-- M10R-3 / Doc 15 §10.4: Voll-Editor-Modal (VK-Stil) — Kopf + Tabs (Aufbau/Nährwerte/Allergene/Kalkulation/Notizen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($item = $concept ?? $paket)
@php($titel = $item?->name ?? 'Editor')
@php($tabAktiv = 'border-violet-500 text-violet-700')
@php($tabIdle = 'border-transparent text-gray-600 hover:text-gray-700')
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
            @if($concept && ! $concept->is_template)
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
                @php($stripVk = $concept ? ($cockpit['price_per_person'] ?? 0) : ($paket?->price_per_person !== null ? (float) $paket->price_per_person : null))
                @php($stripEk = (float) ($kalkulation['hk1_pro_person'] ?? 0))
                @php($stripWpct = ($stripVk !== null && $stripVk > 0) ? $stripEk / $stripVk * 100 : null)
                <div class="grid grid-cols-3 md:grid-cols-7 gap-2">
                    <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                        <span class="text-[10px] uppercase tracking-wider text-violet-600">VK €/Person</span>
                        <p class="text-base font-bold text-violet-700 tabular-nums">{{ $stripVk !== null ? number_format((float) $stripVk, 2, ',', '.') . ' €' : '—' }}</p>
                    </div>
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <span class="{{ $dt }}">Wareneinsatz/Pers.</span>
                        <p class="text-sm font-semibold tabular-nums">{{ number_format($stripEk, 2, ',', '.') }} €</p>
                    </div>
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <span class="{{ $dt }}">Wareneinsatz %</span>
                        <p class="text-sm font-semibold tabular-nums">{{ $stripWpct !== null ? number_format($stripWpct, 1, ',', '.') . ' %' : '—' }}</p>
                    </div>
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <span class="{{ $dt }}">HK2 (Vollkosten)</span>
                        <p class="text-sm font-semibold tabular-nums">{{ number_format((float) ($kalkulation['hk2_pro_person'] ?? 0), 2, ',', '.') }} €</p>
                    </div>
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <span class="{{ $dt }}">VK-Vorschlag</span>
                        <p class="text-sm font-semibold tabular-nums text-violet-700">{{ number_format((float) ($kalkulation['vk_vorschlag'] ?? 0), 2, ',', '.') }} €</p>
                    </div>
                    <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div>
                        <span class="{{ $dt }}">Deckungsbeitrag</span>
                        <p class="text-sm font-semibold tabular-nums {{ ($kalkulation['db_eur'] ?? 0) < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $kalkulation['db_eur'] !== null ? number_format((float) $kalkulation['db_eur'], 2, ',', '.') . ' €' : '—' }}</p>
                    </div>
                    @isset($aggregat['gewicht_pro_person_g'])
                        <div class="{{ $kpiTile }}" data-kpi-gewicht><div class="{{ $kpiTileAccent }}"></div>
                            <span class="{{ $dt }}">Gewicht/P</span>
                            <p class="text-sm font-semibold tabular-nums">{{ number_format((float) $aggregat['gewicht_pro_person_g'], 0, ',', '.') }} g{!! ($aggregat['gewicht_vollstaendig'] ?? true) ? '' : ' <span class="text-amber-500 font-normal" title="≥1 Position ohne Portionsgewicht — Gewicht unvollständig">~</span>' !!}</p>
                        </div>
                    @endisset
                </div>
            </x-slot:kpiHeader>
        @endif

        @if($item === null)
            <p class="text-sm text-gray-500 py-10 text-center">Nichts geladen.</p>
        @else
            {{-- ── Kopf ──────────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <div class="md:col-span-2">
                    <label class="{{ $label }}">Bezeichnung (intern)</label>
                    <input type="text" wire:model="form.name" class="{{ $input }}" />
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">Konsumentenbezeichnung</label>
                    <input type="text" wire:model="form.consumer_name" class="{{ $input }}" placeholder="z. B. „Sommerliche Vorspeisen-Auswahl"" />
                </div>
                <div>
                    <label class="{{ $label }}">Klasse</label>
                    <input type="text" wire:model="form.class" list="concepter-klassen" class="{{ $input }}" placeholder="frei/wählbar" />
                    <datalist id="concepter-klassen">@foreach($klassen as $k)<option value="{{ $k }}"></option>@endforeach</datalist>
                </div>
                <div>
                    <label class="{{ $label }}">Niveau</label>
                    <select wire:model="form.level" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['klassisch' => 'klassisch', 'gehoben' => 'gehoben', 'haute' => 'haute'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </div>

                @if($concept)
                    <div>
                        <label class="{{ $label }}">Anlass</label>
                        <input type="text" wire:model="form.occasion" class="{{ $input }}" placeholder="z. B. Sommerfest" />
                    </div>
                    {{-- 4c: Kategorie-Feld abgelöst — Facetten (Servierform/Eventtyp/Momente/Saison) übernehmen --}}
                    <div>
                        <label class="{{ $label }}">Servierform</label>
                        <select wire:model="form.serving_form_id" wire:change="speichern" class="{{ $input }}" title="Steuert die Darreichungs-Auflösung der Gerichte (Slot → passende Variante) — speichert sofort">
                            <option value="">—</option>
                            @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Eventtyp</label>
                        <select wire:model="form.event_type_id" wire:change="speichern" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">
                            @foreach(['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Geschmack</label>
                        <select wire:model="form.taste_direction" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label class="{{ $label }}">Rolle</label>
                        <input type="text" wire:model="form.role" list="concepter-rollen" class="{{ $input }}" placeholder="z. B. Vorspeise" />
                        <datalist id="concepter-rollen">@foreach($rollen as $r)<option value="{{ $r }}"></option>@endforeach</datalist>
                    </div>
                @endif
            </div>

            @if($concept)
                {{-- R4.3: Phasen-Statusmaschine (ergänzt den Sichtbarkeits-Status) --}}
                <div class="mt-2">
                    @include('foodalchemist::livewire.planning.partials.phase-stepper', ['phaseAktuell' => $concept->phase ?? 'kontext'])
                </div>

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
                    {{-- Schreibstil (Tonalität) — 2026-07-06 aus Tab „Notizen" hierher (immer sichtbar):
                         Stil fürs ganze Konzept + ✨ erzeugt je Position Brand-Voice-Wording (WordingResolver-Kette). --}}
                    <div class="flex items-center gap-1.5" data-konzept-schreibstil>
                        <span class="{{ $label }} !mb-0 mr-1">Schreibstil</span>
                        <select wire:model="form.writing_style_id" class="{{ $input }} !w-auto !py-0.5 !text-[11px]" title="Tonalität fürs Wording des ganzen Konzepts — Foodbook kann je Kunde überschreiben">
                            <option value="">— neutral —</option>
                            @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="wordingGenerieren" class="{{ $btnGhostXs }} shrink-0 text-violet-600" title="Wording übers ganze Konzept erzeugen: pro Position einen Brand-Voice-Namen + Konzept-Einleitung (echter Text mit LLM-Key)" data-ki-concept-wording>✨ Wording</button>
                    </div>
                </div>
            @endif

            {{-- ── Tab-Nav ───────────────────────────────────────────────── --}}
            <div class="flex gap-4 border-b border-black/5 mt-1">
                @php($editorTabs = ['aufbau' => 'Aufbau'])
                @if($concept)@php($editorTabs['konzept'] = 'Konzept')@endif
                {{-- 'allergene'-Key bleibt stabil, Label seit 2026-07-02 „Deklaration" (Diät-Rollup + Nährwerte/Person — Parität zu Rezept-/VK-Modal) --}}
                @php($editorTabs['allergene'] = 'Deklaration')
                @php($editorTabs['kalkulation'] = 'Kalkulation')
                @if($concept)@php($editorTabs['geschirr'] = 'Geschirr')@endif
                @if($concept)@php($editorTabs['sensorik'] = 'Sensorik')@endif
                @php($editorTabs['notes'] = 'Notizen')
                @foreach($editorTabs as $k => $l)
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="px-1 py-2 text-xs font-medium border-b-2 -mb-px transition-colors {{ $tab === $k ? $tabAktiv : $tabIdle }}">{{ $l }}</button>
                @endforeach
            </div>

            {{-- ── Tab: AUFBAU ───────────────────────────────────────────── --}}
            @if($tab === 'aufbau')
                {{-- Live-Kosten-Streifen ist jetzt fix im Modal-Kopf (Phase 1, x-slot:kpiHeader). --}}
                @if($concept)
                {{-- R4.2: Soll/Ist-Coverage gegen das Planungs-Gerüst — live beim Befüllen, Lücken-Klick filtert den Picker --}}
                @if($coverage !== null && $coverage['hat_geruest'])
                    <div class="mb-3">
                        @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverageFillAction' => 'coverageFuellen'])
                    </div>
                @endif
                {{-- R6.1: Kohäsions-Beweis über die Menüfolge (on-demand) --}}
                <div class="mb-3">
                    @include('foodalchemist::livewire.planning.partials.kohaesion-panel')
                </div>
                {{-- x-data hält den Drag-Zustand: dragTyp/dragId = Liste→einfügen, dragSlotId = Position umsortieren.
                     bauModus schaltet zwischen Bearbeiten (Tabelle + Einfüge-Listen) und Menü-Ansicht (Gäste-Sicht,
                     UX-Umbau 2026-07-03) — Alpine statt Livewire: kein Re-Mount, ungespeicherte Eingaben bleiben. --}}
                <div class="flex gap-3 items-start w-full" x-data="{ dragTyp: null, dragId: null, dragSlotId: null, bauModus: true }">
                {{-- Phase 3: linke Spalte — Basisrezepte als Position einfügen (sticky Panel wie zutaten-kern) --}}
                <aside x-show="bauModus" x-cloak class="w-72 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-0 self-start max-h-[70vh]" data-konzept-basisliste>
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
                                    <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" title="{{ $pk->name }}{{ $pk->role ? ' · ' . $pk->role : '' }}">{{ $pk->name }}</span>
                                    <span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ $pk->price_per_person !== null ? number_format((float) $pk->price_per_person, 2, ',', '.') . ' €' : '' }}</span>
                                    <button type="button" wire:click="positionEinfuegen('paket', {{ $pk->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                                </div>
                            @empty
                                <p class="text-[10px] text-gray-500 px-1">— keine Treffer —</p>
                            @endforelse
                        </div>
                    @else
                        <p class="{{ $dt }} mb-1">Basisrezepte ({{ $basisListe->count() }})</p>
                        <input type="search" wire:model.live.debounce.300ms="basisSuche" placeholder="Basisrezept suchen …" class="{{ $input }} !py-0.5 !text-[11px] mb-1" />
                        <div class="space-y-1 mb-1.5">
                            <select wire:model.live="basisHg" class="{{ $input }} !py-0.5 !text-[11px]" data-basis-filter-hg>
                                <option value="">Alle Hauptgruppen</option>
                                @foreach($basisHauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->label }}</option>@endforeach
                            </select>
                            <select wire:model.live="basisKat" class="{{ $input }} !py-0.5 !text-[11px]" data-basis-filter-kat @disabled($basisKategorien->isEmpty())>
                                <option value="">Alle Kategorien</option>
                                @foreach($basisKategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->label }}</option>@endforeach
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
                                    <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" title="{{ $br->name }}">{{ $br->name }}</span>
                                    <span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ $br->ek_total_eur !== null ? number_format((float) $br->ek_total_eur, 2, ',', '.') . ' €' : '' }}</span>
                                    <button type="button" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $br->id }} })" class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Rezept einsehen">📖</button>
                                    <button type="button" wire:click="positionEinfuegen('basisrezept', {{ $br->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                                </div>
                            @empty
                                <p class="text-[10px] text-gray-500 px-1">— keine Treffer —</p>
                            @endforelse
                        </div>
                    @endif
                </aside>
                <div class="flex-1 min-w-0 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900" x-text="bauModus ? 'Positionen' : 'Menü-Ansicht'">Positionen</h3>
                        <div class="flex items-center gap-2">
                            {{-- Einfügen läuft über die Listen links/rechts (wie im Gerichte-Editor) + „+ Paket"/Struktur oben. --}}
                            @if($einfuegenNachId !== null)
                                <span x-show="bauModus" class="inline-flex items-center gap-1 text-[11px] text-violet-600" data-einfuege-ziel>
                                    📍 Einfügen unter markierter Zeile
                                    <button type="button" wire:click="$set('einfuegenNachId', null)" class="underline decoration-dotted hover:text-violet-800">ans Ende</button>
                                </span>
                            @endif
                            {{-- UX-Umbau 2026-07-03: Toggle Bearbeiten ⇄ Menü (Gäste-Perspektive mit aufgelöstem Wording) --}}
                            <div class="inline-flex rounded-lg bg-black/[0.05] p-0.5" role="group" aria-label="Ansicht" data-konzept-ansicht-toggle>
                                <button type="button" @click="bauModus = true" :class="bauModus ? 'bg-white text-violet-600 shadow-sm' : 'text-gray-600 hover:text-gray-700'" class="px-3 py-1 text-[11px] font-medium rounded-md transition-all" data-ansicht-bearbeiten>⚙ Bearbeiten</button>
                                <button type="button" @click="bauModus = false" :class="!bauModus ? 'bg-white text-violet-600 shadow-sm' : 'text-gray-600 hover:text-gray-700'" class="px-3 py-1 text-[11px] font-medium rounded-md transition-all" data-ansicht-menue>🍽 Menü</button>
                            </div>
                        </div>
                    </div>

                    {{-- ═══ MENÜ-ANSICHT (Gäste-Perspektive, read-only) — UX-Umbau 2026-07-03 ═══ --}}
                    <div x-show="!bauModus" x-cloak class="space-y-3" data-konzept-menue>
                        @php($menueGruppen = [])
                        @php($aktuelleGruppe = ['type' => 'sektion', 'title' => null, 'headerSlotId' => null, 'slots' => []])
                        @foreach($concept->slots as $s)
                            @if(in_array($s->type, ['header', 'header_preis'], true))
                                @php($menueGruppen[] = $aktuelleGruppe)
                                @php($aktuelleGruppe = ['type' => 'header', 'title' => $s->title ?: '(Überschrift)', 'headerSlotId' => $s->id, 'slots' => []])
                            @elseif($s->package_id && $s->package)
                                @php($menueGruppen[] = $aktuelleGruppe)
                                @php($menueGruppen[] = ['type' => 'paket', 'title' => $s->package->name, 'price' => $s->package->price_per_person, 'headerSlotId' => null, 'slots' => [], 'paket' => $s->package])
                                @php($aktuelleGruppe = ['type' => 'sektion', 'title' => null, 'headerSlotId' => null, 'slots' => []])
                            @elseif($s->sales_recipe_id && $s->dish)
                                @php($aktuelleGruppe['slots'][] = $s)
                            @endif
                        @endforeach
                        @php($menueGruppen[] = $aktuelleGruppe)
                        {{-- Gäste-Sicht: leere Gruppen (Paket ohne Gerichte, Header ohne Positionen) sind unsichtbar --}}
                        @php($menueGruppen = collect($menueGruppen)->filter(fn ($g) => $g['type'] === 'paket' ? $g['paket']->dishes->isNotEmpty() : count($g['slots']) > 0)->values())

                        @php($quelleBadge = ['konzept' => ['Konzept-Wording', 'text-violet-600', 'bg-violet-500'], 'standard' => ['VK-Wording (Standard)', 'text-gray-500', 'bg-gray-400'], 'name' => ['Wording fehlt — interner Name', 'text-amber-600', 'bg-amber-500']])
                        @php($wres = app(\Platform\FoodAlchemist\Services\WordingResolver::class))

                        @forelse($menueGruppen as $g)
                            {{-- Gruppen-Container: subtile Klammer um Kopf + Karten (gleiche Fläche wie die Seiten-Listen) --}}
                            <section wire:key="menue-{{ $loop->index }}" class="rounded-xl bg-gray-500/[0.05] border border-black/5 p-3">
                                @php($slotEks = collect($g['slots'])->map(fn ($sx) => $cockpitZeilen[$sx->id]['ek'] ?? null)->filter())
                                @php($slotVks = collect($g['slots'])->map(fn ($sx) => $cockpitZeilen[$sx->id]['price'] ?? null)->filter())
                                <div class="flex items-baseline gap-2 pb-2.5">
                                    @if($g['type'] === 'paket')
                                        <span class="{{ $pill }} {{ $variantPill['primary'] }} shrink-0">📦 Paket</span>
                                        <h4 class="text-sm font-semibold text-gray-900">{{ $g['title'] }}</h4>
                                        <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">{{ $g['paket']->dishes->count() }} {{ $g['paket']->dishes->count() === 1 ? 'Gericht' : 'Gerichte' }}{{ $g['price'] !== null ? ' · ' . number_format((float) $g['price'], 2, ',', '.') . ' €/P fix' : '' }}</span>
                                    @elseif($g['title'])
                                        <span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">Sektion</span>
                                        <h4 class="text-sm font-semibold text-gray-900">{{ $g['title'] }}</h4>
                                        <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">{{ count($g['slots']) }} {{ count($g['slots']) === 1 ? 'Position' : 'Positionen' }}{{ $slotVks->isNotEmpty() ? ' · ' . number_format($slotVks->sum(), 2, ',', '.') . ' €/P' : '' }}{{ $slotEks->isNotEmpty() ? ' · Σ EK ' . number_format($slotEks->sum(), 2, ',', '.') . ' €' : '' }}</span>
                                    @else
                                        <h4 class="text-[11px] font-medium uppercase tracking-wider text-gray-500">Gerichte</h4>
                                        <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">{{ count($g['slots']) }} {{ count($g['slots']) === 1 ? 'Position' : 'Positionen' }}{{ $slotVks->isNotEmpty() ? ' · ' . number_format($slotVks->sum(), 2, ',', '.') . ' €/P' : '' }}</span>
                                    @endif
                                </div>
                                <div class="grid gap-2.5" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
                                    @if($g['type'] === 'paket')
                                        @foreach($g['paket']->dishes as $pg)
                                            @php($pgG = $pg->dish)
                                            @php($pw = $wres->fuerGericht($pgG))
                                            @php($qb = $quelleBadge[$pw['source']] ?? $quelleBadge['name'])
                                            @php($pgEnthaelt = collect(['Schwein' => $pgG?->spec_contains_pork, 'Rind' => $pgG?->spec_contains_beef])->filter()->keys()->all())
                                            <article wire:key="mpcard-{{ $pg->id }}" class="group relative rounded-xl bg-white/60 backdrop-blur-xl border border-white/20 shadow-sm shadow-black/5 px-3.5 py-3 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                                                <div class="flex items-start justify-between gap-2">
                                                    <span class="inline-flex items-center gap-1.5 text-[9.5px] font-semibold uppercase tracking-wider {{ $qb[1] }}"><span class="w-1.5 h-1.5 rounded-full {{ $qb[2] }}"></span>{{ $qb[0] }}</span>
                                                    @if($pg->sales_recipe_id)<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" class="text-gray-300 hover:text-violet-500 opacity-0 group-hover:opacity-100 transition-opacity" title="Gericht öffnen">🍽️</button>@endif
                                                </div>
                                                <p class="text-sm font-semibold text-gray-900 leading-snug mt-1 {{ $pw['source'] === 'name' ? 'italic text-amber-700 font-medium' : '' }}">{{ $pw['text'] }}</p>
                                                <p class="text-[10.5px] text-gray-500 font-mono truncate mt-0.5" title="{{ $pgG?->name }}">{{ $pgG?->name }}</p>
                                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                                    @if($pgG?->spec_is_vegan)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>@elseif($pgG?->spec_is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg.</span>@endif
                                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-medium {{ count($pgEnthaelt) ? 'bg-amber-500/20 text-amber-700' : 'bg-black/5 text-gray-500' }}" title="Allergene / Diät{{ count($pgEnthaelt) ? ' — enthält ' . implode(', ', $pgEnthaelt) : '' }} · Konfidenz {{ $pgG?->allergens_confidence ?? 'unbekannt' }}">A</span>
                                                    @if($pw['source'] === 'name')<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" class="{{ $pill }} {{ $variantPill['warning'] }}" title="VK-Wording am Gericht ergänzen">✎ Wording ergänzen</button>@endif
                                                </div>
                                                <div class="flex gap-3 mt-2.5 pt-2 border-t border-black/5 tabular-nums">
                                                    <span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">VK/P</span><span class="text-xs font-semibold text-gray-600">im Paket</span></span>
                                                    <span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">EK</span><span class="text-xs font-semibold text-gray-700">{{ $pgG?->ek_total_eur !== null ? number_format((float) $pgG->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</span></span>
                                                </div>
                                            </article>
                                        @endforeach
                                    @else
                                        @foreach($g['slots'] as $s)
                                            @php($g0 = $s->dish)
                                            @php($w = $slotWording[$s->id] ?? ['text' => $g0->name, 'source' => 'name'])
                                            @php($qb = $quelleBadge[$w['source']] ?? $quelleBadge['name'])
                                            @php($enthaelt = collect(['Schwein' => $g0->spec_contains_pork, 'Rind' => $g0->spec_contains_beef])->filter()->keys()->all())
                                            @php($ekz = $cockpitZeilen[$s->id]['ek'] ?? null)
                                            @php($vkz = $cockpitZeilen[$s->id]['price'] ?? null)
                                            @php($wpct = ($vkz && (float) $vkz > 0 && $ekz !== null) ? ((float) $ekz / (float) $vkz * 100) : null)
                                            <article wire:key="mcard-{{ $s->id }}" class="group relative rounded-xl bg-white/60 backdrop-blur-xl border border-white/20 shadow-sm shadow-black/5 px-3.5 py-3 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                                                <div class="flex items-start justify-between gap-2">
                                                    <span class="inline-flex items-center gap-1.5 text-[9.5px] font-semibold uppercase tracking-wider {{ $qb[1] }}"><span class="w-1.5 h-1.5 rounded-full {{ $qb[2] }}"></span>{{ $qb[0] }}</span>
                                                    <button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $s->sales_recipe_id }} })" class="text-gray-300 hover:text-violet-500 opacity-0 group-hover:opacity-100 transition-opacity" title="Gericht öffnen">🍽️</button>
                                                </div>
                                                <p class="text-sm font-semibold text-gray-900 leading-snug mt-1 {{ $w['source'] === 'name' ? 'italic text-amber-700 font-medium' : '' }}">{{ $w['text'] }}</p>
                                                <p class="text-[10.5px] text-gray-500 font-mono truncate mt-0.5" title="{{ $g0->name }}">{{ $g0->name }}</p>
                                                <div class="flex flex-wrap gap-1.5 mt-2">
                                                    @if(isset($darreichungInfo[$s->id]))
                                                        <span class="{{ $pill }} {{ str_starts_with($darreichungInfo[$s->id], 'Standard:') ? $variantPill['secondary'] : $variantPill['primary'] }}">🍴 {{ $darreichungInfo[$s->id] }}</span>
                                                    @endif
                                                    @if($g0->dishClass)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $g0->dishClass->label }}</span>@endif
                                                    @if($g0->spec_is_vegan)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>@elseif($g0->spec_is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg.</span>@endif
                                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-medium {{ count($enthaelt) ? 'bg-amber-500/20 text-amber-700' : 'bg-black/5 text-gray-500' }}" title="Allergene / Diät{{ count($enthaelt) ? ' — enthält ' . implode(', ', $enthaelt) : '' }} · Konfidenz {{ $g0->allergens_confidence ?? 'unbekannt' }}">A</span>
                                                    @if(isset($varianteFehlt[$s->id]))<button type="button" wire:click="varianteAnlegen({{ $s->id }})" class="{{ $pill }} {{ $variantPill['warning'] }}" title="Konzept-Servierform fehlt als Darreichung — anlegen">⚠ Form fehlt</button>@endif
                                                    @if($w['source'] === 'name')<button type="button" @click="bauModus = true" class="{{ $pill }} {{ $variantPill['warning'] }}" title="In der Bearbeiten-Ansicht Wording ergänzen">✎ Wording ergänzen</button>@endif
                                                </div>
                                                <div class="flex gap-3 mt-2.5 pt-2 border-t border-black/5 tabular-nums">
                                                    <span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">VK/P</span><span class="text-xs font-semibold text-emerald-600">{{ $vkz !== null ? number_format((float) $vkz, 2, ',', '.') . ' €' : '—' }}</span></span>
                                                    <span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">EK</span><span class="text-xs font-semibold text-gray-700">{{ $ekz !== null ? number_format((float) $ekz, 2, ',', '.') . ' €' : '—' }}</span></span>
                                                    <span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">W%</span><span class="text-xs font-semibold {{ $wpct !== null && $wpct > 35 ? 'text-rose-500' : 'text-gray-700' }}">{{ $wpct !== null ? number_format($wpct, 1, ',', '.') . '%' : '—' }}</span></span>
                                                </div>
                                            </article>
                                        @endforeach
                                    @endif
                                </div>
                            </section>
                        @empty
                            <p class="text-xs text-gray-500 py-8 text-center">Noch keine Gerichte im Konzept — links in der Bearbeiten-Ansicht einfügen.</p>
                        @endforelse

                        <div class="flex flex-wrap gap-3 text-[11px] text-gray-600 pt-1 border-t border-black/5">
                            <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span>Konzept-Wording</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>VK-Wording Standard</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>kein Wording — Handlungsbedarf</span>
                            <span class="ml-auto italic">Kette: Foodbook-Override → Konzept → Standard → Name</span>
                        </div>
                    </div>

                    {{-- ═══ BEARBEITEN-ANSICHT (Tabelle + Struktur + Paket-Bilden) ═══ --}}
                    <div x-show="bauModus" x-cloak class="space-y-4">
                    {{-- Kombi-Suche (wie Gerichte-Editor): filtert BEIDE Seiten-Listen; Übernehmen per „+"/Drag in den Spalten. --}}
                    <input type="search" wire:model.live.debounce.300ms="kombiSuche" data-konzept-kombisuche
                           placeholder="Suchen — filtert Basisrezepte/Pakete UND Gerichte … (Übernehmen per + in den Spalten)"
                           class="{{ $input }} !py-2" />
                    {{-- B3: Struktur-Blöcke (freie Gliederung OHNE Paket) + „+ Paket" (= bepreister Abschnitt) --}}
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $label }} mr-1">Struktur:</span>
                        <button type="button" wire:click="neuesPaketAlsPosition" class="{{ $btnGhostXs }} !text-violet-600 !border-violet-500/30" title="Neues Paket als Abschnitt anlegen, einfügen und öffnen">+ Paket</button>
                        <button type="button" wire:click="blockHinzu('header')" class="{{ $btnGhostXs }}">+ Header</button>
                        <button type="button" wire:click="blockHinzu('text')" class="{{ $btnGhostXs }}">+ Text</button>
                        <button type="button" wire:click="blockHinzu('spacer')" class="{{ $btnGhostXs }}">+ Leerzeile</button>
                    </div>
                    {{-- B4: aus markierten Gericht-/Basisrezept-Positionen ein Paket bilden --}}
                    @if(count($auswahl) > 0)
                        <div class="flex items-center gap-2 rounded-xl border border-violet-500/30 bg-violet-500/5 px-3 py-2" data-paket-bilden>
                            <span class="text-xs font-medium text-violet-700 shrink-0">{{ count($auswahl) }} markiert →</span>
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
                            @php($vkz = $cockpitZeilen[$slot->id]['price'] ?? null)
                            @php($wpct = ($vkz && (float) $vkz > 0 && $ekz !== null) ? ((float) $ekz / (float) $vkz * 100) : null)
                            <tr wire:key="erow-{{ $slot->id }}"
                                @dragover.prevent
                                @drop.prevent="if (dragId) { $wire.positionDrop(dragTyp, dragId, {{ $slot->id }}); } else if (dragSlotId && dragSlotId !== {{ $slot->id }}) { $wire.positionVerschieben(dragSlotId, {{ $slot->id }}); } dragTyp = null; dragId = null; dragSlotId = null"
                                class="{{ $tr }} {{ $istStruktur ? 'bg-violet-500/[0.03]' : '' }} {{ $slot->package_id ? 'bg-violet-500/[0.06] border-t-2 !border-t-violet-500/30' : '' }} {{ $einfuegenNachId === $slot->id ? 'border-b-2 !border-b-violet-400' : '' }}">
                                <td class="{{ $td }} !px-1.5 !py-0.5 whitespace-nowrap align-top">
                                    {{-- Ziehgriff: Position per Drag umsortieren (▲▼ bleibt als zuverlässige Alternative) --}}
                                    <span class="inline-block cursor-grab active:cursor-grabbing text-gray-500 hover:text-violet-500 select-none align-middle mr-0.5" draggable="true"
                                          @dragstart="dragSlotId = {{ $slot->id }}; $event.dataTransfer.effectAllowed = 'move'" @dragend="dragSlotId = null" title="ziehen zum Umsortieren">⠿</span>
                                    <span class="inline-flex flex-col align-middle leading-none">
                                        <button type="button" wire:click="slotHoch({{ $slot->id }})" class="text-[9px] text-gray-600 hover:text-violet-500 leading-none" title="hoch">▲</button>
                                        <button type="button" wire:click="slotRunter({{ $slot->id }})" class="text-[9px] text-gray-600 hover:text-violet-500 leading-none" title="runter">▼</button>
                                    </span>
                                    @if(! $istStruktur && $slot->sales_recipe_id)
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
                                                <div class="mt-1 text-[11px] text-violet-600 tabular-nums">{{ $ss['n'] }} {{ $ss['n'] === 1 ? 'Position' : 'Positionen' }} · Σ EK {{ number_format($ss['ek'], 2, ',', '.') }} € · {{ number_format($ss['vk'], 2, ',', '.') }} €/P</div>
                                            @endif
                                            @if($slot->type === 'header_preis')
                                                <span class="inline-flex items-center gap-1 mt-1">
                                                    <input type="number" step="0.01" min="0" wire:model.blur="blockForm.{{ $slot->id }}.price_value" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-24 text-right tabular-nums" placeholder="€" />
                                                    <select wire:model="blockForm.{{ $slot->id }}.preis_basis" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-28">
                                                        @foreach(['person' => '/Person', 'pauschal' => 'pauschal', 'staffel' => 'Staffel'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                                    </select>
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                @else
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->sales_recipe_id)
                                            <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.quantity" wire:change="mengeSpeichern({{ $slot->id }})" class="{{ $input }} !w-16 text-right tabular-nums" placeholder="1" />
                                        @else<span class="text-gray-300">—</span>@endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->sales_recipe_id)
                                            <select wire:model="slotForm.{{ $slot->id }}.unit_vocab_id" wire:change="mengeSpeichern({{ $slot->id }})" class="{{ $input }} !w-24">
                                                <option value="">—</option>
                                                @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                                            </select>
                                        @else<span class="text-gray-300">—</span>@endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->package_id && $slot->package)
                                            {{-- Paket = Abschnitts-Header (Gerichte stehen als eingerückte Zeilen darunter) --}}
                                            <span class="{{ $pill }} {{ $variantPill['info'] }}">📦 Paket</span>
                                            <span class="text-sm font-semibold text-gray-900">{{ $slot->package->name }}</span>
                                            <button type="button" wire:click="paketOeffnen({{ $slot->package_id }})" class="text-gray-500 hover:text-violet-500 align-middle" title="Paket öffnen / bearbeiten">📦</button>
                                            @if($slot->package->class)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $slot->package->class }}</span>@endif
                                            <span class="text-gray-500 text-[11px] tabular-nums">{{ $slot->package->price_per_person !== null ? number_format((float) $slot->package->price_per_person, 2, ',', '.') . ' €/P' : '' }}</span>
                                        @elseif($slot->sales_recipe_id && $slot->dish)
                                            @php($g = $slot->dish)
                                            @php($enthaelt = collect(['Schwein' => $g->spec_contains_pork, 'Rind' => $g->spec_contains_beef])->filter()->keys()->all())
                                            @php($allTitle = 'Allergene / Diät' . (count($enthaelt) ? ' — enthält ' . implode(', ', $enthaelt) : '') . ' · Konfidenz ' . ($g->allergens_confidence ?? 'unbekannt'))
                                            <span class="{{ $pill }} font-medium" style="{{ $typStyle($slot->type === 'basisrezept' ? 'basisrezept' : 'gericht') }}">{{ $slot->type === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</span>
                                            <span class="text-sm font-medium">{{ $g->name }}</span>
                                            @if($g->dishClass)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $g->dishClass->label }}</span>@endif
                                            @if(isset($darreichungInfo[$slot->id]))
                                                <span class="{{ $pill }} {{ str_starts_with($darreichungInfo[$slot->id], 'Standard:') ? $variantPill['secondary'] : $variantPill['primary'] }}"
                                                      title="Aufgelöste Darreichung dieser Position (explizit → Konzept-Servierform → Standard)" data-darreichung-pill>🍴 {{ $darreichungInfo[$slot->id] }}</span>
                                            @endif
                                            @if(isset($darreichungOptionen[$slot->id]))
                                                {{-- A1: explizite Form nur für diese Position (auto = Konzept-Form/Standard) --}}
                                                <select wire:change="slotDarreichungSetzen({{ $slot->id }}, $event.target.value)"
                                                        class="{{ $input }} !py-0 !text-[10px] !w-auto inline-block align-middle" data-slot-form-picker
                                                        title="Form dieser Position übersteuern — auto folgt der Konzept-Servierform bzw. dem Standard">
                                                    <option value="" @selected($slot->presentation_id === null)>auto</option>
                                                    @foreach($darreichungOptionen[$slot->id] as $opt)
                                                        <option value="{{ $opt['id'] }}" @selected((int) $slot->presentation_id === (int) $opt['id'])>{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            @if($g->spec_is_vegan)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>@elseif($g->spec_is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg.</span>@endif
                                            <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-medium align-middle {{ count($enthaelt) ? 'bg-amber-500/20 text-amber-700' : 'bg-black/5 text-gray-500' }}" title="{{ $allTitle }}">A</span>
                                            {{-- Phase 6: einsehen — Basisrezept → Rezept-Fenster, VK-Gericht → Gericht-Fenster (über dem Editor) --}}
                                            <button type="button" @click="Livewire.dispatch('{{ $slot->type === 'basisrezept' ? 'recipe-modal' : 'vk-modal' }}.oeffnen', { id: {{ $slot->sales_recipe_id }} })" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="{{ $slot->type === 'basisrezept' ? 'Rezept' : 'Gericht' }} einsehen">{{ $slot->type === 'basisrezept' ? '📖' : '🍽️' }}</button>
                                            {{-- R4.4: Zutaten-Baum (read-first) + konzept-lokale Slot-Variante --}}
                                            <button type="button" wire:click="zutatenToggle({{ $slot->id }})" class="text-[11px] ml-1 align-middle {{ $zutatenOffenSlotId === $slot->id ? 'text-violet-600' : 'text-gray-300 hover:text-violet-500' }}" title="Zutaten-Zeilen zeigen (Tausch erzeugt eine konzept-lokale Variante — Quell-Gericht bleibt unangetastet)" data-slot-zutaten-toggle>🧾</button>
                                            @if($slot->variant_source_recipe_id !== null)
                                                <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="Konzept-lokale Variante — das Quell-Gericht ist unverändert" data-slot-variiert>variiert</span>
                                                <button type="button" wire:click="slotVarianteZuruecksetzen({{ $slot->id }})" wire:confirm="Variante verwerfen und Original-Gericht wiederherstellen?" class="text-[10px] text-gray-500 hover:text-rose-500 align-middle" data-slot-variante-reset>↩ Original</button>
                                            @endif
                                            {{-- Umbau-Spec Phase 5: Konzept-Servierform ohne passende Darreichung → 1-Klick-Anlage --}}
                                            @if(isset($varianteFehlt[$slot->id]))
                                                <button type="button" wire:click="varianteAnlegen({{ $slot->id }})"
                                                        class="{{ $pill }} {{ $variantPill['warning'] }}" data-variante-fehlt
                                                        title="Gericht hat keine Darreichung für „{{ $concept->servingForm?->label }}" — Klick legt sie an (vorbefüllt aus der Standard-Form, danach Grammatur prüfen)">⚠ {{ $concept->servingForm?->label }} fehlt — anlegen</button>
                                            @endif
                                            {{-- Concept-Wording: Brand-Voice-Anzeigename je Position (leer = Standardname; ✨ oben füllt alle) --}}
                                            <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.wording" wire:change="wordingSpeichern({{ $slot->id }})" class="{{ $input }} !py-0.5 !text-[11px] italic mt-1 w-full" placeholder="Anzeigename im Konzept-Wording … (leer = „{{ $g->name }}“)" data-slot-wording />
                                        @else
                                            <span class="text-xs text-gray-500">leer — links/rechts aus den Listen einfügen</span>
                                            @if($slot->note)
                                                {{-- R6.1: Generator-Begründung, warum der Slot bewusst leer blieb --}}
                                                <div class="text-[11px] text-amber-600 mt-0.5" data-slot-leer-begruendung>⚠ {{ $slot->note }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top"><input type="text" wire:model.blur="slotForm.{{ $slot->id }}.role" wire:change="slotSpeichern({{ $slot->id }})" class="{{ $input }} !w-28" placeholder="Rolle" /></td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top">{{ $vkz !== null ? number_format((float) $vkz, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top">{{ $ekz !== null ? number_format((float) $ekz, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top text-gray-500">{{ $wpct !== null ? number_format($wpct, 1, ',', '.') . '%' : '—' }}</td>
                                @endif
                                <td class="{{ $td }} !px-2 text-right whitespace-nowrap align-top">
                                    @if(! $istStruktur)
                                        <label class="inline-flex items-center gap-0.5 text-[10px] text-gray-500 mr-1" title="Pflicht-Position">
                                            <input type="checkbox" wire:model="slotForm.{{ $slot->id }}.is_pflicht" wire:change="slotSpeichern({{ $slot->id }})" class="rounded border-gray-300 !w-3 !h-3" />P
                                        </label>
                                        <button type="button" wire:click="fillToggle({{ $slot->id }})" class="text-gray-500 hover:text-violet-500 text-[11px]" title="Befüllung ändern">⚙</button>
                                    @endif
                                    <button type="button" wire:click="zielSetzen({{ $slot->id }})" class="text-[11px] ml-1 align-middle {{ $einfuegenNachId === $slot->id ? 'text-violet-600' : 'text-gray-300 hover:text-violet-500' }}" title="{{ $einfuegenNachId === $slot->id ? 'Einfügeziel aktiv — nächste Position landet hier darunter (Klick = abwählen)' : 'Hier einfügen — die nächste neue Position landet unter dieser Zeile' }}">📍</button>
                                    <button type="button" wire:click="slotRaus({{ $slot->id }})" class="text-gray-500 hover:text-red-500 ml-1" title="entfernen">✕</button>
                                </td>
                            </tr>
                            {{-- Paket-Position = Abschnitt: seine Gerichte stehen immer read-only als eingerückte Zeilen darunter --}}
                            @if($slot->package_id && $slot->package)
                                <tr wire:key="epaket-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 align-top">
                                        <div class="ml-2 rounded-lg border border-gray-900/15 bg-black/[0.02] divide-y divide-black/5">
                                            @forelse($slot->package->dishes as $pg)
                                                <div wire:key="epaketg-{{ $slot->id }}-{{ $pg->id }}" class="flex items-center gap-2 px-3 py-1 text-[11px]">
                                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gericht') }}">G</span>
                                                    <span class="flex-1 min-w-0 break-words leading-snug text-gray-700">{{ $pg->dish?->name ?? '—' }}</span>
                                                    <span class="shrink-0 text-gray-500 tabular-nums">{{ $pg->quantity !== null ? rtrim(rtrim(number_format((float) $pg->quantity, 2, ',', '.'), '0'), ',') . '×' : '' }}</span>
                                                    <span class="shrink-0 text-gray-500 tabular-nums w-16 text-right">{{ $pg->dish?->sales_net !== null ? number_format((float) $pg->dish->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                                                    @if($pg->sales_recipe_id)<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="Gericht einsehen">🍽️</button>@endif
                                                </div>
                                            @empty
                                                <p class="px-3 py-1.5 text-[11px] text-gray-500">Paket ohne Gerichte — im Paket-Editor pflegen.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                            {{-- R4.4: Zutaten-Zeilen des Gerichts (read-first) — ♻ Tausch läuft IMMER über die Slot-Variante --}}
                            @if($zutatenOffenSlotId === $slot->id && $slot->sales_recipe_id !== null)
                                <tr wire:key="ezutaten-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 align-top">
                                        <div class="ml-2 rounded-lg border border-violet-500/20 bg-violet-500/[0.03] divide-y divide-black/5" data-slot-zutaten>
                                            @forelse($slotZutaten as $z)
                                                <div wire:key="ezutat-{{ $slot->id }}-{{ $z['id'] }}" class="flex items-center gap-2 px-3 py-1 text-[11px]">
                                                    <span class="flex-1 min-w-0 truncate text-gray-700">{{ $z['name'] }}</span>
                                                    <span class="shrink-0 text-gray-500 tabular-nums">{{ $z['menge'] }}</span>
                                                    @if($z['swap_locked'])
                                                        <span class="shrink-0" title="swap-gesperrt — bewusst gewählte Realisierung">🔒</span>
                                                    @elseif($z['ersatz'] !== null)
                                                        <button type="button" wire:click="slotZutatTauschen({{ $slot->id }}, {{ $z['id'] }})"
                                                                class="{{ $btnGhostXs }} text-violet-600 shrink-0"
                                                                title="Konzept-lokal tauschen (erzeugt/nutzt die Slot-Variante — Quell-Gericht bleibt unangetastet)" data-slot-zutat-tausch>
                                                            ♻ {{ $z['ersatz'] }}
                                                        </button>
                                                    @endif
                                                    @if($z['peek_recipe_id'] !== null)
                                                        <button type="button" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $z['peek_recipe_id'] }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="Sub-Rezept einsehen">📖</button>
                                                    @endif
                                                </div>
                                            @empty
                                                <p class="px-3 py-1.5 text-[11px] text-gray-500">Keine Zutaten-Zeilen an diesem Gericht.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                            @if(! $istStruktur && ($fillOpenId === $slot->id || (! $slot->package_id && ! $slot->sales_recipe_id)))
                                <tr wire:key="efill-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 bg-black/[0.02]">
                                        <div class="flex flex-wrap items-center gap-2 pt-1">
                                            <select x-on:change="$wire.fuellePaket({{ $slot->id }}, $event.target.value); $event.target.value=''" class="{{ $input }} w-56">
                                                <option value="">↹ Paket (Rolle {{ $slot->role ?: '–' }}) …</option>
                                                @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                                    <option value="{{ $b->id }}">{{ $b->name }}{{ $b->price_per_person !== null ? ' (' . number_format((float) $b->price_per_person, 2, ',', '.') . ' €)' : '' }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="gerichtPicker({{ $slot->id }})" class="{{ $btnGhostXs }}">Gericht / Basisrezept …</button>
                                            <button type="button" wire:click="neuesPaketImSlot({{ $slot->id }})" class="{{ $btnGhostXs }} text-violet-600" title="Inline ein neues Paket schnüren">+ neues Paket</button>
                                            @if($slot->package_id || $slot->sales_recipe_id)
                                                <button type="button" wire:click="slotLeeren({{ $slot->id }})" class="text-[11px] text-gray-500 hover:text-red-500">leeren</button>
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
                                                                <span class="text-gray-500 tabular-nums shrink-0">@if($pickTyp === 'gericht'){{ $kand->sales_net !== null ? number_format((float) $kand->sales_net, 2, ',', '.') . ' €' : '' }}@else{{ $kand->ek_total_eur !== null ? 'EK ' . number_format((float) $kand->ek_total_eur, 2, ',', '.') . ' €' : '' }}@endif</span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @elseif($gerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '' || $pickDiaet !== '')
                                                    <p class="text-[11px] text-gray-500 px-2 py-1">Keine Treffer für diese Auswahl.</p>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="9" class="text-xs text-gray-500 py-4 text-center">Noch keine Positionen — links/rechts aus den Listen mit „+" einfügen (oder ziehen), Abschnitte über „+ Paket".</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    </div>
                    </div>{{-- /BEARBEITEN-ANSICHT (x-show bauModus) --}}
                </div>{{-- /mittlere Spalte --}}
                {{-- Phase 3: rechte Spalte — VK-Gerichte als Position einfügen (VK-Baum-Filter + Liste) --}}
                <aside x-show="bauModus" x-cloak class="w-72 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-0 self-start max-h-[70vh]" data-konzept-gerichtliste>
                    <p class="{{ $dt }} mb-1">VK-Gerichte ({{ $gerichtListe->count() }})</p>
                    @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'gerichtSuche'])
                    <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1 mt-1.5">
                        @forelse($gerichtListe as $gr)
                            <div wire:key="kgr-{{ $gr->id }}" draggable="true" @dragstart="dragTyp = 'gericht'; dragId = {{ $gr->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px] cursor-grab active:cursor-grabbing">
                                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gericht') }}">G</span>
                                <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" title="{{ $gr->name }}">{{ $gr->name }}</span>
                                <span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ $gr->sales_net !== null ? number_format((float) $gr->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                                <button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $gr->id }} })" class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Gericht einsehen">🍽️</button>
                                <button type="button" wire:click="positionEinfuegen('gericht', {{ $gr->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                            </div>
                        @empty
                            <p class="text-[10px] text-gray-500 px-1">— keine Treffer —</p>
                        @endforelse
                    </div>
                </aside>
                </div>{{-- /3-Spalten-Flex --}}
                @else
                    {{-- Paket: Gerichte schnüren — Mitte (Inhalt) + rechte VK-Gerichte-Filter-Spalte (wie Concept-Aufbau, Dominique 2026-06-17) --}}
                    <div class="flex gap-3 items-start">
                    <div class="flex-1 min-w-0 space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900">Posten im Paket</h3>
                        <span class="text-[11px] text-gray-500">Gerichte (VK) + Basisrezepte (Menge = g/Person).</span>
                    </div>
                    <div class="space-y-1">
                        @forelse($paket->dishes as $pg)
                            @php($istBasis = ! ($pg->dish?->is_sales_recipe ?? true))
                            <div wire:key="epg-{{ $pg->id }}" class="flex items-center gap-2 rounded-lg border border-black/5 px-3 py-1.5">
                                <span class="flex flex-col -my-0.5 shrink-0">
                                    <button type="button" wire:click="gerichtHoch({{ $pg->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▲</button>
                                    <button type="button" wire:click="gerichtRunter({{ $pg->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▼</button>
                                </span>
                                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle($istBasis ? 'basisrezept' : 'gericht') }}">{{ $istBasis ? 'BR' : 'G' }}</span>
                                <span class="flex-1 min-w-0 truncate text-sm">{{ $pg->dish?->name ?? '—' }}</span>
                                @if($pg->sales_recipe_id)<button type="button" @click="Livewire.dispatch('{{ $istBasis ? 'recipe-modal.oeffnen' : 'vk-modal.oeffnen' }}', { id: {{ $pg->sales_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="{{ $istBasis ? 'Basisrezept' : 'Gericht' }} einsehen">{{ $istBasis ? '📋' : '🍽️' }}</button>@endif
                                <span class="text-[10px] text-gray-500">{{ $istBasis ? 'g/Person' : 'Menge/Person' }}</span>
                                <input type="number" step="0.01" min="0" wire:model.blur="" value="{{ $pg->quantity }}" wire:change="gerichtMengeSpeichern({{ $pg->id }}, $event.target.value)" class="{{ $input }} w-24 text-right tabular-nums" />
                                <span class="text-gray-500 text-xs tabular-nums w-16 text-right">@if($istBasis){{ $pg->dish?->ek_total_eur !== null ? 'EK ' . number_format((float) $pg->dish->ek_total_eur, 2, ',', '.') . ' €' : '' }}@else{{ $pg->dish?->sales_net !== null ? number_format((float) $pg->dish->sales_net, 2, ',', '.') . ' €' : '' }}@endif</span>
                                <button type="button" wire:click="gerichtRaus({{ $pg->sales_recipe_id }})" class="text-gray-500 hover:text-red-500 px-1" title="entfernen">✕</button>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500 py-4 text-center">Noch keine Posten. Rechts Gericht oder Basisrezept suchen und hinzufügen.</p>
                        @endforelse
                    </div>
                    </div>{{-- /Mitte Paket-Inhalt --}}
                    {{-- rechte Spalte: roomy VK-Gerichte-Filter + Liste (wie Concept-Aufbau) --}}
                    <aside class="w-80 shrink-0 hidden lg:flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-0 self-start max-h-[70vh]" data-paket-gerichtliste>
                    <p class="{{ $dt }} mb-1">Posten hinzufügen</p>
                    <div class="flex items-center gap-1 mb-1.5">
                        <button type="button" wire:click="$set('paketQuelle', 'gericht')" class="{{ $pill }} {{ $paketQuelle !== 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Gericht</button>
                        <button type="button" wire:click="$set('paketQuelle', 'basisrezept')" class="{{ $pill }} {{ $paketQuelle === 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Basisrezept</button>
                    </div>
                    {{-- Park-Flow (Politur): suchen → [+] parken → Menge bzw. g/Person → Enter → ✓-Flash --}}
                    <div class="space-y-1 flex-1 min-h-0 overflow-y-auto" x-data="{
                            geparkt: null, quantity: '', flash: false,
                            park(id, name) { this.geparkt = { id, name }; this.quantity = ''; this.$nextTick(() => this.$refs.quantity && this.$refs.quantity.focus()); },
                            einfuegen() { if (!this.geparkt) return; this.$wire.gerichtHinzu(this.geparkt.id, this.quantity); this.geparkt = null; this.quantity = ''; this.flash = true; setTimeout(() => { this.flash = false; }, 1400); },
                         }">
                        <div x-show="geparkt === null">
                            @if($paketQuelle === 'basisrezept')
                                <input type="search" wire:model.live.debounce.300ms="paketGerichtSuche" placeholder="Basisrezept suchen …" class="{{ $input }} w-full" />
                            @else
                                @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'paketGerichtSuche'])
                            @endif
                            <p class="text-[10px] text-gray-500 mt-0.5">Treffer: <span class="text-violet-500 font-bold">+</span> parken → {{ $paketQuelle === 'basisrezept' ? 'g/Person' : 'Menge/Person' }} → Enter.</p>
                            @if($paketKandidaten->isNotEmpty())
                                <div class="space-y-0.5 max-h-56 overflow-y-auto mt-1">
                                    @foreach($paketKandidaten as $kand)
                                        <div wire:key="epk-{{ $paketQuelle }}-{{ $kand->id }}" class="flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">
                                            <span class="truncate">{{ $kand->name }}</span>
                                            <span class="flex items-center gap-2 shrink-0">
                                                <span class="text-gray-500 tabular-nums">@if($paketQuelle === 'basisrezept'){{ $kand->ek_total_eur !== null ? 'EK ' . number_format((float) $kand->ek_total_eur, 2, ',', '.') . ' €' : '' }}@else{{ $kand->sales_net !== null ? number_format((float) $kand->sales_net, 2, ',', '.') . ' €' : '' }}@endif</span>
                                                <button type="button" @click="park({{ $kand->id }}, @js($kand->name))" class="text-violet-500 font-bold px-1" title="parken">+</button>
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif($paketGerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '' || $pickDiaet !== '')
                                <p class="text-[11px] text-gray-500 px-2 py-1 mt-1">Keine Treffer für diese Auswahl.</p>
                            @endif
                        </div>
                        <div x-show="geparkt !== null" x-cloak class="flex items-center gap-2" data-park-zeile>
                            <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $paketQuelle === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</span>
                            <span class="flex-1 truncate text-sm" x-text="geparkt?.name"></span>
                            <input type="number" step="0.01" min="0" x-ref="quantity" x-model="quantity" @keydown.enter.prevent="einfuegen()" placeholder="{{ $paketQuelle === 'basisrezept' ? 'g/Person' : 'Menge/Person' }}" class="{{ $input }} w-32 text-right tabular-nums" />
                            <button type="button" @click="einfuegen()" class="{{ $btnGhostXs }} text-emerald-600">Einfügen ⏎</button>
                            <button type="button" @click="geparkt = null" class="{{ $btnGhostXs }}">✕</button>
                        </div>
                        <p x-show="flash" x-cloak class="text-[11px] text-emerald-600">✓ hinzugefügt</p>
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
                        <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['confidence']] ?? $variantPill['secondary'] }}">Konf. {{ $aggregat['allergene']['confidence'] }}</span>
                    </div>
                @else
                    <p class="text-sm text-gray-500 py-6 text-center">Noch keine Gerichte für den Allergen-Rollup.</p>
                @endif

                <div class="border-t border-black/5 mt-4 pt-3 space-y-2">
                @if($aggregat && $aggregat['naehrwerte']['kcal'] !== null)
                    <div class="flex items-center justify-between">
                        <span class="{{ $label }}">Nährwerte / Person (aus den Gerichten · Portionsgramm)</span>
                        <span class="{{ $pill }} {{ $konfPill[$aggregat['naehrwerte']['confidence']] ?? $variantPill['secondary'] }}">Konf. {{ $aggregat['naehrwerte']['confidence'] }}</span>
                    </div>
                    <div class="grid grid-cols-7 gap-2">
                        @foreach(['kcal' => 'kcal', 'protein_g' => 'Eiweiß (g)', 'fett_g' => 'Fett (g)', 'gesfett_g' => 'dav. ges. (g)', 'kh_g' => 'KH (g)', 'zucker_g' => 'dav. Zucker (g)', 'salz_g' => 'Salz (g)'] as $k => $l)
                            <div class="rounded-lg bg-black/[0.03] px-3 py-2 text-center">
                                <p class="text-base font-semibold tabular-nums">{{ $aggregat['naehrwerte'][$k] !== null ? number_format((float) $aggregat['naehrwerte'][$k], $k === 'kcal' ? 0 : 1, ',', '.') : '—' }}</p>
                                <p class="text-[10px] text-gray-500 uppercase">{{ $l }}</p>
                            </div>
                        @endforeach
                    </div>
                    @unless($aggregat['naehrwerte']['vollstaendig'])
                        <p class="text-[11px] text-amber-600">⚠ Nur {{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }}/{{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichte haben Nährwert + Portionsgramm — Werte sind eine Untergrenze.</p>
                    @endunless
                @else
                    <p class="text-sm text-gray-500 py-6 text-center">Keine Nährwerte — den Gerichten fehlen Werte oder Portionsgramm.</p>
                @endif
                </div>
            @endif

            {{-- ── Tab: KALKULATION ──────────────────────────────────────── --}}
            @if($tab === 'kalkulation')
                {{-- Concept-VK: automatisch (Σ Positionen) ODER manuell (z. B. Lunchbuffet, Preis auf EK-Basis) --}}
                @if($concept)
                    <div class="rounded-xl border border-black/5 p-3 space-y-2" data-concept-vk>
                        <div class="flex items-center justify-between">
                            <span class="{{ $label }}">VK-Preis / Person</span>
                            <div class="flex gap-1">
                                <button type="button" wire:click="setPreisModus('auto')" class="{{ $pill }} {{ ($form['price_mode'] ?? 'auto') === 'auto' ? $variantPill['primary'] : $variantPill['secondary'] }}">automatisch (Summe)</button>
                                <button type="button" wire:click="setPreisModus('manuell')" class="{{ $pill }} {{ ($form['price_mode'] ?? 'auto') === 'manuell' ? $variantPill['primary'] : $variantPill['secondary'] }}">manuell</button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
                            <span class="text-gray-600">Berechnete Summe: <span class="tabular-nums font-medium text-gray-900">{{ number_format((float) ($cockpit['summe_pro_person'] ?? 0), 2, ',', '.') }} €</span></span>
                            <span class="text-gray-600">Wareneinsatz: <span class="tabular-nums">{{ number_format((float) ($cockpit['ek_per_person'] ?? 0), 2, ',', '.') }} €</span></span>
                        </div>
                        @if(($form['price_mode'] ?? 'auto') === 'manuell')
                            <div class="flex items-center gap-2">
                                <label class="{{ $label }}">Fixer VK / Person</label>
                                <input type="number" step="0.01" min="0" wire:model.blur="form.price_per_person_manual" wire:change="speichern" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 24,90" />
                                <span class="text-[11px] text-gray-500">überschreibt die Summe — EK bleibt als Basis sichtbar</span>
                            </div>
                        @endif
                    </div>
                @endif
                {{-- M-K1/Doc 16: Herstellkosten-Wasserfall (WE → +Blöcke → HK2 → VK-Vorschlag) --}}
                @if($kalkulation)
                    <div class="rounded-xl border border-black/5 p-3 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="{{ $label }}">Herstellkosten (HK2) — Aufschlüsselung / {{ $concept ? 'Person' : 'Person' }}</span>
                            <span class="text-[11px] text-gray-500">Marge {{ rtrim(rtrim(number_format((float) $kalkulation['marge_pct'], 2, ',', '.'), '0'), ',') }} %</span>
                        </div>
                        @foreach($kalkulation['bloecke'] as $blk)
                            <div class="flex items-center justify-between text-xs py-0.5 {{ $blk['key'] === 'we' ? 'font-medium text-gray-900' : 'text-gray-600' }}">
                                <span>{{ $blk['key'] === 'we' ? '' : '+ ' }}{{ $blk['label'] }}</span>
                                <span class="tabular-nums">{{ number_format((float) $blk['betrag'], 2, ',', '.') }} €</span>
                            </div>
                        @endforeach
                        <div class="flex items-center justify-between text-xs py-1 border-t border-black/5 font-semibold text-gray-900">
                            <span>= HK2</span><span class="tabular-nums">{{ number_format((float) $kalkulation['hk2_pro_person'] ?? $kalkulation['hk2_pro_portion'] ?? 0, 2, ',', '.') }} €</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-600">VK-Vorschlag (HK2 × Marge)</span>
                            <span class="tabular-nums text-violet-700 font-medium">{{ number_format((float) $kalkulation['vk_vorschlag'], 2, ',', '.') }} €</span>
                        </div>
                        @if($kalkulation['db_eur'] !== null)
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-600">Deckungsbeitrag (gesetzter VK − HK2)</span>
                                <span class="tabular-nums {{ $kalkulation['db_eur'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format((float) $kalkulation['db_eur'], 2, ',', '.') }} €{{ $kalkulation['db_pct'] !== null ? ' · ' . number_format((float) $kalkulation['db_pct'], 1, ',', '.') . ' %' : '' }}</span>
                            </div>
                        @endif
                        <p class="text-[10px] text-gray-500 pt-0.5">Blöcke pflegst du in Einstellungen → Kalkulation.</p>
                    </div>
                @endif

                {{-- Wareneinsatz je Position — woraus sich die Kosten zusammensetzen (wie die Zutatenliste beim Gericht) --}}
                @if($concept && $cockpit)
                    <div class="rounded-xl border border-black/5 p-3">
                        <p class="{{ $label }} mb-1.5">Wareneinsatz je Position / Person</p>
                        <table class="w-full text-xs">
                            <thead><tr class="text-gray-500 text-[10px] uppercase tracking-wider">
                                <th class="text-left font-medium py-1">Position</th>
                                <th class="text-right font-medium">Wareneinsatz</th>
                                <th class="text-right font-medium">VK</th>
                                <th class="text-right font-medium">W-%</th>
                            </tr></thead>
                            <tbody>
                            @foreach($cockpit['zeilen'] as $z)
                                @php($zw = (($z['price'] ?? 0) > 0 && $z['ek'] !== null) ? $z['ek'] / $z['price'] * 100 : null)
                                <tr class="border-t border-black/5">
                                    <td class="py-1">@if($z['role'])<span class="text-gray-500">{{ $z['role'] }}:</span> @endif{{ $z['label'] }}</td>
                                    <td class="text-right tabular-nums">{{ $z['ek'] !== null ? number_format((float) $z['ek'], 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-600">{{ $z['price'] !== null ? number_format((float) $z['price'], 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums">{{ $zw !== null ? number_format($zw, 1, ',', '.') . ' %' : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-black/10 font-semibold text-gray-900">
                                    <td class="py-1">Summe / Person</td>
                                    <td class="text-right tabular-nums">{{ number_format((float) $cockpit['ek_per_person'], 2, ',', '.') }} €</td>
                                    <td class="text-right tabular-nums text-gray-600">{{ number_format((float) $cockpit['price_per_person'], 2, ',', '.') }} €</td>
                                    <td class="text-right tabular-nums">{{ $cockpit['price_per_person'] > 0 ? number_format($cockpit['ek_per_person'] / $cockpit['price_per_person'] * 100, 1, ',', '.') . ' %' : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @elseif($paket)
                    <div class="rounded-xl border border-black/5 p-3">
                        <p class="{{ $label }} mb-1.5">Wareneinsatz je Posten / Person</p>
                        <table class="w-full text-xs">
                            <thead><tr class="text-gray-500 text-[10px] uppercase tracking-wider">
                                <th class="text-left font-medium py-1">Posten</th>
                                <th class="text-right font-medium">Menge</th>
                                <th class="text-right font-medium">Wareneinsatz</th>
                                <th class="text-right font-medium">VK</th>
                            </tr></thead>
                            <tbody>
                            @forelse($paket->dishes as $pg)
                                @php($istBasis = ! ($pg->dish?->is_sales_recipe ?? true))
                                @php($faktor = $pg->quantity !== null ? (float) $pg->quantity : 1.0)
                                @php($yieldG = (float) ($pg->dish?->yield_kg ?? 0) * 1000)
                                @php($postenEk = $istBasis
                                    ? (($pg->dish?->ek_total_eur !== null && $yieldG > 0 && $pg->quantity !== null) ? (float) $pg->dish->ek_total_eur * ((float) $pg->quantity / $yieldG) : null)
                                    : ($pg->dish?->ek_total_eur !== null ? (float) $pg->dish->ek_total_eur * $faktor : null))
                                <tr class="border-t border-black/5">
                                    <td class="py-1">{{ $pg->dish?->name ?? '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-600">{{ $pg->quantity !== null ? (rtrim(rtrim(number_format($faktor, 2, ',', '.'), '0'), ',') . ($istBasis ? ' g' : '')) : ($istBasis ? '— g' : '1') }}</td>
                                    <td class="text-right tabular-nums">{{ $postenEk !== null ? number_format($postenEk, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-600">{{ $istBasis ? '—' : ($pg->dish?->sales_net !== null ? number_format((float) $pg->dish->sales_net * $faktor, 2, ',', '.') . ' €' : '—') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-2 text-center text-gray-500">Noch keine Posten.</td></tr>
                            @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-black/10 font-semibold text-gray-900">
                                    <td class="py-1">Summe / Person</td>
                                    <td></td>
                                    <td class="text-right tabular-nums">{{ $paket->ek_per_person !== null ? number_format((float) $paket->ek_per_person, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-600">{{ $aggregat !== null ? number_format((float) $aggregat['vk_summe'], 2, ',', '.') . ' €' : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif

                @if($concept && $cockpit)
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                        <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                            <span class="text-[10px] uppercase tracking-wider text-violet-600">€/Person</span>
                            <p class="text-base font-bold text-violet-700 tabular-nums">{{ number_format($cockpit['price_per_person'], 2, ',', '.') }} €</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                            <span class="{{ $dt }}">Wareneinsatz/Pers.</span>
                            <p class="text-xs font-semibold tabular-nums">{{ $kalkulation !== null ? number_format((float) $kalkulation['hk1_pro_person'], 2, ',', '.') . ' €' : '—' }}</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                            <span class="{{ $dt }}">Wareneinsatz %</span>
                            <p class="text-xs font-semibold tabular-nums">{{ ($kalkulation !== null && $cockpit['price_per_person'] > 0) ? number_format($kalkulation['hk1_pro_person'] / $cockpit['price_per_person'] * 100, 1, ',', '.') . ' %' : '—' }}</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                            <span class="{{ $dt }}">Arbeitszeit</span>
                            <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? $aggregat['work_time_min'] . ' min' : '—' }}</p>
                        </div>
                        <div>
                            <label class="{{ $label }}">Zielpreis €/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.target_price_per_person" class="{{ $input }} text-right tabular-nums" />
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="button" wire:click="zielpreisToggle" class="{{ $btnGhost }} {{ $zielModus ? 'text-violet-600' : '' }}">🎯 Zielpreis-Konfigurator</button>
                    </div>
                    @if($zielModus)
                        <div class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-3 space-y-2">
                            <div class="flex items-end gap-2 flex-wrap">
                                <div>
                                    <label class="{{ $label }}">Komm auf €/Person</label>
                                    <input type="number" step="0.01" min="0" wire:model="zielPreis" wire:keydown.enter="zielpreisBerechnen" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 36,00" />
                                </div>
                                <button type="button" wire:click="zielpreisBerechnen" class="{{ $btnPrimary }}">Vorschlag</button>
                                <span class="text-[11px] text-gray-500">Tauscht Pakete derselben Rolle; feste Gerichte = Fixkosten.</span>
                            </div>
                            @if($zielVorschlag)
                                <div class="text-xs space-y-1 pt-1 border-t border-violet-500/20">
                                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                                        <span><span class="{{ $label }}">Aktuell</span> {{ number_format($zielVorschlag['aktuell'], 2, ',', '.') }} €</span>
                                        <span><span class="{{ $label }}">Vorschlag</span> <span class="font-semibold">{{ number_format($zielVorschlag['price'], 2, ',', '.') }} €</span></span>
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
                            <select wire:model.live="form.price_mode" class="{{ $input }}">
                                <option value="manuell">manuell (Buffet)</option>
                                <option value="auto">auto (Σ Gerichte)</option>
                            </select>
                        </div>
                        <div>
                            <label class="{{ $label }}">€/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.price_per_person" @disabled($form['price_mode'] === 'auto') class="{{ $input }} text-right tabular-nums" />
                        </div>
                        <div>
                            <label class="{{ $label }}">EK/Person <span class="text-gray-500 normal-case">· aus Gerichten</span></label>
                            <input type="number" step="0.0001" min="0" wire:model="form.ek_per_person" disabled class="{{ $input }} text-right tabular-nums opacity-70" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Wareneinsatz % <span class="text-gray-500 normal-case">· abgeleitet</span></label>
                            <input type="number" step="0.1" min="0" wire:model="form.food_cost_percent" disabled class="{{ $input }} text-right tabular-nums opacity-70" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="neuBerechnen" class="{{ $btnGhost }}">↻ EK aus Gerichten neu berechnen</button>
                        <span class="text-[10px] text-gray-500">Kosten folgen den Gerichten; nur der €/Person ist im manuell-Modus (Buffet) frei.</span>
                    </div>
                @endif
            @endif

            {{-- ── Tab: NOTIZEN ──────────────────────────────────────────── --}}
            @if($tab === 'notes')
                @if($concept)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {{-- Schreibstil (Tonalität) lebt seit 2026-07-06 im Kopfbereich (Hauptseite, User-Wunsch) --}}
                        <div>
                            <label class="{{ $label }}">Diät-Vorgabe (KI-Brief)</label>
                            <input type="text" wire:model="form.diet_requirement" class="{{ $input }}" placeholder="z. B. „je Gang ≥1 vegan"" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Struktur-Vorgabe</label>
                            <input type="text" wire:model="form.structure_requirement" class="{{ $input }}" placeholder="z. B. „3-Gang" / „Buffet: Salat+HG+Dessert"" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="{{ $label }}">Saison</label>
                                <input type="text" wire:model="form.season" class="{{ $input }}" />
                            </div>
                            <div>
                                <label class="{{ $label }}">Zielgruppe/Sektor (frei)</label>
                                <input type="text" wire:model="form.target_group" class="{{ $input }}" />
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
                            <textarea wire:model="form.additional_text" rows="2" class="{{ $input }}"></textarea>
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
                            <textarea wire:model="form.description" rows="2" class="{{ $input }}"></textarea>
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
                    <p class="text-[11px] text-gray-500 mb-2">Das kreative Foodkonzept (Leitidee, Inszenierung, Geschmackswelten) — fließt als Kontext in alle KI-Texte dieses Konzepts. Stil/Geschmack erbt es aus der Team-Food-DNA.</p>
                    @include('foodalchemist::livewire.canvas.partials.board')

                    {{-- R4.1: Planungs-Gerüst — messbarer Soll-Rahmen neben dem Freitext-Canvas --}}
                    <p class="text-[11px] text-gray-500 mt-4 mb-2">Planungs-Gerüst — das messbare SOLL (Mengen, Preisrahmen, Diät-Quoten, Saison, No-Gos, Dramaturgie). Messlatte für Coverage (R4.2) und KI-Konzepte (R6).</p>
                    @include('foodalchemist::livewire.planning.partials.frame-board')
                @endif
            @endif

            {{-- ── Tab: SENSORIK (Geschmacks-Balance + Textur über die Concept-Gerichte) ── --}}
            @if($tab === 'sensorik')
                @if($concept && $sensorik)
                    @if($sensorik['leer'])
                        <p class="text-xs text-gray-500 py-6 text-center">Noch keine Gerichte mit Sensorik-Daten — erst im Aufbau Gerichte/Basisrezepte einfügen.</p>
                    @else
                        @php($dimLabel = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'])
                        <p class="text-[11px] text-gray-500 mb-2">Aggregiert über {{ $sensorik['abdeckung']['gesamt'] }} Grundprodukte ({{ $sensorik['abdeckung']['mit'] }} mit Sensorik-Daten) — MAX je Dimension = „im Teller vorhanden?". Quelle: Sensorik-Graph.</p>

                        <div class="relative overflow-hidden {{ $card }} mb-3">
                            <div class="{{ $cardAccent }}"></div>
                            <div class="px-5 py-4 space-y-2">
                                <h3 class="font-medium tracking-tight text-gray-900">Geschmacks-Balance</h3>
                                @foreach($dimLabel as $d => $l)
                                    @php($v = (float) ($sensorik['geschmack'][$d] ?? 0))
                                    @php($istDom = in_array($d, $sensorik['dominant'], true))
                                    @php($istLueck = in_array($d, $sensorik['luecken'], true))
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] w-14 shrink-0 {{ $istLueck ? 'text-gray-500' : 'text-gray-700' }}">{{ $l }}</span>
                                        <div class="flex-1 h-2 rounded-full bg-black/[0.06] overflow-hidden">
                                            <div class="h-full rounded-full {{ $istDom ? 'bg-violet-500' : ($istLueck ? 'bg-gray-300' : 'bg-violet-400/60') }}" style="width: {{ (int) round($v * 100) }}%"></div>
                                        </div>
                                        <span class="text-[11px] w-8 text-right tabular-nums text-gray-600">{{ number_format($v, 2, ',', '.') }}</span>
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
                                <h3 class="font-medium tracking-tight text-gray-900">Textur-Profil</h3>
                                @if(count($sensorik['textur']))
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
                                    </div>
                                @else
                                    <p class="text-[11px] text-gray-500">Keine Textur-Daten.</p>
                                @endif
                                @if($sensorik['monotonie'])
                                    <p class="text-[11px] text-amber-600">⚠ {{ $sensorik['monotonie'] }}</p>
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
                    @php($gerichtSlots = $concept->slots->filter(fn ($s) => $s->sales_recipe_id !== null || in_array($s->type, ['gericht', 'basisrezept'], true)))
                    <p class="text-[11px] text-gray-500 mb-2">Pro Gericht ein Haupt-Geschirr + optional eine Alternative (z. B. anderer Leih-Caterer). Pflege den Geschirr-Katalog unter <span class="font-medium">Stammdaten → Geschirr</span>.</p>
                    @forelse($gerichtSlots as $slot)
                        <div wire:key="geschirr-slot-{{ $slot->id }}" class="rounded-lg border border-black/5 px-3 py-2 mb-2">
                            <p class="text-xs font-medium text-gray-900 mb-1.5 truncate">{{ $slot->wording ?: ($slot->dish?->name ?: ($slot->title ?: 'Position')) }}</p>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach(['haupt' => 'Haupt-Geschirr', 'alt' => 'Alternative'] as $role => $rolleLabel)
                                    @php($item = $role === 'haupt' ? $slot->dishwareItem : $slot->dishwareAltItem)
                                    <div>
                                        <label class="{{ $label }} block mb-0.5">{{ $rolleLabel }}</label>
                                        @if($item)
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs text-gray-800 truncate" title="{{ $item->label }}">{{ $item->label }}</span>
                                                @if($item->rental_price !== null)<span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">{{ number_format((float) $item->rental_price, 2, ',', '.') }} €</span>@endif
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <button type="button" wire:click="geschirrPicker({{ $slot->id }}, '{{ $role }}')" class="{{ $btnGhostXs }} text-violet-600">ändern</button>
                                                <button type="button" wire:click="geschirrEntfernen({{ $slot->id }}, '{{ $role }}')" class="{{ $btnGhostXs }} text-rose-500">entfernen</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="geschirrPicker({{ $slot->id }}, '{{ $role }}')" class="{{ $btnGhostXs }} text-violet-600">+ Geschirr wählen</button>
                                            @if($role === 'haupt' && isset($geschirrVorschlag[$slot->id]))
                                                <button type="button" wire:click="geschirrWaehle({{ $slot->id }}, 'haupt', {{ $geschirrVorschlag[$slot->id]['id'] }})"
                                                        class="{{ $pill }} {{ $variantPill['primary'] }} mt-0.5" data-geschirr-vorschlag
                                                        title="Default-Geschirr der aufgelösten Darreichung ({{ $geschirrVorschlag[$slot->id]['form'] }}) — Klick übernimmt">💡 {{ $geschirrVorschlag[$slot->id]['label'] }} übernehmen</button>
                                            @endif
                                        @endif

                                        @if($geschirrPickSlotId === $slot->id && $geschirrPickRolle === $role)
                                            <div class="mt-1.5 rounded-lg border border-violet-500/30 bg-violet-500/[0.04] p-2" wire:key="geschirr-pick-{{ $slot->id }}-{{ $role }}">
                                                <input type="search" wire:model.live.debounce.300ms="geschirrSuche" placeholder="Geschirr suchen …" class="{{ $input }} !py-1 mb-1" autofocus />
                                                <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                                    @forelse($geschirrKandidaten as $kandidat)
                                                        <button type="button" wire:key="gk-{{ $slot->id }}-{{ $role }}-{{ $kandidat->id }}"
                                                                wire:click="geschirrWaehle({{ $slot->id }}, '{{ $role }}', {{ $kandidat->id }})"
                                                                class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 hover:bg-violet-500/10 transition-colors">
                                                            {{ $kandidat->label }}
                                                            <span class="text-gray-500">· {{ $kandidat->supplier?->name }}{{ $kandidat->rental_price !== null ? ' · ' . number_format((float) $kandidat->rental_price, 2, ',', '.') . ' €' : '' }}</span>
                                                        </button>
                                                    @empty
                                                        <p class="text-[11px] text-gray-500 px-2 py-1">{{ trim($geschirrSuche) === '' ? 'Tippen zum Suchen …' : 'Kein Geschirr gefunden.' }}</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500 py-6 text-center">Noch keine Gerichte im Konzept — erst im Aufbau-Tab Gerichte/Basisrezepte einfügen.</p>
                    @endforelse
                @endif
            @endif
        @endif
    </x-foodalchemist::modal>
</div>
