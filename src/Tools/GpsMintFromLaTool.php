<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LaFirstGpService;

/**
 * 07·M3 — LA-First-GP-Mint als MCP-Tool. Löst den Ruby-Schokolade-Fall (#76)
 * FA-nativ: eine Zutat ohne passenden GP wird aus einer REALEN Lieferantenartikel
 * (LA) gemintet (Name aus LA, status=tentative, LA-verknüpft → Allergene/Nährwerte/
 * EK LA-abgeleitet), statt in der Staging-Sackgasse zu enden.
 *
 * Doktrin (Dominique 2026-07-18): Ein GP darf NIE ohne LA entstehen. Der Mint aus
 * einer realen LA ist die sanktionierte LA-First-Entstehung — KEIN „autonomer
 * Commit aus dem Nichts": tentative + ReviewQueue-pflichtig (Mensch hebt auf
 * approved). Findet sich KEINE LA → KEIN GP: dann fehlt Stammdaten, nicht ein
 * Kurations-Klick → foodalchemist.gp_proposals.POST als Beschaffungs-Wunsch (M4).
 *
 * Reihenfolge: erst foodalchemist.gps.MATCH (Bestand nutzen!), nur bei „none" minten.
 */
class GpsMintFromLaTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.MINT_FROM_LA';
    }

    public function getDescription(): string
    {
        return 'Mintet FA-nativ ein Grundprodukt (GP) aus einer passenden Lieferantenartikel (LA) für eine '
            . 'Zutat OHNE Bestands-GP — Name aus der LA, status=tentative, LA-verknüpft. NUR nutzen, wenn '
            . 'foodalchemist.gps.MATCH keinen Treffer (target=none) lieferte. Findet sich KEINE passende LA, '
            . 'entsteht KEIN GP (minted=false) → dann foodalchemist.gp_proposals.POST als Beschaffungs-Wunsch. '
            . 'Der gemintete GP ist tentative (menschliche Freigabe folgt), aber sofort als gp_id verwendbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zutat' => ['type' => 'string', 'description' => 'Zutat-Freitext, z. B. "Ruby-Schokolade" oder "Yuzu-Saft"'],
                'main_ingredient_slug' => ['type' => 'string', 'description' => 'Optionaler Slug der Hauptzutat zur Präzisierung'],
            ],
            'required' => ['zutat'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $zutat = trim((string) ($arguments['zutat'] ?? ''));
        if ($zutat === '') {
            return ToolResult::error('zutat darf nicht leer sein.', 'VALIDATION_ERROR');
        }
        $slug = isset($arguments['main_ingredient_slug']) ? (string) $arguments['main_ingredient_slug'] : null;

        $gp = app(LaFirstGpService::class)->mintFromLa($team, $zutat, $slug);

        if ($gp === null) {
            return ToolResult::success([
                'minted' => false,
                'gp' => null,
                'note' => 'Keine passende LA gefunden → KEIN GP (Doktrin: kein GP ohne LA). Es fehlt Stammdaten, '
                    . 'nicht ein Kurations-Klick. Nächster Schritt: foodalchemist.gp_proposals.POST als '
                    . 'Beschaffungs-Wunsch („Artikel beschaffen/anlegen"), nicht raten.',
            ]);
        }

        return ToolResult::success([
            'minted' => true,
            'gp' => [
                'id' => $gp->id,
                'name' => $gp->name,
                'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : $gp->status,
                'main_ingredient_slug' => $gp->main_ingredient_slug,
                'requires_la' => (bool) $gp->requires_la,
            ],
            'note' => 'LA-First gemintet: status=tentative (menschliche Freigabe folgt), LA-verknüpft → '
                . 'Allergene/Nährwerte/EK LA-abgeleitet. Sofort als gp_id in Rezept-Zeilen verwendbar.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'mint', 'la-first', 'lieferantenartikel'],
            'read_only' => false,
            'idempotent' => true,   // Dedup-Reuse (gp_key/Jaccard) + LA-schon-gemappt → gleiches GP
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MATCH', 'foodalchemist.gp_proposals.POST', 'foodalchemist.artikel.SEARCH'],
            'examples' => [
                'Es gibt keinen GP für "Ruby-Schokolade" — minte einen aus der passenden LA',
                'Lege LA-First ein GP für "Yuzu-Saft" an',
            ],
        ];
    }
}
