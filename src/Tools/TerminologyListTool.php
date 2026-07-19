<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAlias;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Platform\FoodAlchemist\Services\TerminologyService;

/**
 * #507 Weg-2 · E7-b: die runtime-gepflegten Terminologie-Einträge (Alias-Gruppen +
 * Anti-Marker) auflisten. Zeigt die DB-Zeilen (mit id, verwaltbar via terminology.POST)
 * plus die Anzahl der fest im Code liegenden Baseline-Regeln.
 */
class TerminologyListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.terminology.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die runtime-gepflegte Terminologie-Schicht (globaler Master): Alias-Gruppen '
            . '(Synonyme/Dialekt/Übersetzung) + Anti-Marker (Verwechslungs-Sperren) aus der DB, plus die '
            . 'Anzahl der Code-Baseline-Regeln. Neue Einträge via foodalchemist.terminology.POST.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'optionaler Filter (Teilstring auf Phrasen/Token)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($this->team($context) === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = mb_strtolower(trim((string) ($arguments['q'] ?? '')));
        $limit = min(200, max(1, (int) ($arguments['limit'] ?? 100)));

        $aliases = FoodAlchemistTerminologyAlias::query()->whereNull('deleted_at')
            ->orderByDesc('id')->limit($limit)->get(['id', 'members', 'note', 'source'])
            ->map(fn ($a) => ['id' => $a->id, 'members' => $a->members, 'note' => $a->note, 'source' => $a->source])
            ->filter(fn ($a) => $q === '' || str_contains(mb_strtolower(implode(' ', (array) $a['members'])), $q))
            ->values()->all();

        $anti = FoodAlchemistTerminologyAntiMarker::query()->whereNull('deleted_at')
            ->orderByDesc('id')->limit($limit)->get(['id', 'trigger_token', 'forbid_token', 'unless_token', 'note', 'source'])
            ->map(fn ($r) => [
                'id' => $r->id, 'trigger' => $r->trigger_token, 'forbid' => $r->forbid_token,
                'unless' => $r->unless_token, 'note' => $r->note, 'source' => $r->source,
            ])
            ->filter(fn ($r) => $q === '' || str_contains(mb_strtolower($r['trigger'] . ' ' . $r['forbid']), $q))
            ->values()->all();

        $svc = app(TerminologyService::class);

        return ToolResult::success([
            'aliases' => $aliases,
            'anti_markers' => $anti,
            'baseline' => [
                'alias_groups_total' => count($svc->aliasGroups()),
                'anti_markers_total' => count($svc->antiMarkerRules()),
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'terminology', 'alias', 'anti-marker', 'matching'],
            'related_tools' => ['foodalchemist.terminology.POST'],
            'examples' => ['Zeige die gepflegten Terminologie-Aliase', 'Welche Anti-Marker gibt es?'],
        ];
    }
}
