<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Platform\FoodAlchemist\Support\Masse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceChangeAudit;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungZubehoer;

/**
 * Darreichungs-CRUD + Preisrechnung (Umbau-Spec Darreichungen, Phase 5).
 *
 * Invarianten:
 *  - genau EINE Standard-Darreichung pro Gericht (setzeStandard swappt transaktional)
 *  - mehrere Varianten derselben Servierform möglich (z. B. kleines und großes Glas)
 *  - Deltas nur auf Zutatenzeilen des Kernrezepts (FK) — Grenzregel E5:
 *    weglassen/umgewichten ja, neue Zutaten strukturell unmöglich
 *  - FA-native Anlagen tragen created_via (F12; WaWi-Importe haben legacy_id)
 *
 * Preise (spiegelt WaWi-Recompute 206 Stufe 4):
 *  - Stufe 1 (keine Deltas): ek_portion = EK/g des Rezepts × Grammatur × Anzahl
 *  - Stufe 2 (Deltas): Misch-Preis/g über Komponenten NACH Delta (omitted raus,
 *    Kosten skalieren linear mit der Masse), dann × Grammatur × Anzahl
 *  - auto: dynamischer Vorschlag aus Unternehmens-Basissatz × relativem Klassenfaktor
 *  - fixed: Live-VK bleibt, der Vergleichsvorschlag rechnet weiter; Begründung ist Pflicht
 *  - MwSt kommt als Profil-Schlüssel aus Darreichung → Preisklasse → Team
 *  - Preis-Wahrheit ist die Darreichung; die Rezept-Spalten sales_net/markup_class_id gibt es nicht mehr (Spec 85 D-1)
 */
class DarreichungService
{
    public function __construct(
        private RecipeRecomputeService $recompute,
        private \Platform\FoodAlchemist\Services\Preis\CatalogPricingService $catalogPricing,
        private \Platform\FoodAlchemist\Services\TeamSettingsService $settings,
    ) {}

    /**
     * Plan 98 B, Sperre (Entscheid Dominique 22.11.2026, Modell 23.11.): Es gibt keine freie Grammatur mehr. Ein Gericht ist
     * EINE Portion — die Bestandteile tragen ihre Menge je Portion, die Grammatur des Standards ist ihre Summe; Varianten
     * rechnen aus ihren Komponenten (Anteil am Standard oder Mengen je Komponente). Zentral hier, damit Oberfläche, MCP und
     * Importe gleich laufen.
     */
    public const GRAMMATUR_GESPERRT = 'Die Grammatur ist nicht direkt setzbar: Sie ist am Standard die Summe der Bestandteile '
        .'(Mengen je Portion), an einer Variante die Summe ihrer Komponenten. Setze stattdessen die Zutatenmengen des Gerichts, '
        .'an einer Variante den Anteil am Standard (anteil_prozent) oder Mengen je Komponente (recipe_darreichung_delta.PUT).';

    /**
     * Plan 98 B (h), Entscheid Dominique 23.11. („ok fixe die Ki“): liegt die Portion des Standards (Summe der Bestandteile, bei
     * festem Ertrag gegart) über dieser Grenze, wirken die Mengen wie eine Charge — kein Auto-VK, Hinweis am Gericht. Nur die
     * Obergrenze (Kurator 23.11.): Canapés, Pralinen, Amuse-Bouche liegen echt bei 10–15 g; eine Herkunft „aus dem Generator“
     * ist am Rezept nicht verlässlich markiert (created_via mal mcp, plan_go, import oder leer), deshalb gilt die Regel für alle Gerichte.
     */
    public const CHARGE_OBERGRENZE_G = 3000.0;

    public const CHARGE_HINWEIS = 'Mengen wirken wie eine Charge – bitte prüfen';

    /** Modell 23.11. (Dominique): die Portionszahl eines Gerichts ist fest 1 — kein Teiler. Altbestand > 1 stellt die Grammatur-Umstellung um. */
    public const PORTIONSZAHL_FEST = 'Die Portionszahl eines Gerichts ist fest 1: Die Zutatenmengen gelten je Portion. '
        .'Ein Gericht mit Mengen für mehrere Portionen wird über foodalchemist:grammatur-automatisch auf eine Portion skaliert.';

    public function anlegen(Team $team, int $recipeId, int $servierformId, array $attrs = [], string $createdVia = 'fa_ui'): FoodAlchemistRecipeDarreichung
    {
        if (array_key_exists('quantity_per_unit_g', $attrs)) {
            throw new \RuntimeException(self::GRAMMATUR_GESPERRT);
        }

        return $this->anlegenIntern($team, $recipeId, $servierformId, $attrs, $createdVia, true);
    }

    /**
     * $folgen: jede neue Form folgt der Mengenquelle (Plan 98 B). Nur die Selbstheilung {@see ensureStandard} legt einen
     * fehlenden Standard noch in Altbestand-Form an (Grammatur aus dem Kopf) — sie repariert Bestand, sie pflegt nicht.
     */
    private function anlegenIntern(Team $team, int $recipeId, int $servierformId, array $attrs, string $createdVia, bool $folgen): FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::wennNichtEigen($recipe, $team, 'die Darreichung');   // Spec 84 §4.0
        if (! $recipe->is_sales_recipe) {
            // Darreichung = Verkaufsform. Ein Basisrezept wird über ein Gericht verkauft, das es als Komponente trägt.
            throw new \RuntimeException('„' . $recipe->name . '" ist ein Basisrezept — Darreichungen gibt es nur an Gerichten.');
        }
        // Spec 85: Fremdschlüssel team-autorisieren (Sichtbarkeit, wie TeamScope::referenz) — sonst hängt ein MCP-Aufruf eine
        // fremde Form/Klasse (z. B. die des Masters) an die eigene Darreichung: Etikett leckt, Daten falsch.
        $servierform = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)->where('is_inactive', false)->find($servierformId);
        if ($servierform === null) {
            throw new \RuntimeException('Unbekannte Servierform.');
        }
        $attrs = [...$attrs, ...$this->referenzenPruefen($team, $attrs)];
        if (mb_strlen((string) ($attrs['name'] ?? '')) > 120) {
            throw new \RuntimeException('Die Bezeichnung darf höchstens 120 Zeichen haben.');
        }

        $standard = $recipe->standardPresentation;
        $name = trim((string) ($attrs['name'] ?? ''));
        if ($name === '' && $standard !== null) {
            $nummer = $recipe->presentations()->withTrashed()->where('serving_form_id', $servierformId)->count() + 1;
            $name = mb_substr($servierform->label, 0, 95).' · Variante '.$nummer;
        }
        $darreichung = FoodAlchemistRecipeDarreichung::create([
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'serving_form_id' => $servierformId,
            'is_standard' => $standard === null,               // erste Form = Standard
            // Plan 98 B: jede neue Form folgt der Mengenquelle — der Standard der Rezeptur, eine Variante dem Standard
            // 98 · 3.5 6b: ohne Blick auf den Rezeptkopf (Spiegel entfällt) — Grammatur immer automatisch, außer sie wird mitgegeben
            'menge_vom_standard' => $folgen || ! isset($attrs['quantity_per_unit_g']),
            // Vorbefüllung aus der Standard-Form; recomputePreise rechnet sie gleich neu
            'quantity_per_unit_g' => $attrs['quantity_per_unit_g'] ?? $standard?->quantity_per_unit_g,
            'unit_vocab_id' => $attrs['unit_vocab_id'] ?? $standard?->unit_vocab_id,
            'unit_count' => $attrs['unit_count'] ?? 1,
            'markup_class_id' => $attrs['markup_class_id'] ?? $standard?->markup_class_id
                ?? $this->vorbelegteKlasseId($team, $recipe),
            'price_mode' => $attrs['price_mode'] ?? 'auto',
            'sales_net' => $attrs['sales_net'] ?? null,
            'note' => $attrs['note'] ?? null,
            'name' => $name !== '' ? $name : null,
            'arbeitszeiten' => array_key_exists('arbeitszeiten', $attrs)
                ? app(DarreichungsZeitService::class)->normalisieren($attrs['arbeitszeiten']) : null,
            // Servier-Typ: Vorgabe = wirksamer Typ der Standard-Darreichung (98 · 3.5 6b: direkt, nicht mehr über den Rezept-Spiegel);
            // das konkrete Gefäß wählt man an der Zeile
            'serving_vehicle_vocab_id' => $attrs['serving_vehicle_vocab_id'] ?? ($standard !== null ? $this->vehikelId($standard) : null),
            // Spec 83 B3i/B3l: NICHT aus der Standard-Form vorbefüllen — die Einheit hängt an der Form (s je Teller ≠ min je Platte)
            'anrichten_wert' => isset($attrs['anrichten_wert']) && is_numeric(str_replace(',', '.', (string) $attrs['anrichten_wert']))
                ? (float) str_replace(',', '.', (string) $attrs['anrichten_wert']) : null,
            'anrichten_pauschal_min' => isset($attrs['anrichten_pauschal_min']) && is_numeric(str_replace(',', '.', (string) $attrs['anrichten_pauschal_min']))
                ? (float) str_replace(',', '.', (string) $attrs['anrichten_pauschal_min']) : null,
            'created_via' => $createdVia,
        ]);

        $this->recomputePreise($darreichung);

        return $darreichung->refresh();
    }

    /**
     * Stellt sicher, dass ein (VK-)Gericht eine Standard-Darreichung hat. Legt sonst
     * eine auf der Form „unbestimmt" an (Review-Queue, kein teller-Default — Servierform-
     * Regel der Umbau-Spec) mit vorbelegter Aufschlagsklasse. Idempotent.
     * Gibt die Standard-Darreichung zurück (oder null, wenn die unbestimmt-Form fehlt).
     */
    public function ensureStandard(Team $team, int $recipeId, string $createdVia = 'mcp'): ?FoodAlchemistRecipeDarreichung
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if (! $recipe->is_sales_recipe) {
            return null; // Basisrezept: keine Verkaufsform (s. anlegen())
        }

        $standard = $recipe->standardPresentation()->first();
        if ($standard !== null) {
            return $standard;
        }
        if ($recipe->presentations()->exists()) {
            return null; // Varianten ohne Standard-Flag: nichts raten (wie syncStandardDarreichung)
        }
        // Spec 84 §4.0: erst beim Anlegen prüfen — das Nachschlagen oben bleibt für jede sichtbare Zeile erlaubt
        \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::wennNichtEigen($recipe, $team, 'die Darreichung');

        $unbestimmt = $this->unbestimmtId($team);

        // 98 · 3.5 6b: der Standard entsteht automatisch (Grammatur = Summe der Bestandteile, eine Portion je Gericht) — ohne
        // Werte aus den Rezept-Spiegeln (sales_quantity_per_unit_g / sales_unit_count / sales_unit_vocab_id), die entfallen.
        return $this->anlegenIntern($team, $recipe->id, $unbestimmt, [
            'unit_count' => 1,
            'markup_class_id' => $this->vorbelegteKlasseId($team, $recipe),
        ], $createdVia, false);
    }

    /**
     * Spec 85 D-1 PR-B: Vorbelegung der Aufschlagsklasse für eine neue Darreichung — Speisen-Klasse → Hauptgruppe →
     * Team-Standard (Defaults zählen nur, wenn die Klasse für das Team sichtbar ist, wie RecipeOneShotService::markupKaskade).
     * Die Klasse des Gerichts IST danach die seiner Standard-Darreichung.
     */
    public function vorbelegteKlasseId(Team $team, FoodAlchemistRecipe $recipe): ?int
    {
        $sichtbar = fn (?int $id) => $id !== null
            && \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::visibleToTeam($team)->bereich('speisen')->whereKey($id)->exists() ? $id : null;

        return $sichtbar($recipe->dishClass?->default_markup_class_id)
            ?? $sichtbar($recipe->dishMainGroup?->default_markup_class_id)
            ?? $this->settings->defaultMarkupClassId($team);
    }

    private const FELDER = [
        'name', 'arbeitszeiten', 'ausgabe_station_id', 'menge_vom_standard',
        'unit_vocab_id', 'unit_count',   // quantity_per_unit_g: gesperrt (Plan 98 B), siehe GRAMMATUR_GESPERRT
        'markup_class_id', 'price_mode', 'sales_net',
        'vat_profile_key', 'price_override_reason', 'price_override_expires_at',
        'container_warm_vocab_id', 'container_cold_vocab_id',
        // Spec 51: die vier Regenerations-Skalare sind entfallen. Sie waren eine zweite Wahrheit
        // neben `recipe_regenerations`, die kein Schreibpfad je fuellte — eine Servierform aendert
        // nichts an 140 °C. Behaelter und Vehikel bleiben: dort greift die Form legitim ein.
        'serving_vehicle_vocab_id',
        'work_time_surcharge_min', 'offer_text_override', 'note',
        'anrichten_wert', 'anrichten_pauschal_min',   // Spec 83 B3i/B3l: Anrichte-Zeit je Darreichung (Einheit aus der Form)
        'vessel_gp_id',      // Gefäß der Form: Non-Food-GP (WG 16); der Servier-Typ kommt daraus
        'vessel_cost_type',  // 17.11.2026: Art des Gefäßes übersteuern (einweg|mehrweg|extern), leer = Eintrag des Teams am GP
    ];

    /** Spec 85: Fremdschlüssel der Darreichung → Vokabular (Model-Klasse oder Tabelle ohne Model). Sichtbar heißt erlaubt. */
    private const REFERENZEN = [
        'ausgabe_station_id' => [\Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::class, 'Ausgabe-Posten'],
        'markup_class_id' => [\Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::class, 'Aufschlagsklasse'],
        'unit_vocab_id' => [\Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::class, 'Einheit'],
        'container_warm_vocab_id' => [\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::class, 'Behälter warm'],
        'container_cold_vocab_id' => [\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::class, 'Behälter kalt'],
        'serving_vehicle_vocab_id' => ['foodalchemist_vocab_serving_vehicles', 'Servier-Vehikel'],
    ];

    /**
     * Gesetzte Fremdschlüssel aus {@see self::REFERENZEN} gegen die Team-Sicht prüfen (eigene, geerbte, globale Zeilen).
     * Ein UNVERÄNDERTER Bestandswert bleibt erlaubt (wie updateVk) — sonst bricht das Speichern anderer Felder.
     *
     * @return array<string, ?int> die geprüften Werte (als int), nur für die übergebenen Schlüssel
     */
    private function referenzenPruefen(Team $team, array $werte, ?FoodAlchemistRecipeDarreichung $bestand = null): array
    {
        $geprueft = [];
        foreach (self::REFERENZEN as $feld => [$ziel, $bezeichnung]) {
            if (! array_key_exists($feld, $werte)
                || ($bestand?->{$feld} !== null && is_numeric($werte[$feld]) && (int) $werte[$feld] === (int) $bestand->{$feld})) {
                continue;
            }
            if (class_exists($ziel)) {
                $geprueft[$feld] = \Platform\FoodAlchemist\Support\TeamScope::referenz($ziel, $werte[$feld], $team, $bezeichnung);

                continue;
            }
            $id = $werte[$feld];
            if ($id === null || $id === '' || (int) $id === 0) {
                $geprueft[$feld] = null;

                continue;
            }
            if (! \Platform\FoodAlchemist\Support\TeamScope::applyVisible(DB::table($ziel)->whereNull('deleted_at'), 'team_id', $team)->where('id', (int) $id)->exists()) {
                throw new \RuntimeException("{$bezeichnung}: die gewählte Zuordnung ist in diesem Team nicht verfügbar.");
            }
            $geprueft[$feld] = (int) $id;
        }

        return $geprueft;
    }

    private const DEZIMAL_FELDER = [
        'unit_count' => 'Anzahl',
        'anrichten_wert' => 'Anrichten je Einheit',
        'anrichten_pauschal_min' => 'Anrichten-Pauschale',
        'sales_net' => 'VK netto',
    ];

    public function aktualisieren(Team $team, int $darreichungId, array $attrs): FoodAlchemistRecipeDarreichung
    {
        if (array_key_exists('quantity_per_unit_g', $attrs)) {
            throw new \RuntimeException(self::GRAMMATUR_GESPERRT);
        }
        $darreichung = $this->find($team, $darreichungId);
        $update = array_intersect_key($attrs, array_flip(self::FELDER));
        if (array_key_exists('menge_vom_standard', $update)) {
            if (! in_array($update['menge_vom_standard'], [true, false, 0, 1, '0', '1'], true)) {
                throw new \RuntimeException('Mengenherkunft muss Standard oder eigene Menge sein.');
            }
            $update['menge_vom_standard'] = (bool) $update['menge_vom_standard'];
        }
        if (array_key_exists('arbeitszeiten', $update)) {
            $update['arbeitszeiten'] = app(DarreichungsZeitService::class)->normalisieren($update['arbeitszeiten']);
        }
        if (array_key_exists('name', $update) && mb_strlen((string) $update['name']) > 120) {
            throw new \RuntimeException('Die Bezeichnung darf höchstens 120 Zeichen haben.');
        }
        foreach ($update as $k => $v) {
            if ($v === '') {
                $update[$k] = null;
            }
        }
        foreach (self::DEZIMAL_FELDER as $feld => $bezeichnung) {
            if (! array_key_exists($feld, $update) || $update[$feld] === null) {
                continue;
            }
            $wert = str_replace(',', '.', trim((string) $update[$feld]));
            if (! is_numeric($wert)) {
                throw new \RuntimeException($bezeichnung . ' muss eine gültige Zahl sein.');
            }
            $update[$feld] = (float) $wert;
        }
        if (array_key_exists('vat_profile_key', $update) && $update['vat_profile_key'] !== null
            && ! in_array($update['vat_profile_key'], ['regulaer', 'ermaessigt'], true)) {
            throw new \RuntimeException('Unbekanntes MwSt-Profil.');
        }
        $legacyManual = ($update['price_mode'] ?? null) === 'manuell';
        if (array_key_exists('price_mode', $update)) {
            $update['price_mode'] = $legacyManual ? 'fixed' : $update['price_mode'];
            if (! in_array($update['price_mode'], ['auto', 'fixed'], true)) {
                throw new \RuntimeException('Unbekannter Preismodus.');
            }
        }
        $update = [...$update, ...$this->referenzenPruefen($team, $update, $darreichung)];
        if (array_key_exists('vessel_cost_type', $update)) {
            $update['vessel_cost_type'] = \Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::pruefe($update['vessel_cost_type']);
        }
        // Gefäß: nur ein sichtbares Non-Food-GP (WG 16)
        if (array_key_exists('vessel_gp_id', $update) && $update['vessel_gp_id'] !== null) {
            $gefaess = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find((int) $update['vessel_gp_id']);
            if ($gefaess === null) {
                throw new \RuntimeException('Gefäß (Grundprodukt) nicht sichtbar oder nicht vorhanden.');
            }
            if (! $gefaess->istNonFood()) {
                throw new \RuntimeException('Als Gefäß nimmt die Darreichung nur Non-Food-Grundprodukte (Warengruppe 16).');
            }
            $update['vessel_gp_id'] = (int) $gefaess->id;
        }
        // Eine Übersteuerung des Servier-Typs gibt es nur, wenn sie vom Typ des Gefäßes ABWEICHT: ein leerer oder gleicher Wert wird geleert,
        // sonst übersteuerte ein Bestandswert still für immer den Typ des Gefäßes (Etikett lügt).
        if (array_key_exists('vessel_gp_id', $update) || array_key_exists('serving_vehicle_vocab_id', $update)) {
            $gefaessId = array_key_exists('vessel_gp_id', $update) ? $update['vessel_gp_id'] : $darreichung->vessel_gp_id;
            if ($gefaessId !== null) {
                $typ = array_key_exists('serving_vehicle_vocab_id', $update) ? $update['serving_vehicle_vocab_id'] : $darreichung->serving_vehicle_vocab_id;
                $gefaessTyp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereKey($gefaessId)->value('serving_vehicle_vocab_id');
                if ($typ !== null && $gefaessTyp !== null && (int) $typ === (int) $gefaessTyp) {
                    $update['serving_vehicle_vocab_id'] = null;
                }
            }
        }
        $wirdFixiert = ($update['price_mode'] ?? $darreichung->price_mode) === 'fixed';
        if ($wirdFixiert) {
            $reason = trim((string) ($update['price_override_reason'] ?? $darreichung->price_override_reason ?? ''));
            if ($reason === '' && $legacyManual) {
                $reason = 'Legacy-Übernahme aus Preismodus manuell';
            }
            $fixedPrice = $update['sales_net'] ?? $darreichung->sales_net;
            if ($reason === '' || $fixedPrice === null) {
                throw new \RuntimeException('Ein fixierter Preis benötigt einen VK und eine Begründung.');
            }
            $update['price_override_reason'] = $reason;
            $update['price_override_user_id'] = Auth::id();
            $update['price_override_at'] = now();
        } elseif (($update['price_mode'] ?? null) === 'auto') {
            $update = array_merge($update, [
                'price_override_reason' => null,
                'price_override_user_id' => null,
                'price_override_at' => null,
                'price_override_expires_at' => null,
            ]);
        }
        // Vererbung abschalten hält die aktuelle Komposition als Mengen je Komponente fest (wie setzeStandardmenge). Plan 98 B:
        // immer, nicht nur bei vorhandenen Deltas — sonst bliebe eine freie Grammatur übrig.
        if ($darreichung->menge_vom_standard && ($update['menge_vom_standard'] ?? true) === false) {
            $this->fixiereKomposition($darreichung, $team);
        }
        $vorher = [$darreichung->serving_vehicle_vocab_id, $darreichung->vessel_gp_id];
        $darreichung->update($update);
        $darreichung->is_standard ? $this->recomputeFuerRezept($darreichung->recipe_id) : $this->recomputePreise($darreichung);
        $this->typInsGericht($darreichung, $vorher != [$darreichung->serving_vehicle_vocab_id, $darreichung->vessel_gp_id]);

        return $darreichung->refresh();
    }

    /**
     * Servierform einer bestehenden Darreichung wechseln (Entscheid Dominique 2026-09-04).
     *
     * `unbestimmt` ist ein REVIEW-ZUSTAND, kein Label. Steht fest, wie das Gericht
     * ausgegeben wird, wird die FORM der Zeile gesetzt — das Vokabular wird nie
     * umbenannt (es ist WaWi-Master und an allen Gerichten dasselbe). Darum ist
     * `serving_form_id` bewusst kein Eintrag in {@see self::FELDER}: der Formwechsel
     * ist ein eigener Übergang mit Kollisionsprüfung, kein Attribut-Update.
     *
     * Preisneutral: die Aufschlagsklasse bleibt eigenständig (Umbau-Spec F7, kein
     * Servierform→Aufschlag-Mapping), deshalb kein Recompute.
     */
    public function setzeServierform(Team $team, int $darreichungId, int $servierformId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = $this->find($team, $darreichungId);
        if ((int) $darreichung->serving_form_id === $servierformId) {
            return $darreichung;                                  // nichts zu tun
        }

        // Sichtbarkeit statt Eigentum: geerbte und globale Formen müssen am eigenen
        // Gericht wählbar bleiben (Master-Vererbung, wie TeamScope::referenz()).
        $form = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)
            ->whereKey($servierformId)->first();
        if ($form === null) {
            throw new \RuntimeException('Unbekannte Servierform.');
        }
        if ($form->is_inactive) {
            throw new \RuntimeException('Diese Servierform ist deaktiviert.');
        }
        $aenderung = ['serving_form_id' => $servierformId];
        // Explizite Sekunden dürfen beim Formwechsel nicht plötzlich Minuten je Platte bedeuten.
        if ($darreichung->arbeitszeiten === null && $darreichung->anrichten_wert !== null) {
            $aenderung['arbeitszeiten'] = array_map(fn ($z) => $z + ['quelle' => 'bestand'],
                app(DarreichungsZeitService::class)->formular($darreichung));
        }
        $darreichung->update($aenderung);

        return $darreichung->refresh();
    }

    public function loeschen(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        // Die Standard-Darreichung ist der Anker der Preis-Wahrheit und der Slot-Auflösung.
        // Sie darf nie verschwinden — vorher (2026-09-04) griff der Guard nur bei >1 Zeile,
        // die EINZIGE Darreichung war über den MCP-Pfad löschbar (die UI versteckt den
        // Knopf unabhängig von der Anzahl, deshalb fiel es nie auf).
        if ($darreichung->is_standard) {
            throw new \RuntimeException($darreichung->recipe->presentations()->count() > 1
                ? 'Standard-Darreichung zuerst auf eine andere Form übertragen.'
                : 'Die einzige Darreichung eines Gerichts kann nicht gelöscht werden — stattdessen die Servierform wechseln.');
        }
        $darreichung->delete();
    }

    public function setzeStandard(Team $team, int $darreichungId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        DB::transaction(function () use ($darreichung, $team) {
            $darreichung->recipe()->lockForUpdate()->first();
            $darreichung->refresh();
            if ($darreichung->is_standard) {
                return;
            }
            // Die gewählte Variante behält ihre wirksame Komposition und wird selbst zur Quelle.
            if ($darreichung->deltas()->exists() || ($darreichung->menge_vom_standard && $darreichung->recipe->standardPresentation?->deltas()->exists())) {
                $this->fixiereKomposition($darreichung, $team);
            }
            // Reihenfolge wichtig: partieller Unique-Index erlaubt nur EIN is_standard=1
            $darreichung->recipe->presentations()
                ->where('id', '!=', $darreichung->id)
                ->update(['is_standard' => false]);
            $darreichung->update(['is_standard' => true, 'menge_vom_standard' => false]);
            $this->recomputeFuerRezept($darreichung->recipe_id);
        });
        $this->typInsGericht($darreichung->refresh(), false);
    }

    /**
     * Wirksame Mengen festhalten, bevor eine Darreichung ihre Referenz wechselt. Öffentlich für die Grammatur-Umstellung
     * (Plan 98 B, Weg A): dieselbe Rechnung wie der UI-Schalter „Standardmenge verwenden“ — EK und Produktionsmengen bleiben.
     */
    public function fixiereKomposition(FoodAlchemistRecipeDarreichung $darreichung, Team $team): void
    {
        $anteil = $this->produktionsAnteil($darreichung->recipe, $darreichung, 1, $team);
        foreach ($this->recompute->zeilenKostenUndMassen($darreichung->recipe, $team) as $ingId => $z) {
            if ($z['ohne_gewicht'] ?? false) {
                continue;   // keine Gramm festzuhalten — ohne Delta läuft die Zeile im Verhältnis der Variante mit, statt als 0 g weggelassen zu werden
            }
            $m = ($anteil['je_zutat'][$ingId] ?? $anteil['ansaetze']) * $z['masse_g'] / max(1, (float) ($darreichung->unit_count ?: 1));
            FoodAlchemistRecipeDarreichungDelta::withTrashed()->updateOrCreate(
                ['presentation_id' => $darreichung->id, 'recipe_ingredient_id' => $ingId],
                ['team_id' => $darreichung->team_id, 'quantity_override_g' => $m, 'omitted' => $m <= 0, 'deleted_at' => null],
            );
        }
    }

    /** Expliziter UI-Wechsel: Quelle übernehmen oder die aktuelle Komposition zur eigenen machen. */
    public function setzeStandardmenge(Team $team, int $id, bool $verwenden): void
    {
        $dar = $this->find($team, $id);
        DB::transaction(function () use ($dar, $team, $verwenden) {
            $dar->recipe()->lockForUpdate()->first();
            $dar->refresh();
            if ($verwenden) {
                $dar->deltas()->delete();
                $dar->update(['menge_vom_standard' => true, 'komponenten_referenz_g' => null]);
            } else {
                $this->fixiereKomposition($dar, $team);
                $dar->update(['menge_vom_standard' => false]);
            }
            $this->recomputeFuerRezept($dar->recipe_id);
        });
    }

    /**
     * Plan 98 B, Schritt 5 — „alle Mengen auf … %“: jede Komponente der Variante bekommt δ_i = m_i_std × p, die Grammatur ergibt
     * sich daraus. Ersetzt die bisherigen Mengen der Variante; was der Standard weglässt, bleibt weggelassen. Weglassungen der
     * Variante selbst (Delta weggelassen oder auf 0 g) bleiben bestehen — sonst landete still wieder z. B. Speck in „ohne Speck“
     * (Kurator 23.11., Produktentscheid Dominique steht aus). Danach steht die Variante wie nach der Umstellung (Weg A): eigene
     * Komposition, Bezug auf die Standard-Komposition. Nicht für den Standard.
     */
    public function setzeAnteil(Team $team, int $darreichungId, float $prozent): FoodAlchemistRecipeDarreichung
    {
        if (! is_finite($prozent) || $prozent <= 0 || $prozent > 1000) {
            throw new \RuntimeException('Der Anteil muss zwischen 0 und 1000 % liegen (größer als 0).');
        }
        $dar = $this->find($team, $darreichungId);
        if ($dar->is_standard) {
            throw new \RuntimeException('Der Anteil bezieht sich auf die Standard-Darreichung — an ihr selbst nicht setzbar.');
        }
        DB::transaction(function () use ($dar, $team, $prozent) {
            $recipe = $dar->recipe()->lockForUpdate()->first();
            $standard = $this->standardProEinheit($recipe, $team);
            if ($standard === [] || array_sum(array_column($standard, 'masse_g')) <= 0) {
                throw new \RuntimeException('Die Standard-Darreichung hat keine Komponenten mit Menge — kein Anteil berechenbar.');
            }
            $weggelassen = $dar->deltas()->get()
                ->filter(fn ($d) => $d->omitted || ($d->quantity_override_g !== null && (float) $d->quantity_override_g <= 0))
                ->pluck('recipe_ingredient_id')->map(fn ($id) => (int) $id)->all();
            $dar->deltas()->withTrashed()->forceDelete();
            foreach ($standard as $ingId => $z) {
                if (($z['ohne_gewicht'] ?? false) && ! in_array((int) $ingId, $weggelassen, true)) {
                    continue;   // ohne Gramm: kein Delta, läuft im Anteil mit (sonst wäre sie als 0 g weggelassen)
                }
                $m = in_array((int) $ingId, $weggelassen, true) ? 0.0 : round($z['masse_g'] * $prozent / 100, 3);
                FoodAlchemistRecipeDarreichungDelta::create(['presentation_id' => $dar->id, 'recipe_ingredient_id' => $ingId,
                    'team_id' => $dar->team_id, 'quantity_override_g' => $m, 'omitted' => $m <= 0]);
            }
            $dar->update(['menge_vom_standard' => false, 'komponenten_referenz_g' => null]);
            $this->recomputePreise($dar->refresh());
        });

        return $dar->refresh();
    }

    /**
     * Servier-Typ an EINER Stelle (Entscheid Dominique 18.11.2026): Typ (neutral, Vokabular) und konkretes Gefäß (GP mit Preis) stehen an der
     * Darreichung; gelesen wird der wirksame Typ der Standard-Darreichung ({@see vehikelId}).
     * 98 · 3.5 6b: der Typ wird NICHT mehr ans Gericht gespiegelt (recipes.serving_vehicle_vocab_id entfällt mit 6c). Am Gericht bleibt nur die
     * Lineage (Entscheid Kurator): wer den Typ an der Standard-Darreichung ausdrücklich setzt oder leert, setzt die Quelle auf `manual` bzw. leer.
     */
    public function typInsGericht(FoodAlchemistRecipeDarreichung $d, bool $typGeaendert): void
    {
        if (! $d->is_standard || ! $typGeaendert) {
            return;
        }
        $typ = $this->vehikelId($d);
        DB::table('foodalchemist_recipes')->where('id', $d->recipe_id)->update([
            'serving_vehicle_source' => $typ === null ? null : 'manual',
            'serving_vehicle_ai_confidence' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * KI-Vorschlag „Vehikel“ an die Standard-Darreichung (Gericht-Modal): nur wenn dort kein konkretes Gefäß steht — das Gefäß bestimmt den Typ.
     * Lineage am Gericht bleibt ehrlich `ki`. Gibt false zurück, wenn ein Gefäß gesetzt ist.
     */
    public function typVorschlagUebernehmen(Team $team, int $recipeId, int $typId, float $konfidenz): bool
    {
        $standard = $this->ensureStandard($team, $recipeId, 'fa_ui');
        if ($standard === null || $standard->vessel_gp_id !== null) {
            return false;
        }
        $this->aktualisieren($team, $standard->id, ['serving_vehicle_vocab_id' => $typId]);
        DB::table('foodalchemist_recipes')->where('id', $recipeId)->update(['serving_vehicle_source' => 'ki', 'serving_vehicle_ai_confidence' => $konfidenz]);

        return true;
    }

    /** Delta setzen/ändern — nur Zutatenzeilen des eigenen Rezepts (E5 strukturell). */
    public function setzeDelta(Team $team, int $darreichungId, int $recipeIngredientId, ?float $mengeOverrideG, bool $omitted): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $gehoertZumRezept = $darreichung->recipe->ingredients()
            ->where('id', $recipeIngredientId)->exists();
        if (! $gehoertZumRezept) {
            throw new \RuntimeException('Zutat gehört nicht zum Kernrezept (E5: keine neuen Zutaten).');
        }
        if ($mengeOverrideG === null && ! $omitted) {
            $this->entferneDelta($team, $darreichungId, $recipeIngredientId);

            return;
        }
        if ($mengeOverrideG !== null && (! is_finite($mengeOverrideG) || $mengeOverrideG < 0)) {
            throw new \RuntimeException('Die Komponentenmenge muss eine gültige positive Grammzahl oder 0 sein.');
        }
        if (! $darreichung->menge_vom_standard && ! $darreichung->deltas()->exists()) {
            $darreichung->update(['komponenten_referenz_g' => $this->grammJeEinheit($darreichung, $darreichung->recipe)]);
        }
        FoodAlchemistRecipeDarreichungDelta::withTrashed()->updateOrCreate(
            ['presentation_id' => $darreichung->id, 'recipe_ingredient_id' => $recipeIngredientId],
            ['team_id' => $darreichung->team_id, 'quantity_override_g' => $mengeOverrideG,
                'omitted' => $omitted, 'deleted_at' => null],
        );
        $darreichung->is_standard ? $this->recomputeFuerRezept($darreichung->recipe_id) : $this->recomputePreise($darreichung);
    }

    public function entferneDelta(Team $team, int $darreichungId, int $recipeIngredientId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $darreichung->deltas()->where('recipe_ingredient_id', $recipeIngredientId)->forceDelete();
        if (! $darreichung->menge_vom_standard && ! $darreichung->deltas()->exists() && $darreichung->komponenten_referenz_g !== null) {
            $darreichung->update(['quantity_per_unit_g' => $darreichung->komponenten_referenz_g, 'komponenten_referenz_g' => null]);
        }
        $darreichung->is_standard ? $this->recomputeFuerRezept($darreichung->recipe_id) : $this->recomputePreise($darreichung);
    }

    /**
     * Zubehör & Verpackung setzen/ändern (ein Non-Food-GP je Form). Nur Grundprodukte der Warengruppe 16: Lebensmittel gehören in die
     * Zutaten (Gegenstück zum Zutaten-Guard). Wirkt nur auf den EK der Darreichung, nie auf Allergene, Nährwerte, Profil oder Yield.
     */
    public function setzeZubehoer(Team $team, int $darreichungId, int $gpId, float $menge, ?string $note = null): FoodAlchemistRecipeDarreichungZubehoer
    {
        $darreichung = $this->find($team, $darreichungId);
        if ($menge <= 0) {
            throw new \RuntimeException('Die Menge muss größer als 0 sein.');
        }
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht sichtbar/vorhanden.');
        }
        if (! $gp->istNonFood()) {
            throw new \RuntimeException('Zubehör & Verpackung nimmt nur Non-Food-Grundprodukte (Warengruppe 16). Lebensmittel gehören in die Zutaten.');
        }
        $zeile = FoodAlchemistRecipeDarreichungZubehoer::withTrashed()->updateOrCreate(
            ['presentation_id' => $darreichung->id, 'gp_id' => $gp->id],
            ['team_id' => $darreichung->team_id, 'quantity' => $menge, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'deleted_at' => null],
        );
        if ($zeile->wasRecentlyCreated || $zeile->position === 0) {
            $zeile->update(['position' => (int) $darreichung->zubehoer()->max('position') + 1]);
        }
        $this->recomputePreise($darreichung);

        return $zeile;
    }

    public function entferneZubehoer(Team $team, int $darreichungId, int $gpId): void
    {
        $darreichung = $this->find($team, $darreichungId);
        $darreichung->zubehoer()->where('gp_id', $gpId)->forceDelete();
        $this->recomputePreise($darreichung);
    }

    /**
     * Kosten des Zubehörs je Einheit der Darreichung (Lead-LA-Preis je Stück wie überall).
     *
     * @return array{summe: float, vollstaendig: bool, zeilen: list<array{gp_id:int, name:string, menge:float, preis_stk:?float, kosten:?float, note:?string}>}
     */
    public function zubehoerKosten(FoodAlchemistRecipeDarreichung $darreichung, ?Team $team = null): array
    {
        $team ??= Team::find($darreichung->team_id);
        $summe = 0.0;
        $vollstaendig = true;
        $zeilen = [];
        // Zubehör am Gefäß-GP (20.11.2026) zählt beim Gefäß — steht dasselbe Teil zusätzlich hier, nicht doppelt rechnen
        $amGefaess = $darreichung->vessel_gp_id !== null
            ? array_flip(array_column(app(\Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService::class)->liste((int) $darreichung->vessel_gp_id, $team)['zeilen'], 'gp_id')) : [];
        $liste = $darreichung->relationLoaded('zubehoer') ? $darreichung->zubehoer   // vorgeladen (Concepter-Aufbau, 2.1e)
            : $darreichung->zubehoer()->with('gp:id,name,team_id,commodity_group_code,lead_la_supplier_item_id,piece_default_g')->get();
        foreach ($liste as $z) {
            // Doppelzählung vermeiden: dasselbe GP als Gefäß oder als Zubehör am Gefäß zählt dort (Gefäß gewinnt). Miete (Lieferant der Gruppe VERLEIH): nur Hinweis, nie je Portion.
            $doppelt = ($darreichung->vessel_gp_id !== null && (int) $darreichung->vessel_gp_id === (int) $z->gp_id) || isset($amGefaess[(int) $z->gp_id]);
            $miete = ! $doppelt && $z->gp !== null && $this->istVerleihGp($z->gp, $team);
            if ($doppelt || $miete) {
                $zeilen[] = ['gp_id' => (int) $z->gp_id, 'name' => (string) $z->gp?->name, 'menge' => (float) $z->quantity, 'preis_stk' => null, 'kosten' => null, 'note' => $z->note, 'doppelt' => $doppelt, 'miete' => $miete];

                continue;
            }
            $preis = $z->gp !== null ? $this->recompute->preisProStueckPublic($z->gp, $team) : null;
            $kosten = $preis !== null ? round($preis * (float) $z->quantity, 4) : null;
            if ($kosten === null) {
                $vollstaendig = false;
            } else {
                $summe += $kosten;
            }
            $zeilen[] = ['gp_id' => (int) $z->gp_id, 'name' => (string) $z->gp?->name, 'menge' => (float) $z->quantity, 'preis_stk' => $preis, 'kosten' => $kosten, 'note' => $z->note, 'doppelt' => false, 'miete' => false];
        }

        return ['summe' => round($summe, 4), 'vollstaendig' => $vollstaendig, 'zeilen' => $zeilen];
    }

    /**
     * Wirksamer Servier-Typ der Darreichung — EINE Lese-Stelle: die ausdrückliche Übersteuerung an der Darreichung, sonst der Typ des Gefäß-GP.
     * Kein Kopieren, kein Auseinanderlaufen: ändert sich der Typ am GP, ziehen alle Darreichungen mit.
     */
    public function vehikelId(FoodAlchemistRecipeDarreichung $d): ?int
    {
        if ($d->serving_vehicle_vocab_id !== null) {
            return (int) $d->serving_vehicle_vocab_id;
        }
        $typ = $d->vessel_gp_id !== null ? ($d->relationLoaded('vessel') ? $d->vessel?->serving_vehicle_vocab_id : \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereKey($d->vessel_gp_id)->value('serving_vehicle_vocab_id')) : null;

        return $typ !== null ? (int) $typ : null;
    }

    /** Hat die Darreichung einen Servier-Typ, der vom Typ ihres Gefäßes abweicht (ausdrückliche Übersteuerung)? */
    public function uebersteuert(FoodAlchemistRecipeDarreichung $d): bool
    {
        if ($d->serving_vehicle_vocab_id === null || $d->vessel_gp_id === null) {
            return false;
        }
        $gefaessTyp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereKey($d->vessel_gp_id)->value('serving_vehicle_vocab_id');

        return $gefaessTyp !== null && (int) $gefaessTyp !== (int) $d->serving_vehicle_vocab_id;
    }

    /**
     * Kommt der Lead-Artikel des GP von einem Lieferanten der Gruppe VERLEIH (Miete je Tag/Veranstaltung)? Dann zählt er nicht je Portion.
     * Mit Team: der effektive Lead dieses Teams wie im EK (Pin, inaktiver Lieferant, fürs Team nicht sichtbar — Lead-Sicht 29.10.2026).
     */
    public function istVerleihGp(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, ?Team $team = null): bool
    {
        $leadId = $team !== null ? $this->recompute->effektiverLeadFuer($gp, $team) : $gp->lead_la_supplier_item_id;
        if ($leadId === null || ! \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_supplier_groups')) {
            return false;
        }

        return DB::table('foodalchemist_supplier_items as i')
            ->join('foodalchemist_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->join('foodalchemist_supplier_groups as g', 'g.id', '=', 's.group_id')
            ->where('i.id', $leadId)->where('g.code', \Platform\FoodAlchemist\Support\LieferantenGruppen::VERLEIH)->exists();
    }

    /**
     * Gefäß der Darreichung als Kostenposten (1 Stück je Einheit) nach Art und Preis ({@see GefaessKostenartService}):
     * einweg/extern = Preis des Teams am GP, sonst Lead-Preis je Stück · mehrweg = nur der Preis des Teams am GP. Fehlt der Preis: `preis_fehlt`,
     * die Darreichung gilt als unvollständig — auch beim Verleih-Gefäß ohne Team-Eintrag (Entscheid Dominique 17.11.2026: „einfach einen Preis an
     * den Artikel setzen“; der frühere stille Miet-Hinweis „nicht je Portion gerechnet“ entfällt, Befund Achenbachglas 18.11.).
     *
     * Seit 20.11.2026 kommt das Zubehör am Gefäß-GP dazu (`zubehoer`, je Teil mit eigener Art und eigenem Preis); `kosten`/`preis_fehlt` bleiben
     * das Gefäß allein, `gesamt` = Gefäß + Zubehör je Einheit.
     *
     * @return array{gp:?\Platform\FoodAlchemist\Models\FoodAlchemistGp, kosten:?float, miete:bool, preis_fehlt:bool, kostenart:?string, kostenart_quelle:?string, kostenart_vorgabe:?string, preis_quelle:?string, zubehoer:list<array<string,mixed>>, zubehoer_summe:float, zubehoer_preis_fehlt:bool, gesamt:?float}
     */
    public function gefaessKosten(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        $gp = $d->vessel_gp_id !== null ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->find($d->vessel_gp_id) : null;
        $info = ['gp' => $gp, 'kosten' => null, 'miete' => false, 'preis_fehlt' => false, 'kostenart' => null, 'kostenart_quelle' => null,
            'kostenart_vorgabe' => null, 'preis_quelle' => null, 'zubehoer' => [], 'zubehoer_summe' => 0.0, 'zubehoer_preis_fehlt' => false, 'gesamt' => null];
        if ($gp === null) {
            return $info;
        }
        $team ??= Team::find($d->team_id);
        $st = $this->stueckKosten($gp, $team, $d->vessel_cost_type);
        $zub = $this->gefaessZubehoerKosten($gp, $team);

        return [...$info, 'kostenart' => $st['kostenart'], 'kostenart_quelle' => $st['kostenart_quelle'], 'kostenart_vorgabe' => $st['kostenart_vorgabe'],
            'miete' => $st['kostenart'] === \Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::EXTERN, 'preis_quelle' => $st['preis_quelle'],
            'kosten' => $st['preis'], 'preis_fehlt' => $st['preis'] === null,
            'zubehoer' => $zub['zeilen'], 'zubehoer_summe' => $zub['summe'], 'zubehoer_preis_fehlt' => $zub['preis_fehlt'],
            // Gefäß + Zubehör je Einheit (nur bekannte Preise; fehlt einer, sagen es preis_fehlt / zubehoer_preis_fehlt)
            'gesamt' => round(($st['preis'] ?? 0.0) + $zub['summe'], 4)];
    }

    /**
     * Preis je Stück eines Non-Food-GP nach seiner wirksamen Art ({@see GefaessKostenartService}) — für das Gefäß und sein Zubehör gleich:
     * einweg/extern = Preis des Teams am GP, sonst Lead-Preis je Stück · mehrweg = nur der Preis des Teams am GP. null = Preis fehlt.
     *
     * @return array{kostenart: string, kostenart_quelle: string, kostenart_vorgabe: string, preis: ?float, preis_quelle: ?string}
     */
    public function stueckKosten(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, ?Team $team, ?string $uebersteuerung = null): array
    {
        $art = app(\Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::class)->wirksamArt($uebersteuerung, (int) $gp->id, $team, fn () => $this->istVerleihGp($gp, $team));
        $preis = $art['preis_gp'];
        $quelle = $preis !== null ? 'gp' : null;
        if ($preis === null && $art['kostenart'] !== \Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService::MEHRWEG) {
            $preis = $this->recompute->preisProStueckPublic($gp, $team);   // einweg UND extern: Lead-Artikelpreis je Stück (Mehrweg kauft nichts ein)
            $quelle = $preis !== null ? 'artikel' : null;
        }

        return ['kostenart' => $art['kostenart'], 'kostenart_quelle' => $art['quelle'], 'kostenart_vorgabe' => $art['vorgabe'],
            'preis' => $preis !== null ? round($preis, 4) : null, 'preis_quelle' => $quelle];
    }

    /**
     * Zubehör am Gefäß-GP (20.11.2026, {@see GefaessZubehoerService}) je Gefäß: jedes Teil × Menge zu seinem Preis und seiner Kostenart.
     * Eine Ebene (Zubehör des Zubehörs zählt nie). Fehlt ein Preis: Zeile mit kosten null, `preis_fehlt` — nie still 0.
     *
     * @return array{summe: float, preis_fehlt: bool, zeilen: list<array{gp_id:int, name:string, menge:float, kostenart:string, preis_stk:?float, preis_quelle:?string, kosten:?float}>}
     */
    public function gefaessZubehoerKosten(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gefaess, ?Team $team): array
    {
        $liste = app(\Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService::class)->liste((int) $gefaess->id, $team);
        if ($liste['zeilen'] === []) {
            return ['summe' => 0.0, 'preis_fehlt' => false, 'zeilen' => []];
        }
        $gps = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->whereIn('id', array_column($liste['zeilen'], 'gp_id'))->get()->keyBy('id');
        $summe = 0.0;
        $fehlt = false;
        $zeilen = [];
        foreach ($liste['zeilen'] as $z) {
            $gp = $gps->get($z['gp_id']);
            $st = $gp !== null ? $this->stueckKosten($gp, $team) : ['kostenart' => null, 'preis' => null, 'preis_quelle' => null];
            $kosten = $st['preis'] !== null ? round($st['preis'] * $z['menge'], 4) : null;
            if ($kosten === null) {
                $fehlt = true;
            } else {
                $summe += $kosten;
            }
            $zeilen[] = ['gp_id' => $z['gp_id'], 'name' => $gp?->name ?? $z['name'], 'menge' => $z['menge'], 'kostenart' => $st['kostenart'],
                'preis_stk' => $st['preis'], 'preis_quelle' => $st['preis_quelle'], 'kosten' => $kosten];
        }

        return ['summe' => round($summe, 4), 'preis_fehlt' => $fehlt, 'zeilen' => $zeilen];
    }

    /**
     * Logistik (20.11.2026): was zur Darreichung mitgeht — das Gefäß (1 je Einheit) und sein Zubehör (Menge je Gefäß), hochgerechnet auf
     * `$portionen`. Gleiche Quelle wie die Kosten ({@see GefaessZubehoerService::liste}, wirksam fürs Team), keine zweite Logik.
     *
     * @return list<array{gp_id:int, name:string, menge:float, rolle:string}>  rolle = gefaess | zubehoer
     */
    public function gefaessMitgeben(FoodAlchemistRecipeDarreichung $d, float $portionen, ?Team $team = null): array
    {
        if ($d->vessel_gp_id === null || $portionen <= 0) {
            return [];
        }
        $team ??= Team::find($d->team_id);
        $einheiten = $portionen * (float) ($d->unit_count ?: 1);
        $name = (string) DB::table('foodalchemist_gps')->where('id', $d->vessel_gp_id)->value('name');
        $out = [['gp_id' => (int) $d->vessel_gp_id, 'name' => $name, 'menge' => round($einheiten, 3), 'rolle' => 'gefaess']];
        foreach (app(\Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService::class)->liste((int) $d->vessel_gp_id, $team)['zeilen'] as $z) {
            $out[] = ['gp_id' => $z['gp_id'], 'name' => $z['name'], 'menge' => round($einheiten * $z['menge'], 3), 'rolle' => 'zubehoer'];
        }

        return $out;
    }

    /**
     * Zubehör je Portion für Küche/Service (Handbuch, ohne Preise): Gefäß + Zubehör einer Darreichung als Text-Zeilen „1 × Schraubglas …“.
     *
     * @return list<string>
     */
    public function gefaessZeilenJePortion(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        return array_map(fn ($t) => self::stueckText($t['menge']).' × '.$t['name'], $this->gefaessMitgeben($d, 1.0, $team));
    }

    /** Stückzahl für Logistik-Texte: ganze Stück aufgerundet (nie 119,5 Deckel), Bruchteile je Portion mit Komma. */
    public static function stueckText(float $menge, bool $aufrunden = false): string
    {
        if ($aufrunden) {
            return number_format((float) ceil(round($menge, 3)), 0, ',', '.');
        }

        return rtrim(rtrim(number_format($menge, 3, ',', '.'), '0'), ',');
    }

    /**
     * Alles, was auf den EK der Darreichung kommt, ohne die Zutaten: Zubehör & Verpackung plus Gefäß samt seinem Zubehör (je Einheit).
     *
     * @return array{summe: float, vollstaendig: bool, zubehoer: array<string,mixed>, gefaess: array<string,mixed>}
     */
    public function beigabenKosten(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        $zub = $this->zubehoerKosten($d, $team);
        $gef = $this->gefaessKosten($d, $team);

        return ['summe' => round($zub['summe'] + ($gef['kosten'] ?? 0.0) + $gef['zubehoer_summe'], 4),
            'vollstaendig' => $zub['vollstaendig'] && ! $gef['preis_fehlt'] && ! $gef['zubehoer_preis_fehlt'], 'zubehoer' => $zub, 'gefaess' => $gef];
    }

    /**
     * Anzeige-Info für Gefäß, Typ und Hinweise (VK-Modal, Concepter „Anrichten“, Paket) — berechnet, nichts gespeichert.
     *
     * @return array<string,mixed> `leer` = weder Gefäß noch Typ
     */
    public function gefaessInfo(FoodAlchemistRecipeDarreichung $d, ?Team $team = null): array
    {
        $typId = $this->vehikelId($d);
        $name = fn (?int $id) => $id !== null ? (string) DB::table('foodalchemist_vocab_serving_vehicles')->where('id', $id)->value('name') : null;
        $gef = $this->gefaessKosten($d, $team);
        $gp = $gef['gp'];
        $warnung = null;
        $gramm = (float) ($d->quantity_per_unit_g ?? 0);
        $volumen = $gp?->volume_ml !== null ? (float) $gp->volume_ml : null;
        if ($gp !== null && $volumen !== null && $volumen > 0 && $gramm > $volumen) {
            $warnung = 'Füllmenge '.rtrim(rtrim(number_format($gramm, 1, ',', '.'), '0'), ',').' g passt nicht in '.\Platform\FoodAlchemist\Support\NonFoodMasse::volumen($volumen).' (Volumen des Gefäßes).';
        }
        $gefaessTypId = $gp?->serving_vehicle_vocab_id !== null ? (int) $gp->serving_vehicle_vocab_id : null;

        return [
            'leer' => $d->vessel_gp_id === null && $typId === null,
            'gefaess_id' => $gp?->id, 'gefaess_name' => $gp?->name, 'typ_id' => $typId, 'typ_name' => $name($typId),
            'uebersteuert' => $this->uebersteuert($d), 'gefaess_typ_name' => $name($gefaessTypId),
            'warnung' => $warnung, 'miete' => $gef['miete'], 'kosten' => $gef['kosten'], 'preis_fehlt' => $gef['preis_fehlt'],
            'kostenart' => $gef['kostenart'], 'kostenart_quelle' => $gef['kostenart_quelle'], 'kostenart_vorgabe' => $gef['kostenart_vorgabe'],
            'preis_quelle' => $gef['preis_quelle'],
            'zubehoer' => $gef['zubehoer'], 'zubehoer_summe' => $gef['zubehoer_summe'], 'zubehoer_preis_fehlt' => $gef['zubehoer_preis_fehlt'], 'gesamt' => $gef['gesamt'],
        ];
    }

    /** EK/VK einer Darreichung neu rechnen (Stufe 1 + Stufe-2-Deltas). */
    public function recomputePreise(FoodAlchemistRecipeDarreichung $darreichung, ?FoodAlchemistRecipe $recipe = null): void
    {
        $recipe ??= $darreichung->recipe()->with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->first();
        $team = Team::findOrFail($darreichung->team_id);
        $deltas = $darreichung->deltas()->get();

        if ($deltas->isEmpty() && ! $darreichung->menge_vom_standard) {
            // Stufe 1: proportional — EK/g des Rezepts × Grammatur × Anzahl
            $ekProG = Masse::preisJeG($recipe->ek_per_kg_eur);
            $grammatur = $this->grammJeEinheit($darreichung, $recipe) ?? 0.0;
            $ekPortion = ($ekProG !== null && $grammatur > 0)
                ? round($ekProG * $grammatur
                    * (float) ($darreichung->unit_count ?: 1), 4)
                : null;
        } else {
            // Stufe 2: Overrides sind ECHTE Gramm je Einheit dieser Form (User-Entscheid
            // 2026-07-03) — die Grammatur der Form ergibt sich aus der Komponenten-Summe,
            // der EK direkt aus Σ (Preis/g × Gramm). Kein Verhältnis-Umweg mehr.
            ['pro_einheit' => $proEinheit, 'kosten' => $kosten, 'masse' => $masse, 'n_bepreist' => $nBepreist]
                = $this->komponentenSumme($darreichung, $recipe, $team, $deltas);
            $masseBekannt = true;
            if ($proEinheit === [] && $deltas->isEmpty() && $darreichung->menge_vom_standard) {
                $referenzMasse = $this->grammJeEinheit($darreichung, $recipe);
                $masseBekannt = $referenzMasse !== null;
                $masse = $referenzMasse ?? 0;
            }
            // Auto-Grammatur: g/Einheit = Summe der Komponenten dieser Form — bei festem Ertrag gegart (Dominique 23.11.: „Gegart
            // (Ertrag)“). Kosten und Produktion rechnen weiter auf den rohen Mengen; EK je kg bezieht sich auf den Ertrag.
            $masse = $proEinheit === [] ? $masse : $masse * $this->garfaktor($recipe, null, $team);
            $darreichung->quantity_per_unit_g = $masseBekannt ? round($masse, 1) : null;
            $ekPortion = $nBepreist > 0
                ? round($kosten * (float) ($darreichung->unit_count ?: 1), 4)
                : ($recipe->ek_per_kg_eur !== null && $masse > 0
                    ? round(Masse::preisJeG($recipe->ek_per_kg_eur) * $masse
                        * (float) ($darreichung->unit_count ?: 1), 4)
                    : null);
        }

        // Zubehör & Verpackung (Non-Food, je Einheit) kommt auf den EK der Darreichung — NUR dorthin. Ist der Lebensmittel-EK unbepreist,
        // bleibt ek_portion null: das Zubehör allein täuscht keinen Preis vor.
        if ($ekPortion !== null && ($darreichung->vessel_gp_id !== null || $darreichung->zubehoer()->exists())) {
            $ekPortion = round($ekPortion + $this->beigabenKosten($darreichung, $team)['summe'] * (float) ($darreichung->unit_count ?: 1), 4);
        }

        $oldCalculated = $darreichung->calculated_sales_net;
        $oldEffective = $darreichung->sales_net;
        $darreichung->forceFill(['quantity_per_unit_g' => $darreichung->quantity_per_unit_g, 'ek_portion' => $ekPortion]);
        $price = $this->catalogPricing->catalogPrice($team, $darreichung);
        if ($price['price_mode'] === 'auto'
            && $this->chargenHinweis($recipe, $darreichung->is_standard ? $darreichung : null) !== null) {
            // Plan 98 B (h): Mengen wirken wie eine Charge — kein Auto-VK (ein bewusst fixierter VK bleibt)
            $price = ['calculated_sales_net' => null, 'sales_net' => null, 'sales_gross' => null] + $price;
        }
        $darreichung->update([
            'quantity_per_unit_g' => $darreichung->quantity_per_unit_g,
            'ek_portion' => $ekPortion,
            'price_mode' => $price['price_mode'],
            'calculated_sales_net' => $price['calculated_sales_net'],
            'sales_net' => $price['sales_net'],
            'sales_gross' => $price['sales_gross'],
            // vat_profile_key bleibt die ausdrückliche Wahl (leer = aus Preisklasse) — der aufgelöste Schlüssel steckt nur in sales_gross.
            // Vorher schrieb der Recompute den aufgelösten Schlüssel zurück: „aus Preisklasse“ sprang nach dem Speichern auf „ermäßigt“ (18.11.2026).
            'price_calculation_source' => $price['base_source'],
            'price_calculation_version' => $price['calculation_version'],
            'price_calculated_at' => now(),
            ...($price['override_expired'] ? [
                'price_override_reason' => null,
                'price_override_user_id' => null,
                'price_override_at' => null,
                'price_override_expires_at' => null,
            ] : []),
        ]);

        if ($oldCalculated !== $darreichung->calculated_sales_net || $oldEffective !== $darreichung->sales_net) {
            FoodAlchemistPriceChangeAudit::create([
                'team_id' => $darreichung->team_id,
                'entity_type' => 'presentation',
                'entity_id' => $darreichung->id,
                'old_calculated_net' => $oldCalculated,
                'new_calculated_net' => $darreichung->calculated_sales_net,
                'old_effective_net' => $oldEffective,
                'new_effective_net' => $darreichung->sales_net,
                'price_mode' => $darreichung->price_mode,
                'source' => $price['base_source'],
                'reason' => $darreichung->price_override_reason,
                'user_id' => $darreichung->price_override_user_id,
                'metadata' => [
                    'base_factor' => $price['base_factor'],
                    'class_factor_pct' => $price['class_factor_pct'],
                    'calculation_version' => $price['calculation_version'],
                ],
            ]);
        }
    }

    /**
     * Komponenten einer Darreichung mit Mengen-Rezept (Deltas oder „wie Standard“): Σ Kosten und Σ rohe Masse je Einheit, aus den
     * Zeilen des übergebenen Teams (Preise, Pins und Sub-Kosten dieses Teams). Overrides sind echte Gramm, weggelassen zählt nicht.
     * Eine Rechnung für den Besitzer ({@see recomputePreise}) und den Abo-Weg ({@see TeamVkService}).
     *
     * @return array{pro_einheit: array<int, array{masse_g: float, kosten_pro_g: ?float}>, kosten: float, masse: float, n_bepreist: int, fehlende: list<int>}
     */
    public function komponentenSumme(FoodAlchemistRecipeDarreichung $darreichung, FoodAlchemistRecipe $recipe, Team $team, ?\Illuminate\Support\Collection $deltas = null): array
    {
        $proEinheit = $this->standardProEinheit($recipe, $team, (int) $darreichung->id);
        $deltaMap = ($deltas ?? $darreichung->deltas()->get())->keyBy('recipe_ingredient_id');
        $kosten = 0.0;
        $masse = 0.0;
        $nBepreist = 0;
        $fehlende = [];
        $skala = $this->skalaOhneGewicht($proEinheit, $deltaMap);
        $ohneGewicht = [];
        foreach ($proEinheit as $ingId => $zeile) {
            $delta = $deltaMap->get($ingId);
            if ($delta !== null && $delta->omitted) {
                continue;
            }
            if ($zeile['ohne_gewicht'] ?? false) {
                // Trockenlauf io 23.11. (R149): Kosten im Anteil der Darreichung, nicht 0. Unbepreist → fehlende (kein stiller Cent)
                $ohneGewicht[] = (int) $ingId;
                if ($zeile['kosten_einheit'] !== null) {
                    $nBepreist++;
                    $kosten += $zeile['kosten_einheit'] * $skala;
                } else {
                    $fehlende[] = (int) $ingId;
                }

                continue;
            }
            $m = $delta?->quantity_override_g !== null ? (float) $delta->quantity_override_g : $zeile['masse_g'];
            if ($zeile['kosten_pro_g'] !== null) {
                $nBepreist++;
                $kosten += $zeile['kosten_pro_g'] * $m;
            } elseif ($m > 0) {
                $fehlende[] = (int) $ingId;
            }
            $masse += $m;
        }

        // vorläufig: Zeilen ohne Gewicht in einer Variante mit eigenen Mengen laufen mit dem mittleren Verhältnis der Variante mit
        // (genau bei proportionalen Varianten, sonst eine Schätzung)
        return ['pro_einheit' => $proEinheit, 'kosten' => $kosten, 'masse' => $masse, 'n_bepreist' => $nBepreist, 'fehlende' => $fehlende,
            'ohne_gewicht' => $ohneGewicht, 'vorlaeufig' => $ohneGewicht !== [] && $deltaMap->isNotEmpty()];
    }

    /**
     * Verhältnis einer Darreichung mit eigenen Mengen (Deltas) zum Standard, über die Zeilen MIT Gewicht: Σ wirksame Gramm ÷ Σ Standard-Gramm.
     * Damit laufen Zeilen ohne Gewicht (Stück ohne Stückgewicht …) im selben Maß mit. Ohne Deltas 1.
     *
     * @param  array<int, array{masse_g: float, ohne_gewicht?: bool}>  $proEinheit
     */
    private function skalaOhneGewicht(array $proEinheit, \Illuminate\Support\Collection $deltaMap): float
    {
        if ($deltaMap->isEmpty()) {
            return 1.0;
        }
        $std = 0.0;
        $var = 0.0;
        foreach ($proEinheit as $ingId => $z) {
            if ($z['ohne_gewicht'] ?? false) {
                continue;
            }
            $d = $deltaMap->get($ingId);
            $std += $z['masse_g'];
            $var += $d?->omitted ? 0.0 : ($d?->quantity_override_g !== null ? (float) $d->quantity_override_g : $z['masse_g']);
        }

        return $std > 0 ? $var / $std : 1.0;
    }

    /**
     * Preis-Anstoß für Gefäß und Zubehör: ändert sich der (Lead-)Preis eines Non-Food-GP, rechnen NUR die Darreichungen neu, die es als Gefäß oder
     * Zubehör führen — gezielt über die Indizes auf gp_id/vessel_gp_id, kein Massen-Recompute — und danach Pakete, Konzepte und offene Auto-Angebote
     * mit diesen Gerichten (Paket-EK liest ek_portion; vorher blieb er nach einer Gefäß-Preisänderung stehen, Befund 18.11.2026).
     *
     * @param  list<int>  $gpIds
     * @param  list<int>|null  $teamIds  nur Darreichungen dieser Teams (null = alle)
     * @param  list<int>  $ohneRezepte  Gerichte, deren Darreichungen schon frisch gerechnet sind (Rezept-Recompute lief davor)
     * @return int Anzahl neu gerechneter Darreichungen
     */
    public function recomputeFuerZubehoerGps(array $gpIds, ?array $teamIds = null, array $ohneRezepte = []): int
    {
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        if ($gpIds === []) {
            return 0;
        }
        // Zubehör am Gefäß (20.11.2026): ändert sich Preis oder Art des Deckels, rechnen die Darreichungen mit dem Glas neu
        $gpIds = array_values(array_unique([...$gpIds, ...app(\Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService::class)->gefaesseMitZubehoer($gpIds)]));
        $ids = collect();
        foreach (array_chunk($gpIds, 500) as $teil) {
            $ids = $ids->merge(DB::table('foodalchemist_recipe_presentation_zubehoer')->whereIn('gp_id', $teil)->whereNull('deleted_at')->distinct()->pluck('presentation_id'))
                ->merge(DB::table('foodalchemist_recipe_presentations')->whereIn('vessel_gp_id', $teil)->whereNull('deleted_at')->pluck('id'));
        }
        $ids = $ids->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }
        $ohne = array_flip(array_map('intval', $ohneRezepte));
        $n = 0;
        $rezepte = [];
        foreach ($ids->chunk(500) as $teil) {
            $q = FoodAlchemistRecipeDarreichung::whereIn('id', $teil->all());
            if ($teamIds !== null) {
                $q->whereIn('team_id', $teamIds);
            }
            foreach ($q->get() as $d) {
                if (isset($ohne[(int) $d->recipe_id])) {
                    continue;
                }
                \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm((int) $d->team_id, fn () => $this->recomputePreise($d));   // in der Sicht des Darreichungs-Teams wie die Rezept-Pipeline
                $rezepte[(int) $d->recipe_id] = true;
                $n++;
            }
        }
        if ($rezepte !== []) {
            try {
                \Platform\FoodAlchemist\Events\RezeptKostenGeaendert::dispatch(array_keys($rezepte));   // Paket-/Konzept-EK + offene Auto-Angebote (CQ 3.4: Event → PricingCascadeService)
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Preis-Kaskade nach Gefäß/Zubehör-Recompute fehlgeschlagen: '.$e->getMessage());
            }
        }

        return $n;
    }

    /** Wie {@see recomputeFuerZubehoerGps}, ausgehend von einem Lieferantenartikel (Preiszeile geändert): die GPs kommen aus der Struktur. */
    public function nachLaPreisAenderung(int $supplierItemId): int
    {
        $gpIds = DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $supplierItemId)->whereNull('deleted_at')
            ->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->all();

        return $this->recomputeFuerZubehoerGps($gpIds);
    }

    /** Alle Darreichungen eines Gerichts neu rechnen (nach Zutaten-/EK-Änderung). */
    public function recomputeFuerRezept(int $recipeId): void
    {
        $darreichungen = FoodAlchemistRecipeDarreichung::where('recipe_id', $recipeId)->orderByDesc('is_standard')->orderBy('id')->get();
        if ($darreichungen->isEmpty()) {
            return;
        }
        // Rezept + Zutaten-Relationen EINMAL laden statt je Darreichung neu (N+1).
        $recipe = FoodAlchemistRecipe::with('ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe')->find($recipeId);
        foreach ($darreichungen as $d) {
            $this->recomputePreise($d, $recipe);
        }
    }

    /**
     * Grammatur EINER Einheit dieser Darreichung (ohne Deltas) — eine Regel für Preis UND
     * Produktion: eingetragene Grammatur; fehlt sie an der STANDARD-Form, der Ertrag (ein Gericht = eine Portion,
     * Entscheid Dominique; 98 · 3.5 6b ohne Rezept-Spiegel und ohne Teiler). Andere Formen ohne Grammatur: null.
     */
    public function grammJeEinheit(FoodAlchemistRecipeDarreichung $darreichung, FoodAlchemistRecipe $recipe): ?float
    {
        if ($darreichung->is_standard && $darreichung->menge_vom_standard && ! $darreichung->deltas()->exists()) {
            $zeilen = $this->recompute->zeilenKostenUndMassen($recipe, Team::findOrFail($darreichung->team_id));
            if ($zeilen !== []) {
                // Plan 98 B (Grammatur immer automatisch): Summe der Bestandteile, bei festem Ertrag gegart (Dominique 23.11.);
                // Gericht = eine Portion (98 · 3.5: Teiler fest 1)
                return array_sum(array_column($zeilen, 'masse_g')) * $this->garfaktor($recipe, $zeilen);
            }
            // Ohne Komponenten kein Rückfall auf die zuvor zwischengespeicherte Portionssumme.
            return $recipe->yield_kg !== null ? (float) $recipe->yield_kg * 1000 : null;
        }
        if (! $darreichung->is_standard && $darreichung->menge_vom_standard && ! $darreichung->deltas()->exists()) {
            $standard = $recipe->standardPresentation()->first();
            return $standard !== null ? $this->grammJeEinheit($standard, $recipe) : null;
        }
        $grammatur = (float) ($darreichung->quantity_per_unit_g ?? 0);
        if ($grammatur > 0 || ($darreichung->quantity_per_unit_g !== null && $darreichung->deltas()->exists())) {
            // Eine auf 0 reduzierte Komponenten-Summe ist bekannt, kein fehlender Altwert.
            return $grammatur;
        }
        if (! $darreichung->is_standard) {
            return null;
        }
        if ($recipe->yield_kg !== null && (float) $recipe->yield_kg > 0) {
            return (float) $recipe->yield_kg * 1000;
        }

        return null;
    }

    /**
     * Produktionsmenge für N verkaufte Portionen eines Gerichts in einer Darreichung —
     * gerechnet wie der Portions-EK der Darreichung (recomputePreise), nur mit Menge statt
     * Preis: Grammatur × Anzahl je Portion bzw. bei Deltas die Gramm je Komponente
     * (weggelassen = 0, Override = echte Gramm, Rest = Standard-Komposition je Einheit).
     *
     * Ergebnis in Rezept-Ansätzen (Explosion skaliert jede Zutatenzeile damit):
     *  - `ansaetze`: Mengenäquivalent (Σ Gramm ÷ Ansatz-Masse) für Zeit, Behälter, Anzeige
     *  - `je_zutat`: nur bei Deltas — Ansätze je recipe_ingredient_id (weicht vom Mittel ab)
     *
     *  - `ohne_gewicht`: nur bei Komponenten — Zeilen ohne Gramm, deren Ansätze über den Rezept-Anteil laufen (R149)
     *
     * @return array{ansaetze: float, je_zutat: ?array<int, float>, gramm_je_portion: ?float, warnung: ?string, ohne_gewicht?: list<int>}
     */
    public function produktionsAnteil(FoodAlchemistRecipe $recipe, ?FoodAlchemistRecipeDarreichung $darreichung, float $portionen, ?Team $team = null): array
    {
        $einheiten = $portionen * (float) ($darreichung?->unit_count ?: 1);
        // Ansatz-Masse wie beim Preis: ek_per_kg bezieht sich auf den Kalkulations-Yield
        // (COALESCE(yield_kg_manual, yield_kg), RecipeRecomputeService A-3).
        $yieldKg = $recipe->yield_kg_manual !== null ? (float) $recipe->yield_kg_manual : (float) ($recipe->yield_kg ?? 0);
        $ansatzG = $yieldKg > 0 ? $yieldKg * 1000 : 0.0;

        if ($darreichung !== null && ($darreichung->deltas()->exists() || $darreichung->menge_vom_standard)) {
            $proEinheit = $this->standardProEinheit($recipe, $team, (int) $darreichung->id);
            $batchZeilen = $this->recompute->zeilenKostenUndMassen($recipe, $team);
            $batchMasse = array_sum(array_map(fn ($z) => $z['masse_g'], $batchZeilen));
            $faktorStd = ($batchMasse > 0 && array_sum(array_column($proEinheit, 'masse_g')) > 0)
                ? array_sum(array_column($proEinheit, 'masse_g')) / $batchMasse : null;
            $deltaMap = $darreichung->deltas()->get()->keyBy('recipe_ingredient_id');
            $jeZutat = [];
            $summeG = 0.0;
            $skala = $this->skalaOhneGewicht($proEinheit, $deltaMap);
            $ohneGewicht = [];
            foreach ($proEinheit as $ingId => $zeile) {
                $delta = $deltaMap->get($ingId);
                if ($zeile['ohne_gewicht'] ?? false) {
                    // Ansätze dieser Zeile = Einheiten × Rezept-Anteil je Einheit × Verhältnis der Variante (statt 0, R149)
                    $ohneGewicht[] = (int) $ingId;
                    $jeZutat[(int) $ingId] = $delta?->omitted ? 0.0 : $einheiten * (float) ($zeile['anteil'] ?? 0) * $skala;

                    continue;
                }
                $g = $delta?->omitted ? 0.0 : ($delta?->quantity_override_g !== null ? (float) $delta->quantity_override_g : $zeile['masse_g']);
                $summeG += $g;
                // Ansätze dieser Zutat = gebrauchte Gramm ÷ Gramm je Ansatz (Standard-Komposition ist bereits je Einheit skaliert).
                $jeAnsatz = (float) ($batchZeilen[$ingId]['masse_g'] ?? 0);
                $jeZutat[(int) $ingId] = $jeAnsatz > 0 ? $einheiten * $g / $jeAnsatz : 0.0;
            }
            $basis = $ansatzG > 0 ? $ansatzG : $batchMasse;
            $summeG *= $this->garfaktor($recipe, $batchZeilen);   // gegart wie der Ertrag in $basis; je_zutat bleibt roh

            return [
                'ansaetze' => $basis > 0 ? $einheiten * $summeG / $basis : 0.0,
                'je_zutat' => $jeZutat,
                'gramm_je_portion' => round($summeG * (float) ($darreichung->unit_count ?: 1), 1),
                'warnung' => $ohneGewicht === [] ? null
                    : "Gericht „{$recipe->name}“: ".count($ohneGewicht).' Bestandteil(e) ohne Gewicht — Menge im Anteil des Rezepts gerechnet'
                        .($deltaMap->isNotEmpty() ? ' (vorläufig, Verhältnis der Variante).' : '.'),
                'ohne_gewicht' => $ohneGewicht,
            ];
        }

        $gramm = (float) ($darreichung?->quantity_per_unit_g ?? 0);
        if ($gramm <= 0 || $ansatzG <= 0) {
            // Form ohne eingetragene Grammatur (oder Gericht ohne Ertrag): Menge über die Ausbeute des Ansatzes — Stück
            // (yield_pieces), sonst eine Portion je Ansatz (98 · 3.5-6a B: Gericht fest 1, vorher sales_unit_count).
            $anzahl = ($recipe->yield_pieces !== null && (float) $recipe->yield_pieces > 0)
                ? (float) $recipe->yield_pieces
                : 1.0;

            return [
                'ansaetze' => $einheiten / $anzahl,
                'je_zutat' => null,
                'gramm_je_portion' => null,
                'warnung' => ($darreichung !== null && ! $darreichung->is_standard)
                    ? "Gericht „{$recipe->name}“: Darreichung „".($darreichung->servingForm?->label ?? '?')."“ ohne Grammatur — Menge wie eine Standard-Portion gerechnet."
                    : null,
            ];
        }
        $warnung = null;

        return [
            'ansaetze' => $einheiten * $gramm / $ansatzG,
            'je_zutat' => null,
            'gramm_je_portion' => round($gramm * (float) ($darreichung?->unit_count ?: 1), 1),
            'warnung' => $warnung,
        ];
    }

    /**
     * Plan 98 B (h): Hinweis „Mengen wirken wie eine Charge“ — abgeleitet, keine Spalte. Die Portion des Standards liegt über
     * {@see CHARGE_OBERGRENZE_G}. Er verschwindet, sobald die Mengen im Band liegen oder der VK des Standards bewusst
     * fixiert ist. Ohne Standard oder ohne bekannte Grammatur: kein Hinweis.
     */
    public function chargenHinweis(FoodAlchemistRecipe $recipe, ?FoodAlchemistRecipeDarreichung $standard = null): ?string
    {
        if (! $recipe->is_sales_recipe) {
            return null;
        }
        $standard ??= $recipe->standardPresentation()->first();
        if ($standard === null || in_array($standard->price_mode, ['fixed', 'manuell'], true)) {
            return null;
        }
        $g = $this->grammJeEinheit($standard, $recipe);
        if ($g === null || $g <= 0) {
            return null;
        }
        $g *= (float) ($standard->unit_count ?: 1);

        return $g > self::CHARGE_OBERGRENZE_G ? self::CHARGE_HINWEIS : null;
    }

    /**
     * Plan 98 B (Dominique 23.11.: „Gegart (Ertrag)“): Faktor roh → gegart für angezeigte Grammaturen. Bei festem Ertrag
     * (yield_kg_manual) Ertrag ÷ Summe der Bestandteile, sonst 1. Kosten und Produktionsmengen bleiben roh.
     *
     * @param  array<int, array{masse_g: float}>|null  $zeilen
     */
    public function garfaktor(FoodAlchemistRecipe $recipe, ?array $zeilen = null, ?Team $team = null): float
    {
        if ($recipe->yield_kg_manual === null || (float) $recipe->yield_kg_manual <= 0) {
            return 1.0;
        }
        $zeilen ??= $this->recompute->zeilenKostenUndMassen($recipe, $team ?? Team::findOrFail($recipe->team_id));
        $b = array_sum(array_column($zeilen, 'masse_g'));

        return $b > 0 ? (float) $recipe->yield_kg_manual * 1000 / $b : 1.0;
    }

    /**
     * Standard-Komposition JE EINHEIT: Batch-Massen der Zutaten skaliert auf die
     * Grammatur der Standard-Darreichung (Fallback: ganze Charge = eine Einheit).
     * Referenz für Delta-Editor (Anzeige „Standard (g)") und Delta-Preisrechnung.
     *
     * @return array<int, array{masse_g: float, kosten_pro_g: ?float}>
     */
    public function standardProEinheit(FoodAlchemistRecipe $recipe, ?Team $team = null, ?int $fuerDarreichung = null): array
    {
        $zeilen = $this->recompute->zeilenKostenUndMassen($recipe, $team);
        $batchG = array_sum(array_map(fn ($z) => $z['masse_g'], $zeilen));
        // Referenz = Grammatur der Standard-Form — aber nur, wenn diese selbst delta-frei
        // ist (sonst wäre die Referenz zirkulär, weil ihre Grammatur aus Deltas entsteht).
        $standard = $recipe->standardPresentation()->first();
        $stdG = $standard !== null && $standard->deltas()->exists() ? $standard->komponenten_referenz_g
            : ($standard?->menge_vom_standard ? null : $standard?->quantity_per_unit_g);
        $eigeneReferenz = false;
        if ($fuerDarreichung !== null && $standard?->id !== $fuerDarreichung) {
            $ziel = $recipe->presentations()->find($fuerDarreichung);
            if ($ziel !== null && ! $ziel->menge_vom_standard && ($ziel->komponenten_referenz_g !== null || ! $ziel->deltas()->exists())) {
                $stdG = $ziel->komponenten_referenz_g ?? $ziel->quantity_per_unit_g;
                $eigeneReferenz = true;
            }
        }
        // Bezugsgrammaturen sind gegart (bei festem Ertrag): ins Verhältnis zum Ertrag, nicht zur rohen Summe — die Mengen je
        // Komponente bleiben roh (Kosten, Produktion)
        $nenner = $batchG * $this->garfaktor($recipe, $zeilen);
        // 98 · 3.5: „wie Rezept“ = der ganze Ansatz ist eine Portion (Teiler fest 1)
        $faktor = ($nenner > 0 && $stdG !== null && (float) $stdG > 0) ? (float) $stdG / $nenner : 1.0;

        $out = [];
        foreach ($zeilen as $ingId => $z) {
            $out[$ingId] = [
                'masse_g' => $z['masse_g'] * $faktor,
                'kosten_pro_g' => ($z['kosten'] !== null && $z['masse_g'] > 0) ? $z['kosten'] / $z['masse_g'] : null,
                // Zeile ohne Gramm: Anteil am Rezept je Einheit und ihre Kosten in diesem Anteil (statt still 0 g)
                'ohne_gewicht' => (bool) ($z['ohne_gewicht'] ?? false),
                'anteil' => $faktor,
                'kosten_einheit' => ($z['ohne_gewicht'] ?? false) && $z['kosten'] !== null ? $z['kosten'] * $faktor : null,
            ];
        }
        if (! $eigeneReferenz && $standard !== null && $standard->id !== $fuerDarreichung) {
            foreach ($standard->deltas()->get() as $delta) {
                if (isset($out[$delta->recipe_ingredient_id])) {
                    $out[$delta->recipe_ingredient_id]['masse_g'] = $delta->omitted ? 0.0
                        : ($delta->quantity_override_g ?? $out[$delta->recipe_ingredient_id]['masse_g']);
                }
            }
        }

        return $out;
    }

    /** Technische Standardform für alte Gerichte ohne Darreichung selbstheilend bereitstellen. */
    private function unbestimmtId(Team $team): int
    {
        $model = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::visibleToTeam($team)
            ->where('code', 'unbestimmt')->first();
        if ($model === null) {
            $model = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create([
                'team_id' => $team->id,
                'code' => 'unbestimmt',
                'label' => 'Unbestimmt',
            ]);
        }

        return (int) $model->id;
    }

    private function find(Team $team, int $darreichungId): FoodAlchemistRecipeDarreichung
    {
        $darreichung = FoodAlchemistRecipeDarreichung::with('recipe')->findOrFail($darreichungId);
        // Spec 84 §4.0: find() bedient nur Schreibwege — sichtbar reicht nicht, das Rezept muss dem Team gehören
        \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::wennNichtEigen(FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($darreichung->recipe_id), $team, 'die Darreichung');

        return $darreichung;
    }
}
