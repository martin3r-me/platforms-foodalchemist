<div class="space-y-4" data-fa-designs-builder>
    {{-- Feedback --}}
    @if($status)
        <div class="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-3 py-2" data-fa-designs-status>{{ $status }}</div>
    @endif
    @if($fehler)
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2" data-fa-designs-fehler>{{ $fehler }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        {{-- ── Links: Designs + Palette ─────────────────────────────── --}}
        <aside class="lg:col-span-3 space-y-4">
            <div class="rounded-xl border border-gray-200 p-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Meine Designs</h3>
                <div class="space-y-1">
                    @forelse($designs as $d)
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="waehlen({{ $d['id'] }})"
                                class="flex-1 text-left text-sm px-2 py-1 rounded hover:bg-gray-100 {{ $selectedId === $d['id'] ? 'bg-violet-50 text-violet-800 font-medium' : '' }}">
                                {{ $d['name'] }}@unless($d['owned'])<span class="text-[10px] text-gray-400"> (geerbt)</span>@endunless
                            </button>
                            <button type="button" wire:click="duplizieren({{ $d['id'] }})" title="Duplizieren" class="text-gray-400 hover:text-gray-700 px-1">⧉</button>
                            @if($d['owned'])
                                <button type="button" wire:click="loeschen({{ $d['id'] }})" wire:confirm="Design {{ $d['name'] }} löschen?" title="Löschen" class="text-gray-400 hover:text-red-600 px-1 text-[11px]">Löschen</button>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Noch keine eigenen Designs — starte aus einer Vorlage.</p>
                    @endforelse
                </div>
                <div class="mt-3 pt-3 border-t border-gray-100">
                    <p class="text-[11px] text-gray-500 mb-1">Neu aus Vorlage</p>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" wire:click="neuAusBuiltin('editorial')" class="text-xs px-2 py-1 rounded border border-gray-200 hover:bg-gray-50">Editorial</button>
                        <button type="button" wire:click="neuAusBuiltin('angebot')" class="text-xs px-2 py-1 rounded border border-gray-200 hover:bg-gray-50">Angebot</button>
                        <button type="button" wire:click="neuAusBuiltin('menu')" class="text-xs px-2 py-1 rounded border border-gray-200 hover:bg-gray-50">Speisekarte</button>
                        <button type="button" wire:click="neuAusBuiltin('kiosk')" class="text-xs px-2 py-1 rounded border border-gray-200 hover:bg-gray-50">Kiosk</button>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 p-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Block hinzufügen</h3>
                <div class="grid grid-cols-2 gap-1">
                    @foreach($blockTypen as $t)
                        <button type="button" wire:click="blockHinzufuegen('{{ $t }}')"
                            class="text-xs px-2 py-1 rounded border border-gray-200 hover:bg-violet-50 hover:border-violet-200 text-left" data-fa-add-block="{{ $t }}">
                            + {{ $blockLabels[$t] ?? $t }}
                        </button>
                    @endforeach
                </div>
                <p class="text-[10px] text-gray-400 mt-2">Alle Blöcke sind datengebunden — es gibt keinen EK-/Interna-Block.</p>
            </div>
        </aside>

        {{-- ── Mitte: Kopf + Live-Vorschau ──────────────────────────── --}}
        <section class="lg:col-span-5 space-y-3">
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-[11px] text-gray-500">Design-Name</label>
                    <input type="text" wire:model="name" class="w-full text-sm border border-gray-300 rounded px-2 py-1" placeholder="Design-Name" data-fa-design-name>
                </div>
                <button type="button" wire:click="speichern" class="text-sm px-4 py-1.5 rounded-lg bg-violet-600 text-white font-medium hover:bg-violet-700" data-fa-design-save>
                    {{ $selectedId ? 'Speichern' : 'Anlegen' }}
                </button>
            </div>

            {{-- Form-Scoping: für welche Ausgabeformen dieses Design im Picker auftaucht (leer = alle). --}}
            <div class="flex flex-wrap items-center gap-3" data-fa-output-types>
                <span class="text-[11px] text-gray-500">Gilt für</span>
                @foreach(['foodbook' => 'Foodbook', 'angebot' => 'Angebot', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan'] as $ot => $otLabel)
                    <label class="flex items-center gap-1.5 text-sm">
                        <input type="checkbox" value="{{ $ot }}" wire:model.live="outputTypes" class="rounded border-gray-300"> {{ $otLabel }}
                    </label>
                @endforeach
                <span class="text-[11px] text-gray-400">(nichts angehakt = alle Formen)</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <label class="text-[11px] text-gray-500">Vorschau</label>
                <select wire:model.live="previewType" class="text-sm border border-gray-300 rounded px-2 py-1">
                    <option value="foodbook">Foodbook</option>
                    <option value="angebot">Angebot</option>
                    <option value="speisekarte">Speisekarte</option>
                    <option value="speiseplan">Speiseplan</option>
                </select>
                <select wire:model.live="previewSourceId" class="text-sm border border-gray-300 rounded px-2 py-1">
                    <option value="">— Quelle wählen —</option>
                    @foreach($quellenOptionen as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="rounded-xl border border-gray-200 overflow-hidden bg-gray-50">
                @if($vorschauHtml !== null)
                    <iframe class="w-full" style="height: 640px; border: 0; background: #fff;" srcdoc="{{ $vorschauHtml }}" title="Live-Vorschau" data-fa-preview-frame></iframe>
                @else
                    <div class="h-[640px] flex items-center justify-center text-sm text-gray-400 text-center px-6">
                        Wähle Form + Quelle, um das Design live zu sehen.
                    </div>
                @endif
            </div>
        </section>

        {{-- ── Rechts: Struktur + Style + Tokens ────────────────────── --}}
        <aside class="lg:col-span-4 space-y-4">
            <div class="rounded-xl border border-gray-200 p-3" x-data="{ from: null }">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Struktur ({{ count($layout) }} Blöcke)</h3>
                <div class="space-y-1" data-fa-structure>
                    @forelse($layout as $i => $b)
                        <div draggable="true"
                             @dragstart="from = {{ $i }}"
                             @dragover.prevent
                             @drop.prevent="$wire.bloeckeNachDrop(from, {{ $i }}); from = null"
                             wire:key="block-{{ $i }}-{{ $b['block_type'] }}"
                             class="flex items-center gap-1 text-sm px-2 py-1 rounded border cursor-move {{ $selectedBlockIndex === $i ? 'border-violet-300 bg-violet-50' : 'border-gray-200' }}">
                            <span class="text-gray-300 select-none">⠿</span>
                            <button type="button" wire:click="blockWaehlen({{ $i }})" class="flex-1 text-left" data-fa-block="{{ $b['block_type'] }}">
                                {{ $blockLabels[$b['block_type']] ?? $b['block_type'] }}
                            </button>
                            <button type="button" wire:click="blockVerschieben({{ $i }}, -1)" title="hoch" class="text-gray-400 hover:text-gray-700 px-1" @disabled($i === 0)>▲</button>
                            <button type="button" wire:click="blockVerschieben({{ $i }}, 1)" title="runter" class="text-gray-400 hover:text-gray-700 px-1" @disabled($i === count($layout) - 1)>▼</button>
                            <button type="button" wire:click="blockEntfernen({{ $i }})" title="entfernen" class="text-gray-400 hover:text-red-600 px-1">×</button>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Noch keine Blöcke — links hinzufügen.</p>
                    @endforelse
                </div>
            </div>

            {{-- Style-Panel für den gewählten Block --}}
            @if($selectedBlockIndex !== null && isset($layout[$selectedBlockIndex]))
                @php $sb = $layout[$selectedBlockIndex]; $bt = $sb['block_type']; $i = $selectedBlockIndex; @endphp
                <div class="rounded-xl border border-gray-200 p-3 space-y-2" data-fa-style-panel>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stil · {{ $blockLabels[$bt] ?? $bt }}</h3>

                    @if(in_array($bt, ['chapter_loop', 'dish_list'], true))
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_price"> Preise zeigen</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_codes"> Allergen-Codes zeigen</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_dish_photos"> Gericht-Fotos zeigen</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_chapter_image"> Kapitel-Bilder zeigen</label>
                        <label class="block text-sm">Gericht-Spalten
                            <select wire:model.live="layout.{{ $i }}.style.dish_columns" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="1">1 Spalte (Standard)</option>
                                <option value="2">2 Spalten (Raster)</option>
                            </select>
                        </label>
                        @if($bt === 'chapter_loop')
                            <label class="block text-sm">Bezeichnung Kapitel
                                <input type="text" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_haupt" placeholder="Kapitel" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                            </label>
                            <label class="block text-sm">Bezeichnung Unterkapitel
                                <input type="text" wire:model.live.debounce.400ms="layout.{{ $i }}.style.kicker_unter" placeholder="Abschnitt" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                            </label>
                            <p class="text-[11px] text-gray-400">Der kleine Kicker über dem Titel. Leer = „Kapitel" / „Abschnitt". Für Angebote z. B. „Leistung".</p>
                        @endif
                    @elseif($bt === 'cover')
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_cover_image"> Coverbild zeigen</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="layout.{{ $i }}.style.show_logo"> Logo zeigen</label>
                        <label class="block text-sm">Coverbild-Darstellung
                            <select wire:model.live="layout.{{ $i }}.style.cover_fit" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="cover">Füllen (Ausschnitt)</option>
                                <option value="contain">Ganz zeigen (kein Beschnitt)</option>
                            </select>
                        </label>
                        <label class="block text-sm">Coverbild-Höhe
                            <select wire:model.live="layout.{{ $i }}.style.cover_height" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="klein">klein</option>
                                <option value="mittel">mittel</option>
                                <option value="gross">groß</option>
                            </select>
                        </label>
                    @elseif(in_array($bt, ['text', 'heading'], true))
                        <label class="block text-[11px] text-gray-500">Text</label>
                        <textarea wire:model.blur="layout.{{ $i }}.style.text" rows="3" class="w-full text-sm border border-gray-300 rounded px-2 py-1"></textarea>
                    @elseif($bt === 'image')
                        <label class="block text-[11px] text-gray-500">Bild</label>
                        <div class="flex items-center gap-3 flex-wrap">
                            @if($blockImageUrl)
                                <img src="{{ $blockImageUrl }}" alt="" class="h-12 w-20 object-cover rounded border border-black/10">
                                <button type="button" wire:click="blockBildEntfernen({{ $i }})" class="text-rose-600 text-[11px] underline">entfernen</button>
                            @endif
                            <input type="file" wire:model="blockImageUpload" accept="image/*" class="text-[11px]">
                            <div wire:loading wire:target="blockImageUpload" class="text-[11px] text-gray-400">lädt …</div>
                        </div>
                        @error('blockImageUpload')<div class="text-[11px] text-rose-600 mt-1">{{ $message }}</div>@enderror
                        <p class="text-[11px] text-gray-400 mt-1">Freie Bildstrecke — unabhängig von Konzept/Kapitel.</p>
                        <label class="block text-sm mt-2">Darstellung
                            <select wire:model.live="layout.{{ $i }}.style.img_fit" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="cover">Füllen (Ausschnitt)</option>
                                <option value="contain">Ganz zeigen (kein Beschnitt)</option>
                                <option value="auto">Natürliche Höhe</option>
                            </select>
                        </label>
                        <label class="block text-sm">Höhe
                            <select wire:model.live="layout.{{ $i }}.style.img_height" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="klein">klein (Panorama)</option>
                                <option value="mittel">mittel</option>
                                <option value="gross">groß</option>
                            </select>
                        </label>
                        <label class="block text-sm">Breite
                            <select wire:model.live="layout.{{ $i }}.style.img_width" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="voll">breit</option>
                                <option value="schmal">schmal (Lesebreite)</option>
                                <option value="bleed">randlos (volle Breite)</option>
                            </select>
                        </label>
                    @elseif($bt === 'spacer')
                        <label class="block text-[11px] text-gray-500">Höhe (px)</label>
                        <input type="number" min="0" wire:model.blur="layout.{{ $i }}.style.height" class="w-24 text-sm border border-gray-300 rounded px-2 py-1">
                    @elseif($bt === 'price_summary')
                        <label class="block text-sm">Preis pro Person
                            <select wire:model.live="layout.{{ $i }}.style.preis_anzeige" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="netto">nur netto</option>
                                <option value="brutto">nur brutto (inkl. MwSt)</option>
                                <option value="beide">netto + brutto</option>
                            </select>
                        </label>
                        <p class="text-[11px] text-gray-400">Brutto erscheint nur, wenn Preise vorhanden sind (Angebot). Beim Foodbook ohne MwSt bleibt es netto.</p>
                    @elseif($bt === 'preis_aufschluesselung')
                        <label class="block text-sm">Summen-Anzeige
                            <select wire:model.live="layout.{{ $i }}.style.preis_anzeige" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                                <option value="netto">nur netto</option>
                                <option value="brutto">nur brutto (inkl. MwSt)</option>
                                <option value="beide">netto + MwSt + brutto</option>
                            </select>
                        </label>
                        <p class="text-[11px] text-gray-400">Zeilen je Leistung sind netto; diese Wahl steuert die Summenzeilen darunter.</p>
                    @else
                        <p class="text-xs text-gray-400">Für diesen Block gibt es keine Stil-Optionen.</p>
                    @endif
                </div>
            @endif

            {{-- Globale Tokens --}}
            <div class="rounded-xl border border-gray-200 p-3 space-y-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Design-Tokens</h3>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-sm">Primär
                        <input type="color" wire:model.live="tokens.palette.primary" class="block w-full h-8 border border-gray-300 rounded">
                    </label>
                    <label class="text-sm">Akzent
                        <input type="color" wire:model.live="tokens.palette.accent" class="block w-full h-8 border border-gray-300 rounded">
                    </label>
                    <label class="text-sm">Hintergrund
                        <input type="color" wire:model.live="tokens.palette.bg" class="block w-full h-8 border border-gray-300 rounded">
                    </label>
                    <label class="text-sm">Text
                        <input type="color" wire:model.live="tokens.palette.text" class="block w-full h-8 border border-gray-300 rounded">
                    </label>
                </div>
                <label class="block text-sm">Überschrift-Schrift
                    <select wire:model.live="tokens.typography.heading" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                        <option value="serif">Serif</option>
                        <option value="sans">Sans</option>
                    </select>
                </label>
                <label class="block text-sm">Schriftgröße (Skala)
                    <input type="number" step="0.05" min="0.7" max="2" wire:model.blur="tokens.typography.scale" class="block w-24 text-sm border border-gray-300 rounded px-2 py-1">
                </label>
                <label class="block text-sm">Abstände
                    <select wire:model.live="tokens.spacing" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                        <option value="compact">kompakt</option>
                        <option value="comfortable">normal</option>
                        <option value="roomy">großzügig</option>
                    </select>
                </label>
                <label class="block text-sm">Navigation
                    <select wire:model.live="tokens.nav" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                        <option value="none">keine</option>
                        <option value="anchor">Sprungmenü (schwebend)</option>
                        <option value="sidebar">Sidebar (fest, links)</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm mt-1">
                    <input type="checkbox" wire:model.live="tokens.lightbox" class="rounded border-gray-300">
                    Bilder klickbar vergrößern (Lightbox)
                </label>
                <label class="block text-sm">Bild-Band
                    <select wire:model.live="tokens.band_style" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                        <option value="grid">Raster (Standard)</option>
                        <option value="rondell">Rondell (Karussell)</option>
                    </select>
                </label>
                <label class="block text-sm">Speiseplan-Ausgabe
                    <select wire:model.live="tokens.speiseplan_layout" class="block w-full text-sm border border-gray-300 rounded px-2 py-1">
                        <option value="grid">Wochen-Tabelle (Standard)</option>
                        <option value="liste">Liste (Tag für Tag)</option>
                    </select>
                    <span class="block text-[11px] text-gray-400 mt-0.5">Nur für Speiseplan-Ausgaben wirksam.</span>
                </label>
            </div>

            {{-- Stufe 2 „Leinwand via Code": eigenes, sandboxed CSS auf die Blöcke (kein HTML/JS/@import). --}}
            <div class="mt-4 rounded-xl border border-gray-200 p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Eigenes CSS (fortgeschritten / KI)</h3>
                <p class="text-[11px] text-gray-400 mt-1 mb-2">Beschreibe den Look — die KI erzeugt das CSS. Oder schreib/feile direkt am CSS. Sandboxed (kein HTML/JS, kein @import); wirkt in der Live-Vorschau (nach Verlassen des Felds), beim Speichern eingefroren.</p>
                <div class="flex items-start gap-2 mb-2">
                    <input type="text" wire:model="cssBrief" placeholder="z. B. modernes Catering-Design mit Website-Feeling" class="flex-1 text-sm text-gray-900 border border-gray-300 rounded px-2 py-1" data-fa-css-brief>
                    <button type="button" wire:click="cssGenerieren" wire:loading.attr="disabled" wire:target="cssGenerieren" class="text-sm px-3 py-1.5 rounded-lg bg-violet-600 text-white font-medium hover:bg-violet-700 whitespace-nowrap" data-fa-css-generate>
                        <span wire:loading.remove wire:target="cssGenerieren">Mit KI erzeugen</span>
                        <span wire:loading wire:target="cssGenerieren">erzeuge …</span>
                    </button>
                </div>
                <textarea wire:model.blur="customCss" rows="7" class="block w-full text-[12px] font-mono text-gray-900 border border-gray-300 rounded px-2 py-1" placeholder=".pt-hero-title { letter-spacing: .04em; }
.pt-section-title { text-transform: uppercase; }" data-fa-design-css></textarea>
                <p class="text-[11px] text-gray-400 mt-1">Danach kannst du das CSS von Hand feinjustieren und die Farben/Tokens oben anpassen.</p>
            </div>
        </aside>
    </div>
</div>
