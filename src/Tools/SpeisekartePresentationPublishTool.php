<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 43 (write): Veröffentlicht eine Speisekarte als digitale à-la-carte-Karte (Public-Link
 * ohne Login, absoluter Snapshot, Pflicht-Datum). Nur team-eigene Karten.
 */
class SpeisekartePresentationPublishTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_presentation.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht eine Speisekarte als teilbare digitale Karte (Public-Link ohne Login). '
            . 'Friert einen Snapshot ein; expires_at (gültig bis) ist Pflicht. Nur eigene Karten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
                'expires_at' => ['type' => 'string', 'description' => 'Gültig bis (Datum ISO, Pflicht)'],
                'design' => ['type' => 'string', 'description' => 'editorial|menu|kiosk oder design:{id}'],
                'price_display' => ['type' => 'boolean'],
                'price_mode' => ['type' => 'string', 'enum' => ['auto', 'preserve'], 'description' => 'Republish-Preis-Modus: '
                    . 'fehlt/„preserve" = eingefrorene Preise BEHALTEN (neue Speisen kommen mit aktuellem Preis rein), '
                    . '„auto" = aktuelle VK ziehen. Erst-Veröffentlichung ist immer aktuell.'],
                'declaration' => ['type' => 'boolean'],
                'cta_text' => ['type' => 'string'],
                'cta_link' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'description' => 'Optionaler eigener Link-Name statt Zufalls-Token '
                    . '(kebab-normalisiert, je Ausgabeform eindeutig; leerer String = zurück auf Token).'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional (Slice F): veröffentlicht einen '
                    . 'ZUSÄTZLICHEN Link FÜR diesen Betrieb — eingefroren mit dessen Preisen UND dessen Vorlage, '
                    . 'eigene Freigabe. Ohne outlet_id = der Standard-Link am Dokument-Kopf. Der Betrieb muss dem Team gehören.'],
            ],
            'required' => ['speisekarte_id', 'expires_at'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $settings = [
                'expires_at' => $arguments['expires_at'] ?? null,
                'design' => $arguments['design'] ?? null,
                'price_display' => $arguments['price_display'] ?? true,
                'price_mode' => ($arguments['price_mode'] ?? null) === 'auto' ? 'auto' : 'preserve',
                'declaration' => $arguments['declaration'] ?? true,
                'cta' => ['text' => $arguments['cta_text'] ?? null, 'link' => $arguments['cta_link'] ?? null],
            ] + (array_key_exists('slug', $arguments) ? ['slug' => $arguments['slug']] : []);
            $svc = app(PresentationService::class);
            $res = ($arguments['outlet_id'] ?? null) !== null
                ? $svc->publishForOutlet($team, 'speisekarte', (int) $arguments['speisekarte_id'], (int) $arguments['outlet_id'], $settings)
                : $svc->publish($team, 'speisekarte', (int) $arguments['speisekarte_id'], $settings);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Speisekarte oder Betrieb nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'speisekarte', 'praesentation', 'snapshot', 'freigabe', 'publish'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_presentation.WITHDRAW', 'foodalchemist.speisekarte_presentation.GET'],
            'examples' => ['Veröffentliche Speisekarte 7 als digitale Karte, gültig bis 2027-06-30.'],
        ];
    }
}
