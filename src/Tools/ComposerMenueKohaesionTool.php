<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Composer-MCP: Ad-hoc-Menü-Kohäsion über MEHRERE Gerichte. Spiegelt {@see PairingService::menuCohesion}
 * (jedes Gericht = eine Komponente, deren Anker die Union seiner Zutaten-Anker sind). Antwortet auf
 * „hängt diese Gänge-/Menü-Folge aromatisch zusammen" — ein geteilter Anker zwischen zwei Gerichten
 * HEBT den Score (Menü-Faden), anders als die Wiederholungs-Warnung. Read-only, Gerichte team-scoped geladen.
 */
class ComposerMenueKohaesionTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.composer.MENUE_KOHAESION';
    }

    public function getDescription(): string
    {
        return 'Composer: misst die aromatische Kohäsion einer MENÜ-Folge aus mehreren Gerichten/Rezepten '
            . '(recipe_ids). Jedes Gericht ist eine Komponente; ihre Anker sind die Union der Zutaten-Anker. '
            . 'Liefert score/min_score/coverage, das schwächste Gang-Paar, Waisen-Gänge und unbewertete Paare '
            . '(gleiche Struktur wie composer.KOHAESION, nur eine Ebene höher). Ein geteilter Anker zwischen zwei '
            . 'Gängen hebt den Score (Menü-Faden). Mind. 2 team-sichtbare Rezepte. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'IDs der Gerichte/Rezepte der Menü-Folge (mind. 2, team-sichtbar).'],
            ],
            'required' => ['recipe_ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_filter(
            array_unique(array_map('intval', (array) ($arguments['recipe_ids'] ?? []))),
            fn ($i) => $i > 0,
        ));
        if (count($ids) < 2) {
            return ToolResult::error('recipe_ids: mind. zwei Gericht-/Rezept-IDs nötig — Menü-Kohäsion misst das Zusammenspiel MEHRERER Gerichte.', 'VALIDATION_ERROR');
        }

        $dishes = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $ids)->get();
        $gefunden = $dishes->pluck('id')->map(fn ($i) => (int) $i)->all();
        $fehlend = array_values(array_diff($ids, $gefunden));
        if ($dishes->count() < 2) {
            return ToolResult::error('Weniger als zwei team-sichtbare Rezepte gefunden — Menü-Kohäsion nicht messbar.', 'NOT_FOUND');
        }

        $kohaesion = app(PairingService::class)->menuCohesion($dishes->all());

        return ToolResult::success([
            'recipe_ids' => $gefunden,
            'fehlende_ids' => $fehlend,
            'kohaesion' => $kohaesion,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'composer', 'pairing', 'menue', 'kohaesion', 'gang'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.composer.KOHAESION', 'foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.pairings.SUGGEST'],
            'examples' => ['Hängt dieses 4-Gänge-Menü aromatisch zusammen: [3591, 3592, 3593, 3596]?'],
        ];
    }
}
