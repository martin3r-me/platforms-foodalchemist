<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Volle Speisekarte lesen: Rubrik-Baum + Positionen inkl. aufgelöstem Netto-VK. */
class SpeisekartenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert eine Speisekarte mit Rubrik-Baum, Positionen (Gericht/Menü) und aufgelöstem '
            . 'Netto-VK je Position (Darreichung/Concept/manuell).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
            ],
            'required' => ['speisekarte_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $svc = app(SpeisekarteService::class);
        $karte = $svc->detail($team, (int) $arguments['speisekarte_id']);
        if ($karte === null) {
            return ToolResult::error('Speisekarte nicht gefunden.', 'NOT_FOUND');
        }

        $rubriken = $karte->sections->whereNull('parent_id')->map(function ($rubrik) use ($karte, $svc) {
            return $this->rubrikDaten($rubrik, $karte, $svc);
        })->values()->all();

        return ToolResult::success([
            'speisekarte' => [
                'id' => $karte->id, 'name' => $karte->name, 'status' => $karte->status,
                'karten_typ' => $karte->karten_typ, 'rubriken' => $rubriken,
            ],
        ]);
    }

    private function rubrikDaten($rubrik, $karte, SpeisekarteService $svc): array
    {
        return [
            'id' => $rubrik->id, 'title' => $rubrik->title, 'consumer_title' => $rubrik->consumer_title,
            'art' => $rubrik->art,
            'positionen' => $rubrik->items->map(fn ($pos) => [
                'id' => $pos->id, 'typ' => $pos->type,
                'name' => $pos->wording ?: ($pos->dish?->name ?? $pos->concept?->name ?? $pos->label),
                'vk_netto' => $svc->positionPreis($pos)['vk'],
            ])->all(),
            'unterrubriken' => $karte->sections->where('parent_id', $rubrik->id)
                ->map(fn ($k) => $this->rubrikDaten($k, $karte, $svc))->values()->all(),
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'speisekarte', 'detail'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => [], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarten.SEARCH', 'foodalchemist.speisekarte_leitstelle.GET'],
            'examples' => ['Zeig mir Speisekarte 3 mit allen Positionen und Preisen'],
        ];
    }
}
