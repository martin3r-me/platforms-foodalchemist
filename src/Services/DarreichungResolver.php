<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;

/**
 * Löst auf, WELCHE Darreichung eines Gerichts in einem Kontext gilt
 * (Umbau-Spec Darreichungen §2). Auflösungsreihenfolge:
 *
 *   1. explizit am Slot/Paket-Gericht gesetzte Darreichung
 *   2. (Phase 4, vorbereitet) Darreichung passend zur Servierform des Konzepts
 *   3. Standard-Darreichung des Gerichts
 *
 * Stateless. Preis-Wahrheit liegt an der Darreichung; recipes.sales_net ist
 * nur noch Anzeige-Spiegel der Standard-Form (Import füllt ihn fill-only).
 */
class DarreichungResolver
{
    public function fuerSlot(FoodAlchemistConceptSlot $slot): ?FoodAlchemistRecipeDarreichung
    {
        if ($slot->presentation_id !== null && $slot->presentation !== null) {
            return $slot->presentation;
        }

        if ($slot->dish === null) {
            return null;
        }

        // Phase 4: Servierform des Konzepts → passende Darreichung des Gerichts
        $konzeptServierformId = $slot->concept?->serving_form_id;
        if ($konzeptServierformId !== null) {
            $passend = $slot->dish->presentations()
                ->where('serving_form_id', $konzeptServierformId)
                ->first();
            if ($passend !== null) {
                return $passend;
            }
        }

        return $this->standardFuer($slot->dish);
    }

    public function fuerPaketGericht(FoodAlchemistPaketGericht $pg): ?FoodAlchemistRecipeDarreichung
    {
        if ($pg->presentation_id !== null && $pg->presentation !== null) {
            return $pg->presentation;
        }

        return $pg->dish !== null ? $this->standardFuer($pg->dish) : null;
    }

    /**
     * Darreichung eines `recipe_ref`-Foodbook-Blocks (Spec 19, M5/E7.1 — Einzel-Gericht-Pfad).
     * Auflösung analog {@see fuerSlot()}:
     *   1. block.presentation_id (expliziter Override) →
     *   2. Gericht-Darreichung zur übergebenen Servierform (Kapitel-/Foodbook-Servierform,
     *      vom Aufrufer aus der Dimensions-Kaskade gereicht) →
     *   3. standardFuer().
     *
     * `$servingFormId === null` UND keine block-Darreichung ⇒ standardFuer(), dessen sales_net
     * per Invariante der Anzeige-Spiegel `recipes.sales_net` ist → bit-identisch zu heute
     * (keine sichtbaren Preisänderungen im Bestand). dish === null ⇒ null.
     */
    public function fuerBlock(FoodAlchemistFoodbookBlock $block, ?int $servingFormId = null): ?FoodAlchemistRecipeDarreichung
    {
        if ($block->presentation_id !== null && $block->presentation !== null) {
            return $block->presentation;
        }

        if ($block->dish === null) {
            return null;
        }

        if ($servingFormId !== null) {
            $passend = $block->dish->presentations()
                ->where('serving_form_id', $servingFormId)
                ->first();
            if ($passend !== null) {
                return $passend;
            }
        }

        return $this->standardFuer($block->dish);
    }

    /**
     * Darreichung eines Speiseplan-Eintrags: explizit gesetzte Form (nur wenn sie noch zum
     * Gericht des Eintrags gehört) → Standard-Darreichung. Concept-/Paket-Einträge ⇒ null.
     */
    public function fuerSpeiseplanEintrag(FoodAlchemistSpeiseplanEintrag $e): ?FoodAlchemistRecipeDarreichung
    {
        if ($e->sales_recipe_id === null || $e->dish === null) {
            return null;
        }
        if ($e->presentation_id !== null && $e->presentation !== null
            && (int) $e->presentation->recipe_id === (int) $e->sales_recipe_id) {
            return $e->presentation;
        }

        return $this->standardFuer($e->dish);
    }

    public function standardFuer(FoodAlchemistRecipe $recipe): ?FoodAlchemistRecipeDarreichung
    {
        return $recipe->standardPresentation ?? $recipe->presentations()->orderBy('id')->first();
    }

    /**
     * VK netto EINES GERICHTS ohne Kontext — die Preis-Leiter selbst: Standard-Darreichung
     * (M2-Wahrheit) → `recipes.sales_net` (Legacy-Spiegel) → keine Zahl. Gibt die Quelle mit
     * aus, damit ein Aufrufer sie benennen kann statt sie zu erraten.
     *
     * **Warum hier und nicht beim Aufrufer (V-046):** dieselbe Leiter lag verstreut in
     * `KalkulationService::recipeHk`, im Wirtschafts-Block des Kandidaten-Pools und (in halber
     * Form) direkt als Rezept-Spalte im Pool-Filter. Solange der harte Preisfilter die eine
     * Zahl liest und die Zielfunktion die andere, ist ein Optimum nicht erklärbar.
     *
     * **Batch-fähig ohne zweiten Weg:** ist `standardPresentation` bereits eager geladen, wird
     * die geladene Relation genommen (keine Query je Gericht); sonst läuft die volle Leiter über
     * {@see standardFuer()} inklusive dessen Fallback „erste Darreichung nach id".
     *
     * ⚠️ **Bekannte Rest-Divergenz (V-059, NICHT in diesem Zug behoben):** genau dieser Fallback
     * unterscheidet die beiden Wege. Hat ein Gericht Darreichungen, aber keine mit `is_standard`,
     * liefert der geladene Weg `null` (⇒ Legacy-Spalte), der ungeladene die erste Darreichung.
     * Im Bestand ist der Fall leer (0 von 31 Gerichten mit Darreichung, Dev-MySQL 2026-07-27);
     * ihn aufzulösen heißt, entweder alle Darreichungen eager zu laden oder den Fallback
     * fallenzulassen — eine Auswahl-Entscheidung, die Dominique gehört.
     *
     * @return array{vk: ?float, quelle: 'darreichung'|'legacy'|'keine'}
     */
    public function vkNettoMitQuelle(FoodAlchemistRecipe $recipe, ?FoodAlchemistOutlet $outlet = null): array
    {
        $standard = $recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe);

        if ($standard !== null) {
            // Ebene 2: im Betriebs-Kontext den VK on-the-fly gegen dessen Kostenstruktur;
            // ohne Betrieb der gespeicherte Baseline-VK der Standard-Darreichung (= heute).
            $vk = $outlet !== null
                ? app(CatalogPricingService::class)->salesNetFor(Team::find($outlet->team_id), $standard, $outlet)
                : ($standard->sales_net !== null ? (float) $standard->sales_net : null);
            if ($vk !== null) {
                return ['vk' => $vk, 'quelle' => 'darreichung'];
            }
        }

        if ($recipe->sales_net !== null) {   // Spec 85 D-1: Rückfall Altspalte bis PR-C
            return ['vk' => (float) $recipe->sales_net, 'quelle' => 'legacy'];
        }

        return ['vk' => null, 'quelle' => 'keine'];
    }

    /**
     * VK netto eines Gerichts als Zahl — {@see vkNettoMitQuelle()} ohne die Quelle. Spec 85 D-1 PR-A: die
     * Leser lesen den VK nur noch hier (bzw. per SQL an der Standard-Darreichung), nie direkt aus
     * `recipes.sales_net`. Für Listen vorher `standardPresentation` eager laden (sonst eine Query je Gericht).
     */
    public function vkNetto(FoodAlchemistRecipe $recipe, ?FoodAlchemistOutlet $outlet = null): ?float
    {
        return $this->vkNettoMitQuelle($recipe, $outlet)['vk'];
    }

    /**
     * EK EINER Portion — Gegenstück zu {@see vkNetto()}, damit Wareneinsatz % = EK/Portion ÷ VK/Portion auf
     * derselben Menge rechnet (Spec 85, Wareneinsatz-Maßstab): `ek_portion` der Standard-Darreichung (inkl.
     * Beigaben), sonst `ek_total_eur` (ganzer Ansatz) ÷ Portionen. `sales_unit_count` muss geladen sein:
     * fehlt die Spalte, wirft die Methode in Tests (Lücke finden), in Produktion lädt sie den Wert nach und
     * schreibt eine Warnung mit Aufrufstelle in `fa-langsam` (Kurator 19.10.: kein 500 für Nutzer, kein stilles ÷ 1).
     * Steuerbar über `foodalchemist.leistung.ek_pro_portion_streng` (Standard: läuft unter Tests).
     */
    public function ekProPortion(FoodAlchemistRecipe $recipe): ?float
    {
        $standard = $recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe);
        if ($standard?->ek_portion !== null) {
            return (float) $standard->ek_portion;
        }
        if ($recipe->ek_total_eur === null) {
            return null;
        }
        $anzahl = array_key_exists('sales_unit_count', $recipe->getAttributes())
            ? $recipe->sales_unit_count
            : $this->portionenNachladen($recipe);

        return (float) $recipe->ek_total_eur / max(1, (int) ($anzahl ?? 1));
    }

    /** Fehlende Spalte `sales_unit_count`: in Tests werfen, in Produktion nachladen + warnen (s. {@see ekProPortion()}). */
    private function portionenNachladen(FoodAlchemistRecipe $recipe): mixed
    {
        $meldung = 'ekProPortion braucht sales_unit_count am Rezept ' . $recipe->id . ' (im Select ergänzen).';
        if (config('foodalchemist.leistung.ek_pro_portion_streng') ?? app()->runningUnitTests()) {
            throw new \LogicException($meldung);
        }
        // Dasselbe, schon geladene Rezept — nur die fehlende Spalte; bewusst ohne Sicht-Scope (die Zeile ist bereits gelesen).
        $anzahl = \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')->where('id', $recipe->id)->value('sales_unit_count');
        \Illuminate\Support\Facades\Log::channel(\Platform\FoodAlchemist\Support\LangsameAbfragen::KANAL)->warning('Spec 85: ' . $meldung . ' Nachgeladen.', [
            'art' => 'ek_pro_portion_nachgeladen',
            'recipe_id' => (int) $recipe->id,
            'aufrufer' => $this->aufrufer(),
        ]);

        return $anzahl;
    }

    /** Erster Frame aus dem Modul-`src/` außerhalb dieser Klasse und des Rezept-Modells: Datei relativ zu src/ + Zeile. */
    private function aufrufer(): ?string
    {
        $src = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $aus = [__FILE__, $src . 'Models' . DIRECTORY_SEPARATOR . 'FoodAlchemistRecipe.php'];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) {
            $datei = $f['file'] ?? '';
            if ($datei !== '' && str_starts_with($datei, $src) && ! in_array($datei, $aus, true)) {
                return substr($datei, strlen($src)) . ':' . ($f['line'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Spec 85 D-1 PR-B: Aufschlagsklasse eines Gerichts = Klasse seiner Standard-Darreichung. Für Listen
     * `standardPresentation.markupClass` eager laden.
     */
    public function klasseFuer(FoodAlchemistRecipe $recipe): ?\Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass
    {
        $standard = $recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe);
        if ($standard?->markup_class_id !== null) {
            return $standard->markupClass;
        }

        return $recipe->markup_class_id !== null ? $recipe->markupClass : null;   // Spec 85 D-1: Rückfall Altspalte bis PR-C
    }

    /** VK netto im Kontext (Darreichung zuerst, Legacy-Spalte als Fallback). */
    public function vkNettoFuerSlot(FoodAlchemistConceptSlot $slot, ?FoodAlchemistOutlet $outlet = null): ?float
    {
        $darreichung = $this->fuerSlot($slot);

        if ($darreichung !== null) {
            $vk = $outlet !== null
                ? app(CatalogPricingService::class)->salesNetFor(Team::find($outlet->team_id), $darreichung, $outlet)
                : ($darreichung->sales_net !== null ? (float) $darreichung->sales_net : null);
            if ($vk !== null) {
                return $vk;
            }
        }

        return $slot->dish?->sales_net !== null ? (float) $slot->dish->sales_net : null;   // Spec 85 D-1: Rückfall Altspalte bis PR-C
    }
}
