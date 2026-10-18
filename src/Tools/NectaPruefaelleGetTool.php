<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\NectaPruefallException;
use Platform\FoodAlchemist\Services\Necta\NectaPruefallService;

/** Necta-Prüffälle lesen (PR 3): Liste mit Filtern und Zählern — nur Master-Team. */
class NectaPruefaelleGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.necta_pruefaelle.GET';
    }

    public function getDescription(): string
    {
        return 'Listet Necta-Prüffälle (Abweichungen, die der Necta-Abgleich nicht still übernimmt): je Fall Artikel, Lieferant, Feld, FA-Wert damals '
            .'und jetzt, Necta-Wert, Produktpass-Marker, letzter Necta-Normalpreis. Filter: art (allergen_aenderung, deklaration_aenderung, '
            .'manuell_gepflegt, produktpass_abweichung, eingestellt_widerspruch, in_necta_entfernt …), lieferant (FA-ID), feld, status '
            .'(offen|uebernommen|verworfen|alle, Standard offen), suche, normalpreis_tage. Dazu Zähler je Art und Lieferant. Nur Master-Team. '
            .'Entscheiden: foodalchemist.necta_pruefaelle.ENTSCHEIDEN.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'art' => ['type' => 'string'], 'lieferant' => ['type' => 'integer', 'description' => 'FA-Lieferanten-ID'],
                'feld' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['offen', 'uebernommen', 'verworfen', 'alle']],
                'suche' => ['type' => 'string'], 'normalpreis_tage' => ['type' => 'integer', 'description' => 'nur Fälle mit Necta-Normalpreis jünger als N Tage'],
                'seite' => ['type' => 'integer'], 'pro_seite' => ['type' => 'integer', 'description' => 'max. 200, Standard 50'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $filter = array_intersect_key($arguments, array_flip(['art', 'lieferant', 'feld', 'status', 'suche', 'normalpreis_tage']));
        $svc = app(NectaPruefallService::class);
        try {
            $liste = $svc->liste($team, $filter, (int) ($arguments['seite'] ?? 1), max(1, min(200, (int) ($arguments['pro_seite'] ?? 50))));

            return ToolResult::success($liste + ['zaehler' => $svc->zaehler($team, $filter)]);
        } catch (NectaPruefallException $e) {
            return ToolResult::error($e->getMessage(), $e->fehlercode === 'NUR_MASTER' ? 'ACCESS_DENIED' : $e->fehlercode);
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'necta', 'pruefaelle', 'allergene', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.necta_pruefaelle.ENTSCHEIDEN'],
            'examples' => ['Zeig mir die offenen Allergen-Prüffälle von Hanos.'],
        ];
    }
}
