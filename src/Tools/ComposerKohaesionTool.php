<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Composer-MCP: dieselbe Read-Seite wie der Composer-Tab, headless (Spec 60).
 *  - {@see \Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::datenAusAnkern} — „Passt das zusammen?"
 *  - {@see PairingService::composerCohesion}    — Kennzahl derselben Regel (nur 3★ zählt)
 *  - {@see PairingService::pairingNetzForAnkers} — nur die Zählwerte (★★★, Kontrast, Konflikt); D3-Daten verworfen
 *  - {@see PairingService::gpsForAnkerIds}      — welche echten, kaufbaren GPs die Anker als Kern tragen (Erdung)
 * Anker-IDs kommen aus composer.ANKER_SUCHE. Read-only.
 */
class ComposerKohaesionTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.composer.KOHAESION';
    }

    public function getDescription(): string
    {
        return 'Composer: bewertet eine freie Aroma-Anker-Menge headless — dieselbe Aussage wie der Composer-Tab. '
            . 'Liefert (1) kombination = die Kombinationslogik (Spec 60) über die Auswahl: harmoniert (nur 3★), '
            . 'spannung, bedarf_offen, konflikt, kombination — je mit Grundlage — plus Vorschläge (Basisrezepte) für '
            . 'offene Bedarfe, (2) kohaesion = Kennzahl derselben Regel (nur 3★ zählt; gemessene Paare ohne 3★ = 0), '
            . '(3) netz_counts = ★★★-Partner, Kontrast-Lieferanten, Konflikte im Netz, (4) erdung = welche echten '
            . 'team-sichtbaren GPs die Anker als Kern tragen (Aromaträger zum Einkaufen). '
            . 'Anker-IDs via composer.ANKER_SUCHE holen. Für eine Kohäsions-Aussage '
            . 'mind. 2 IDs. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'anker_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Anker-IDs der Komposition (aus composer.ANKER_SUCHE). Mind. 2 für eine Kohäsions-/Brücken-Aussage; bei 1 wird nur die Erdung geliefert.'],
            ],
            'required' => ['anker_ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ankerIds = array_values(array_filter(
            array_unique(array_map('intval', (array) ($arguments['anker_ids'] ?? []))),
            fn ($i) => $i > 0,
        ));
        if ($ankerIds === []) {
            return ToolResult::error('anker_ids ist Pflicht (mind. eine Anker-ID; für eine Kohäsions-Aussage mind. zwei). IDs via composer.ANKER_SUCHE holen.', 'VALIDATION_ERROR');
        }

        $svc = app(PairingService::class);
        $kohaesion = $svc->composerCohesion($ankerIds);

        // Netz nur für Zählwerte + Anker-Labels; nodes/edges (D3) werden verworfen.
        $counts = null;
        $labelMap = [];
        if (count($ankerIds) >= 2) {
            $netz = $svc->pairingNetzForAnkers($team, $ankerIds);
            $meta = $netz['meta'] ?? [];
            $counts = $meta['counts'] ?? null;
            foreach (($netz['nodes'] ?? []) as $n) {
                if (($n['kind'] ?? '') === 'anker' && isset($n['id'])) {
                    $aid = (int) str_replace('a:', '', (string) $n['id']);
                    $labelMap[$aid] = ['label' => $n['label'] ?? null, 'slug' => $n['slug'] ?? null];
                }
            }
        }

        // Erdung: welche echten GPs tragen die Anker als Kern (kern-Rolle), gruppiert je Anker.
        $byAnchor = [];
        foreach ($svc->gpsForAnkerIds($team, $ankerIds) as $row) {
            $byAnchor[(int) $row->anchor_id][] = $row;
        }
        $erdung = [];
        foreach ($ankerIds as $aid) {
            $rows = $byAnchor[$aid] ?? [];
            $erdung[] = [
                'anker_id' => $aid,
                'label' => $labelMap[$aid]['label'] ?? null,
                'slug' => $labelMap[$aid]['slug'] ?? null,
                'gp_count' => count($rows),
                'gps' => array_map(fn ($r) => [
                    'gp_id' => (int) $r->id,
                    'name' => (string) $r->name,
                    'is_favorite' => (bool) $r->is_favorite,
                    'status' => (string) $r->status,
                    'requires_la' => (bool) $r->requires_la,
                ], array_slice($rows, 0, 6)),
            ];
        }

        return ToolResult::success([
            'anker_ids' => $ankerIds,
            'kohaesion' => $kohaesion,
            'netz_counts' => $counts,
            'erdung' => $erdung,
            // Spec 60: dieselbe Kombinationslogik wie Gericht und Oberfläche (Anker als Einzel-Bestandteile).
            'kombination' => count($ankerIds) >= 2
                ? app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class)->datenAusAnkern($ankerIds, null, null, (int) $team->id)
                : null,
            'hinweis' => count($ankerIds) < 2
                ? 'Nur ein Anker — Kombination/Kohäsion brauchen mindestens zwei. Erdung (tragende GPs) trotzdem geliefert.'
                : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'composer', 'pairing', 'kohaesion', 'anker', 'bruecke', 'erdung'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.composer.MENUE_KOHAESION', 'foodalchemist.recipes.GENERATE'],
            'examples' => ['Hält diese Anker-Menge zusammen: [12, 44, 87]?', 'Welche GPs tragen diese Aroma-Anker?'],
        ];
    }
}
