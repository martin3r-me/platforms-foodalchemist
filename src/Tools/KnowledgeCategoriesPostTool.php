<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * MCP: neue Wissens-Kategorie anlegen (team-scoped). Schließt die Lücke, dass das
 * Kategorie-Vokabular sonst nur unter Einstellungen pflegbar war — knowledge.POST/PUT
 * lehnte neue Slugs ab. Die neue Kategorie ist SOFORT aktiv/nutzbar; Dokumente darin
 * bleiben wie gehabt in Quarantäne (ein Mensch aktiviert sie).
 */
class KnowledgeCategoriesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_categories.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine neue Wissens-Kategorie an (team-scoped, sofort aktiv → unmittelbar als '
            . 'category in knowledge.POST/PUT nutzbar). Der Slug wird aus dem label gebildet; Dubletten '
            . '(globales Vokabular oder eigenes Team) werden abgewiesen. Für Vokabular-Wachstum ohne '
            . 'UI-Schritt. Danach ggf. via knowledge_routings.PUT ins Auto-Grounding routen. Kategorien '
            . 'auflisten: knowledge_categories.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['label'],
            'properties' => [
                'label' => ['type' => 'string', 'description' => 'Anzeigename, z. B. "Ernährung". Der Slug wird daraus abgeleitet (ASCII) — sofern nicht slug gesetzt ist'],
                'slug' => ['type' => 'string', 'description' => 'Optionaler expliziter Slug (für deutsche Formen wie ernaehrung/geschaeftsmodell) — sonst aus dem Label'],
                'description' => ['type' => 'string', 'description' => 'Optionale Kurzbeschreibung der Kategorie'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $label = trim((string) ($arguments['label'] ?? ''));
        if ($label === '') {
            return ToolResult::error('label ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $kategorie = app(KnowledgeService::class)->createCategory(
                $team,
                $label,
                isset($arguments['description']) ? (string) $arguments['description'] : null,
                isset($arguments['slug']) ? (string) $arguments['slug'] : null,
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'category' => $kategorie,
            'hinweis' => 'Kategorie angelegt und aktiv — sofort als category in knowledge.POST/PUT nutzbar. '
                . 'Für Auto-Grounding zusätzlich knowledge_routings.PUT setzen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'kategorie', 'vokabular', 'konfiguration'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_categories.GET', 'foodalchemist.knowledge.POST', 'foodalchemist.knowledge_routings.PUT'],
            'examples' => ['Lege die Kategorie "Ernährung" an', 'Neue Kategorie Geschäftsmodell mit Beschreibung anlegen'],
        ];
    }
}
