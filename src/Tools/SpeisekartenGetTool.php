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
        // Token-Vereinheitlichung (D8): GET auf die Plural-Ressource `speisekarten.*` gebracht,
        // damit Read und Writes denselben Namespace teilen (vorher speisekarte.GET singular).
        return 'foodalchemist.speisekarten.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert eine Speisekarte mit Kopf (Status, Kontext-Leitplanken, Branding, CRM-Kunde), '
            . 'Rubrik-Baum, Positionen (Gericht/Menü) und aufgelöstem Netto-VK je Position.';
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
                'id' => $karte->id, 'name' => $karte->name,
                'status' => $karte->status instanceof \BackedEnum ? $karte->status->value : $karte->status,
                'karten_typ' => $karte->karten_typ,
                // Kontext-Leitplanken (via speisekarten.PUT setzbar; steuern Wording/Defaults)
                'kontext' => [
                    'kundentyp' => $karte->kundentyp,
                    'niveau' => $karte->default_niveau,
                    'convenience' => $karte->default_convenience,
                    'writing_style_id' => $karte->writing_style_id !== null ? (int) $karte->writing_style_id : null,
                    'preis_anzeige_brutto' => (bool) $karte->preis_anzeige_brutto,
                    'preis_rundung' => $karte->preis_rundung,
                ],
                'branding' => [
                    'brand_color' => $karte->brand_color,
                    'band_color' => $karte->band_color,
                    'footer_text' => $karte->footer_text,
                    'has_logo' => ! empty($karte->logo_path),
                    'has_cover' => ! empty($karte->cover_image_path),
                ],
                'kunde' => [
                    'crm_company_id' => $karte->crm_company_id !== null ? (int) $karte->crm_company_id : null,
                    'crm_contact_id' => $karte->crm_contact_id !== null ? (int) $karte->crm_contact_id : null,
                ],
                'rubriken' => $rubriken,
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
