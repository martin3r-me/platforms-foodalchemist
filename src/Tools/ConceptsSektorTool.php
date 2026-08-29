<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Sektor-Eignung eines team-eigenen Konzepts setzen/entfernen. */
class ConceptsSektorTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.SEKTOR';
    }

    public function getDescription(): string
    {
        return 'Setzt (on=true) oder entfernt (on=false) eine Sektor-Eignung eines team-eigenen Konzepts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'slug' => ['type' => 'string', 'description' => 'Sektor-Slug.'],
                'on' => ['type' => 'boolean', 'description' => 'true = setzen (Default), false = entfernen.'],
            ],
            'required' => ['id', 'slug'],
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
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;
        }
        $on = ($arguments['on'] ?? true) !== false;

        $svc = app(ConceptService::class);
        try {
            $on ? $svc->setzeSektorEignung($team, $id, $slug) : $svc->entferneSektorEignung($team, $id, $slug);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'slug' => $slug, 'on' => $on]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'sektor', 'eignung', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concepts.PUT'],
            'examples' => ['Markiere Konzept 7 als für den Sektor „care" geeignet.'],
        ];
    }
}
