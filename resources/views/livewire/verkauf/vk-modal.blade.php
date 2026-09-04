{{-- M6-04: VK-Editor (D-6 §4.2–4.5) — Anlage aus Basisrezept + Sektionen-Edit --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- R5 (Dominique): VK-Editor nimmt wie der Basis-Editor den ganzen Bildschirm --}}
{{-- Spec 28 / E1-2: Titel bleibt generisch, der Gerichtname ist der Akzent-Chip. --}}
<x-foodalchemist::modal name="vk-modal" title="{{ $rezept !== null ? 'Gericht bearbeiten' : 'Neues Gericht' }}"
    :title-name="$rezept?->name" size="max-w-3xl" :fullscreen="$rezept !== null" :dark-canvas="true">
    @if($rezept !== null)
        <x-slot:actions>
            {{-- #1b: EIN Speichern-Weg, sequenziert — erst VK-Stammdaten (`speichern`), dann bei
                 Erfolg adressiert das Zutaten-Speichern der eingebetteten Komponenten (MVP-046).
                 Der Editor meldet `zutaten-persistiert` zurück → beiZutatenPersistiert schließt. --}}
            <button type="button"
                    x-on:click="$wire.speichern().then(() => { if (! $wire.fehler && $wire.recipeId) $dispatch('zutaten-speichern', { recipeId: $wire.recipeId }) })"
                    class="{{ $btnPrimary }}" data-vk-speichern>Speichern</button>
            <a href="{{ route('foodalchemist.rezepte.dokument', ['id' => $rezept->id, 'profil' => 'produktion']) }}" target="_blank"
               class="{{ $btnGhostXs }}" title="Druck-/PDF-Report mit Profilen und Filtern" data-vk-druck>
                @svg('heroicon-o-printer', 'w-3.5 h-3.5') Druck
            </a>
            <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-zutaten>Komponenten bearbeiten</button>
            <button type="button" wire:click="loeschen" wire:confirm="Gericht wirklich löschen? Nur der VK-Layer wird entfernt — Basisrezepte und GP-Verknüpfungen bleiben bestehen."
                    class="{{ $btnGhostXs }} text-rose-600" data-vk-loeschen>Löschen</button>
            <span class="text-gray-300">|</span>
            {{-- Spec 03 L1b: ✨ Alles anreichern — VK-Schrittfolge + operative OneShot-Coverage --}}
            <button type="button" wire:click="allesAnreichern" class="{{ $btnAi }}"
                    title="VK-Text, Eigenschaften, Produktionsrouting, Equipment, Schritte, Aromaanker, Pairings, Eignung, Sensorik, Wirtschaftlichkeit und Kohärenz synchronisieren. KI-Fotos laufen separat; Ersatz bleibt manuell."
                    data-vk-alles-anreichern>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Alles anreichern</button>
        </x-slot:actions>
    @endif

    {{-- Phase 1: KPI-Streifen fix im Modal-Kopf (immer sichtbar, scrollt nie weg) --}}
    @if($rezept !== null)
        <x-slot:kpiHeader>
            {{-- Spec 28 / E2.1: Kacheln über den Baustein `kpi-tiles` (Palette hell + dunkel dort).
                 Reihe 1 = Kosten-Seite (Basisrezept-Parität), Reihe 2 = VK-Seite aus
                 SalesRecipeService::cockpit() (MargeService) — „—" wenn Preisklasse oder
                 Portionsgröße fehlt.

                 Tönung nach E1-Regel 6 (genau EIN accent):
                 · Rohertragsquote = Leitwert des Gericht-Editors → accent.
                 · „Mit Preis" + Allergen-Konf. tragen echte Messgrößen → good/warn/bad.
                 · Wareneinsatz ampelt seit Spec 28 §6.1 gegen die Ziel-Quote des Teams:
                   `cockpit()` liefert `ziel_pct` + `ampel`, die Leiter liegt im MargeService
                   (grün ≤ Ziel · rot > Ziel × 1,5 · sonst gelb) und ist dieselbe wie im
                   Wirtschaftlichkeits-Glied und in den Signalen. Ohne Ziel-Quote oder ohne
                   VK bleibt sie `unbekannt` → neutral, nie geraten. --}}
            @php($weAmpelTone = ['gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad'][$cockpit['ampel'] ?? ''] ?? 'neutral')
            <x-foodalchemist::kpi-tiles marker="vk-editor-kpis" :cols="5" :tiles="[
                ['kpi' => 'yield', 'label' => 'Yield',
                 'value' => $rezept->yield_kg !== null ? number_format((float) $rezept->yield_kg, 3, ',', '.') . ' kg' : '—'],
                ['kpi' => 'ek', 'label' => 'EK gesamt',
                 'value' => $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'ekkg', 'label' => 'EK / kg',
                 'value' => $rezept->ek_per_kg_eur !== null ? number_format((float) $rezept->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : '—'],
                ['kpi' => 'priced', 'label' => 'Mit Preis',
                 'tone' => ($rezept->ek_n_ingredients_total ?? 0) > 0 && ($rezept->ek_n_ingredients_priced ?? 0) >= ($rezept->ek_n_ingredients_total ?? 0) ? 'good' : 'warn',
                 'value' => ($rezept->ek_n_ingredients_priced ?? 0) . '/' . ($rezept->ek_n_ingredients_total ?? 0)],
                ['kpi' => 'allergen', 'label' => 'Allergen-Konf.',
                 'tone' => ['high' => 'good', 'medium' => 'warn', 'low' => 'bad'][$rezept->allergens_confidence] ?? 'neutral',
                 'value' => strtoupper((string) $rezept->allergens_confidence)],
                ['kpi' => 'vk-netto', 'label' => 'VK netto',
                 'value' => ($cockpit['vk']['sales_net'] ?? null) !== null ? number_format((float) $cockpit['vk']['sales_net'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'vk-brutto', 'label' => 'VK brutto',
                 'value' => ($cockpit['sales_gross'] ?? null) !== null ? number_format((float) $cockpit['sales_gross'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'vk-portion', 'label' => 'VK / Portion',
                 'value' => ($cockpit['pro_einheit']['vk_netto_pro_einheit'] ?? null) !== null ? number_format((float) $cockpit['pro_einheit']['vk_netto_pro_einheit'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'wareneinsatz', 'label' => 'Wareneinsatz', 'tone' => $weAmpelTone,
                 'title' => ($cockpit['ziel_pct'] ?? null) !== null
                    ? 'Ziel des Teams: ' . number_format((float) $cockpit['ziel_pct'], 1, ',', '.') . ' % — grün bis Ziel, rot ab Ziel × 1,5 (Einstellungen → Herstellkosten).'
                    : 'Keine Ziel-Wareneinsatzquote ermittelbar — ohne Vorgabe wird nicht geampelt.',
                 'value' => ($cockpit['marge']['wareneinsatz_pct'] ?? null) !== null ? number_format((float) $cockpit['marge']['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—'],
                ['kpi' => 'marge', 'label' => 'Rohertragsquote', 'tone' => 'accent',
                 'title' => 'Rohertragsquote = (VK netto − MEK) ÷ VK netto. Sie berücksichtigt noch keine auftragsspezifischen Lohn- und Gemeinkosten.',
                 'value' => ($cockpit['marge']['marge_pct'] ?? null) !== null ? number_format((float) $cockpit['marge']['marge_pct'], 1, ',', '.') . ' %' : '—'],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($fehler !== null)
        <p class="text-xs text-rose-600" data-vk-fehler>{{ $fehler }}</p>
    @endif

    {{-- Legacy-Bulk-Status (falls ein alter Lauf noch offen ist); der Button nutzt inzwischen OneShot-Coverage. --}}
    @if($bulkRun !== null)
        <div class="mb-3 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs flex items-center gap-2"
             @if($bulkRun->status === 'running') wire:poll.2s @endif data-vk-anreichern-status>
            @if($bulkRun->status === 'running')
                <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Anreicherung läuft …</span>
            @else
                <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') {{ $bulkOffen }} Vorschläge offen{{ $bulkRun->failed > 0 ? " · {$bulkRun->failed} Fehler" : '' }}</span>
                <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-vk-anreichern-uebernehmen>Alle übernehmen</button>
            @endif
        </div>
    @endif

    <x-foodalchemist::oneshot-ergebnis :anreicherung="$anreicherung" />

    @if($rezept === null)
        {{-- Anlage-Modus (DoD: VK aus Basisrezept manuell) --}}
        <x-foodalchemist::modal-section title="Gericht anlegen">
            <div class="space-y-3" data-vk-anlage>
                <div>
                    <label class="block {{ $label }} mb-1">Name* (Pipe-Syntax §4.4: »HG: Hauptkomponente | Komponente | …«)</label>
                    <input type="text" wire:model="neuName" class="{{ $input }}" placeholder="HG: Rinderfilet | Rotwein-Jus | Kartoffelgratin" data-vk-neu-name />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Basisrezept als erste Komponente <span class="normal-case text-gray-500">(optional)</span></label>
                    <input type="search" wire:model.live.debounce.300ms="basisSuche" class="{{ $input }}" placeholder="Basisrezept suchen …" data-vk-basis-suche />
                    @foreach($basisTreffer as $b)
                        <button type="button" wire:key="bt-{{ $b->id }}" wire:click="$set('basisId', {{ $b->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-xs {{ $basisId === $b->id ? 'bg-violet-500/10 text-violet-700' : 'text-gray-700 hover:bg-black/[0.03]' }}"
                                data-vk-basis-treffer="{{ $b->id }}">
                            {{ $b->name }} <span class="text-[11px] text-gray-500">{{ $b->yield_kg !== null ? number_format((float) $b->yield_kg, 2, ',', '.') . ' kg' : '' }} {{ $b->ek_total_eur !== null ? '· EK ' . number_format((float) $b->ek_total_eur, 2, ',', '.') . ' €' : '' }}</span>
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="anlegen" class="{{ $btnPrimary }}" data-vk-anlegen>Anlegen</button>
                <p class="text-[10px] text-gray-500">Mit Basisrezept: dessen ganze Charge wird erste Komponente (Menge = Yield). Ohne: leeres Gericht — Komponenten danach im Editor hinzufügen.</p>
            </div>
        </x-foodalchemist::modal-section>
    @else
        {{-- R7 (Dominique 2026-06-14): Tabs statt langem Scroll. Alpine x-show statt Livewire-setTab:
             alle Sektionen bleiben im DOM (Marker/Tests bleiben grün), der eingebettete
             <livewire ingredient-editor> wird NICHT neu gemountet, ungespeicherte Eingaben bleiben,
             Umschalten ist sofort (kein Server-Roundtrip).
             Spec 28 / E2.1: Leiste + Alpine-Scope kommen aus dem Baustein `editor-tabs` — inklusive
             wire:key (Gericht-Wechsel) und Tab-Reset beim Öffnen, die hier vorher fehlten.
             'allergene'-Key bleibt stabil, Label seit 2026-07-02 „Deklaration" (bündelt Allergene ·
             Zusatzstoffe · Nährwerte · Spezifikation — Rezept-Editor-Parität). --}}
        <x-foodalchemist::editor-tabs marker="vk" wire-key="vk-tabs-{{ $rezept->id }}" :init="'aufbau'"
            {{-- Tab-Namen folgen den drei Anleitungs-Ebenen (Regelwerk Verkaufsgerichte §3,
                 User-Entscheid 2026-09-04) in ihrer Prozess-Reihenfolge: regenerieren →
                 fertigstellen → anrichten. Der alte Sammel-Tab „Service" ist aufgeteilt; er
                 mischte Behälter, Regenerations-Programm, Eigenschaften und Teller-Aufbau. --}}
            :tabs="[
                'aufbau' => 'Aufbau',
                'stammdaten' => 'Stammdaten',
                'regeneration' => 'Regeneration',
                'preparation' => 'Fertigstellen',
                'plating' => 'Anrichten',
                'allergene' => 'Deklaration',
                'kalkulation' => 'Kalkulation',
                'darreichungen' => 'Darreichungen',
                'sensorik' => 'Sensorik & Pairing',
                'feedback' => 'Feedback',
                'notes' => 'Notizen',
            ]">

        {{-- ── Tab: STAMMDATEN (Stammdaten + Klassifikation) ─────────────
             Spec 28 / E6: aus „Aufbau" herausgelöst — Master-Parität (Basisrezept-Editor).
             Aufbau ist jetzt reiner Bau: man sieht die Komponenten ohne vorher an den
             Stammdaten vorbeizuscrollen. --}}
        <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 space-y-4">
        <x-foodalchemist::modal-section title="Stammdaten">
            {{-- M9-01i: ✨-Vorschläge in die Form-Felder (Save = Accept).
                 ✨ Marketing ist raus (UX-Umbau 2026-07-03): Marketing-Text lebt am Foodbook-Block. --}}
            <x-slot:actions>
                <button type="button" wire:click="ki('wording')" class="{{ $btnAi }}" title="vk.wording: kanonischer Marketing-Name, stil-neutral" data-ki-wording>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Wording</button>
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">Name*</label>
                    <input type="text" wire:model="form.name" class="{{ $input }}" data-vk-name />
                </div>
                @if($rezept !== null)
                    {{-- Spec 43 (Bild-Epic): Gericht-Foto — dasselbe Bild wie am Basisrezept (recipes.image_*). --}}
                    <div class="col-span-2" data-vk-bild>
                        <label class="block {{ $label }} mb-1">Gericht-Foto <span class="normal-case text-gray-400">(optional, für Präsentation & Detail)</span></label>
                        <div class="flex items-center gap-3 flex-wrap">
                            @if(!empty($dishImageUrl))
                                <img src="{{ $dishImageUrl }}" alt="" class="h-12 w-20 object-cover rounded border border-black/10">
                                <button type="button" wire:click="dishImageEntfernen" class="text-rose-600 text-[11px] underline">entfernen</button>
                            @endif
                            <input type="file" wire:model="dishImageUpload" accept="image/*" class="text-[11px]" data-vk-bild-upload>
                            <div wire:loading wire:target="dishImageUpload" class="text-[11px] text-gray-400">lädt …</div>
                        </div>
                        @error('dishImageUpload')<div class="text-[11px] text-rose-600 mt-1">{{ $message }}</div>@enderror
                    </div>
                @endif
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">VK-Wording (neutraler Standard — Fallback für Concepter &amp; Foodbook)</label>
                    <input type="text" wire:model="form.sales_wording_standard" class="{{ $input }}" data-vk-wording />
                    <p class="text-[10px] text-gray-500 mt-0.5">Wording-Kette: Foodbook-Override → Konzept-Wording → dieser Standard → interner Name. Marketing-Texte werden am Foodbook-Block gepflegt.</p>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Geschmack</label>
                    <select wire:model="form.taste_direction" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Klassifikation">
            <div class="grid grid-cols-2 gap-3" data-vk-klassifikation>
                <div>
                    {{-- Modell A (MVP-049): HG ist ein normales Formularfeld am Gericht, keine
                         Kaskaden-Steuerung mehr — deshalb `form.dish_main_group_id` statt eines
                         separaten Props und kein `.live` (nichts hängt am Wechsel). --}}
                    <label class="block {{ $label }} mb-1">Speisen-Hauptgruppe</label>
                    <select wire:model="form.dish_main_group_id" class="{{ $input }}" data-vk-hg>
                        <option value="">—</option>
                        @foreach($hauptgruppen as $hg)
                            <option value="{{ $hg->id }}">[{{ $hg->code }}] {{ $hg->label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    {{-- Unabhängige Achse: die vier Diätformen, nie von der HG abhängig. --}}
                    <label class="block {{ $label }} mb-1">Speisen-Klasse (Diätform)</label>
                    <select wire:model="form.dish_class_id" class="{{ $input }}" data-vk-klasse>
                        <option value="">—</option>
                        @foreach($klassen as $k)
                            <option value="{{ $k->id }}">{{ $k->label }} ({{ $k->diet_form }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        {{-- Produktion / Auto-Planer (Parität Basisrezept-Editor, 2026-08-03): Posten-Routing +
             Rüstzeit + Vorproduzierbarkeit. Der Auto-Planer routet Gericht-Auftragszeilen über
             recipe.default_station_id (ProductionPlanService) — ohne diese Felder blieben sie
             „nicht zugeteilt". Als eigene, klar benannte Sektion (nicht in „Eigenschaften"
             vergraben), damit die Zuweisung auffindbar ist. --}}
        <x-foodalchemist::modal-section title="Produktion (Auto-Planer) — Fertigstellungs-Lauf">
            {{-- 2026-09-04: Diese Werte gelten für das ZUSAMMENSETZEN, nicht fürs ganze Gericht.
                 Der Auftrag explodiert jede Komponente in eine eigene Zeile mit eigenem Posten,
                 eigener Zeit und eigenem Vorlauf — die Summen kommen also aus den Basisrezepten. --}}
            <p class="text-[11px] text-gray-500 mb-2">
                Gilt für den Fertigstellungs-Lauf am Einsatztag. Herstellung, Zeiten und Vorlauf der Komponenten stehen an deren Basisrezepten und werden im Auftrag je Zeile geplant.
            </p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-vk-produktion>
                {{-- 2026-09-04: am Gericht ist das der FERTIGSTELLUNGS-Posten — wer zusammensetzt,
                     abschmeckt und ausgibt. Die Komponenten produzieren auf ihren eigenen Posten
                     (aus dem jeweiligen Basisrezept); die routet der Planer je Zeile. --}}
                {{-- Entscheid 2026-09-04: In der Küche laufen die BASISREZEPTE über Posten.
                     Beim Fertigstellen und Anrichten kommen die Posten zusammen und sind wieder
                     ein Team — ein einzelner „Posten, der das Gericht macht" existiert nicht.
                     Das Feld bleibt optional (für Betriebe mit echtem Pass-/Ausgabe-Posten samt
                     Rollenbesetzung); LEER ist der Normalfall, die Kalkulation rechnet dann mit
                     dem Team-Stundensatz. Die eigentliche Information steht darunter: welche
                     Posten am Fertigungstag beteiligt sind. --}}
                <div>
                    <label class="block {{ $label }} mb-1">Ausgabe-Posten <span class="normal-case text-gray-500">(optional — am Pass arbeitet das Team)</span></label>
                    <select wire:model="form.default_station_id" class="{{ $input }}" data-vk-default-station>
                        <option value="">— Team am Pass —</option>
                        @foreach($posten as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                    @if($beteiligtePosten->isNotEmpty())
                        <p class="text-[10px] text-gray-500 mt-1" data-vk-beteiligte-posten>
                            Beteiligt am Fertigungstag:
                            {{ $beteiligtePosten->map(fn ($p) => $p['name'] . ($p['anzahl'] > 1 ? " ({$p['anzahl']})" : ''))->implode(' · ') }}
                            <span class="text-gray-600">— aus den Basisrezepten der Komponenten</span>
                        </p>
                    @endif
                </div>
                {{-- Rüstzeit und Vorproduzierbarkeit sind RAUS am Gericht (User-Entscheid
                     2026-09-04): beides sind Herstellungs-Eigenschaften. Ein „Rüsten des
                     Laufs" gibt es beim Zusammensetzen nicht, und ein fertiggestelltes
                     Gericht ist nicht vorproduzierbar — vorproduziert werden seine
                     Komponenten, und die tragen die Werte an ihren Basisrezepten. --}}
                <div>
                    <label class="block {{ $label }} mb-1">Variable Personenminuten</label>
                    <input type="text" inputmode="decimal" wire:model="form.variable_work_time_min" class="{{ $input }}" placeholder="0" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Variable Zeit je</label>
                    <select wire:model="form.variable_work_time_basis" class="{{ $input }}"><option value="kg">kg</option><option value="piece">Stück</option><option value="portion">Portion</option></select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Batchgrenze kg</label>
                    <input type="text" inputmode="decimal" wire:model="form.batch_max_kg" class="{{ $input }}" placeholder="Team-Default" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Batchgrenze Stück</label>
                    <input type="text" inputmode="decimal" wire:model="form.batch_max_pieces" class="{{ $input }}" placeholder="Team-Default" />
                </div>
            </div>
            @if($posten->isEmpty())
                <p class="text-[10px] text-gray-500 mt-2">Noch keine Posten angelegt (Einstellungen → „Posten & Kapazität"). Am Gericht unkritisch — die Fertigstellung rechnet mit dem Team-Satz.</p>
            @endif
        </x-foodalchemist::modal-section>

        {{-- M9-01a/b: Zutaten INLINE (P-8-Kern, VK-Kontext = Rollen-Spalte) + 🎭 + KPI-Leiste --}}
        </div>{{-- /Tab STAMMDATEN --}}

        {{-- ── Tab: ZUBEREITUNG (Step-by-Step, 2026-08-03) ───────────────────
             Gleiche Schrittfolge wie im Basisrezept-Editor: der eingebettete StepEditor
             ist typ-agnostisch (hängt nur an recipe_id, kein is_sales_recipe-Guard) und
             schreibt in `foodalchemist_recipe_steps` (Master); `recipes.preparation` ist
             der gerenderte Lese-Spiegel. Kein $neu-Freitext-Zweig — der VK-Editor öffnet
             nur mit bestehendem Gericht ($rezept !== null), die recipe_id existiert also
             immer. Lineage-Buttons (manual/Reset) bleiben dem Basisrezept-Editor
             vorbehalten (RecipeModal-Methoden), hier nicht verdrahtet. --}}
        <div x-show="tab === 'preparation'" x-cloak class="pt-4 space-y-4">
        <x-foodalchemist::modal-section title="Fertigstellen am Einsatztag">
            <p class="text-[11px] text-gray-500 mb-2">
                Alles zwischen <em>regeneriert</em> und <em>angerichtet</em>: bereitstellen, portionieren, tranchieren, montieren, abschmecken. Die <strong>Herstellung</strong> der Komponenten steht in deren Basisrezepten, das <strong>Regenerations-Programm</strong> im Tab Regeneration, der <strong>Teller-Aufbau</strong> im Tab Anrichten.
            </p>
            <livewire:foodalchemist.recipes.step-editor :recipe-id="$rezept->id" ebene="produktion"
                wire:key="schritt-editor-vk-produktion-{{ $rezept->id }}" />
            <p class="text-[10px] text-gray-500 mt-1">
                Schritte sind der Master — der Markdown in <code>preparation</code> wird daraus erzeugt (Produktionsdruck, Suche und Prozessanker lesen ihn). Gleiche Mechanik wie im Basisrezept-Editor.
            </p>
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab FERTIGSTELLEN --}}

        {{-- ── Tab: AUFBAU (nur Komponenten) ─────────────────────────────── --}}
        <div x-show="tab === 'aufbau'" x-cloak class="pt-4 space-y-4">
        <x-foodalchemist::modal-section title="Zutaten ({{ $rezept->ingredients->count() }})">
            <x-slot:actions>
                <button type="button" wire:click="ai_rollen" class="{{ $btnAi }}" title="ai_verteile_rollen — Gesamt-Gericht-Sicht (V-21)" data-vk-editor-rollen>@svg('heroicon-o-user-group', 'w-3.5 h-3.5') Rollen verteilen</button>
                {{-- Spec 03 L1a: ✨ KI-Überarbeiten — freie Anweisung, Vorschau, Übernehmen --}}
                <button type="button" wire:click="$toggle('ueberarbeitenOffen')" class="{{ $btnAi }}"
                        title="Freie Anweisung — KI überarbeitet Komponenten, Mengen, Beschreibung, Plating & VK-Wording (Vorschau + Übernehmen). Klasse/Diät/Darreichung/Verkaufseinheit bleiben unangetastet."
                        data-vk-ki-ueberarbeiten>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')KI-Überarbeiten</button>
                {{-- Spec 03 L6b: 🧑‍🍳 Copilot — Prüf-Pass statt Neu-Schreiben (Befunde einzeln annehmen) --}}
                <button type="button" wire:click="$toggle('copilotOffen')" class="{{ $btnAi }}"
                        title="Prüf-Pass: die KI beurteilt Mengen, Einheiten, überflüssige und fehlende Komponenten am Massstab der Verkaufs-Facetten — je Befund einzeln übernehmbar."
                        data-vk-copilot>@svg('heroicon-o-clipboard-document-check', 'w-3.5 h-3.5') Copilot</button>
                {{-- Garverluste: feuert ins eingebettete zutaten-kern (Alpine garverluste() via Window-Event) --}}
                <button type="button" x-on:click="$dispatch('garverluste-vorschlagen')" class="{{ $btnAi }}"
                        title="M4-11: KI-Schätzung der Garverluste je Komponente (GL-07 — geschrieben erst beim Speichern)" data-vk-garverlust-ki>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Garverluste</button>
            </x-slot:actions>

            @if($ueberarbeitenOffen)
                <div class="mb-3 rounded-lg bg-violet-500/5 border border-violet-500/20 px-3 py-2 space-y-2" data-vk-ueberarbeiten-box>
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="anweisung" wire:keydown.enter="kiUeberarbeiten"
                               placeholder="z. B. «mach das Gericht vegan und ersetze die Sauce»" class="{{ $input }} !py-1.5 flex-1" data-vk-anweisung />
                        <button type="button" wire:click="kiUeberarbeiten" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-vk-ueberarbeiten-start>
                            <span wire:loading.remove wire:target="kiUeberarbeiten" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlagen</span>
                            <span wire:loading wire:target="kiUeberarbeiten">denkt …</span>
                        </button>
                    </div>
                    @if($ueberarbeitung !== null)
                        <div class="rounded-lg bg-white/60 px-3 py-2 space-y-1.5 max-h-72 overflow-y-auto" data-vk-ueberarbeiten-vorschau>
                            @if(is_string($ueberarbeitung['werte']['aenderungs_notiz'] ?? null))
                                <p class="text-[11px] font-medium text-violet-700">{{ $ueberarbeitung['werte']['aenderungs_notiz'] }}</p>
                            @endif
                            @if(!empty($ueberarbeitung['werte']['zutaten']))
                                <p class="{{ $dt }}">Komponenten (neu)</p>
                                @foreach($ueberarbeitung['werte']['zutaten'] as $z)
                                    @if(is_array($z))
                                        @php($mv = $ueberarbeitung['match_vorschau'][$loop->index] ?? null)
                                        <p class="text-[11px] text-gray-600 flex flex-wrap items-center gap-x-1.5" wire:key="vkuz-{{ $loop->index }}">
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
                                @php($vkHardstops = collect($ueberarbeitung['match_vorschau'] ?? [])->where('status', 'hardstop')->count())
                                @if($vkHardstops > 0)
                                    <p class="text-[10px] text-violet-700 mt-0.5" data-vk-ueberarbeiten-hardstops>
                                        {{ $vkHardstops }} Komponente(n) ohne Bestandstreffer → nach dem Übernehmen als GP/Basisrezept anlegen (Hard-Stop). Alle anderen werden automatisch verknüpft.
                                    </p>
                                @endif
                            @endif
                            @foreach(['sales_wording_standard' => 'VK-Wording (neu)', 'description' => 'Beschreibung (neu)', 'plating_text' => 'Plating (neu)'] as $feld => $titel)
                                @if(is_string($ueberarbeitung['werte'][$feld] ?? null) && trim($ueberarbeitung['werte'][$feld]) !== '')
                                    <p class="{{ $dt }}">{{ $titel }}</p>
                                    <p class="text-[11px] text-gray-600 whitespace-pre-line" wire:key="vkut-{{ $feld }}">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte'][$feld], 400) }}</p>
                                @endif
                            @endforeach
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="ueberarbeitungUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-vk-ueberarbeiten-uebernehmen>Übernehmen ({{ round($ueberarbeitung['confidence'] * 100) }} %)</button>
                            <button type="button" wire:click="ueberarbeitungVerwerfen" class="{{ $btnGhostXs }}" data-vk-ueberarbeiten-verwerfen>Verwerfen</button>
                            <span class="text-[10px] text-gray-500">Übernehmen schreibt Komponenten-Sync + Texte mit Lineage ki — manuell Gepflegtes und die Verkaufs-Facetten bleiben (GL-07).</span>
                        </div>
                    @endif
                </div>
            @endif

            @if($rollenVorschlag !== null)
                <div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs" data-vk-editor-rollen-vorschlag>
                    <p class="text-gray-900 inline-flex items-center gap-1.5">@svg('heroicon-o-user-group', 'w-3.5 h-3.5') Rollen-Verteilung <span class="text-[11px] text-gray-500">· {{ round($rollenVorschlag['confidence'] * 100) }} %</span></p>
                    @if($rollenVorschlag['rollen'] === [])
                        <p class="text-[11px] text-gray-500 mt-0.5">Kein gültiger Vorschlag (Vokabular: aroma_treiber · komponente · beilage · garnitur).</p>
                    @else
                        <div class="mt-1 space-y-0.5">
                            @foreach($rollenVorschlag['rollen'] as $zeileId => $role)
                                @php($zeile = $rezept->ingredients->firstWhere('id', $zeileId))
                                <p class="text-[11px] text-gray-600" wire:key="vkmr-{{ $zeileId }}">{{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }} → <span class="font-medium">{{ $role }}</span></p>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex gap-1.5 mt-1.5">
                        @if($rollenVorschlag['rollen'] !== [])
                            <button type="button" wire:click="accept_rollen" class="{{ $btnGhostXs }} text-emerald-600" data-vk-rollen-accept>Übernehmen</button>
                        @endif
                        <button type="button" wire:click="reject_rollen" class="{{ $btnGhostXs }}">Verwerfen</button>
                    </div>
                </div>
            @endif

            @if($copilotOffen)
                <x-foodalchemist::copilot-box :copilot="$copilot" :status="$copilotStatus" prefix="vk-" zeilen-wort="Komponente" />
            @endif

            <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="vk-zutaten-{{ $recipeId }}-v{{ $zutatenVersion }}" />
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab AUFBAU --}}

        {{-- ── Tab: DEKLARATION (Allergene · Zusatzstoffe · Nährwerte · Spezifikation) ── --}}
        <div x-show="tab === 'allergene'" x-cloak class="pt-4 space-y-4">
        {{-- M9-01c: Allergene · Zusatzstoffe · Diät (geteiltes R6-Partial) --}}
        <x-foodalchemist::modal-section title="Deklaration">
            @include('foodalchemist::livewire.recipes.partials.deklaration', ['rezept' => $rezept])
        </x-foodalchemist::modal-section>

        {{-- M9-01d: Nährwerte (GL-08-Aggregate — pro 100 g + pro Stück; seit 2026-07-02 hier statt im eigenen Tab) --}}
        <x-foodalchemist::modal-section title="Nährwerte">
            @if($rezept->nutri_kcal_per_100g === null)
                <p class="text-[11px] text-gray-500" data-vk-naehrwerte-leer>Noch nicht aggregiert — läuft mit dem nächsten Zutaten-Speichern (GL-08).</p>
            @else
                <table class="{{ $table }}" data-vk-naehrwerte>
                    <thead><tr class="text-left">
                        <th class="{{ $th }}">Nährwert</th>
                        <th class="{{ $th }} text-right">pro 100 g</th>
                        <th class="{{ $th }} text-right">pro Stück {{ $gProStueck !== null ? '(≈ ' . number_format($gProStueck, 0, ',', '.') . ' g)' : '' }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach([
                            ['Brennwert', $rezept->nutri_kcal_per_100g, 'kcal', 0],
                            ['Eiweiß', $rezept->nutri_protein_g_per_100g, 'g', 1],
                            ['Fett', $rezept->nutri_fat_g_per_100g, 'g', 1],
                            ['— davon gesättigte Fettsäuren', $rezept->nutri_saturated_fat_g_per_100g, 'g', 1],
                            ['Kohlenhydrate', $rezept->nutri_carbs_g_per_100g, 'g', 1],
                            ['— davon Zucker', $rezept->nutri_sugar_g_per_100g, 'g', 1],
                            ['Salz', $rezept->nutri_salt_g_per_100g, 'g', 2],
                        ] as [$lbl, $wert, $unit, $dez])
                            <tr class="{{ $tr }}" wire:key="vkn-{{ $lbl }}">
                                <td class="{{ $td }} {{ $lbl === 'Brennwert' ? 'font-medium text-gray-900' : '' }}">{{ $lbl }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $wert !== null ? number_format((float) $wert, $dez, ',', '.') . ' ' . $unit : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $wert !== null && $gProStueck !== null ? number_format((float) $wert * $gProStueck / 100, $dez, ',', '.') . ' ' . $unit : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-[10px] text-gray-500 mt-1">
                    Konfidenz: <span class="font-medium {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$rezept->nutri_confidence] ?? '' }}">{{ strtoupper($rezept->nutri_confidence ?? '—') }}</span>
                    · {{ $rezept->nutri_n_ingredients_mapped ?? 0 }}/{{ $rezept->nutri_n_ingredients_total ?? 0 }} Zutaten mit Nährwert-Daten
                    {{ $rezept->nutri_aggregated_at !== null ? '· aggregiert ' . $rezept->nutri_aggregated_at->format('Y-m-d H:i') : '' }}
                    — Garverlust/Putzverlust werden NICHT angewendet (BLS-Rohwerte); Stück-Zutaten ohne g/ml-Basis tragen nichts bei.
                </p>
            @endif
        </x-foodalchemist::modal-section>

        {{-- M9-01e: Spezifikation (Bio-/Regional-Anteil, Gramm-gewichtet über GP-Tags) --}}
        <x-foodalchemist::modal-section title="Spezifikation">
            <div class="grid grid-cols-2 gap-3" data-vk-spezifikation>
                <div>
                    <span class="{{ $dt }}">Bio-Anteil</span>
                    <p class="text-lg font-semibold text-gray-900">{{ $anteile['bio'] !== null ? number_format($anteile['bio'], 1, ',', '.') . ' %' : '—' }}</p>
                </div>
                <div>
                    <span class="{{ $dt }}">Regional (DE)</span>
                    <p class="text-lg font-semibold text-gray-900">{{ $anteile['regional'] !== null && $anteile['regional'] > 0 ? number_format($anteile['regional'], 1, ',', '.') . ' %' : '—' }}</p>
                </div>
            </div>
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab DEKLARATION --}}

        {{-- ── Tab: KALKULATION (Verkaufseinheit + Verkaufs-Block) ──────── --}}
        <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 space-y-4">
        <x-foodalchemist::modal-section title="Verkaufseinheit">
            <div class="grid grid-cols-3 gap-3" data-vk-unit-block>
                <div>
                    <label class="block {{ $label }} mb-1">Einheit</label>
                    <select wire:model="form.sales_unit_vocab_id" class="{{ $input }}" data-vk-unit-select>
                        <option value="">—</option>
                        @foreach($verkaufsEinheiten as $e)
                            <option value="{{ $e->id }}">{{ $e->display_de ?? $e->slug }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Anzahl Einheiten (primär)</label>
                    <input type="number" step="0.1" min="0" wire:model="form.sales_unit_count" class="{{ $input }}" data-vk-anzahl />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">g/Einheit (leer = aus Yield)</label>
                    <input type="number" step="1" min="0" wire:model="form.sales_quantity_per_unit_g" class="{{ $input }}"
                           placeholder="{{ $cockpit['verkauft_als']['g_pro_einheit'] ?? '' }}" data-vk-g-unit />
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Verkaufs-Block (Live-Rohertrag)">
            <div class="grid grid-cols-3 gap-3" data-vk-verkaufsblock>
                <div>
                    <label class="block {{ $label }} mb-1">Preisklasse</label>
                    <select wire:model="form.markup_class_id"
                            wire:change="preisklasseGeaendert($event.target.value)"
                            class="{{ $input }}" data-vk-ak>
                        <option value="">—</option>
                        @foreach($aufschlagsklassen as $ak)
                            <option value="{{ $ak->id }}">{{ $ak->code }} ({{ number_format((float) ($ak->class_factor_pct ?? 100), 1, ',', '.') }} % relativ)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">MwSt</label>
                    <div class="{{ $input }} text-gray-500" data-vk-mwst>Wird je Darreichung als Profil gewählt</div>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Katalog-VK netto</label>
                    <div class="{{ $input }} tabular-nums" data-vk-netto-manuell>{{ $cockpit['vk']['sales_net'] !== null ? number_format($cockpit['vk']['sales_net'], 2, ',', '.') . ' €' : '—' }}</div>
                </div>
            </div>
            @if($cockpit !== null && $cockpit['vk']['vorschlag'] !== null)
                <p class="text-[11px] text-gray-500 mt-2" data-vk-vorschau>Auto-Vorschlag: {{ number_format($cockpit['vk']['vorschlag']['sales_net'], 2, ',', '.') }} € netto · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
            @endif
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab KALKULATION --}}

        {{-- ── Tab: DARREICHUNGEN (Umbau-Spec Phase 5 — Varianten je Servierform) ── --}}
        <div x-show="tab === 'darreichungen'" x-cloak class="pt-4 space-y-4" data-vk-darreichungen>
        <x-foodalchemist::modal-section title="Darreichungen">
            <p class="text-[11px] text-gray-500 mb-2">Ein Gericht = ein kulinarischer Kern; je Servierform eine Variante mit eigener Grammatur und eigenem EK/VK. Varianten entstehen nachfragegetrieben — meist per Klick aus dem Concepter. Komponenten dürfen nur reduziert oder weggelassen werden (neue Zutaten = neues Gericht).</p>
            {{-- Schon vergebene Formen: fallen aus der Anlage-Auswahl und aus den Zeilen-Selects
                 der ANDEREN Zeilen (eine Form höchstens einmal je Gericht, DB-Unique). --}}
            @php($belegte = $darreichungen->pluck('serving_form_id')->all())
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-1 pr-2 font-medium">Servierform</th>
                        <th class="py-1 pr-2 font-medium text-center">Standard</th>
                        <th class="py-1 pr-2 font-medium text-right">g/Einheit</th>
                        <th class="py-1 pr-2 font-medium text-right">Anzahl</th>
                        <th class="py-1 pr-2 font-medium">Preisklasse</th>
                        <th class="py-1 pr-2 font-medium">Preis</th>
                        <th class="py-1 pr-2 font-medium">MwSt</th>
                        <th class="py-1 pr-2 font-medium">Geschirr</th>
                        <th class="py-1 pr-2 font-medium text-right">EK/Portion</th>
                        <th class="py-1 pr-2 font-medium text-right">VK netto</th>
                        <th class="py-1 pr-2 font-medium text-right" title="Wareneinsatz: EK ÷ VK netto">W%</th>
                        <th class="py-1 pr-2 font-medium text-right">VK brutto</th>
                        <th class="py-1 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($darreichungen as $d)
                    <tr wire:key="dar-{{ $d->id }}" class="border-t border-black/5 align-top">
                        {{-- Servierform ist WÄHLBAR (2026-09-04): so kommt eine Zeile aus dem
                             Review-Zustand „Unbestimmt" heraus. Das Vokabular-Label selbst wird
                             nie umbenannt — es ist WaWi-Master und gilt für alle Gerichte. --}}
                        <td class="py-1.5 pr-2">
                            <select wire:change="darreichungForm({{ $d->id }}, $event.target.value)"
                                    class="{{ $input }} !py-0.5 !w-40 font-medium" data-dar-form="{{ $d->id }}"
                                    title="Servierform dieser Darreichung wechseln">
                                @foreach($servierformenAlle as $sf)
                                    @continue($sf->id !== $d->serving_form_id && in_array($sf->id, $belegte))
                                    <option value="{{ $sf->id }}" @selected($sf->id === $d->serving_form_id)>{{ $sf->label }}</option>
                                @endforeach
                            </select>
                            @if($d->created_via)<span class="block text-[10px] text-gray-500">{{ $d->created_via }}</span>@endif
                        </td>
                        <td class="py-1.5 pr-2 text-center">
                            <input type="radio" name="dar-standard" @checked($d->is_standard)
                                   wire:click="darreichungStandard({{ $d->id }})" title="Als Standard setzen" />
                        </td>
                        <td class="py-1.5 pr-2 text-right">
                            @if($d->deltas->count() > 0)
                                <span class="tabular-nums text-gray-600" title="Ergibt sich automatisch aus der Komponenten-Summe">{{ $d->quantity_per_unit_g !== null ? number_format($d->quantity_per_unit_g, 0, ',', '.') : '—' }} <span class="text-[10px] text-gray-500">Σ</span></span>
                            @else
                                <input type="text" wire:model.blur="darForm.{{ $d->id }}.quantity_per_unit_g"
                                       wire:change="darreichungSpeichern({{ $d->id }})" class="{{ $input }} !py-0.5 !w-16 text-right" />
                            @endif
                        </td>
                        <td class="py-1.5 pr-2 text-right">
                            <input type="text" wire:model.blur="darForm.{{ $d->id }}.unit_count"
                                   wire:change="darreichungSpeichern({{ $d->id }})" class="{{ $input }} !py-0.5 !w-12 text-right" />
                        </td>
                        <td class="py-1.5 pr-2">
                            <select wire:model="darForm.{{ $d->id }}.markup_class_id"
                                    wire:change="darreichungSpeichern({{ $d->id }})" class="{{ $input }} !py-0.5 !w-28">
                                <option value="">—</option>
                                @foreach($aufschlagsklassen as $ak)<option value="{{ $ak->id }}">{{ $ak->code }}</option>@endforeach
                            </select>
                        </td>
                        <td class="py-1.5 pr-2">
                            <div class="inline-flex overflow-hidden rounded border border-black/10">
                                <button type="button" wire:click="darreichungPreisModusGeaendert({{ $d->id }}, 'auto')"
                                        class="px-2 py-1 {{ ($darForm[$d->id]['price_mode'] ?? 'auto') === 'auto' ? 'bg-violet-600 text-white' : 'bg-transparent text-gray-500' }}">auto</button>
                                <button type="button" wire:click="darreichungPreisModusGeaendert({{ $d->id }}, 'fixed')"
                                        class="px-2 py-1 {{ in_array(($darForm[$d->id]['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true) ? 'bg-violet-600 text-white' : 'bg-transparent text-gray-500' }}">fixiert</button>
                            </div>
                        </td>
                        <td class="py-1.5 pr-2">
                            <select wire:model="darForm.{{ $d->id }}.vat_profile_key"
                                    wire:change="darreichungSpeichern({{ $d->id }})" class="{{ $input }} !py-0.5 !w-24">
                                <option value="">aus Klasse</option>
                                <option value="ermaessigt">ermäßigt</option>
                                <option value="regulaer">regulär</option>
                            </select>
                        </td>
                        <td class="py-1.5 pr-2">
                            <select wire:model="darForm.{{ $d->id }}.tableware_item_id"
                                    wire:change="darreichungSpeichern({{ $d->id }})" class="{{ $input }} !py-0.5 !w-32"
                                    title="Default-Geschirr dieser Form — wird im Concepter am Slot vorgeschlagen">
                                <option value="">—</option>
                                @foreach($geschirrItems as $gi)<option value="{{ $gi->id }}">{{ $gi->label }}</option>@endforeach
                            </select>
                        </td>
                        <td class="py-1.5 pr-2 text-right tabular-nums text-orange-600">
                            {{ $d->ek_portion !== null ? number_format($d->ek_portion, 2, ',', '.') . ' €' : '—' }}
                        </td>
                        <td class="py-1.5 pr-2 text-right tabular-nums">
                            @if(in_array(($darForm[$d->id]['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))
                                <input type="text" wire:model.blur="darForm.{{ $d->id }}.sales_net"
                                       class="{{ $input }} !py-0.5 !w-16 text-right" />
                                <input type="text" wire:model.blur="darForm.{{ $d->id }}.price_override_reason"
                                       placeholder="Begründung" class="{{ $input }} !py-0.5 !w-28 mt-1" />
                                <button type="button" wire:click="darreichungSpeichern({{ $d->id }})"
                                        class="mt-1 text-[10px] text-violet-600 hover:text-violet-800">Fixpreis übernehmen</button>
                            @else
                                <span class="text-emerald-600">{{ $d->sales_net !== null ? number_format($d->sales_net, 2, ',', '.') . ' €' : '—' }}</span>
                                @if($d->calculated_sales_net !== null)<span class="block text-[10px] text-gray-500">{{ $d->price_calculation_source }}</span>@endif
                            @endif
                        </td>
                        @php($darVkNetto = in_array(($darForm[$d->id]['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true)
                            ? (is_numeric(str_replace(',', '.', (string) ($darForm[$d->id]['sales_net'] ?? ''))) ? (float) str_replace(',', '.', (string) $darForm[$d->id]['sales_net']) : null)
                            : $d->sales_net)
                        @php($darWpct = ($d->ek_portion !== null && $darVkNetto !== null && $darVkNetto > 0) ? 100 * $d->ek_portion / $darVkNetto : null)
                        <td class="py-1.5 pr-2 text-right tabular-nums {{ $darWpct !== null && $darWpct > 35 ? 'text-rose-500' : 'text-gray-500' }}"
                            title="Wareneinsatz dieser Form">{{ $darWpct !== null ? number_format($darWpct, 0) . ' %' : '—' }}</td>
                        <td class="py-1.5 pr-2 text-right tabular-nums text-gray-500">
                            {{ $d->sales_gross !== null ? number_format($d->sales_gross, 2, ',', '.') . ' €' : '—' }}
                        </td>
                        <td class="py-1.5 text-right whitespace-nowrap">
                            <button type="button" wire:click="darDeltaToggle({{ $d->id }})"
                                    class="{{ $btnGhostXs }} {{ $d->deltas->count() > 0 ? 'text-violet-600' : 'text-gray-500' }}"
                                    title="Komponenten dieser Form anpassen (weglassen/reduzieren)">@svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5') {{ $d->deltas->count() ?: '' }}</button>
                            @unless($d->is_standard)
                                <button type="button" wire:click="darreichungLoeschen({{ $d->id }})" wire:confirm="Diese Darreichung löschen?"
                                        class="{{ $btnGhostXs }} text-rose-500" title="löschen">@svg('heroicon-o-trash', 'w-3.5 h-3.5')</button>
                            @endunless
                        </td>
                    </tr>
                    @if($darDeltaOffen === $d->id && $rezept !== null)
                        <tr wire:key="dar-delta-{{ $d->id }}">
                            <td colspan="13" class="pb-2">
                                <div class="rounded-lg bg-violet-500/[0.04] border border-violet-500/10 p-2 mt-1" data-dar-delta="{{ $d->id }}">
                                    <p class="text-[11px] text-gray-500 mb-1.5">Komponenten in dieser Form — echte Gramm <strong>je Einheit</strong> eintragen oder weglassen (leer = Standard). g/Einheit der Form ergibt sich automatisch aus der Summe. Neue Zutaten sind bewusst nicht möglich.</p>
                                    @php($deltaMap = $d->deltas->keyBy('recipe_ingredient_id'))
                                    <table class="w-full text-xs">
                                        <thead><tr class="text-left text-gray-500">
                                            <th class="py-0.5 pr-2 font-medium">Komponente</th>
                                            <th class="py-0.5 pr-2 font-medium text-right">Standard (g)</th>
                                            <th class="py-0.5 pr-2 font-medium text-right">Override (g)</th>
                                            <th class="py-0.5 font-medium text-center">weglassen</th>
                                        </tr></thead>
                                        <tbody>
                                        @foreach($rezept->ingredients as $z)
                                            @continue(! isset($darZeilen[$z->id]))
                                            @php($delta = $deltaMap->get($z->id))
                                            <tr wire:key="delta-{{ $d->id }}-{{ $z->id }}" class="border-t border-black/5 {{ $delta?->omitted ? 'opacity-40 line-through' : '' }}">
                                                <td class="py-1 pr-2">{{ $z->display_name ?? $z->gp?->gp_name ?? $z->referencedRecipe?->name ?? $z->raw_text }}</td>
                                                <td class="py-1 pr-2 text-right tabular-nums text-gray-500">{{ number_format($darZeilen[$z->id]['masse_g'], 0, ',', '.') }}</td>
                                                <td class="py-1 pr-2 text-right">
                                                    <input type="text" value="{{ $delta?->quantity_override_g }}"
                                                           wire:change="darDeltaMenge({{ $d->id }}, {{ $z->id }}, $event.target.value)"
                                                           class="{{ $input }} !py-0.5 !w-20 text-right" placeholder="—" />
                                                </td>
                                                <td class="py-1 text-center">
                                                    <input type="checkbox" @checked($delta?->omitted)
                                                           wire:click="darDeltaWeg({{ $d->id }}, {{ $z->id }})" />
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="12" class="py-3 text-center text-gray-500">Noch keine Darreichung — beim Speichern der VK-Daten entsteht automatisch die Standard-Form.</td></tr>
                @endforelse
                </tbody>
            </table>

            <div class="flex items-center gap-2 mt-2" data-dar-anlegen>
                <select wire:model="darNeueForm" class="{{ $input }} !py-1 w-52">
                    <option value="">Neue Darreichung: Servierform …</option>
                    @foreach($servierformenAlle as $sf)
                        @continue(in_array($sf->id, $belegte))
                        <option value="{{ $sf->id }}">{{ $sf->label }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="darreichungNeu" class="{{ $btnAi }}">+ Anlegen</button>
            </div>
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab DARREICHUNGEN --}}

        {{-- ── Tab: SERVICE (Behälter + Regeneration + Eigenschaften + Plating) ── --}}
        {{-- ── Tab: REGENERATION (§3.2 — finaler Garprozess am Einsatztag, oft am Satelliten;
             dazu die Behälter, weil sie Transport und Warmhalten tragen, §3.4) ───────────── --}}
        <div x-show="tab === 'regeneration'" x-cloak class="pt-4 space-y-4">
        <x-foodalchemist::modal-section title="Behälter (Transport & Warmhalten)">
            <x-slot:actions>
                <button type="button" wire:click="ki('behaelter')" class="{{ $btnAi }}" title="vk.behaelter: warm/kalt + Anzahl fürs Catering" data-ki-behaelter>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Behälter</button>
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3" data-vk-container>
                <div>
                    <label class="block {{ $label }} mb-1">Behälter warm</label>
                    <div class="flex gap-2">
                        <select wire:model="form.container_warm_vocab_id" class="{{ $input }} flex-1">
                            <option value="">—</option>
                            @foreach($behaelter as $b)
                                <option value="{{ $b->id }}" @if($b->is_inactive && $form['container_warm_vocab_id'] != $b->id) hidden @endif>{{ $b->name }}{{ $b->group_name ? ' · ' . $b->group_name : '' }}{{ $b->is_inactive ? ' (inaktiv)' : '' }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="0" wire:model="form.container_warm_count" class="{{ $input }} w-16" placeholder="n" />
                    </div>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Behälter kalt</label>
                    <div class="flex gap-2">
                        <select wire:model="form.container_cold_vocab_id" class="{{ $input }} flex-1">
                            <option value="">—</option>
                            @foreach($behaelter as $b)
                                <option value="{{ $b->id }}" @if($b->is_inactive && $form['container_cold_vocab_id'] != $b->id) hidden @endif>{{ $b->name }}{{ $b->group_name ? ' · ' . $b->group_name : '' }}{{ $b->is_inactive ? ' (inaktiv)' : '' }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="0" wire:model="form.container_cold_count" class="{{ $input }} w-16" placeholder="n" />
                    </div>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Regeneration (je Komponente, V-19)">
            <x-slot:actions>
                <button type="button" wire:click="kiRegeneration" class="{{ $btnAi }}" title="vk.regeneration: ein Programm je Komponente (Vorschlag, Übernahme je Zeile)" data-ki-regeneration>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Regeneration</button>
            </x-slot:actions>
            @if($regenVorschlaege !== [])
                <div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 space-y-1" data-regen-vorschlaege>
                    <p class="text-[11px] font-medium text-violet-700 inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Programm-Vorschläge — je Zeile übernehmen:</p>
                    @foreach($regenVorschlaege as $idx => $rv)
                        <div class="flex items-center justify-between gap-2 text-[11px] text-gray-600" wire:key="rvz-{{ $idx }}">
                            <span class="min-w-0 truncate">{{ $rv['component_label'] }}{{ $rv['temp_c'] !== null ? ' · ' . $rv['temp_c'] . ' °C' : '' }}{{ $rv['duration_min'] !== null ? ' · ' . $rv['duration_min'] . ' min' : '' }}{{ $rv['core_temp_c'] !== null ? ' · KT ' . $rv['core_temp_c'] . ' °C' : '' }}</span>
                            <button type="button" wire:click="regenVorschlagUebernehmen({{ $idx }})" class="{{ $btnGhostXs }} text-emerald-600 shrink-0" data-regen-uebernehmen>+ Übernehmen</button>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="space-y-1.5" data-vk-regen>
                @foreach($regenZeilen as $z)
                    <div wire:key="rg-{{ $z->id }}" class="flex items-center gap-2 text-xs text-gray-700" data-regen-zeile="{{ $z->id }}">
                        <span class="flex-1 truncate">
                            <span class="font-medium">{{ $z->component_label }}</span>
                            <span class="text-gray-500">· {{ $z->geraet ?? 'kalt servieren' }}{{ $z->temp_c !== null ? " · {$z->temp_c} °C" : '' }}{{ $z->duration_min !== null ? " · {$z->duration_min} min" : '' }}{{ $z->core_temp_c !== null ? " · KT {$z->core_temp_c} °C" : '' }}{{ $z->note ? " · {$z->note}" : '' }}</span>
                        </span>
                        <button type="button" wire:click="regenSchieben({{ $z->id }}, -1)" class="{{ $btnGhostXs }}" title="hoch">↑</button>
                        <button type="button" wire:click="regenSchieben({{ $z->id }}, 1)" class="{{ $btnGhostXs }}" title="runter">↓</button>
                        <button type="button" wire:click="regenBearbeiten({{ $z->id }})" class="{{ $btnGhostXs }}">Edit</button>
                        <button type="button" wire:click="regenLoeschen({{ $z->id }})" class="{{ $btnGhostXs }} text-rose-500">✕</button>
                    </div>
                @endforeach
                <div class="grid grid-cols-6 gap-2 pt-1" data-regen-form>
                    <input type="text" wire:model="regenForm.component_label" class="{{ $input }} col-span-2" placeholder="Komponente (z. B. Gesamt)" />
                    <select wire:model="regenForm.device_vocab_id" class="{{ $input }}">
                        <option value="">kalt</option>
                        @foreach($geraete as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                    </select>
                    <input type="number" wire:model="regenForm.temp_c" class="{{ $input }}" placeholder="°C" />
                    <input type="number" wire:model="regenForm.duration_min" class="{{ $input }}" placeholder="min" />
                    <input type="number" wire:model="regenForm.core_temp_c" class="{{ $input }}" placeholder="KT °C" />
                    <input type="text" wire:model="regenForm.note" class="{{ $input }} col-span-5" placeholder="Hinweis (z. B. abgedeckt, nach 8 min schwenken)" />
                    <button type="button" wire:click="regenSpeichern" class="{{ $btnGhostXs }}" data-regen-speichern>{{ $regenEditId !== null ? 'Aktualisieren' : '+ Zeile' }}</button>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        </div>{{-- /Tab REGENERATION --}}

        {{-- ── Tab: STAMMDATEN (Fortsetzung) ── Die Eigenschaften sind KEINE Anleitung:
             Arbeitszeit, Standzeit, Nebenkosten, Temperatur, Funktion, Fertigungstiefe und
             Beschreibung sind Attribute des Gerichts und gehören zu den Stammdaten (die
             verwandten Zeitfelder Rüst-/Vorlaufzeit stehen dort schon im Auto-Planer).
             Der Block steht nur physisch hier — der x-show-Wrapper ordnet ihn dem
             Stammdaten-Tab zu; die DOM-Reihenfolge weicht in diesem Editor ohnehin von der
             Tab-Reihenfolge ab. --}}
        <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 space-y-4">
        {{-- M9-01f: Eigenschaften (+ ✨ recipe.eigenschaften/geschmack) --}}
        <x-foodalchemist::modal-section title="Eigenschaften">
            <x-slot:actions>
                <button type="button" wire:click="ki('eigenschaften')" class="{{ $btnAi }}" data-ki-eigenschaften>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Eigenschaften</button>
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3" data-vk-eigenschaften>
                {{-- 2026-09-04: am Gericht ist das die FERTIGSTELLUNGS-Zeit. Die Auftrags-Explosion
                     erzeugt eine eigene Zeile je Komponente, jede mit ihrer eigenen Zeit — wer hier
                     die Gesamtzeit einträgt, zählt im selben Auftrag doppelt. --}}
                <div>
                    <label class="block {{ $label }} mb-1" title="Nur das Zusammensetzen am Einsatztag. Die Herstellungszeit steht am jeweiligen Basisrezept und wird im Auftrag als eigene Zeile geplant.">Fertigstellungszeit (min) <span class="normal-case text-gray-500">nur Zusammensetzen</span></label>
                    <input type="number" min="0" wire:model="form.work_time_min" class="{{ $input }}" data-vk-fertigstellungszeit />
                    @if(($komponentenZeiten['anzahl'] ?? 0) > 0)
                        <p class="text-[10px] text-gray-500 mt-1" data-vk-komponenten-zeiten>
                            Komponenten je Ansatz: {{ $komponentenZeiten['work_time_min'] }} min aktiv
                            @if($komponentenZeiten['setup_time_min'] > 0) · {{ $komponentenZeiten['setup_time_min'] }} min Rüsten @endif
                            @if($komponentenZeiten['ohne_zeit'] > 0)
                                · <span class="text-amber-600">{{ $komponentenZeiten['ohne_zeit'] }} von {{ $komponentenZeiten['anzahl'] }} ohne Zeitangabe</span>
                            @endif
                        </p>
                        {{-- Verdachts-Hinweis statt stiller Korrektur: ob im Feld die Gesamtzeit steht,
                             kann nur der Mensch entscheiden — automatisch abziehen wäre geraten. --}}
                        @if($komponentenZeiten['work_time_min'] > 0 && (float) ($form['work_time_min'] ?? 0) >= $komponentenZeiten['work_time_min'])
                            <p class="text-[10px] text-amber-600 mt-1" data-vk-zeit-verdacht>
                                Der Wert erreicht die Komponenten-Summe — steht hier vielleicht die Gesamtzeit statt der Fertigstellung? Sie würde doppelt zählen.
                            </p>
                        @endif
                    @endif
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Passive Standzeit (min)</label>
                    <input type="number" min="0" wire:model="form.standzeit_min" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1" title="Direkte Einzelkosten je ANSATZ (Energie, Verpackung …) — fließen als Block in HK2. Die Kalkulation teilt sie auf die Portionen des Ansatzes.">Nebenkosten (€ je Ansatz)</label>
                    <input type="number" min="0" step="0.01" wire:model="form.additional_costs_eur" class="{{ $input }}" data-vk-nebenkosten />
                </div>
                {{-- „Temperatur" und „Funktion" sind am Gericht RAUS (Entscheid 2026-09-04):
                     die Regeneration führt Garraum- und Kerntemperatur strukturiert je
                     Komponente — ein Freitext daneben wäre die nächste konkurrierende
                     Wahrheit. „Funktion" war die Speisen-Hauptgruppe als Freitext und wurde
                     nirgends gelesen. Beide bleiben am Basisrezept-Editor. --}}
                <div>
                    <label class="block {{ $label }} mb-1">Fertigungstiefe</label>
                    <select wire:model="form.production_depth" class="{{ $input }}">
                        <option value="">— unbestimmt —</option>
                        <option value="from_scratch">from scratch</option>
                        <option value="teilfertig">teilfertig</option>
                        <option value="convenience">Convenience</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">KI-Beschreibung (3–5 Sätze nüchtern, §8.3)</label>
                    <textarea wire:model="form.description" rows="3" class="{{ $input }}" data-vk-description></textarea>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        </div>{{-- /Tab STAMMDATEN (Fortsetzung) --}}

        {{-- ── Tab: ANRICHTEN (§3.3 — wie der Teller aufgebaut und ausgegeben wird; keine
             Produktion, keine Regenerations-Parameter. Dazu das Servier-Vehikel: das ist,
             was der Gast sieht, §3.4) ────────────────────────────────────────────────── --}}
        <div x-show="tab === 'plating'" x-cloak class="pt-4 space-y-4">
        <x-foodalchemist::modal-section title="Servier-Vehikel">
            <x-slot:actions>
                <button type="button" wire:click="ki('vehikel')" class="{{ $btnAi }}" title="vk.servier_vehikel: worauf wird angerichtet" data-ki-vehikel>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Servier-Vorschlag</button>
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block {{ $label }} mb-1">Servier-Vehikel <span class="normal-case text-gray-500">(was der Gast sieht)</span></label>
                    <select wire:model="form.serving_vehicle_vocab_id" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach($vehikel as $v)
                            <option value="{{ $v->id }}" @if($v->is_inactive && $form['serving_vehicle_vocab_id'] != $v->id) hidden @endif>{{ $v->name }}{{ $v->group_name ? ' · ' . $v->group_name : '' }}{{ $v->is_inactive ? ' (inaktiv)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        {{-- Teller-Aufbau als bebilderte Schrittfolge (User-Entscheid 2026-09-04): das
             Anrichten war die einzige der drei Ebenen ohne Bilder — dabei ist es der
             visuellste Arbeitsgang. Statt einer zweiten Foto-Mechanik läuft es über
             dieselben `recipe_steps` mit `ebene='anrichten'`; `plating_text` bleibt der
             gerenderte Spiegel (Foodbook/Report lesen unverändert das Textfeld). --}}
        <x-foodalchemist::modal-section title="Anrichten & Ausgabe">
            <x-slot:actions>
                <button type="button" wire:click="ki('plating')" class="{{ $btnAi }}" title="vk.plating: Plating-Vorschlag — wird in Anrichte-Schritte geparst" data-ki-plating>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')Plating</button>
            </x-slot:actions>
            <p class="text-[11px] text-gray-500 mb-2">
                Teller-Aufbau am Pass: Reihenfolge, Mengen je Teller, Geometrie, Garnitur — Schritt für Schritt, mit Fotos. Keine Produktion und keine Regenerations-Parameter.
            </p>
            <div data-vk-plating>
                <livewire:foodalchemist.recipes.step-editor :recipe-id="$rezept->id" ebene="anrichten"
                    wire:key="schritt-editor-vk-anrichten-{{ $rezept->id }}" />
            </div>
            <p class="text-[10px] text-gray-500 mt-1">
                Die Schritte sind der Master — <code>plating_text</code> wird daraus erzeugt (Foodbook, Angebot und Report lesen weiterhin diesen Text).
            </p>
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab ANRICHTEN --}}

        {{-- ── Tab: SENSORIK & PAIRING (Geschmacks-Balance + Textur + Aroma-Kohäsion über die Zutaten-GPs) ── --}}
        <div x-show="tab === 'sensorik'" x-cloak class="pt-4">
            @if($rezept !== null)
                <div class="flex items-center justify-between gap-2 mb-2">
                    <span class="text-[11px] text-gray-500">Gegartes Profil — KI liest Zutaten + Zubereitung.</span>
                    <button type="button" wire:click="sensorikBewerten" wire:loading.attr="disabled" wire:target="sensorikBewerten" class="{{ $btnAi }}">
                        <span wire:loading.remove wire:target="sensorikBewerten" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Sensorik neu bewerten</span>
                        <span wire:loading wire:target="sensorikBewerten">… bewertet</span>
                    </button>
                </div>
            @endif
            @if(($komposition ?? null) && ! ($komposition['leer'] ?? true))
                @include('foodalchemist::livewire.concepter.partials.sensorik_komposition')
            @else
                @include('foodalchemist::livewire.concepter.partials.sensorik')
            @endif
            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 mt-5 mb-2">Pairing</h3>
            @include('foodalchemist::livewire.concepter.partials.pairing')
        </div>

        {{-- ── Tab: FEEDBACK (R2.6 — Praxis-Feedback Küche/Kunde/Event) ── --}}
        @if($rezept !== null)
        <div x-show="tab === 'feedback'" x-cloak class="pt-4">
            @livewire('foodalchemist.recipes.feedback-panel', ['recipeId' => $rezept->id], key('feedback-vk-'.$rezept->id))
        </div>
        @endif

        {{-- ── Tab: NOTIZEN (Notizen + Verwendungsnachweise) ───────────── --}}
        <div x-show="tab === 'notes'" x-cloak class="pt-4 space-y-4">
        {{-- M9-01h: Notizen (§9.1 — manuelle Insel) --}}
        <x-foodalchemist::modal-section title="Notizen (§9.1 — bleibt bei jedem KI-Sync erhalten)">
            <textarea wire:model="form.notes_manual" rows="3" class="{{ $input }}" data-vk-notes></textarea>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Verwendungsnachweise (Kunde × Marketing-Name)">
            <div class="space-y-1.5" data-vk-kunden>
                @foreach($kunden as $k)
                    <div wire:key="kn-{{ $k->id }}" class="flex items-center gap-2 text-xs text-gray-700" data-kunde-zeile="{{ $k->id }}">
                        <span class="flex-1 truncate"><span class="font-medium">{{ $k->customer_name }}</span> <span class="text-gray-500">· {{ $k->marketing_name }}</span></span>
                        <button type="button" wire:click="kundeLoeschen({{ $k->id }})" class="{{ $btnGhostXs }} text-rose-500">✕</button>
                    </div>
                @endforeach
                <div class="grid grid-cols-5 gap-2 pt-1">
                    <input type="text" wire:model="kundeName" class="{{ $input }} col-span-2" placeholder="Kunde" data-kunde-name />
                    <input type="text" wire:model="kundeMarketing" class="{{ $input }} col-span-2" placeholder="Marketing-Name beim Kunden" data-kunde-marketing />
                    <button type="button" wire:click="kundeHinzufuegen" class="{{ $btnGhostXs }}" data-kunde-hinzufuegen>+ Nachweis</button>
                </div>
            </div>
        </x-foodalchemist::modal-section>
        </div>{{-- /Tab NOTIZEN --}}

        </x-foodalchemist::editor-tabs>
    @endif
</x-foodalchemist::modal>
