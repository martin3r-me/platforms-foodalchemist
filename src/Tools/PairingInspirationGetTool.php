<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PairingInspirationService;

/**
 * Spec 19 E9.5 (Nachtrag) — Pairing-Inspiration für die Kreativ-Phase lesen. READ-ONLY.
 *
 * Gibt einer KI dieselbe Inspiration wie der Kreativ-Tab: Aroma-Nachbarn eines Seeds,
 * je nach Kreativ-Modus abstrakt (voll_kreativ) oder geerdet (hybrid/datenbank: welche
 * echten GPs das Aroma tragen + Verfügbarkeits-Bucket führen/leicht/Lücke). Der Modus
 * wird aus dem Kapitel aufgelöst (Kaskade Kapitel→Foodbook→hybrid), wenn `chapter_id`
 * angegeben ist; sonst optional per `modus` überschreibbar, sonst 'hybrid'.
 *
 * Erdet nichts, legt nichts an. Das bewusste Melden einer Sortiments-Lücke ist ein
 * separater, human-getriggerter Schreibpfad (Kreativ-Tab „Lücke melden") — NICHT hier.
 */
class PairingInspirationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pairing_inspiration.GET';
    }

    public function getDescription(): string
    {
        return 'Pairing-Inspiration für die Kreativ-Phase lesen (Spec 19 E9). READ-ONLY. Aroma-Nachbarn zu einem '
            . 'Seed (search=Freitext ODER seeds=Anker-Slugs), je nach Kreativ-Modus abstrakt (voll_kreativ) oder '
            . 'geerdet (hybrid/datenbank: tragende GPs + Bucket führen/leicht/luecke, Nachbar-luecke-Flag). Modus aus '
            . 'chapter_id aufgelöst (Kaskade Kapitel→Foodbook→hybrid) oder per modus überschrieben. Erdet/legt nichts an.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Freitext-Aroma/Zutat — wird zu Anker-Slug(s) aufgelöst. Alternativ zu seeds.'],
                'seeds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Explizite Aroma-Anker-Slugs. Alternativ zu search.',
                ],
                'chapter_id' => ['type' => 'integer', 'description' => 'Kapitel (team-sichtbar) — löst den Kreativ-Modus per Kaskade auf.'],
                'modus' => ['type' => 'string', 'enum' => FoodAlchemistFoodbookKapitel::CREATIVE_MODES, 'description' => 'Modus-Override voll_kreativ|hybrid|datenbank (sonst aus chapter_id oder Default hybrid).'],
                'limit_pro_seed' => ['type' => 'integer', 'description' => 'Nachbarn je Seed (Default 8).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $svc = app(PairingInspirationService::class);

        // Seeds: explizit ODER aus Freitext aufgelöst.
        $seeds = array_values(array_filter(array_map(
            static fn ($s) => trim((string) $s),
            (array) ($arguments['seeds'] ?? [])
        )));
        if ($seeds === [] && trim((string) ($arguments['search'] ?? '')) !== '') {
            $seeds = $svc->sucheAnker((string) $arguments['search'], 5)->pluck('slug')->all();
        }
        if ($seeds === []) {
            return ToolResult::error('search (Freitext) oder seeds (Anker-Slugs) erforderlich.', 'VALIDATION_ERROR');
        }

        // Modus: aus Kapitel (Kaskade) ODER Override ODER Default.
        $modus = null;
        $modusQuelle = 'default';
        if (isset($arguments['chapter_id'])) {
            $kapitel = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $arguments['chapter_id']);
            if ($kapitel === null) {
                return ToolResult::error('Kapitel nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
            $aufgeloest = app(FoodbookService::class)->kreativModus($team, $kapitel);
            $modus = $aufgeloest['modus'];
            $modusQuelle = 'kapitel:' . $aufgeloest['quelle'];
        } elseif (isset($arguments['modus'])) {
            if (! in_array((string) $arguments['modus'], FoodAlchemistFoodbookKapitel::CREATIVE_MODES, true)) {
                return ToolResult::error('modus muss voll_kreativ|hybrid|datenbank sein.', 'VALIDATION_ERROR');
            }
            $modus = (string) $arguments['modus'];
            $modusQuelle = 'override';
        } else {
            $modus = FoodAlchemistFoodbookKapitel::CREATIVE_MODE_DEFAULT;
        }

        $limit = isset($arguments['limit_pro_seed']) ? max(1, min(30, (int) $arguments['limit_pro_seed'])) : 8;
        $result = $svc->inspiration($team, $seeds, $modus, $limit);
        $result['modus_quelle'] = $modusQuelle;

        return ToolResult::success(['pairing_inspiration' => $result]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'foodbook', 'kreativ', 'pairing', 'inspiration', 'aroma'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook_kapitel.PUT', 'foodalchemist.kapitel_ideen.GET'],
            'examples' => ['Was passt aromatisch zu Zander im Kapitel 40?', 'Zeig Aroma-Nachbarn zu rote_bete, abstrakt (voll_kreativ).'],
        ];
    }
}
