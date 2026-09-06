<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 50 E-7: Kanon-Zeile ENTFERNEN (soft). Eigene Team-Zeilen; globale nur Master mit global=true.
 * Das Dossier selbst bleibt unangetastet — nur die Packlisten-Zeile fällt.
 */
class KnowledgeCanonDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_canon.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt EINE Kanon-Zeile (scope + scope_key + slug, optional role, Standard root). Das Dossier bleibt '
            . 'erhalten und weiter per Suche/Discovery findbar — nur die verbindliche Bindung ans Feature fällt. '
            . 'global=true trifft die globale Zeile (nur Master-Team).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scope', 'scope_key', 'slug'],
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => KnowledgeCanonService::SCOPES],
                'scope_key' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => KnowledgeCanonService::ROLES, 'default' => 'root'],
                'global' => ['type' => 'boolean'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $scope = trim((string) ($arguments['scope'] ?? ''));
        $scopeKey = trim((string) ($arguments['scope_key'] ?? ''));
        $slug = trim((string) ($arguments['slug'] ?? ''));
        $role = trim((string) ($arguments['role'] ?? 'root'));
        if (! in_array($scope, KnowledgeCanonService::SCOPES, true) || $scopeKey === '' || $slug === '') {
            return ToolResult::error('scope (feature|prompt_key), scope_key und slug sind Pflicht.', 'VALIDATION_ERROR');
        }
        if (! in_array($role, KnowledgeCanonService::ROLES, true)) {
            return ToolResult::error('role muss root oder child sein.', 'VALIDATION_ERROR');
        }

        try {
            $n = app(KnowledgeCanonService::class)->remove($team, $scope, $scopeKey, $slug, $role, (bool) ($arguments['global'] ?? false));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'deleted' => $n, 'scope' => $scope, 'scope_key' => $scopeKey, 'slug' => $slug, 'role' => $role,
            'hinweis' => $n > 0 ? 'Kanon-Zeile entfernt — Dossier bleibt per Suche findbar.' : 'Keine passende Kanon-Zeile gefunden.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'kanon', 'wissen', 'konfiguration'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_canon.GET', 'foodalchemist.knowledge_canon.PUT'],
            'examples' => ['Nimm regelwerk_concept aus dem Kanon von foodbook.plan'],
        ];
    }
}
