<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\StammPaketService;

/** Spec 82 · Lieferanten-Freischaltung eines Mandanten setzen (exakt diese Menge; nur Plattform-Admin). */
class StammPaketLieferantenPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.stamm_paket_lieferanten.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die freigeschalteten Stamm-Lieferanten eines Mandanten auf EXAKT die übergebene Menge: lieferant_ids (Liste) oder alle=true oder keine=true. '
            .'Artikel, Preise und Allergen-Sichten der Mandanten folgen sofort (auch neue Artikel freigeschalteter Lieferanten). Voraussetzung: Stamm-Paket gebucht. Wird protokolliert. '
            .'„Preise mitgeben“ (laufend, je Lieferant): preise_lieferant_ids (EXAKTE Menge, nur freigeschaltete Lieferanten) oder preise_alle=true / preise_keine=true — '
            .'der Mandant sieht dann die Master-Preise (Necta) dieser Lieferanten, eigene Preise gewinnen immer. Freischaltung und Preise sind einzeln oder zusammen setzbar. '
            .'Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'team_id' => ['type' => 'integer'],
            'lieferant_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'alle' => ['type' => 'boolean'], 'keine' => ['type' => 'boolean'],
            'preise_lieferant_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Stamm-Preise mitgeben: exakt diese freigeschalteten Lieferanten'],
            'preise_alle' => ['type' => 'boolean'], 'preise_keine' => ['type' => 'boolean'],
        ], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $auswahl = match (true) {
                ! empty($arguments['alle']) => 'alle',
                ! empty($arguments['keine']) => 'keine',
                isset($arguments['lieferant_ids']) && is_array($arguments['lieferant_ids']) => $arguments['lieferant_ids'],
                default => null,
            };
            $preise = match (true) {
                ! empty($arguments['preise_alle']) => 'alle',
                ! empty($arguments['preise_keine']) => 'keine',
                isset($arguments['preise_lieferant_ids']) && is_array($arguments['preise_lieferant_ids']) => $arguments['preise_lieferant_ids'],
                default => null,
            };
            if ($auswahl === null && $preise === null) {
                throw new \RuntimeException('lieferant_ids, alle=true oder keine=true (Freischaltung) bzw. preise_lieferant_ids, preise_alle=true oder preise_keine=true angeben.');
            }
            $svc = app(StammPaketService::class);
            $mandant = Team::findOrFail((int) ($arguments['team_id'] ?? 0));
            $ergebnis = $auswahl !== null ? $svc->setzeLieferanten($mandant, $auswahl, $context->user) : ['team_id' => (int) $mandant->id];
            if ($preise !== null) {
                $ergebnis['preise'] = $svc->setzePreisFreigabe($mandant, $preise, $context->user);
            }

            return $ergebnis;
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['stamm_paket'], ['Schalte Hanos für Soulkitchen frei.'], ['foodalchemist.stamm_paket_lieferanten.GET']), [
            'tags' => ['foodalchemist', 'stamm_paket', 'plattform', 'lieferanten', 'write'],
        ]);
    }
}
