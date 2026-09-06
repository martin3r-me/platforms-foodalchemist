<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 50 E-7: Kanon-Zeile SETZEN — ein Dossier (Slug) verbindlich an ein Feature/Prompt-Key hängen.
 * Upsert; Guards: globale Zeile nur Master + nur globale Dossiers; kein Changelog im Kanon-Dossier;
 * Größen-Hinweis über dem Deckel (nicht blockierend — Kuration entscheidet).
 */
class KnowledgeCanonPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_canon.PUT';
    }

    public function getDescription(): string
    {
        return 'Hängt EIN Wissens-Dossier (slug) verbindlich an ein KI-Feature oder einen Prompt-Key (Upsert). '
            . 'scope ∈ feature|prompt_key, scope_key (z. B. ai_generate_recipe), slug Pflicht. mode pflicht (immer im '
            . 'Prompt) | wenn_platz (nur wenn Budget reicht), ord = Reihenfolge (leer = ans Ende), role root|child. '
            . 'global=true legt eine GLOBALE Zeile an (nur Master-Team, nur globale Dossiers). Abgelehnt: Dossiers '
            . 'mit „## Changelog" im Body (Kurationsregel — Changelog gehört nie in den Prompt). Dossiers über dem '
            . 'Deckel (Standard 4000 Zeichen) werden angenommen, aber mit Hinweis: ein Thema pro Dossier, teilen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scope', 'scope_key', 'slug'],
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => KnowledgeCanonService::SCOPES],
                'scope_key' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Feature-Name oder Prompt-Key'],
                'slug' => ['type' => 'string', 'description' => 'Slug des Wissens-Dossiers (knowledge.LIST/SEARCH)'],
                'role' => ['type' => 'string', 'enum' => KnowledgeCanonService::ROLES, 'default' => 'root'],
                'mode' => ['type' => 'string', 'enum' => KnowledgeCanonService::MODES, 'default' => 'pflicht'],
                'ord' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Reihenfolge im Prompt (leer = hinten anfügen)'],
                'active' => ['type' => 'boolean', 'default' => true],
                'global' => ['type' => 'boolean', 'description' => 'true → globale Kanon-Zeile (team_id NULL; nur Master-Team)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $res = app(KnowledgeCanonService::class)->set($team, $arguments);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'canon' => $res['zeile'],
            'hinweise' => $res['hinweise'],
            'hinweis' => 'Gesetzt — wirkt sofort beim nächsten KI-Kontext-Bau.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'kanon', 'wissen', 'konfiguration'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_canon.GET', 'foodalchemist.knowledge_canon.DELETE', 'foodalchemist.knowledge.GET'],
            'examples' => [
                'Hänge regelwerk_basisrezepte_p1_naming als pflicht an ai_generate_recipe (ord 10)',
                'Kanon für vk.generator: regelwerk_verkaufsgerichte_p3 als wenn_platz',
            ],
        ];
    }
}
