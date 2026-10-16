<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\AktionspreisService;

/** Aktionspreis (16.10.2026) an einem sichtbaren Lieferantenartikel anlegen: reine Information, die Kalkulation bleibt beim Einkaufspreis. */
class ArtikelAktionspreisePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_aktionspreise.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Aktionspreis (netto) mit Zeitraum an einem sichtbaren Lieferantenartikel an. Reine Information: Einkaufspreis, '
            .'Lead-Artikel und Preis-Linie rechnen weiter mit dem Normalpreis; am Artikel steht „Aktion bis …“. Schreiben darf das '
            .'Besitzer-Team bzw. ein Kunden-Hauptteam (wie Teampreise). Entfernen: Stand in „Frühere Preise“ ausblenden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (für das Team sichtbar).'],
                'preis' => ['type' => 'number', 'description' => 'Aktionspreis netto (> 0) je Bestelleinheit.'],
                'ab' => ['type' => 'string', 'description' => 'Beginn als Datum JJJJ-MM-TT (optional, Standard: heute).'],
                'bis' => ['type' => 'string', 'description' => 'Ende als Datum JJJJ-MM-TT (einschließlich, Pflicht).'],
            ],
            'required' => ['id', 'preis', 'bis'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! is_numeric($arguments['preis'] ?? null) || (float) $arguments['preis'] <= 0) {
            return ToolResult::error('preis muss eine Zahl > 0 sein.', 'VALIDATION_ERROR');
        }
        $datum = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
        if (! $datum($arguments['bis'] ?? null) || (isset($arguments['ab']) && ! $datum($arguments['ab']))) {
            return ToolResult::error('ab/bis als Datum JJJJ-MM-TT angeben (bis ist Pflicht).', 'VALIDATION_ERROR');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey((int) ($arguments['id'] ?? 0))->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $r = app(AktionspreisService::class)->anlegen($team, $item, (float) $arguments['preis'], (string) ($arguments['ab'] ?? now('Europe/Berlin')->toDateString()),
                (string) $arguments['bis'], 'manuell', null, ['kanal' => 'mcp'], $context->user?->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $item->id, 'aktion_id' => $r['id'], 'status' => $r['status'], 'team_id' => (int) $team->id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'preis', 'aktion', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.artikel_aktionspreise.GET', 'foodalchemist.artikel_preise.POST'],
            'examples' => ['Lege für Artikel 4711 eine Aktion 7,90 € bis 2026-11-30 an.'],
        ];
    }
}
