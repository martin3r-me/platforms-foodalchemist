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
            . 'category muss ein bestehender Kategorie-Slug sein. Optional: aliases (Findbarkeit). '
            . 'Damit das Dossier VERBINDLICH in einen Prompt kommt, danach `knowledge_canon.PUT`; damit '
            . 'seine Kategorie überhaupt gesucht wird, `knowledge_routings.PUT`. Einsatzort-Bindungen '
            . 'gibt es nicht mehr (Spec 52). '
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
                'geltung' => ['type' => 'object', 'description' => 'UND zwischen Achsen, ODER zwischen Werten: gang, komponentenrolle, portionskontext, niveau, saison, warengruppe, occasion, sektor, format. Werte als Listen von Strings. Leer = uneingeschränkt.'],
                'datenwerte' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'kennzahl' => ['type' => 'string'], 'min' => ['type' => 'number'], 'max' => ['type' => 'number'],
                    'einheit' => ['type' => 'string'], 'bezug' => ['type' => 'string', 'description' => 'Zum Beispiel Rohgewicht pro Portion oder verzehrfertig pro Ansatz.'],
                    'quelle' => ['type' => 'string'], 'geltung' => ['type' => 'object'],
                ], 'required' => ['kennzahl', 'min', 'max', 'einheit', 'bezug', 'quelle']], 'description' => 'Nur Datenwerke. Einzelwert: min=max. Quelle und Dossierversion bleiben nachvollziehbar.'],
                'content_md' => ['type' => 'string', 'description' => 'Inhalt als Markdown'],
                'active' => ['type' => 'boolean', 'default' => true, 'description' => 'Default true: wirkt sofort im KI-Kontext. false = Entwurf in Quarantäne, den ein Mensch freischaltet.'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Begriffe, unter denen die KI das Doc findet'],
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
                'geltung' => $arguments['geltung'] ?? [],
                'datenwerte' => $arguments['datenwerte'] ?? [],
                'slug' => isset($arguments['slug']) ? (string) $arguments['slug'] : null,
                'content_md' => $arguments['content_md'] ?? '',
                'aliases' => $arguments['aliases'] ?? [],
                // ★ `bind_layers` steht nicht mehr im Schema — WIRD ABER WEITERGEREICHT.
                // Erst ohne diese Zeile war es still weg: der Riegel in
                // `KnowledgeService::verweigereBindLayers()` bekam nichts zu sehen und der
                // Aufrufer ein `success`, das nichts getan hat. Genau die Fehlerklasse, gegen
                // die dieser Umbau antritt — im Umbau selbst gebaut, von einem Test gefangen.
                'bind_layers' => $arguments['bind_layers'] ?? null,
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
                'geltung' => json_decode($doc->geltung ?? '[]', true),
                'datenwerte' => json_decode($doc->datenwerte ?? '[]', true),
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
