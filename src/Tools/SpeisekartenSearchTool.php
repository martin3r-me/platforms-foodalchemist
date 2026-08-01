<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;

/** Speisekarten (dem Team sichtbar) suchen/auflisten. */
class SpeisekartenSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarten.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Listet/sucht Speisekarten (Name/Code), team-sichtbar. Optional Filter status/karten_typ.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchtext (Name/Code)'],
                'status' => ['type' => 'string', 'enum' => ['entwurf', 'aktiv', 'veroeffentlicht', 'archiviert']],
                'karten_typ' => ['type' => 'string', 'enum' => ['alacarte', 'tageskarte', 'saisonkarte', 'getraenkekarte', 'weinkarte']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $q = trim((string) ($arguments['q'] ?? ''));
        $karten = FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->withCount('sections')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', ['%' . mb_strtolower($q) . '%'])))
            ->when(! empty($arguments['status']), fn ($w) => $w->where('status', $arguments['status']))
            ->when(! empty($arguments['karten_typ']), fn ($w) => $w->where('karten_typ', $arguments['karten_typ']))
            ->orderBy('name')
            ->limit((int) ($arguments['limit'] ?? 25))
            ->get();

        return ToolResult::success([
            'speisekarten' => $karten->map(fn ($k) => [
                'id' => $k->id, 'name' => $k->name, 'status' => $k->status,
                'karten_typ' => $k->karten_typ, 'rubriken' => $k->sections_count,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'speisekarte', 'suche', 'liste'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => [], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte.GET'],
            'examples' => ['Zeige alle aktiven Weinkarten'],
        ];
    }
}
