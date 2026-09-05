<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;

/**
 * Spec 50 · Schicht 4 — Reife eines Basisrezepts oder Verkaufsgerichts.
 *
 * Komponiert die vorhandenen Messer, statt neu zu rechnen:
 *  · {@see BulkEnrichService::luecken()} — die Zielfelder der Schrittfolge
 *  · {@see RecipeOneShotService::vkVorbedingungen()} — VK-Vorbedingungen, read-only (Etappe 2)
 *  · {@see DataQualityService::trifftObjekt()} — die Live-Ampel-Metriken je Objekt
 *  · Satelliten-Tabellen — was `complete_coverage` füllen würde
 *
 * Zwei Felder ohne Schrittfolge bekommen eigene Codes, weil sie je eine Kette tragen und
 * die Etappe-0-Messung sie als Massenphänomen ausgewiesen hat (§9):
 *  · `work_time_min` fehlt → `KalkulationService::recipeHk` rechnet FEK und FGK = 0
 *    (912 von 950 Gerichten auf demo).
 *  · `dichteklasse` fehlt → `BehaelterBedarfService` kann den Bedarf nicht rechnen
 *    (100 % auf beiden Ebenen — das Feld ist neu aus Spec 51).
 */
class RecipeReifeAdapter implements ReifeAdapter
{
    /** Metriken aus der Datenqualitäts-Ampel, die auf dieser Ebene etwas aussagen. */
    private const DQ_BASIS = ['br_ek_null', 'br_ek_teil', 'br_anker_fehlt'];

    private const DQ_VK = ['vk_ek_null', 'vk_ek_teil', 'vk_anker_fehlt', 'vk_servierform_unbestimmt'];

    /**
     * Metriken, die dieselbe Aussage tragen wie ein Satelliten-Code — sie würden den Report
     * verdoppeln. `*_anker_fehlt` und `aromaanker` sind derselbe Befund unter zwei Namen;
     * ein Agent, der beide sieht, hält sie für zwei Aufgaben.
     */
    private const DQ_DUBLETTE = ['br_anker_fehlt' => 'aromaanker', 'vk_anker_fehlt' => 'aromaanker'];

    /** Satellit → [Code, Beschreibung, Tool zum Schliessen]. */
    private const SATELLITEN = [
        'foodalchemist_recipe_steps' => ['steps', 'Keine Zubereitungsschritte', 'foodalchemist.recipe_steps.PUT'],
        'foodalchemist_recipe_taste_vectors' => ['sensorik', 'Keine Sensorik-Bewertung', 'foodalchemist.recipe_sensorik.POST'],
        'foodalchemist_recipe_anchor_mappings' => ['aromaanker', 'Keine Aroma-Anker', 'foodalchemist.recipe_anchors.PUT'],
        'foodalchemist_recipe_pairings' => ['pairings', 'Keine Foodpairing-Partner', 'foodalchemist.recipe_pairings.PUT'],
        'foodalchemist_recipe_equipment' => ['equipment', 'Kein Equipment hinterlegt', null],
    ];

    public function __construct(
        private BulkEnrichService $bulk,
        private RecipeOneShotService $oneShot,
        private DataQualityService $dq,
    ) {}

    public function artifactType(): string
    {
        return 'recipe';
    }

    public function messe(Team $team, int $id): ?array
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($id);
        if ($r === null) {
            return null;
        }

        $istVk = (bool) $r->is_sales_recipe;
        $luecken = [];
        $erfuellt = [];
        $nichtMessbar = [];
        $kennzahlen = [];
        $vorlaeufig = false;

        // ── 1. Zielfelder der Schrittfolge ───────────────────────────────────────────
        $schritte = $istVk ? BulkEnrichService::SCHRITTE_VK : BulkEnrichService::SCHRITTE;
        $offen = $this->bulk->luecken($r, $schritte);
        foreach ($schritte as $schritt) {
            if (in_array($schritt, $offen, true)) {
                $luecken[] = $this->luecke(
                    $schritt, $istVk ? 'gericht' : 'basisrezept', 'wichtig',
                    "Zielfeld des Anreicherungs-Schritts «{$schritt}» ist leer.",
                    $istVk ? 'foodalchemist.verkaufsrezepte.PUT' : 'foodalchemist.recipes.PUT'
                );
            } else {
                $erfuellt[] = $schritt;
            }
        }

        // ── 2. Felder ohne Schrittfolge, die je eine Kette tragen ────────────────────
        if ($r->work_time_min === null) {
            $luecken[] = $this->luecke('work_time_min', $istVk ? 'gericht' : 'basisrezept', 'blockiert',
                'Ohne Arbeitszeit rechnet die Kalkulation FEK und FGK als 0 — die Lohnkosten fehlen still.',
                $istVk ? 'foodalchemist.verkaufsrezepte.PUT' : 'foodalchemist.recipes.PUT');
        } else {
            $erfuellt[] = 'work_time_min';
        }
        if ($r->dichteklasse === null) {
            $luecken[] = $this->luecke('dichteklasse', $istVk ? 'gericht' : 'basisrezept', 'wichtig',
                'Ohne Dichteklasse lässt sich der Behälterbedarf nicht rechnen (Spec 51).',
                null);
        } else {
            $erfuellt[] = 'dichteklasse';
        }

        // ── 3. Satelliten — was complete_coverage füllen würde ───────────────────────
        foreach (self::SATELLITEN as $tabelle => [$code, $text, $tool]) {
            $hat = \Illuminate\Support\Facades\DB::table($tabelle)->where('recipe_id', $r->id)->exists();
            if ($hat) {
                $erfuellt[] = $code;

                continue;
            }
            // Ehrliche Degradation: Pairings ohne Anker sind keine Lücke des Rezepts,
            // sondern eine Folge — das `pairings`-Glied steigt ohne Anker-Grounding aus
            // (RecipeOneShotService:673). Als Lücke gemeldet würde es zu einem Auftrag,
            // der so nicht erfüllbar ist.
            if ($code === 'pairings' && ! \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_anchor_mappings')
                ->where('recipe_id', $r->id)->exists()) {
                $nichtMessbar[] = ['code' => 'pairings', 'warum' => 'Ohne Aroma-Anker gibt es keine Erdung — zuerst «aromaanker» schliessen.'];

                continue;
            }
            $luecken[] = $this->luecke($code, $istVk ? 'gericht' : 'basisrezept', 'hinweis', $text, $tool);
        }

        // ── 4. VK-Vorbedingungen (read-only, Etappe 2) ───────────────────────────────
        if ($istVk) {
            $vk = $this->oneShot->vkVorbedingungen($team, $r);
            if ($vk !== null && ($vk['fehler'] ?? null) === null) {
                $kennzahlen = [
                    'vk_netto' => $vk['sales_net'], 'ek_pro_portion' => $vk['ek_pro_portion'],
                    'wareneinsatz_pct' => $vk['wareneinsatz_pct'], 'ziel_pct' => $vk['ziel_pct'],
                    'food_cost_ampel' => $vk['ampel'], 'aufschlagsklasse' => $vk['aufschlagsklasse'],
                    'aufschlagsklasse_quelle' => $vk['aufschlagsklasse_quelle'],
                ];
                foreach ($vk['luecken'] as $code) {
                    $luecken[] = match ($code) {
                        'portion' => $this->luecke('portion', 'gericht', 'blockiert',
                            'Ohne Portionsgrösse kein Auto-VK — die Grammatur wird bewusst nicht geraten.',
                            'foodalchemist.recipe_darreichung.PUT'),
                        'darreichung' => $this->luecke('darreichung', 'gericht', 'blockiert',
                            'Keine Standard-Darreichung — sie ist die Preis-Wahrheit, ohne sie gibt es keinen VK.',
                            'foodalchemist.recipe_darreichung.POST'),
                        default => $this->luecke($code, 'gericht', 'wichtig', "Offene VK-Vorbedingung «{$code}».", null),
                    };
                }
                // Die Aufschlagsklasse blockiert den Preis nicht (neutraler Faktor 100 %),
                // aber „nur ableitbar" ist etwas anderes als „gesetzt" — und genau das
                // verwischt der Schreib-Pfad, weil er sie festschreibt.
                if ($vk['aufschlagsklasse_quelle'] !== 'gesetzt') {
                    $luecken[] = $this->luecke('aufschlagsklasse', 'gericht',
                        $vk['aufschlagsklasse_quelle'] === 'keine' ? 'wichtig' : 'hinweis',
                        $vk['aufschlagsklasse_quelle'] === 'keine'
                            ? 'Keine Aufschlagsklasse und kein Default ableitbar — der Preis rechnet mit neutralem Faktor 100 %.'
                            : "Aufschlagsklasse nur aus dem Default «{$vk['aufschlagsklasse_quelle']}» abgeleitet, nicht am Gericht gesetzt.",
                        'foodalchemist.verkaufsrezepte.PUT');
                }
                $vorlaeufig = (bool) $vk['vorlaeufig'];
                if ($vorlaeufig) {
                    $nichtMessbar[] = ['code' => 'wareneinsatz', 'warum' => 'EK unvollständig (Zutaten ohne Preis) — die Quote ist vorläufig.'];
                }
            } else {
                $nichtMessbar[] = ['code' => 'vk_vorbedingungen', 'warum' => $vk['fehler'] ?? 'VK-Vorbedingungen nicht ermittelbar.'];
            }
        }

        // ── 5. Datenqualitäts-Ampel je Objekt ───────────────────────────────────────
        $gemeldet = array_column($luecken, 'code');
        foreach ($istVk ? self::DQ_VK : self::DQ_BASIS as $metrik) {
            $dublette = self::DQ_DUBLETTE[$metrik] ?? null;
            if ($dublette !== null && in_array($dublette, $gemeldet, true)) {
                continue;                                          // schon als Satelliten-Lücke gemeldet
            }
            if ($this->dq->trifftObjekt($team, $metrik, 'recipe', $r->id)) {
                $luecken[] = $this->luecke($metrik, $istVk ? 'gericht' : 'basisrezept', 'wichtig',
                    'Datenqualitäts-Ampel meldet diesen Befund.', null);
            }
        }

        return [
            'name' => (string) $r->name,
            // `status` ist ein RecipeStatus-Enum — ->value, nicht (string).
            'status' => $r->status instanceof \BackedEnum ? (string) $r->status->value : ($r->status !== null ? (string) $r->status : null),
            'luecken' => $luecken,
            'erfuellt' => $erfuellt,
            'nicht_messbar' => $nichtMessbar,
            'vorlaeufig' => $vorlaeufig,
            'kennzahlen' => $kennzahlen,
        ];
    }

    /** @return array{code: string, ebene: string, schwere: string, was: string, wie: ?array} */
    private function luecke(string $code, string $ebene, string $schwere, string $was, ?string $tool): array
    {
        return [
            'code' => $code, 'ebene' => $ebene, 'schwere' => $schwere, 'was' => $was,
            'wie' => $tool !== null ? ['tool' => $tool] : null,
        ];
    }
}
