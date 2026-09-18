<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\WissenGesperrtException;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * MCP-Steuerbarkeit · D12: Alias eines team-eigenen Wissensdokuments hinzufügen/entfernen (action-enum).
 *
 * ★ Briefing Zutaten-Bulk-Import, 2026-09-18: `action=check` (rein lesend, keine Team-Bindung
 * nötig — Aliasse sind systemweit eindeutig) beantwortet je Kandidat, ob er schon belegt ist und
 * auf welchem Dossier. Anlass: `add` schluckte eine Kollision bisher STILLSCHWEIGEND — das Dossier
 * wurde angelegt, der Alias blieb einfach weg. Bei 2.628 Zutatennamen sind hunderte Kollisionen zu
 * erwarten; vorher prüfen statt hinterher raten.
 */
class KnowledgeAliasTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.ALIAS';
    }

    public function getDescription(): string
    {
        return 'Pflegt Aliasse eines team-eigenen Wissensdokuments. action=add: slug + alias (Text). '
            . 'action=remove: alias_id. action=check: aliases[] (bis '.KnowledgeService::ALIAS_CHECK_MAX.') — '
            . 'rein lesend, meldet je Kandidat belegt (true/false) und auf welchem Dossier (slug + title). '
            . 'Vor einem Massen-add IMMER erst check fahren: Aliasse sind systemweit eindeutig, add '
            . 'schluckt eine Kollision sonst stillschweigend (Dossier wird angelegt, Alias bleibt weg). '
            . 'Aliasse verbessern die deterministische Wissens-Auflösung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'remove', 'check'], 'description' => 'Hinzufügen, entfernen oder (rein lesend) Belegung prüfen.'],
                'slug' => ['type' => 'string', 'description' => 'Doc-Slug (bei action=add).'],
                'alias' => ['type' => 'string', 'description' => 'Alias-Text (bei action=add; wird zu Slug normalisiert).'],
                'alias_id' => ['type' => 'integer', 'description' => 'Alias-Id (bei action=remove).'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Alias-Kandidaten (bei action=check), bis '.KnowledgeService::ALIAS_CHECK_MAX.'.'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $action = (string) ($arguments['action'] ?? '');
        $svc = app(KnowledgeService::class);

        if ($action === 'check') {
            $aliases = $arguments['aliases'] ?? null;
            if (! is_array($aliases) || $aliases === []) {
                return ToolResult::error('aliases[] ist Pflicht bei action=check.', 'VALIDATION_ERROR');
            }
            if (count($aliases) > KnowledgeService::ALIAS_CHECK_MAX) {
                return ToolResult::error(sprintf('Hoechstens %d Alias-Kandidaten je Aufruf.', KnowledgeService::ALIAS_CHECK_MAX), 'VALIDATION_ERROR');
            }
            $ergebnisse = $svc->checkAliases($aliases);

            return ToolResult::success([
                'geprueft' => count($ergebnisse),
                'belegt' => count(array_filter($ergebnisse, fn ($r) => $r['belegt'])),
                'eintraege' => $ergebnisse,
            ]);
        }

        try {
            if ($action === 'add') {
                $slug = trim((string) ($arguments['slug'] ?? ''));
                if ($slug === '') {
                    return ToolResult::error('slug ist Pflicht bei action=add.', 'VALIDATION_ERROR');
                }
                $aliasSlug = $svc->addAlias($team, $slug, (string) ($arguments['alias'] ?? ''));

                return ToolResult::success(['action' => 'add', 'slug' => $slug, 'alias_slug' => $aliasSlug]);
            }
            if ($action === 'remove') {
                $aliasId = (int) ($arguments['alias_id'] ?? 0);
                if ($aliasId <= 0) {
                    return ToolResult::error('alias_id ist Pflicht bei action=remove.', 'VALIDATION_ERROR');
                }
                $svc->removeAlias($team, $aliasId);

                return ToolResult::success(['action' => 'remove', 'alias_id' => $aliasId, 'removed' => true]);
            }
        } catch (WissenGesperrtException $e) {
            return ToolResult::error($e->getMessage(), 'LOCKED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::error('action muss add, remove oder check sein.', 'VALIDATION_ERROR');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'knowledge', 'alias', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes'],
            'related_tools' => ['foodalchemist.knowledge.DELETE'],
            'examples' => ['Füge dem Doc "cross_cutting.mengen" den Alias "portionen" hinzu.'],
        ];
    }
}
