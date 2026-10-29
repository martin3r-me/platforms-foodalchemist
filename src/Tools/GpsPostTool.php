<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * MCP-Steuerbarkeit · D1: Grundprodukt anlegen (Regelwerk_Grundprodukte §6-Naming).
 *
 * Legt team-eigen an (status=tentative). Der Name wird aus den Strukturfeldern gerendert,
 * wenn kein `name` übergeben ist, und gegen das Regelwerk validiert (§7.1 Verpackungswörter,
 * §9 Zustands-Vokabular). Der Anlage-Guard (gp_key-UNIQUE + Namens-Jaccard) blockt Dubletten
 * hart — `force=true` legt bewusst trotzdem an (~n-Suffix). `derivat_von_gp_id` wird team-scoped
 * re-autorisiert.
 */
class GpsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const IN_KEYS = [
        'name', 'hauptzutat', 'flavor', 'zusatz', 'condition', 'processing', 'form', 'portion', 'pflichtangabe',
        'commodity_group_code', 'sub_category', 'is_derivat', 'derivat_von_gp_id', 'bio', 'vegan', 'glutenfrei', 'laktosefrei',
        'rezepturware',   // §5 Ausnahme 3; marke_offen ist abgeleitet und nie Eingabe
    ];

    public function getName(): string
    {
        return 'foodalchemist.gps.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Grundprodukt an (team-eigen, status=tentative), Name nach Regelwerk_Grundprodukte §6. '
            . 'Entweder `name` direkt oder Strukturfelder (hauptzutat + flavor/condition/processing/form/…) zum Rendern; flavor = Geschmackszutat (§6.6), steht vor dem Zustand. '
            . 'hauptzutat ist Pflicht. Dubletten werden hart geblockt — force=true legt bewusst trotzdem an. '
            . 'Für den Zutaten→GP-Sourcing-Backlog stattdessen gps.MATCH/gp_proposals.POST nutzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Fertiger GP-Name (optional; sonst aus Strukturfeldern gerendert).'],
                'hauptzutat' => ['type' => 'string', 'description' => 'Hauptzutat (Pflicht) — Basis des Namens + main_ingredient_slug.'],
                'flavor' => ['type' => 'string', 'description' => 'Geschmackszutat (§6.6): erste Eigenschaft im Namen vor dem Zustand, z. B. Chili → "Käse: Chili, frisch, …". Nur wenn im Artikel belegt; die Grundware bleibt hauptzutat.'],
                'zusatz' => ['type' => 'string', 'description' => 'Klammer-Zusatz, steht hinten als "/ (…)": Fantasiename (§6.6) oder bei Rezepturware und Getränken mit Gattungsname (WG 15) die Marke (§5 Ausnahme 3; Getränke Dominique 28.10.; Wert aus foodalchemist.artikel.MARKE, z. B. Block House, Heisters, Happy Day). Nur exakt aus Artikel/Vermarktungsname bzw. aus artikel.MARKE, nie selbst bilden; Händlernamen nie.'],
                'rezepturware' => ['type' => 'boolean', 'description' => '§5 Ausnahme 3: Produkt mit eigener Rezeptur aus mehreren Zutaten (Marke/Hersteller in der Klammer, anderer Hersteller = eigenes GP). null bzw. weglassen = nicht entschieden. Wurst (WG 04) und Getränke (WG 15) immer false — Getränke mit Gattungsname + Hersteller-Marke tragen die Marke trotzdem als Klammer-Zusatz (zusatz, Dominique 28.10.: „Ananassaft: 1 l / (Happy Day)“); Markenprodukte ohne eigenen Gattungsnamen behalten die Marke als Name („Coca-Cola: 0,33 l“); ohne Marke bleibt die Klammer leer (generisch, kein marke_offen). marke_offen leitet das System ab.'],
                'condition' => ['type' => 'string', 'description' => 'Zustand (§9): ' . \Platform\FoodAlchemist\Services\GpNamingService::zustandListe() . '.'],
                'processing' => ['type' => 'string', 'description' => 'Verarbeitung/Zuschnitt (z.B. Wuerfel 10 mm).'],
                'form' => ['type' => 'string', 'description' => 'Form (falls keine processing).'],
                'portion' => ['type' => 'string', 'description' => 'Portionsgrammatur pro Stück (optional, z. B. "100 g"; der Name zeigt "100 g pro Stück"). Wird am GP gespeichert.'],
                'pflichtangabe' => ['type' => 'string', 'description' => 'Kategorie-Pflichtangabe (§8), z.B. Kochtyp/Käseart, mini, 3-fach sortiert. Wird am GP gespeichert.'],
                'bio' => ['type' => 'boolean', 'description' => 'Namensmarker „/ (Bio)“ (Feld am GP; ohne den Parameter bleibt der Wert stehen).'],
                'vegan' => ['type' => 'boolean', 'description' => 'Namensmarker „/ (Vegan)“ (Feld namens_vegan, getrennt vom Artikel-Tag).'],
                'glutenfrei' => ['type' => 'boolean', 'description' => 'Namensmarker „/ (Glutenfrei)“ (Feld namens_glutenfrei).'],
                'laktosefrei' => ['type' => 'boolean', 'description' => 'Namensmarker „/ (Laktosefrei)“ (Feld namens_laktosefrei).'],
                'commodity_group_code' => ['type' => 'string', 'description' => 'Warengruppen-Code (§3).'],
                'sub_category' => ['type' => 'string', 'description' => 'Sub-Kategorie.'],
                'is_derivat' => ['type' => 'boolean', 'description' => 'Nebenprodukt-Derivat (§11.2) — requires_la wird dann 0.'],
                'derivat_von_gp_id' => ['type' => 'integer', 'description' => 'Mutter-GP bei Derivat (team-scoped geprüft).'],
                'diameter_cm' => ['type' => 'number', 'description' => 'Non-Food (WG 16): Ø in cm, z. B. 27 oder 7,5 (leer löscht).'],
                'length_cm' => ['type' => 'number', 'description' => 'Non-Food (WG 16): Länge in cm.'],
                'width_cm' => ['type' => 'number', 'description' => 'Non-Food (WG 16): Breite in cm.'],
                'height_cm' => ['type' => 'number', 'description' => 'Non-Food (WG 16): Höhe in cm.'],
                'volume_ml' => ['type' => 'number', 'description' => 'Non-Food (WG 16): Volumen in ml (Gläser, Schalen).'],
                'serving_vehicle' => ['type' => 'string', 'description' => 'Non-Food (WG 16): Servier-Typ des Gefäßes als Name oder Id (als Text) aus dem Vokabular Servier-Vehikel (leer löscht); die Darreichung liest ihn von hier.'],
                'force' => ['type' => 'boolean', 'description' => 'Dubletten-Hard-Stop bewusst übergehen.'],
            ],
            'required' => ['hauptzutat'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        if (trim((string) ($arguments['hauptzutat'] ?? '')) === '') {
            return ToolResult::error('hauptzutat ist Pflicht.', 'VALIDATION_ERROR');
        }

        $derivatVon = $arguments['derivat_von_gp_id'] ?? null;
        if ($derivatVon !== null && $derivatVon !== '') {
            if (! FoodAlchemistGp::visibleToTeam($team)->whereKey((int) $derivatVon)->exists()) {
                return ToolResult::error('derivat_von_gp_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
        }

        $in = array_intersect_key($arguments, array_flip(self::IN_KEYS));
        $masse = array_intersect_key($arguments, \Platform\FoodAlchemist\Support\NonFoodMasse::EINGABE);
        // Non-Food (WG 16): ohne eigene Größe-Angabe kommt sie aus den Maßen (Ø 27 cm, 30 x 20 x 5 cm, 212 ml) — Regelwerk §6.5
        if ($masse !== [] && ($in['commodity_group_code'] ?? '') === \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE && trim((string) ($in['pflichtangabe'] ?? '')) === '') {
            $groesse = app(\Platform\FoodAlchemist\Services\GpMasseService::class)->groesseAusEingabe($masse);
            if ($groesse !== null) {
                $in['pflichtangabe'] = $groesse;
            }
        }

        try {
            $gp = app(GpNamingService::class)->createGp($team, $in, (bool) ($arguments['force'] ?? false));
            if ($masse !== []) {
                $gp = app(\Platform\FoodAlchemist\Services\GpMasseService::class)->setze($gp, $masse);
            }
            if (array_key_exists('serving_vehicle', $arguments)) {
                $gp = app(\Platform\FoodAlchemist\Services\GpMasseService::class)->setzeServierTyp($gp, $arguments['serving_vehicle']);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $gp->id,
            'gp_key' => $gp->gp_key,
            'name' => $gp->name,
            'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status,
            'is_derivat' => (bool) $gp->is_derivat,
            'requires_la' => (bool) $gp->requires_la,
            'groesse' => $gp->masseText(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'anlegen', 'katalog', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.gps.PUT', 'foodalchemist.gps.MATCH', 'foodalchemist.gp_proposals.POST'],
            'examples' => ['Lege ein GP „Zanderfilet: frisch" an.'],
        ];
    }
}
