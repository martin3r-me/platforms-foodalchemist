<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;

/**
 * S1b: Wissens-Routing SETZEN/ENTFERNEN zur Laufzeit — eine Kategorie ohne Code-Deploy routen
 * oder deckeln. Globaler Master, wirkt sofort beim nächsten KI-Kontext-Bau.
 */
class KnowledgeRoutingsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_routings.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt oder entfernt EIN Wissens-Routing (globaler Master, wirkt sofort). feature + category '
            . 'Pflicht. mode ∈ always|discovery|grounding|none — discovery für WACHSENDE Kategorien (gedeckelt, '
            . 'skalierbar), always nur für kleine fixe Sets (jede Doc immer im Prompt = Bloat), none = bewusst '
            . 'leer. Optional max_docs / max_chars_per_doc als Cap (leer/0 = Service-Default). Mit delete=true wird '
            . 'das Routing entfernt → die Kategorie ist dann search-only (nur Browser/Suche, kein Auto-Grounding).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['feature', 'category'],
            'properties' => [
                'feature' => ['type' => 'string', 'description' => 'KI-Feature, z. B. ai_generate_recipe'],
                'category' => ['type' => 'string', 'description' => 'Wissens-Kategorie, z. B. niveau, kueche, domain'],
                'mode' => ['type' => 'string', 'enum' => KnowledgeRoutingService::MODES, 'description' => 'Lade-Modus (bei delete=true ignoriert)'],
                'max_docs' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Cap Top-K (leer = Service-Default)'],
                'max_chars_per_doc' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Cap Zeichen je Doc (leer = Service-Default)'],
                'delete' => ['type' => 'boolean', 'description' => 'true → Routing entfernen (Kategorie wird search-only)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $feature = trim((string) ($arguments['feature'] ?? ''));
        $category = trim((string) ($arguments['category'] ?? ''));
        if ($feature === '' || $category === '') {
            return ToolResult::error('feature und category sind Pflicht.', 'VALIDATION_ERROR');
        }

        $svc = app(KnowledgeRoutingService::class);

        if (($arguments['delete'] ?? false) === true) {
            $n = $svc->remove($feature, $category);

            return ToolResult::success([
                'deleted' => $n, 'feature' => $feature, 'category' => $category,
                'hinweis' => $n > 0 ? 'Routing entfernt — Kategorie ist jetzt search-only.' : 'Kein passendes Routing gefunden.',
            ]);
        }

        try {
            $row = $svc->set(
                $feature, $category, (string) ($arguments['mode'] ?? ''),
                isset($arguments['max_docs']) ? (int) $arguments['max_docs'] : null,
                isset($arguments['max_chars_per_doc']) ? (int) $arguments['max_chars_per_doc'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['routing' => $row, 'hinweis' => 'Gesetzt — wirkt sofort beim nächsten KI-Kontext-Bau.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'routing', 'wissen', 'konfiguration'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_routings.GET'],
            'examples' => ['Route niveau für ai_generate_recipe als discovery mit max_docs 1', 'Entferne trend aus foodbook.plan (delete)'],
        ];
    }
}
