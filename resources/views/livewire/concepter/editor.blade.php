{{-- Concepter-Editor (Konzept und Paket) — Voll-Editor im Werkbank-Modus (darkCanvas → data-fa-theme="dark").
     fa-pass: nur --fa-*-Tokens und x-fa-Bausteine, damit hell UND dunkel stimmen.

     Anatomie: Kopf = Titel + Name + Status · rechts Weitere Aktionen (Menü) · Speichern (die eine
     Hauptaktion). Kennzahlen fix im Kopf, VK je Person als Hauptzahl. Häufigste Arbeit = Positionen
     zusammenstellen → Reiter «Aufbau» zuerst. Reiter laufen im Server-Modus (setTab): nur das aktive
     Panel lebt, Coverage, Picker und Kalkulation sind zu schwer für alle gleichzeitig.

     Laptop-Breite (Dominique 2026-10-05): der Quellen-Picker war unter 1280 px komplett ausgeblendet,
     auf dem Laptop ließ sich also nichts einfügen. Jetzt steht er unter 1024 px über der Tabelle, ab
     1024 px links daneben (schmaler, ab 1536 px breiter). Raster brechen früher um, Tabellen scrollen
     waagerecht statt abzuschneiden. --}}
@php
    $item = $concept ?? $paket;
    // Kaskade (2026-08-24): ein kind=paket-Concept öffnet im selben Editor wie ein Konzept.
    $istPaket = ($concept?->kind ?? null) === 'paket';
    // Typ-Farbe aus den Team-Einstellungen (Phase 5) — Laufzeitwert, deshalb als style-Bindung.
    $typStyle = fn (string $t) => isset($typFarben[$t]) ? 'color:' . $typFarben[$t] . ';background-color:' . $typFarben[$t] . '1a' : '';
    $euro = fn ($wert, int $nachkomma = 2) => $wert === null ? null : number_format((float) $wert, $nachkomma, ',', '.') . ' €';
    $menge = fn ($wert) => $wert === null ? null : rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',');

    $klein = 'text-[length:var(--fa-text-sm)]';
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $feldLabel = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    // Chips mit wire:click (Facetten, Filter): an = Akzent, aus = ruhig.
    $chip = 'inline-flex items-center gap-1 h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] font-medium transition-colors';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';
    // Umschalter (zwei bis drei Ansichten).
    $segment = 'inline-flex rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-[var(--fa-ground)] p-0.5';
    $segKnopf = 'inline-flex items-center gap-1.5 h-7 px-3 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium transition-colors';
    $segAn = 'bg-[var(--fa-surface)] text-[var(--fa-accent)] shadow-sm';
    $segAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    // Listenzeile im Quellen-Picker (ziehbar).
    $listeZeile = 'group flex items-center gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)] cursor-grab active:cursor-grabbing';
    $liste = 'flex flex-col gap-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1';
    $leerText = 'px-2 py-3 text-center text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $kopfzelle = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';

    // Deklarations-Konfidenz lesbar statt Rohwert.
    $konf = [
        'high' => ['belegt', 'ok'],
        'medium' => ['teilweise belegt', 'warn'],
        'low' => ['unsicher', 'crit'],
        'unknown' => ['nicht bewertet', 'neutral'],
    ];
    $konfText = fn (?string $k) => $konf[$k ?? 'unknown'][0] ?? (string) $k;
    $konfTon = fn (?string $k) => $konf[$k ?? 'unknown'][1] ?? 'neutral';

    $statusAnzeige = ['draft' => ['Entwurf', 'neutral'], 'active' => ['Aktiv', 'ok'], 'archiviert' => ['Archiviert', 'neutral']];
@endphp

<div>
    {{-- Spec 28 / E1-2: Titel sagt WAS bearbeitet wird, der Name steht daneben.
         Concept und Paket teilen diesen Editor — der Titel benennt, welches von beiden. --}}
    <x-foodalchemist::modal name="concepter-editor" :title="($paket || $istPaket) ? 'Paket' : 'Konzept'"
        :title-name="$item?->name" fullscreen dark-canvas>

        @if($item !== null)
            <x-slot:titleExtra>
                @if($concept)
                    @php [$stText, $stTon] = $statusAnzeige[$concept->status ?? 'draft'] ?? [ucfirst((string) $concept->status), 'neutral']; @endphp
                    <x-fa::badge :tone="$stTon" data-concept-status="{{ $concept->status }}">{{ $stText }}</x-fa::badge>
                    @if($concept->is_template)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack">Vorlage</x-fa::badge>@endif
                @endif
                @if($bewertung)
                    @php $scoreTon = $bewertung['score'] >= 80 ? 'ok' : ($bewertung['score'] >= 50 ? 'warn' : 'crit'); @endphp
                    <x-fa::badge :tone="$scoreTon" icon="heroicon-m-check-badge" title="Menü-Bewertung: Anteil der bestandenen Prüfungen">Bewertung {{ $bewertung['score'] }} %</x-fa::badge>
                @endif
            </x-slot:titleExtra>
        @endif

        <x-slot:actions>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if($paket && $rueckSprungConceptId)
                    <x-fa::button icon="heroicon-m-arrow-uturn-left" wire:click="zurueckZumConcept" title="Paket sichern und zurück ins Konzept">Speichern und zurück zum Konzept</x-fa::button>
                @endif

                {{-- Weitere Aktionen: Kunden-Karte · Technischer Bericht · Vorlage --}}
                @if($concept)
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" data-concepter-weitere />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                            {{-- #6 (F3c): Druck-Symmetrie zum Format-Editor — schöne Kunden-Ausgabe + technischer Bericht.
                                 Die Karte gilt auch fürs Paket (concepts.karte nimmt eine Concept-ID). --}}
                            <a href="{{ route('foodalchemist.concepts.karte', ['id' => $concept->id]) }}" target="_blank" role="menuitem"
                               x-on:click="offen = false" class="{{ $menuePunkt }}" title="Menükarte für den Kunden, zum Drucken oder als PDF" data-concepter-karte>
                                @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Kundenkarte drucken
                            </a>
                            <a href="{{ route('foodalchemist.concepts.dokument', array_filter(['id' => $concept->id, 'profil' => 'voll', 'simulation' => $simulationPax > 0 ? 1 : null, 'pax' => $simulationPax > 0 ? $simulationPax : null], fn ($value) => $value !== null)) }}" target="_blank" role="menuitem"
                               x-on:click="offen = false" class="{{ $menuePunkt }}" title="Vollständiger Bericht vom Gericht über Basisrezept und Grundprodukt bis zum Lieferantenartikel" data-concepter-druck>
                                @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--fa-ink-3)]') Technischen Bericht öffnen
                            </a>
                            @if(! $concept->is_template)
                                <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                <button type="button" role="menuitem" wire:click="alsVorlage" x-on:click="offen = false" class="{{ $menuePunkt }}" data-concepter-als-vorlage>
                                    @svg('heroicon-o-square-2-stack', 'w-4 h-4 text-[var(--fa-ink-3)]') Als Vorlage speichern
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- #5 (2026-08-13): EIN Speichern pro Tab — auf «Konzept & Planung» sichert der Knopf
                     Stammdaten + Canvas + Rahmen zusammen (konzeptSpeichern), sonst nur die Stammdaten. --}}
                {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung,
                     der Editor bleibt offen (bearbeitungSpeichern wählt konzeptSpeichern/speichern je Reiter). --}}
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="bearbeitungSpeichern" data-concepter-speichern>Speichern</x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>
        </x-slot:actions>

        {{-- Live-Kosten fix im Modal-Kopf (immer sichtbar, alle Reiter). EIN Leitwert: VK je Person.
             Wareneinsatz % folgt derselben Team-Ziel-Ampel wie Gericht und Angebot. --}}
        @if($kalkulation)
            @php
                $stripVk = $concept ? ($cockpit['price_per_person'] ?? 0) : ($paket?->price_per_person !== null ? (float) $paket->price_per_person : null);
                $stripEk = $concept ? (float) ($cockpit['ek_per_person'] ?? 0) : (float) ($kalkulation['hk1_pro_person'] ?? 0);
                $stripWpct = ($stripVk !== null && $stripVk > 0) ? $stripEk / $stripVk * 100 : null;
                $stripWeTone = match ($wareneinsatzAmpel ?? 'unbekannt') { 'gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', default => null };
                $vkWert = $stripVk === null ? 'Preis fehlt' : ((float) $stripVk > 0 ? $euro($stripVk) : 'noch kein Preis');
            @endphp
            <x-slot:kpiHeader>
                <x-foodalchemist::kpi-tiles :cols="4" marker="konzept-kpis" :tiles="array_values(array_filter([
                    ['kpi' => 'vk-person', 'label' => 'VK je Person', 'tone' => ($stripVk !== null && (float) $stripVk > 0) ? 'accent' : 'warn', 'value' => $vkWert],
                    ['kpi' => 'we-person', 'label' => 'Wareneinsatz je Person', 'value' => $euro($stripEk)],
                    ['kpi' => 'we-pct', 'label' => 'Wareneinsatz', 'tone' => $stripWeTone,
                     'title' => $stripWpct !== null ? 'Ziel des Teams: ' . number_format((float) $zielWareneinsatzPct, 1, ',', '.') . ' %' : 'Ohne VK lässt sich der Wareneinsatz nicht berechnen',
                     'value' => $stripWpct !== null ? number_format($stripWpct, 1, ',', '.') . ' %' : 'ohne VK'],
                    isset($aggregat['gewicht_pro_person_g']) ? [
                        'kpi' => 'gewicht', 'label' => 'Gewicht je Person',
                        'value' => number_format((float) $aggregat['gewicht_pro_person_g'], 0, ',', '.') . ' g',
                        'hint' => ($aggregat['gewicht_vollstaendig'] ?? true) ? null : '~',
                        'hint_title' => 'Mindestens eine Position ohne Portionsgewicht, das Gewicht ist unvollständig',
                    ] : null,
                ]))" />
            </x-slot:kpiHeader>
        @endif

        @if($item === null)
            <x-fa::empty icon="heroicon-o-rectangle-stack" title="Nichts geladen">Konzept oder Paket im Browser wählen.</x-fa::empty>
        @else
            {{-- Reiter im SERVER-Modus des Bausteins: der Concepter hält den Reiter in Livewire und
                 rendert nur das aktive Panel. 'allergene'-Key bleibt stabil, Label „Deklaration". --}}
            <x-foodalchemist::editor-tabs marker="konzept" action="setTab" :active="$tab" :tabs="[
                'aufbau' => 'Aufbau',
                'stammdaten' => 'Stammdaten',
                'konzept' => ($concept && ! $istPaket) ? 'Konzept & Planung' : null,
                'allergene' => 'Deklaration',
                'kalkulation' => 'Kalkulation',
                'geschirr' => ($concept || $paket) ? 'Geschirr' : null,
                'notes' => 'Notizen',
            ]" />

            {{-- Spec 65: Reiter im Server-Modus liefern nur die Leiste — die Panels sperrt im Lesemodus ein fieldset
                 (Reiterleiste bleibt bedienbar). Im Reiter Kalkulation einzeln je Abschnitt, damit „Auftrag hochrechnen"
                 (reine Vorschau, schreibt nichts) auch im Lesemodus bedienbar bleibt. --}}
            @php $lesemodus = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
            <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">

            @if($fehler)
                <x-fa::notice tone="crit" data-concepter-fehler>{{ $fehler }}</x-fa::notice>
            @endif

            {{-- ── Reiter: STAMMDATEN ─────────────────────────────────────────────
                 #6 (2026-08-13): Felder in Abschnitten — Bezeichnung / Bilder / Einordnung /
                 Anlass / Phase und Schreibstil. Bindings, wire:change-Sofortspeichern und data-Marker 1:1. --}}
            @if($tab === 'stammdaten')
                <div class="flex flex-col gap-4">
                    <x-fa::section title="Bezeichnung" icon="heroicon-o-tag">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <x-fa::field label="Interne Bezeichnung" for="cc-name">
                                <x-fa::input id="cc-name" wire:model="form.name" />
                            </x-fa::field>
                            <x-fa::field label="Name für Gäste" for="cc-consumer" hint="Steht auf Karte, Foodbook und Präsentation.">
                                <x-fa::input id="cc-consumer" wire:model="form.consumer_name" placeholder="z. B. „Sommerliche Vorspeisen-Auswahl“" />
                            </x-fa::field>
                        </div>
                    </x-fa::section>

                    {{-- Spec 43 (Bild-Epic): Titelbild + kleine Galerie fürs Kapitel-Band in der Präsentation --}}
                    <x-fa::section title="Bilder für die Präsentation" icon="heroicon-o-photo"
                        description="Das Titelbild wird zum Kapitel-Band der Präsentation. Ein eigenes Kapitel-Bild ersetzt es dort." data-concept-image>
                        <div class="flex flex-col gap-1.5">
                            <span class="{{ $feldLabel }}">Titelbild</span>
                            <div class="flex items-center gap-3 flex-wrap">
                                @if($conceptImageUrl)
                                    <img src="{{ $conceptImageUrl }}" alt="Titelbild" class="h-14 w-24 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-trash" wire:click="conceptImageEntfernen" data-concept-image-remove>Titelbild entfernen</x-fa::button>
                                @endif
                                <input type="file" wire:model="conceptImageUpload" accept="image/*" class="{{ $klein }} text-[var(--fa-ink-2)]" data-concept-image-upload>
                                <span wire:loading wire:target="conceptImageUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
                            </div>
                            @if($conceptImageFehler)<x-fa::signal tone="crit">{{ $conceptImageFehler }}</x-fa::signal>@endif
                            @error('conceptImageUpload')<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
                        </div>

                        {{-- Galerie: weitere Bilder neben dem Titelbild (Kapitel-Band zeigt Titel + erstes Galeriebild) --}}
                        <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-concept-gallery>
                            <span class="{{ $feldLabel }}">Weitere Bilder <span class="font-normal text-[var(--fa-ink-3)]">(optional)</span></span>
                            <div class="flex items-center gap-3 flex-wrap">
                                @foreach($conceptGallery as $gi)
                                    <div class="relative" wire:key="cgal-{{ $gi['id'] }}">
                                        <img src="{{ $gi['url'] }}" alt="Galeriebild" class="h-14 w-24 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                        <button type="button" wire:click="galerieBildEntfernen({{ $gi['id'] }})"
                                            class="absolute -top-2 -right-2 h-6 w-6 rounded-full bg-[var(--fa-surface)] border border-[var(--fa-crit-line)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)] flex items-center justify-center"
                                            title="Bild entfernen" aria-label="Bild entfernen" data-gallery-remove>@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </div>
                                @endforeach
                                <input type="file" wire:model="conceptGalleryUpload" accept="image/*" multiple class="{{ $klein }} text-[var(--fa-ink-2)]" data-concept-gallery-upload>
                                <span wire:loading wire:target="conceptGalleryUpload" class="{{ $hinweis }}">Wird hochgeladen …</span>
                            </div>
                            @error('conceptGalleryUpload')<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
                        </div>
                    </x-fa::section>

                    {{-- Einordnung (Klasse/Niveau + Status/Geschmack) --}}
                    <x-fa::section title="Einordnung" icon="heroicon-o-squares-2x2">
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <x-fa::field label="Klasse" for="cc-klasse" hint="Frei wählbar oder aus der Liste.">
                                <x-fa::input id="cc-klasse" wire:model="form.class" list="concepter-klassen" placeholder="z. B. Buffet" />
                                <datalist id="concepter-klassen">@foreach($klassen as $k)<option value="{{ $k }}"></option>@endforeach</datalist>
                            </x-fa::field>
                            <x-fa::choice name="form.level" :live="false" label="Niveau" :options="['' => 'Keine Vorgabe', 'klassisch' => 'klassisch', 'gehoben' => 'gehoben', 'haute' => 'Haute Cuisine']" />
                            @if($concept)
                                <x-fa::choice name="form.status" :live="false" label="Status" :options="['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert']" />
                                <x-fa::choice name="form.taste_direction" :live="false" label="Geschmack" :options="['' => 'Keine Vorgabe', 'suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral']" />
                            @endif
                            {{-- Paket-Rolle 2026-08-24 entfernt: Paket = wiederverwendbares Bündel mit eigenem Preis, keine Gang-Rolle nötig --}}
                        </div>
                    </x-fa::section>

                    @if($concept)
                        {{-- Anlass und Einsatz (Facetten, Umbau-Spec Phase 4b) --}}
                        <x-fa::section title="Anlass und Einsatz" icon="heroicon-o-calendar-days">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <x-fa::field label="Anlass" for="cc-anlass">
                                    <x-fa::input id="cc-anlass" wire:model="form.occasion" placeholder="z. B. Sommerfest" />
                                </x-fa::field>
                                {{-- 4c: Kategorie-Feld abgelöst — Facetten (Servierform/Eventtyp/Momente/Saison) übernehmen --}}
                                <x-fa::field label="Servierform" for="cc-servierform" hint="Bestimmt, welche Darreichung der Gerichte gilt. Wird sofort gespeichert.">
                                    <x-fa::select id="cc-servierform" wire:model="form.serving_form_id" wire:change="speichern">
                                        <option value="">Keine Vorgabe</option>
                                        @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Eventtyp" for="cc-eventtyp" hint="Wird sofort gespeichert.">
                                    <x-fa::select id="cc-eventtyp" wire:model="form.event_type_id" wire:change="speichern">
                                        <option value="">Keine Vorgabe</option>
                                        @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                            </div>
                            <div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)]">
                                <div class="flex flex-col gap-1.5">
                                    <span class="{{ $feldLabel }}">Einsatzmoment</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse($einsatzmomente as $em)
                                            @php $an = in_array($em->id, $form['einsatzmoment_ids'] ?? []); @endphp
                                            <button type="button" wire:key="cc-em-{{ $em->id }}" wire:click="toggleFacette('einsatzmoment_ids', {{ $em->id }})" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                                class="{{ $chip }} {{ $an ? $chipAn : $chipAus }}">@if($an)@svg('heroicon-m-check', 'w-3.5 h-3.5')@endif{{ $em->name }}</button>
                                        @empty
                                            <span class="{{ $hinweis }}">Keine Einsatzmomente gepflegt.</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <span class="{{ $feldLabel }}">Saison</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse($saisons as $sa)
                                            @php $an = in_array($sa->id, $form['saison_ids'] ?? []); @endphp
                                            <button type="button" wire:key="cc-sa-{{ $sa->id }}" wire:click="toggleFacette('saison_ids', {{ $sa->id }})" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                                class="{{ $chip }} {{ $an ? $chipAn : $chipAus }}">@if($an)@svg('heroicon-m-check', 'w-3.5 h-3.5')@endif{{ $sa->name }}</button>
                                        @empty
                                            <span class="{{ $hinweis }}">Keine Saisons gepflegt.</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </x-fa::section>

                        {{-- Phase und Schreibstil --}}
                        <x-fa::section title="Phase und Schreibstil" icon="heroicon-o-flag">
                            {{-- R4.3: Phasen-Statusmaschine (ergänzt den Sichtbarkeits-Status) --}}
                            @include('foodalchemist::livewire.planning.partials.phase-stepper', ['phaseAktuell' => $concept->phase ?? 'kontext'])
                            {{-- Schreibstil (Tonalität) fürs ganze Konzept + KI schreibt je Position Gästetexte (WordingResolver-Kette). --}}
                            <div class="flex flex-wrap items-end gap-3 pt-3 border-t border-[var(--fa-line)]" data-konzept-schreibstil>
                                <x-fa::field label="Schreibstil" for="cc-schreibstil" hint="Wird sofort gespeichert. Die Texte entstehen erst auf Knopfdruck." class="w-full sm:w-72">
                                    <x-fa::select id="cc-schreibstil" wire:model="form.writing_style_id" wire:change="speichern">
                                        <option value="">Neutral, ohne Stil</option>
                                        @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-foodalchemist::ki-action action="wordingGenerieren" variant="ai" icon="heroicon-o-sparkles" label="Gästetexte schreiben"
                                        title="Schreibt für jede Position einen Namen im gewählten Stil und eine Einleitung fürs Konzept"
                                        busy="Schreibt …" flash="Texte da" class="shrink-0 mb-[22px]" data-ki-concept-wording />
                            </div>
                        </x-fa::section>
                    @endif
                </div>
            @endif

            {{-- ── Reiter: AUFBAU (die Positionen) ────────────────────────────────── --}}
            @if($tab === 'aufbau')
                @if($concept)
                    {{-- x-data hält den Drag-Zustand: dragTyp/dragId = Liste → einfügen, dragSlotId = Position umsortieren.
                         bauModus schaltet zwischen Bearbeiten (Tabelle + Quellen-Picker) und Menü (Gäste-Sicht).
                         Alpine statt Livewire: kein Neuaufbau, ungespeicherte Eingaben bleiben. Default = Menü
                         (Dominique 2026-08-13): Konzept öffnet in der Gäste-Sicht, Bearbeiten wird bewusst gewählt. --}}
                    <div class="flex flex-col lg:flex-row gap-4 items-stretch lg:items-start w-full" x-data="{ dragTyp: null, dragId: null, dragSlotId: null, bauModus: false }">
                        {{-- EIN Quellen-Picker (2026-08-24): Gerichte / Pakete / Basisrezepte.
                             Laptop: unter lg über der Tabelle, ab lg links daneben. --}}
                        <aside x-show="bauModus" x-cloak
                               class="w-full lg:w-64 2xl:w-80 shrink-0 flex flex-col gap-2 fa-surface p-3 lg:sticky lg:top-16 lg:self-start max-h-[55vh] lg:max-h-[calc(100vh-18rem)]"
                               data-konzept-quelle-picker data-konzept-basisliste>
                            <div class="{{ $segment }} self-start" role="group" aria-label="Quelle" data-linke-liste-umschalter>
                                <button type="button" wire:click="$set('linkeListe', 'gericht')" class="{{ $segKnopf }} {{ $linkeListe === 'gericht' ? $segAn : $segAus }}">Gerichte</button>
                                @unless($istPaket)<button type="button" wire:click="$set('linkeListe', 'paket')" class="{{ $segKnopf }} {{ $linkeListe === 'paket' ? $segAn : $segAus }}">Pakete</button>@endunless{{-- kein Paket-in-Paket --}}
                                <button type="button" wire:click="$set('linkeListe', 'basisrezept')" class="{{ $segKnopf }} {{ $linkeListe === 'basisrezept' ? $segAn : $segAus }}">Basisrezepte</button>
                            </div>

                            @if($linkeListe === 'gericht')
                                @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'gerichtSuche'])
                                <p class="{{ $hinweis }} tabular-nums">{{ $gerichtListe->count() }} {{ $gerichtListe->count() === 1 ? 'Gericht' : 'Gerichte' }}, mit Plus einfügen oder in die Tabelle ziehen</p>
                                <div class="{{ $liste }}" data-konzept-gerichtliste>
                                    @forelse($gerichtListe as $gr)
                                        <div wire:key="kgr-{{ $gr->id }}" draggable="true" @dragstart="dragTyp = 'gericht'; dragId = {{ $gr->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="{{ $listeZeile }}">
                                            <span class="min-w-0 flex-1 break-words leading-snug text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $gr->name }}">{{ $gr->name }}</span>
                                            <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-3)] tabular-nums">{{ $euro($gr->sales_net) ?? 'kein Preis' }}</span>
                                            <x-fa::icon-button href="#" icon="heroicon-o-eye" label="Gericht ansehen" size="sm" x-on:click.prevent="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $gr->id }} })" />
                                            <x-fa::icon-button icon="heroicon-m-plus" label="Als Position einfügen" size="sm" wire:click="positionEinfuegen('gericht', {{ $gr->id }})" />
                                        </div>
                                    @empty
                                        <p class="{{ $leerText }}">Keine Gerichte für diese Auswahl.</p>
                                    @endforelse
                                </div>
                            @elseif($linkeListe === 'paket')
                                <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="basisSuche" placeholder="Paket suchen …" aria-label="Paket suchen" />
                                <x-fa::select size="sm" wire:model.live="paketKlasse" aria-label="Klasse" data-paket-filter-klasse>
                                    <option value="">Alle Klassen</option>
                                    @foreach($paketKlassenListe as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                                </x-fa::select>
                                {{-- F7b: Facetten-Filter als Auswahllisten (identisch zum Format-Picker) --}}
                                <div class="grid grid-cols-2 gap-1.5">
                                    <x-fa::select size="sm" wire:model.live="paketServierform" aria-label="Servierform" data-paket-filter-servierform>
                                        <option value="">Alle Servierformen</option>
                                        @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                    </x-fa::select>
                                    <x-fa::select size="sm" wire:model.live="paketEventtyp" aria-label="Eventtyp" data-paket-filter-eventtyp>
                                        <option value="">Alle Eventtypen</option>
                                        @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                    </x-fa::select>
                                    <x-fa::select size="sm" wire:model.live="paketMoment" aria-label="Einsatzmoment" data-paket-filter-moment>
                                        <option value="">Alle Momente</option>
                                        @foreach($einsatzmomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                    </x-fa::select>
                                    <x-fa::select size="sm" wire:model.live="paketSaison" aria-label="Saison" data-paket-filter-saison>
                                        <option value="">Alle Saisons</option>
                                        @foreach($saisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                    </x-fa::select>
                                </div>
                                <p class="{{ $hinweis }} tabular-nums">{{ $paketListe->count() }} {{ $paketListe->count() === 1 ? 'Paket' : 'Pakete' }}</p>
                                <div class="{{ $liste }}" data-konzept-paketliste>
                                    @forelse($paketListe as $pk)
                                        <div wire:key="kpk-{{ $pk->id }}" draggable="true" @dragstart="dragTyp = 'paket'; dragId = {{ $pk->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="{{ $listeZeile }}">
                                            <span class="min-w-0 flex-1 break-words leading-snug text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $pk->name }}">{{ $pk->name }}</span>
                                            <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-3)] tabular-nums">{{ $euro($pk->price_per_person_cache) ?? 'kein Preis' }}</span>
                                            <x-fa::icon-button icon="heroicon-m-plus" label="Als Position einfügen" size="sm" wire:click="positionEinfuegen('paket', {{ $pk->id }})" />
                                        </div>
                                    @empty
                                        <p class="{{ $leerText }}">Keine Pakete für diese Auswahl.</p>
                                    @endforelse
                                </div>
                            @else
                                <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="basisSuche" placeholder="Basisrezept suchen …" aria-label="Basisrezept suchen" />
                                <div class="flex flex-col gap-1.5">
                                    <x-fa::select size="sm" wire:model.live="basisHg" aria-label="Hauptgruppe" data-basis-filter-hg>
                                        <option value="">Alle Hauptgruppen</option>
                                        @foreach($basisHauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->label }}</option>@endforeach
                                    </x-fa::select>
                                    <x-fa::select size="sm" wire:model.live="basisKat" aria-label="Kategorie" data-basis-filter-kat :disabled="$basisKategorien->isEmpty()">
                                        <option value="">Alle Kategorien</option>
                                        @foreach($basisKategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->label }}</option>@endforeach
                                    </x-fa::select>
                                    <x-fa::select size="sm" wire:model.live="basisNiveau" aria-label="Niveau" data-basis-filter-niveau>
                                        <option value="">Jedes Niveau</option>
                                        @foreach($basisNiveaus as $n)<option value="{{ $n['slug'] }}">{{ $n['label'] }}</option>@endforeach
                                    </x-fa::select>
                                </div>
                                <p class="{{ $hinweis }} tabular-nums">{{ $basisListe->count() }} {{ $basisListe->count() === 1 ? 'Basisrezept' : 'Basisrezepte' }}</p>
                                <div class="{{ $liste }}">
                                    @forelse($basisListe as $br)
                                        <div wire:key="kbr-{{ $br->id }}" draggable="true" @dragstart="dragTyp = 'basisrezept'; dragId = {{ $br->id }}; $event.dataTransfer.effectAllowed = 'copy'" @dragend="dragTyp = null; dragId = null" class="{{ $listeZeile }}">
                                            <span class="min-w-0 flex-1 break-words leading-snug text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $br->name }}">{{ $br->name }}</span>
                                            <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-3)] tabular-nums">{{ $br->ek_total_eur !== null ? 'EK ' . $euro($br->ek_total_eur) : 'kein EK' }}</span>
                                            <x-fa::icon-button href="#" icon="heroicon-o-book-open" label="Rezept ansehen" size="sm" x-on:click.prevent="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $br->id }} })" />
                                            <x-fa::icon-button icon="heroicon-m-plus" label="Als Position einfügen" size="sm" wire:click="positionEinfuegen('basisrezept', {{ $br->id }})" />
                                        </div>
                                    @empty
                                        <p class="{{ $leerText }}">Keine Basisrezepte für diese Auswahl.</p>
                                    @endforelse
                                </div>
                            @endif
                        </aside>

                        <div class="flex-1 min-w-0 flex flex-col gap-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]" x-text="bauModus ? 'Positionen bearbeiten' : 'So sieht der Gast das Menü'">Positionen bearbeiten</h3>
                                <div class="flex flex-wrap items-center gap-3">
                                    @if($einfuegenNachId !== null)
                                        <span x-show="bauModus" class="inline-flex items-center gap-1.5 {{ $klein }} text-[var(--fa-accent)]" data-einfuege-ziel>
                                            @svg('heroicon-m-map-pin', 'w-4 h-4') Neue Positionen landen unter der markierten Zeile
                                            <x-fa::button size="sm" variant="ghost" wire:click="$set('einfuegenNachId', null)">Ans Ende setzen</x-fa::button>
                                        </span>
                                    @endif
                                    {{-- UX-Umbau 2026-07-03: Umschalter Bearbeiten ⇄ Menü (Gäste-Sicht mit aufgelöstem Wording) --}}
                                    <div class="{{ $segment }}" role="group" aria-label="Ansicht" data-konzept-ansicht-toggle>
                                        {{-- Spec 65: Ansichts-Umschalter als <a role=button> — bleibt im gesperrten Lesemodus (fieldset) bedienbar --}}
                                        <a href="#" role="button" @click.prevent="bauModus = true" :class="bauModus ? '{{ $segAn }}' : '{{ $segAus }}'" class="{{ $segKnopf }}" data-ansicht-bearbeiten>@svg('heroicon-m-adjustments-horizontal', 'w-4 h-4') Bearbeiten</a>
                                        <a href="#" role="button" @click.prevent="bauModus = false" :class="!bauModus ? '{{ $segAn }}' : '{{ $segAus }}'" class="{{ $segKnopf }}" data-ansicht-menue>@svg('heroicon-m-book-open', 'w-4 h-4') Menü</a>
                                    </div>
                                </div>
                            </div>

                            {{-- ═══ MENÜ-ANSICHT (Gäste-Perspektive, nur lesen) ═══ --}}
                            <div x-show="!bauModus" x-cloak class="flex flex-col gap-3" data-konzept-menue>
                                @php
                                    $menueGruppen = [];
                                    $aktuelleGruppe = ['type' => 'sektion', 'title' => null, 'headerSlotId' => null, 'slots' => [], 'texte' => []];
                                    foreach ($concept->slots as $s) {
                                        if (in_array($s->type, ['header', 'header_preis'], true)) {
                                            $menueGruppen[] = $aktuelleGruppe;
                                            $aktuelleGruppe = ['type' => 'header', 'title' => $s->title ?: 'Ohne Überschrift', 'headerSlotId' => $s->id, 'slots' => [], 'texte' => []];
                                        } elseif (($s->embedded_concept_id && $s->embeddedConcept) || ($s->package_id && $s->package)) {
                                            $menueGruppen[] = $aktuelleGruppe;
                                            $_ref = $s->embeddedConcept ?? $s->package;
                                            $_dishes = $s->embeddedConcept ? $s->embeddedConcept->slots->filter(fn ($e) => $e->sales_recipe_id !== null)->values() : $s->package->dishes;
                                            $_preis = $s->embeddedConcept ? $s->embeddedConcept->price_per_person_cache : $s->package->price_per_person;
                                            $menueGruppen[] = ['type' => 'paket', 'title' => $_ref->name, 'price' => $_preis, 'headerSlotId' => null, 'slots' => [], 'texte' => [], 'paket' => $_ref, 'dishes' => $_dishes];
                                            $aktuelleGruppe = ['type' => 'sektion', 'title' => null, 'headerSlotId' => null, 'slots' => [], 'texte' => []];
                                        } elseif ($s->sales_recipe_id && $s->dish) {
                                            $aktuelleGruppe['slots'][] = $s;
                                        } elseif ($s->type === 'text' && trim((string) $s->text_content) !== '') {
                                            // Freitext-Block erscheint als Sektions-Beschreibung in der Gäste-Sicht (Bug-Fix 2026-08-24)
                                            $aktuelleGruppe['texte'][] = $s->text_content;
                                        }
                                    }
                                    $menueGruppen[] = $aktuelleGruppe;
                                    // Gäste-Sicht: leere Gruppen unsichtbar — reine Text-Sektionen (Beschreibung ohne Gericht) bleiben sichtbar
                                    $menueGruppen = collect($menueGruppen)->filter(fn ($g) => $g['type'] === 'paket' ? ($g['dishes'] ?? collect())->isNotEmpty() : (count($g['slots']) > 0 || count($g['texte'] ?? []) > 0))->values();
                                    // Herkunft des Gästetexts: [Text, Ton]
                                    $quelleBadge = ['konzept' => ['Konzept-Wording', 'accent'], 'standard' => ['Standard-Wording', 'neutral'], 'name' => ['Wording fehlt', 'warn']];
                                    $wres = app(\Platform\FoodAlchemist\Services\WordingResolver::class);
                                @endphp

                                @forelse($menueGruppen as $g)
                                    @php
                                        $slotEks = collect($g['slots'])->map(fn ($sx) => $cockpitZeilen[$sx->id]['ek'] ?? null)->filter();
                                        $slotVks = collect($g['slots'])->map(fn ($sx) => $cockpitZeilen[$sx->id]['price'] ?? null)->filter();
                                        $anzahl = $g['type'] === 'paket' ? $g['dishes']->count() : count($g['slots']);
                                    @endphp
                                    <section wire:key="menue-{{ $loop->index }}" class="flex flex-col gap-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3">
                                        <header class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                            @if($g['type'] === 'paket')
                                                <x-fa::badge tone="info" icon="heroicon-m-archive-box">Paket</x-fa::badge>
                                                <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $g['title'] }}</h4>
                                                <span class="ml-auto {{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $anzahl }} Posten{{ $g['price'] !== null ? ', ' . $euro($g['price']) . ' je Person' : '' }}</span>
                                            @elseif($g['title'])
                                                <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $g['title'] }}</h4>
                                                <span class="ml-auto {{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $anzahl }} {{ $anzahl === 1 ? 'Position' : 'Positionen' }}{{ (! $istPaket && $slotVks->isNotEmpty()) ? ', ' . $euro($slotVks->sum()) . ' je Person' : '' }}{{ $slotEks->isNotEmpty() ? ', EK ' . $euro($slotEks->sum()) : '' }}</span>
                                            @else
                                                <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink-2)]">Gerichte</h4>
                                                <span class="ml-auto {{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $anzahl }} {{ $anzahl === 1 ? 'Position' : 'Positionen' }}{{ (! $istPaket && $slotVks->isNotEmpty()) ? ', ' . $euro($slotVks->sum()) . ' je Person' : '' }}</span>
                                            @endif
                                        </header>
                                        @if(! empty($g['texte'] ?? []))
                                            {{-- Freitext-Blöcke der Sektion als Beschreibung (Gäste-Sicht) --}}
                                            <div class="flex flex-col gap-1 -mt-1" data-konzept-menue-text>
                                                @foreach($g['texte'] as $tx)
                                                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] italic leading-snug">{{ $tx }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="grid gap-2.5 grid-cols-[repeat(auto-fill,minmax(min(15rem,100%),1fr))]">
                                            @if($g['type'] === 'paket')
                                                @foreach($g['dishes'] as $pg)
                                                    @php
                                                        $pgG = $pg->dish;
                                                        $pw = $wres->fuerGericht($pgG);
                                                        [$qbText, $qbTon] = $quelleBadge[$pw['source']] ?? $quelleBadge['name'];
                                                        $pgEnthaelt = collect(['Schwein' => $pgG?->spec_contains_pork, 'Rind' => $pgG?->spec_contains_beef])->filter()->keys()->all();
                                                    @endphp
                                                    <article wire:key="mpcard-{{ $pg->id }}" class="fa-surface flex flex-col gap-2 px-3.5 py-3">
                                                        <div class="flex items-start justify-between gap-2">
                                                            <x-fa::badge :tone="$qbTon">{{ $qbText }}</x-fa::badge>
                                                            @if($pg->sales_recipe_id)<x-fa::icon-button href="#" icon="heroicon-o-eye" label="Gericht öffnen" size="sm" x-on:click.prevent="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" />@endif
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="text-[length:var(--fa-text-base)] font-semibold leading-snug {{ $pw['source'] === 'name' ? 'italic text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}">{{ $pw['text'] }}</p>
                                                            <p class="{{ $klein }} text-[var(--fa-ink-3)] break-words mt-0.5" title="Interner Name">{{ $pgG?->name }}</p>
                                                        </div>
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            @if($pgG?->spec_is_vegan)<x-fa::badge tone="ok">vegan</x-fa::badge>@elseif($pgG?->spec_is_vegetarian)<x-fa::badge tone="ok">vegetarisch</x-fa::badge>@endif
                                                            @if(count($pgEnthaelt))<x-fa::badge tone="warn">enthält {{ implode(', ', $pgEnthaelt) }}</x-fa::badge>@endif
                                                            @if($pw['source'] === 'name')<x-fa::button href="#" size="sm" variant="ghost" icon="heroicon-m-pencil" x-on:click.prevent="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" title="Wording am Gericht ergänzen">Wording ergänzen</x-fa::button>@endif
                                                        </div>
                                                        <dl class="flex gap-4 pt-2 border-t border-[var(--fa-line)] tabular-nums">
                                                            <div class="flex flex-col"><dt class="{{ $kopfzelle }}">VK je Person</dt><dd class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink-2)]">im Paketpreis</dd></div>
                                                            <div class="flex flex-col"><dt class="{{ $kopfzelle }}">EK</dt><dd class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]"><x-fa::money :value="$pgG?->ek_total_eur" missing="EK fehlt" /></dd></div>
                                                        </dl>
                                                    </article>
                                                @endforeach
                                            @else
                                                @foreach($g['slots'] as $s)
                                                    @php
                                                        $g0 = $s->dish;
                                                        $w = $slotWording[$s->id] ?? ['text' => $g0->name, 'source' => 'name'];
                                                        [$qbText, $qbTon] = $quelleBadge[$w['source']] ?? $quelleBadge['name'];
                                                        $enthaelt = collect(['Schwein' => $g0->spec_contains_pork, 'Rind' => $g0->spec_contains_beef])->filter()->keys()->all();
                                                        $ekz = $cockpitZeilen[$s->id]['ek'] ?? null;
                                                        $vkz = $cockpitZeilen[$s->id]['price'] ?? null;
                                                        $wpct = ($vkz && (float) $vkz > 0 && $ekz !== null) ? ((float) $ekz / (float) $vkz * 100) : null;
                                                    @endphp
                                                    <article wire:key="mcard-{{ $s->id }}" class="fa-surface flex flex-col gap-2 px-3.5 py-3">
                                                        <div class="flex items-start justify-between gap-2">
                                                            <x-fa::badge :tone="$qbTon">{{ $qbText }}</x-fa::badge>
                                                            <x-fa::icon-button href="#" icon="heroicon-o-eye" label="Gericht öffnen" size="sm" x-on:click.prevent="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $s->sales_recipe_id }} })" />
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="text-[length:var(--fa-text-base)] font-semibold leading-snug {{ $w['source'] === 'name' ? 'italic text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}">{{ $w['text'] }}</p>
                                                            <p class="{{ $klein }} text-[var(--fa-ink-3)] break-words mt-0.5" title="Interner Name">{{ $g0->name }}</p>
                                                        </div>
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            @if(isset($darreichungInfo[$s->id]))
                                                                <x-fa::badge :tone="str_starts_with($darreichungInfo[$s->id], 'Standard:') ? 'neutral' : 'accent'" icon="heroicon-m-rectangle-stack">{{ $darreichungInfo[$s->id] }}</x-fa::badge>
                                                            @endif
                                                            @if($g0->dishClass)<x-fa::badge>{{ $g0->dishClass->label }}</x-fa::badge>@endif
                                                            @if($g0->spec_is_vegan)<x-fa::badge tone="ok">vegan</x-fa::badge>@elseif($g0->spec_is_vegetarian)<x-fa::badge tone="ok">vegetarisch</x-fa::badge>@endif
                                                            @if(count($enthaelt))<x-fa::badge tone="warn">enthält {{ implode(', ', $enthaelt) }}</x-fa::badge>@endif
                                                            @if(isset($varianteFehlt[$s->id]))<x-fa::button size="sm" variant="ghost" icon="heroicon-m-exclamation-triangle" wire:click="varianteAnlegen({{ $s->id }})" title="Die Servierform des Konzepts fehlt als Darreichung am Gericht">Darreichung anlegen</x-fa::button>@endif
                                                            @if($w['source'] === 'name')<x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil" x-on:click="bauModus = true" title="In der Bearbeiten-Ansicht Wording ergänzen">Wording ergänzen</x-fa::button>@endif
                                                        </div>
                                                        <dl class="flex gap-4 pt-2 border-t border-[var(--fa-line)] tabular-nums">
                                                            {{-- Im Paket ist der Einzel-VK je Speise irreführend (ein Paketpreis) → nur EK zeigen. --}}
                                                            @unless($istPaket)<div class="flex flex-col"><dt class="{{ $kopfzelle }}">VK je Person</dt><dd class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]"><x-fa::money :value="$vkz" /></dd></div>@endunless
                                                            <div class="flex flex-col"><dt class="{{ $kopfzelle }}">EK</dt><dd class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]"><x-fa::money :value="$ekz" missing="EK fehlt" /></dd></div>
                                                            @unless($istPaket)<div class="flex flex-col"><dt class="{{ $kopfzelle }}">Wareneinsatz</dt><dd class="text-[length:var(--fa-text-md)] font-semibold {{ $wpct !== null && $wpct > (float) $zielWareneinsatzPct ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ink)]' }}">{{ $wpct !== null ? number_format($wpct, 1, ',', '.') . ' %' : 'ohne VK' }}</dd></div>@endunless
                                                        </dl>
                                                    </article>
                                                @endforeach
                                            @endif
                                        </div>
                                    </section>
                                @empty
                                    <x-fa::empty icon="heroicon-o-book-open" title="Noch keine Gerichte im Konzept">
                                        In die Bearbeiten-Ansicht wechseln und links aus Gerichten, Paketen oder Basisrezepten einfügen.
                                        <x-slot:action><x-fa::button size="sm" icon="heroicon-m-adjustments-horizontal" x-on:click="bauModus = true">Positionen bearbeiten</x-fa::button></x-slot:action>
                                    </x-fa::empty>
                                @endforelse

                                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pt-2 border-t border-[var(--fa-line)] {{ $klein }} text-[var(--fa-ink-2)]">
                                    <x-fa::badge tone="accent">Konzept-Wording</x-fa::badge>
                                    <x-fa::badge>Standard-Wording</x-fa::badge>
                                    <x-fa::badge tone="warn">Wording fehlt, der interne Name steht da</x-fa::badge>
                                    <span class="sm:ml-auto text-[var(--fa-ink-3)]">Reihenfolge: Foodbook, dann Konzept, dann Gericht-Standard, sonst interner Name</span>
                                </div>
                            </div>

                            {{-- ═══ BEARBEITEN-ANSICHT (Tabelle + Struktur + Paket bilden) ═══ --}}
                            <div x-show="bauModus" x-cloak class="flex flex-col gap-3">
                                {{-- Kombi-Suche (wie Gerichte-Editor): filtert die Listen im Quellen-Picker. --}}
                                <x-fa::input type="search" wire:model.live.debounce.300ms="kombiSuche" data-konzept-kombisuche
                                       placeholder="Gerichte, Pakete und Basisrezepte im Picker filtern …" aria-label="Im Quellen-Picker suchen" />
                                {{-- B3: Struktur-Blöcke (freie Gliederung OHNE Paket) + Paket als bepreister Abschnitt --}}
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="{{ $feldLabel }}">Gliederung einfügen</span>
                                    @unless($istPaket)<x-fa::button size="sm" icon="heroicon-m-archive-box" wire:click="neuesPaketAlsPosition" title="Neues Paket als Abschnitt anlegen, einfügen und direkt öffnen">Paket erstellen</x-fa::button>@endunless{{-- kein Paket-in-Paket --}}
                                    <x-fa::button size="sm" icon="heroicon-m-h1" wire:click="blockHinzu('header')">Überschrift</x-fa::button>
                                    <x-fa::button size="sm" icon="heroicon-m-bars-3-bottom-left" wire:click="blockHinzu('text')">Text</x-fa::button>
                                    <x-fa::button size="sm" icon="heroicon-m-minus" wire:click="blockHinzu('spacer')">Leerzeile</x-fa::button>
                                </div>
                                {{-- B4: aus markierten Gericht-/Basisrezept-Positionen ein Paket bilden --}}
                                @if(count($auswahl) > 0)
                                    <div class="flex flex-wrap items-center gap-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2" data-paket-bilden>
                                        <span class="{{ $klein }} font-medium text-[var(--fa-accent)] shrink-0">{{ count($auswahl) }} {{ count($auswahl) === 1 ? 'Position' : 'Positionen' }} markiert</span>
                                        <x-fa::input wire:model="paketName" wire:keydown.enter="paketBilden" placeholder="Name des Pakets, z. B. Grill-Hauptgang" class="flex-1 min-w-[12rem]" aria-label="Name des Pakets" />
                                        <x-fa::button icon="heroicon-m-archive-box" wire:click="paketBilden">Paket bilden</x-fa::button>
                                        <x-fa::button variant="ghost" wire:click="$set('auswahl', [])">Abbrechen</x-fa::button>
                                    </div>
                                @endif

                                <div class="overflow-x-auto fa-surface">
                                    <table class="fa-table fa-table--compact">
                                        <thead><tr>
                                            <th class="w-px"><span class="sr-only">Reihenfolge</span></th>
                                            <th class="w-px">Menge</th>
                                            <th>Position</th>
                                            <th class="w-px">Rolle</th>
                                            <th class="w-px text-right" title="Verkaufspreis je Person">VK</th>
                                            <th class="w-px text-right" title="Einkauf je Person">EK</th>
                                            <th class="w-px text-right" title="Wareneinsatz in Prozent vom VK">Einsatz</th>
                                            <th class="w-px"><span class="sr-only">Aktionen</span></th>
                                        </tr></thead>
                                        <tbody>
                                        @forelse($concept->slots as $slot)
                                            @php
                                                $istStruktur = in_array($slot->type, ['text', 'spacer', 'header', 'header_preis']);
                                                $istAbschnitt = (bool) ($slot->package_id || $slot->embedded_concept_id);
                                                $ekz = $cockpitZeilen[$slot->id]['ek'] ?? null;
                                                $vkz = $cockpitZeilen[$slot->id]['price'] ?? null;
                                                $wpct = ($vkz && (float) $vkz > 0 && $ekz !== null) ? ((float) $ekz / (float) $vkz * 100) : null;
                                                $istZiel = $einfuegenNachId === $slot->id;
                                            @endphp
                                            <tr wire:key="erow-{{ $slot->id }}"
                                                @dragover.prevent
                                                @drop.prevent="if (dragId) { $wire.positionDrop(dragTyp, dragId, {{ $slot->id }}); } else if (dragSlotId && dragSlotId !== {{ $slot->id }}) { $wire.positionVerschieben(dragSlotId, {{ $slot->id }}); } dragTyp = null; dragId = null; dragSlotId = null"
                                                class="{{ $istStruktur ? 'bg-[var(--fa-ground)]' : '' }} {{ $istAbschnitt ? 'bg-[var(--fa-info-soft)]' : '' }} {{ $istZiel ? 'shadow-[inset_0_-2px_0_var(--fa-accent)]' : '' }}"
                                                @if($istZiel) aria-current="true" @endif>
                                                <td class="align-top whitespace-nowrap">
                                                    <div class="flex items-center gap-0.5">
                                                        {{-- Ziehgriff: Position per Drag umsortieren (Pfeile bleiben als zuverlässige Alternative) --}}
                                                        <span class="inline-flex items-center justify-center w-6 h-7 cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] select-none" draggable="true"
                                                              @dragstart="dragSlotId = {{ $slot->id }}; $event.dataTransfer.effectAllowed = 'move'" @dragend="dragSlotId = null" title="Ziehen zum Umsortieren">@svg('heroicon-m-bars-3', 'w-4 h-4')</span>
                                                        <span class="inline-flex flex-col">
                                                            <button type="button" wire:click="slotHoch({{ $slot->id }})" class="text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach oben" aria-label="Nach oben">@svg('heroicon-m-chevron-up', 'w-4 h-4')</button>
                                                            <button type="button" wire:click="slotRunter({{ $slot->id }})" class="text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach unten" aria-label="Nach unten">@svg('heroicon-m-chevron-down', 'w-4 h-4')</button>
                                                        </span>
                                                        @if(! $istStruktur && $slot->sales_recipe_id)
                                                            <input type="checkbox" wire:click="toggleAuswahl({{ $slot->id }})" @checked(in_array($slot->id, $auswahl)) class="ml-1 w-4 h-4 accent-[var(--fa-accent)]" title="Für „Paket bilden“ markieren" aria-label="Für Paket bilden markieren" />
                                                        @endif
                                                    </div>
                                                </td>
                                                @if($istStruktur)
                                                    <td colspan="6" class="align-top">
                                                        @if($slot->type === 'spacer')
                                                            <div class="flex items-center gap-2">
                                                                <x-fa::badge>Leerzeile</x-fa::badge>
                                                                <x-fa::select size="sm" wire:model="blockForm.{{ $slot->id }}.height" wire:change="blockSpeichern({{ $slot->id }})" class="w-32" aria-label="Höhe der Leerzeile">
                                                                    @foreach(['klein' => 'klein', 'mittel' => 'mittel', 'gross' => 'groß'] as $h => $hl)<option value="{{ $h }}">{{ $hl }}</option>@endforeach
                                                                </x-fa::select>
                                                            </div>
                                                        @elseif($slot->type === 'text')
                                                            <div class="flex flex-col gap-1.5">
                                                                <x-fa::badge class="self-start">Text</x-fa::badge>
                                                                <x-fa::input size="sm" wire:model.blur="blockForm.{{ $slot->id }}.text_content" wire:change="blockSpeichern({{ $slot->id }})" placeholder="Freier Text für den Gast …" aria-label="Freier Text" />
                                                            </div>
                                                        @else
                                                            @php $ss = $sektionSumme['h' . $slot->id] ?? null; @endphp
                                                            <div class="flex flex-col gap-1.5">
                                                                <x-fa::badge tone="info" class="self-start">{{ $slot->type === 'header_preis' ? 'Überschrift mit Preis' : 'Überschrift' }}</x-fa::badge>
                                                                <x-fa::input size="sm" wire:model.blur="blockForm.{{ $slot->id }}.title" wire:change="blockSpeichern({{ $slot->id }})" class="font-medium" placeholder="Überschrift …" aria-label="Überschrift" />
                                                                @if($ss && $ss['n'] > 0)
                                                                    <span class="{{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $ss['n'] }} {{ $ss['n'] === 1 ? 'Position' : 'Positionen' }}, EK {{ $euro($ss['ek']) }}{{ $istPaket ? '' : ', ' . $euro($ss['vk']) . ' je Person' }}</span>
                                                                @endif
                                                                @if($slot->type === 'header_preis')
                                                                    <span class="inline-flex items-center gap-1.5">
                                                                        <x-fa::input size="sm" numeric type="number" step="0.01" min="0" wire:model.blur="blockForm.{{ $slot->id }}.price_value" wire:change="blockSpeichern({{ $slot->id }})" class="w-24" placeholder="€" aria-label="Preis" />
                                                                        <x-fa::select size="sm" wire:model="blockForm.{{ $slot->id }}.price_basis" wire:change="blockSpeichern({{ $slot->id }})" class="w-32" aria-label="Preisbasis">
                                                                            @foreach(['person' => 'je Person', 'pauschal' => 'pauschal', 'staffel' => 'Staffel'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                                                        </x-fa::select>
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                @else
                                                    <td class="align-top">
                                                        @if($slot->sales_recipe_id)
                                                            <div class="flex items-center gap-1">
                                                                <x-fa::input size="sm" numeric wire:model.blur="slotForm.{{ $slot->id }}.quantity" wire:change="mengeSpeichern({{ $slot->id }})" class="w-14" placeholder="1" aria-label="Menge" />
                                                                <x-fa::select size="sm" wire:model="slotForm.{{ $slot->id }}.unit_vocab_id" wire:change="mengeSpeichern({{ $slot->id }})" class="w-24" aria-label="Einheit">
                                                                    <option value="">Einheit</option>
                                                                    @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                                                                </x-fa::select>
                                                            </div>
                                                        @endif
                                                    </td>
                                                    <td class="align-top min-w-[16rem]">
                                                        @php
                                                            $epk = $slot->embeddedConcept ?? $slot->package;
                                                            $epkOpenId = $slot->embedded_concept_id ?? $slot->package_id;
                                                            $epkPreis = $slot->embeddedConcept ? $slot->embeddedConcept->price_per_person_cache : ($slot->package?->price_per_person);
                                                        @endphp
                                                        @if($epk)
                                                            {{-- Paket = Abschnitt (kind=paket-Concept oder Alt-Package; Gerichte eingerückt darunter) --}}
                                                            <div class="flex flex-wrap items-center gap-1.5">
                                                                <x-fa::badge tone="info" icon="heroicon-m-archive-box">Paket</x-fa::badge>
                                                                <span class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)] break-words">{{ $epk->name }}</span>
                                                                @if($epk->class ?? null)<x-fa::badge>{{ $epk->class }}</x-fa::badge>@endif
                                                                @if($epkPreis !== null)<span class="{{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $euro($epkPreis) }} je Person</span>@endif
                                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-top-right-on-square" href="#" x-on:click.prevent="" wire:click="paketOeffnen({{ $epkOpenId }})">Paket öffnen</x-fa::button>
                                                            </div>
                                                        @elseif($slot->sales_recipe_id && $slot->dish)
                                                            @php
                                                                $g = $slot->dish;
                                                                $istBasisPos = $slot->type === 'basisrezept';
                                                                $enthaelt = collect(['Schwein' => $g->spec_contains_pork, 'Rind' => $g->spec_contains_beef])->filter()->keys()->all();
                                                            @endphp
                                                            <div class="flex flex-col gap-1.5">
                                                                <div class="flex flex-wrap items-center gap-1.5">
                                                                    <span class="inline-flex items-center h-[22px] px-2 rounded-full {{ $klein }} font-medium bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]" style="{{ $typStyle($istBasisPos ? 'basisrezept' : 'gericht') }}">{{ $istBasisPos ? 'Basisrezept' : 'Gericht' }}</span>
                                                                    <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] break-words">{{ $g->name }}</span>
                                                                    {{-- Phase 6: ansehen — Basisrezept → Rezept-Fenster, VK-Gericht → Gericht-Fenster (über dem Editor) --}}
                                                                    <x-fa::icon-button href="#" :icon="$istBasisPos ? 'heroicon-o-book-open' : 'heroicon-o-eye'" :label="$istBasisPos ? 'Rezept ansehen' : 'Gericht ansehen'" size="sm"
                                                                        x-on:click.prevent="Livewire.dispatch('{{ $istBasisPos ? 'recipe-modal' : 'vk-modal' }}.oeffnen', { id: {{ $slot->sales_recipe_id }} })" />
                                                                    {{-- R4.4: Zutaten lesen + konzept-lokale Variante --}}
                                                                    <a href="#" role="button" wire:click.prevent="zutatenToggle({{ $slot->id }})" aria-pressed="{{ $zutatenOffenSlotId === $slot->id ? 'true' : 'false' }}"
                                                                        class="inline-flex items-center gap-1 h-7 px-2 rounded-[var(--fa-radius-control)] {{ $klein }} font-medium {{ $zutatenOffenSlotId === $slot->id ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]' }}"
                                                                        title="Zutaten zeigen. Ein Tausch erzeugt eine Variante nur für dieses Konzept, das Gericht selbst bleibt unverändert." data-slot-zutaten-toggle>@svg('heroicon-m-list-bullet', 'w-4 h-4') Zutaten</a>
                                                                </div>
                                                                <div class="flex flex-wrap items-center gap-1.5">
                                                                    @if($g->dishClass)<x-fa::badge>{{ $g->dishClass->label }}</x-fa::badge>@endif
                                                                    @if(isset($darreichungInfo[$slot->id]))
                                                                        <x-fa::badge :tone="str_starts_with($darreichungInfo[$slot->id], 'Standard:') ? 'neutral' : 'accent'" icon="heroicon-m-rectangle-stack"
                                                                              title="Gültige Darreichung dieser Position: eigene Wahl, sonst Servierform des Konzepts, sonst Standard" data-darreichung-pill>{{ $darreichungInfo[$slot->id] }}</x-fa::badge>
                                                                    @endif
                                                                    @if(isset($darreichungOptionen[$slot->id]))
                                                                        {{-- A1: eigene Form nur für diese Position (automatisch = Konzept-Form/Standard) --}}
                                                                        <select wire:change="slotDarreichungSetzen({{ $slot->id }}, $event.target.value)"
                                                                                class="fa-control fa-select pr-8 h-7 w-auto {{ $klein }}" data-slot-form-picker aria-label="Darreichung dieser Position"
                                                                                title="Form dieser Position festlegen. Automatisch folgt der Servierform des Konzepts bzw. dem Standard.">
                                                                            <option value="" @selected($slot->presentation_id === null)>Form automatisch</option>
                                                                            @foreach($darreichungOptionen[$slot->id] as $opt)
                                                                                <option value="{{ $opt['id'] }}" @selected((int) $slot->presentation_id === (int) $opt['id'])>{{ $opt['label'] }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                    @endif
                                                                    @if($g->spec_is_vegan)<x-fa::badge tone="ok">vegan</x-fa::badge>@elseif($g->spec_is_vegetarian)<x-fa::badge tone="ok">vegetarisch</x-fa::badge>@endif
                                                                    @if(count($enthaelt))<x-fa::badge tone="warn" title="Konfidenz der Allergene: {{ $konfText($g->allergens_confidence) }}">enthält {{ implode(', ', $enthaelt) }}</x-fa::badge>@endif
                                                                    @if($slot->variant_source_recipe_id !== null)
                                                                        <x-fa::badge tone="warn" title="Variante nur für dieses Konzept, das Original-Gericht ist unverändert" data-slot-variiert>Variante</x-fa::badge>
                                                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-uturn-left" wire:click="slotVarianteZuruecksetzen({{ $slot->id }})" wire:confirm="Variante verwerfen und das Original-Gericht wiederherstellen?" data-slot-variante-reset>Original wiederherstellen</x-fa::button>
                                                                    @endif
                                                                    {{-- Umbau-Spec Phase 5: Konzept-Servierform ohne passende Darreichung → mit einem Klick anlegen --}}
                                                                    @if(isset($varianteFehlt[$slot->id]))
                                                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-exclamation-triangle" wire:click="varianteAnlegen({{ $slot->id }})" data-variante-fehlt
                                                                                title="Am Gericht fehlt die Darreichung „{{ $concept->servingForm?->label }}“. Der Klick legt sie aus der Standard-Form an, danach die Grammatur prüfen.">{{ $concept->servingForm?->label }} anlegen</x-fa::button>
                                                                    @endif
                                                                </div>
                                                                {{-- Konzept-Wording: Name im Stil des Konzepts (leer = Standardname; „Gästetexte schreiben" füllt alle) --}}
                                                                <x-fa::input size="sm" wire:model.blur="slotForm.{{ $slot->id }}.wording" wire:change="wordingSpeichern({{ $slot->id }})" class="italic" placeholder="Name für Gäste in diesem Konzept, leer = „{{ $g->name }}“" aria-label="Name für Gäste" data-slot-wording />
                                                            </div>
                                                        @else
                                                            <div class="flex flex-col gap-1">
                                                                <span class="{{ $klein }} text-[var(--fa-ink-2)]">Leere Position. Links ein Gericht wählen oder unten befüllen.</span>
                                                                @if($slot->note)
                                                                    {{-- R6.1: Begründung der Erstellung, warum die Position bewusst leer blieb --}}
                                                                    <x-fa::signal tone="warn" data-slot-leer-begruendung>{{ $slot->note }}</x-fa::signal>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                    <td class="align-top"><x-fa::input size="sm" wire:model.blur="slotForm.{{ $slot->id }}.role" wire:change="slotSpeichern({{ $slot->id }})" class="w-24" placeholder="Rolle" aria-label="Rolle" /></td>
                                                    {{-- Im Paket kein Einzel-VK/Einsatz je Position (ein Paketpreis); EK bleibt als Kostenbasis. --}}
                                                    <td class="num align-top">@unless($istPaket)@if($vkz !== null){{ $euro($vkz) }}@else<span class="text-[var(--fa-ink-3)]">offen</span>@endif @endunless</td>
                                                    <td class="num align-top">@if($ekz !== null){{ $euro($ekz) }}@else<span class="text-[var(--fa-ink-3)]">offen</span>@endif</td>
                                                    <td class="num align-top text-[var(--fa-ink-2)]">@unless($istPaket){{ $wpct !== null ? number_format($wpct, 1, ',', '.') . ' %' : '' }}@endunless</td>
                                                @endif
                                                <td class="align-top whitespace-nowrap">
                                                    <div class="flex items-center justify-end gap-0.5">
                                                        @if(! $istStruktur)
                                                            <label class="inline-flex items-center gap-1 mr-1 {{ $klein }} text-[var(--fa-ink-2)]" title="Pflicht-Position">
                                                                <input type="checkbox" wire:model="slotForm.{{ $slot->id }}.is_pflicht" wire:change="slotSpeichern({{ $slot->id }})" class="w-4 h-4 accent-[var(--fa-accent)]" />Pflicht
                                                            </label>
                                                            <x-fa::icon-button icon="heroicon-m-adjustments-horizontal" label="Befüllung ändern" size="sm" wire:click="fillToggle({{ $slot->id }})" />
                                                        @endif
                                                        <button type="button" wire:click="zielSetzen({{ $slot->id }})" aria-pressed="{{ $istZiel ? 'true' : 'false' }}"
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-[var(--fa-radius-control)] {{ $istZiel ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                                                            title="{{ $istZiel ? 'Einfügeziel aktiv: neue Positionen landen darunter. Klick hebt es auf.' : 'Hier einfügen: die nächste neue Position landet unter dieser Zeile' }}"
                                                            aria-label="Einfügeziel setzen">@svg('heroicon-m-map-pin', 'w-4 h-4')</button>
                                                        <x-fa::icon-button icon="heroicon-m-x-mark" label="Position entfernen" size="sm" tone="danger" wire:click="slotRaus({{ $slot->id }})" />
                                                    </div>
                                                </td>
                                            </tr>
                                            {{-- Paket-Position = Abschnitt: seine Gerichte stehen immer schreibgeschützt eingerückt darunter --}}
                                            @if($slot->embeddedConcept || ($slot->package_id && $slot->package))
                                                <tr wire:key="epaket-{{ $slot->id }}">
                                                    <td></td>
                                                    <td colspan="7" class="align-top">
                                                        <div class="ml-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]">
                                                            @if($slot->embeddedConcept)
                                                                {{-- Kaskade: eingebettetes Paket = kind=paket-Concept → seine Posten --}}
                                                                @forelse($slot->embeddedConcept->slots->filter(fn ($e) => $e->sales_recipe_id !== null) as $eps)
                                                                    <div wire:key="epaketc-{{ $slot->id }}-{{ $eps->id }}" class="flex items-center gap-2 px-3 py-1.5 text-[length:var(--fa-text-md)]">
                                                                        @if($eps->type === 'basisrezept')<x-fa::badge style="{{ $typStyle('basisrezept') }}">Basisrezept</x-fa::badge>@endif
                                                                        <span class="flex-1 min-w-0 break-words leading-snug text-[var(--fa-ink)]">{{ $eps->dish?->name ?? 'Gericht fehlt' }}</span>
                                                                        @if($eps->quantity !== null)<span class="shrink-0 {{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $menge($eps->quantity) }} ×</span>@endif
                                                                        <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-2)] tabular-nums w-20 text-right">{{ $euro($eps->dish?->sales_net) }}</span>
                                                                        @if($eps->sales_recipe_id)<x-fa::icon-button href="#" icon="heroicon-o-eye" label="Gericht ansehen" size="sm" x-on:click.prevent="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $eps->sales_recipe_id }} })" />@endif
                                                                    </div>
                                                                @empty
                                                                    <p class="px-3 py-2 {{ $hinweis }}">Paket ohne Posten. Im Paket-Editor pflegen.</p>
                                                                @endforelse
                                                            @else
                                                                @forelse($slot->package->dishes as $pg)
                                                                    <div wire:key="epaketg-{{ $slot->id }}-{{ $pg->id }}" class="flex items-center gap-2 px-3 py-1.5 text-[length:var(--fa-text-md)]">
                                                                        <span class="flex-1 min-w-0 break-words leading-snug text-[var(--fa-ink)]">{{ $pg->dish?->name ?? 'Gericht fehlt' }}</span>
                                                                        @if($pg->quantity !== null)<span class="shrink-0 {{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $menge($pg->quantity) }} ×</span>@endif
                                                                        <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-2)] tabular-nums w-20 text-right">{{ $euro($pg->dish?->sales_net) }}</span>
                                                                        @if($pg->sales_recipe_id)<x-fa::icon-button href="#" icon="heroicon-o-eye" label="Gericht ansehen" size="sm" x-on:click.prevent="Livewire.dispatch('vk-modal.oeffnen', { id: {{ $pg->sales_recipe_id }} })" />@endif
                                                                    </div>
                                                                @empty
                                                                    <p class="px-3 py-2 {{ $hinweis }}">Paket ohne Gerichte. Im Paket-Editor pflegen.</p>
                                                                @endforelse
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                            {{-- R4.4: Zutaten des Gerichts (erst lesen) — Tausch läuft IMMER über die Variante der Position --}}
                                            @if($zutatenOffenSlotId === $slot->id && $slot->sales_recipe_id !== null)
                                                <tr wire:key="ezutaten-{{ $slot->id }}">
                                                    <td></td>
                                                    <td colspan="7" class="align-top">
                                                        <div class="ml-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]" data-slot-zutaten>
                                                            @forelse($slotZutaten as $z)
                                                                <div wire:key="ezutat-{{ $slot->id }}-{{ $z['id'] }}" class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-[length:var(--fa-text-md)]">
                                                                    <span class="flex-1 min-w-[10rem] break-words text-[var(--fa-ink)]">{{ $z['name'] }}</span>
                                                                    <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $z['menge'] }}</span>
                                                                    @if($z['swap_locked'])
                                                                        <x-fa::badge icon="heroicon-m-lock-closed" title="Bewusst gewählt, nicht tauschbar">gesperrt</x-fa::badge>
                                                                    @elseif($z['ersatz'] !== null)
                                                                        <x-fa::button size="sm" icon="heroicon-m-arrow-path" wire:click="slotZutatTauschen({{ $slot->id }}, {{ $z['id'] }})"
                                                                                title="Nur in diesem Konzept tauschen. Das Original-Gericht bleibt unverändert." data-slot-zutat-tausch>Tauschen gegen {{ $z['ersatz'] }}</x-fa::button>
                                                                    @endif
                                                                    @if($z['peek_recipe_id'] !== null)
                                                                        <x-fa::icon-button href="#" icon="heroicon-o-book-open" label="Unterrezept ansehen" size="sm" x-on:click.prevent="Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $z['peek_recipe_id'] }} })" />
                                                                    @endif
                                                                </div>
                                                            @empty
                                                                <p class="px-3 py-2 {{ $hinweis }}">Dieses Gericht hat keine Zutaten.</p>
                                                            @endforelse
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                            @if(! $istStruktur && ($fillOpenId === $slot->id || (! $slot->package_id && ! $slot->embedded_concept_id && ! $slot->sales_recipe_id)))
                                                <tr wire:key="efill-{{ $slot->id }}">
                                                    <td></td>
                                                    <td colspan="7" class="align-top bg-[var(--fa-ground)]">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <select x-on:change="$wire.fuellePaket({{ $slot->id }}, $event.target.value); $event.target.value=''" class="fa-control fa-select pr-8 h-7 w-60 {{ $klein }}" aria-label="Paket tauschen">
                                                                <option value="">Gegen ein Paket tauschen …</option>
                                                                @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                                                    <option value="{{ $b->id }}">{{ $b->name }}{{ $b->price_per_person_cache !== null ? ' (' . $euro($b->price_per_person_cache) . ')' : '' }}</option>
                                                                @endforeach
                                                            </select>
                                                            <x-fa::button size="sm" icon="heroicon-m-magnifying-glass" wire:click="gerichtPicker({{ $slot->id }})">Gericht oder Basisrezept wählen</x-fa::button>
                                                            <x-fa::button size="sm" icon="heroicon-m-archive-box" wire:click="neuesPaketImSlot({{ $slot->id }})" title="Direkt hier ein neues Paket schnüren">Neues Paket schnüren</x-fa::button>
                                                            @if($slot->package_id || $slot->embedded_concept_id || $slot->sales_recipe_id)
                                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="slotLeeren({{ $slot->id }})">Position leeren</x-fa::button>
                                                            @else
                                                                {{-- L4: fester Vorschlag aus dem Bestand (ohne KI) --}}
                                                                <x-fa::button size="sm" icon="heroicon-m-light-bulb" wire:click="vorschlagFuerSlot({{ $slot->id }})"
                                                                        title="Vorschlag aus dem Bestand, geordnet nach Rolle, Aroma-Verbindung zur bisherigen Folge, Aroma-Ankern und Preisnähe. Ohne KI.">Vorschlag aus dem Bestand</x-fa::button>
                                                            @endif
                                                        </div>
                                                        @if(isset($slotVorschlaege[$slot->id]))
                                                            @php $vs = $slotVorschlaege[$slot->id]; @endphp
                                                            <div class="mt-2 flex flex-col gap-1.5">
                                                                @foreach($vs['kandidaten'] as $v)
                                                                    <div wire:key="ev-{{ $slot->id }}-{{ $v['id'] }}" class="fa-surface flex flex-wrap items-start justify-between gap-2 px-3 py-2">
                                                                        <div class="min-w-0 flex-1">
                                                                            <p class="flex flex-wrap items-center gap-1.5 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">
                                                                                <span class="break-words">{{ $v['name'] }}</span>
                                                                                @if($v['diet_form'])<x-fa::badge tone="ok">{{ $v['diet_form'] }}</x-fa::badge>@endif
                                                                            </p>
                                                                            <p class="{{ $hinweis }} leading-snug">{{ $v['begruendung'] }}</p>
                                                                        </div>
                                                                        <div class="flex items-center gap-1.5 shrink-0">
                                                                            <span class="{{ $klein }} text-[var(--fa-ink-2)] tabular-nums">{{ $euro($v['sales_net']) ?? 'kein Preis' }}</span>
                                                                            <x-fa::button size="sm" icon="heroicon-m-check" wire:click="vorschlagUebernehmen({{ $slot->id }}, {{ $v['id'] }})">Übernehmen</x-fa::button>
                                                                            <x-fa::icon-button icon="heroicon-m-x-mark" label="Vorschlag verwerfen" size="sm" wire:click="vorschlagVerwerfen({{ $slot->id }}, {{ $v['id'] }})" />
                                                                        </div>
                                                                    </div>
                                                                @endforeach
                                                                @if($vs['hinweis'] !== null)
                                                                    <p class="{{ $hinweis }} px-1">{{ $vs['hinweis'] }}</p>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        @if($fillSlotId === $slot->id)
                                                            <div class="mt-2 flex flex-col gap-2">
                                                                <div class="{{ $segment }} self-start" role="group" aria-label="Art">
                                                                    <button type="button" wire:click="pickTypWaehle('gericht')" class="{{ $segKnopf }} {{ $pickTyp === 'gericht' ? $segAn : $segAus }}">Gericht</button>
                                                                    <button type="button" wire:click="pickTypWaehle('basisrezept')" class="{{ $segKnopf }} {{ $pickTyp === 'basisrezept' ? $segAn : $segAus }}">Basisrezept</button>
                                                                </div>
                                                                @if($pickTyp === 'gericht')
                                                                    @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'gerichtSuche'])
                                                                @else
                                                                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Basisrezept suchen …" aria-label="Basisrezept suchen" />
                                                                @endif
                                                                @if($kandidaten->isNotEmpty())
                                                                    <div class="flex flex-col gap-px max-h-56 overflow-y-auto fa-surface p-1">
                                                                        @foreach($kandidaten as $kand)
                                                                            <button type="button" wire:key="ek-{{ $slot->id }}-{{ $kand->id }}" wire:click="fuelleGericht({{ $slot->id }}, {{ $kand->id }}, '{{ $pickTyp }}')" class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                                                                <span class="min-w-0 break-words">{{ $kand->name }}</span>
                                                                                <span class="{{ $klein }} text-[var(--fa-ink-2)] tabular-nums shrink-0">@if($pickTyp === 'gericht'){{ $euro($kand->sales_net) ?? 'kein Preis' }}@else{{ $kand->ek_total_eur !== null ? 'EK ' . $euro($kand->ek_total_eur) : 'kein EK' }}@endif</span>
                                                                            </button>
                                                                        @endforeach
                                                                    </div>
                                                                @elseif($gerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '' || $pickDiaet !== '')
                                                                    <p class="{{ $hinweis }} px-1">Keine Treffer für diese Auswahl.</p>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif
                                        @empty
                                            <tr><td colspan="8">
                                                <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Positionen">
                                                    Links aus Gerichten, Paketen oder Basisrezepten mit Plus einfügen oder hierher ziehen. Abschnitte entstehen über „Paket erstellen“.
                                                </x-fa::empty>
                                            </td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>{{-- /BEARBEITEN-ANSICHT --}}
                        </div>{{-- /Positionen --}}
                    </div>{{-- /Picker + Positionen --}}
                @else
                    {{-- Paket: Posten schnüren — Quellen-Picker links (spiegelt den Konzept-Aufbau) + Posten-Liste --}}
                    <div class="flex flex-col lg:flex-row gap-4 items-stretch lg:items-start">
                        <aside class="w-full lg:w-64 2xl:w-80 shrink-0 flex flex-col gap-2 fa-surface p-3 lg:sticky lg:top-16 lg:self-start max-h-[55vh] lg:max-h-[calc(100vh-18rem)]" data-paket-quelle-picker data-paket-gerichtliste
                               x-data="{
                                    geparkt: null, quantity: '', flash: false,
                                    park(id, name) { this.geparkt = { id, name }; this.quantity = ''; this.$nextTick(() => this.$refs.quantity && this.$refs.quantity.focus()); },
                                    einfuegen() { if (!this.geparkt) return; this.$wire.gerichtHinzu(this.geparkt.id, this.quantity); this.geparkt = null; this.quantity = ''; this.flash = true; setTimeout(() => { this.flash = false; }, 1400); },
                                 }">
                            {{-- Umschalter: Gerichte ⇄ Basisrezepte (Pakete enthalten keine Pakete) --}}
                            <div class="{{ $segment }} self-start" role="group" aria-label="Quelle" data-paket-quelle-umschalter>
                                <button type="button" wire:click="$set('paketQuelle', 'gericht')" class="{{ $segKnopf }} {{ $paketQuelle !== 'basisrezept' ? $segAn : $segAus }}">Gerichte</button>
                                <button type="button" wire:click="$set('paketQuelle', 'basisrezept')" class="{{ $segKnopf }} {{ $paketQuelle === 'basisrezept' ? $segAn : $segAus }}">Basisrezepte</button>
                            </div>
                            <div class="flex flex-col gap-2 flex-1 min-h-0 overflow-y-auto">
                                <div x-show="geparkt === null" class="flex flex-col gap-2">
                                    @if($paketQuelle === 'basisrezept')
                                        <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="paketGerichtSuche" placeholder="Basisrezept suchen …" aria-label="Basisrezept suchen" />
                                    @else
                                        @include('foodalchemist::livewire.concepter.partials.gericht-baum', ['sucheModel' => 'paketGerichtSuche'])
                                    @endif
                                    <p class="{{ $hinweis }}">Mit Plus vormerken, {{ $paketQuelle === 'basisrezept' ? 'Gramm je Person' : 'Menge je Person' }} eingeben, mit Enter einfügen.</p>
                                    @if($paketKandidaten->isNotEmpty())
                                        <div class="flex flex-col gap-px -mx-1 px-1">
                                            @foreach($paketKandidaten as $kand)
                                                <div wire:key="epk-{{ $paketQuelle }}-{{ $kand->id }}" class="flex items-center gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)]">
                                                    <span class="min-w-0 flex-1 break-words leading-snug text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $kand->name }}">{{ $kand->name }}</span>
                                                    <span class="shrink-0 {{ $klein }} text-[var(--fa-ink-3)] tabular-nums">@if($paketQuelle === 'basisrezept'){{ $kand->ek_total_eur !== null ? 'EK ' . $euro($kand->ek_total_eur) : 'kein EK' }}@else{{ $euro($kand->sales_net) ?? 'kein Preis' }}@endif</span>
                                                    <x-fa::icon-button icon="heroicon-m-plus" label="Vormerken" size="sm" x-on:click="park({{ $kand->id }}, {{ \Illuminate\Support\Js::from($kand->name) }})" />
                                                </div>
                                            @endforeach
                                        </div>
                                    @elseif($paketGerichtSuche !== '' || $pickHg !== null || $pickKlasse !== null || $pickGeschmack !== '' || $pickDiaet !== '')
                                        <p class="{{ $leerText }}">Keine Treffer für diese Auswahl.</p>
                                    @endif
                                </div>
                                <div x-show="geparkt !== null" x-cloak class="flex flex-col gap-2" data-park-zeile>
                                    <div class="flex items-center gap-2">
                                        <x-fa::badge tone="info">{{ $paketQuelle === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</x-fa::badge>
                                        <span class="flex-1 min-w-0 break-words text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]" x-text="geparkt?.name"></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <input type="number" step="0.01" min="0" x-ref="quantity" x-model="quantity" @keydown.enter.prevent="einfuegen()" placeholder="{{ $paketQuelle === 'basisrezept' ? 'Gramm je Person' : 'Menge je Person' }}" aria-label="{{ $paketQuelle === 'basisrezept' ? 'Gramm je Person' : 'Menge je Person' }}" class="fa-control h-7 flex-1 min-w-0 text-right tabular-nums {{ $klein }}" />
                                        <x-fa::button size="sm" icon="heroicon-m-plus" x-on:click="einfuegen()">Einfügen</x-fa::button>
                                        <x-fa::icon-button icon="heroicon-m-x-mark" label="Abbrechen" size="sm" x-on:click="geparkt = null" />
                                    </div>
                                </div>
                                <x-fa::signal tone="ok" x-show="flash" x-cloak>Hinzugefügt</x-fa::signal>
                            </div>
                        </aside>
                        <div class="flex-1 min-w-0 flex flex-col gap-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Posten im Paket</h3>
                                <span class="{{ $hinweis }}">Gerichte mit Menge je Person, Basisrezepte mit Gramm je Person</span>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                @forelse($paket->dishes as $pg)
                                    @php $istBasis = ! ($pg->dish?->is_sales_recipe ?? true); @endphp
                                    <div wire:key="epg-{{ $pg->id }}" class="fa-surface flex flex-wrap items-center gap-2 px-3 py-2">
                                        <span class="inline-flex flex-col shrink-0">
                                            <button type="button" wire:click="gerichtHoch({{ $pg->id }})" class="text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach oben" aria-label="Nach oben">@svg('heroicon-m-chevron-up', 'w-4 h-4')</button>
                                            <button type="button" wire:click="gerichtRunter({{ $pg->id }})" class="text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]" title="Nach unten" aria-label="Nach unten">@svg('heroicon-m-chevron-down', 'w-4 h-4')</button>
                                        </span>
                                        <span class="inline-flex items-center h-[22px] px-2 rounded-full {{ $klein }} font-medium bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)] shrink-0" style="{{ $typStyle($istBasis ? 'basisrezept' : 'gericht') }}">{{ $istBasis ? 'Basisrezept' : 'Gericht' }}</span>
                                        <span class="flex-1 min-w-[10rem] break-words text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $pg->dish?->name ?? 'Gericht fehlt' }}</span>
                                        @if($pg->sales_recipe_id)
                                            <x-fa::icon-button href="#" :icon="$istBasis ? 'heroicon-o-book-open' : 'heroicon-o-eye'" :label="$istBasis ? 'Basisrezept ansehen' : 'Gericht ansehen'" size="sm"
                                                x-on:click.prevent="Livewire.dispatch('{{ $istBasis ? 'recipe-modal.oeffnen' : 'vk-modal.oeffnen' }}', { id: {{ $pg->sales_recipe_id }} })" />
                                        @endif
                                        <label class="inline-flex items-center gap-1.5 {{ $klein }} text-[var(--fa-ink-2)]">
                                            {{ $istBasis ? 'Gramm je Person' : 'Menge je Person' }}
                                            <input type="number" step="0.01" min="0" value="{{ $pg->quantity }}" wire:change="gerichtMengeSpeichern({{ $pg->id }}, $event.target.value)" class="fa-control h-7 w-24 text-right tabular-nums {{ $klein }}" wire:key="epg-menge-{{ $pg->id }}" />
                                        </label>
                                        @if(isset($paketFormen[$pg->id]))
                                            {{-- Darreichung je Posten: Preis und Produktion folgen der gewählten Form. --}}
                                            <select wire:key="epg-form-{{ $pg->id }}" wire:change="paketGerichtDarreichungSetzen({{ $pg->id }}, $event.target.value)"
                                                    class="fa-control fa-select pr-8 h-7 w-auto {{ $klein }}" aria-label="Darreichung dieses Postens" data-paket-form-picker
                                                    title="Form dieses Gerichts im Paket. Standard folgt der Standard-Darreichung des Gerichts.">
                                                @foreach($paketFormen[$pg->id] as $f)
                                                    <option value="{{ $f['standard'] ? '' : $f['id'] }}" @selected($f['standard'] ? $pg->presentation_id === null : (int) $pg->presentation_id === $f['id'])>{{ $f['label'] }}{{ $f['gramm'] !== null ? ' · ' . $f['gramm'] . ' g' : '' }}{{ $f['standard'] ? ' (Standard)' : '' }}</option>
                                                @endforeach
                                            </select>
                                        @elseif(! $istBasis && isset($paketPosten[$pg->id]['label']))
                                            <span class="{{ $klein }} text-[var(--fa-ink-3)]" data-paket-form>{{ $paketPosten[$pg->id]['label'] }}{{ ($paketPosten[$pg->id]['gramm'] ?? null) !== null ? ' · ' . $paketPosten[$pg->id]['gramm'] . ' g' : '' }}</span>
                                        @endif
                                        <span class="{{ $klein }} text-[var(--fa-ink-2)] tabular-nums w-24 text-right">@if($istBasis){{ $pg->dish?->ek_total_eur !== null ? 'EK ' . $euro($pg->dish->ek_total_eur) : 'kein EK' }}@else{{ $euro($paketPosten[$pg->id]['vk'] ?? $pg->dish?->sales_net) ?? 'kein Preis' }}@endif</span>
                                        <x-fa::icon-button icon="heroicon-m-x-mark" label="Posten entfernen" size="sm" tone="danger" wire:click="gerichtRaus({{ $pg->sales_recipe_id }})" />
                                    </div>
                                @empty
                                    <x-fa::empty compact icon="heroicon-o-archive-box" title="Noch keine Posten">Links ein Gericht oder Basisrezept suchen und hinzufügen.</x-fa::empty>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            {{-- ── Reiter: DEKLARATION ───────────────────────────────────────────
                 Drei Zonen (Entscheid Dominique 2026-09-04):
                   1. Quoten übergeordnet — Aussagen über das ANGEBOT
                   2. Deklaration je Gericht — die eigentliche Pflicht (LMIV: je Speise)
                   3. Nährwerte nur, wo sie fachlich stimmen
                 Die Ziffern-Kennzeichnung (Buchstaben = Allergene, Zahlen = Zusatzstoffe) ist dieselbe,
                 die Foodbook und Speisekarte ausgeben. --}}
            @if($tab === 'allergene')
                @if($deklaration === null || $deklaration['quoten']['n'] === 0)
                    <x-fa::empty icon="heroicon-o-shield-check" title="Noch keine Gerichte">Die Deklaration entsteht aus den Positionen im Aufbau.</x-fa::empty>
                @else
                    <div class="flex flex-col gap-4">
                        {{-- Zone 1: Quoten übers Angebot --}}
                        <x-fa::section title="Übers ganze Angebot" icon="heroicon-o-chart-pie">
                            <div class="flex flex-wrap items-center gap-1.5" data-deklaration-quoten>
                                <span class="{{ $feldLabel }} mr-1">{{ $deklaration['quoten']['n'] }} {{ $deklaration['quoten']['n'] === 1 ? 'Gericht' : 'Gerichte' }}</span>
                                @foreach(['vegetarisch' => 'vegetarisch', 'vegan' => 'vegan', 'glutenfrei' => 'glutenfrei', 'laktosefrei' => 'laktosefrei', 'halal' => 'halal'] as $k => $l)
                                    @if($deklaration['quoten'][$k] > 0)
                                        {{-- Quote statt Alles-oder-nichts: „3 von 9" ist die Aussage, die ein Kunde hören will. --}}
                                        <x-fa::badge :tone="$deklaration['quoten'][$k] === $deklaration['quoten']['n'] ? 'ok' : 'neutral'">{{ $deklaration['quoten'][$k] }} von {{ $deklaration['quoten']['n'] }} {{ $l }}</x-fa::badge>
                                    @endif
                                @endforeach
                                @foreach(['schwein' => 'Schwein', 'rind' => 'Rind'] as $k => $l)
                                    @if(count($deklaration['quoten'][$k]) > 0)
                                        {{-- Warnung MIT Adresse: welche Gerichte es sind, steht im Titel. --}}
                                        <x-fa::badge tone="warn" title="{{ implode(', ', $deklaration['quoten'][$k]) }}">{{ count($deklaration['quoten'][$k]) }} mit {{ $l }}</x-fa::badge>
                                    @endif
                                @endforeach
                                <x-fa::badge :tone="$konfTon($deklaration['confidence'])" class="sm:ml-auto"
                                      title="{{ $deklaration['schwaechstes'] ? 'Schwächstes Glied: ' . $deklaration['schwaechstes'] : '' }}">Deklaration {{ $konfText($deklaration['confidence']) }}@if($deklaration['schwaechstes']), schwächstes Glied: {{ $deklaration['schwaechstes'] }}@endif</x-fa::badge>
                            </div>
                        </x-fa::section>

                        {{-- Zone 2: Deklaration je Gericht --}}
                        <x-fa::section title="Deklaration je Gericht" icon="heroicon-o-list-bullet" description="Buchstaben stehen für Allergene, Zahlen für Zusatzstoffe, ein Stern für Spuren.">
                            <div class="overflow-x-auto -mx-4 px-4">
                                <table class="fa-table" data-deklaration-tabelle>
                                    <thead><tr>
                                        <th>Gericht</th>
                                        <th>Kennzeichnung</th>
                                        <th>Geeignet für</th>
                                        <th class="text-right">{{ $deklaration['modus'] === 'spanne' ? 'kcal je Portion' : 'kcal je Person' }}</th>
                                        <th>Stand</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($deklaration['zeilen'] as $z)
                                            <tr wire:key="dekl-{{ $z['id'] }}">
                                                <td class="min-w-[12rem]">{{ $z['name'] }}</td>
                                                <td class="font-mono {{ $klein }} whitespace-nowrap">@if($z['codes'] === [])<span class="text-[var(--fa-ink-3)]">keine</span>@else{{ implode(', ', $z['codes']) }}@endif</td>
                                                <td>
                                                    <div class="flex flex-wrap gap-1">
                                                        @forelse($z['diaet'] as $d)<x-fa::badge tone="ok">{{ $d }}</x-fa::badge>@empty<span class="text-[var(--fa-ink-3)]">nichts Besonderes</span>@endforelse
                                                    </div>
                                                </td>
                                                <td class="num">@if($z['kcal'] !== null){{ number_format((float) $z['kcal'], 0, ',', '.') }}@else<span class="text-[var(--fa-ink-3)]">fehlt</span>@endif</td>
                                                <td>
                                                    @if($z['fehlt'] === [])
                                                        <x-fa::badge :tone="$konfTon($z['confidence'])">{{ $konfText($z['confidence']) }}</x-fa::badge>
                                                    @else
                                                        <x-fa::badge tone="crit">Es fehlt: {{ implode(', ', $z['fehlt']) }}</x-fa::badge>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if($deklaration['legende']['allergene'] !== [] || $deklaration['legende']['zusatzstoffe'] !== [])
                                {{-- Legende: nur real vorkommende Codes (identisch zu Foodbook/Speisekarte). --}}
                                <p class="{{ $klein }} text-[var(--fa-ink-2)] leading-relaxed" data-deklaration-legende>
                                    @foreach($deklaration['legende']['allergene'] as $a)<span class="font-mono font-medium">{{ $a['code'] }}</span> {{ $a['label'] }}@if(! $loop->last) · @endif @endforeach
                                    @if($deklaration['legende']['allergene'] !== [] && $deklaration['legende']['zusatzstoffe'] !== []) <br> @endif
                                    @foreach($deklaration['legende']['zusatzstoffe'] as $z)<span class="font-mono font-medium">{{ $z['code'] }}</span> {{ $z['label'] }}@if(! $loop->last) · @endif @endforeach
                                </p>
                            @endif
                        </x-fa::section>

                        {{-- Zone 3: Nährwerte — nur wo sie fachlich stimmen --}}
                        <x-fa::section title="Nährwerte" icon="heroicon-o-scale">
                            @if($deklaration['luecken'] !== [])
                                {{-- Entscheid Dominique: bei Lücken KEINE Summe, sondern eine Aufgabenliste. --}}
                                <x-fa::notice tone="warn" title="Noch nicht belastbar">
                                    {{ count($deklaration['luecken']) }} von {{ $deklaration['quoten']['n'] }} {{ count($deklaration['luecken']) === 1 ? 'Gericht braucht' : 'Gerichten fehlen' }} noch Angaben:
                                    <ul class="mt-1.5 flex flex-col gap-1" data-deklaration-luecken>
                                        @foreach($deklaration['luecken'] as $l)
                                            <li class="flex flex-wrap items-baseline gap-x-1.5">
                                                <span class="font-medium text-[var(--fa-ink)]">{{ $l['name'] }}</span>
                                                <span class="text-[var(--fa-ink-2)]">fehlt: {{ implode(', ', $l['fehlt']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </x-fa::notice>
                            @elseif($deklaration['modus'] === 'spanne')
                                {{-- Auswahl à la carte: eine Summe je Person wäre sinnlos (niemand isst alle Positionen). --}}
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="{{ $hinweis }}">Je Portion, weil der Gast auswählt. Deshalb keine Summe.</span>
                                    <x-fa::badge :tone="$konfTon($aggregat['naehrwerte']['confidence'])">Nährwerte {{ $konfText($aggregat['naehrwerte']['confidence']) }}</x-fa::badge>
                                </div>
                                <x-fa::kpis data-deklaration-spanne :items="collect(['kcal_min' => 'kcal, niedrigstes Gericht', 'kcal_schnitt' => 'kcal im Durchschnitt', 'kcal_max' => 'kcal, höchstes Gericht'])->map(fn ($l, $k) => ['label' => $l, 'value' => $deklaration[$k] !== null ? number_format((float) $deklaration[$k], 0, ',', '.') : 'fehlt'])->values()->all()" />
                            @elseif($aggregat && $aggregat['naehrwerte']['kcal'] !== null)
                                {{-- Gesamtpreis/Paket: der Gast isst alles → Summe je Person ist die richtige Zahl. --}}
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="{{ $hinweis }}">Je Person, Summe über alle Positionen.</span>
                                    <x-fa::badge :tone="$konfTon($aggregat['naehrwerte']['confidence'])">Nährwerte {{ $konfText($aggregat['naehrwerte']['confidence']) }}</x-fa::badge>
                                </div>
                                <x-fa::kpis data-deklaration-summe :items="collect(['kcal' => 'kcal', 'protein_g' => 'Eiweiß (g)', 'fett_g' => 'Fett (g)', 'gesfett_g' => 'davon gesättigt (g)', 'kh_g' => 'Kohlenhydrate (g)', 'zucker_g' => 'davon Zucker (g)', 'salz_g' => 'Salz (g)'])->map(fn ($l, $k) => ['label' => $l, 'value' => $aggregat['naehrwerte'][$k] !== null ? number_format((float) $aggregat['naehrwerte'][$k], $k === 'kcal' ? 0 : 1, ',', '.') : 'fehlt'])->values()->all()" />
                            @else
                                <x-fa::empty compact icon="heroicon-o-scale" title="Keine Nährwerte">Den Gerichten fehlen Nährwerte oder das Portionsgewicht.</x-fa::empty>
                            @endif
                        </x-fa::section>
                    </div>
                @endif
            @endif

            </fieldset>

            {{-- ── Reiter: KALKULATION ───────────────────────────────────────────── --}}
            @if($tab === 'kalkulation')
                <div class="flex flex-col gap-4">
                    {{-- Konzept-VK: automatisch (Summe der Positionen) ODER fixiert (z. B. Lunchbuffet, Preis auf EK-Basis) --}}
                    @if($concept)
                        @php $preisModus = $form['price_mode'] ?? 'auto'; $istFix = in_array($preisModus, ['fixed', 'manuell'], true); @endphp
                        <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">
                        <x-fa::section :title="$istPaket ? 'Paketpreis je Person' : 'VK je Person'" icon="heroicon-o-banknotes" data-concept-vk>
                            <x-slot:actions>
                                <div class="{{ $segment }}" role="group" aria-label="Preisermittlung">
                                    <button type="button" wire:click="setPreisModus('auto')" class="{{ $segKnopf }} {{ $preisModus === 'auto' ? $segAn : $segAus }}">Automatisch</button>
                                    <button type="button" wire:click="setPreisModus('fixed')" class="{{ $segKnopf }} {{ $istFix ? $segAn : $segAus }}">Fixiert</button>
                                </div>
                            </x-slot:actions>
                            {{-- Preisdarstellung (2026-08-25, Dominique): Gesamtpreis (ein Preis fürs Konzept) vs.
                                 Einzelpreise (je Gericht/Paket, à la carte). Pakete sind immer Gesamtpreis. --}}
                            @unless($istPaket)
                                <x-fa::field label="Preisdarstellung" for="cc-preisdarstellung"
                                    :hint="($form['price_display'] ?? 'gesamt') === 'einzel' ? 'Kein Summenpreis: jedes Gericht oder Paket zeigt seinen eigenen Preis. Gilt für Foodbook, Format, Speisekarte und die interne Sicht.' : null" class="max-w-md">
                                    <x-fa::select id="cc-preisdarstellung" wire:change="setPreisDisplay($event.target.value)">
                                        <option value="gesamt" @selected(($form['price_display'] ?? 'gesamt') === 'gesamt')>Gesamtpreis, ein Preis fürs Konzept</option>
                                        <option value="einzel" @selected(($form['price_display'] ?? 'gesamt') === 'einzel')>Einzelpreise, je Gericht oder Paket</option>
                                    </x-fa::select>
                                </x-fa::field>
                            @endunless
                            <dl class="flex flex-wrap gap-x-8 gap-y-2">
                                <div><dt class="{{ $kopfzelle }}">Berechnete Summe</dt><dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $euro($cockpit['summe_pro_person'] ?? 0) }}</dd></div>
                                <div><dt class="{{ $kopfzelle }}">Wareneinsatz</dt><dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $euro($cockpit['ek_per_person'] ?? 0) }}</dd></div>
                            </dl>
                            @if($preisModus === 'auto')
                                <p class="{{ $hinweis }}">Automatisch addiert die gültigen Katalogpreise der Positionen. Je Gericht gilt die Preisklasse der Darreichung, sonst die des Gerichts, dann die Standardklasse des Teams. Pakete bringen ihren eigenen Katalogpreis mit.</p>
                            @endif
                            @if($istFix)
                                <div class="flex flex-wrap items-end gap-3">
                                    <x-fa::field :label="$istPaket ? 'Paketpreis gesamt je Person' : 'Fixer VK je Person'" for="cc-fixpreis" class="w-40">
                                        @if($istPaket)
                                            <x-fa::input id="cc-fixpreis" numeric type="number" step="0.01" min="0" wire:model="form.price_per_person" placeholder="z. B. 24,90" />
                                        @else
                                            <x-fa::input id="cc-fixpreis" numeric type="number" step="0.01" min="0" wire:model="form.price_per_person_manual" placeholder="z. B. 24,90" />
                                        @endif
                                    </x-fa::field>
                                    <x-fa::field label="Begründung der Abweichung" for="cc-begruendung" class="flex-1 min-w-[14rem]">
                                        <x-fa::input id="cc-begruendung" wire:model="form.price_override_reason" placeholder="z. B. Lunchbuffet mit Pauschalpreis" />
                                    </x-fa::field>
                                    <x-fa::button icon="heroicon-m-check" wire:click="speichern">Fixpreis übernehmen</x-fa::button>
                                </div>
                            @endif
                        </x-fa::section>
                        </fieldset>

                        <x-fa::section title="Auftrag hochrechnen" icon="heroicon-o-calculator" description="Rechnet den Katalogpreis für eine Personenzahl durch, ohne Stammdaten zu verändern.">
                            <x-fa::field label="Personen" for="cc-pax" class="w-40">
                                <x-fa::input id="cc-pax" numeric type="number" min="0" step="1" wire:model.live.debounce.400ms="simulationPax" placeholder="z. B. 100" />
                            </x-fa::field>
                            @if($auftragsSimulation)
                                @php
                                    $simPax = max(1, (int) $auftragsSimulation['pax']);
                                    $simZielPp = (float) ($auftragsSimulation['target_price_per_person'] ?? 0);
                                    $simAbweichungPp = (float) $auftragsSimulation['catalog_price_per_person'] - $simZielPp;
                                    $simDbPp = (float) $auftragsSimulation['contribution_margin'] / $simPax;
                                    $simKachel = 'flex flex-col gap-0.5 min-w-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2';
                                    $simLabel = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
                                    $simWert = 'text-[length:var(--fa-text-md)] font-semibold tabular-nums text-[var(--fa-ink)]';
                                @endphp
                                {{-- Laptop: höchstens fünf Spalten, darüber zwei Reihen (vorher zehn Spalten in einer Reihe, auf 1366 px unlesbar). --}}
                                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2" data-auftrag-preisempfehlung>
                                    <div class="{{ $simKachel }} border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]"><span class="text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]">Preisempfehlung / Person</span><span class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-accent)]">{{ $euro($simZielPp) }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">Katalog / Person</span><span class="{{ $simWert }}">{{ $euro($auftragsSimulation['catalog_price_per_person']) }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}" title="Katalogpreis je Person minus Preisempfehlung je Person">Abweichung Katalog − Ziel</span><span class="{{ $simWert }} {{ $simAbweichungPp < 0 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ok)]' }}">{{ $simAbweichungPp > 0 ? '+' : '' }}{{ $euro($simAbweichungPp) }} je Person</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">Deckungsbeitrag Auftrag</span><span class="{{ $simWert }} {{ $auftragsSimulation['contribution_margin'] < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">{{ $euro($simDbPp) }} je Person</span><span class="{{ $simLabel }} tabular-nums">{{ $euro($auftragsSimulation['contribution_margin']) }}, {{ $auftragsSimulation['contribution_margin_pct'] !== null ? number_format((float) $auftragsSimulation['contribution_margin_pct'], 1, ',', '.') . ' %' : 'ohne Prozent' }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">Aktive Personenzeit</span><span class="{{ $simWert }}">{{ number_format((float) $auftragsSimulation['active_person_minutes'] / 60, 2, ',', '.') }} h</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">MEK Auftrag / Person</span><span class="{{ $simWert }}">{{ $euro((float) $auftragsSimulation['mek'] / $simPax) }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">FEK Auftrag / Person</span><span class="{{ $simWert }}">{{ $euro((float) $auftragsSimulation['fek'] / $simPax) }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">HK2 / Person</span><span class="{{ $simWert }}">{{ $euro((float) $auftragsSimulation['hk2'] / $simPax) }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">Mindestpreis gesamt</span><span class="{{ $simWert }}">{{ $euro($auftragsSimulation['minimum_price']) }}</span></div>
                                    <div class="{{ $simKachel }}"><span class="{{ $simLabel }}">Zielpreis gesamt</span><span class="{{ $simWert }}">{{ $euro($auftragsSimulation['target_price']) }}</span></div>
                                </div>
                                @if($auftragsSimulation['unprofitable'])
                                    <x-fa::notice tone="warn">Der Katalogpreis liegt {{ $euro($auftragsSimulation['target_gap']) }} unter dem Zielpreis. Der Katalogpreis wurde nicht verändert.</x-fa::notice>
                                @endif
                                @unless($auftragsSimulation['complete'])
                                    <x-fa::notice tone="warn" title="Preisempfehlung nicht belastbar">Die Auftragsdaten sind noch unvollständig. Für die Berechnung gilt mindestens der ausgewiesene Katalog-MEK.</x-fa::notice>
                                @endunless
                                @if(count($auftragsSimulation['warnings']))
                                    <ul class="flex flex-col gap-1">
                                        @foreach($auftragsSimulation['warnings'] as $warnung)<li><x-fa::signal tone="warn">{{ $warnung }}</x-fa::signal></li>@endforeach
                                    </ul>
                                @endif
                                @if(count($auftragsSimulation['cost_breakdown'] ?? []))
                                    <div class="overflow-x-auto pt-2 border-t border-[var(--fa-line)]" data-auftragskosten-wasserfall>
                                        <div class="min-w-[26rem] flex flex-col">
                                            <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 pb-1 {{ $kopfzelle }}">
                                                <span>Auftragskosten</span><span class="text-right">je Person</span><span class="text-right">gesamt</span>
                                            </div>
                                            @foreach($auftragsSimulation['cost_breakdown'] as $kosten)
                                                @php $kostenStufe = $kosten['stage'] ?? 'cost'; @endphp
                                                <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 py-1 text-[length:var(--fa-text-md)] {{ in_array($kostenStufe, ['subtotal', 'total'], true) ? 'mt-1 border-t border-[var(--fa-line)] font-semibold text-[var(--fa-ink)]' : 'text-[var(--fa-ink-2)]' }} {{ $kostenStufe === 'total' ? 'text-[var(--fa-accent)]' : '' }}">
                                                    <span>{{ in_array($kostenStufe, ['surcharge'], true) ? '+ ' : '' }}{{ $kosten['label'] }}</span>
                                                    <span class="text-right tabular-nums">{{ $euro((float) $kosten['amount'] / $simPax) }}</span>
                                                    <span class="text-right tabular-nums">{{ $euro($kosten['amount']) }}</span>
                                                </div>
                                            @endforeach
                                            <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 mt-1 border-t border-[var(--fa-line)] py-1 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-accent)]">
                                                <span>Preisempfehlung</span>
                                                <span class="text-right tabular-nums">{{ $euro($simZielPp) }}</span>
                                                <span class="text-right tabular-nums">{{ $euro($auftragsSimulation['target_price']) }}</span>
                                            </div>
                                            <div class="grid grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 py-1 text-[length:var(--fa-text-md)] {{ $auftragsSimulation['contribution_margin'] < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">
                                                <span>Deckungsbeitrag beim Katalog-VK</span>
                                                <span class="text-right tabular-nums">{{ $euro($simDbPp) }}</span>
                                                <span class="text-right tabular-nums">{{ $euro($auftragsSimulation['contribution_margin']) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                                @if(count($auftragsSimulation['time_breakdown'] ?? []))
                                    <details class="pt-1" data-zeitaufschluesselung>
                                        <summary class="cursor-pointer {{ $klein }} font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]">Zeit je Rezept: {{ number_format((float) $auftragsSimulation['active_person_minutes'] / 60, 2, ',', '.') }} Personenstunden <span class="font-normal text-[var(--fa-ink-3)]">({{ number_format((float) $auftragsSimulation['active_person_minutes'], 1, ',', '.') }} Personenminuten)</span></summary>
                                        <div class="overflow-x-auto pt-2">
                                            <table class="fa-table fa-table--compact min-w-[40rem]">
                                                <thead><tr>
                                                    <th>Rezept</th><th class="text-right">Ansätze</th><th class="text-right">Vorgänge</th><th class="text-right">Rüsten</th><th class="text-right">Vorgangszeit</th><th class="text-right">Variabel</th><th class="text-right">Aktiv gesamt</th>
                                                </tr></thead>
                                                <tbody>
                                                @foreach($auftragsSimulation['time_breakdown'] as $zeit)
                                                    <tr>
                                                        <td>{{ $zeit['recipe'] }}</td>
                                                        <td class="num">{{ number_format((float) $zeit['production_batches'], 2, ',', '.') }}</td>
                                                        <td class="num">{{ $zeit['operations'] }}</td>
                                                        <td class="num">{{ number_format((float) $zeit['setup_minutes'], 1, ',', '.') }} min</td>
                                                        <td class="num">{{ number_format((float) $zeit['batch_minutes'], 1, ',', '.') }} min</td>
                                                        <td class="num">{{ number_format((float) $zeit['variable_minutes'], 1, ',', '.') }} min</td>
                                                        <td class="num font-medium">{{ number_format((float) $zeit['active_person_minutes'], 1, ',', '.') }} min</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                @endif
                            @endif
                        </x-fa::section>
                    @endif

                    {{-- Wareneinsatz je Position — woraus sich die Kosten zusammensetzen --}}
                    @if($concept && $cockpit)
                        <x-fa::section title="Wareneinsatz je Position" icon="heroicon-o-queue-list" description="Je Person.">
                            <div class="overflow-x-auto -mx-4 px-4">
                                <table class="fa-table fa-table--compact">
                                    <thead><tr>
                                        <th>Position</th>
                                        <th class="text-right">Wareneinsatz</th>
                                        <th class="text-right">VK</th>
                                        <th class="text-right">Einsatz</th>
                                    </tr></thead>
                                    <tbody>
                                    @forelse($cockpit['zeilen'] as $z)
                                        @php $zw = (($z['price'] ?? 0) > 0 && $z['ek'] !== null) ? $z['ek'] / $z['price'] * 100 : null; @endphp
                                        <tr>
                                            <td class="min-w-[12rem]">@if($z['role'])<span class="text-[var(--fa-ink-2)]">{{ $z['role'] }}:</span> @endif{{ $z['label'] }}</td>
                                            <td class="num">@if($z['ek'] !== null){{ $euro($z['ek']) }}@else<span class="text-[var(--fa-ink-3)]">offen</span>@endif</td>
                                            <td class="num text-[var(--fa-ink-2)]">@if($z['price'] !== null){{ $euro($z['price']) }}@else<span class="text-[var(--fa-ink-3)]">offen</span>@endif</td>
                                            <td class="num">{{ $zw !== null ? number_format($zw, 1, ',', '.') . ' %' : '' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-[var(--fa-ink-3)]">Noch keine Positionen.</td></tr>
                                    @endforelse
                                    </tbody>
                                    <tfoot>
                                        <tr class="font-semibold">
                                            <td class="pt-2 border-t border-[var(--fa-line-strong)]">Summe je Person</td>
                                            <td class="num pt-2 border-t border-[var(--fa-line-strong)]">{{ $euro($cockpit['ek_per_person']) }}</td>
                                            <td class="num pt-2 border-t border-[var(--fa-line-strong)] text-[var(--fa-ink-2)]">{{ $euro($cockpit['price_per_person']) }}</td>
                                            <td class="num pt-2 border-t border-[var(--fa-line-strong)]">{{ $cockpit['price_per_person'] > 0 ? number_format($cockpit['ek_per_person'] / $cockpit['price_per_person'] * 100, 1, ',', '.') . ' %' : '' }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </x-fa::section>
                    @elseif($paket)
                        <x-fa::section title="Wareneinsatz je Posten" icon="heroicon-o-queue-list" description="Je Person.">
                            <div class="overflow-x-auto -mx-4 px-4">
                                <table class="fa-table fa-table--compact">
                                    <thead><tr>
                                        <th>Posten</th>
                                        <th class="text-right">Menge</th>
                                        <th class="text-right">Wareneinsatz</th>
                                        <th class="text-right">VK</th>
                                    </tr></thead>
                                    <tbody>
                                    @forelse($paket->dishes as $pg)
                                        @php
                                            $istBasis = ! ($pg->dish?->is_sales_recipe ?? true);
                                            $faktor = $pg->quantity !== null ? (float) $pg->quantity : 1.0;
                                            $yieldG = (float) ($pg->dish?->yield_kg ?? 0) * 1000;
                                            $postenEk = $istBasis
                                                ? (($pg->dish?->ek_total_eur !== null && $yieldG > 0 && $pg->quantity !== null) ? (float) $pg->dish->ek_total_eur * ((float) $pg->quantity / $yieldG) : null)
                                                : ($pg->dish?->ek_total_eur !== null ? (float) $pg->dish->ek_total_eur * $faktor : null);
                                        @endphp
                                        <tr>
                                            <td class="min-w-[12rem]">{{ $pg->dish?->name ?? 'Gericht fehlt' }}</td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $pg->quantity !== null ? ($menge($faktor) . ($istBasis ? ' g' : '')) : ($istBasis ? 'Gramm fehlt' : '1') }}</td>
                                            <td class="num">@if($postenEk !== null){{ $euro($postenEk) }}@else<span class="text-[var(--fa-ink-3)]">offen</span>@endif</td>
                                            <td class="num text-[var(--fa-ink-2)]">@if($istBasis)<span class="text-[var(--fa-ink-3)]">kein VK</span>@else{{ $pg->dish?->sales_net !== null ? $euro((float) $pg->dish->sales_net * $faktor) : 'offen' }}@endif</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-[var(--fa-ink-3)]">Noch keine Posten.</td></tr>
                                    @endforelse
                                    </tbody>
                                    <tfoot>
                                        <tr class="font-semibold">
                                            <td class="pt-2 border-t border-[var(--fa-line-strong)]">Summe je Person</td>
                                            <td class="pt-2 border-t border-[var(--fa-line-strong)]"></td>
                                            <td class="num pt-2 border-t border-[var(--fa-line-strong)]">{{ $euro($paket->ek_per_person) ?? 'offen' }}</td>
                                            <td class="num pt-2 border-t border-[var(--fa-line-strong)] text-[var(--fa-ink-2)]">{{ $aggregat !== null ? $euro($aggregat['vk_summe']) : 'offen' }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </x-fa::section>
                    @endif

                    <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">
                    @if($concept && $cockpit)
                        {{-- Die Kennzahlen VK, Wareneinsatz und Prozent stehen fix im Kopf; hier nur das Ziel. --}}
                        <x-fa::section title="Zielpreis" icon="heroicon-o-flag">
                            <x-slot:actions>
                                <x-fa::button size="sm" :variant="$zielModus ? 'ai' : 'secondary'" icon="heroicon-m-adjustments-vertical" wire:click="zielpreisToggle" aria-pressed="{{ $zielModus ? 'true' : 'false' }}">Preis aufs Ziel bringen</x-fa::button>
                            </x-slot:actions>
                            <x-fa::field label="Zielpreis je Person" for="cc-zielpreis" class="w-48">
                                <x-fa::input id="cc-zielpreis" numeric type="number" step="0.01" min="0" wire:model="form.target_price_per_person" />
                            </x-fa::field>
                            @if($zielModus)
                                <div class="flex flex-col gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] p-3">
                                    <div class="flex flex-wrap items-end gap-2">
                                        <x-fa::field label="Gewünschter Preis je Person" for="cc-zielwunsch" class="w-48">
                                            <x-fa::input id="cc-zielwunsch" numeric type="number" step="0.01" min="0" wire:model="zielPreis" wire:keydown.enter="zielpreisBerechnen" placeholder="z. B. 36,00" />
                                        </x-fa::field>
                                        <x-fa::button icon="heroicon-m-calculator" wire:click="zielpreisBerechnen">Vorschlag berechnen</x-fa::button>
                                        <span class="{{ $hinweis }} pb-2">Tauscht Pakete gegeneinander. Feste Gerichte zählen als Fixkosten.</span>
                                    </div>
                                    @if($zielVorschlag)
                                        <div class="flex flex-col gap-2 pt-2 border-t border-[var(--fa-accent-line)]">
                                            <dl class="flex flex-wrap gap-x-8 gap-y-1">
                                                <div><dt class="{{ $kopfzelle }}">Aktuell</dt><dd class="font-semibold tabular-nums">{{ $euro($zielVorschlag['aktuell']) }}</dd></div>
                                                <div><dt class="{{ $kopfzelle }}">Vorschlag</dt><dd class="font-semibold tabular-nums text-[var(--fa-accent)]">{{ $euro($zielVorschlag['price']) }}</dd></div>
                                                <div><dt class="{{ $kopfzelle }}">Getauschte Pakete</dt><dd class="font-semibold tabular-nums">{{ $zielVorschlag['aenderungen'] }}</dd></div>
                                            </dl>
                                            <div class="flex gap-2">
                                                <x-fa::button icon="heroicon-m-check" wire:click="zielpreisUebernehmen" :disabled="$zielVorschlag['aenderungen'] === 0">Vorschlag übernehmen</x-fa::button>
                                                <x-fa::button variant="ghost" wire:click="$set('zielVorschlag', null)">Verwerfen</x-fa::button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </x-fa::section>
                    @elseif($paket)
                        <x-fa::section title="Paketpreis" icon="heroicon-o-banknotes">
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                                <x-fa::field label="Preisermittlung" for="cc-paket-modus">
                                    <x-fa::select id="cc-paket-modus" wire:change="setPreisModus($event.target.value)">
                                        <option value="auto">Automatisch, Summe der Gerichte</option>
                                        <option value="fixed" @selected(in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))>Fixiert</option>
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Preis je Person" for="cc-paket-preis">
                                    <x-fa::input id="cc-paket-preis" numeric type="number" step="0.01" min="0" wire:model="form.price_per_person" :disabled="($form['price_mode'] ?? 'auto') === 'auto'" />
                                </x-fa::field>
                                <x-fa::field label="EK je Person" for="cc-paket-ek" hint="Aus den Gerichten berechnet.">
                                    <x-fa::input id="cc-paket-ek" numeric type="number" step="0.0001" min="0" wire:model="form.ek_per_person" disabled />
                                </x-fa::field>
                                <x-fa::field label="Wareneinsatz %" for="cc-paket-we" hint="Abgeleitet.">
                                    <x-fa::input id="cc-paket-we" numeric type="number" step="0.1" min="0" wire:model="form.food_cost_percent" disabled />
                                </x-fa::field>
                            </div>
                            @if(in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))
                                <div class="flex flex-wrap items-end gap-2">
                                    <x-fa::field label="Begründung der Abweichung" for="cc-paket-begruendung" class="flex-1 min-w-[14rem]">
                                        <x-fa::input id="cc-paket-begruendung" wire:model="form.price_override_reason" />
                                    </x-fa::field>
                                    <x-fa::button icon="heroicon-m-check" wire:click="speichern">Fixpreis übernehmen</x-fa::button>
                                </div>
                            @endif
                            <div class="flex flex-wrap items-center gap-3 pt-3 border-t border-[var(--fa-line)]">
                                <x-fa::button icon="heroicon-m-arrow-path" wire:click="neuBerechnen">Wareneinsatz neu berechnen</x-fa::button>
                                <span class="{{ $hinweis }}">Kosten und automatischer Preis folgen den Gerichten. Ein Fixpreis braucht eine Begründung.</span>
                            </div>
                        </x-fa::section>
                    @endif
                    </fieldset>
                </div>
            @endif

            <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">

            {{-- ── Reiter: NOTIZEN ───────────────────────────────────────────────── --}}
            @if($tab === 'notes')
                @if($concept)
                    <div class="flex flex-col gap-4">
                        <x-fa::section title="Vorgaben für die KI" icon="heroicon-o-sparkles" description="Fließen in jede KI-Erstellung zu diesem Konzept ein.">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <x-fa::field label="Ernährungs-Vorgabe" for="cc-diaet">
                                    <x-fa::input id="cc-diaet" wire:model="form.diet_requirement" placeholder="z. B. „je Gang mindestens ein veganes Gericht“" />
                                </x-fa::field>
                                <x-fa::field label="Aufbau-Vorgabe" for="cc-struktur">
                                    <x-fa::input id="cc-struktur" wire:model="form.structure_requirement" placeholder="z. B. „3 Gänge“ oder „Buffet mit Salat, Hauptgang, Dessert“" />
                                </x-fa::field>
                                <x-fa::field label="Saison" for="cc-saison-frei">
                                    <x-fa::input id="cc-saison-frei" wire:model="form.season" />
                                </x-fa::field>
                                <x-fa::field label="Zielgruppe oder Sektor" for="cc-zielgruppe" optional>
                                    <x-fa::input id="cc-zielgruppe" wire:model="form.target_group" />
                                </x-fa::field>
                                <x-fa::field label="Auftrag in eigenen Worten" for="cc-brief" class="md:col-span-2">
                                    <x-fa::textarea id="cc-brief" wire:model="form.brief" rows="3" placeholder="Was soll das Konzept leisten, für wen, zu welchem Anlass?" />
                                </x-fa::field>
                            </div>
                        </x-fa::section>
                        <x-fa::section title="Geeignet für Sektoren" icon="heroicon-o-building-office-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach($sektorSlugs as $slug)
                                    <span wire:key="sek-{{ $slug }}" class="inline-flex items-center gap-1 h-7 pl-2.5 pr-1 rounded-full {{ $klein }} font-medium bg-[var(--fa-info-soft)] text-[var(--fa-info)]">
                                        {{ $slug }}
                                        <button type="button" wire:click="sektorRaus(@js($slug))" class="inline-flex items-center justify-center w-5 h-5 rounded-full hover:bg-[var(--fa-hover)]" title="Sektor entfernen" aria-label="Sektor {{ $slug }} entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </span>
                                @endforeach
                                <x-fa::input size="sm" wire:model="neuerSektor" wire:keydown.enter.prevent="sektorHinzu" placeholder="Sektor eingeben und Enter, z. B. Kita, Klinik" class="w-72" aria-label="Neuer Sektor" />
                            </div>
                        </x-fa::section>
                        <x-fa::section title="Texte und Notizen" icon="heroicon-o-document-text">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <x-fa::field label="Zusatztext für Gäste" for="cc-zusatz">
                                    <x-fa::textarea id="cc-zusatz" wire:model="form.additional_text" rows="3" />
                                </x-fa::field>
                                <x-fa::field label="Interne Notiz" for="cc-notiz">
                                    <x-fa::textarea id="cc-notiz" wire:model="form.note" rows="3" />
                                </x-fa::field>
                            </div>
                        </x-fa::section>
                    </div>
                @else
                    <x-fa::section title="Texte und Notizen" icon="heroicon-o-document-text">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <x-fa::field label="Beschreibung" for="cc-paket-beschreibung">
                                <x-fa::textarea id="cc-paket-beschreibung" wire:model="form.description" rows="3" />
                            </x-fa::field>
                            <x-fa::field label="Interne Notiz" for="cc-paket-notiz">
                                <x-fa::textarea id="cc-paket-notiz" wire:model="form.note" rows="3" />
                            </x-fa::field>
                        </div>
                    </x-fa::section>
                @endif
            @endif

            {{-- ── Reiter: KONZEPT & PLANUNG (#389 Canvas — kreatives Foodkonzept) ── --}}
            @if($tab === 'konzept')
                @if($concept)
                    <div class="flex flex-col gap-4">
                        <p class="{{ $hinweis }}">Das kreative Foodkonzept mit Leitidee, Inszenierung und Geschmackswelten. Es fließt in alle KI-Texte dieses Konzepts ein, Stil und Geschmack erbt es aus der Food-DNA des Teams.</p>
                        @include('foodalchemist::livewire.canvas.partials.board', ['hideSave' => true])

                        {{-- R4.1 + Progressive Disclosure (2026-08-24): Planungs-Gerüst = messbare Vorgaben.
                             Kein Gerüst → ruhige Einladung; Gerüst vorhanden → voll (Vorgaben + Soll-Ist-Abgleich).
                             Der Rahmen gehört dem Konzept: Planung-Leitstelle und dieser Reiter bearbeiten dasselbe. --}}
                        @php $hatGeruest = $coverage !== null && $coverage['hat_geruest']; @endphp
                        <div x-data="{ offen: @js($hatGeruest) }" class="flex flex-col gap-3">
                            {{-- Zu (kein Gerüst): ruhige Einladung statt leerer Maske --}}
                            <div x-show="!offen" @if($hatGeruest) x-cloak @endif
                                 class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--fa-radius-surface)] border border-dashed border-[var(--fa-line-strong)] px-4 py-3">
                                <div class="min-w-0 max-w-[70ch]">
                                    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Noch kein Planungs-Gerüst</p>
                                    <p class="{{ $hinweis }}">Messbare Vorgaben für Mengen, Preis, Ernährungsformen, Saison und Gangfolge. Nur nötig, wenn gegen Vorgaben geplant oder mit KI erstellt wird. Die Planung-Leitstelle füllt es aus einem Auftrag.</p>
                                </div>
                                <x-fa::button size="sm" icon="heroicon-m-plus" x-on:click="offen = true" class="shrink-0">Gerüst anlegen</x-fa::button>
                            </div>

                            {{-- Offen (Gerüst vorhanden ODER von Hand aufgeklappt): volle Vorgaben + Abgleich --}}
                            <div x-show="offen" @unless($hatGeruest) x-cloak @endunless class="flex flex-col gap-3">
                                <div>
                                    <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Planungs-Gerüst</h3>
                                    <p class="{{ $hinweis }}">Die messbaren Vorgaben: Mengen, Preisrahmen, Ernährungsformen, Saison, Ausschlüsse und Gangfolge. Daran misst sich der Abgleich unten und jede KI-Erstellung.</p>
                                </div>
                                @include('foodalchemist::livewire.planning.partials.frame-board', ['hideSave' => true])

                                {{-- Spec 28 / E6: die Ist-Messung gegen genau dieses Gerüst. Klick auf eine Lücke
                                     filtert weiterhin den Picker im Reiter «Aufbau» (R4.2). --}}
                                @if($hatGeruest)
                                    @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverageFillAction' => 'coverageFuellen'])
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            {{-- ── Reiter: GESCHIRR (#388 — Haupt-Geschirr + Alternative je Gericht) ── --}}
            @if($tab === 'geschirr')
                @php
                    if ($concept) {
                        $geschirrZeilen = $concept->slots->filter(fn ($s) => $s->sales_recipe_id !== null || in_array($s->type, ['gericht', 'basisrezept'], true))
                            ->map(fn ($s) => ['id' => $s->id, 'key' => 'geschirr-slot-' . $s->id, 'pickKey' => 'geschirr-pick-' . $s->id, 'kandKey' => 'gk-' . $s->id, 'name' => $s->wording ?: ($s->dish?->name ?: ($s->title ?: 'Position')), 'haupt' => $s->dishwareItem, 'alt' => $s->dishwareAltItem, 'vorschlag' => true]);
                    } else {
                        $geschirrZeilen = $paket->dishes
                            ->map(fn ($pg) => ['id' => $pg->id, 'key' => 'paket-geschirr-' . $pg->id, 'pickKey' => 'paket-geschirr-pick-' . $pg->id, 'kandKey' => 'pgk-' . $pg->id, 'name' => $pg->dish?->name ?: 'Posten', 'haupt' => $pg->dishwareItem, 'alt' => $pg->dishwareAltItem, 'vorschlag' => false]);
                    }
                @endphp
                <div class="flex flex-col gap-3">
                    <p class="{{ $hinweis }}">Je {{ $concept ? 'Gericht' : 'Posten' }} ein Haupt-Geschirr und auf Wunsch eine Alternative, etwa von einem anderen Verleiher. Den Geschirr-Katalog pflegst du unter <span class="font-medium text-[var(--fa-ink-2)]">Stammdaten, Geschirr</span>.</p>
                    @forelse($geschirrZeilen as $zeile)
                        <section wire:key="{{ $zeile['key'] }}" class="fa-surface flex flex-col gap-3 px-4 py-3">
                            <h4 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)] break-words">{{ $zeile['name'] }}</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @foreach(['haupt' => 'Haupt-Geschirr', 'alt' => 'Alternative'] as $role => $rolleLabel)
                                    @php $geschirr = $zeile[$role]; @endphp
                                    <div class="flex flex-col gap-1.5 min-w-0">
                                        <span class="{{ $feldLabel }}">{{ $rolleLabel }}</span>
                                        @if($geschirr)
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">{{ $geschirr->label }}</span>
                                                @if($geschirr->rental_price !== null)<x-fa::badge>{{ $euro($geschirr->rental_price) }} Miete</x-fa::badge>@endif
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <x-fa::button size="sm" icon="heroicon-m-arrows-right-left" wire:click="geschirrPicker({{ $zeile['id'] }}, '{{ $role }}')">Geschirr ändern</x-fa::button>
                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="geschirrEntfernen({{ $zeile['id'] }}, '{{ $role }}')">Entfernen</x-fa::button>
                                            </div>
                                        @else
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="geschirrPicker({{ $zeile['id'] }}, '{{ $role }}')">Geschirr wählen</x-fa::button>
                                                @if($zeile['vorschlag'] && $role === 'haupt' && isset($geschirrVorschlag[$zeile['id']]))
                                                    <x-fa::button size="sm" variant="ai" icon="heroicon-m-light-bulb" wire:click="geschirrWaehle({{ $zeile['id'] }}, 'haupt', {{ $geschirrVorschlag[$zeile['id']]['id'] }})" data-geschirr-vorschlag
                                                            title="Standard-Geschirr der gültigen Darreichung ({{ $geschirrVorschlag[$zeile['id']]['form'] }}). Klick übernimmt.">{{ $geschirrVorschlag[$zeile['id']]['label'] }} übernehmen</x-fa::button>
                                                @endif
                                            </div>
                                        @endif

                                        @if($geschirrPickSlotId === $zeile['id'] && $geschirrPickRolle === $role)
                                            <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-ground)] p-2" wire:key="{{ $zeile['pickKey'] }}-{{ $role }}">
                                                <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="geschirrSuche" placeholder="Geschirr suchen …" aria-label="Geschirr suchen" autofocus />
                                                <div class="flex flex-col gap-px max-h-48 overflow-y-auto">
                                                    @forelse($geschirrKandidaten as $kandidat)
                                                        <button type="button" wire:key="{{ $zeile['kandKey'] }}-{{ $role }}-{{ $kandidat->id }}"
                                                                wire:click="geschirrWaehle({{ $zeile['id'] }}, '{{ $role }}', {{ $kandidat->id }})"
                                                                class="flex flex-wrap items-baseline gap-x-2 w-full text-left px-2 py-1.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                                            <span>{{ $kandidat->label }}</span>
                                                            <span class="{{ $klein }} text-[var(--fa-ink-3)]">{{ $kandidat->supplier?->name }}{{ $kandidat->rental_price !== null ? ', ' . $euro($kandidat->rental_price) : '' }}</span>
                                                        </button>
                                                    @empty
                                                        <p class="{{ $leerText }}">{{ trim($geschirrSuche) === '' ? 'Zum Suchen tippen …' : 'Kein Geschirr gefunden.' }}</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @empty
                        <x-fa::empty icon="heroicon-o-square-3-stack-3d" :title="$concept ? 'Noch keine Gerichte im Konzept' : 'Noch keine Posten im Paket'">Erst im Reiter «Aufbau» {{ $concept ? 'Gerichte oder Basisrezepte einfügen' : 'Posten hinzufügen' }}.</x-fa::empty>
                    @endforelse
                </div>
            @endif
            </fieldset>
        @endif
    </x-foodalchemist::modal>
</div>
