<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * Löst eine Alt-Bindung (#469) — der einzige verbliebene Bindungs-Schreibpfad.
 *
 * Sein Gegenstück `knowledge.BIND` ist mit Spec 52 · F2/F3 GELÖSCHT: der Gateway liest
 * `knowledge_bindings` nicht mehr, eine neue Bindung wäre eine Zeile, die nichts tut.
 * Dieses Tool bleibt, weil der Rückweg offen bleiben muss — auf demo standen zum Zeitpunkt
 * der Abschaffung 9 Alt-Bindungen, alle wirkungslos, die jemand loswerden können soll.
 *
 * Nur team-eigene Bindungen; globale/Fremd-Bindungen bleiben unberührt. Soft-Delete,
 * idempotent.
 */
class KnowledgeUnbindTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.UNBIND';
    }

    public function getDescription(): string
    {
        return 'Löst eine Einsatzort-Bindung eines Wissens-Dokuments — AUFRÄUM-Werkzeug für Alt-Bindungen. '
            . 'Bindungen wirken seit Spec 52 nicht mehr; `knowledge.BIND` ist entfernt. Verbindlich machen: '
            . '`knowledge_canon.PUT`; suchbar machen: `knowledge_routings.PUT`. Welche Alt-Bindungen es noch '
            . 'gibt, zeigt `knowledge_bindings.GET`. Entfernt nur team-eigene Bindungen; existiert keine '
            . 'passende (aktive), passiert nichts (idempotent).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug des Dokuments'],
                'target_key' => ['type' => 'string', 'description' => 'Einsatzort-Slug, dessen Bindung gelöst werden soll'],
            ],
            'required' => ['slug', 'target_key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $removed = app(KnowledgeService::class)->unbindExisting(
                $team,
                (string) $arguments['slug'],
                (string) $arguments['target_key'],
            );
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'nicht gefunden') ? 'NOT_FOUND' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }

        return ToolResult::success([
            'slug' => (string) $arguments['slug'],
            'target_key' => (string) $arguments['target_key'],
            'removed' => $removed,
            'note' => $removed ? 'Bindung gelöst.' : 'Keine passende team-eigene Bindung gefunden — nichts geändert.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'binden', 'loesen', 'einsatzort', 'layer', 'mcp'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['deletes'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_bindings.GET', 'foodalchemist.knowledge.SEARCH'],
            'examples' => ['Löse die Bindung von "regelwerk_grundprodukte" am Einsatzort "gp.suggest"'],
        ];
    }
}
