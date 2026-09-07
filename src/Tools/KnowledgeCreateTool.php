<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * #469 v3: neues Wissens-Dokument „von außen" anlegen (Trends/Know-how), created_via='mcp'.
 * Kategorie muss im Vokabular stehen (foodalchemist.settings.GET / Browser). Optional Aliase
 * + Einsatzort-Bindungen.
 *
 * **Aktiv als Default (Entscheid Dominique 2026-09-07).** Bis dahin landete jede Anlage in
 * Quarantäne und musste im Browser freigeschaltet werden. Gedacht als Schutz, gewirkt als
 * stille Falle: ein vergessenes `SET_ACTIVE` hinterlässt ein fertiges Dossier, das nirgends
 * wirkt — und man sieht ihm nicht an, ob es Absicht war. Wer einen Entwurf will, setzt
 * `active: false`; dann ist die Quarantäne eine Entscheidung statt eines Standards.
 */
class KnowledgeCreateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein neues Wissens-Dokument an (created_via=mcp) — z. B. einen Trend oder '
            . 'Know-how-Baustein. Es ist SOFORT AKTIV und wirkt im KI-Kontext seiner Kategorie; mit '
            . 'active=false entsteht stattdessen ein Entwurf, den ein Mensch im Wissens-Browser freischaltet. '
            . 'category muss ein bestehender Kategorie-Slug sein. Optional: aliases (Findbarkeit) '
            . 'und bind_layers (an Einsatzorte binden: target_key = Bereich/Prompt-Slug, mode). '
            . 'Vault-Regelwerke NICHT hier neu anlegen — die kommen aus dem Vault-Import.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'category' => ['type' => 'string', 'description' => 'Kategorie-Slug aus dem Vokabular, z. B. trend, domain, cross_cutting, workflow, concept'],
                'art' => ['type' => 'string', 'description' => 'Wissensart — steuert, WIE das Wissen benutzt werden darf (die Kategorie sagt nur, WORUM es geht): regel (verbindlich) | datenwerk (Nachschlagewerk: wird ueber Achsen aufgeloest, nicht gesucht) | fachwissen (echter Suchfall) | referenz (Inspiration) | ablauf (Anleitung fuer AGENTEN — gehoert in KEINEN Prompt). Weglassen = noch nicht eingeordnet.'],
                'slug' => ['type' => 'string', 'description' => 'Optionaler expliziter Slug (sonst aus Titel). Für Vault-Konsistenz nutzen, z. B. workflow.rezept_anlegen_mcp — dann reconciled ein späterer Vault-Import statt zu duplizieren.'],
                'content_md' => ['type' => 'string', 'description' => 'Inhalt als Markdown'],
                'active' => ['type' => 'boolean', 'default' => true, 'description' => 'Default true: wirkt sofort im KI-Kontext. false = Entwurf in Quarantäne, den ein Mensch freischaltet.'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Begriffe, unter denen die KI das Doc findet'],
                'bind_layers' => [
                    'type' => 'array',
                    'description' => 'Einsatzort-Bindungen (optional)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'target_key' => ['type' => 'string', 'description' => 'Slug eines Einsatzorts (Bereich wie gp/recipe/vk oder einzelner Prompt)'],
                            'mode' => ['type' => 'string', 'enum' => ['always', 'discovery', 'grounding', 'reference'], 'default' => 'discovery'],
                        ],
                        'required' => ['target_key'],
                    ],
                ],
            ],
            'required' => ['title', 'category'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $doc = app(KnowledgeService::class)->create($team, [
                'title' => (string) $arguments['title'],
                'category' => (string) $arguments['category'],
                'art' => $arguments['art'] ?? null,
                'slug' => isset($arguments['slug']) ? (string) $arguments['slug'] : null,
                'content_md' => $arguments['content_md'] ?? '',
                'aliases' => $arguments['aliases'] ?? [],
                'bind_layers' => $arguments['bind_layers'] ?? [],
                'active' => array_key_exists('active', $arguments) ? (bool) $arguments['active'] : true,
                'source' => 'mcp',
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'document' => [
                'slug' => $doc->slug,
                'title' => $doc->title,
                'category' => $doc->category,
                'art' => $doc->art ?? null,
                'version' => (int) $doc->version,
                'active' => (bool) $doc->active,
                'created_via' => $doc->created_via,
            ],
            'note' => $doc->active
                ? 'Aktiv — wirkt ab dem nächsten KI-Kontext-Bau in seiner Kategorie.'
                : 'Entwurf (inaktiv, weil active=false gesetzt wurde). Freischalten macht ein Mensch im Wissens-Browser.',
            // Spec 50 Strang III: Deckel erinnert, blockiert nicht.
            'hinweis' => app(KnowledgeCanonService::class)->groessenHinweis((int) $doc->char_count, (string) ($arguments['content_md'] ?? '')),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'anlegen', 'trend', 'mcp'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.PUT', 'foodalchemist.knowledge.SEARCH', 'foodalchemist.knowledge.GET'],
            'examples' => [
                'Lege ein Wissens-Dokument zum Trend "Fermentierte Chili-Pasten" an',
                'Lege es als Entwurf an, ich will es erst lesen (active=false)',
            ],
        ];
    }
}
