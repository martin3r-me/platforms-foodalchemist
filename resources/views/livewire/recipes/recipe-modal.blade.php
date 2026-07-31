{{-- Editor-Parität (Ist-App-Vorbild): EIN Voll-Editor — Stammdaten · Zutaten inline (P-8-Kern)
     · KPI-Leiste · Equipment gruppiert · Eigenschaften · Beschreibung · Zubereitung (Tabs) · Notizen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- R4 (Dominique): Voll-Editor nimmt den ganzen Bildschirm — 19-Zutaten-Rezepte brauchen die Fläche --}}
<x-foodalchemist::modal name="recipe-modal" :title="$neu ? 'Basisrezept anlegen' : 'Rezept bearbeiten'" :title-name="$neu ? null : $form['name']" size="max-w-3xl" :fullscreen="! $neu" :dark-canvas="! $neu" :close-via="'schliessenOderZurueck'">
    {{-- Aktionsleiste (D-5 §4.2.1) --}}
    <x-slot:actions>
        <button type="button" wire:click="speichern" x-on:click="$dispatch('zutaten-speichern', { recipeId: @js($recipeId) })" class="{{ $btnPrimary }}" data-rezept-speichern>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
        @if(!$neu)
            <button type="button" wire:click="loeschen" wire:confirm="Rezept wirklich löschen? (Als Sub-Rezept referenzierte Rezepte sind geschützt)"
                    class="{{ $btnGhostXs }} text-rose-600" data-rezept-loeschen>Löschen</button>
            <span class="text-gray-300">|</span>
            <button type="button" wire:click="allesAnreichern" class="{{ $btnAi }}"
                    title="D-5 §4.4: Vorschläge für Beschreibung · Kategorie · Geschmack (Review, nie Auto-Persistenz)" data-alles-anreichern>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Alles anreichern</button>
            {{-- R6: Template-Markierung (Basis für «Aus Template» im Browser) --}}
            <button type="button" wire:click="templateToggle" class="{{ $btnGhostXs }} {{ $istTemplate ? '!text-orange-600 !bg-orange-500/10 !border-orange-500/20' : '' }}"
                    title="Template = Vorlage für neue Rezepte (Browser: «Aus Template»)" data-template-toggle>
                @svg('heroicon-o-square-2-stack', 'w-3.5 h-3.5') {{ $istTemplate ? 'Template ✓' : 'Als Template' }}
            </button>
        @endif
    </x-slot:actions>

    {{-- Phase 1: KPI-Streifen fix im Modal-Kopf (immer sichtbar, scrollt nie weg) --}}
    @if($voll !== null)
        <x-slot:kpiHeader>
            @php($ekComplete = ($voll->ek_n_ingredients_total ?? 0) > 0 && ($voll->ek_n_ingredients_priced ?? 0) >= ($voll->ek_n_ingredients_total ?? 0))
            {{-- Spec 28 / E0.2: Kacheln + Palette liegen im Baustein `kpi-tiles`.
                 Leitwert = EK/kg (accent, kein Alarm-Orange) · „Mit Preis" grün/bernstein je
                 Vollständigkeit · Allergen-Konf. in der Konfidenz-Farbe. --}}
            <x-foodalchemist::kpi-tiles marker="editor-kpis" :tiles="[
                ['kpi' => 'yield', 'label' => 'Yield',
                 'value' => $voll->yield_kg !== null ? number_format((float) $voll->yield_kg, 3, ',', '.') . ' kg' : '—'],
                ['kpi' => 'ek', 'label' => 'EK gesamt',
                 'value' => $voll->ek_total_eur !== null ? number_format((float) $voll->ek_total_eur, 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'ekkg', 'label' => 'EK / kg', 'tone' => 'accent',
                 'value' => $voll->ek_per_kg_eur !== null ? number_format((float) $voll->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : '—'],
                ['kpi' => 'priced', 'label' => 'Mit Preis', 'tone' => $ekComplete ? 'good' : 'warn',
                 'value' => ($voll->ek_n_ingredients_priced ?? 0) . '/' . ($voll->ek_n_ingredients_total ?? 0)],
                ['kpi' => 'allergen', 'label' => 'Allergen-Konf.',
                 'tone' => ['high' => 'good', 'medium' => 'warn', 'low' => 'bad'][$voll->allergens_confidence] ?? 'neutral',
                 'value' => strtoupper((string) $voll->allergens_confidence)],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($fehler !== null)
        <p class="text-xs text-rose-600 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    {{-- ✨-Anreichern-Lauf (M7-06-Mechanik auf EIN Rezept) --}}
    @if($bulkRun !== null)
        <div class="mb-3 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs flex items-center gap-2"
             @if($bulkRun->status === 'running') wire:poll.2s @endif data-anreichern-status>
            @if($bulkRun->status === 'running')
                <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Anreicherung läuft …</span>
            @else
                <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') {{ $bulkOffen }} Vorschläge offen{{ $bulkRun->failed > 0 ? " · {$bulkRun->failed} Fehler" : '' }}</span>
                <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-anreichern-uebernehmen>Alle übernehmen</button>
            @endif
        </div>
    @endif

    {{-- Spec 28 / E0.1: sticky Tab-Leiste + Alpine-Scope liegen im Baustein `editor-tabs`
         (Panels bleiben hier und alle im DOM — der eingebettete Zutaten-Editor darf nicht neu
         gemountet werden). Start-Tab: «Aufbau», bei Neuanlage «Stammdaten» (Aufbau ist ohne
         Zutaten leer). Die drei Morph-Fallen (wire:key · x-effect-Reset · ein Scope für Leiste
         und Panels) stecken im Baustein. --}}
    <x-foodalchemist::editor-tabs marker="rezept" wire-key="rezept-tabs-{{ $recipeId ?? 'neu' }}"
        :init="$neu ? 'eigenschaften' : 'aufbau'"
        :tabs="[
            'aufbau' => 'Aufbau',
            'eigenschaften' => 'Stammdaten',
            'preparation' => 'Zubereitung',
            'details' => 'Deklaration',
            'sensorik' => $neu ? null : 'Sensorik & Pairing',
            'feedback' => $neu ? null : 'Feedback',
            'notes' => 'Notizen',
        ]">

    {{-- ── Tab: AUFBAU (nur Zutaten) ───────────────────────── --}}
    <div x-show="tab === 'aufbau'" x-cloak class="pt-4 space-y-4">
    {{-- Aufbau = nur Zutaten (Stammdaten liegt jetzt im „Stammdaten"-Tab, 2026-07-31) --}}
    {{-- ZUTATEN (§4.2.3) — der P-8-Kern eingebettet + KPI-Leiste (Ist-App unten) --}}
    @if(!$neu)
        <x-foodalchemist::modal-section title="Zutaten ({{ $voll?->ingredients?->count() ?? 0 }})">
            {{-- R6e: ✨ KI-Überarbeiten (Ist-Button) — freie Anweisung, Vorschau, Übernehmen --}}
            <x-slot:actions>
                <button type="button" wire:click="$toggle('ueberarbeitenOffen')" class="{{ $btnAi }}"
                        title="Freie Anweisung — KI überarbeitet Zutaten, Mengen, Zubereitung & Beschreibung (Vorschau + Übernehmen)" data-ki-ueberarbeiten>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Überarbeiten</button>
                {{-- Spec 03 L6b: Copilot — Prüf-Pass statt Neu-Schreiben (Befunde einzeln annehmen) --}}
                <button type="button" wire:click="$toggle('copilotOffen')" class="{{ $btnAi }}"
                        title="Prüf-Pass: die KI beurteilt Mengen, Einheiten, überflüssige und fehlende Zutaten — je Befund einzeln übernehmbar. Das Rezept bleibt stehen." data-copilot>@svg('heroicon-o-clipboard-document-check', 'w-3.5 h-3.5') Copilot</button>
                {{-- Garverluste: feuert ins eingebettete zutaten-kern (Alpine garverluste() via Window-Event) --}}
                <button type="button" x-on:click="$dispatch('garverluste-vorschlagen')" class="{{ $btnAi }}"
                        title="M4-11: KI-Schätzung der Garverluste je Zutat (GL-07 — geschrieben erst beim Speichern)" data-garverlust-ki>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Garverluste</button>
            </x-slot:actions>

            @if($ueberarbeitenOffen)
                <div class="mb-3 rounded-lg bg-violet-500/5 border border-violet-500/20 px-3 py-2 space-y-2" data-ueberarbeiten-box>
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="anweisung" wire:keydown.enter="kiUeberarbeiten"
                               placeholder="z. B. «mach das Rezept vegan und halbiere den Zucker»" class="{{ $input }} !py-1.5 flex-1" data-anweisung />
                        <button type="button" wire:click="kiUeberarbeiten" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-ueberarbeiten-start>
                            <span wire:loading.remove wire:target="kiUeberarbeiten" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlagen</span>
                            <span wire:loading wire:target="kiUeberarbeiten">denkt …</span>
                        </button>
                    </div>
                    @if($ueberarbeitung !== null)
                        <div class="rounded-lg bg-white/60 px-3 py-2 space-y-1.5 max-h-72 overflow-y-auto" data-ueberarbeiten-vorschau>
                            @if(is_string($ueberarbeitung['werte']['aenderungs_notiz'] ?? null))
                                <p class="text-[11px] font-medium text-violet-700">{{ $ueberarbeitung['werte']['aenderungs_notiz'] }}</p>
                            @endif
                            @if(!empty($ueberarbeitung['werte']['zutaten']))
                                <p class="{{ $dt }}">Zutaten (neu)</p>
                                @foreach($ueberarbeitung['werte']['zutaten'] as $z)
                                    @if(is_array($z))
                                        @php($mv = $ueberarbeitung['match_vorschau'][$loop->index] ?? null)
                                        <p class="text-[11px] text-gray-600 flex flex-wrap items-center gap-x-1.5" wire:key="uz-{{ $loop->index }}">
                                            <span>{{ $z['quantity'] ?? '?' }} {{ $z['einheit_slug'] ?? '' }} · {{ $z['text'] ?? '—' }}</span>
                                            <span class="text-gray-500">{{ isset($z['id']) ? '(bestehend #' . $z['id'] . ')' : '(neu)' }}</span>
                                            @if($mv)
                                                @if($mv['status'] === 'matched')
                                                    <span class="text-emerald-600" title="Bestehende Verknüpfung bleibt">✓ {{ $mv['kind'] === 'gp' ? 'GP' : 'Rezept' }}: {{ $mv['ziel'] ?? '—' }}</span>
                                                @elseif($mv['status'] === 'grounded')
                                                    <span class="text-emerald-600" title="Wird beim Übernehmen automatisch verknüpft">→ {{ $mv['kind'] === 'gp' ? 'GP' : 'Rezept' }}: {{ $mv['ziel'] ?? '—' }}</span>
                                                @else
                                                    <span class="text-violet-600" title="Kein Bestandstreffer — nach dem Übernehmen anlegen">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') {{ $mv['primaer'] === 'basisrezept_anlegen' ? 'Basisrezept anlegen' : 'GP anlegen' }}{{ ($mv['shortlist'] ?? 0) > 0 ? ' · ' . $mv['shortlist'] . ' Kandidaten' : '' }}</span>
                                                @endif
                                            @endif
                                        </p>
                                    @endif
                                @endforeach
                                @php($hardstops = collect($ueberarbeitung['match_vorschau'] ?? [])->where('status', 'hardstop')->count())
                                @if($hardstops > 0)
                                    <p class="text-[10px] text-violet-700 mt-0.5" data-ueberarbeiten-hardstops>
                                        {{ $hardstops }} Zutat(en) ohne Bestandstreffer → nach dem Übernehmen als GP/Basisrezept anlegen (Hard-Stop). Alle anderen werden automatisch verknüpft.
                                    </p>
                                @endif
                            @endif
                            @if(is_string($ueberarbeitung['werte']['description'] ?? null))
                                <p class="{{ $dt }}">Beschreibung (neu)</p>
                                <p class="text-[11px] text-gray-600">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte']['description'], 280) }}</p>
                            @endif
                            @if(is_string($ueberarbeitung['werte']['preparation'] ?? null))
                                <p class="{{ $dt }}">Zubereitung (neu)</p>
                                <p class="text-[11px] text-gray-600 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte']['preparation'], 400) }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="ueberarbeitungUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-ueberarbeiten-uebernehmen>Übernehmen ({{ round($ueberarbeitung['confidence'] * 100) }} %)</button>
                            <button type="button" wire:click="ueberarbeitungVerwerfen" class="{{ $btnGhostXs }}" data-ueberarbeiten-verwerfen>Verwerfen</button>
                            <span class="text-[10px] text-gray-500">Übernehmen schreibt Zutaten-Sync + Texte mit Lineage ki — manuell Gepflegtes bleibt (GL-07).</span>
                        </div>
                    @endif
                </div>
            @endif

            @if($copilotOffen)
                <x-foodalchemist::copilot-box :copilot="$copilot" :status="$copilotStatus" zeilen-wort="Zutat" />
            @endif

            <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="zutaten-inline-{{ $recipeId }}-v{{ $zutatenVersion }}" />

            @if($voll !== null)
                <div class="mt-2 flex flex-wrap items-end gap-4">
                    <div>
                        <label class="block {{ $label }} mb-1">Yield manuell (kg, A-3 — Vorrang vor Auto-Summe)</label>
                        <input type="text" wire:model="form.yield_kg_manual" placeholder="leer = Auto ({{ $voll->yield_kg !== null ? number_format((float) $voll->yield_kg, 3, ',', '.') : '—' }})" class="{{ $input }} !w-48" data-yield-manual />
                    </div>
                    <div>
                        <label class="block {{ $label }} mb-1">Ertrag in Stück (kg ↔ Stück)</label>
                        <input type="text" wire:model.live.debounce.500ms="form.yield_pieces" placeholder="z. B. 50 (Törtchen)" class="{{ $input }} !w-40" data-ertrag-stueck />
                        @php($es = is_numeric(str_replace(',', '.', (string) ($form['yield_pieces'] ?? ''))) ? (float) str_replace(',', '.', (string) $form['yield_pieces']) : null)
                        @if($es !== null && $es > 0 && $voll->yield_kg !== null)
                            <p class="text-[11px] text-gray-600 mt-1">1 Stück ≈ {{ number_format((float) $voll->yield_kg / $es * 1000, 0, ',', '.') }} g{{ $voll->ek_total_eur !== null ? ' · EK/Stück ≈ ' . number_format((float) $voll->ek_total_eur / $es, 2, ',', '.') . ' €' : '' }}</p>
                        @endif
                    </div>
                </div>
            @endif
        </x-foodalchemist::modal-section>
    @endif
    </div>{{-- /Tab AUFBAU --}}

    {{-- ── Tab: ZUBEREITUNG (Equipment + Zubereitung) ────────────────── --}}
    <div x-show="tab === 'preparation'" x-cloak class="pt-4 space-y-4">
    {{-- EQUIPMENT (§4.2.6) — gruppiert nach Vokabular-Gruppe (Ist-App-Layout) --}}
    <x-foodalchemist::modal-section title="Equipment">
        <x-slot:actions>
            @if(!$neu)<button type="button" wire:click="kiEquipment" class="{{ $btnAi }}" title="Set-Vorschlag aus den Zutaten (in die Auswahl, nichts persistiert)">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Equipment</button>@endif
        </x-slot:actions>
        {{-- Gewählte Geräte deutlich hervorheben (gefüllt violett + ✓) + Zusammenfassung oben,
             damit die Auswahl im ~40-Chip-Raster nicht untergeht. Farben als rohes CSS (hell + dunkel). --}}
        <style>
            [data-rezept-equipment] .fa-eq{ transition:background-color .15s, color .15s, box-shadow .15s; }
            [data-rezept-equipment] .fa-eq-off{ background:rgba(148,163,184,.16); color:#64748b; }
            [data-rezept-equipment] .fa-eq-off:hover{ background:rgba(139,92,246,.12); color:#6d28d9; }
            [data-rezept-equipment] .fa-eq-on{ background:#7c3aed; color:#fff; font-weight:600; box-shadow:0 1px 5px rgba(124,58,237,.35); }
            [data-rezept-equipment] .fa-eq-summary{ color:#6d28d9; }
            .fa-editor-panel [data-rezept-equipment] .fa-eq-off{ background:rgba(255,255,255,.07); color:#94a3b8; }
            .fa-editor-panel [data-rezept-equipment] .fa-eq-off:hover{ background:rgba(139,92,246,.22); color:#e9d5ff; }
            .fa-editor-panel [data-rezept-equipment] .fa-eq-on{ background:#8b5cf6; color:#fff; box-shadow:0 1px 6px rgba(139,92,246,.5); }
            .fa-editor-panel [data-rezept-equipment] .fa-eq-summary{ color:#c4b5fd; }
        </style>
        <div class="space-y-1.5" data-rezept-equipment>
            @php($eqGewaehlt = $equipmentListe->filter(fn ($g) => in_array((string) $g->id, $form['equipment_ids'], true)))
            <div class="flex items-start gap-2 pb-1.5 mb-1 border-b border-black/5" data-equipment-gewaehlt>
                <span class="{{ $dt }} w-28 shrink-0 pt-0.5">Gewählt ({{ $eqGewaehlt->count() }})</span>
                @if($eqGewaehlt->isNotEmpty())
                    <span class="fa-eq-summary text-xs font-medium leading-snug">{{ $eqGewaehlt->pluck('name')->join(' · ') }}</span>
                @else
                    <span class="text-[11px] text-gray-400">— noch nichts gewählt —</span>
                @endif
            </div>
            @foreach($equipmentListe->groupBy(fn ($g) => $g->group_name ?? 'sonstig') as $gruppe => $geraete)
                <div class="flex items-start gap-2">
                    <span class="{{ $dt }} w-28 shrink-0 pt-1">{{ $gruppe }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($geraete as $geraet)
                            @php($eqOn = in_array((string) $geraet->id, $form['equipment_ids'], true))
                            <label class="fa-eq {{ $eqOn ? 'fa-eq-on' : 'fa-eq-off' }} inline-flex items-center gap-1 {{ $pill }} cursor-pointer"
                                   wire:key="eq-{{ $geraet->id }}">
                                <input type="checkbox" wire:model.live="form.equipment_ids" value="{{ $geraet->id }}" class="hidden" />
                                @if($eqOn)@svg('heroicon-o-check', 'w-3 h-3 shrink-0')@endif
                                {{ $geraet->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-foodalchemist::modal-section>

    {{-- ZUBEREITUNG (§4.2.5) — Spec 27: strukturierte Schritte sind der Master,
         `recipes.preparation` ist nur noch ihr gerenderter Lese-Spiegel. Inhalt + KI
         + Fotos leben im eingebetteten Schritt-Editor; hier bleibt die Lineage-Steuerung. --}}
    <x-foodalchemist::modal-section title="Zubereitung">
        <x-slot:actions>
            @if(!$neu)
                <button type="button" wire:click="manual_zubereitung" class="{{ $btnGhostXs }}" title="gegen KI-Überschreiben sperren">als manuell</button>
                <button type="button" wire:click="clear_zubereitung" class="{{ $btnGhostXs }}" title="Lineage-Markierung aufheben — der Text bleibt">Lineage-Reset</button>
            @endif
        </x-slot:actions>
        @if($neu)
            {{-- Anlage-Modus: es gibt noch keine Schritt-IDs (und damit keine Foto-Verknüpfung).
                 Freitext ist hier weiter erlaubt und wird beim Speichern in Schritte geparst. --}}
            <textarea wire:model="form.preparation" rows="6" class="{{ $input }} font-mono text-[11px]" data-rezept-preparation
                      placeholder="Optional schon eintippen — wird beim Speichern in Schritte umgewandelt.&#10;## Mise en Place&#10;1. …"></textarea>
            <p class="text-[10px] text-gray-500 mt-1">
                <code>##</code> = Abschnitt · <code>1.</code> / <code>-</code> = Schritt. Nach dem Speichern gibt es den Schritt-Editor mit Fotos.
            </p>
        @else
            <livewire:foodalchemist.recipes.step-editor :recipe-id="$recipeId" wire:key="schritt-editor-{{ $recipeId }}" />
            <p class="text-[10px] text-gray-500 mt-1">
                Lineage: {{ $zustaende['preparation'] }} — der Markdown-Text in <code>preparation</code> wird aus den Schritten erzeugt
                (Produktionsdruck, Suche und Prozessanker lesen ihn).
            </p>
        @endif
    </x-foodalchemist::modal-section>
    </div>{{-- /Tab ZUBEREITUNG --}}

    {{-- ── Tab: STAMMDATEN (Stammdaten + Eigenschaften, 2026-07-31 zusammengelegt) ── --}}
    <div x-show="tab === 'eigenschaften'" x-cloak class="pt-4 space-y-4">
    {{-- STAMMDATEN (§4.2.2) — Name/Herkunft/Status/Taxonomie --}}
    <x-foodalchemist::modal-section title="Stammdaten" class="!p-3">
        <x-slot:actions>
            <button type="button" wire:click="namePutzen" class="{{ $btnAi }}" title="§1-Syntax normalisieren">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Name putzen</button>
            @if(!$neu)
                <button type="button" wire:click="ai_kategorie" class="{{ $btnAi }}" title="D-1-Klassifikation (GL-07-Vorschlag unten)">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Kategorie</button>
            @endif
            <button type="button" wire:click="kiFertigung" class="{{ $btnAi }}" title="Fertigungstiefe aus den Zutaten">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Fertigung</button>
        </x-slot:actions>

        {{-- Kompakt: ein enges Raster, Name volle Breite --}}
        <div class="grid grid-cols-2 gap-x-4 gap-y-2">
            <div class="col-span-2">
                <label class="block {{ $label }} mb-1">Name *</label>
                <input type="text" wire:model.live.debounce.300ms="form.name" placeholder="Schaumsauce: Beurre Blanc" class="{{ $input }}" data-rezept-name />
                <p class="text-[10px] text-gray-500 mt-0.5">§1.2: <code>Typ: Bezeichnung (Variante)</code>, Title Case @if($keyVorschau !== '')· <span class="font-mono" data-key-vorschau>{{ $keyVorschau }}{{ $neu ? '' : ' (stabil)' }}</span>@endif</p>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Herkunft / Quelle <span class="normal-case text-gray-400">(nicht im Namen — §1.6)</span></label>
                <input type="text" wire:model="form.origin_source" placeholder="z. B. Broich, nach Paul, nach Omas Art" class="{{ $input }}" />
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
                    @foreach($hauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Kategorie * <span class="normal-case text-gray-400">({{ $kategorien->count() }} in dieser HG)</span></label>
                <select wire:model.live="form.category_id" class="{{ $input }}" @disabled($kategorien->isEmpty())>
                    <option value="">—</option>
                    @foreach($kategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->label }}</option>@endforeach
                </select>
            </div>
        </div>
        @if(isset($kiVorschlag['category']))
            <div class="mt-2 text-xs flex items-center gap-2" data-kategorie-vorschlag>
                <span class="{{ $pill }} {{ $variantPill['primary'] }}">Kategorie: {{ $kiVorschlag['category']['werte']['kategorie_name'] ?? $kiVorschlag['category']['werte']['category_id'] ?? '—' }} · {{ round($kiVorschlag['category']['confidence'] * 100) }} %</span>
                <button type="button" wire:click="accept_kategorie" class="{{ $btnGhostXs }} text-emerald-600">Übernehmen</button>
            </div>
        @endif
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 mt-2">
            <input type="checkbox" wire:model="form.is_sales_recipe" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
            Gericht (D-6 — VK-Felder im VK-Editor)
        </label>
    </x-foodalchemist::modal-section>

    {{-- EIGENSCHAFTEN (§4.2.4) --}}
    <x-foodalchemist::modal-section title="Eigenschaften">
        <x-slot:actions>
            <button type="button" wire:click="kiEigenschaften" class="{{ $btnAi }}" title="Arbeitszeit/Temperatur/Funktion + Geschmack (in die Felder, nichts persistiert)">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Eigenschaften</button>
        </x-slot:actions>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block {{ $label }} mb-1">Arbeitszeit (min)</label>
                <input type="number" wire:model="form.work_time_min" min="0" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Temperatur</label>
                <input type="text" wire:model="form.temperature" placeholder="z. B. raumtemperatur, warm, kalt" class="{{ $input }}" />
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Funktion</label>
                {{-- Dropdown-Vorschläge via datalist — freie Eingabe bleibt möglich (bestehende Freitext-Werte gehen nicht verloren). --}}
                <input type="text" wire:model="form.function" list="fa-function-optionen" placeholder="z. B. Komponente, Sauce, Bindung …" class="{{ $input }}" />
                <datalist id="fa-function-optionen">
                    @foreach(['Komponente', 'Hauptkomponente', 'Sauce', 'Bindung', 'Topping', 'Beilage', 'Garnitur', 'Fond / Basis', 'Marinade', 'Dekor', 'Füllung', 'Teig'] as $opt)
                        <option value="{{ $opt }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Geschmacksrichtung <span class="normal-case text-gray-500">(via KI oder manuell)</span></label>
                <select wire:model="form.taste_direction" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="suess">süß</option><option value="herzhaft">herzhaft</option><option value="neutral">neutral</option>
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Fertigungstiefe <span class="normal-case text-gray-500">(via KI-Fertigung oder manuell)</span></label>
                <select wire:model="form.production_depth" class="{{ $input }}">
                    <option value="">—</option>
                    <option value="from_scratch">From Scratch</option><option value="teilfertig">teilfertig</option><option value="convenience">Convenience</option>
                </select>
            </div>
        </div>
    </x-foodalchemist::modal-section>

    {{-- EIGNUNG (M9-01k) — klickbare Toggle-Chips, Detail-Panel-Kartei via section-Prop --}}
    <x-foodalchemist::modal-section title="Eignung (Niveau · Sektor)">
        @if($recipeId !== null)
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" :embedded="true" section="eignung" wire:key="reignung-{{ $recipeId }}" />
        @else
            <p class="text-xs text-gray-500">Eignung lässt sich nach dem ersten Speichern pflegen.</p>
        @endif
    </x-foodalchemist::modal-section>

    {{-- BESCHREIBUNG (§8) --}}
    <x-foodalchemist::modal-section title="Beschreibung (§8.3 — 3-5 Sätze nüchtern)">
        <x-slot:actions>
            @if(!$neu)
                <button type="button" wire:click="ai_beschreibung" class="{{ $btnAi }}" data-ai-description>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Beschreibung</button>
                <button type="button" wire:click="manual_beschreibung" class="{{ $btnGhostXs }}" title="aktuellen Text als manuell markieren (Override-First-Schutz)">als manuell</button>
                <button type="button" wire:click="clear_beschreibung" class="{{ $btnGhostXs }}" title="Feld + Lineage leeren">Reset</button>
            @endif
        </x-slot:actions>
        <textarea wire:model="form.description" rows="3" class="{{ $input }}"></textarea>
        @if(isset($kiVorschlag['description']))
            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2" data-description-vorschlag>
                <p class="text-[11px] text-violet-700 italic">{{ $kiVorschlag['description']['werte']['description'] ?? '—' }}</p>
                <button type="button" wire:click="accept_beschreibung" class="{{ $btnGhostXs }} text-emerald-600 mt-1">Übernehmen ({{ round($kiVorschlag['description']['confidence'] * 100) }} %)</button>
            </div>
        @endif
        @if(!$neu)<p class="text-[10px] text-gray-500 mt-1">Lineage: {{ $zustaende['description'] }}</p>@endif
    </x-foodalchemist::modal-section>

    {{-- ERSATZ (make-or-buy / Artikel-Ersatz) — Detail-Panel-Kartei via section-Prop (eine Quelle, keine Duplikation) --}}
    <x-foodalchemist::modal-section title="Ersatz (make-or-buy · fertig ↔ selbst)">
        @if($recipeId !== null)
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" :embedded="true" section="ersatz" wire:key="rersatz-{{ $recipeId }}" />
        @else
            <p class="text-xs text-gray-500">Ersatz lässt sich nach dem ersten Speichern verknüpfen.</p>
        @endif
    </x-foodalchemist::modal-section>
    </div>{{-- /Tab EIGENSCHAFTEN --}}

    {{-- ── Tab: DEKLARATION — Allergene · Zusatzstoffe (Detail-Panel-Embed) + Nährwerte ── --}}
    <div x-show="tab === 'details'" x-cloak class="pt-4 space-y-4">
        @if($recipeId !== null)
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" :embedded="true" wire:key="rdetail-{{ $recipeId }}" />

            {{-- NÄHRWERTE (GL-08-Aggregat, read-only — Quelle: Zutaten-Recompute; hierher verschoben 2026-07-02, gehört zur Deklaration) --}}
            <x-foodalchemist::modal-section title="Nährwerte (pro 100 g)">
                @if($voll?->nutri_kcal_per_100g === null)
                    <p class="text-[11px] text-gray-500" data-naehrwerte-leer>Noch nicht aggregiert — läuft mit dem nächsten Zutaten-Speichern (GL-08).</p>
                @else
                    <div class="grid grid-cols-5 gap-2 rounded-lg bg-black/[0.03] px-3 py-2" data-naehrwerte>
                        @foreach([
                            ['Brennwert', $voll->nutri_kcal_per_100g, 'kcal', 0, null, null],
                            ['Eiweiß', $voll->nutri_protein_g_per_100g, 'g', 1, null, null],
                            ['Fett', $voll->nutri_fat_g_per_100g, 'g', 1, 'davon gesättigt', $voll->nutri_saturated_fat_g_per_100g],
                            ['Kohlenhydrate', $voll->nutri_carbs_g_per_100g, 'g', 1, 'davon Zucker', $voll->nutri_sugar_g_per_100g],
                            ['Salz', $voll->nutri_salt_g_per_100g, 'g', 2, null, null],
                        ] as [$lbl, $wert, $unit, $dez, $subLbl, $subWert])
                            <div class="text-center" wire:key="rn-{{ $lbl }}">
                                <p class="text-[10px] uppercase tracking-wider text-gray-500">{{ $lbl }}</p>
                                <p class="text-xs font-medium text-gray-900 tabular-nums">{{ $wert !== null ? number_format((float) $wert, $dez, ',', '.') . ' ' . $unit : '—' }}</p>
                                @if($subLbl !== null)
                                    <p class="text-[10px] text-gray-500 tabular-nums" data-naehrwert-sub="{{ $subLbl }}">{{ $subLbl }} {{ $subWert !== null ? number_format((float) $subWert, 1, ',', '.') . ' g' : '—' }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">
                        Konfidenz: <span class="font-medium {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$voll->nutri_confidence] ?? '' }}">{{ strtoupper($voll->nutri_confidence ?? '—') }}</span>
                        · {{ $voll->nutri_n_ingredients_mapped ?? 0 }}/{{ $voll->nutri_n_ingredients_total ?? 0 }} Zutaten mit Nährwert-Daten
                        — BLS-Rohwerte, Garverlust/Putzverlust nicht angewendet (GL-08)
                    </p>
                @endif
            </x-foodalchemist::modal-section>
        @else
            <p class="text-xs text-gray-500 py-6 text-center">Deklaration erscheint nach dem ersten Speichern.</p>
        @endif
    </div>

    {{-- ── Tab: SENSORIK & PAIRING (Geschmacks-Balance + Textur + Aroma-Kohäsion über die Zutaten-GPs) ── --}}
    <div x-show="tab === 'sensorik'" x-cloak class="pt-4">
        @unless($neu)
            <div class="flex items-center justify-between gap-2 mb-2">
                <span class="text-[11px] text-gray-500">Gegartes Profil — KI liest Zutaten + Zubereitung.</span>
                <button type="button" wire:click="sensorikBewerten" wire:loading.attr="disabled" wire:target="sensorikBewerten" class="{{ $btnAi }}">
                    <span wire:loading.remove wire:target="sensorikBewerten" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Sensorik neu bewerten</span>
                    <span wire:loading wire:target="sensorikBewerten">… bewertet</span>
                </button>
            </div>
        @endunless
        @include('foodalchemist::livewire.concepter.partials.sensorik')
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 mt-5 mb-2">Pairing</h3>
        @include('foodalchemist::livewire.concepter.partials.pairing')
    </div>

    {{-- ── Tab: FEEDBACK (R2.6 — Praxis-Feedback Küche/Kunde/Event) ───── --}}
    @if(! $neu && $recipeId !== null)
    <div x-show="tab === 'feedback'" x-cloak class="pt-4">
        <livewire:foodalchemist.recipes.feedback-panel :recipe-id="$recipeId" wire:key="feedback-rez-{{ $recipeId }}" />
    </div>
    @endif

    {{-- ── Tab: NOTIZEN ──────────────────────────────────────────────── --}}
    <div x-show="tab === 'notes'" x-cloak class="pt-4 space-y-4">
    {{-- NOTIZEN (§9.1 — manuelle Insel) --}}
    <x-foodalchemist::modal-section title="Notizen (§9.1 — bleibt bei jedem KI-Sync erhalten)">
        <textarea wire:model="form.notes_manual" rows="3" class="{{ $input }}" data-rezept-notes
                  placeholder="z. B. Anpassung im Catering-Kontext, Mengen-Korrektur, …"></textarea>
    </x-foodalchemist::modal-section>
    </div>{{-- /Tab NOTIZEN --}}
    </x-foodalchemist::editor-tabs>

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'recipe-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" wire:click="speichern" x-on:click="$dispatch('zutaten-speichern', { recipeId: @js($recipeId) })" class="{{ $btnPrimary }}" data-rezept-speichern-footer>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>
    </x-slot:footer>
</x-foodalchemist::modal>
