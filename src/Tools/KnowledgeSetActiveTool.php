<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\WissenGesperrtException;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * MCP: ein Wissens-Dokument aktiv/inaktiv schalten. Aktiv = fließt in den KI-Kontext;
 * inaktiv = aus dem Grounding raus (zum „Einstampfen" alter/überholter Docs). Reine
 * Kuration, KEIN Inhalts-Edit → anders als knowledge.PUT NICHT durch den Vault-Content-
 * Guard gesperrt (auch Vault-verwaltete Trends lassen sich so stilllegen; der Import setzt
 * den Flag nicht zurück). Nur das Besitzer-Team; geerbtes/globales Master-Wissen ist gesperrt.
 *
 * ★ Briefing Zutaten-Bulk-Import, 2026-09-18: `slugs[]` (bis zu {@see KnowledgeService::SET_ACTIVE_BATCH_MAX})
 * aktiviert blockweise über {@see KnowledgeService::setActiveBatch()} — Embedding läuft dabei
 * gestaffelt (Chunks, siehe dort), nie als Burst. `slug` (Einzahl) bleibt unverändert für den
 * Einzelfall; genau eines von `slug`/`slugs` ist Pflicht.
 */
class KnowledgeSetActiveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.SET_ACTIVE';
    }

    public function getDescription(): string
    {
        return 'Schaltet ein Wissens-Dokument (slug) ODER mehrere in einem Block (slugs[], bis '
            . KnowledgeService::SET_ACTIVE_BATCH_MAX . ') aktiv oder inaktiv. Aktiv → fließt ins '
            . 'KI-Grounding; inaktiv → raus (zum Einstampfen alter/überholter Trends, auch Vault-verwalteter). '
            . 'Reine Kuration, kein Inhalts-Edit — daher NICHT durch den Vault-Content-Guard gesperrt; der '
            . 'knowledge-import setzt den Flag nicht zurück. Nur das Besitzer-Team darf (de)aktivieren; '
            . 'geerbtes/globales Master-Wissen ist gesperrt. Bei slugs[] + active=true laeuft das Embedding '
            . 'gestaffelt in Chunks (kein Burst auf Queue/Provider) — die Antwort nennt embedding_fertig_ca. '
            . 'Inaktive Docs auflisten: knowledge.LIST (mit include_inactive, sofern verfügbar).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['active'],
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug EINES Wissens-Dokuments (aus knowledge.SEARCH/LIST/GET). Genau eines von slug/slugs.'],
                'slugs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Mehrere Slugs fuer blockweises (De-)Aktivieren, bis '.KnowledgeService::SET_ACTIVE_BATCH_MAX.'.'],
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
        if (! array_key_exists('active', $arguments)) {
            return ToolResult::error('active (true/false) ist Pflicht.', 'VALIDATION_ERROR');
        }
        $active = (bool) $arguments['active'];
        $hatSlug = is_string($arguments['slug'] ?? null) && trim($arguments['slug']) !== '';
        $hatSlugs = is_array($arguments['slugs'] ?? null) && $arguments['slugs'] !== [];

        if ($hatSlug === $hatSlugs) {
            return ToolResult::error('Genau eines von slug (einzeln) oder slugs[] (Block) angeben.', 'VALIDATION_ERROR');
        }

        if ($hatSlug) {
            $slug = trim((string) $arguments['slug']);
            try {
                $doc = app(KnowledgeService::class)->setActive($team, $slug, $active);
            } catch (WissenGesperrtException $e) {
                return ToolResult::error($e->getMessage(), 'LOCKED');
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

        $slugs = $arguments['slugs'];
        if (count($slugs) > KnowledgeService::SET_ACTIVE_BATCH_MAX) {
            return ToolResult::error(sprintf('Hoechstens %d Slugs je Aufruf. Bitte in Bloecken fahren.', KnowledgeService::SET_ACTIVE_BATCH_MAX), 'VALIDATION_ERROR');
        }
        $ergebnis = app(KnowledgeService::class)->setActiveBatch($team, $slugs, $active);
        $zaehler = ['geaendert' => 0, 'unveraendert' => 0, 'nicht_gefunden' => 0, 'gesperrt' => 0];
        foreach ($ergebnis['eintraege'] as $r) {
            $zaehler[$r['status']] = ($zaehler[$r['status']] ?? 0) + 1;
        }
        $hinweis = "{$zaehler['geaendert']} Dossiers auf ".($active ? 'aktiv' : 'inaktiv').' geschaltet.';
        if ($ergebnis['embedding_fertig_ca'] !== null) {
            $hinweis .= " Embedding-Jobs gestaffelt eingereiht, fertig ca. {$ergebnis['embedding_fertig_ca']}.";
        }

        return ToolResult::success([...$zaehler, 'eintraege' => $ergebnis['eintraege'], 'embedding_fertig_ca' => $ergebnis['embedding_fertig_ca'], 'hinweis' => $hinweis]);
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
