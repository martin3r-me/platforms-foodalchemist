<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * #469 v3: bestehendes Wissens-Dokument aktualisieren (per slug) — auch Vault-verwaltete
 * des EIGENEN Teams (Browser-Parität; der Import-Guard schützt den Edit vor Re-Import-
 * Überschreiben). Globales Master-/Seed-Wissen (team_id NULL) bleibt read-only. Inhalts-
 * Änderung ⇒ version+1. Optional Aliase/Bindungen ergänzen. active kann gesetzt werden
 * (aktivieren bleibt bewusst auch hier möglich; Default beim Anlegen ist inaktiv).
 */
class KnowledgeUpdateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert ein bestehendes Wissens-Dokument (slug aus knowledge.SEARCH/LIST/GET). '
            . 'Änderbar: title, category (Vokabular-Slug), art, content_md (⇒ version+1), active, aliases. '
            . 'Verbindlich in einen Prompt: `knowledge_canon.PUT`. Suchbar: `knowledge_routings.PUT`. '
            . 'Einsatzort-Bindungen gibt es nicht mehr (Spec 52). '
            . 'Auch Vault-verwaltete Docs des eigenen Teams editierbar (Import-Guard schützt den Edit vor '
            . 'Re-Import-Überschreiben); nur globales Master-/Seed-Wissen bleibt read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'art' => ['type' => 'string', 'description' => 'Wissensart — steuert, WIE das Wissen benutzt werden darf (die Kategorie sagt nur, WORUM es geht): regel (verbindlich) | datenwerk (Nachschlagewerk: wird ueber Achsen aufgeloest, nicht gesucht) | fachwissen (echter Suchfall) | referenz (Inspiration) | ablauf (Anleitung fuer AGENTEN — gehoert in KEINEN Prompt). Weglassen = noch nicht eingeordnet.'],
                'geltung' => ['type' => 'object', 'description' => 'UND zwischen Achsen, ODER zwischen Werten: gang, komponentenrolle, portionskontext, niveau, saison, warengruppe, occasion, sektor, format. Werte als Listen von Strings. Leer = uneingeschränkt.'],
                'datenwerte' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'kennzahl' => ['type' => 'string'], 'min' => ['type' => 'number'], 'max' => ['type' => 'number'],
                    'einheit' => ['type' => 'string'], 'bezug' => ['type' => 'string', 'description' => 'Zum Beispiel Rohgewicht pro Portion oder verzehrfertig pro Ansatz.'],
                    'quelle' => ['type' => 'string'], 'geltung' => ['type' => 'object'],
                ], 'required' => ['kennzahl', 'min', 'max', 'einheit', 'bezug', 'quelle']], 'description' => 'Nur Datenwerke. Einzelwert: min=max. Quelle und Dossierversion bleiben nachvollziehbar.'],
                'content_md' => ['type' => 'string'],
                'active' => ['type' => 'boolean'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
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

        $data = array_intersect_key($arguments, array_flip([
            // `bind_layers` bleibt in dieser Liste, obwohl es nicht mehr im Schema steht: nur
            // so erreicht es den Riegel im Service und wird ABGEWIESEN statt still verworfen.
            'title', 'category', 'art', 'geltung', 'datenwerte', 'content_md', 'active', 'aliases', 'bind_layers',
        ]));

        try {
            $doc = app(KnowledgeService::class)->update($team, (string) $arguments['slug'], $data);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $code = str_contains($msg, 'nicht gefunden') ? 'NOT_FOUND'
                : (str_contains($msg, 'Master-/Seed-Wissen') ? 'LOCKED' : 'VALIDATION_ERROR');

            return ToolResult::error($msg, $code);
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
            'hinweis' => app(KnowledgeCanonService::class)->groessenHinweis((int) $doc->char_count, (string) ($doc->content_md ?? '')),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'bearbeiten', 'update', 'mcp'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.POST', 'foodalchemist.knowledge.GET'],
            'examples' => ['Ergänze im Trend-Doc "fermentierte-chili-pasten" einen Abschnitt zu Anwendungen'],
        ];
    }
}
