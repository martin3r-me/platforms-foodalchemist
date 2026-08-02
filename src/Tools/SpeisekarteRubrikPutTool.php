<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Rubrik einer Speisekarte aktualisieren (Titel, Gast-Titel, Claim, Art, Preisanzeige). */
class SpeisekarteRubrikPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_rubrik.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert eine Rubrik einer Speisekarte. Felder optional: title, consumer_title, claim, '
            . 'art (speisen|getraenke|menue|dessert|sonstiges), preis_anzeige (mit|ohne).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rubrik_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'consumer_title' => ['type' => 'string'],
                'claim' => ['type' => 'string'],
                'art' => ['type' => 'string', 'enum' => ['speisen', 'getraenke', 'menue', 'dessert', 'sonstiges']],
                'preis_anzeige' => ['type' => 'string', 'enum' => ['mit', 'ohne']],
            ],
            'required' => ['rubrik_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $felder = array_intersect_key($arguments, array_flip(['title', 'consumer_title', 'claim', 'art', 'preis_anzeige']));

        try {
            $rubrik = app(SpeisekarteService::class)->updateRubrik($team, (int) $arguments['rubrik_id'], $felder);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'rubrik' => [
                'id' => $rubrik->id, 'title' => $rubrik->title, 'consumer_title' => $rubrik->consumer_title,
                'art' => $rubrik->art, 'preis_anzeige' => $rubrik->preis_anzeige,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'rubrik', 'aktualisieren'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_rubrik.POST'],
            'examples' => ['Setze bei Rubrik 7 den Gast-Titel "Unsere Klassiker"'],
        ];
    }
}
