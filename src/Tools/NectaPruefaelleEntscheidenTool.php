<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Exceptions\NectaPruefallException;
use Platform\FoodAlchemist\Services\Necta\NectaPruefallService;

/**
 * Necta-Prüffälle entscheiden (PR 3) — einzeln (ids) oder gesammelt (filter). Zwei Schritte: bestaetigen=false liefert die Vorschau mit
 * Schlüssel (Anzahl, Allergen-Liste), bestaetigen=true + schluessel führt aus. Gleiche Rechte wie die Oberfläche: ab Kuratieren,
 * Sammel-Übernahme an Allergenen/Deklarationen nur Admin.
 */
class NectaPruefaelleEntscheidenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.necta_pruefaelle.ENTSCHEIDEN';
    }

    public function getDescription(): string
    {
        return 'Entscheidet Necta-Prüffälle. aktion: necta_uebernehmen (Necta-Wert eintragen; nicht bei Produktpass-Feldern, nicht wenn der FA-Wert '
            .'inzwischen anders ist), fa_behalten (derselbe Necta-Wert wird nicht wieder gemeldet), eingestellt_bestaetigen / aktiv_lassen '
            .'(eingestellt_widerspruch). Auswahl: ids[] ODER filter{art, lieferant, feld, suche, normalpreis_tage}. Ablauf: zuerst bestaetigen=false '
            .'→ Vorschau mit anzahl, allergene (betroffene Allergen-Lockerungen) und schluessel; dann bestaetigen=true mit genau diesem schluessel. '
            .'Allergen-Lockerungen sind sicherheitsrelevant: Vorschau dem Menschen zeigen. Sammel über den Filter: art genügt (alle Lieferanten), '
            .'lieferant/suche schränken ein; die Vorschau nennt je_lieferant. Ab '.NectaPruefallService::SAMMEL_SOFORT_MAX.' Fällen läuft die '
            .'Entscheidung im Hintergrund (Antwort im_hintergrund=true + lauf); Stand mit lauf=<id> abfragen. Nur Master-Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aktion' => ['type' => 'string', 'enum' => NectaPruefallService::AKTIONEN],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'filter' => ['type' => 'object', 'properties' => [
                    'art' => ['type' => 'string'], 'lieferant' => ['type' => 'integer'], 'feld' => ['type' => 'string'],
                    'suche' => ['type' => 'string'], 'normalpreis_tage' => ['type' => 'integer'],
                ]],
                'bestaetigen' => ['type' => 'boolean', 'description' => 'false = nur Vorschau (Standard), true = ausführen'],
                'schluessel' => ['type' => 'string', 'description' => 'aus der Vorschau'],
                'lauf' => ['type' => 'string', 'description' => 'nur Stand eines Hintergrund-Laufs abfragen (gesamt, erledigt, uebersprungen, fertig)'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (isset($arguments['lauf']) && is_string($arguments['lauf']) && $arguments['lauf'] !== '') {
            if (! \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team)) {
                return ToolResult::error('Necta-Prüffälle entscheidet das Master-Team.', 'ACCESS_DENIED');
            }
            $stand = NectaPruefallService::sammelStand($arguments['lauf']);

            return $stand === null ? ToolResult::error('Lauf unbekannt oder abgelaufen.', 'NOT_FOUND') : ToolResult::success(['lauf' => $arguments['lauf']] + $stand);
        }
        $ids = isset($arguments['ids']) && is_array($arguments['ids']) && $arguments['ids'] !== [] ? array_map('intval', $arguments['ids']) : null;
        $filter = is_array($arguments['filter'] ?? null) ? array_intersect_key($arguments['filter'], array_flip(['art', 'lieferant', 'feld', 'suche', 'normalpreis_tage'])) : [];
        if ($ids === null && $filter === []) {
            return ToolResult::error('ids oder filter angeben — ohne Auswahl wird nichts entschieden.', 'VALIDATION_ERROR');
        }
        $aktion = (string) ($arguments['aktion'] ?? '');
        $svc = app(NectaPruefallService::class);
        try {
            if (! ($arguments['bestaetigen'] ?? false)) {
                return ToolResult::success(['vorschau' => true] + $svc->vorschau($team, $ids, $filter, $aktion));
            }
            $schluessel = isset($arguments['schluessel']) ? (string) $arguments['schluessel'] : null;
            // Einzelfall per ID braucht keinen Schlüssel (wie in der Oberfläche); Sammel immer
            return ToolResult::success($svc->entscheiden($team, $ids, $filter, $aktion, $context->user?->id, $schluessel));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (NectaPruefallException $e) {
            return ToolResult::error($e->getMessage(), $e->fehlercode === 'NUR_MASTER' ? 'ACCESS_DENIED' : $e->fehlercode);
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'necta', 'pruefaelle', 'allergene', 'kennzeichnung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.necta_pruefaelle.GET', 'foodalchemist.produktpass.ABWEICHUNG_ENTSCHEIDEN'],
            'examples' => ['Behalte bei allen offenen Allergen-Prüffällen von Hanos den FA-Wert.'],
        ];
    }
}
