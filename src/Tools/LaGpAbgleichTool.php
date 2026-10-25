<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\DeklarationsprofilAbweichtException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\GpAbgleich\GpAbgleichService;

/**
 * Abgleich Lieferantenartikel → GP (Entscheid Dominique 25.10.2026): dieselbe Pipeline wie Modal, Job und Eval — das Tool ruft NUR {@see GpAbgleichService}.
 * Ohne `apply` nur die Entscheidung (verknüpfen | neu | unsicher | blockiert) mit Begründung, Kandidaten, Merkmals-Diff und Guard-Vorschau. Mit
 * `apply=true` + `confirm=true` wird sie ausgeführt: verknüpfen (Allergen-Guard hart) bzw. neues GP aus den Artikelfeldern. Mandanten ohne Stamm-Paket-Abo
 * verknüpfen nicht mit globalen Stamm-GPs; Option `eigenes_gp` legt dann ein team-eigenes GP an.
 */
class LaGpAbgleichTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.la.GP_ABGLEICH';
    }

    public function getDescription(): string
    {
        return 'Gleicht einen Lieferantenartikel gegen die vorhandenen Grundprodukte ab: Es gibt schon ein passendes GP (verknüpfen mit #X, mit Begründung) oder es braucht ein neues (neu), '
            . 'oder die Lage ist unklar (unsicher). Pipeline: exakte Brücke (EAN/Artikelnummer) → Felder (gp.suggest) → Kandidaten mit harten Filtern (Warengruppe, Zustand), '
            . 'Merkmals-Diff (Geschmack, Fantasiename, Prozent, Zucker, Bio) und Allergen-Guard-Vorschau → KI-Entscheider (max. 5 Kandidaten). '
            . 'Automatisch gilt nur: exakte Brücke ODER Sicherheit ≥ 0,90 mit allen Prüfungen ok und GP approved (Feld automatisch). '
            . 'Ohne apply wird nichts verknüpft. apply=true + confirm=true führt aus (verknüpfen: Guard hart; neu: GP aus den Feldern anlegen). '
            . 'Mandant ohne Stamm-Paket-Abo + globales Stamm-GP: nicht verknüpfbar (alternative=eigenes_gp_im_mandanten); eigenes_gp=true legt ein team-eigenes GP an.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'artikel_id' => ['type' => 'integer', 'description' => 'Lieferantenartikel (supplier_item id).'],
                'apply' => ['type' => 'boolean', 'default' => false, 'description' => 'Entscheidung ausführen (verknüpfen bzw. neu anlegen). Erfordert confirm=true.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Pflicht bei apply=true.'],
                'eigenes_gp' => ['type' => 'boolean', 'default' => false, 'description' => 'Statt mit dem (globalen) Stamm-GP zu verknüpfen ein team-eigenes GP aus den Artikelfeldern anlegen.'],
                'ki' => ['type' => 'boolean', 'default' => true, 'description' => 'false = nur deterministische Stufen (S0–S5), kein KI-Call; liefert höchstens exakt oder „unsicher“ mit Kandidaten.'],
                'ignoriere_verknuepfung' => ['type' => 'boolean', 'default' => false, 'description' => 'Auch für bereits verknüpfte Artikel neu entscheiden (Vergleich/Prüfung).'],
            ],
            'required' => ['artikel_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $apply = (bool) ($arguments['apply'] ?? false);
        if ($apply && ($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('apply erfordert confirm=true.', 'CONFIRM_REQUIRED');
        }
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) ($arguments['artikel_id'] ?? 0));
        if ($la === null) {
            return ToolResult::error('Lieferantenartikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $svc = app(GpAbgleichService::class);
        $userId = $context->user->id ?? null;
        try {
            $r = $svc->entscheideProtokolliert($team, $la, [
                'modus' => 'mcp', 'user_id' => $userId, 'ki' => (bool) ($arguments['ki'] ?? true),
                'ignoriere_verknuepfung' => (bool) ($arguments['ignoriere_verknuepfung'] ?? false),
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $e = $r['ergebnis'];
        $daten = $e->toArray() + ['log_id' => (int) $r['log']->id, 'ausgefuehrt' => null];

        if ($apply) {
            if (! in_array($e->aktion, ['verknuepfen', 'neu'], true)) {
                return ToolResult::error("Nicht ausführbar: Entscheidung ist „{$e->aktion}“ ({$e->begruendung}).", 'VALIDATION_ERROR');
            }
            try {
                $a = $svc->fuehreAus($team, $la, $e, ['eigenes_gp' => (bool) ($arguments['eigenes_gp'] ?? false)]);
            } catch (DeklarationsprofilAbweichtException $x) {
                return ToolResult::error($x->getMessage(), 'ALLERGEN_GUARD');
            } catch (\RuntimeException $x) {
                return ToolResult::error($x->getMessage(), 'ACCESS_DENIED');
            }
            $r['log']->update(['ausgefuehrt' => true, 'ausgefuehrt_aktion' => $a['ausgefuehrt'], 'ausgefuehrt_gp_id' => (int) $a['gp']->id]);
            $daten['ausgefuehrt'] = ['aktion' => $a['ausgefuehrt'], 'gp_id' => (int) $a['gp']->id, 'gp_name' => (string) $a['gp']->name];
        }

        return ToolResult::success($daten);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'lieferantenartikel', 'abgleich', 'matching', 'la-first'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'confirmation_required' => false,   // nur apply=true verlangt confirm=true; ohne apply liest/protokolliert das Tool nur
            'side_effects' => ['creates', 'links', 'logs'],
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.gps.MATCH', 'foodalchemist.gps.MINT_FROM_LA', 'foodalchemist.gp_la.PUT', 'foodalchemist.gps.POST'],
            'examples' => [
                'Gibt es für den Lieferantenartikel 426565 schon ein passendes Grundprodukt?',
                'Gleiche Artikel 309726 ab und verknüpfe ihn, wenn es sicher ist',
            ],
        ];
    }
}
