<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * #380 Composer / Spec 43 (write): Veröffentlicht ein Angebot als digitales Kundenbuch — friert einen
 * absoluten, interna-freien Snapshot ein und aktiviert den Public-Link (ohne Login). Pflicht-Datum
 * (expires_at). Nur team-eigene Angebote (isOwnedBy). Spiegelt FoodbookPresentationPublishTool.
 */
class AngebotPresentationPublishTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_presentation.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht ein Angebot als teilbares digitales Kundenbuch (Public-Link ohne Login). '
            . 'Friert einen absoluten Snapshot ein; spätere Editor-Änderungen wirken erst nach erneutem '
            . 'Veröffentlichen. expires_at (gültig bis) ist Pflicht. Nur eigene Angebote.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angebot_id' => ['type' => 'integer'],
                'expires_at' => ['type' => 'string', 'description' => 'Gültig bis (Datum ISO, Pflicht — kein Link ohne Ablauf)'],
                'design' => ['type' => 'string', 'description' => 'editorial|menu|kiosk oder design:{id}'],
                'price_display' => ['type' => 'boolean', 'description' => 'Kundenpreise zeigen (Default an).'],
                'price_mode' => ['type' => 'string', 'enum' => ['auto', 'preserve'], 'description' => 'Republish-Preis-Modus: '
                    . 'fehlt/„preserve" = eingefrorene Preise BEHALTEN (neue Positionen kommen mit aktuellem Preis rein), '
                    . '„auto" = aktuelle VK ziehen. Erst-Veröffentlichung ist immer aktuell.'],
                'cta_text' => ['type' => 'string'],
                'cta_link' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'description' => 'Optionaler eigener Link-Name statt Zufalls-Token '
                    . '(z. B. "broich-gala-2027" → /p/angebot/broich-gala-2027). Wird kebab-normalisiert, '
                    . 'muss je Ausgabeform eindeutig sein; leerer String setzt zurück auf Token.'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional: veröffentlicht einen ZUSÄTZLICHEN Link '
                    . 'FÜR diesen Betrieb — eingefroren mit dessen Preisen UND dessen Vorlage, eigene Freigabe. '
                    . 'Ohne outlet_id = der Standard-Link am Dokument-Kopf. Der Betrieb muss dem Team gehören.'],
            ],
            'required' => ['angebot_id', 'expires_at'],
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
                'cta' => ['text' => $arguments['cta_text'] ?? null, 'link' => $arguments['cta_link'] ?? null],
            ] + (array_key_exists('slug', $arguments) ? ['slug' => $arguments['slug']] : []);
            $svc = app(PresentationService::class);
            $res = ($arguments['outlet_id'] ?? null) !== null
                ? $svc->publishForOutlet($team, 'angebot', (int) $arguments['angebot_id'], (int) $arguments['outlet_id'], $settings)
                : $svc->publish($team, 'angebot', (int) $arguments['angebot_id'], $settings);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Angebot oder Betrieb nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'angebot', 'praesentation', 'snapshot', 'freigabe', 'publish'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.angebot_presentation.WITHDRAW', 'foodalchemist.angebot_presentation.GET'],
            'examples' => ['Veröffentliche Angebot 5 als Kundenbuch, gültig bis 2027-12-31.'],
        ];
    }
}
