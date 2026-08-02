<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\MenuEngineeringService;

/**
 * Spec 32 · C3 (read): Menu-Engineering-Matrix — Popularität × Deckungsbeitrag je Gericht,
 * jeweils gegen den Portfolio-Durchschnitt (Star | Renner | Schläfer | Penner).
 *
 * Die Antwort trägt `quelle` mit: `sales` = echtes Verkaufs-Ist, `feedback` = menschliche
 * Akzeptanz als Ersatzachse. Das ist keine Kosmetik — eine Empfehlung auf Feedback-Basis
 * sagt etwas anderes als eine auf Absatzzahlen, und ein LLM muss das unterscheiden können.
 */
class MenuEngineeringGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.menu_engineering.GET';
    }

    public function getDescription(): string
    {
        return 'Menu-Engineering-Matrix: je Verkaufsgericht Popularität × Deckungsbeitrag, eingeordnet '
            . 'gegen den Portfolio-Durchschnitt in star (beliebt+ertragreich), renner (beliebt, wenig Ertrag), '
            . 'schlaefer (unbeliebt, ertragreich), penner (beides schwach). Optional von/bis (YYYY-MM-DD). '
            . 'Feld `quelle`: "sales" = echtes Verkaufs-Ist aus dem Verkaufsjournal, "feedback" = Praxis-Bewertungen '
            . 'als Ersatzachse, solange kein Verkaufs-Ist eingelesen ist (dann ist es Akzeptanz, NICHT Absatz). '
            . 'Deckungsbeitrag = sales_net − ek_portion an der Standard-Darreichung (gleiche Zahl wie die W%-Ampel).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'von' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'bis' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $von = ($arguments['von'] ?? '') !== '' ? (string) $arguments['von'] : null;
        $bis = ($arguments['bis'] ?? '') !== '' ? (string) $arguments['bis'] : null;

        return ToolResult::success(app(MenuEngineeringService::class)->matrix($team, $von, $bis));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'controlling', 'menu-engineering', 'deckungsbeitrag', 'popularitaet', 'verkauf'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.sales_facts.GET', 'foodalchemist.kalkulation.GET'],
            'examples' => ['Welche Gerichte sind Renner mit zu wenig Deckungsbeitrag?'],
        ];
    }
}
