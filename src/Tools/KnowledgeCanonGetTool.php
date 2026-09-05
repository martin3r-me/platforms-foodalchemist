<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 50 E-7: Kanon LESEN — welche Dossiers ein Feature/Prompt-Key verbindlich mitbekommt.
 * Sicht = globale Zeilen ∪ eigene Team-Zeilen (Ahnenkette), ohne Volltext (Packliste).
 */
class KnowledgeCanonGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_canon.GET';
    }

    public function getDescription(): string
    {
        return 'Listet den Wissens-KANON: welche Dossiers (Slugs) ein KI-Feature bzw. Prompt-Key verbindlich in den '
            . 'Prompt bekommt (mode pflicht|wenn_platz, Reihenfolge ord). Filter scope (feature|prompt_key), '
            . 'scope_key, role (root|child). Zeigt globale + eigene Team-Zeilen (global=true/false). Kein Kanon = '
            . 'leere Liste → Generator fällt auf ganze Docs per always-Routing zurück. Volltext via knowledge.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => KnowledgeCanonService::SCOPES],
                'scope_key' => ['type' => 'string', 'description' => 'Feature-Name (z. B. ai_generate_recipe) oder Prompt-Key'],
                'role' => ['type' => 'string', 'enum' => KnowledgeCanonService::ROLES, 'description' => 'root = Hauptgericht/Top-Level, child = Sub-Rezepte in der Kaskade'],
                'include_inactive' => ['type' => 'boolean', 'description' => 'true → auch deaktivierte Kanon-Zeilen'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $scope = isset($arguments['scope']) ? trim((string) $arguments['scope']) : null;
        if ($scope !== null && ! in_array($scope, KnowledgeCanonService::SCOPES, true)) {
            return ToolResult::error('scope muss feature oder prompt_key sein.', 'VALIDATION_ERROR');
        }

        $svc = app(KnowledgeCanonService::class);
        $zeilen = $svc->list(
            $team, $scope,
            isset($arguments['scope_key']) ? trim((string) $arguments['scope_key']) : null,
            isset($arguments['role']) ? trim((string) $arguments['role']) : null,
            (bool) ($arguments['include_inactive'] ?? false),
        );

        $zuGross = array_values(array_filter($zeilen, fn ($z) => $z['char_count'] > $svc->dossierMaxChars()));

        return ToolResult::success([
            'total' => count($zeilen),
            'dossier_max_chars' => $svc->dossierMaxChars(),
            'canon' => $zeilen,
            'hinweis' => $zuGross === []
                ? null
                : sprintf('%d Kanon-Dossier(s) über dem Deckel — teilen: %s', count($zuGross), implode(', ', array_column($zuGross, 'slug'))),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'kanon', 'wissen', 'konfiguration'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_canon.PUT', 'foodalchemist.knowledge_canon.DELETE', 'foodalchemist.knowledge_routings.GET', 'foodalchemist.knowledge.GET'],
            'examples' => ['Welche Dossiers bekommt ai_generate_recipe verbindlich?', 'Kanon für scope_key vk.generator, role child'],
        ];
    }
}
