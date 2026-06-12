{{-- Editor-Parität (Ist-App-Vorbild): EIN Voll-Editor — Stammdaten · Zutaten inline (P-8-Kern)
     · KPI-Leiste · Equipment gruppiert · Eigenschaften · Beschreibung · Zubereitung (Tabs) · Notizen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- R4 (Dominique): Voll-Editor nimmt den ganzen Bildschirm — 19-Zutaten-Rezepte brauchen die Fläche --}}
<x-foodalchemist::modal name="recipe-modal" :title="$neu ? 'Basisrezept anlegen' : 'Rezept bearbeiten: ' . $form['name']" size="max-w-[100rem]">
    {{-- Aktionsleiste (D-5 §4.2.1) --}}
    <x-slot:actions>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-rezept-speichern>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
        @if(!$neu)
            <button type="button" wire:click="loeschen" wire:confirm="Rezept wirklich löschen? (Als Sub-Rezept referenzierte Rezepte sind geschützt)"
                    class="{{ $btnGhostXs }} text-rose-600 dark:text-rose-400" data-rezept-loeschen>Löschen</button>
            <span class="text-gray-300 dark:text-gray-600">|</span>
            <button type="button" wire:click="allesAnreichern" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400"
                    title="D-5 §4.4: Vorschläge für Beschreibung · Kategorie · Geschmack (Review, nie Auto-Persistenz)" data-alles-anreichern>✨ Alles anreichern</button>
            {{-- R6: Template-Markierung (Basis für «Aus Template» im Browser) --}}
            <button type="button" wire:click="templateToggle" class="{{ $btnGhostXs }} {{ $istTemplate ? 'text-orange-600 dark:text-orange-400' : '' }}"
                    title="Template = Vorlage für neue Rezepte (Browser: «Aus Template»)" data-template-toggle>
                📐 {{ $istTemplate ? 'Template ✓' : 'Als Template' }}
            </button>
        @endif
    </x-slot:actions>

    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    {{-- ✨-Anreichern-Lauf (M7-06-Mechanik auf EIN Rezept) --}}
    @if($bulkRun !== null)
        <div class="mb-3 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs flex items-center gap-2"
             @if($bulkRun->status === 'running') wire:poll.2s @endif data-anreichern-status>
            @if($bulkRun->status === 'running')
                <span>✨ Anreicherung läuft …</span>
            @else
                <span>✨ {{ $bulkOffen }} Vorschläge offen{{ $bulkRun->fehler > 0 ? " · {$bulkRun->fehler} Fehler" : '' }}</span>
                <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-anreichern-uebernehmen>Alle übernehmen</button>
            @endif
        </div>
    @endif

    {{-- STAMMDATEN (§4.2.2) — ✨-Aktionen im Sektions-Header (Ist-App-Pattern) --}}
    <x-foodalchemist::modal-section title="Stammdaten">
        <x-slot:actions>
            <button type="button" wire:click="namePutzen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="§1-Syntax normalisieren">✨ Name putzen</button>
            @if(!$neu)
                <button type="button" wire:click="ai_kategorie" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="D-1-Klassifikation (GL-07-Vorschlag unten)">✨ Kategorie</button>
            @endif
            <button type="button" wire:click="kiFertigung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Fertigungstiefe aus den Zutaten">✨ Fertigung</button>
        </x-slot:actions>

        <div>
            <label class="block {{ $label }} mb-1">Name *</label>
            <input type="text" wire:model.live.debounce.300ms="form.name" placeholder="Schaumsauce: Beurre Blanc" class="{{ $input }} !text-base" data-rezept-name />
            <p class="text-[11px] text-gray-400 mt-1">Syntax §1.2: <code>Typ: Bezeichnung (Variante)</code>, Title Case.
                @if($keyVorschau !== '')<span class="font-mono" data-key-vorschau>recipe_key: {{ $keyVorschau }}{{ $neu ? '' : ' (bleibt beim Edit stabil)' }}</span>@endif
            </p>
        </div>
        <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block {{ $label }} mb-1">Herkunft / Quelle <span class="normal-case text-gray-400">(nicht im Namen — §1.6)</span></label>
                <input type="text" wire:model="form.herkunft" placeholder="z. B. Broich, nach Paul, nach Omas Art" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Status (§4.2.8)</label>
                <select wire:model="form.status" class="{{ $input }}" data-rezept-status @disabled($neu)>
                    @foreach(['stub' => 'Stub', 'draft' => 'Entwurf', 'review' => 'Review', 'approved' => 'Freigegeben', 'archived' => 'Archiviert'] as $wert => $lbl)
                        <option value="{{ $wert }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Hauptgruppe * <span class="normal-case text-gray-400">({{ $hauptgruppen->count() }} kuratiert)</span></label>
                <select wire:model.live="form.hauptgruppe_id" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach($hauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->bezeichnung }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Kategorie * <span class="normal-case text-gray-400">({{ $kategorien->count() }} in dieser Hauptgruppe)</span></label>
                <select wire:model.live="form.kategorie_id" class="{{ $input }}" @disabled($kategorien->isEmpty())>
                    <option value="">—</option>
                    @foreach($kategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->bezeichnung }}</option>@endforeach
                </select>
            </div>
        </div>
        @if(isset($kiVorschlag['kategorie']))
            <div class="mt-2 text-xs" data-kategorie-vorschlag>
                <span class="{{ $pill }} {{ $variantPill['primary'] }}">✨ Kategorie: {{ $kiVorschlag['kategorie']['werte']['kategorie_name'] ?? $kiVorschlag['kategorie']['werte']['kategorie_id'] ?? '—' }} · {{ round($kiVorschlag['kategorie']['confidence'] * 100) }} %</span>
                <button type="button" wire:click="accept_kategorie" class="{{ $btnGhostXs }} text-emerald-600">Übernehmen</button>
            </div>
        @endif
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300 mt-3">
            <input type="checkbox" wire:model="form.ist_verkaufsrezept" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
            Verkaufsrezept (D-6 — VK-Felder im VK-Editor)
        </label>
    </x-foodalchemist::modal-section>

    {{-- ZUTATEN (§4.2.3) — der P-8-Kern eingebettet + KPI-Leiste (Ist-App unten) --}}
    @if(!$neu)
        <x-foodalchemist::modal-section title="Zutaten ({{ $voll?->ingredients?->count() ?? 0 }})">
            {{-- R6e: ✨ KI-Überarbeiten (Ist-Button) — freie Anweisung, Vorschau, Übernehmen --}}
            <x-slot:actions>
                <button type="button" wire:click="$toggle('ueberarbeitenOffen')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400"
                        title="Freie Anweisung — KI überarbeitet Zutaten, Mengen, Zubereitung & Beschreibung (Vorschau + Übernehmen)" data-ki-ueberarbeiten>✨ KI-Überarbeiten</button>
            </x-slot:actions>

            @if($ueberarbeitenOffen)
                <div class="mb-3 rounded-lg bg-violet-500/5 border border-violet-500/20 px-3 py-2 space-y-2" data-ueberarbeiten-box>
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="anweisung" wire:keydown.enter="kiUeberarbeiten"
                               placeholder="z. B. «mach das Rezept vegan und halbiere den Zucker»" class="{{ $input }} !py-1.5 flex-1" data-anweisung />
                        <button type="button" wire:click="kiUeberarbeiten" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-ueberarbeiten-start>
                            <span wire:loading.remove wire:target="kiUeberarbeiten">✨ Vorschlagen</span>
                            <span wire:loading wire:target="kiUeberarbeiten">denkt …</span>
                        </button>
                    </div>
                    @if($ueberarbeitung !== null)
                        <div class="rounded-lg bg-white/60 dark:bg-gray-900/60 px-3 py-2 space-y-1.5 max-h-72 overflow-y-auto" data-ueberarbeiten-vorschau>
                            @if(is_string($ueberarbeitung['werte']['aenderungs_notiz'] ?? null))
                                <p class="text-[11px] font-medium text-violet-700 dark:text-violet-300">{{ $ueberarbeitung['werte']['aenderungs_notiz'] }}</p>
                            @endif
                            @if(!empty($ueberarbeitung['werte']['zutaten']))
                                <p class="{{ $dt }}">Zutaten (neu)</p>
                                @foreach($ueberarbeitung['werte']['zutaten'] as $z)
                                    @if(is_array($z))
                                        <p class="text-[11px] text-gray-600 dark:text-gray-300" wire:key="uz-{{ $loop->index }}">
                                            {{ $z['menge'] ?? '?' }} {{ $z['einheit_slug'] ?? '' }} · {{ $z['text'] ?? '—' }}
                                            <span class="text-gray-400">{{ isset($z['id']) ? '(bestehend #' . $z['id'] . ')' : '(neu)' }}</span>
                                        </p>
                                    @endif
                                @endforeach
                            @endif
                            @if(is_string($ueberarbeitung['werte']['beschreibung'] ?? null))
                                <p class="{{ $dt }}">Beschreibung (neu)</p>
                                <p class="text-[11px] text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte']['beschreibung'], 280) }}</p>
                            @endif
                            @if(is_string($ueberarbeitung['werte']['zubereitung'] ?? null))
                                <p class="{{ $dt }}">Zubereitung (neu)</p>
                                <p class="text-[11px] text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte']['zubereitung'], 400) }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="ueberarbeitungUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-ueberarbeiten-uebernehmen>Übernehmen ({{ round($ueberarbeitung['confidence'] * 100) }} %)</button>
                            <button type="button" wire:click="ueberarbeitungVerwerfen" class="{{ $btnGhostXs }}" data-ueberarbeiten-verwerfen>Verwerfen</button>
                            <span class="text-[10px] text-gray-400">Übernehmen schreibt Zutaten-Sync + Texte mit Lineage ki — manuell Gepflegtes bleibt (GL-07).</span>
                        </div>
                    @endif
                </div>
            @endif

            <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="zutaten-inline-{{ $recipeId }}-v{{ $zutatenVersion }}" />

            @if($voll !== null)
                <div class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-2" data-editor-kpis>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Yield</span>
                        <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $voll->yield_kg !== null ? number_format((float) $voll->yield_kg, 3, ',', '.') . ' kg' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">EK gesamt</span>
                        <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $voll->ek_total_eur !== null ? number_format((float) $voll->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-orange-600 dark:text-orange-400">EK / kg</span>
                        <p class="text-xs font-bold text-orange-700 dark:text-orange-300">{{ $voll->ek_per_kg_eur !== null ? number_format((float) $voll->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Mit Preis</span>
                        <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $voll->ek_n_ingredients_priced ?? 0 }}/{{ $voll->ek_n_ingredients_total ?? 0 }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                        <span class="{{ $dt }}">Allergen-Konf.</span>
                        <p class="text-xs font-semibold {{ ['high' => 'text-emerald-600', 'medium' => 'text-amber-600', 'low' => 'text-rose-600'][$voll->allergene_konfidenz] ?? 'text-gray-400' }}">{{ strtoupper((string) $voll->allergene_konfidenz) }}</p>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="block {{ $label }} mb-1">Yield manuell (kg, A-3 — Vorrang vor Auto-Summe)</label>
                    <input type="text" wire:model="form.yield_kg_manual" placeholder="leer = Auto ({{ $voll->yield_kg !== null ? number_format((float) $voll->yield_kg, 3, ',', '.') : '—' }})" class="{{ $input }} !w-48" data-yield-manual />
                </div>
            @endif
        </x-foodalchemist::modal-section>
    @endif

    {{-- EQUIPMENT (§4.2.6) — gruppiert nach Vokabular-Gruppe (Ist-App-Layout) --}}
    <x-foodalchemist::modal-section title="Equipment">
        <x-slot:actions>
            @if(!$neu)<button type="button" wire:click="kiEquipment" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Set-Vorschlag aus den Zutaten (in die Auswahl, nichts persistiert)">✨ Equipment</button>@endif
        </x-slot:actions>
        <div class="space-y-1.5" data-rezept-equipment>
            @foreach($equipmentListe->groupBy(fn ($g) => $g->gruppe ?? 'sonstig') as $gruppe => $geraete)
                <div class="flex items-start gap-2">
                    <span class="{{ $dt }} w-28 shrink-0 pt-1">{{ $gruppe }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($geraete as $geraet)
                            <label class="inline-flex items-center gap-1 {{ $pill }} cursor-pointer transition-colors
                                          {{ in_array((string) $geraet->id, $form['equipment_ids'], true) ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                   wire:key="eq-{{ $geraet->id }}">
                                <input type="checkbox" wire:model.live="form.equipment_ids" value="{{ $geraet->id }}" class="hidden" />
                                {{ $geraet->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-foodalchemist::modal-section>

    {{-- EIGENSCHAFTEN (§4.2.4) --}}
    <x-foodalchemist::modal-section title="Eigenschaften">
        <x-slot:actions>
            <button type="button" wire:click="kiEigenschaften" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Arbeitszeit/Temperatur/Funktion + Geschmack (in die Felder, nichts persistiert)">✨ Eigenschaften</button>
        </x-slot:actions>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block {{ $label }} mb-1">Arbeitszeit (min)</label>
                <input type="number" wire:model="form.arbeitszeit_min" min="0" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Temperatur</label>
                <input type="text" wire:model="form.temperatur" placeholder="z. B. raumtemperatur, warm, kalt" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Funktion</label>
                <input type="text" wire:model="form.funktion" placeholder="z. B. Sauce, Bindung, Topping" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Geschmacksrichtung <span class="normal-case text-gray-400">(via ✨ oder manuell)</span></label>
                <select wire:model="form.geschmacksrichtung" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="suess">süß</option><option value="herzhaft">herzhaft</option><option value="neutral">neutral</option>
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Fertigungstiefe <span class="normal-case text-gray-400">(via ✨ Fertigung oder manuell)</span></label>
                <select wire:model="form.fertigungstiefe" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="from_scratch">From Scratch</option><option value="teilfertig">teilfertig</option><option value="convenience">Convenience</option>
                </select>
            </div>
        </div>
    </x-foodalchemist::modal-section>

    {{-- BESCHREIBUNG (§8) --}}
    <x-foodalchemist::modal-section title="Beschreibung (§8.3 — 3-5 Sätze nüchtern)">
        <x-slot:actions>
            @if(!$neu)
                <button type="button" wire:click="ai_beschreibung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-ai-beschreibung>✨ Beschreibung</button>
                <button type="button" wire:click="manual_beschreibung" class="{{ $btnGhostXs }}" title="aktuellen Text als manuell markieren (Override-First-Schutz)">als manuell</button>
                <button type="button" wire:click="clear_beschreibung" class="{{ $btnGhostXs }}" title="Feld + Lineage leeren">Reset</button>
            @endif
        </x-slot:actions>
        <textarea wire:model="form.beschreibung" rows="3" class="{{ $input }}"></textarea>
        @if(isset($kiVorschlag['beschreibung']))
            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2" data-beschreibung-vorschlag>
                <p class="text-[11px] text-violet-700 dark:text-violet-300 italic">{{ $kiVorschlag['beschreibung']['werte']['beschreibung'] ?? '—' }}</p>
                <button type="button" wire:click="accept_beschreibung" class="{{ $btnGhostXs }} text-emerald-600 mt-1">Übernehmen ({{ round($kiVorschlag['beschreibung']['confidence'] * 100) }} %)</button>
            </div>
        @endif
        @if(!$neu)<p class="text-[10px] text-gray-400 mt-1">Lineage: {{ $zustaende['beschreibung'] }}</p>@endif
    </x-foodalchemist::modal-section>

    {{-- ZUBEREITUNG (§4.2.5) — Schreiben/Vorschau-Tabs (Ist-App) --}}
    <x-foodalchemist::modal-section title="Zubereitung">
        <x-slot:actions>
            @if(!$neu)
                <button type="button" wire:click="ai_zubereitung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-ai-zubereitung>✨ Zubereitung</button>
                <button type="button" wire:click="manual_zubereitung" class="{{ $btnGhostXs }}" title="als manuell markieren">als manuell</button>
                <button type="button" wire:click="clear_zubereitung" class="{{ $btnGhostXs }}">Reset</button>
            @endif
        </x-slot:actions>
        <div x-data="{ tab: 'schreiben' }" data-zubereitung-tabs>
            <div class="flex items-center gap-1 mb-1.5">
                <button type="button" @click="tab = 'schreiben'" :class="tab === 'schreiben' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'" class="{{ $pill }}">Schreiben</button>
                <button type="button" @click="tab = 'vorschau'; $wire.vorschauZubereitung()" :class="tab === 'vorschau' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'" class="{{ $pill }}" data-tab-vorschau>Vorschau</button>
                <span class="text-[10px] text-gray-400 ml-2">Markdown — <code>##</code> für Phasen (Mise en Place / Finish), nummerierte Schritte</span>
                <span class="ml-auto" x-show="tab === 'schreiben'">
                    @include('foodalchemist::livewire.recipes.partials.md-toolbar', ['ziel' => 'zubereitung-text'])
                </span>
            </div>
            <div x-show="tab === 'schreiben'">
                <textarea wire:model="form.zubereitung" id="zubereitung-text" rows="8" class="{{ $input }} font-mono text-[11px]" data-rezept-zubereitung></textarea>
            </div>
            <div x-show="tab === 'vorschau'" x-cloak class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-4 py-3" data-zubereitung-vorschau>
                <div class="prose prose-sm dark:prose-invert max-w-none">
                    {!! $zubereitungVorschau ?? '<p class="text-gray-400">Vorschau lädt …</p>' !!}
                </div>
                {{-- R6: Schritt-Fotos in der Vorschau, gruppiert an der Anleitung --}}
                @foreach($schrittFotos as $schritt => $fotos)
                    <div class="mt-3 pt-2 border-t border-black/5 dark:border-white/10" wire:key="vfg-{{ $schritt }}">
                        <p class="{{ $dt }} mb-1">{{ $schritt === 0 ? 'Rezept-Fotos' : "Fotos zu Schritt {$schritt}" }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($fotos as $foto)
                                <figure class="w-32" wire:key="vf-{{ $foto->id }}">
                                    <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? "Schritt {$schritt}" }}" class="w-32 h-24 object-cover rounded-lg border border-black/10 dark:border-white/10" loading="lazy" />
                                    @if($foto->caption)<figcaption class="text-[10px] text-gray-400 mt-0.5 truncate">{{ $foto->caption }}</figcaption>@endif
                                </figure>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- R6: Step-by-Step-Fotos — Verwaltung (Upload + Löschen), gekoppelt über Schritt-Nr --}}
        @if(!$neu)
            <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-schritt-fotos>
                <p class="{{ $dt }} mb-1.5">📷 Schritt-Fotos ({{ $schrittFotos->flatten()->count() }})</p>
                @if($schrittFotos->isNotEmpty())
                    <div class="space-y-1.5 mb-2">
                        @foreach($schrittFotos as $schritt => $fotos)
                            <div class="flex items-start gap-2" wire:key="sfg-{{ $schritt }}">
                                <span class="shrink-0 w-20 text-[11px] text-gray-400 pt-1">{{ $schritt === 0 ? 'allgemein' : "Schritt {$schritt}" }}</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($fotos as $foto)
                                        <span class="relative group" wire:key="sf-{{ $foto->id }}">
                                            <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}" class="w-16 h-12 object-cover rounded border border-black/10 dark:border-white/10" loading="lazy" />
                                            <button type="button" wire:click="fotoLoeschen({{ $foto->id }})" wire:confirm="Foto löschen?"
                                                    class="hidden group-hover:flex absolute -top-1.5 -right-1.5 w-4 h-4 items-center justify-center rounded-full bg-rose-500 text-white text-[9px]" title="löschen" data-foto-loeschen>✕</button>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="flex flex-wrap items-center gap-2" data-foto-upload>
                    <input type="number" min="0" max="99" wire:model="fotoSchritt" placeholder="Schritt-Nr" title="Schritt-Nummer aus der Zubereitung (leer/0 = allgemeines Rezept-Foto)" class="{{ $input }} !py-1 !w-24" />
                    <input type="file" wire:model="fotoUpload" accept="image/*" class="text-[11px] text-gray-500 file:mr-2 file:px-2 file:py-1 file:rounded-lg file:border-0 file:bg-violet-500/10 file:text-violet-600 dark:file:text-violet-300 file:text-[11px] file:cursor-pointer" data-foto-datei />
                    <input type="text" wire:model="fotoCaption" placeholder="Bildunterschrift (optional)" class="{{ $input }} !py-1 w-56" />
                    <button type="button" wire:click="fotoHochladen" wire:loading.attr="disabled" wire:target="fotoUpload, fotoHochladen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-foto-hochladen>
                        <span wire:loading.remove wire:target="fotoUpload, fotoHochladen">Hochladen</span>
                        <span wire:loading wire:target="fotoUpload, fotoHochladen">lädt …</span>
                    </button>
                    @error('fotoUpload')<span class="text-[11px] text-rose-500">{{ $message }}</span>@enderror
                </div>
            </div>
        @endif
        @if(isset($kiVorschlag['zubereitung']))
            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 max-h-40 overflow-y-auto" data-zubereitung-ki-vorschlag>
                <p class="text-[11px] text-violet-700 dark:text-violet-300 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($kiVorschlag['zubereitung']['werte']['zubereitung'] ?? '—', 900) }}</p>
                <button type="button" wire:click="accept_zubereitung" class="{{ $btnGhostXs }} text-emerald-600 mt-1">Übernehmen ({{ round($kiVorschlag['zubereitung']['confidence'] * 100) }} %)</button>
            </div>
        @endif
        @if(!$neu)<p class="text-[10px] text-gray-400 mt-1">Lineage: {{ $zustaende['zubereitung'] }}</p>@endif
    </x-foodalchemist::modal-section>

    {{-- NOTIZEN (§9.1 — manuelle Insel) --}}
    <x-foodalchemist::modal-section title="Notizen (§9.1 — bleibt bei jedem KI-Sync erhalten)">
        <textarea wire:model="form.notizen_manual" rows="3" class="{{ $input }}" data-rezept-notizen
                  placeholder="z. B. Anpassung im Catering-Kontext, Mengen-Korrektur, …"></textarea>
    </x-foodalchemist::modal-section>

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'recipe-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-rezept-speichern-footer>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
    </x-slot:footer>
</x-foodalchemist::modal>
