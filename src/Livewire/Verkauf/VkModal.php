<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Services\Produktion\RegenerationCascadeService;
use Platform\FoodAlchemist\Services\Rezept\SalesRecipeService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M6-04 / D-6 §4.2–4.5: VK-Editor — Anlage-Modus (»VK aus Basisrezept«,
 * DoD) + Edit-Modus mit Sektionen Stammdaten/VK-Wording, Klassifikation
 * (HG → Klasse zweistufig), Verkaufseinheit (Anzahl primär ⇒ g/Einheit
 * abgeleitet), Verkaufs-Block (AK, MwSt, VK-netto-manuell mit Vorschlags-
 * Vorschau), Container (Behälter warm/kalt + Vehikel), Regeneration
 * (V-19-Zeilen-CRUD + ↑↓) und Verwendungsnachweise. Schreibpfade laufen
 * durch SalesRecipeService (V-07-Transaktionen, V-12-Feld-Gate).
 * ✨-KI-Aktionen (Wording/Marketing/Klassifizieren) folgen mit M6-05.
 */
class VkModal extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\HatGpUmbauImGericht;   // Altbestand GP direkt im Gericht (17.11.2026)

    use \Platform\FoodAlchemist\Livewire\Concerns\HatRezeptCopilot;   // Spec 03 L6b
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;
    use \Platform\FoodAlchemist\Livewire\Concerns\HatDarreichungsFormular;   // Festpreis + deutsche Zahlen
    use \Platform\FoodAlchemist\Livewire\Concerns\HatAnreicherungsLauf;
    use \Livewire\WithFileUploads;
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65
    use \Platform\FoodAlchemist\Livewire\Concerns\HatRezeptModalWerkzeuge;   // 98 · 2.2c: Bild, KI, Sensorik, Sperr-Haken (wie RecipeModal)

    protected function sperrModalName(): ?string
    {
        return 'vk-modal';
    }

    /**
     * Lesend/Polling: ohne Bearbeiten-Sperre erlaubt. Die Copilot-Prüfung liest nur (Befunde zeigen, aus der Ablage
     * holen, verwerfen) — wie im RecipeModal (98 · 2.2c); Übernehmen bleibt gesperrt.
     */
    protected function sperrFreiExtra(): array
    {
        return ['pruefeProduktfotoErgebnis', 'beiZutatenPersistiert', 'beiModalGeschlossen', 'copilotPruefen', 'copilotAusAblage', 'copilotVerwerfen'];
    }

    /** Abbrechen: ungespeicherten Formularstand verwerfen, Datensatz frisch laden (eingebettete Editoren neu mounten). */
    protected function nachAbbrechen(): void
    {
        if ($this->recipeId !== null) {
            $this->oeffnen($this->recipeId);                 // setzt zutatenVersion nicht zurück
            $this->zutatenVersion++;                         // Komponenten-Zeilen leben im Client → neu mounten, sonst bleiben verworfene Eingaben stehen (98 · 2.2c)
        }
    }

    public ?int $recipeId = null;                                    // null = Anlage-Modus

    /** @var array<string, mixed> */
    public array $form = [];


    // Anlage-Modus
    public string $neuName = '';

    public string $basisSuche = '';

    public ?int $basisId = null;

    // V-19-Regen-Zeile (Formular)
    /** @var array<string, mixed> */
    public array $regenForm = [];

    public ?int $regenEditId = null;

    // Verwendungsnachweis
    public string $kundeName = '';

    public string $kundeMarketing = '';

    public ?string $fehler = null;

    // Darreichungen-Tab (Umbau-Spec Phase 5)
    /** @var array<int, array<string, mixed>> */
    public array $darForm = [];

    public ?int $darDeltaOffen = null;                               // Darreichung mit offenem Komponenten-Editor

    /** @var array<int, string> Darreichungs-Id → Suchtext für Zubehör & Verpackung (Non-Food-GP) */
    public array $darZubehoerSuche = [];

    public ?int $darZubehoerOffen = null;                            // Darreichung, deren Zubehör-Liste offen ist (Klick ins Feld)

    public string $darNeueForm = '';                                 // serving_form_id für Anlage

    /**
     * $copilot = Sprung aus dem Signal-Cockpit (Spec 21 · S5b): die abgelegten Befunde
     * werden direkt aufgeklappt. Kein Prüf-Call — s. `copilotAusAblage()`.
     */
    #[On('vk-modal.oeffnen')]
    public function oeffnen(?int $id = null, bool $copilot = false, ?int $ausBasis = null): void
    {
        $this->formZuruecksetzen();
        $this->recipeId = $id;
        $this->darAktiv = null;
        $this->fehler = null;
        $this->behaelterLaden();
        if ($id === null && $ausBasis !== null) {
            // Einstieg aus dem Basisrezept-Editor: Anlage-Modus mit vorgewähltem Basisrezept.
            $team = Auth::user()?->currentTeamRelation;
            $basis = $team !== null ? FoodAlchemistRecipe::visibleToTeam($team)->basis()->find($ausBasis) : null;
            if ($basis !== null) {
                $this->basisId = (int) $basis->id;
                $this->basisSuche = (string) $basis->name;
                $this->neuName = (string) $basis->name;
            }
        }
        if ($id !== null) {
            $team = Auth::user()?->currentTeamRelation;
            $r = $team !== null ? app(SalesRecipeService::class)->detail($team, $id) : null;
            if ($r === null) {
                return;
            }
            $this->form = [
                'name' => $r->name,
                'sales_wording_standard' => $r->sales_wording_standard,
                'taste_direction' => $r->taste_direction, 'vorgabe_convenience' => $r->vorgabe_convenience, 'vorgabe_niveau' => $r->vorgabe_niveau,
                'dish_class_id' => $r->dish_class_id,
                // Die Preisklasse steht NUR an der Darreichung (13.11.2026): ein zweites Feld im Kopf überschrieb sie beim Speichern mit seinem alten Stand.
                // 98 · 3.5 6b: Einheit, Grammatur, Portionszahl und Behälter-Typen stehen nur an der Darreichung (kein Rezept-Spiegel
                // mehr im Kopf); die Behälter-Anzahlen bleiben am Gericht.
                'container_warm_count' => $r->container_warm_count,
                'container_cold_count' => $r->container_cold_count,
                // serving_vehicle_vocab_id: Typ und Gefäß stehen nur an der Darreichung (18.11.2026), am Gericht abgeleitet
                // M9-01: Voll-Editor-Parität (Texte/Eigenschaften/Plating/Notizen).
                // marketing_text ist seit dem UX-Umbau 2026-07-03 raus: der Text lebt
                // kundenspezifisch am Foodbook-Block (Spalte bleibt als WaWi-Import-Spiegel).
                'description' => $r->description,
                'work_time_min' => $r->work_time_min,
                // Auto-Produktionsplaner (Parität Basisrezept-Editor 2026-08-03): Posten-Routing +
                // Rüstzeit + Vorproduzierbarkeit. Ohne diese landeten Gericht-Zeilen im Planer immer
                // „nicht zugeteilt" (ProductionPlanService routet über recipe.default_station_id).
                // `setup_time_min` + `max_vorlauf_tage` NICHT im Formular (Entscheid 2026-09-04):
                // Herstellungs-Eigenschaften, die am Gericht keine Bedeutung haben.
                'variable_work_time_min' => $r->variable_work_time_min,
                'variable_work_time_basis' => $r->variable_work_time_basis ?: 'portion',
                'standzeit_min' => $r->standzeit_min,
                'batch_max_kg' => $r->batch_max_kg,
                'batch_max_pieces' => $r->batch_max_pieces,
                'default_station_id' => $r->default_station_id,
                'additional_costs_eur' => $r->additional_costs_eur,             // M-K8: direkte Einzelkosten → HK2 (#379)
                // `temperature` + `function` NICHT im Formular (Entscheid 2026-09-04):
                // die Regeneration führt die Temperaturen strukturiert je Komponente,
                // und „Funktion" ist die Speisen-Hauptgruppe als Freitext.
                'production_depth' => $r->production_depth,
                // `plating_text` NICHT im Formular (2026-09-04): es ist der gerenderte
                // Spiegel der Anrichte-Schritte (Regelwerk §3.3). Ein Mitsenden bei jedem
                // Speichern würde die Schritte aus ihrem eigenen Spiegel neu parsen und
                // dabei die Foto-Verknüpfungen verlieren — dieselbe Regel wie bei
                // `preparation`, das hier ebenfalls fehlt.
                'notes_manual' => $r->notes_manual,
            ];
            // MVP-049: Modell A — die Hauptgruppe hängt am Gericht (`recipes.dish_main_group_id`),
            // nicht an der Klasse. Die alte Ableitung aus `dishClass.dish_main_group_id` war seit
            // der Umstellung auf die vier flachen Diätformen immer NULL: HG leer, Klassen-Select
            // deaktiviert, Klassifikation funktionslos.
            $this->form['dish_main_group_id'] = $r->dish_main_group_id;
        }
        $this->ladeDarreichungen();
        if ($copilot && $this->recipeId !== null) {
            $this->copilotAusAblage();
        }
        $this->istOffen = true;
        $this->dispatch('modal.open', name: 'vk-modal');
    }

    // ── Darreichungen (Umbau-Spec Phase 5) ──────────────────────────────

    /**
     * Spec 83 B3i/B3l: je Darreichung die Anrichte-Rechnung für die Vorschau-Portionen (Vorbelegung 100) — dieselbe Rechnung wie
     * Auftrag und Kalkulation ({@see \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::anrichten}). Herstellung der
     * Basisrezepte nur für die offene Darreichung (Rechnung des Planungsblatts, DarreichungsZeitService::herstellung).
     *
     * @return array<int, array<string,mixed>>
     */
    private function anrichtenVorschau(?\Platform\Core\Models\Team $team, $rezept): array
    {
        if ($team === null || ! $rezept instanceof \Platform\FoodAlchemist\Models\FoodAlchemistRecipe) {
            return [];
        }
        $zeit = app(\Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::class);
        $zjm = $zeit->zeitJeMenge($rezept, 'kg');
        $raus = [];
        $n = $this->vorschauPortionen();
        $dzs = app(\Platform\FoodAlchemist\Services\Rezept\DarreichungsZeitService::class);
        foreach ($rezept->presentations()->with('servingForm')->get() as $d) {
            $a = $zeit->anrichten($team, $rezept, [['dar' => $d, 'portionen' => $n]], null, $zjm);
            $raus[(int) $d->id] = ($a['formen'][0] ?? []) + ($this->darAktiv === null || $this->darAktiv === (int) $d->id ? app(\Platform\FoodAlchemist\Services\Planung\PlanungsblattService::class)->darreichungsHerstellung($team, $rezept, $d, $n) : [])
                + ['portionen' => $n, 'service_kosten' => $dzs->serviceKosten($team, (float) ($a['vor_ort_minuten'] ?? 0)), 'minuten_gesamt' => round($a['minuten'], 1), 'startwert' => match ($a['formen'][0]['einheit'] ?? 'portion') {
                'stueck' => \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::ANRICHTEN_SEKUNDEN_JE_STUECK,
                'behaelter' => \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::ANRICHTEN_MINUTEN_JE_PLATTE,
                'vor_ort' => null,
                default => $zeit->tellerKomponenten($rezept) * \Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::ANRICHTEN_SEKUNDEN_JE_KOMPONENTE,
            }];
        }

        return $raus;
    }

    private function darServiceCall(\Closure $aktion): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $aktion(app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class), $team);
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $offen = $this->darDeltaOffen;
        $entwuerfe = $this->darZeitEntwuerfe();
        $this->ladeDarreichungen();
        foreach ($entwuerfe as $id => $zeilen) {
            if (isset($this->darForm[$id]) && $zeilen != ($this->darZeitStart[$id] ?? [])) $this->darZeitForm[$id] = $zeilen;
        }
        $this->darDeltaOffen = $offen;
        $this->dispatch('recipe-gespeichert');
    }

    /** 98 · 3.5 6b: Portionsgrammatur aus der Standard-Darreichung (statt aus dem entfallenden Rezept-Kopf). */
    private function portionGramm(FoodAlchemistRecipe $r): ?float
    {
        $std = $r->standardPresentation()->first();
        $g = $std !== null ? app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)->grammJeEinheit($std, $r) : null;

        return $g !== null ? round($g, 1) : null;
    }

    public function darreichungSpeichern(int $id): void
    {
        // Plan 98 B: die Grammatur steht in der Zeile nur zur Anzeige — gesperrt, also nicht mitschicken
        $this->darServiceCall(fn ($svc, $team) => $svc->aktualisieren($team, $id, array_diff_key($this->darForm[$id] ?? [], ['quantity_per_unit_g' => true])));
    }

    public function darreichungPreisModusGeaendert(int $id, string $modus): void
    {
        if (! isset($this->darForm[$id]) || ! in_array($modus, ['auto', 'fixed'], true)) {
            return;
        }

        $this->darForm[$id]['price_mode'] = $modus;
        if ($modus === 'auto') {
            $this->darreichungSpeichern($id);
            if ($this->fehler === null) {
                $this->savedToast('Auto-Preis aktiviert');
            }
        }
    }

    public function darreichungNeu(): void
    {
        if ($this->recipeId === null || ! ctype_digit($this->darNeueForm)) {
            return;
        }
        $formId = (int) $this->darNeueForm;
        $this->darServiceCall(function ($svc, $team) use ($formId) {
            $this->darAktiv = (int) $svc->anlegen($team, $this->recipeId, $formId, [], 'fa_ui')->id;
        });
    }

    public function darreichungLoeschen(int $id): void
    {
        $this->darServiceCall(fn ($svc, $team) => $svc->loeschen($team, $id));
    }

    public function darreichungStandard(int $id): void
    {
        $this->darServiceCall(fn ($svc, $team) => $svc->setzeStandard($team, $id));
    }

    /**
     * Servierform einer Zeile wechseln — der Weg aus dem Review-Zustand „Unbestimmt"
     * (Entscheid 2026-09-04): entschieden wird die FORM, nicht der Name.
     */
    public function darreichungForm(int $id, ?string $servierformId): void
    {
        if ($servierformId === null || $servierformId === '') {
            return;                                               // leere Auswahl = kein Wechsel
        }
        $this->darServiceCall(fn ($svc, $team) => $svc->setzeServierform($team, $id, (int) $servierformId));
    }

    /** Zubehör & Verpackung (Non-Food) zu einer Form hinzufügen: Menge 1 je Einheit, danach im Feld änderbar. */
    public function darZubehoerHinzu(int $darId, int $gpId): void
    {
        $this->darServiceCall(fn ($svc, $team) => $svc->setzeZubehoer($team, $darId, $gpId, 1.0));
        if ($this->fehler === null) {
            unset($this->darZubehoerSuche[$darId]);
            $this->darZubehoerOffen = null;
        }
    }

    public function darZubehoerMenge(int $darId, int $gpId, ?string $wert): void
    {
        $menge = is_numeric(str_replace(',', '.', (string) $wert)) ? (float) str_replace(',', '.', (string) $wert) : 0.0;
        $this->darServiceCall(function ($svc, $team) use ($darId, $gpId, $menge) {
            $notiz = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungZubehoer::where('presentation_id', $darId)->where('gp_id', $gpId)->value('note');
            $svc->setzeZubehoer($team, $darId, $gpId, $menge, $notiz);
        });
    }

    public function darZubehoerWeg(int $darId, int $gpId): void
    {
        $this->darServiceCall(fn ($svc, $team) => $svc->entferneZubehoer($team, $darId, $gpId));
    }

    public function darDeltaToggle(int $id): void
    {
        $this->darDeltaOffen = $this->darDeltaOffen === $id ? null : $id;
    }

    public function darDeltaMenge(int $darId, int $ingId, ?string $wert): void
    {
        $quantity = is_numeric(str_replace(',', '.', (string) $wert)) ? (float) str_replace(',', '.', (string) $wert) : null;
        $this->darServiceCall(function ($svc, $team) use ($darId, $ingId, $quantity) {
            $weg = (bool) \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta::where('presentation_id', $darId)
                ->where('recipe_ingredient_id', $ingId)->value('omitted');
            $svc->setzeDelta($team, $darId, $ingId, $quantity, $weg);
        });
    }

    public function darDeltaWeg(int $darId, int $ingId): void
    {
        $this->darServiceCall(function ($svc, $team) use ($darId, $ingId) {
            $delta = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta::where('presentation_id', $darId)
                ->where('recipe_ingredient_id', $ingId)->first();
            $svc->setzeDelta($team, $darId, $ingId,
                $delta?->quantity_override_g !== null ? (float) $delta->quantity_override_g : null,
                ! (bool) ($delta?->omitted ?? false));
        });
    }

    // P-2-State-Leak-Schutz wie RecipeModal/GeneratorModal: Reset beim ÖFFNEN.
    // (Der frühere Zusatz „modal.closed erreicht Livewire nicht" war falsch: sechs Komponenten
    // hören per #[On('modal.closed')] darauf, und der Modal-Baustein dokumentiert es als
    // State-Leak-Vertrag. Der Reset bleibt trotzdem beim Öffnen — er ist die Stelle, die auch
    // ohne jedes Event greift.)
    /**
     * Spec 60 · P10: das Pairing-Panel (Kombinationslogik + Netz + Vorschläge, auf demo-Daten ~0,5 s)
     * erst rechnen, wenn der Tab „Sensorik & Pairing" besucht wird — nicht bei jedem Neuzeichnen
     * des Editors. Muster wie RecipeModal::tabLaden.
     */
    public bool $pairingGeladen = false;

    public function tabLaden(string $tab): void
    {
        if ($tab === 'sensorik') {
            $this->pairingGeladen = true;
        }
    }

    private function formZuruecksetzen(): void
    {
        $this->reset(['recipeId', 'istOffen', 'form', 'neuName', 'basisSuche', 'basisId', 'regenForm', 'regenEditId', 'kundeName', 'kundeMarketing', 'fehler', 'rollenVorschlag', 'regenVorschlaege',
            'ueberarbeitenOffen', 'anweisung', 'ueberarbeitung',     // L1a: Revise-Vorschau darf nicht ins nächste Gericht lecken
            'bulkRunId', 'anreicherung',                            // L1b: dito für die Anreicherungs-Lauf-Box
            'kiLineage',                                            // KI-Herkunfts-Marker gehören zu GENAU diesem Gericht
            'pairingGeladen']);                                     // Spec 60: Pairing je Gericht neu laden
        $this->copilotZuruecksetzen();                              // L6b: Befunde gehören zu GENAU diesem Gericht
    }


    public function anlegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->neuName) === '') {
            $this->fehler = 'Bitte einen Namen eingeben.';

            return;
        }
        try {
            // Basisrezept ist OPTIONAL: mit → ganze Charge als erste Komponente; ohne → leeres Gericht.
            $svc = app(SalesRecipeService::class);
            $vk = $this->basisId !== null
                ? $svc->createFromBasis($team, $this->basisId, trim($this->neuName))
                : $svc->createLeer($team, trim($this->neuName));
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->oeffnen($vk->id);                                     // direkt in den Edit-Modus
        $this->bearbeitenStarten();                                  // Spec 65: Neuanlage startet im Bearbeiten-Modus
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('vk-recipe-selected', id: $vk->id);
        $this->savedToast('Gericht angelegt');
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;   // ein erfolgreiches Speichern nach einem abgelehnten zeigt keinen alten Fehler mehr
        try {
            $update = array_map(
                fn ($v) => $v === '' ? null : $v, $this->form,
            );
            // Preis-Wahrheit liegt an der Darreichung: der angezeigte VK darf beim allgemeinen Speichern keinen Auto-Preis fixieren.
            unset($update['sales_net'], $update['price_override_reason']);
            // 98 · 3.5 6b: der Kopf trägt keine Darreichungs-Spiegel mehr — nichts zu filtern (vorher ohneUnveraenderteSpiegelFelder)
            $this->darZeitEntwuerfeSpeichern();
            app(SalesRecipeService::class)->updateVk($team, $this->recipeId, $update, $this->kiLineage);
            $this->kiLineage = [];

            // Spec 51: die Behälter-Overrides hängen am selben Speichern-Knopf. Leerer Behälter
            // heisst »wie die Komponente« — also die Override-Zeile weg, nicht eine leere anlegen.
            foreach ($this->behaelterForm as $zweck => $zeile) {
                if (($zeile['container_vocab_id'] ?? '') === '') {
                    app(SalesRecipeService::class)->deleteContainer($team, $this->recipeId, (string) $zweck);

                    continue;
                }
                app(SalesRecipeService::class)->upsertContainer($team, $this->recipeId, (string) $zweck, $zeile);
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->ladeDarreichungen();   // die Zeilen zeigen den gespeicherten Stand (sonst schriebe die nächste Zeilen-Änderung Altes zurück)
        $this->dispatch('recipe-gespeichert');
        // #1b: Schließen + finaler Toast erst NACH erfolgreichem Zutaten-Save. Der Button stößt
        // den Zutaten-Save sequenziert nach diesem Promise an (adressiert, MVP-046); der Editor
        // meldet `zutaten-persistiert` → beiZutatenPersistiert schließt. Hier NICHT schließen —
        // sonst wäre ein Zutaten-Fehler unsichtbar (Race-Fix, Parität zum Rezept-Editor).
    }

    /** VK-Layer lösen (D-6): löscht NUR das Gericht — Basisrezepte + GP-Verknüpfungen bleiben. */
    public function loeschen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(SalesRecipeService::class)->deleteDish($team, $this->recipeId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('recipe-gespeichert');
        $this->istOffen = false;
        $this->dispatch('modal.close', name: 'vk-modal');
    }

    // ── M9-01i: ✨-Aktionen — Vorschlag in die Form-Felder (Save = Accept, RecipeModal-Muster) ──

    /** Re-Mount-Zähler für den eingebetteten Zutaten-Editor (Client-rows). */
    public int $zutatenVersion = 0;

    /** @var ?array{rollen: array<int, string>, confidence: float, reasoning: ?string} */
    public ?array $rollenVorschlag = null;

    /**
     * Spec 50 (Nebenbefund 2026-09-06): welche Formularwerte aus einem ✨-KI-Vorschlag stammen —
     * Lineage-Präfix ⇒ Konfidenz. Bis hier landete ein KI-Wording/-Vehikel nur im Formular und
     * `speichern()` etikettierte es als `manual` (Handarbeit, die keine war — und die jede spätere
     * KI-Korrektur für immer blockte). Der Marker wandert in `updateVk(..., $kiLineage)`; tippt der
     * Nutzer nach der Übernahme selbst ins Feld, fällt er (dann IST es Handarbeit).
     *
     * @var array<string,float>
     */
    public array $kiLineage = [];

    /** Formular-Feld ⇒ Lineage-Präfix der Felder, die eine `_source`-Spalte tragen. */
    private const KI_LINEAGE_FELDER = [
        'sales_wording_standard' => 'sales_wording',
        'plating_text' => 'plating',
    ];

    /** Nutzer ändert ein KI-übernommenes Feld selbst → ab jetzt Handarbeit. */
    public function updatedForm(mixed $value, string $key): void
    {
        $praefix = self::KI_LINEAGE_FELDER[$key] ?? null;
        if ($praefix !== null) {
            unset($this->kiLineage[$praefix]);
        }
    }

    public function ki(string $aktion): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $r = app(SalesRecipeService::class)->detail($team, $this->recipeId);
        $gateway = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class);
        $kontext = [
            'name' => $this->form['name'] ?? $r->name,
            'sales_wording_standard' => $this->form['sales_wording_standard'] ?? null,
            'komponenten' => $r->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'speisen_klasse' => $r->dishClass?->label,
        ];

        try {
            match ($aktion) {
                'einordnung' => $this->uebernehmeEinordnung($team, $gateway, $kontext),
                'wording' => $this->uebernehmeText('sales_wording_standard', $gateway->propose('vk.wording', $kontext)),
                'plating' => $this->uebernehmePlating($gateway->propose('vk.plating', $kontext + ['portion_g' => $this->recipeId !== null && ($pr = FoodAlchemistRecipe::find($this->recipeId)) !== null ? $this->portionGramm($pr) : null])),
                'eigenschaften' => $this->uebernehmeEigenschaften($gateway, $kontext),
                'vehikel' => $this->uebernehmeVehikel($gateway->propose('vk.servier_vehikel', $kontext + [
                    'vokabular' => TeamScope::applyVisible(DB::table('foodalchemist_vocab_serving_vehicles')->whereNull('deleted_at')->where('is_inactive', false), 'team_id', $team)->pluck('name', 'id')->all(),
                ])),
                default => null,
            };
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function uebernehmeText(string $feld, \Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $wert = $v->werte[$feld] ?? null;
        if (is_string($wert) && trim($wert) !== '') {
            $this->form[$feld] = trim($wert);
            if (isset(self::KI_LINEAGE_FELDER[$feld])) {
                $this->kiLineage[self::KI_LINEAGE_FELDER[$feld]] = (float) ($v->confidence ?? 0.5);
            }
        } else {
            $this->fehler = 'KI lieferte keinen verwertbaren Text — echter Provider nötig.';
        }
    }

    /**
     * KI-Plating übernehmen (2026-09-04): der Vorschlag landet als ANRICHTE-SCHRITTE,
     * nicht in einem Formularfeld — `plating_text` ist nur noch deren Spiegel.
     *
     * `ueberschreiben: true`, weil der Klick eine bewusste Neu-Erzeugung ist (Muster wie
     * `regenVorschlagUebernehmen`). Foto-Verknüpfungen an ersetzten Schritten gehen dabei
     * verloren — das ist beim expliziten „neu vorschlagen" gewollt und unterscheidet den
     * Knopf vom stillen Markdown-Eingang in `updateVk`, der den Bestand schützt.
     */
    private function uebernehmePlating(\Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $wert = $v->werte['preparation'] ?? $v->werte['plating_text'] ?? null;   // Registry-Schema: {preparation}
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI lieferte keinen verwertbaren Plating-Text — echter Provider nötig.';

            return;
        }

        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null
            ? FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId)
            : null;
        if ($rezept === null) {
            return;
        }

        $ebene = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::EBENE_ANRICHTEN;
        $anzahl = app(\Platform\FoodAlchemist\Services\Rezept\RecipeStepService::class)->ausMarkdown(
            $rezept,
            trim($wert),
            ueberschreiben: true,
            ebene: $ebene,
        );

        if ($anzahl === 0) {
            $this->fehler = 'Im KI-Vorschlag war kein Anrichte-Schritt erkennbar.';

            return;
        }

        // Der eingebettete Step-Editor haelt seinen eigenen Puffer — ohne dieses Event
        // zeigte er weiter „0 Schritte", obwohl die Zeilen geschrieben waren.
        $this->dispatch('schritte-aktualisiert', recipeId: (int) $rezept->id, ebene: $ebene);
        $this->dispatch('recipe-gespeichert');
    }

    /**
     * KI-Knopf „Einordnung vorschlagen" (Dominique 2026-10-12): Speisen-Klasse + Hauptgruppe über den
     * SpeisenKlassenService (eine Wahrheit mit recipe_klasse.POST), Geschmacksrichtung und Fertigungstiefe
     * über ihre Prompts. Alles landet im Formular, Speichern übernimmt. Gefüllte Felder bleiben stehen
     * (Override-First) — leer heißt hier „nicht gesetzt" bzw. „unbestimmt".
     */
    private function uebernehmeEinordnung(\Platform\Core\Models\Team $team, \Platform\FoodAlchemist\Services\Ai\AiGatewayService $ki, array $kontext): void
    {
        $gesetzt = [];
        if ($this->recipeId !== null && (empty($this->form['dish_class_id']) || empty($this->form['dish_main_group_id']))) {
            $k = app(\Platform\FoodAlchemist\Services\GpStamm\SpeisenKlassenService::class)->classify($team, $this->recipeId);
            if (empty($this->form['dish_class_id']) && $k['klasse_id'] !== null) {
                $this->form['dish_class_id'] = (string) $k['klasse_id'];
                $gesetzt[] = 'Klasse '.$k['klasse_name'];
            }
            if (empty($this->form['dish_main_group_id']) && ($k['hauptgruppe_id'] ?? null) !== null) {
                $this->form['dish_main_group_id'] = (string) $k['hauptgruppe_id'];
                $gesetzt[] = 'Hauptgruppe '.$k['hauptgruppe_name'];
            }
        }
        if (empty($this->form['taste_direction'])) {
            $g = $ki->propose('recipe.geschmack', $kontext);
            if (in_array($g->werte['taste_direction'] ?? null, \Platform\FoodAlchemist\Services\Rezept\RecipeService::TASTE_DIRECTIONS, true)) {
                $this->form['taste_direction'] = $g->werte['taste_direction'];
                $gesetzt[] = 'Geschmack '.$g->werte['taste_direction'];
            }
        }
        if (empty($this->form['production_depth'])) {
            $f = $ki->propose('recipe.production_depth', ['name' => $kontext['name'], 'production_depth' => null, 'zutaten' => $kontext['komponenten']]);
            if (in_array($f->werte['production_depth'] ?? null, ['from_scratch', 'teilfertig', 'convenience'], true)) {
                $this->form['production_depth'] = $f->werte['production_depth'];
                $gesetzt[] = 'Fertigungstiefe '.$f->werte['production_depth'];
            }
        }
        $this->kiMeldung = $gesetzt === []
            ? 'Einordnung: KI lieferte nichts Neues oder alle Felder sind schon gesetzt.'
            : 'Einordnung vorgeschlagen: '.implode(' · ', $gesetzt).'. Speichern übernimmt.';
    }

    /** Rückmeldung der Einordnungs-/Behälter-KI im Reiter (kein Fehler). */
    public ?string $kiMeldung = null;

    /**
     * KI-Knopf „Behälter vorschlagen" (Dominique 2026-10-12): wie `RecipeModal::kiDichteklasse`, aber für
     * das Gericht — Behälter je Zweck, Skalierung und Referenzmenge aus dem Füllmengen-Dossier, nur
     * leere Felder (Override-First), gespeichert wird mit Speichern. Die ANZAHL bleibt Rechnung.
     */
    public function kiBehaelter(\Platform\FoodAlchemist\Services\Ai\AiGatewayService $gateway, \Platform\FoodAlchemist\Services\Ai\KnowledgeContextService $wissen): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $r = $team !== null && $this->recipeId !== null ? FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients.gp:id,name')->find($this->recipeId) : null;
        if ($r === null) {
            return;
        }
        $this->fehler = null;
        $katalog = TeamScope::applyVisible(DB::table('foodalchemist_vocab_containers')->whereNull('deleted_at')->where('is_inactive', false), 'team_id', $team)
            ->orderBy('sort_order')->get()->keyBy(fn ($b) => (int) $b->id);
        $wissenBlock = $wissen->contextFor($team, 'recipe.dichteklasse', trim($r->name.' Behälter Füllmenge Füllgrad Dichte'),
            null, [], \Platform\FoodAlchemist\Services\Knowledge\RezeptAchsen::fuer($r));
        try {
            $v = $gateway->propose('recipe.dichteklasse', [
                'name' => $r->name,
                'kategorie' => $r->category?->label,
                'zutaten' => $r->ingredients->take(15)->map->anzeigeName()->filter()->values()->all(),
                'ausbeute_kg' => $r->yield_kg !== null ? round((float) $r->yield_kg, 2) : null,
                'behaelter' => $katalog->map(fn ($b) => [
                    'id' => (int) $b->id, 'name' => $b->name, 'familie' => $b->familie,
                    'liter' => $b->volumen_l !== null ? (float) $b->volumen_l : null,
                    'tiefe_mm' => $b->tiefe_mm !== null ? (int) $b->tiefe_mm : null,
                    'freigegeben_fuer' => $b->eignung !== null ? json_decode((string) $b->eignung, true) : null,
                ])->values()->all(),
            ], \Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::proposeOptionen($wissenBlock));
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $gesetzt = [];
        $skalierung = $v->werte['skalierung'] ?? null;
        $behaelter = is_array($v->werte['behaelter_je_zweck'] ?? null) ? $v->werte['behaelter_je_zweck'] : [];
        $mengen = is_array($v->werte['referenz_menge_kg_je_zweck'] ?? null) ? $v->werte['referenz_menge_kg_je_zweck'] : [];
        foreach (FoodAlchemistVocabContainer::ZWECKE as $zweck) {
            $id = $behaelter[$zweck] ?? null;
            if (($this->behaelterForm[$zweck]['container_vocab_id'] ?? '') === '' && is_numeric($id) && $katalog->has((int) $id)) {
                $this->behaelterForm[$zweck]['container_vocab_id'] = (string) (int) $id;
                $gesetzt[] = ucfirst($zweck).': '.$katalog[(int) $id]->name;
            }
            if (($this->behaelterForm[$zweck]['skalierung'] ?? '') === '' && in_array($skalierung, ['tiefer_fuellbar', 'hoehe_gebunden', 'lagenware'], true)) {
                $this->behaelterForm[$zweck]['skalierung'] = $skalierung;
            }
            $m = $mengen[$zweck] ?? null;
            if (($this->behaelterForm[$zweck]['referenz_menge_kg'] ?? '') === '' && is_numeric($m) && (float) $m > 0) {
                $this->behaelterForm[$zweck]['referenz_menge_kg'] = rtrim(rtrim(number_format((float) $m, 3, ',', ''), '0'), ',');
            }
        }
        $this->kiMeldung = $gesetzt === []
            ? 'Behälter: KI lieferte keinen verwertbaren Vorschlag oder alles ist schon gepflegt.'
            : 'Behälter vorgeschlagen: '.implode(' · ', $gesetzt).'. Speichern übernimmt.';
    }

    private function uebernehmeEigenschaften(\Platform\FoodAlchemist\Services\Ai\AiGatewayService $ki, array $kontext): void
    {
        $v = $ki->propose('recipe.eigenschaften', $kontext + [
            'work_time_min' => $this->form['work_time_min'] ?? null,
            'setup_time_min' => $this->form['setup_time_min'] ?? null,
            'standzeit_min' => $this->form['standzeit_min'] ?? null,
            'variable_work_time_min' => $this->form['variable_work_time_min'] ?? null,
            'variable_work_time_basis' => $this->form['variable_work_time_basis'] ?? null,
            'temperature' => $this->form['temperature'] ?? null,
            'function' => $this->form['function'] ?? null,
        ]);
        foreach (['work_time_min', 'setup_time_min', 'standzeit_min', 'variable_work_time_min',
            'variable_work_time_basis', 'max_vorlauf_tage', 'temperature', 'function'] as $feld) {
            if (! empty($v->werte[$feld])) {
                $this->form[$feld] = $v->werte[$feld];
            }
        }
        $g = $ki->propose('recipe.geschmack', $kontext + ['taste_direction' => $this->form['taste_direction'] ?? null]);
        if (in_array($g->werte['taste_direction'] ?? null, \Platform\FoodAlchemist\Services\Rezept\RecipeService::TASTE_DIRECTIONS, true)) {
            $this->form['taste_direction'] = $g->werte['taste_direction'];
        }
    }

    /*
     * `uebernehmeBehaelter()` ist mit Spec 51 ENTFALLEN — zusammen mit dem Prompt `vk.behaelter`.
     *
     * Zwei Gruende, und der zweite war ein Datenverlust:
     *
     * 1. Die ANZAHL ist eine Rechnung, keine Schaetzung. Ein LLM danach zu fragen, waehrend die
     *    Datenbank die produzierte Menge exakt kennt, ist genau das Anti-Pattern der
     *    Kanon-Entscheidungsvorlage. Sie kommt jetzt aus BehaelterRechner.
     *
     * 2. Der Uebernahme-Pfad war destruktiv: der Prompt lieferte `container_warm_count`
     *    (englisch), gelesen wurde `behaelter_warm_anzahl` (deutsch) — und das Ergebnis wurde
     *    BEDINGUNGSLOS ins Formular geschrieben. Jede KI-Uebernahme hat damit die von Hand
     *    getippte Anzahl auf NULL gesetzt. Der Test fuetterte den deutschen Key und maskierte es.
     */

    private function uebernehmeVehikel(\Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $id = $v->werte['servier_vehikel_id'] ?? null;
        if ($id !== null && TeamScope::applyVisible(DB::table('foodalchemist_vocab_serving_vehicles')->whereNull('deleted_at')->where('id', (int) $id), 'team_id', $team)->exists()) {
            // Eine Stelle (18.11.2026): der Typ geht an die Standard-Darreichung; steht dort ein konkretes Gefäß, bestimmt das den Typ
            if (! app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)->typVorschlagUebernehmen($team, $this->recipeId, (int) $id, (float) ($v->confidence ?? 0.5))) {
                $this->fehler = 'An der Standard-Darreichung steht ein Gefäß — der Typ kommt aus dem Gefäß.';
            }
            $this->ladeDarreichungen();
        } else {
            $this->fehler = 'KI lieferte kein gültiges Servier-Vehikel — echter Provider nötig.';
        }
    }

    // ── M9-01i: ✨ Regeneration — Programm-Liste als Vorschlag, Übernahme je Zeile (GL-07) ──

    /** @var array<int, array> */
    public array $regenVorschlaege = [];

    public function kiRegeneration(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $r = app(SalesRecipeService::class)->detail($team, $this->recipeId);
        $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('vk.regeneration', [
            'name' => $r->name,
            'komponenten' => $r->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'vokabular' => TeamScope::applyVisible(DB::table('foodalchemist_vocab_regeneration_devices')->whereNull('deleted_at')->where('is_inactive', false), 'team_id', $team)->pluck('name', 'id')->all(),
        ]);
        $gueltig = TeamScope::applyVisible(DB::table('foodalchemist_vocab_regeneration_devices')->whereNull('deleted_at'), 'team_id', $team)->pluck('id')->flip();
        $this->regenVorschlaege = collect((array) ($v->werte['programme'] ?? []))
            ->filter(fn ($z) => is_array($z) && ! empty($z['component_label']))
            ->map(fn ($z) => [
                'component_label' => (string) $z['component_label'],
                'device_vocab_id' => isset($z['geraet_id']) && isset($gueltig[(int) $z['geraet_id']]) ? (int) $z['geraet_id'] : null,
                'temp_c' => is_numeric($z['temp_c'] ?? null) ? (int) $z['temp_c'] : null,
                'duration_min' => is_numeric($z['duration_min'] ?? null) ? (int) $z['duration_min'] : null,
                'core_temp_c' => is_numeric($z['core_temp_c'] ?? null) ? (int) $z['core_temp_c'] : null,
                'note' => is_string($z['note'] ?? null) ? $z['note'] : null,
            ])->values()->all();
        if ($this->regenVorschlaege === []) {
            $this->fehler = 'KI lieferte keine verwertbaren Regenerations-Programme — echter Provider nötig.';
        }
    }

    public function regenVorschlagUebernehmen(int $idx): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $zeile = $this->regenVorschlaege[$idx] ?? null;
        if ($team === null || $this->recipeId === null || $zeile === null) {
            return;
        }
        app(SalesRecipeService::class)->upsertRegeneration($team, $this->recipeId, $zeile, null);
        unset($this->regenVorschlaege[$idx]);
        $this->regenVorschlaege = array_values($this->regenVorschlaege);
    }

    // ── Spec 03 L1a: ✨ KI-Überarbeiten — freie Anweisung, Vorschau, Übernehmen (GL-07) ──
    //
    // Zweite Fläche derselben Strecke wie im RecipeModal: Vorschau über
    // RecipeReviseService::vorschau, Übernehmen über ::syncZeilen + den
    // #508-Grounding in RecipeService::syncIngredients. Der VK-Unterschied ist
    // NICHT die Mechanik, sondern der Schreib-Umfang: die Verkaufs-Facetten
    // (Klasse/Diät, Darreichungen, Verkaufseinheit, Aufschlagsklasse, Container,
    // Vehikel) gehen als Kontext HINEIN und werden nie zurückgeschrieben.

    /** Die drei Text-Felder, die ein VK-Revise anfassen darf (Facetten stehen bewusst NICHT drin). */
    private const REVISE_TEXTE = SalesRecipeService::REVISE_TEXTE;

    // Ablauf von kiUeberarbeiten/ueberarbeitungUebernehmen in HatRezeptModalWerkzeuge; hier die VK-Haken.

    protected function ueberarbeitungsPflichtText(): string
    {
        return 'Anweisung ist Pflicht (z. B. «mach das Gericht vegan und ersetze die Sauce»).';
    }

    protected function ueberarbeitungsRezept(\Platform\Core\Models\Team $team): ?FoodAlchemistRecipe
    {
        return app(SalesRecipeService::class)->detail($team, $this->recipeId);
    }

    /** Grounded VK-Freitext-Revision (Facetten + Regelwerk-Erdung via contextFor) im geteilten RecipeReviseService. */
    protected function ueberarbeitungsVorschlag(\Platform\Core\Models\Team $team, FoodAlchemistRecipe $r): array
    {
        return app(\Platform\FoodAlchemist\Services\Rezept\RecipeReviseService::class)->vkFreitextVorschlag($team, $r, $this->anweisung);
    }

    protected function ueberarbeitungVerwertbar(array $werte): bool
    {
        $hatText = false;
        foreach (array_keys(self::REVISE_TEXTE) as $feld) {
            $hatText = $hatText || is_string($werte[$feld] ?? null) && trim($werte[$feld]) !== '';
        }

        return ! (empty($werte['zutaten']) && ! $hatText);
    }

    protected function ueberarbeitungSchreiben(\Platform\Core\Models\Team $team, array $werte, mixed $konfidenz): bool
    {
        if (app(SalesRecipeService::class)->detail($team, $this->recipeId) === null) {
            return false;
        }
        $teile = app(SalesRecipeService::class)->uebernehmeUeberarbeitung($team, $this->recipeId, $werte, $konfidenz);
        foreach ($teile as $feld) {
            if (isset(SalesRecipeService::REVISE_TEXTE[$feld])) {
                $this->form[$feld] = trim((string) $werte[$feld]);
            }
        }

        return true;
    }

    /**
     * Die Verkaufs-Facetten als VORGABE im Prompt — der Grund, warum das VK-Revise
     * einen eigenen Prompt-Key hat: ohne sie revidiert die KI ein Fingerfood zum
     * Tellergericht oder ein veganes Gericht zurück in die Butter.
     *
     * @return array<string, mixed>
     */
    private function facetten(FoodAlchemistRecipe $r): array
    {
        return [
            'speisen_klasse' => $r->dishClass?->label,
            'diaetform' => $r->dishClass?->diet_form,
            'darreichungen' => $r->presentations->map(fn ($d) => $d->servingForm?->label)->filter()->values()->all(),
            // 98 · 3.5 6b: aus der Standard-Darreichung (ein Gericht = eine Portion)
            'verkaufseinheit' => $r->standardPresentation()->with('unit')->first()?->unit?->display_de,
            'portion_g' => $this->portionGramm($r),
            'aufschlagsklasse' => $r->vk_klasse?->code,   // Spec 85 D-1 PR-B
        ];
    }

    // ── M9-01a: 🎭 Rollen verteilen (V-21) — Proposal-Box über den Zutaten ──

    public function ai_rollen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $this->rollenVorschlag = app(\Platform\FoodAlchemist\Services\GpStamm\SpeisenKlassenService::class)
            ->verteileRollen($team, $this->recipeId);
    }

    public function accept_rollen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->rollenVorschlag === null || $this->rollenVorschlag['rollen'] === []) {
            return;
        }
        app(\Platform\FoodAlchemist\Services\GpStamm\SpeisenKlassenService::class)
            ->acceptRollen($team, $this->recipeId, $this->rollenVorschlag['rollen']);
        $this->rollenVorschlag = null;
        $this->zutatenVersion++;                                     // Editor-rows neu vom Server
        $this->dispatch('recipe-gespeichert');
    }

    public function reject_rollen(): void
    {
        $this->rollenVorschlag = null;
    }

    // ── Spec 51: Behälter je Zweck am Gericht (Override) + Bedarfs-Vorschau ──

    /** Referenz für die Vorschau. Rund, damit die Skalierung ablesbar ist. */
    public const VORSCHAU_PORTIONEN = 100;

    /** @var array<string, array<string, mixed>> */
    public array $behaelterForm = [];

    /** Lädt die Override-Zeilen des Gerichts. */
    private function behaelterLaden(): void
    {
        $zahl = fn ($w) => $w === null ? '' : rtrim(rtrim(number_format((float) $w, 3, ',', ''), '0'), ',');
        $vorhanden = $this->recipeId === null ? collect() : DB::table('foodalchemist_recipe_containers')
            ->where('recipe_id', $this->recipeId)->whereNull('deleted_at')->get()->keyBy('zweck');

        $this->behaelterForm = [];
        foreach (FoodAlchemistVocabContainer::ZWECKE as $zweck) {
            $z = $vorhanden->get($zweck);
            $this->behaelterForm[$zweck] = [
                'container_vocab_id' => (string) ($z->container_vocab_id ?? ''),
                'referenz_menge_kg' => $zahl($z->referenz_menge_kg ?? null),
                'skalierung' => (string) ($z->skalierung ?? ''),
                'stueck_je_behaelter' => (string) ($z->stueck_je_behaelter ?? ''),
            ];
        }
    }

    /**
     * Bedarfs-Vorschau — über DENSELBEN Pfad wie die Produktion.
     *
     * Bewusst kein zweiter Rechenweg im Editor: eine Vorschau, die anders rechnet als der Auftrag,
     * ist schlimmer als keine. Deshalb das echte Produktionsblatt für eine runde Referenzmenge.
     *
     * @return list<string>
     */
    private function behaelterVorschau(): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return [];
        }

        try {
            $blatt = app(\Platform\FoodAlchemist\Services\Planung\PlanungsblattService::class)
                ->produktionsblattFuerZiele($team, [[
                    'recipe_id' => $this->recipeId, 'portions' => self::VORSCHAU_PORTIONEN,
                ]]);
        } catch (\Throwable) {
            return [];
        }

        $summe = [];
        foreach ($blatt['rezepte'] ?? [] as $zeile) {
            $block = $zeile['behaelter'] ?? null;
            if ($block === null) {
                continue;
            }
            $kandidaten = array_filter(
                [$block['abfuellen'] ?? null, ...($block['je_komponente'] ?? [])],
                fn ($e) => is_array($e) && ! ($e['bereits_gezaehlt'] ?? false) && ($e['berechenbar'] ?? false)
            );
            foreach ($kandidaten as $e) {
                $basis = $e['varianten'][0] ?? null;
                if ($basis !== null && ($basis['behaelter'] ?? null) !== null) {
                    $summe[$basis['behaelter']] = ($summe[$basis['behaelter']] ?? 0) + (int) $basis['anzahl'];
                }
            }
        }

        $raus = [];
        foreach ($summe as $name => $anzahl) {
            $raus[] = "{$anzahl}× {$name}";
        }

        return $raus;
    }

    // ── V-19 + Spec 51: Regen-Zeilen als Kaskade ─────────────────────────

    /**
     * Was der Tab zeigt: Gesamt-Zeile(n) plus eine Zeile je Direkt-Komponente, mit Herkunft.
     * Vgl. {@see RegenerationCascadeService} — Tiefe 1, Overrides gewinnen, Luecken werden gezaehlt.
     */
    private function kaskade(): array
    {
        $leer = ['gesamt' => [], 'komponenten' => [], 'luecken' => 0, 'verwaist' => []];
        if ($this->recipeId === null) {
            return $leer;
        }
        $r = FoodAlchemistRecipe::find($this->recipeId);

        return $r !== null ? app(RegenerationCascadeService::class)->fuerRezept($r) : $leer;
    }

    /**
     * Eine geerbte Zeile anfassen heisst: einen Override anlegen — sichtbar, mit Ruecksetzer.
     * Vorbefuellt mit dem geerbten Stand, damit »ein Grad anders« nicht bei null anfaengt.
     */
    public function regenKomponenteBearbeiten(int $ingredientId): void
    {
        $zeile = collect($this->kaskade()['komponenten'])->firstWhere('ingredient_id', $ingredientId);
        if ($zeile === null) {
            return;
        }

        $this->regenEditId = $zeile['regeneration_id'];               // gesetzt = bestehender Override
        $this->regenForm = [
            'ingredient_id' => $ingredientId,
            'component_label' => $zeile['label'],
            'device_vocab_id' => $zeile['device_vocab_id'],
            'temp_c' => $zeile['temp_c'],
            'duration_min' => $zeile['duration_min'],
            'core_temp_c' => $zeile['core_temp_c'],
            'note' => $zeile['note'],
        ];
    }

    /** Override weg = die Komponente gibt wieder den Ton an. */
    public function regenOverrideZuruecksetzen(int $regenerationId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(SalesRecipeService::class)->deleteRegeneration($team, $this->recipeId, $regenerationId);
            $this->regenForm = [];
            $this->regenEditId = null;
        }
    }



    public function regenBearbeiten(int $id): void
    {
        $zeile = DB::table('foodalchemist_recipe_regenerations')->where('id', $id)->where('recipe_id', $this->recipeId)->first();
        if ($zeile !== null) {
            $this->regenEditId = $id;
            $this->regenForm = [
                'ingredient_id' => $zeile->ingredient_id,            // Override bleibt Override
                'component_label' => $zeile->component_label, 'device_vocab_id' => $zeile->device_vocab_id,
                'temp_c' => $zeile->temp_c, 'duration_min' => $zeile->duration_min, 'core_temp_c' => $zeile->core_temp_c,
                'note' => $zeile->note,
            ];
        }
    }

    public function regenSpeichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(SalesRecipeService::class)->upsertRegeneration($team, $this->recipeId, array_map(
            fn ($v) => $v === '' ? null : $v, $this->regenForm,
        ) + ['ingredient_id' => null], $this->regenEditId);
        $this->regenForm = [];
        $this->regenEditId = null;
    }

    public function regenLoeschen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(SalesRecipeService::class)->deleteRegeneration($team, $this->recipeId, $id);
        }
    }

    public function regenSchieben(int $id, int $richtung): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $ids = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->recipeId)
            ->whereNull('deleted_at')->orderBy('sort_order')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        $ziel = $pos !== false ? $pos + $richtung : false;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        app(SalesRecipeService::class)->reorderRegenerations($team, $this->recipeId, $ids);
    }

    // ── Verwendungsnachweise ─────────────────────────────────────────────

    public function kundeHinzufuegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || trim($this->kundeName) === '' || trim($this->kundeMarketing) === '') {
            return;
        }
        app(SalesRecipeService::class)->addCustomerName($team, $this->recipeId, $this->kundeName, $this->kundeMarketing);
        $this->kundeName = '';
        $this->kundeMarketing = '';
    }

    public function kundeLoeschen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(SalesRecipeService::class)->deleteCustomerName($team, $this->recipeId, $id);
        }
    }

    // ── Spec 03 L1b: ✨ Alles anreichern (Bulk-Mechanik M7-06 auf EIN Gericht) ──
    //
    // Unterschied zu den ✨-Einzelknöpfen daneben: die schreiben in `$form` (der
    // Nutzer sieht den Text und speichert), hier entstehen VORSCHLÄGE mit Confidence
    // in der Review-Liste, und »Alle übernehmen« schreibt Override-First mit Lineage
    // `ki`. Die Schrittfolge ist die VK-Ebene (SCHRITTE_VK), nicht die Basisrezept-
    // Folge — sonst würde der Knopf am Gericht die 186er-Rezept-Kategorie anreichern.

    protected function anreicherungsRezept(\Platform\Core\Models\Team $team): ?FoodAlchemistRecipe
    {
        return app(SalesRecipeService::class)->detail($team, $this->recipeId);
    }

    protected function anreicherungFertigEvent(): string
    {
        return 'vk-recipe-gespeichert';
    }

    /**
     * Auswahlliste für die Verkaufseinheit (Entscheid Dominique 2026-09-04).
     *
     * Ein Gericht wird als Portion, in Stück, nach Gewicht oder als Volumen
     * verkauft — nicht in Prisen und Zweigen. Das Einheiten-Vokabular bedient
     * aber primär die Zutaten-Zeilen und bot hier bisher über 50 Einträge an.
     *
     * Bestandsschutz: die aktuell gesetzte Einheit bleibt in der Liste, auch
     * wenn sie nicht zur Whitelist gehört. Ohne sie fiele der Wert stumm aus
     * dem Select und der nächste Speichern-Klick würde die Einheit löschen.
     *
     * @return \Illuminate\Support\Collection<int, FoodAlchemistVocabEinheit>
     */
    private function verkaufsEinheiten(\Platform\Core\Models\Team $team): \Illuminate\Support\Collection
    {
        $slugs = (array) config('foodalchemist.sales_units', []);
        // Bestandsschutz für die an den Darreichungen gesetzten Einheiten (98 · 3.5 6b: kein Rezept-Spiegel mehr)
        $gesetzt = collect($this->darForm)->pluck('unit_vocab_id')->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $treffer = FoodAlchemistVocabEinheit::visibleToTeam($team)
            ->where(function ($q) use ($slugs, $gesetzt) {
                $q->where(fn ($q2) => $q2->whereIn('slug', $slugs)->where('is_inactive', false));
                if ($gesetzt !== []) {
                    $q->orWhereIn('id', $gesetzt);           // Bestandsschutz, auch wenn inaktiv
                }
            })
            ->get(['id', 'slug', 'display_de']);

        // Anzeigereihenfolge = Reihenfolge der Whitelist (Portion zuerst = Normalfall);
        // eine bestandsgeschützte Fremd-Einheit hängt hinten dran.
        $rang = array_flip(array_values($slugs));

        return $treffer->sortBy(fn ($e) => $rang[$e->slug] ?? PHP_INT_MAX)->values();
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? $verkauf->detail($team, $this->recipeId) : null;
        // Ebene 2 (D3): VK folgt dem aktiven Betrieb (ambienter Kontext).
        $outlet = $team !== null ? app(\Platform\FoodAlchemist\Services\Plattform\ActiveOutletContext::class)->current($team) : null;

        // M9-01d/e: Nährwerte pro Stück + Bio-/Regional-Anteil (Gramm-gewichtet über GP-Tags)
        $gProStueck = null;
        $anteile = ['bio' => null, 'regional' => null];
        if ($rezept !== null) {
            $cockpitTmp = $verkauf->cockpit($rezept, $team, $outlet);
            // „je Einheit“ = Grammatur der Standard-Darreichung (Preis-Wahrheit, gleiche Rückfall-Kette wie ihr EK: Darreichung → Gericht → Ertrag ÷ Anzahl),
            // sonst die Vorgabe am Gericht. Vorher nur das Gericht: ohne Verkaufseinheit am Gericht blieb die Spalte leer (18.11.2026).
            $standardDar = $rezept->standardPresentation()->first();
            $gProStueck = ($standardDar !== null ? app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)->grammJeEinheit($standardDar, $rezept) : null)
                ?? ($cockpitTmp['verkauft_als']['g_pro_einheit'] ?? null);
            $totalG = 0.0;
            $summen = ['bio' => 0.0, 'regional' => 0.0];
            foreach ($rezept->ingredients as $z) {
                $faktor = (float) ($z->unit?->default_in_g ?? $z->unit?->default_in_ml ?? 0);
                $g = (float) $z->quantity * $faktor;
                if ($g <= 0 || $z->is_optional) {
                    continue;
                }
                $totalG += $g;
                if ($z->gp?->tag_is_organic) {
                    $summen['bio'] += $g;
                }
                if ($z->gp?->tag_is_regional) {
                    $summen['regional'] += $g;
                }
            }
            if ($totalG > 0) {
                $anteile = ['bio' => round(100 * $summen['bio'] / $totalG, 1), 'regional' => round(100 * $summen['regional'] / $totalG, 1)];
            }
        }

        // L1b: Lauf-Status der ✨-Anreicherung (Poll-Zeile über dem Editor)
        $bulkRun = $this->bulkRunId !== null && $team !== null
            ? app(\Platform\FoodAlchemist\Services\Plattform\BulkEnrichService::class)->status($team, $this->bulkRunId) : null;

        return view('foodalchemist::livewire.verkauf.vk-modal', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'rezept' => $rezept,
            'gpDirekt' => $this->gpDirektZeilen($rezept),   // Altbestand GP direkt im Gericht (17.11.2026)
            'dishImageUrl' => ($rezept !== null && ($rezept->image_context_file_id || $rezept->image_path))
                ? app(\Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService::class)->url($rezept->image_context_file_id, $rezept->image_path)
                : null,
            'bulkRun' => $bulkRun,
            'bulkOffen' => $bulkRun !== null
                ? app(\Platform\FoodAlchemist\Services\Plattform\BulkEnrichService::class)->offeneVorschlaege($team, $this->bulkRunId) : 0,
            'cockpit' => $rezept !== null ? ($cockpitTmp ?? $verkauf->cockpit($rezept, $team, $outlet)) : null,
            'gProStueck' => $gProStueck,
            'anteile' => $anteile,
            // Spec 83 B3: Teller-Komponenten (Startwert Anrichten) + B3i/B3l: Hochrechnung je Darreichung für die Vorschau-Portionen
            'anrichteKomponenten' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Produktion\ProductionTimeService::class)->tellerKomponenten($rezept) : 0,
            'anrichtenVorschau' => $this->anrichtenVorschau($team, $rezept),
            'hauptgruppen' => $team !== null ? $verkauf->dishMainGroups($team) : collect(),
            // MVP-049 + MVP-050 (P0): identisch zum Browser (Verkauf/Browser::render) — Klasse ist
            // die Diätform als EIGENE Achse (`dish_main_group_id IS NULL`), unabhängig von der
            // Hauptgruppe. Beide Listen teamgescopt; vorher lasen sie über alle Teams hinweg,
            // während die Nachbarzeilen darunter es korrekt machten.
            'klassen' => FoodAlchemistDishClass::visibleToTeam($team)
                ->whereNull('dish_main_group_id')->orderBy('id')->get(['id', 'label', 'diet_form']),
            'aufschlagsklassen' => FoodAlchemistMarkupClass::visibleToTeam($team)->bereich('speisen')
                ->where('is_inactive', false)->orderBy('code')->get(['id', 'code', 'label', 'class_factor_pct']),
            'einheiten' => $team !== null ? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)->orderBy('slug')->get(['id', 'slug', 'display_de']) : collect(),
            // Entscheid 2026-09-04: die VERKAUFS-Einheit ist eine eigene, kurze Liste —
            // `einheiten` bleibt unverändert das volle Vokabular für die Zutaten-Zeilen.
            'verkaufsEinheiten' => $team !== null ? $this->verkaufsEinheiten($team) : collect(),
            'behaelter' => TeamScope::applyVisible(DB::table('foodalchemist_vocab_containers')->whereNull('deleted_at'), 'team_id', $team)->orderBy('group_name')->orderBy('sort_order')->get(['id', 'name', 'group_name', 'is_inactive']),
            'geraete' => TeamScope::applyVisible(DB::table('foodalchemist_vocab_regeneration_devices')->whereNull('deleted_at'), 'team_id', $team)->orderBy('sort_order')->get(['id', 'name', 'is_inactive']),
            'vehikel' => TeamScope::applyVisible(DB::table('foodalchemist_vocab_serving_vehicles')->whereNull('deleted_at'), 'team_id', $team)->orderBy('group_name')->orderBy('sort_order')->get(['id', 'name', 'group_name', 'is_inactive']),
            // 2026-09-04: die Posten der Komponenten, read-only neben dem Fertigstellungs-Posten.
            // Ein Posten ist nie für ein ganzes Gericht zuständig — abgeleitet, nie persistiert.
            'beteiligtePosten' => $rezept !== null ? $verkauf->beteiligtePosten($rezept) : collect(),
            // Orientierung zum Zeitfeld: die Komponenten bringen im Auftrag ihre eigene Zeit
            // mit (eine Auftragszeile je Rezept) — am Gericht steht nur die Fertigstellung.
            'komponentenZeiten' => $rezept !== null ? $verkauf->komponentenZeiten($rezept) : null,
            // Stufe 3 — Posten-Liste fürs Default-Posten-Dropdown (Parität Basisrezept-Editor).
            'posten' => $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::visibleToTeam($team)
                    ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
            // Spec 51: die Liste ist ABGELEITET. Gespeichert ist nur, was hier bewusst
            // uebersteuert wurde — der Rest kommt aus den Komponenten und folgt ihnen.
            'kaskade' => $this->kaskade(),
            // Gefäß je Darreichung: wirksamer Typ, Übersteuerung, Warnung (Füllmenge > Volumen), Miete — alles berechnet (DarreichungService::gefaessInfo)
            'darGefaess' => $this->recipeId !== null && $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $this->recipeId)->get()
                    ->mapWithKeys(fn ($d) => [(int) $d->id => app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)->gefaessInfo($d, $team)])->all()
                : [],
            // MwSt „aus Preisklasse“: was die leere Wahl je Darreichung ergibt (Anzeige im Select)
            'darMwstVorgabe' => $this->recipeId !== null && $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $this->recipeId)->get()
                    ->mapWithKeys(fn ($d) => [(int) $d->id => app(\Platform\FoodAlchemist\Services\Preis\CatalogPricingService::class)->vatKeyAusPreisklasse($team, $d)])->all()
                : [],
            'gefaessGps' => $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->nonFood()->whereNotIn('status', ['rejected', 'merged'])
                    ->whereIn('sub_category', \Platform\FoodAlchemist\Support\NonFoodRegeln::mitAlias(\Platform\FoodAlchemist\Support\NonFoodRegeln::SUB_GESCHIRR))->orderBy('name')->limit(300)->get(['id', 'name', 'serving_vehicle_vocab_id'])
                : collect(),
            'behaelterVorschau' => $this->behaelterVorschau(),
            'behaelterVorschauPax' => self::VORSCHAU_PORTIONEN,
            'kunden' => $this->recipeId !== null
                ? TeamScope::applyVisible(DB::table('foodalchemist_recipe_customer_names')->where('recipe_id', $this->recipeId)->whereNull('deleted_at'), 'team_id', $team)->orderBy('customer_name')->get()
                : collect(),
            'basisTreffer' => $this->recipeId === null && trim($this->basisSuche) !== '' && $team !== null
                ? \Platform\FoodAlchemist\Support\Suche::like(
                    FoodAlchemistRecipe::visibleToTeam($team)->basis(), 'name', $this->basisSuche)
                    ->orderBy('name')->limit(6)->get(['id', 'name', 'yield_kg', 'ek_total_eur'])
                : collect(),
            // Darreichungen-Tab (Umbau-Spec Phase 5)
            'darreichungen' => $this->recipeId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::with('servingForm', 'deltas')
                    ->where('recipe_id', $this->recipeId)->orderByDesc('is_standard')->orderBy('id')->get()
                : collect(),
            // Bestandsschutz (2026-09-04): eine schon zugewiesene Form bleibt in der Liste,
            // auch wenn sie inzwischen deaktiviert wurde. Sonst fiele sie aus dem Zeilen-Select
            // und ein unbedachter Change würde die Form der Darreichung stumm überschreiben.
            'servierformenAlle' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where(function ($q) {
                $q->where('is_inactive', false);
                if ($this->recipeId !== null) {
                    $q->orWhereIn('id', \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::query()
                        ->where('recipe_id', $this->recipeId)->pluck('serving_form_id'));
                }
            })->orderBy('sort_order')->get(['id', 'code', 'label', 'is_inactive']),
            // Zubehör & Verpackung je Form (interne Sicht: Zeile mit Menge und EK) + Trefferliste für die Suche (nur Non-Food-GPs)
            'darZubehoer' => $this->recipeId !== null && $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $this->recipeId)->pluck('id')
                    ->mapWithKeys(fn ($id) => [(int) $id => app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)
                        ->zubehoerKosten(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::find($id), $team)])->all()
                : [],
            'darZubehoerTreffer' => $team !== null   // Liste beim Klick ins Feld, sonst Treffer zum Suchtext (ZubehoerAuswahl, 19.11.2026)
                ? collect($this->darZubehoerSuche)->filter(fn ($t) => trim((string) $t) !== '')->union($this->darZubehoerOffen !== null ? [$this->darZubehoerOffen => ''] : [])
                    ->map(fn ($t) => \Platform\FoodAlchemist\Support\ZubehoerAuswahl::treffer(\Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team), $t))->all()
                : [],
            'darZeilen' => $rezept !== null
                ? app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)->standardProEinheit($rezept, $team, $this->darAktiv)
                : [],
            'sensorik' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Rezept\SensorikService::class)->fuerRezept($rezept->id, true) : null,
            'komposition' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Rezept\SensorikService::class)->gerichtKomposition($rezept->id) : null,
            'pairing' => $rezept !== null && $this->pairingGeladen ? app(\Platform\FoodAlchemist\Services\Pairing\PairingService::class)->panelRecipe($rezept) : null,
        ]);
    }
}
