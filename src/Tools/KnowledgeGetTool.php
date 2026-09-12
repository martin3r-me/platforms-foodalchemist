<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/** Phase K: Volltext eines Wissens-Dokuments (Markdown), truncierbar. */
class KnowledgeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.GET';
    }

    public function getDescription(): string
    {
        return 'Lädt ein Wissens-Dokument per slug (aus foodalchemist.knowledge.SEARCH) als Markdown. '
            . 'max_chars begrenzt die Länge (Default 8000).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'max_chars' => ['type' => 'integer', 'minimum' => 500, 'maximum' => 40000, 'default' => 8000],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(KnowledgeContextService::class);
        $doc = $svc->getDocument($team, (string) $arguments['slug']);
        if ($doc === null) {
            return ToolResult::error('Wissens-Dokument nicht gefunden.', 'NOT_FOUND');
        }
        $maxChars = min(40000, max(500, (int) ($arguments['max_chars'] ?? 8000)));

        return ToolResult::success([
            'slug' => $doc->slug,
            'title' => $doc->title,
            'category' => $doc->category,
            'art' => $doc->art ?? null,
            'geltung' => json_decode($doc->geltung ?? '[]', true),
            'datenwerte' => json_decode($doc->datenwerte ?? '[]', true),
            'version' => (int) $doc->version,
            'char_count' => (int) $doc->char_count,
            // Aliase MIT id: `knowledge.ALIAS action=remove` verlangt eine `alias_id`, und die gab
            // bis hierher kein Werkzeug aus — ein einmal gesetzter Alias war über MCP nicht mehr
            // zu finden und nicht mehr zu entfernen. Bei einer Kuration über den ganzen Korpus
            // waeren das hunderte Einbahn-Entscheidungen. Aliase wiegen schwer: ein Treffer gibt
            // +1,0 auf den lexikalischen Score, ein Jaccard-Treffer 0,05–0,3.
            'aliases' => DB::table('foodalchemist_knowledge_aliases')
                ->where('knowledge_document_id', $doc->id)->orderBy('alias_slug')
                ->get(['id', 'alias_slug'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'alias' => $a->alias_slug])->all(),
            'truncated' => mb_strlen($doc->content_md) > $maxChars,
            'content_md' => $svc->truncate($doc->content_md, $maxChars),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'dokument', 'detail'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.SEARCH'],
            'examples' => ['Lade das Dokument mengen_defaults'],
        ];
    }
}
