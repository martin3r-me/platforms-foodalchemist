<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * MCP: ein Wissens-Dokument aktiv/inaktiv schalten. Aktiv = fließt in den KI-Kontext;
 * inaktiv = aus dem Grounding raus (zum „Einstampfen" alter/überholter Docs). Reine
 * Kuration, KEIN Inhalts-Edit → anders als knowledge.PUT NICHT durch den Vault-Content-
 * Guard gesperrt (auch Vault-verwaltete Trends lassen sich so stilllegen; der Import setzt
 * den Flag nicht zurück). Nur das Besitzer-Team; geerbtes/globales Master-Wissen ist gesperrt.
 */
class KnowledgeSetActiveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.SET_ACTIVE';
    }

    public function getDescription(): string
    {
        return 'Schaltet EIN Wissens-Dokument aktiv oder inaktiv (slug + active). Aktiv → fließt ins '
            . 'KI-Grounding; inaktiv → raus (zum Einstampfen alter/überholter Trends, auch Vault-verwalteter). '
            . 'Reine Kuration, kein Inhalts-Edit — daher NICHT durch den Vault-Content-Guard gesperrt; der '
            . 'knowledge-import setzt den Flag nicht zurück. Nur das Besitzer-Team darf (de)aktivieren; '
            . 'geerbtes/globales Master-Wissen ist gesperrt. Inaktive Docs auflisten: knowledge.LIST (mit '
            . 'include_inactive, sofern verfügbar) — sonst per slug direkt schalten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['slug', 'active'],
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug des Wissens-Dokuments (aus knowledge.SEARCH/LIST/GET)'],
                'active' => ['type' => 'boolean', 'description' => 'true → aktivieren (ins Grounding), false → deaktivieren (raus)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $slug = trim((string) ($arguments['slug'] ?? ''));
        if ($slug === '') {
            return ToolResult::error('slug ist Pflicht.', 'VALIDATION_ERROR');
        }
        if (! array_key_exists('active', $arguments)) {
            return ToolResult::error('active (true/false) ist Pflicht.', 'VALIDATION_ERROR');
        }
        $active = (bool) $arguments['active'];

        try {
            $doc = app(KnowledgeService::class)->setActive($team, $slug, $active);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'nicht gefunden') ? 'NOT_FOUND' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }

        $status = $doc['active'] ? 'aktiv' : 'inaktiv';
        $hinweis = $doc['changed']
            ? "«{$doc['slug']}» ist jetzt {$status}."
            : "«{$doc['slug']}» war bereits {$status} — keine Änderung.";
        if ($doc['active']) {
            $hinweis .= ' Wirkt beim nächsten KI-Kontext-Bau.';
        }

        return ToolResult::success(['document' => $doc, 'hinweis' => $hinweis]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'aktivierung', 'kuration', 'einstampfen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.LIST', 'foodalchemist.knowledge.SEARCH', 'foodalchemist.knowledge.PUT'],
            'examples' => ['Deaktiviere das Doc trend.alte-fermentation-2024', 'Aktiviere meinen Entwurf know-how.sous-vide'],
        ];
    }
}
