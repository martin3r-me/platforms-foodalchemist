{{-- Präsentations-Designs (Spec 43): Struktur-Builder fürs digitale Kundenbuch.
     Grundanordnung: links Designs + Bausteine · Mitte Kopf + Live-Vorschau · rechts Struktur, Block-Einstellungen, Farben/Schrift, eigenes CSS.
     Häufigste Aufgabe: ein Design wählen, anpassen, in der Vorschau prüfen, speichern.
     Farben und Schriften sind KUNDEN-BRANDING (Daten): Farbwähler + gespeicherte Werte bleiben unverändert, nur ihre Darstellung ist neu.
     Spalten richten sich nach der verfügbaren Breite (Container-Abfragen), nicht nach dem Fenster. --}}
@php
    $haken = 'w-4 h-4 shrink-0 rounded accent-[var(--fa-accent)]';
    $hakenZeile = 'flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer';
    $hilfe = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $menuPunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuGefahr = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $formen = ['foodbook' => 'Foodbook', 'angebot' => 'Angebot', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan'];
    $farbFelder = ['primary' => 'Hauptfarbe', 'accent' => 'Akzentfarbe', 'bg' => 'Hintergrund', 'text' => 'Schriftfarbe'];
@endphp

<div class="@container flex flex-col gap-4 min-w-0" data-fa-designs-builder>
    {{-- Feedback --}}
    {{-- Spec 65: „Bearbeiten" (am Speichern-Knopf) sperrt den Bereich für das Team; Design wählen, Vorschau und
         Block anklicken bleiben im Lesemodus bedienbar, alles Ändernde liegt in gesperrten <fieldset>s. --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @if($status)
        <x-fa::notice tone="ok" data-fa-designs-status>{{ $status }}</x-fa::notice>
    @endif
    @if($fehler)
        <x-fa::notice tone="crit" data-fa-designs-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    <div class="grid grid-cols-1 gap-4 items-start @2xl:grid-cols-[13rem_minmax(0,1fr)] @6xl:grid-cols-[15rem_minmax(0,1fr)_minmax(0,23rem)]">
        {{-- ── Links: Designs + Bausteine ─────────────────────────────── --}}
        <aside class="flex flex-col gap-4 min-w-0">
            <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
            <x-fa::section title="Meine Designs" icon="heroicon-o-swatch">
                <div class="flex flex-col gap-0.5 -mx-1.5">
                    @forelse($designs as $d)
                        <div class="flex items-center gap-1 min-w-0" wire:key="design-{{ $d['id'] }}">
                            <a href="#" role="button" wire:click.prevent="waehlen({{ $d['id'] }})" aria-pressed="{{ $selectedId === $d['id'] ? 'true' : 'false' }}"
                                class="flex-1 min-w-0 flex items-center gap-1.5 text-left text-[length:var(--fa-text-md)] px-2 py-1.5 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $selectedId === $d['id'] ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}">
                                <span class="truncate">{{ $d['name'] }}</span>
                                @unless($d['owned'])<x-fa::badge class="shrink-0">Geerbt</x-fa::badge>@endunless
                            </a>
                            <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $d['name'] }}" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                <div class="hidden w-48 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="duplizieren({{ $d['id'] }})" class="{{ $menuPunkt }}">
                                        @svg('heroicon-m-document-duplicate', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                        Design duplizieren
                                    </button>
                                    @if($d['owned'])
                                        <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                        <button type="button" role="menuitem" x-on:click="offen = false" wire:click="loeschen({{ $d['id'] }})" wire:confirm="Design {{ $d['name'] }} löschen?" class="{{ $menuGefahr }}">
                                            @svg('heroicon-m-trash', 'w-4 h-4')
                                            Design löschen
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-fa::empty icon="heroicon-o-swatch" title="Noch kein eigenes Design" compact>Unten eine Vorlage wählen und daraus starten.</x-fa::empty>
                    @endforelse
                </div>
                <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Neu aus Vorlage</p>
                    <div class="flex flex-wrap gap-1.5">
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('editorial')">Editorial</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('angebot')">Angebot</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('menu')">Speisekarte</x-fa::button>
                        <x-fa::button size="sm" href="#" x-on:click.prevent="" wire:click="neuAusBuiltin('kiosk')">Kiosk</x-fa::button>
                    </div>
                </div>
            </x-fa::section>

            <x-fa::section title="Block hinzufügen" icon="heroicon-o-plus-circle">
                <div class="grid gap-1.5 grid-cols-[repeat(auto-fill,minmax(min(100%,8.5rem),1fr))]">
                    @foreach($blockTypen as $t)
                        <button type="button" wire:click="blockHinzufuegen('{{ $t }}')" wire:key="add-{{ $t }}"
                            class="flex items-center gap-1.5 min-w-0 text-left text-[length:var(--fa-text-sm)] px-2 py-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] text-[var(--fa-ink)] hover:bg-[var(--fa-accent-soft)] hover:border-[var(--fa-accent-line)] transition-colors duration-150" data-fa-add-block="{{ $t }}">
                            @svg('heroicon-m-plus', 'w-3.5 h-3.5 shrink-0 text-[var(--fa-ink-3)]')
                            <span class="truncate">{{ $blockLabels[$t] ?? $t }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="{{ $hilfe }}">Alle Blöcke zeigen nur Daten für den Gast. Einkaufspreise und Interna erscheinen nie.</p>
            </x-fa::section>
            </fieldset>
        </aside>

        {{-- ── Mitte: Kopf + Live-Vorschau ──────────────────────────── --}}
        <section class="flex flex-col gap-3 min-w-0">
            <div class="flex flex-wrap items-end gap-2">
                <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
                <x-fa::field label="Name des Designs" for="design-name" class="flex-1 min-w-[12rem]">
                    <x-fa::input id="design-name" wire:model="name" placeholder="z. B. Hochzeit Sommer" data-fa-design-name />
                </x-fa::field>
                </fieldset>
                @if($ungespeichert)
                    <x-fa::badge tone="warn" icon="heroicon-m-pencil" class="mb-2">Ungespeichert</x-fa::badge>
                @endif
                {{-- Spec 65: erst „Bearbeiten", dann Abbrechen/Speichern; Speichern gibt den Bereich frei --}}
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-fa-design-save>
                        {{ $selectedId ? 'Design speichern' : 'Design anlegen' }}
                    </x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>

            {{-- Bug-Runde 2026-09-17 #2: Die Vorschau zeigt den Editor-Zustand, der Kundenlink den
                 gespeicherten + veröffentlichten. Ungespeichert sah man die Änderung also nur hier. --}}
            @if($ungespeichert)
                <x-fa::notice tone="warn" title="Ungespeicherte Änderungen" data-fa-design-dirty>
                    Die Vorschau zeigt sie bereits. Im Kundenlink erscheinen sie erst nach dem Speichern und einem erneuten Veröffentlichen der Ausgabe.
                </x-fa::notice>
            @endif

            {{-- Form-Scoping: für welche Ausgabeformen dieses Design im Picker auftaucht (leer = alle). --}}
            <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
            <div class="flex flex-col gap-1" data-fa-output-types>
                <x-fa::choice name="outputTypes" multiple label="Gilt für" :options="$formen" />
                <p class="{{ $hilfe }}">Nichts gewählt heißt: für alle Formen.</p>
            </div>
            </fieldset>

            <div class="flex flex-wrap items-end gap-3">
                <x-fa::choice name="previewType" label="Vorschau als" :options="$formen" />
                <x-fa::field label="Quelle" for="design-vorschau-quelle" class="flex-1 min-w-[12rem]">
                    <x-fa::select id="design-vorschau-quelle" wire:model.live="previewSourceId" placeholder="Quelle wählen">
                        @foreach($quellenOptionen as $opt)
                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
            </div>

            <div class="fa-surface overflow-hidden">
                @if($vorschauHtml !== null)
                    <div class="flex items-center gap-2 px-3 py-2 border-b border-[var(--fa-line)] text-[length:var(--fa-text-sm)] {{ $ungespeichert ? 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]' : 'text-[var(--fa-ink-3)]' }}">
                        @svg($ungespeichert ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-eye', 'w-4 h-4 shrink-0')
                        {{ $ungespeichert ? 'Vorschau des ungespeicherten Stands, noch nicht im Kundenlink.' : 'Vorschau des gespeicherten Stands.' }}
                    </div>
                    <iframe class="block w-full h-[640px] border-0 bg-[var(--fa-surface)]" srcdoc="{{ $vorschauHtml }}" title="Live-Vorschau" data-fa-preview-frame></iframe>
                @else
                    <x-fa::empty icon="heroicon-o-eye" title="Vorschau erscheint hier" class="h-[640px] justify-center">
                        Oben Form und Quelle wählen, dann zeigt die Vorschau das Design mit echten Daten.
                    </x-fa::empty>
                @endif
            </div>
        </section>

        {{-- ── Rechts: Struktur + Block-Einstellungen + Farben/Schrift ────────────────────── --}}
        <aside class="grid gap-4 items-start min-w-0 @2xl:col-span-2 @2xl:grid-cols-2 @6xl:col-span-1 @6xl:grid-cols-1">
            <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
            <x-fa::section title="Aufbau" icon="heroicon-o-queue-list" :meta="count($layout) . ' ' . (count($layout) === 1 ? 'Block' : 'Blöcke')" x-data="{ from: null }">
                <div class="flex flex-col gap-1" data-fa-structure>
                    @forelse($layout as $i => $b)
                        <div draggable="true"
                             @dragstart="from = {{ $i }}"
                             @dragover.prevent
                             @drop.prevent="$wire.bloeckeNachDrop(from, {{ $i }}); from = null"
                             wire:key="block-{{ $i }}-{{ $b['block_type'] }}"
                             class="flex items-center gap-1 min-w-0 pl-1.5 pr-1 py-0.5 rounded-[var(--fa-radius-control)] border cursor-move {{ $selectedBlockIndex === $i ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                            @svg('heroicon-m-bars-3', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                            <a href="#" role="button" wire:click.prevent="blockWaehlen({{ $i }})" class="flex-1 min-w-0 truncate text-left text-[length:var(--fa-text-md)] py-1 {{ $selectedBlockIndex === $i ? 'font-medium text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}" data-fa-block="{{ $b['block_type'] }}">
                                {{ $blockLabels[$b['block_type']] ?? $b['block_type'] }}
                            </a>
                            <x-fa::icon-button icon="heroicon-m-chevron-up" label="Block nach oben" size="sm" wire:click="blockVerschieben({{ $i }}, -1)" :disabled="$i === 0" class="disabled:opacity-40" />
                            <x-fa::icon-button icon="heroicon-m-chevron-down" label="Block nach unten" size="sm" wire:click="blockVerschieben({{ $i }}, 1)" :disabled="$i === count($layout) - 1" class="disabled:opacity-40" />
                            <x-fa::icon-button icon="heroicon-m-x-mark" label="Block entfernen" size="sm" tone="danger" wire:click="blockEntfernen({{ $i }})" />
                        </div>
                    @empty
                        <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Blöcke" compact>Links unter „Block hinzufügen“ den ersten Block wählen.</x-fa::empty>
                    @endforelse
                </div>
                @if(count($layout) > 1)
                    <p class="{{ $hilfe }}">Reihenfolge per Ziehen oder mit den Pfeilen ändern. Block anklicken zeigt seine Einstellungen.</p>
                @endif
            </x-fa::section>

            {{-- Einstellungen für den gewählten Block --}}
            @if($selectedBlockIndex !== null && isset($layout[$selectedBlockIndex]))
                @php
                    $sb = $layout[$selectedBlockIndex];
                    $bt = $sb['block_type'];
                    $i = $selectedBlockIndex;
                @endphp
                <x-fa::section :title="'Block: ' . ($blockLabels[$bt] ?? $bt)" icon="heroicon-o-adjustments-horizontal" data-fa-style-panel>
                    @if(in_array($bt, ['chapter_loop', 'dish_list'], true))
                        <div class="flex flex-col gap-2">
                            <label class="{{ $hakenZeile }}"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_price" class="{{ $haken }}"> Preise zeigen</label>
                            <label class="{{ $hakenZeile }}"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_codes" class="{{ $haken }}"> Allergen-Kennzeichen zeigen</label>
                            <label class="{{ $hakenZeile }}"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_dish_photos" class="{{ $haken }}"> Gericht-Fotos zeigen</label>
                            <label class="{{ $hakenZeile }}"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_chapter_image" class="{{ $haken }}"> Kapitel-Bilder zeigen</label>
                        </div>
                        <x-fa::choice name="layout.{{ $i }}.style.dish_columns" label="Gerichte nebeneinander" :options="['1' => '1 Spalte (Standard)', '2' => '2 Spalten']" />
                        @if($bt === 'chapter_loop')
                            <x-fa::field label="Bezeichnung Kapitel" for="design-kicker-haupt">
                                <x-fa::input id="design-kicker-haupt" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_haupt" placeholder="Kapitel" />
                            </x-fa::field>
                            <x-fa::field label="Bezeichnung Unterkapitel" for="design-kicker-unter" hint="Die kleine Zeile über dem Titel. Leer heißt „Kapitel“ bzw. „Abschnitt“. Für Angebote zum Beispiel „Leistung“.">
                                <x-fa::input id="design-kicker-unter" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_unter" placeholder="Abschnitt" />
                            </x-fa::field>
                        @endif
                    @elseif($bt === 'cover')
                        <div class="flex flex-col gap-2">
                            <label class="{{ $hakenZeile }}"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_cover_image" class="{{ $haken }}"> Titelbild zeigen</label>
                            <label class="{{ $hakenZeile }}"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_logo" class="{{ $haken }}"> Logo zeigen</label>
                        </div>
                        <x-fa::choice name="layout.{{ $i }}.style.cover_fit" label="Titelbild-Darstellung" :options="['cover' => 'Füllen (Ausschnitt)', 'contain' => 'Ganz zeigen']" />
                        {{-- Bug-Runde 2026-09-17: frei einstellbar statt drei fester Stufen.
                             Der Wert ist % der Fensterhöhe — Alt-Designs mit klein/mittel/groß
                             werden beim Anzeigen auf ihren Zahlenwert gespiegelt. --}}
                        @php
                            $chVal = $sb['style']['cover_height'] ?? 'gross';
                            $chVh = is_numeric($chVal) ? (int) $chVal : (['klein' => 46, 'mittel' => 64, 'gross' => 88][$chVal] ?? 88);
                        @endphp
                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Titelbild-Höhe</span>
                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $chVh }} % der Bildschirmhöhe</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="range" min="10" max="100" step="1" value="{{ $chVh }}"
                                       @change="$wire.stilSetzen({{ $i }}, 'cover_height', parseInt($event.target.value))"
                                       class="flex-1 accent-[var(--fa-accent)]" aria-label="Titelbild-Höhe in Prozent" data-fa-cover-height-range>
                                <input type="number" min="10" max="100" step="1" value="{{ $chVh }}"
                                       @change="$wire.stilSetzen({{ $i }}, 'cover_height', parseInt($event.target.value))"
                                       class="fa-control h-9 w-20 text-right tabular-nums text-[length:var(--fa-text-md)]" aria-label="Titelbild-Höhe in Prozent" data-fa-cover-height-num>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 mt-1">
                                <label for="design-cover-max" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Höchstens</label>
                                <input id="design-cover-max" type="number" min="120" max="2000" step="10" placeholder="ohne Grenze"
                                       value="{{ $sb['style']['cover_height_max_px'] ?? '' }}"
                                       @change="$wire.stilSetzen({{ $i }}, 'cover_height_max_px', $event.target.value === '' ? null : parseInt($event.target.value))"
                                       class="fa-control h-9 w-32 text-right tabular-nums text-[length:var(--fa-text-md)]" data-fa-cover-height-max>
                                <span class="{{ $hilfe }}">Pixel, begrenzt die Höhe auf großen Bildschirmen</span>
                            </div>
                        </div>
                    @elseif(in_array($bt, ['text', 'heading'], true))
                        <x-fa::field label="Text" for="design-block-text">
                            <x-fa::textarea id="design-block-text" wire:model.blur="layout.{{ $i }}.style.text" rows="4" />
                        </x-fa::field>
                    @elseif($bt === 'image')
                        <div class="flex flex-col gap-1.5">
                            <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Bild</span>
                            <div class="flex items-center gap-3 flex-wrap">
                                @if($blockImageUrl)
                                    <img src="{{ $blockImageUrl }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                    <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="blockBildEntfernen({{ $i }})">Bild entfernen</x-fa::button>
                                @endif
                                <input type="file" wire:model="blockImageUpload" accept="image/*" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] min-w-0">
                                <x-fa::signal tone="info" icon="heroicon-m-arrow-path" wire:loading wire:target="blockImageUpload">Wird hochgeladen</x-fa::signal>
                            </div>
                            @error('blockImageUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                            <p class="{{ $hilfe }}">Freie Bildstrecke, unabhängig von Konzept und Kapitel.</p>
                        </div>
                        <x-fa::choice name="layout.{{ $i }}.style.img_fit" label="Darstellung" :options="['cover' => 'Füllen (Ausschnitt)', 'contain' => 'Ganz zeigen', 'auto' => 'Natürliche Höhe']" />
                        <x-fa::choice name="layout.{{ $i }}.style.img_height" label="Höhe" :options="['klein' => 'Klein (Panorama)', 'mittel' => 'Mittel', 'gross' => 'Groß']" />
                        <x-fa::choice name="layout.{{ $i }}.style.img_width" label="Breite" :options="['voll' => 'Breit', 'schmal' => 'Schmal (Lesebreite)', 'bleed' => 'Randlos']" />
                    @elseif($bt === 'spacer')
                        <x-fa::field label="Höhe in Pixel" for="design-spacer-hoehe">
                            <x-fa::input id="design-spacer-hoehe" type="number" min="0" wire:model.blur="layout.{{ $i }}.style.height" numeric class="max-w-[8rem]" />
                        </x-fa::field>
                    @elseif($bt === 'price_summary')
                        <x-fa::choice name="layout.{{ $i }}.style.preis_anzeige" label="Preis pro Person" :options="['netto' => 'Nur netto', 'brutto' => 'Nur brutto', 'beide' => 'Netto und brutto']" />
                        <p class="{{ $hilfe }}">Brutto (inkl. MwSt.) erscheint nur, wenn Preise vorhanden sind, also beim Angebot. Ein Foodbook ohne MwSt. bleibt netto.</p>
                    @elseif($bt === 'preis_aufschluesselung')
                        <x-fa::choice name="layout.{{ $i }}.style.preis_anzeige" label="Summen" :options="['netto' => 'Nur netto', 'brutto' => 'Nur brutto', 'beide' => 'Netto, MwSt. und brutto']" />
                        <p class="{{ $hilfe }}">Die Zeilen je Leistung sind immer netto. Diese Wahl steuert die Summenzeilen darunter.</p>
                    @else
                        <p class="{{ $hilfe }}">Für diesen Block gibt es keine Einstellungen.</p>
                    @endif
                </x-fa::section>
            @endif

            {{-- Globale Design-Werte (Kunden-Branding) --}}
            <x-fa::section title="Farben und Schrift" icon="heroicon-o-paint-brush">
                <div class="grid grid-cols-2 gap-3">
                    @foreach($farbFelder as $feld => $farbLabel)
                        <x-fa::field :label="$farbLabel" for="design-farbe-{{ $feld }}">
                            <div class="flex items-center gap-2">
                                <input id="design-farbe-{{ $feld }}" type="color" wire:model.live="tokens.palette.{{ $feld }}"
                                       class="h-9 w-12 shrink-0 cursor-pointer rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] p-0.5">
                                <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] uppercase truncate">{{ $tokens['palette'][$feld] ?? '' }}</span>
                            </div>
                        </x-fa::field>
                    @endforeach
                </div>
                <x-fa::choice name="tokens.typography.heading" label="Schrift der Überschriften" :options="['serif' => 'Mit Serifen', 'sans' => 'Ohne Serifen']" />
                <x-fa::field label="Schriftgröße" for="design-schrift-skala" hint="1 ist Standard, 1,2 macht alles 20 % größer.">
                    <x-fa::input id="design-schrift-skala" type="number" step="0.05" min="0.7" max="2" wire:model.blur="tokens.typography.scale" numeric class="max-w-[8rem]" />
                </x-fa::field>
                <x-fa::choice name="tokens.spacing" label="Abstände" :options="['compact' => 'Kompakt', 'comfortable' => 'Normal', 'roomy' => 'Großzügig']" />
                <x-fa::choice name="tokens.nav" label="Navigation" :options="['none' => 'Keine', 'anchor' => 'Sprungmenü (schwebend)', 'sidebar' => 'Seitenleiste links']" />
                <label class="{{ $hakenZeile }}">
                    <input type="checkbox" wire:model.live="tokens.lightbox" class="{{ $haken }}">
                    Bilder per Klick vergrößern
                </label>
                <x-fa::choice name="tokens.band_style" label="Bild-Band" :options="['grid' => 'Raster (Standard)', 'rondell' => 'Karussell']" />
                <div class="flex flex-col gap-1">
                    <x-fa::choice name="tokens.speiseplan_layout" label="Speiseplan-Ausgabe" :options="['grid' => 'Wochen-Tabelle (Standard)', 'liste' => 'Liste, Tag für Tag']" />
                    <p class="{{ $hilfe }}">Wirkt nur bei Speiseplänen.</p>
                </div>
            </x-fa::section>

            {{-- Stufe 2 „Leinwand via Code": eigenes, sandboxed CSS auf die Blöcke (kein HTML/JS/@import). --}}
            <x-fa::section title="Eigenes CSS" icon="heroicon-o-code-bracket"
                           description="Für Fortgeschrittene. Look beschreiben und die KI schreibt das CSS, oder direkt selbst schreiben. Nur Gestaltung, keine Skripte. Wirkt in der Vorschau nach Verlassen des Felds und wird beim Speichern übernommen.">
                <div class="flex flex-col gap-2">
                    <x-fa::field label="Gewünschter Look" for="design-css-brief">
                        <x-fa::input id="design-css-brief" wire:model="cssBrief" placeholder="z. B. modernes Catering-Design wie eine Website" data-fa-css-brief />
                    </x-fa::field>
                    <div class="flex justify-end">
                        <x-fa::button variant="ai" icon="heroicon-m-sparkles" wire:click="cssGenerieren" wire:loading.attr="disabled" wire:target="cssGenerieren" data-fa-css-generate>
                            <span wire:loading.remove wire:target="cssGenerieren">CSS mit KI erzeugen</span>
                            <span wire:loading wire:target="cssGenerieren">KI schreibt</span>
                        </x-fa::button>
                    </div>
                </div>
                <x-fa::field label="CSS" for="design-css" hint="Danach lässt sich das CSS von Hand feinjustieren. Farben und Schrift oben gelten weiter.">
                    <textarea id="design-css" wire:model.blur="customCss" rows="7" class="fa-control py-2 font-mono text-[length:var(--fa-text-sm)] leading-relaxed" placeholder=".pt-hero-title { letter-spacing: .04em; }
.pt-section-title { text-transform: uppercase; }" data-fa-design-css></textarea>
                </x-fa::field>
            </x-fa::section>
            </fieldset>
        </aside>
    </div>
</div>
