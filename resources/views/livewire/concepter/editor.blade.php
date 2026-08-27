{{-- M10R-3 / Doc 15 §10.4: Voll-Editor-Modal (VK-Stil) — Kopf + Tabs (Aufbau/Nährwerte/Allergene/Kalkulation/Notizen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($item = $concept ?? $paket)
{{-- Kaskade (2026-08-24): ein kind=paket-Concept öffnet im selben Editor wie ein Konzept. --}}
@php($istPaket = ($concept?->kind ?? null) === 'paket')
@php($titel = $item?->name ?? 'Editor')
{{-- $tabAktiv/$tabIdle sind mit Spec 28 / E2.2 entfallen — die Tab-Klassen leben im Baustein. --}}
@php($konfPill = ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']])
{{-- Phase 5: Typ-Farbe als Inline-Style (dynamisch aus Settings) — Text = Hex, Hintergrund = Hex+1a (10%). --}}
@php($typStyle = fn (string $t) => isset($typFarben[$t]) ? 'color:' . $typFarben[$t] . ';background-color:' . $typFarben[$t] . '1a' : '')

<div>
    {{-- Spec 28 / E1-2: Titel sagt WAS bearbeitet wird, der Name ist der Akzent-Chip.
         Concept und Paket teilen diesen Editor — der Titel benennt, welches von beiden. --}}
    <x-foodalchemist::modal name="concepter-editor" :title="($paket || $istPaket) ? 'Paket bearbeiten' : 'Konzept bearbeiten'"
        :title-name="$item?->name" fullscreen dark-canvas>
        <x-slot:actions>
            @if($paket && $rueckSprungConceptId)
                <button type="button" wire:click="zurueckZumConcept" class="{{ $btnGhost }}" title="Paket sichern und zurück ins Concept">← Speichern &amp; zurück zum Concept</button>
            @endif
            {{-- #5 (2026-08-13): EIN Speichern pro Tab — auf «Konzept & Planung» sichert der Button
                 Stammdaten + Canvas + Rahmen zusammen (konzeptSpeichern), sonst nur die Stammdaten. --}}
            <button type="button" wire:click="{{ $tab === 'konzept' ? 'konzeptSpeichern' : 'speichern' }}" class="{{ $btnPrimary }}">Speichern</button>
            {{-- #6 (F3c): Druck-Symmetrie zum Format-Editor — „Druck/Karte" (schöne Kunden-Ausgabe) +
                 „Report" (technisch). Die Karte gilt auch fürs Paket (concepts.karte nimmt eine Concept-ID). --}}
            @if($concept)
                <a href="{{ route('foodalchemist.concepts.karte', ['id' => $concept->id]) }}" target="_blank"
                   class="{{ $btnGhost }}" title="Schöne Kunden-Ausgabe (Menü-Karte · Druck/PDF)" data-concepter-karte>
                    @svg('heroicon-o-printer', 'w-3.5 h-3.5') Druck/Karte
                </a>
                <a href="{{ route('foodalchemist.concepts.dokument', array_filter(['id' => $concept->id, 'profil' => 'voll', 'simulation' => $simulationPax > 0 ? 1 : null, 'pax' => $simulationPax > 0 ? $simulationPax : null], fn ($value) => $value !== null)) }}" target="_blank"
                   class="{{ $btnGhost }}" title="Technischer Report mit voller Gericht→Basisrezept→GP→LA-Kaskade" data-concepter-druck>
                    @svg('heroicon-o-document-text', 'w-3.5 h-3.5') Report
                </a>
            @endif
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
                @php($stripEk = $concept ? (float) ($cockpit['ek_per_person'] ?? 0) : (float) ($kalkulation['hk1_pro_person'] ?? 0))
                @php($stripWpct = ($stripVk !== null && $stripVk > 0) ? $stripEk / $stripVk * 100 : null)
                @php($stripWeTone = match($wareneinsatzAmpel ?? 'unbekannt') { 'gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', default => null })
                {{-- Spec 28 / E2.2: Kacheln über den Baustein `kpi-tiles`.
                     Leitwert = VK €/Person (accent, war hier schon violett — jetzt aus derselben
                     Palette wie Rezept- und Gericht-Editor). Deckungsbeitrag trägt eine echte
                     Messgröße (negativ = Missstand) → bad/good. Wareneinsatz % folgt derselben
                     Team-Ziel-Ampel wie Gericht und Angebot; Wareneinsatz €/Person und HK2 bleiben neutral.
                     Das „~" beim Gewicht ist jetzt ein hint-Feld statt rohem HTML im Wert. --}}
                <x-foodalchemist::kpi-tiles :cols="4" marker="konzept-kpis" :tiles="array_values(array_filter([
                    ['kpi' => 'vk-person', 'label' => 'VK €/Person', 'tone' => 'accent',
                     'value' => $stripVk !== null ? number_format((float) $stripVk, 2, ',', '.') . ' €' : '—'],
                    ['kpi' => 'we-person', 'label' => 'Wareneinsatz/Pers.',
                     'value' => number_format($stripEk, 2, ',', '.') . ' €'],
                    ['kpi' => 'we-pct', 'label' => 'Wareneinsatz %',
                     'tone' => $stripWeTone,
                     'title' => $stripWpct !== null ? 'Ziel des Teams: ' . number_format((float) $zielWareneinsatzPct, 1, ',', '.') . ' %' : null,
                     'value' => $stripWpct !== null ? number_format($stripWpct, 1, ',', '.') . ' %' : '—'],
                    isset($aggregat['gewicht_pro_person_g']) ? [
                        'kpi' => 'gewicht', 'label' => 'Gewicht/P',
                        'value' => number_format((float) $aggregat['gewicht_pro_person_g'], 0, ',', '.') . ' g',
                        'hint' => ($aggregat['gewicht_vollstaendig'] ?? true) ? null : '~',
                        'hint_title' => '≥1 Position ohne Portionsgewicht — Gewicht unvollständig',
                    ] : null,
                ]))" />
            </x-slot:kpiHeader>
        @endif

        @if($item === null)
            <p class="text-sm text-gray-500 py-10 text-center">Nichts geladen.</p>
        @else
            {{-- ── Tab-Nav (sticky, schwebend — die Feldleiste darüber scrollt weg) ───
                 Spec 28 / E2.2: gleiche Leiste wie Rezept- und Gericht-Editor, aber im
                 SERVER-Modus des Bausteins: der Concepter hält den Tab in Livewire und rendert nur
                 das aktive Panel (Coverage, Kohäsion und die Picker sind zu schwer, um alle
                 gleichzeitig zu leben). Die Mechanik bleibt damit unverändert — nur das Aussehen
                 kommt jetzt aus einer Quelle.
                 'allergene'-Key bleibt stabil, Label seit 2026-07-02 „Deklaration" (Diät-Rollup +
                 Nährwerte/Person — Parität zu Rezept-/VK-Editor). --}}
            <x-foodalchemist::editor-tabs marker="konzept" action="setTab" :active="$tab" :tabs="[
                'aufbau' => 'Aufbau',
                'stammdaten' => 'Stammdaten',
                'konzept' => ($concept && ! $istPaket) ? 'Konzept & Planung' : null,
                'allergene' => 'Deklaration',
                'kalkulation' => 'Kalkulation',
                'geschirr' => ($concept || $paket) ? 'Geschirr' : null,
                'notes' => 'Notizen',
            ]" />

            {{-- ── Tab: STAMMDATEN ────────────────────────────────────
                 Spec 28 / E6: die Feldleiste klebte permanent ÜBER den Tabs und drückte
                 den Aufbau nach unten. Jetzt eigener Tab wie im Basisrezept-Editor. --}}
            @if($tab === 'stammdaten')
                {{-- #6 (2026-08-13, Dominique): Felder in Sektionen gruppiert —
                     Identität / Einordnung / Facetten & Anlass / Phase & Ton. Reine
                     Umsortierung: Bindings, wire:change-Autosave und data-Attribute 1:1. --}}
                @php($sekHead = 'text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2')

                {{-- Sektion: Identität --}}
                <h4 class="{{ $sekHead }}">Identität</h4>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Bezeichnung (intern)</label>
                        <input type="text" wire:model="form.name" class="{{ $input }}" />
                    </div>
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Konsumentenbezeichnung</label>
                        <input type="text" wire:model="form.consumer_name" class="{{ $input }}" placeholder="z. B. „Sommerliche Vorspeisen-Auswahl“" />
                    </div>
                </div>

                {{-- Sektion: Einordnung (Klasse/Niveau + Status/Geschmack bzw. Rolle) --}}
                <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-black/5">Einordnung</h4>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
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
                    @endif
                    {{-- Paket-Rolle 2026-08-24 entfernt: Paket = wiederverwendbares Bündel mit eigenem Preis, keine Gang-Rolle nötig --}}
                </div>

                @if($concept)
                    {{-- Sektion: Facetten & Anlass --}}
                    <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-black/5">Facetten &amp; Anlass</h4>
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
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
                    </div>

                    {{-- Sektion: Phase & Ton --}}
                    <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-black/5">Phase &amp; Ton</h4>
                    {{-- R4.3: Phasen-Statusmaschine (ergänzt den Sichtbarkeits-Status) --}}
                    <div>
                        @include('foodalchemist::livewire.planning.partials.phase-stepper', ['phaseAktuell' => $concept->phase ?? 'kontext'])
                    </div>
                    {{-- Schreibstil (Tonalität) — 2026-07-06 aus Tab „Notizen“ hierher (immer sichtbar):
                         Stil fürs ganze Konzept + ✨ erzeugt je Position Brand-Voice-Wording (WordingResolver-Kette). --}}
                    <div class="flex items-center gap-1.5 mt-2" data-konzept-schreibstil>
                        <span class="{{ $label }} !mb-0 mr-1">Schreibstil</span>
                        <select wire:model="form.writing_style_id" wire:change="speichern" class="{{ $input }} !w-auto !py-0.5 !text-[11px]" title="Tonalität fürs Wording — wird sofort gespeichert; ✨ Wording erzeugt daraus die Texte (kein Auto-Erzeugen, LLM-Kosten)">
                            <option value="">— neutral —</option>
                            @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="wordingGenerieren" class="{{ $btnAi }} shrink-0" title="Wording übers ganze Konzept erzeugen: pro Position einen Brand-Voice-Namen + Konzept-Einleitung (echter Text mit LLM-Key)" data-ki-concept-wording>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Wording</button>
                    </div>
                @endif
            @endif



            {{-- ── Tab: AUFBAU (nur die Positionen) ───────────────────────────
                 Spec 28 / E6: Planungs-Gerüst-Coverage → Tab «Konzept & Planung» (direkt neben
                 dem SOLL-Rahmen, den es misst). (Menü-Kohäsion/Sensorik-Tab 2026-08-13 aus dem
                 Concepter entfernt.) Vorher standen beide Panels über der Positions-Tabelle und
                 drückten den eigentlichen Bau nach unten. --}}
            @if($tab === 'aufbau')
                {{-- Live-Kosten-Streifen ist jetzt fix im Modal-Kopf (Phase 1, x-slot:kpiHeader). --}}
                @if($concept)
                {{-- x-data hält den Drag-Zustand: dragTyp/dragId = Liste→einfügen, dragSlotId = Position umsortieren.
                     bauModus schaltet zwischen Bearbeiten (Tabelle + Einfüge-Listen) und Menü-Ansicht (Gäste-Sicht,
                     UX-Umbau 2026-07-03) — Alpine statt Livewire: kein Re-Mount, ungespeicherte Eingaben bleiben.
                     Default = Menü (Gäste-Sicht): Konzept öffnet in der Präsentations-Ansicht, Bearbeiten wird
                     bewusst per Toggle gewählt (Dominique 2026-08-13). --}}
                <div class="flex gap-3 items-start w-full" x-data="{ dragTyp: null, dragId: null, dragSlotId: null, bauModus: false }">
                {{-- Phase 3: linke Spalte — Basisrezepte als Position einfügen (sticky Panel wie zutaten-kern) --}}
                {{-- EIN Quellen-Picker (2026-08-24): die frühere linke [Basisrezept/Paket] + rechte [VK-Gerichte]
                     Seitenleiste sind hier zu einem breiteren Tab-Picker zusammengelegt — Gerichte / Pakete /
                     Basisrezepte. Breiter (w-80), damit die langen Gericht-Namen lesbar bleiben. --}}
                <aside x-show="bauModus" x-cloak class="w-80 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-0 self-start max-h-[70vh]" data-konzept-quelle-picker data-konzept-basisliste>
                    {{-- Umschalter: Gerichte ⇄ Pakete ⇄ Basisrezepte --}}
                    <div class="flex gap-1 mb-1.5" data-linke-liste-umschalter>
                        <button type="button" wire:click="$set('linkeListe', 'gericht')" class="{{ $pill }} {{ $linkeListe === 'gericht' ? $variantPill['primary'] : $variantPill['secondary'] }}">Gerichte</button>
                        @unless($istPaket)<button type="button" wire:click="$set('linkeListe', 'paket')" class="{{ $pill }} {{ $linkeListe === 'paket' ? $variantPill['primary'] : $variantPill['secondary'] }}">Pakete</button>@endunless{{-- kein Paket-in-Paket --}}
                        <button type="button" wire:click="$set('linkeListe', 'basisrezept')" class="{{ $pill }} {{ $linkeListe === 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Basisrezepte</button>
                    </div>
                    @if($linkeListe === 'gericht')
                        {{-- VK-Gerichte (2026-08-24 aus der früheren rechten Spalte hierher) --}}
                        <p class="{{ $dt }} mb-1">VK-Gerichte ({{ $gerichtListe->count() }})</p>
                        @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'gerichtSuche'])
                        <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1 mt-1.5" data-konzept-gerichtliste>
                            @forelse($gerichtListe as $gr)
                                <div wire:key="kgr-{{ $gr->id }}" draggable="true" @dragstart="dragTyp = 'gericht'; dragId = {{ $gr->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px] cursor-grab active:cursor-grabbing">
                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gericht') }}">G</span>
                                    <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" title="{{ $gr->name }}">{{ $gr->name }}</span>
                                    <span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ $gr->sales_net !== null ? number_format((float) $gr->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                                    <button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $gr->id }} })" class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Gericht einsehen">@svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                    <button type="button" wire:click="positionEinfuegen('gericht', {{ $gr->id }})" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="als Position einfügen">+</button>
                                </div>
                            @empty
                                <p class="text-[10px] text-gray-500 px-1">— keine Treffer —</p>
                            @endforelse
                        </div>
                    @elseif($linkeListe === 'paket')
                        <p class="{{ $dt }} mb-1">Pakete ({{ $paketListe->count() }})</p>
                        <input type="search" wire:model.live.debounce.300ms="basisSuche" placeholder="Paket suchen (Name) …" class="{{ $input }} !py-0.5 !text-[11px] mb-1" />
                        <select wire:model.live="paketKlasse" class="{{ $input }} !py-0.5 !text-[11px] mb-1" data-paket-filter-klasse>
                            <option value="">Alle Klassen</option>
                            @foreach($paketKlassenListe as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                        </select>
                        {{-- F7b: Facetten-Filter als Dropdowns (identisch zum Format-Picker) --}}
                        <div class="grid grid-cols-2 gap-1 mb-1.5">
                            <select wire:model.live="paketServierform" class="{{ $input }} !py-0.5 !text-[11px]" data-paket-filter-servierform>
                                <option value="">Servierform</option>
                                @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                            </select>
                            <select wire:model.live="paketEventtyp" class="{{ $input }} !py-0.5 !text-[11px]" data-paket-filter-eventtyp>
                                <option value="">Eventtyp</option>
                                @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="paketMoment" class="{{ $input }} !py-0.5 !text-[11px]" data-paket-filter-moment>
                                <option value="">Moment</option>
                                @foreach($einsatzmomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="paketSaison" class="{{ $input }} !py-0.5 !text-[11px]" data-paket-filter-saison>
                                <option value="">Saison</option>
                                @foreach($saisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1" data-konzept-paketliste>
                            @forelse($paketListe as $pk)
                                <div wire:key="kpk-{{ $pk->id }}" draggable="true" @dragstart="dragTyp = 'paket'; dragId = {{ $pk->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px] cursor-grab active:cursor-grabbing">
                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider {{ $variantPill['info'] }}">PK</span>
                                    <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" title="{{ $pk->name }}">{{ $pk->name }}</span>
                                    <span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ $pk->price_per_person_cache !== null ? number_format((float) $pk->price_per_person_cache, 2, ',', '.') . ' €' : '' }}</span>
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
                                    <button type="button" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $br->id }} })" class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Rezept einsehen">@svg('heroicon-o-book-open', 'w-3.5 h-3.5 inline-block align-middle')</button>
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
                                    @svg('heroicon-o-map-pin', 'w-3.5 h-3.5 inline-block align-middle') Einfügen unter markierter Zeile
                                    <button type="button" wire:click="$set('einfuegenNachId', null)" class="underline decoration-dotted hover:text-violet-800">ans Ende</button>
                                </span>
                            @endif
                            {{-- UX-Umbau 2026-07-03: Toggle Bearbeiten ⇄ Menü (Gäste-Perspektive mit aufgelöstem Wording) --}}
                            <div class="inline-flex rounded-lg bg-black/[0.05] p-0.5" role="group" aria-label="Ansicht" data-konzept-ansicht-toggle>
                                <button type="button" @click="bauModus = true" :class="bauModus ? 'bg-white text-violet-600 shadow-sm' : 'text-gray-600 hover:text-gray-700'" class="px-3 py-1 text-[11px] font-medium rounded-md transition-all" data-ansicht-bearbeiten>@svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5 inline-block align-middle') Bearbeiten</button>
                                <button type="button" @click="bauModus = false" :class="!bauModus ? 'bg-white text-violet-600 shadow-sm' : 'text-gray-600 hover:text-gray-700'" class="px-3 py-1 text-[11px] font-medium rounded-md transition-all" data-ansicht-menue>@svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle') Menü</button>
                            </div>
                        </div>
                    </div>

                    {{-- ═══ MENÜ-ANSICHT (Gäste-Perspektive, read-only) — UX-Umbau 2026-07-03 ═══ --}}
                    <div x-show="!bauModus" x-cloak class="space-y-3" data-konzept-menue>
                        @php($menueGruppen = [])
                        @php($aktuelleGruppe = ['type' => 'sektion', 'title' => null, 'headerSlotId' => null, 'slots' => [], 'texte' => []])
                        @foreach($concept->slots as $s)
                            @if(in_array($s->type, ['header', 'header_preis'], true))
                                @php($menueGruppen[] = $aktuelleGruppe)
                                @php($aktuelleGruppe = ['type' => 'header', 'title' => $s->title ?: '(Überschrift)', 'headerSlotId' => $s->id, 'slots' => [], 'texte' => []])
                            @elseif(($s->embedded_concept_id && $s->embeddedConcept) || ($s->package_id && $s->package))
                                @php($menueGruppen[] = $aktuelleGruppe)
                                @php($_ref = $s->embeddedConcept ?? $s->package)
                                @php($_dishes = $s->embeddedConcept ? $s->embeddedConcept->slots->filter(fn ($e) => $e->sales_recipe_id !== null)->values() : $s->package->dishes)
                                @php($_preis = $s->embeddedConcept ? $s->embeddedConcept->price_per_person_cache : $s->package->price_per_person)
                                @php($menueGruppen[] = ['type' => 'paket', 'title' => $_ref->name, 'price' => $_preis, 'headerSlotId' => null, 'slots' => [], 'texte' => [], 'paket' => $_ref, 'dishes' => $_dishes])
                                @php($aktuelleGruppe = ['type' => 'sektion', 'title' => null, 'headerSlotId' => null, 'slots' => [], 'texte' => []])
                            @elseif($s->sales_recipe_id && $s->dish)
                                @php($aktuelleGruppe['slots'][] = $s)
                            @elseif($s->type === 'text' && trim((string) $s->text_content) !== '')
                                {{-- Freitext-Block erscheint als Sektions-Beschreibung in der Gäste-Sicht (Bug-Fix 2026-08-24) --}}
                                @php($aktuelleGruppe['texte'][] = $s->text_content)
                            @endif
                        @endforeach
                        @php($menueGruppen[] = $aktuelleGruppe)
                        {{-- Gäste-Sicht: leere Gruppen unsichtbar — aber reine Text-Sektionen (Beschreibung ohne Gericht) bleiben sichtbar --}}
                        @php($menueGruppen = collect($menueGruppen)->filter(fn ($g) => $g['type'] === 'paket' ? ($g['dishes'] ?? collect())->isNotEmpty() : (count($g['slots']) > 0 || count($g['texte'] ?? []) > 0))->values())

                        @php($quelleBadge = ['konzept' => ['Konzept-Wording', 'text-violet-600', 'bg-violet-500'], 'standard' => ['VK-Wording (Standard)', 'text-gray-500', 'bg-gray-400'], 'name' => ['Wording fehlt — interner Name', 'text-amber-600', 'bg-amber-500']])
                        @php($wres = app(\Platform\FoodAlchemist\Services\WordingResolver::class))

                        @forelse($menueGruppen as $g)
                            {{-- Gruppen-Container: subtile Klammer um Kopf + Karten (gleiche Fläche wie die Seiten-Listen) --}}
                            <section wire:key="menue-{{ $loop->index }}" class="rounded-xl bg-gray-500/[0.05] border border-black/5 p-3">
                                @php($slotEks = collect($g['slots'])->map(fn ($sx) => $cockpitZeilen[$sx->id]['ek'] ?? null)->filter())
                                @php($slotVks = collect($g['slots'])->map(fn ($sx) => $cockpitZeilen[$sx->id]['price'] ?? null)->filter())
                                <div class="flex items-baseline gap-2 pb-2.5">
                                    @if($g['type'] === 'paket')
                                        <span class="{{ $pill }} {{ $variantPill['primary'] }} shrink-0">@svg('heroicon-o-archive-box', 'w-3.5 h-3.5 inline-block align-middle') Paket</span>
                                        <h4 class="text-sm font-semibold text-gray-900">{{ $g['title'] }}</h4>
                                        <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">{{ $g['dishes']->count() }} {{ $g['dishes']->count() === 1 ? 'Posten' : 'Posten' }}{{ $g['price'] !== null ? ' · ' . number_format((float) $g['price'], 2, ',', '.') . ' €/P' : '' }}</span>
                                    @elseif($g['title'])
                                        <span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">Sektion</span>
                                        <h4 class="text-sm font-semibold text-gray-900">{{ $g['title'] }}</h4>
                                        <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">{{ count($g['slots']) }} {{ count($g['slots']) === 1 ? 'Position' : 'Positionen' }}{{ (! $istPaket && $slotVks->isNotEmpty()) ? ' · ' . number_format($slotVks->sum(), 2, ',', '.') . ' €/P' : '' }}{{ $slotEks->isNotEmpty() ? ' · Σ EK ' . number_format($slotEks->sum(), 2, ',', '.') . ' €' : '' }}</span>
                                    @else
                                        <h4 class="text-[11px] font-medium uppercase tracking-wider text-gray-500">Gerichte</h4>
                                        <span class="ml-auto text-[11px] text-gray-500 tabular-nums shrink-0">{{ count($g['slots']) }} {{ count($g['slots']) === 1 ? 'Position' : 'Positionen' }}{{ (! $istPaket && $slotVks->isNotEmpty()) ? ' · ' . number_format($slotVks->sum(), 2, ',', '.') . ' €/P' : '' }}</span>
                                    @endif
                                </div>
                                @if(! empty($g['texte'] ?? []))
                                    {{-- Freitext-Blöcke der Sektion als Beschreibung (Gäste-Sicht) --}}
                                    <div class="space-y-1 pb-2.5 -mt-1" data-konzept-menue-text>
                                        @foreach($g['texte'] as $tx)
                                            <p class="text-[13px] text-gray-600 italic leading-snug">{{ $tx }}</p>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="grid gap-2.5" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
                                    @if($g['type'] === 'paket')
                                        @foreach($g['dishes'] as $pg)
                                            @php($pgG = $pg->dish)
                                            @php($pw = $wres->fuerGericht($pgG))
                                            @php($qb = $quelleBadge[$pw['source']] ?? $quelleBadge['name'])
                                            @php($pgEnthaelt = collect(['Schwein' => $pgG?->spec_contains_pork, 'Rind' => $pgG?->spec_contains_beef])->filter()->keys()->all())
                                            <article wire:key="mpcard-{{ $pg->id }}" class="group relative rounded-xl bg-white/60 backdrop-blur-xl border border-white/20 shadow-sm shadow-black/5 px-3.5 py-3 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                                                <div class="flex items-start justify-between gap-2">
                                                    <span class="inline-flex items-center gap-1.5 text-[9.5px] font-semibold uppercase tracking-wider {{ $qb[1] }}"><span class="w-1.5 h-1.5 rounded-full {{ $qb[2] }}"></span>{{ $qb[0] }}</span>
                                                    @if($pg->sales_recipe_id)<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" class="text-gray-300 hover:text-violet-500 opacity-0 group-hover:opacity-100 transition-opacity" title="Gericht öffnen">@svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')️</button>@endif
                                                </div>
                                                <p class="text-sm font-semibold text-gray-900 leading-snug mt-1 {{ $pw['source'] === 'name' ? 'italic text-amber-700 font-medium' : '' }}">{{ $pw['text'] }}</p>
                                                <p class="text-[10.5px] text-gray-500 font-mono truncate mt-0.5" title="{{ $pgG?->name }}">{{ $pgG?->name }}</p>
                                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                                    @if($pgG?->spec_is_vegan)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>@elseif($pgG?->spec_is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg.</span>@endif
                                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-medium {{ count($pgEnthaelt) ? 'bg-amber-500/20 text-amber-700' : 'bg-black/5 text-gray-500' }}" title="Allergene / Diät{{ count($pgEnthaelt) ? ' — enthält ' . implode(', ', $pgEnthaelt) : '' }} · Konfidenz {{ $pgG?->allergens_confidence ?? 'unbekannt' }}">A</span>
                                                    @if($pw['source'] === 'name')<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" class="{{ $pill }} {{ $variantPill['warning'] }}" title="VK-Wording am Gericht ergänzen">@svg('heroicon-o-pencil', 'w-3.5 h-3.5 inline-block align-middle') Wording ergänzen</button>@endif
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
                                                    <button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $s->sales_recipe_id }} })" class="text-gray-300 hover:text-violet-500 opacity-0 group-hover:opacity-100 transition-opacity" title="Gericht öffnen">@svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')️</button>
                                                </div>
                                                <p class="text-sm font-semibold text-gray-900 leading-snug mt-1 {{ $w['source'] === 'name' ? 'italic text-amber-700 font-medium' : '' }}">{{ $w['text'] }}</p>
                                                <p class="text-[10.5px] text-gray-500 font-mono truncate mt-0.5" title="{{ $g0->name }}">{{ $g0->name }}</p>
                                                <div class="flex flex-wrap gap-1.5 mt-2">
                                                    @if(isset($darreichungInfo[$s->id]))
                                                        <span class="{{ $pill }} {{ str_starts_with($darreichungInfo[$s->id], 'Standard:') ? $variantPill['secondary'] : $variantPill['primary'] }}">@svg('heroicon-o-rectangle-stack', 'w-3.5 h-3.5 inline-block align-middle') {{ $darreichungInfo[$s->id] }}</span>
                                                    @endif
                                                    @if($g0->dishClass)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $g0->dishClass->label }}</span>@endif
                                                    @if($g0->spec_is_vegan)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>@elseif($g0->spec_is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg.</span>@endif
                                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-medium {{ count($enthaelt) ? 'bg-amber-500/20 text-amber-700' : 'bg-black/5 text-gray-500' }}" title="Allergene / Diät{{ count($enthaelt) ? ' — enthält ' . implode(', ', $enthaelt) : '' }} · Konfidenz {{ $g0->allergens_confidence ?? 'unbekannt' }}">A</span>
                                                    @if(isset($varianteFehlt[$s->id]))<button type="button" wire:click="varianteAnlegen({{ $s->id }})" class="{{ $pill }} {{ $variantPill['warning'] }}" title="Konzept-Servierform fehlt als Darreichung — anlegen">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') Form fehlt</button>@endif
                                                    @if($w['source'] === 'name')<button type="button" @click="bauModus = true" class="{{ $pill }} {{ $variantPill['warning'] }}" title="In der Bearbeiten-Ansicht Wording ergänzen">@svg('heroicon-o-pencil', 'w-3.5 h-3.5 inline-block align-middle') Wording ergänzen</button>@endif
                                                </div>
                                                <div class="flex gap-3 mt-2.5 pt-2 border-t border-black/5 tabular-nums">
                                                    {{-- Im Paket ist der Einzel-VK je Speise irreführend (ein Paketpreis) → nur EK zeigen. --}}
                                                    @unless($istPaket)<span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">VK/P</span><span class="text-xs font-semibold text-emerald-600">{{ $vkz !== null ? number_format((float) $vkz, 2, ',', '.') . ' €' : '—' }}</span></span>@endunless
                                                    <span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">EK</span><span class="text-xs font-semibold text-gray-700">{{ $ekz !== null ? number_format((float) $ekz, 2, ',', '.') . ' €' : '—' }}</span></span>
                                                    @unless($istPaket)<span class="flex flex-col"><span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500">W%</span><span class="text-xs font-semibold {{ $wpct !== null && $wpct > 35 ? 'text-rose-500' : 'text-gray-700' }}">{{ $wpct !== null ? number_format($wpct, 1, ',', '.') . '%' : '—' }}</span></span>@endunless
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
                        @unless($istPaket)<button type="button" wire:click="neuesPaketAlsPosition" class="{{ $btnGhostXs }} !text-violet-600 !border-violet-500/30" title="Neues Paket als Abschnitt anlegen, einfügen und direkt öffnen">+ Paket erstellen</button>@endunless{{-- kein Paket-in-Paket --}}
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
                                class="{{ $tr }} {{ $istStruktur ? 'bg-violet-500/[0.03]' : '' }} {{ ($slot->package_id || $slot->embedded_concept_id) ? 'bg-violet-500/[0.06] border-t-2 !border-t-violet-500/30' : '' }} {{ $einfuegenNachId === $slot->id ? 'border-b-2 !border-b-violet-400' : '' }}">
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
                                            <select wire:model="blockForm.{{ $slot->id }}.height" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-32 ml-1">
                                                @foreach(['klein', 'mittel', 'gross'] as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                                            </select>
                                        @elseif($slot->type === 'text')
                                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">Text</span>
                                            <input type="text" wire:model.blur="blockForm.{{ $slot->id }}.text_content" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-full mt-1" placeholder="Freitext …" />
                                        @else
                                            <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $slot->type === 'header_preis' ? 'Header + Preis' : 'Header' }}</span>
                                            <input type="text" wire:model.blur="blockForm.{{ $slot->id }}.title" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-full mt-1 font-medium" placeholder="Überschrift …" />
                                            @php($ss = $sektionSumme['h' . $slot->id] ?? null)
                                            @if($ss && $ss['n'] > 0)
                                                <div class="mt-1 text-[11px] text-violet-600 tabular-nums">{{ $ss['n'] }} {{ $ss['n'] === 1 ? 'Position' : 'Positionen' }} · Σ EK {{ number_format($ss['ek'], 2, ',', '.') }} €{{ $istPaket ? '' : ' · ' . number_format($ss['vk'], 2, ',', '.') . ' €/P' }}</div>
                                            @endif
                                            @if($slot->type === 'header_preis')
                                                <span class="inline-flex items-center gap-1 mt-1">
                                                    <input type="number" step="0.01" min="0" wire:model.blur="blockForm.{{ $slot->id }}.price_value" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-24 text-right tabular-nums" placeholder="€" />
                                                    <select wire:model="blockForm.{{ $slot->id }}.price_basis" wire:change="blockSpeichern({{ $slot->id }})" class="{{ $input }} w-28">
                                                        @foreach(['person' => '/Person', 'pauschal' => 'pauschal', 'staffel' => 'Staffel'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                                    </select>
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                @else
                                    <td class="{{ $td }} !px-2 align-top">
                                        @if($slot->sales_recipe_id)
                                            <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.quantity" wire:change="mengeSpeichern({{ $slot->id }})" class="{{ $input }} !w-14 text-right tabular-nums" placeholder="1" />
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
                                        @php($epk = $slot->embeddedConcept ?? $slot->package)
                                        @php($epkOpenId = $slot->embedded_concept_id ?? $slot->package_id)
                                        @php($epkPreis = $slot->embeddedConcept ? $slot->embeddedConcept->price_per_person_cache : ($slot->package?->price_per_person))
                                        @if($epk)
                                            {{-- Paket = Abschnitts-Header (kind=paket-Concept oder Alt-Package; Gerichte eingerückt darunter) --}}
                                            <span class="{{ $pill }} {{ $variantPill['info'] }}">@svg('heroicon-o-archive-box', 'w-3.5 h-3.5 inline-block align-middle') Paket</span>
                                            <span class="text-sm font-semibold text-gray-900 break-words">{{ $epk->name }}</span>
                                            <button type="button" wire:click="paketOeffnen({{ $epkOpenId }})" class="text-gray-500 hover:text-violet-500 align-middle" title="Paket öffnen / bearbeiten">@svg('heroicon-o-archive-box', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                            @if($epk->class ?? null)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $epk->class }}</span>@endif
                                            <span class="text-gray-500 text-[11px] tabular-nums">{{ $epkPreis !== null ? number_format((float) $epkPreis, 2, ',', '.') . ' €/P' : '' }}</span>
                                        @elseif($slot->sales_recipe_id && $slot->dish)
                                            @php($g = $slot->dish)
                                            @php($enthaelt = collect(['Schwein' => $g->spec_contains_pork, 'Rind' => $g->spec_contains_beef])->filter()->keys()->all())
                                            @php($allTitle = 'Allergene / Diät' . (count($enthaelt) ? ' — enthält ' . implode(', ', $enthaelt) : '') . ' · Konfidenz ' . ($g->allergens_confidence ?? 'unbekannt'))
                                            <span class="{{ $pill }} font-medium" style="{{ $typStyle($slot->type === 'basisrezept' ? 'basisrezept' : 'gericht') }}">{{ $slot->type === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</span>
                                            <span class="text-sm font-medium break-words">{{ $g->name }}</span>
                                            @if($g->dishClass)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $g->dishClass->label }}</span>@endif
                                            @if(isset($darreichungInfo[$slot->id]))
                                                <span class="{{ $pill }} {{ str_starts_with($darreichungInfo[$slot->id], 'Standard:') ? $variantPill['secondary'] : $variantPill['primary'] }}"
                                                      title="Aufgelöste Darreichung dieser Position (explizit → Konzept-Servierform → Standard)" data-darreichung-pill>@svg('heroicon-o-rectangle-stack', 'w-3.5 h-3.5 inline-block align-middle') {{ $darreichungInfo[$slot->id] }}</span>
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
                                            <button type="button" @click="Livewire.dispatch('{{ $slot->type === 'basisrezept' ? 'recipe-modal' : 'vk-modal' }}.oeffnen', { id: {{ $slot->sales_recipe_id }} })" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="{{ $slot->type === 'basisrezept' ? 'Rezept' : 'Gericht' }} einsehen">@if($slot->type === 'basisrezept')@svg('heroicon-o-book-open', 'w-3.5 h-3.5 inline-block align-middle')@else @svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')@endif</button>
                                            {{-- R4.4: Zutaten-Baum (read-first) + konzept-lokale Slot-Variante --}}
                                            <button type="button" wire:click="zutatenToggle({{ $slot->id }})" class="text-[11px] ml-1 align-middle {{ $zutatenOffenSlotId === $slot->id ? 'text-violet-600' : 'text-gray-300 hover:text-violet-500' }}" title="Zutaten-Zeilen zeigen (Tausch erzeugt eine konzept-lokale Variante — Quell-Gericht bleibt unangetastet)" data-slot-zutaten-toggle>@svg('heroicon-o-list-bullet', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                            @if($slot->variant_source_recipe_id !== null)
                                                <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="Konzept-lokale Variante — das Quell-Gericht ist unverändert" data-slot-variiert>variiert</span>
                                                <button type="button" wire:click="slotVarianteZuruecksetzen({{ $slot->id }})" wire:confirm="Variante verwerfen und Original-Gericht wiederherstellen?" class="text-[10px] text-gray-500 hover:text-rose-500 align-middle" data-slot-variante-reset>↩ Original</button>
                                            @endif
                                            {{-- Umbau-Spec Phase 5: Konzept-Servierform ohne passende Darreichung → 1-Klick-Anlage --}}
                                            @if(isset($varianteFehlt[$slot->id]))
                                                <button type="button" wire:click="varianteAnlegen({{ $slot->id }})"
                                                        class="{{ $pill }} {{ $variantPill['warning'] }}" data-variante-fehlt
                                                        title="Gericht hat keine Darreichung für „{{ $concept->servingForm?->label }}" — Klick legt sie an (vorbefüllt aus der Standard-Form, danach Grammatur prüfen)">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') {{ $concept->servingForm?->label }} fehlt — anlegen</button>
                                            @endif
                                            {{-- Concept-Wording: Brand-Voice-Anzeigename je Position (leer = Standardname; ✨ oben füllt alle) --}}
                                            <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.wording" wire:change="wordingSpeichern({{ $slot->id }})" class="{{ $input }} !py-0.5 !text-[11px] italic mt-1 w-full" placeholder="Anzeigename im Konzept-Wording … (leer = „{{ $g->name }}“)" data-slot-wording />
                                        @else
                                            <span class="text-xs text-gray-500">leer — links aus dem Quellen-Picker einfügen</span>
                                            @if($slot->note)
                                                {{-- R6.1: Generator-Begründung, warum der Slot bewusst leer blieb --}}
                                                <div class="text-[11px] text-amber-600 mt-0.5" data-slot-leer-begruendung>@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') {{ $slot->note }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="{{ $td }} !px-2 align-top"><input type="text" wire:model.blur="slotForm.{{ $slot->id }}.role" wire:change="slotSpeichern({{ $slot->id }})" class="{{ $input }} !w-20" placeholder="Rolle" /></td>
                                    {{-- Im Paket kein Einzel-VK/W% je Position (ein Paketpreis); EK bleibt als Kostenbasis. --}}
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top">{{ $istPaket ? '' : ($vkz !== null ? number_format((float) $vkz, 2, ',', '.') . ' €' : '—') }}</td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top">{{ $ekz !== null ? number_format((float) $ekz, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap align-top text-gray-500">{{ $istPaket ? '' : ($wpct !== null ? number_format($wpct, 1, ',', '.') . '%' : '—') }}</td>
                                @endif
                                <td class="{{ $td }} !px-2 text-right whitespace-nowrap align-top">
                                    @if(! $istStruktur)
                                        <label class="inline-flex items-center gap-0.5 text-[10px] text-gray-500 mr-1" title="Pflicht-Position">
                                            <input type="checkbox" wire:model="slotForm.{{ $slot->id }}.is_pflicht" wire:change="slotSpeichern({{ $slot->id }})" class="rounded border-gray-300 !w-3 !h-3" />P
                                        </label>
                                        <button type="button" wire:click="fillToggle({{ $slot->id }})" class="text-gray-500 hover:text-violet-500 text-[11px]" title="Befüllung ändern">@svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                    @endif
                                    <button type="button" wire:click="zielSetzen({{ $slot->id }})" class="text-[11px] ml-1 align-middle {{ $einfuegenNachId === $slot->id ? 'text-violet-600' : 'text-gray-300 hover:text-violet-500' }}" title="{{ $einfuegenNachId === $slot->id ? 'Einfügeziel aktiv — nächste Position landet hier darunter (Klick = abwählen)' : 'Hier einfügen — die nächste neue Position landet unter dieser Zeile' }}">@svg('heroicon-o-map-pin', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                    <button type="button" wire:click="slotRaus({{ $slot->id }})" class="text-gray-500 hover:text-red-500 ml-1" title="entfernen">✕</button>
                                </td>
                            </tr>
                            {{-- Paket-Position = Abschnitt: seine Gerichte stehen immer read-only als eingerückte Zeilen darunter --}}
                            @if($slot->embeddedConcept || ($slot->package_id && $slot->package))
                                <tr wire:key="epaket-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 align-top">
                                        <div class="ml-2 rounded-lg border border-gray-900/15 bg-black/[0.02] divide-y divide-black/5">
                                            @if($slot->embeddedConcept)
                                                {{-- Kaskade: eingebettetes Paket = kind=paket-Concept → seine Posten-Slots --}}
                                                @forelse($slot->embeddedConcept->slots->filter(fn ($e) => $e->sales_recipe_id !== null) as $eps)
                                                    <div wire:key="epaketc-{{ $slot->id }}-{{ $eps->id }}" class="flex items-center gap-2 px-3 py-1 text-[11px]">
                                                        <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle($eps->type === 'basisrezept' ? 'basisrezept' : 'gericht') }}">{{ $eps->type === 'basisrezept' ? 'BR' : 'G' }}</span>
                                                        <span class="flex-1 min-w-0 break-words leading-snug text-gray-700">{{ $eps->dish?->name ?? '—' }}</span>
                                                        <span class="shrink-0 text-gray-500 tabular-nums">{{ $eps->quantity !== null ? rtrim(rtrim(number_format((float) $eps->quantity, 2, ',', '.'), '0'), ',') . '×' : '' }}</span>
                                                        <span class="shrink-0 text-gray-500 tabular-nums w-16 text-right">{{ $eps->dish?->sales_net !== null ? number_format((float) $eps->dish->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                                                        @if($eps->sales_recipe_id)<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $eps->sales_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="Gericht einsehen">@svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')️</button>@endif
                                                    </div>
                                                @empty
                                                    <p class="px-3 py-1.5 text-[11px] text-gray-500">Paket ohne Posten — im Paket-Editor pflegen.</p>
                                                @endforelse
                                            @else
                                            @forelse($slot->package->dishes as $pg)
                                                <div wire:key="epaketg-{{ $slot->id }}-{{ $pg->id }}" class="flex items-center gap-2 px-3 py-1 text-[11px]">
                                                    <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gericht') }}">G</span>
                                                    <span class="flex-1 min-w-0 break-words leading-snug text-gray-700">{{ $pg->dish?->name ?? '—' }}</span>
                                                    <span class="shrink-0 text-gray-500 tabular-nums">{{ $pg->quantity !== null ? rtrim(rtrim(number_format((float) $pg->quantity, 2, ',', '.'), '0'), ',') . '×' : '' }}</span>
                                                    <span class="shrink-0 text-gray-500 tabular-nums w-16 text-right">{{ $pg->dish?->sales_net !== null ? number_format((float) $pg->dish->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                                                    @if($pg->sales_recipe_id)<button type="button" @click="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="Gericht einsehen">@svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')️</button>@endif
                                                </div>
                                            @empty
                                                <p class="px-3 py-1.5 text-[11px] text-gray-500">Paket ohne Gerichte — im Paket-Editor pflegen.</p>
                                            @endforelse
                                            @endif
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
                                                        <span class="shrink-0" title="swap-gesperrt — bewusst gewählte Realisierung">@svg('heroicon-o-lock-closed', 'w-3.5 h-3.5 inline-block align-middle')</span>
                                                    @elseif($z['ersatz'] !== null)
                                                        <button type="button" wire:click="slotZutatTauschen({{ $slot->id }}, {{ $z['id'] }})"
                                                                class="{{ $btnAi }} shrink-0"
                                                                title="Konzept-lokal tauschen (erzeugt/nutzt die Slot-Variante — Quell-Gericht bleibt unangetastet)" data-slot-zutat-tausch>
                                                            ♻ {{ $z['ersatz'] }}
                                                        </button>
                                                    @endif
                                                    @if($z['peek_recipe_id'] !== null)
                                                        <button type="button" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $z['peek_recipe_id'] }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="Sub-Rezept einsehen">@svg('heroicon-o-book-open', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                                    @endif
                                                </div>
                                            @empty
                                                <p class="px-3 py-1.5 text-[11px] text-gray-500">Keine Zutaten-Zeilen an diesem Gericht.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                            @if(! $istStruktur && ($fillOpenId === $slot->id || (! $slot->package_id && ! $slot->embedded_concept_id && ! $slot->sales_recipe_id)))
                                <tr wire:key="efill-{{ $slot->id }}">
                                    <td></td>
                                    <td colspan="8" class="!px-2 !pb-2 bg-black/[0.02]">
                                        <div class="flex flex-wrap items-center gap-2 pt-1">
                                            <select x-on:change="$wire.fuellePaket({{ $slot->id }}, $event.target.value); $event.target.value=''" class="{{ $input }} w-56">
                                                <option value="">↹ Paket tauschen …</option>
                                                @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                                    <option value="{{ $b->id }}">{{ $b->name }}{{ $b->price_per_person_cache !== null ? ' (' . number_format((float) $b->price_per_person_cache, 2, ',', '.') . ' €)' : '' }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="gerichtPicker({{ $slot->id }})" class="{{ $btnGhostXs }}">Gericht / Basisrezept …</button>
                                            <button type="button" wire:click="neuesPaketImSlot({{ $slot->id }})" class="{{ $btnAi }}" title="Inline ein neues Paket schnüren">+ neues Paket</button>
                                            @if($slot->package_id || $slot->embedded_concept_id || $slot->sales_recipe_id)
                                                <button type="button" wire:click="slotLeeren({{ $slot->id }})" class="text-[11px] text-gray-500 hover:text-red-500">leeren</button>
                                            @else
                                                {{-- L4: deterministischer Vorschlag aus dem Bestand (kein LLM) --}}
                                                <button type="button" wire:click="vorschlagFuerSlot({{ $slot->id }})" class="{{ $btnAi }}"
                                                        title="Vorschlag aus dem Bestand — gerankt nach Rolle · Aroma-Kanten zur gesetzten Folge · Anker-Dichte · Preis-Nähe. Ohne KI.">
                                                    @svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlag
                                                </button>
                                            @endif
                                        </div>
                                        @if(isset($slotVorschlaege[$slot->id]))
                                            @php($vs = $slotVorschlaege[$slot->id])
                                            <div class="mt-2 pl-1 space-y-1">
                                                @foreach($vs['kandidaten'] as $v)
                                                    <div wire:key="ev-{{ $slot->id }}-{{ $v['id'] }}" class="flex items-start justify-between gap-2 px-2 py-1 rounded-lg bg-violet-500/[0.06] border border-violet-500/10">
                                                        <div class="min-w-0">
                                                            <p class="text-xs text-gray-700 truncate">
                                                                {{ $v['name'] }}
                                                                @if($v['diet_form'])
                                                                    <span class="text-[10px] uppercase tracking-wider text-gray-500">{{ $v['diet_form'] }}</span>
                                                                @endif
                                                            </p>
                                                            <p class="text-[10px] text-gray-500 leading-snug">{{ $v['begruendung'] }}</p>
                                                        </div>
                                                        <div class="flex items-center gap-1 shrink-0">
                                                            <span class="text-[11px] text-gray-500 tabular-nums">{{ $v['sales_net'] !== null ? number_format((float) $v['sales_net'], 2, ',', '.') . ' €' : '—' }}</span>
                                                            <button type="button" wire:click="vorschlagUebernehmen({{ $slot->id }}, {{ $v['id'] }})" class="{{ $btnAi }}">übernehmen</button>
                                                            <button type="button" wire:click="vorschlagVerwerfen({{ $slot->id }}, {{ $v['id'] }})" class="text-[11px] text-gray-400 hover:text-red-500" title="Vorschlag verwerfen">✕</button>
                                                        </div>
                                                    </div>
                                                @endforeach
                                                @if($vs['hinweis'] !== null)
                                                    <p class="text-[11px] text-gray-500 px-2 py-0.5">{{ $vs['hinweis'] }}</p>
                                                @endif
                                            </div>
                                        @endif
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
                            <tr><td colspan="9" class="text-xs text-gray-500 py-4 text-center">Noch keine Positionen — links aus dem Quellen-Picker (Gerichte / Pakete / Basisrezepte) mit „+" einfügen (oder ziehen), Abschnitte über „+ Paket".</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    </div>
                    </div>{{-- /BEARBEITEN-ANSICHT (x-show bauModus) --}}
                </div>{{-- /mittlere Spalte --}}
                {{-- rechte VK-Gerichte-Spalte 2026-08-24 in den linken Quellen-Picker (Tab „Gerichte") verschoben --}}
                </div>{{-- /Picker + Positionen (2-Spalten-Flex) --}}
                @else
                    {{-- Paket: Posten schnüren — LINKER Quellen-Picker (spiegelt den Concept-Aufbau, 2026-08-24) + Posten-Liste --}}
                    <div class="flex gap-3 items-start">
                    {{-- Linker Quellen-Picker: Tabs Gerichte / Basisrezepte, gleiche Filter wie im Concept; Park-Flow (Menge/g-Person) bleibt Paket-Anpassung --}}
                    <aside class="w-80 shrink-0 hidden lg:flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-0 self-start max-h-[70vh]" data-paket-quelle-picker data-paket-gerichtliste
                           x-data="{
                                geparkt: null, quantity: '', flash: false,
                                park(id, name) { this.geparkt = { id, name }; this.quantity = ''; this.$nextTick(() => this.$refs.quantity && this.$refs.quantity.focus()); },
                                einfuegen() { if (!this.geparkt) return; this.$wire.gerichtHinzu(this.geparkt.id, this.quantity); this.geparkt = null; this.quantity = ''; this.flash = true; setTimeout(() => { this.flash = false; }, 1400); },
                             }">
                        {{-- Umschalter: Gerichte ⇄ Basisrezepte (Pakete enthalten keine Pakete → kein Paket-Tab) --}}
                        <div class="flex gap-1 mb-1.5" data-paket-quelle-umschalter>
                            <button type="button" wire:click="$set('paketQuelle', 'gericht')" class="{{ $pill }} {{ $paketQuelle !== 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Gerichte</button>
                            <button type="button" wire:click="$set('paketQuelle', 'basisrezept')" class="{{ $pill }} {{ $paketQuelle === 'basisrezept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Basisrezepte</button>
                        </div>
                        <p class="{{ $dt }} mb-1">Posten hinzufügen · {{ $paketQuelle === 'basisrezept' ? 'Basisrezepte' : 'VK-Gerichte' }}</p>
                        <div class="space-y-1 flex-1 min-h-0 overflow-y-auto">
                            <div x-show="geparkt === null">
                                @if($paketQuelle === 'basisrezept')
                                    <input type="search" wire:model.live.debounce.300ms="paketGerichtSuche" placeholder="Basisrezept suchen …" class="{{ $input }} w-full" />
                                @else
                                    @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'paketGerichtSuche'])
                                @endif
                                <p class="text-[10px] text-gray-500 mt-0.5">Treffer: <span class="text-violet-500 font-bold">+</span> parken → {{ $paketQuelle === 'basisrezept' ? 'g/Person' : 'Menge/Person' }} → Enter.</p>
                                @if($paketKandidaten->isNotEmpty())
                                    <div class="space-y-px mt-1 -mx-1 px-1">
                                        @foreach($paketKandidaten as $kand)
                                            <div wire:key="epk-{{ $paketQuelle }}-{{ $kand->id }}" class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px]">
                                                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle($paketQuelle === 'basisrezept' ? 'basisrezept' : 'gericht') }}">{{ $paketQuelle === 'basisrezept' ? 'BR' : 'G' }}</span>
                                                <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" title="{{ $kand->name }}">{{ $kand->name }}</span>
                                                <span class="shrink-0 text-[10px] text-gray-500 tabular-nums">@if($paketQuelle === 'basisrezept'){{ $kand->ek_total_eur !== null ? 'EK ' . number_format((float) $kand->ek_total_eur, 2, ',', '.') . ' €' : '' }}@else{{ $kand->sales_net !== null ? number_format((float) $kand->sales_net, 2, ',', '.') . ' €' : '' }}@endif</span>
                                                <button type="button" @click="park({{ $kand->id }}, @js($kand->name))" class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none" title="parken">+</button>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif($paketGerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '' || $pickDiaet !== '')
                                    <p class="text-[11px] text-gray-500 px-2 py-1 mt-1">Keine Treffer für diese Auswahl.</p>
                                @endif
                            </div>
                            <div x-show="geparkt !== null" x-cloak class="flex items-center gap-2 flex-wrap" data-park-zeile>
                                <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $paketQuelle === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</span>
                                <span class="flex-1 min-w-0 truncate text-sm" x-text="geparkt?.name"></span>
                                <input type="number" step="0.01" min="0" x-ref="quantity" x-model="quantity" @keydown.enter.prevent="einfuegen()" placeholder="{{ $paketQuelle === 'basisrezept' ? 'g/Person' : 'Menge/Person' }}" class="{{ $input }} w-28 text-right tabular-nums" />
                                <button type="button" @click="einfuegen()" class="{{ $btnGhostXs }} text-emerald-600">Einfügen ⏎</button>
                                <button type="button" @click="geparkt = null" class="{{ $btnGhostXs }}">✕</button>
                            </div>
                            <p x-show="flash" x-cloak class="text-[11px] text-emerald-600">✓ hinzugefügt</p>
                        </div>
                    </aside>{{-- /linker Paket-Quellen-Picker --}}
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
                                @if($pg->sales_recipe_id)<button type="button" @click="Livewire.dispatch('{{ $istBasis ? 'recipe-modal.oeffnen' : 'vk-modal.oeffnen' }}', { id: {{ $pg->sales_recipe_id }} })" class="shrink-0 text-gray-300 hover:text-violet-500" title="{{ $istBasis ? 'Basisrezept' : 'Gericht' }} einsehen">@if($istBasis)@svg('heroicon-o-book-open', 'w-3.5 h-3.5 inline-block align-middle')@else @svg('heroicon-o-banknotes', 'w-3.5 h-3.5 inline-block align-middle')@endif</button>@endif
                                <span class="text-[10px] text-gray-500">{{ $istBasis ? 'g/Person' : 'Menge/Person' }}</span>
                                <input type="number" step="0.01" min="0" value="{{ $pg->quantity }}" wire:change="gerichtMengeSpeichern({{ $pg->id }}, $event.target.value)" class="{{ $input }} w-24 text-right tabular-nums" wire:key="epg-menge-{{ $pg->id }}" />
                                <span class="text-gray-500 text-xs tabular-nums w-16 text-right">@if($istBasis){{ $pg->dish?->ek_total_eur !== null ? 'EK ' . number_format((float) $pg->dish->ek_total_eur, 2, ',', '.') . ' €' : '' }}@else{{ $pg->dish?->sales_net !== null ? number_format((float) $pg->dish->sales_net, 2, ',', '.') . ' €' : '' }}@endif</span>
                                <button type="button" wire:click="gerichtRaus({{ $pg->sales_recipe_id }})" class="text-gray-500 hover:text-red-500 px-1" title="entfernen">✕</button>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500 py-4 text-center">Noch keine Posten. Links Gericht oder Basisrezept suchen und hinzufügen.</p>
                        @endforelse
                    </div>
                    </div>{{-- /Mitte Paket-Inhalt --}}
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
                        <p class="text-[11px] text-amber-600">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') Nur {{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }}/{{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichte haben Nährwert + Portionsgramm — Werte sind eine Untergrenze.</p>
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
                            <span class="{{ $label }}">{{ $istPaket ? 'Paketpreis / Person' : 'VK-Preis / Person' }}</span>
                            <div class="flex gap-1">
                                <button type="button" wire:click="setPreisModus('auto')" class="{{ $pill }} {{ ($form['price_mode'] ?? 'auto') === 'auto' ? $variantPill['primary'] : $variantPill['secondary'] }}">automatisch (Summe)</button>
                                <button type="button" wire:click="setPreisModus('fixed')" class="{{ $pill }} {{ in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true) ? $variantPill['primary'] : $variantPill['secondary'] }}">fixiert</button>
                            </div>
                        </div>
                        {{-- Preisdarstellung (2026-08-25, Dominique): Gesamtpreis (ein Preis fürs Konzept)
                             vs. Einzelpreise (je Gericht/Paket, kein Summenpreis — Auswahl à la carte wie
                             Kuchen/Fingerfood). Reine Concept-Eigenschaft; Pakete sind immer Gesamtpreis. --}}
                        @unless($istPaket)
                            <div class="flex items-center gap-2 pt-1 border-t border-black/5">
                                <label class="{{ $label }} shrink-0">Preisdarstellung</label>
                                <select wire:change="setPreisDisplay($event.target.value)" class="{{ $input }} text-xs">
                                    <option value="gesamt" @selected(($form['price_display'] ?? 'gesamt') === 'gesamt')>Gesamtpreis — ein Preis fürs Konzept</option>
                                    <option value="einzel" @selected(($form['price_display'] ?? 'gesamt') === 'einzel')>Einzelpreise — je Gericht / Paket</option>
                                </select>
                            </div>
                            @if(($form['price_display'] ?? 'gesamt') === 'einzel')
                                <p class="text-[11px] text-gray-500">Kein Summenpreis — jedes Gericht (bzw. eingebettetes Paket) zeigt seinen eigenen Preis. Wirkt in Foodbook, Format, Speisekarte &amp; interner Sicht.</p>
                            @endif
                        @endunless
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
                            <span class="text-gray-600">Berechnete Summe: <span class="tabular-nums font-medium text-gray-900">{{ number_format((float) ($cockpit['summe_pro_person'] ?? 0), 2, ',', '.') }} €</span></span>
                            <span class="text-gray-600">Wareneinsatz: <span class="tabular-nums">{{ number_format((float) ($cockpit['ek_per_person'] ?? 0), 2, ',', '.') }} €</span></span>
                        </div>
                        @if(($form['price_mode'] ?? 'auto') === 'auto')
                            <p class="text-[11px] text-gray-500">Automatisch addiert die gültigen Katalogpreise der Positionen. Je Gericht gilt die Preisklasse der Darreichung, sonst die Gerichtsklasse, dann die Team-Standardklasse; Pakete liefern ihren bereits aggregierten Katalogpreis.</p>
                        @endif
                        @if(in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="{{ $label }}">{{ $istPaket ? 'Paketpreis (Gesamt) / Person' : 'Fixer VK / Person' }}</label>
                                @if($istPaket)
                                    <input type="number" step="0.01" min="0" wire:model="form.price_per_person" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 24,90" />
                                @else
                                    <input type="number" step="0.01" min="0" wire:model="form.price_per_person_manual" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 24,90" />
                                @endif
                                <input type="text" wire:model="form.price_override_reason" class="{{ $input }} min-w-56 flex-1" placeholder="Begründung der Preisabweichung" />
                                <button type="button" wire:click="speichern" class="{{ $btnGhostXs }} text-violet-600">Fixpreis übernehmen</button>
                            </div>
                        @endif
                    </div>
                @endif
                @if($concept)
                    <div class="rounded-xl border border-black/5 p-3 space-y-2">
                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label class="{{ $label }}">Auftrag simulieren (Pax)</label>
                                <input type="number" min="0" step="1" wire:model.live.debounce.400ms="simulationPax"
                                       class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 100" />
                            </div>
                            <p class="text-[11px] text-gray-500 pb-2">Die Vorschau prüft den Katalogpreis, ohne Stammdaten zu verändern.</p>
                        </div>
                        @if($auftragsSimulation)
                            @php($simPax = max(1, (int) $auftragsSimulation['pax']))
                            @php($simZielPp = (float) ($auftragsSimulation['target_price_per_person'] ?? 0))
                            @php($simAbweichungPp = (float) $auftragsSimulation['catalog_price_per_person'] - $simZielPp)
                            @php($simDbPp = (float) $auftragsSimulation['contribution_margin'] / $simPax)
                            <div class="grid grid-cols-2 md:grid-cols-5 xl:grid-cols-10 gap-2 text-xs" data-auftrag-preisempfehlung>
                                <div><span class="block text-[10px] text-gray-500">Katalog / Person</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['catalog_price_per_person'], 2, ',', '.') }} €</span></div>
                                <div><span class="block text-[10px] text-gray-500">MEK Auftrag / Person</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['mek'] / $simPax, 2, ',', '.') }} €</span></div>
                                <div><span class="block text-[10px] text-gray-500">FEK Auftrag / Person</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['fek'] / $simPax, 2, ',', '.') }} €</span></div>
                                <div><span class="block text-[10px] text-gray-500">HK2 / Person</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['hk2'] / $simPax, 2, ',', '.') }} €</span></div>
                                <div class="rounded-md bg-violet-500/10 px-2 py-1.5"><span class="block text-[10px] text-violet-500">Preisempfehlung / Person</span><span class="font-semibold text-violet-700 tabular-nums">{{ number_format($simZielPp, 2, ',', '.') }} €</span></div>
                                <div><span class="block text-[10px] text-gray-500" title="Katalogpreis pro Person minus Preisempfehlung pro Person">Abweichung Katalog − Ziel</span><span class="font-medium tabular-nums {{ $simAbweichungPp < 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $simAbweichungPp > 0 ? '+' : '' }}{{ number_format($simAbweichungPp, 2, ',', '.') }} €/P</span></div>
                                <div><span class="block text-[10px] text-gray-500">Mindestpreis gesamt</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['minimum_price'], 2, ',', '.') }} €</span></div>
                                <div><span class="block text-[10px] text-gray-500">Zielpreis gesamt</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['target_price'], 2, ',', '.') }} €</span></div>
                                <div><span class="block text-[10px] text-gray-500">Deckungsbeitrag Auftrag</span><span class="font-medium tabular-nums {{ $auftragsSimulation['contribution_margin'] < 0 ? 'text-rose-500' : 'text-emerald-600' }}">{{ number_format($simDbPp, 2, ',', '.') }} €/P <span class="block text-[9px]">{{ number_format((float) $auftragsSimulation['contribution_margin'], 2, ',', '.') }} € · {{ $auftragsSimulation['contribution_margin_pct'] !== null ? number_format((float) $auftragsSimulation['contribution_margin_pct'], 1, ',', '.') . ' %' : '—' }}</span></span></div>
                                <div><span class="block text-[10px] text-gray-500">Aktive Personenzeit</span><span class="font-medium tabular-nums">{{ number_format((float) $auftragsSimulation['active_person_minutes'] / 60, 2, ',', '.') }} h</span></div>
                            </div>
                            @if($auftragsSimulation['unprofitable'])
                                <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Der Katalogpreis liegt {{ number_format((float) $auftragsSimulation['target_gap'], 2, ',', '.') }} € unter dem Zielpreis. Der Katalogpreis wurde nicht verändert.
                                </div>
                            @endif
                            @unless($auftragsSimulation['complete'])
                                <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                                    Preisempfehlung nicht belastbar: Die Auftragsdaten sind noch unvollständig. Für die Berechnung wird mindestens der ausgewiesene Katalog-MEK verwendet.
                                </div>
                            @endunless
                            @if(count($auftragsSimulation['warnings']))
                                <p class="text-[10px] text-amber-700">{{ implode(' · ', $auftragsSimulation['warnings']) }}</p>
                            @endif
                            @if(count($auftragsSimulation['cost_breakdown'] ?? []))
                                <div class="border-t border-black/5 pt-2" data-auftragskosten-wasserfall>
                                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 pb-1 text-[10px] uppercase tracking-wider text-gray-500">
                                        <span>Auftragskosten</span><span class="text-right">je Person</span><span class="text-right">gesamt</span>
                                    </div>
                                    @foreach($auftragsSimulation['cost_breakdown'] as $kosten)
                                        @php($kostenStufe = $kosten['stage'] ?? 'cost')
                                        <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 py-0.5 text-xs {{ in_array($kostenStufe, ['subtotal', 'total'], true) ? 'mt-1 border-t border-black/5 pt-1 font-semibold text-gray-900' : 'text-gray-600' }} {{ $kostenStufe === 'total' ? 'text-violet-700' : '' }}">
                                            <span>{{ in_array($kostenStufe, ['surcharge'], true) ? '+ ' : '' }}{{ $kosten['label'] }}</span>
                                            <span class="text-right tabular-nums">{{ number_format((float) $kosten['amount'] / $simPax, 2, ',', '.') }} €</span>
                                            <span class="text-right tabular-nums">{{ number_format((float) $kosten['amount'], 2, ',', '.') }} €</span>
                                        </div>
                                    @endforeach
                                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 mt-1 border-t border-black/5 pt-1 text-xs font-semibold text-violet-700">
                                        <span>Preisempfehlung</span>
                                        <span class="text-right tabular-nums">{{ number_format($simZielPp, 2, ',', '.') }} €</span>
                                        <span class="text-right tabular-nums">{{ number_format((float) $auftragsSimulation['target_price'], 2, ',', '.') }} €</span>
                                    </div>
                                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 py-0.5 text-xs {{ $auftragsSimulation['contribution_margin'] < 0 ? 'text-rose-500' : 'text-emerald-600' }}">
                                        <span>Deckungsbeitrag beim Katalog-VK</span>
                                        <span class="text-right tabular-nums">{{ number_format($simDbPp, 2, ',', '.') }} €</span>
                                        <span class="text-right tabular-nums">{{ number_format((float) $auftragsSimulation['contribution_margin'], 2, ',', '.') }} €</span>
                                    </div>
                                </div>
                            @endif
                            @if(count($auftragsSimulation['time_breakdown'] ?? []))
                                <details class="pt-1" data-zeitaufschluesselung>
                                    <summary class="cursor-pointer text-[11px] font-medium text-gray-600">Zeitaufschlüsselung: {{ number_format((float) $auftragsSimulation['active_person_minutes'] / 60, 2, ',', '.') }} Personenstunden <span class="font-normal text-gray-500">({{ number_format((float) $auftragsSimulation['active_person_minutes'], 1, ',', '.') }} Personenminuten)</span></summary>
                                    <div class="overflow-x-auto pt-2">
                                        <table class="w-full min-w-[760px] text-[11px]">
                                            <thead><tr class="text-gray-500">
                                                <th class="py-1 text-left font-medium">Rezept</th><th class="text-right font-medium">Ansätze</th><th class="text-right font-medium">Vorgänge</th><th class="text-right font-medium">Rüsten</th><th class="text-right font-medium">Vorgangszeit</th><th class="text-right font-medium">Variabel</th><th class="text-right font-medium">Aktiv gesamt</th>
                                            </tr></thead>
                                            <tbody>
                                            @foreach($auftragsSimulation['time_breakdown'] as $zeit)
                                                <tr class="border-t border-black/5">
                                                    <td class="py-1 pr-3">{{ $zeit['recipe'] }}</td>
                                                    <td class="text-right tabular-nums">{{ number_format((float) $zeit['production_batches'], 2, ',', '.') }}</td>
                                                    <td class="text-right tabular-nums">{{ $zeit['operations'] }}</td>
                                                    <td class="text-right tabular-nums">{{ number_format((float) $zeit['setup_minutes'], 1, ',', '.') }} min</td>
                                                    <td class="text-right tabular-nums">{{ number_format((float) $zeit['batch_minutes'], 1, ',', '.') }} min</td>
                                                    <td class="text-right tabular-nums">{{ number_format((float) $zeit['variable_minutes'], 1, ',', '.') }} min</td>
                                                    <td class="text-right font-medium tabular-nums">{{ number_format((float) $zeit['active_person_minutes'], 1, ',', '.') }} min</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            @endif
                        @endif
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
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                            <span class="text-[10px] uppercase tracking-wider text-violet-600">€/Person</span>
                            <p class="text-base font-bold text-violet-700 tabular-nums">{{ number_format($cockpit['price_per_person'], 2, ',', '.') }} €</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                            <span class="{{ $dt }}">Wareneinsatz/Pers.</span>
                            <p class="text-xs font-semibold tabular-nums">{{ number_format((float) ($cockpit['ek_per_person'] ?? 0), 2, ',', '.') }} €</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                            <span class="{{ $dt }}">Wareneinsatz %</span>
                            <p class="text-xs font-semibold tabular-nums">{{ ($cockpit['price_per_person'] > 0) ? number_format((float) ($cockpit['ek_per_person'] ?? 0) / $cockpit['price_per_person'] * 100, 1, ',', '.') . ' %' : '—' }}</p>
                        </div>
                        <div>
                            <label class="{{ $label }}">Zielpreis €/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.target_price_per_person" class="{{ $input }} text-right tabular-nums" />
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="button" wire:click="zielpreisToggle" class="{{ $btnGhost }} {{ $zielModus ? 'text-violet-600' : '' }}">@svg('heroicon-o-flag', 'w-3.5 h-3.5 inline-block align-middle') Zielpreis-Konfigurator</button>
                    </div>
                    @if($zielModus)
                        <div class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-3 space-y-2">
                            <div class="flex items-end gap-2 flex-wrap">
                                <div>
                                    <label class="{{ $label }}">Komm auf €/Person</label>
                                    <input type="number" step="0.01" min="0" wire:model="zielPreis" wire:keydown.enter="zielpreisBerechnen" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 36,00" />
                                </div>
                                <button type="button" wire:click="zielpreisBerechnen" class="{{ $btnPrimary }}">Vorschlag</button>
                                <span class="text-[11px] text-gray-500">Tauscht Pakete gegeneinander; feste Gerichte = Fixkosten.</span>
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
                            <select wire:change="setPreisModus($event.target.value)" class="{{ $input }}">
                                <option value="auto">auto (Σ Gerichte)</option>
                                <option value="fixed" @selected(in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))>fixiert</option>
                            </select>
                        </div>
                        <div>
                            <label class="{{ $label }}">€/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.price_per_person" @disabled(($form['price_mode'] ?? 'auto') === 'auto') class="{{ $input }} text-right tabular-nums" />
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
                    @if(in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))
                        <div class="flex items-end gap-2">
                            <div class="flex-1"><label class="{{ $label }}">Begründung der Preisabweichung</label><input type="text" wire:model="form.price_override_reason" class="{{ $input }}" /></div>
                            <button type="button" wire:click="speichern" class="{{ $btnGhostXs }} text-violet-600">Fixpreis übernehmen</button>
                        </div>
                    @endif
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="neuBerechnen" class="{{ $btnGhost }}">↻ EK aus Gerichten neu berechnen</button>
                        <span class="text-[10px] text-gray-500">Kosten und Auto-Vorschlag folgen den Gerichten; ein Fixpreis benötigt eine Begründung.</span>
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
                    @include('foodalchemist::livewire.canvas.partials.board', ['hideSave' => true])

                    {{-- R4.1 + Progressive Disclosure (2026-08-24): Planungs-Gerüst = messbarer Soll-Rahmen.
                         Nur nötig, wenn man gegen ein SOLL plant oder KI-Konzepte erzeugt — darum beim
                         Handaufbau NICHT als große leere Maske aufdrängen. Kein Gerüst → ruhige CTA-Zeile;
                         Gerüst vorhanden (via KI-Planung ODER manuellem „+Slot") → voll (SOLL + Coverage).
                         Der Frame ist owner=concept: Planung-Leitstelle und dieser Tab bearbeiten dasselbe. --}}
                    @php($hatGeruest = $coverage !== null && $coverage['hat_geruest'])
                    <div x-data="{ offen: @js($hatGeruest) }" class="mt-4">
                        {{-- Zu (kein Gerüst): ruhige Einladung statt leerer SOLL-Maske --}}
                        <div x-show="!offen" @if($hatGeruest) style="display:none" @endif
                             class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-black/10 px-3 py-2">
                            <span class="text-[11px] text-gray-500">Kein Planungs-Gerüst — der messbare SOLL-Rahmen (Mengen, Preis, Diät-Quoten, Saison, Dramaturgie). Nur nötig, wenn du gegen ein SOLL planst oder KI-Konzepte erzeugst; die Planung-Leitstelle füllt es aus einem Brief.</span>
                            <button type="button" x-on:click="offen = true" class="{{ $btnGhostXs }} shrink-0">+ Gerüst anlegen</button>
                        </div>

                        {{-- Offen (Gerüst vorhanden ODER manuell aufgeklappt): volle SOLL-Maske + Coverage --}}
                        <div x-show="offen" @unless($hatGeruest) style="display:none" @endunless>
                            <p class="text-[11px] text-gray-500 mb-2">Planungs-Gerüst — das messbare SOLL (Mengen, Preisrahmen, Diät-Quoten, Saison, No-Gos, Dramaturgie). Messlatte für Coverage (R4.2) und KI-Konzepte (R6).</p>
                            @include('foodalchemist::livewire.planning.partials.frame-board', ['hideSave' => true])

                            {{-- Spec 28 / E6: die IST-Messung gegen genau dieses Gerüst — SOLL und IST untereinander.
                                 Lücken-Klick filtert weiterhin den Picker im Aufbau-Tab (R4.2). --}}
                            @if($hatGeruest)
                                <p class="text-[11px] text-gray-500 mt-4 mb-2">Soll/Ist-Coverage — was das Gerüst verlangt und was im Aufbau steht. Klick auf eine Lücke filtert den Picker im Tab «Aufbau».</p>
                                @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverageFillAction' => 'coverageFuellen'])
                            @endif
                        </div>
                    </div>
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
                                                <button type="button" wire:click="geschirrPicker({{ $slot->id }}, '{{ $role }}')" class="{{ $btnAi }}">ändern</button>
                                                <button type="button" wire:click="geschirrEntfernen({{ $slot->id }}, '{{ $role }}')" class="{{ $btnGhostXs }} text-rose-500">entfernen</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="geschirrPicker({{ $slot->id }}, '{{ $role }}')" class="{{ $btnAi }}">+ Geschirr wählen</button>
                                            @if($role === 'haupt' && isset($geschirrVorschlag[$slot->id]))
                                                <button type="button" wire:click="geschirrWaehle({{ $slot->id }}, 'haupt', {{ $geschirrVorschlag[$slot->id]['id'] }})"
                                                        class="{{ $pill }} {{ $variantPill['primary'] }} mt-0.5" data-geschirr-vorschlag
                                                        title="Default-Geschirr der aufgelösten Darreichung ({{ $geschirrVorschlag[$slot->id]['form'] }}) — Klick übernimmt">@svg('heroicon-o-light-bulb', 'w-3.5 h-3.5 inline-block align-middle') {{ $geschirrVorschlag[$slot->id]['label'] }} übernehmen</button>
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
                @elseif($paket)
                    {{-- Geschirr je Paket-Posten (2026-08-24, spiegelt den Concept-Geschirr-Tab) --}}
                    <p class="text-[11px] text-gray-500 mb-2">Pro Posten ein Haupt-Geschirr + optional eine Alternative. Pflege den Geschirr-Katalog unter <span class="font-medium">Stammdaten → Geschirr</span>.</p>
                    @forelse($paket->dishes as $pg)
                        <div wire:key="paket-geschirr-{{ $pg->id }}" class="rounded-lg border border-black/5 px-3 py-2 mb-2">
                            <p class="text-xs font-medium text-gray-900 mb-1.5 truncate">{{ $pg->dish?->name ?: 'Posten' }}</p>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach(['haupt' => 'Haupt-Geschirr', 'alt' => 'Alternative'] as $role => $rolleLabel)
                                    @php($item = $role === 'haupt' ? $pg->dishwareItem : $pg->dishwareAltItem)
                                    <div>
                                        <label class="{{ $label }} block mb-0.5">{{ $rolleLabel }}</label>
                                        @if($item)
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs text-gray-800 truncate" title="{{ $item->label }}">{{ $item->label }}</span>
                                                @if($item->rental_price !== null)<span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">{{ number_format((float) $item->rental_price, 2, ',', '.') }} €</span>@endif
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <button type="button" wire:click="geschirrPicker({{ $pg->id }}, '{{ $role }}')" class="{{ $btnAi }}">ändern</button>
                                                <button type="button" wire:click="geschirrEntfernen({{ $pg->id }}, '{{ $role }}')" class="{{ $btnGhostXs }} text-rose-500">entfernen</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="geschirrPicker({{ $pg->id }}, '{{ $role }}')" class="{{ $btnAi }}">+ Geschirr wählen</button>
                                        @endif

                                        @if($geschirrPickSlotId === $pg->id && $geschirrPickRolle === $role)
                                            <div class="mt-1.5 rounded-lg border border-violet-500/30 bg-violet-500/[0.04] p-2" wire:key="paket-geschirr-pick-{{ $pg->id }}-{{ $role }}">
                                                <input type="search" wire:model.live.debounce.300ms="geschirrSuche" placeholder="Geschirr suchen …" class="{{ $input }} !py-1 mb-1" autofocus />
                                                <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                                    @forelse($geschirrKandidaten as $kandidat)
                                                        <button type="button" wire:key="pgk-{{ $pg->id }}-{{ $role }}-{{ $kandidat->id }}"
                                                                wire:click="geschirrWaehle({{ $pg->id }}, '{{ $role }}', {{ $kandidat->id }})"
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
                        <p class="text-xs text-gray-500 py-6 text-center">Noch keine Posten im Paket — erst im Aufbau-Tab hinzufügen.</p>
                    @endforelse
                @endif
            @endif
        @endif
    </x-foodalchemist::modal>
</div>
