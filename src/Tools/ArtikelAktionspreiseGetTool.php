<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\AktionspreisService;

/** Aktionspreise eines Artikels lesen (16.10.2026): die jetzt gültige plus alle Aktionen der Preis-Zeitachse (status 'A'). */
class ArtikelAktionspreiseGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_aktionspreise.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Aktionspreise eines sichtbaren Lieferantenartikels: die jetzt gültige Aktion und die Liste aller Aktionen '
            .'(Quelle necta_historie, datei oder manuell; ausgeblendete markiert). Aktionen sind reine Information, nicht Teil der Kalkulation.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Artikel-Id (für das Team sichtbar).']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey((int) ($arguments['id'] ?? 0))->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $svc = app(AktionspreisService::class);
        $aktiv = $svc->aktivFuer($team, $item);
        $teams = [...FoodAlchemistSupplierItem::teamAncestryIds($team), ...($item->team_id !== null ? [(int) $item->team_id] : [])];
        $alle = DB::table('foodalchemist_price_history')->where('supplier_item_id', $item->id)->where('status', AktionspreisService::STATUS)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', $teams))
            ->orderByDesc('status_valid_from')->limit(100)
            ->get(['id', 'price', 'status_valid_from', 'valid_to', 'quelle', 'team_id', 'ausgeblendet_at', 'ausgeblendet_grund'])
            ->map(fn ($a) => ['aktion_id' => (int) $a->id, 'preis' => (float) $a->price, 'ab' => $a->status_valid_from, 'bis' => $a->valid_to,
                'quelle' => $a->quelle, 'team_id' => $a->team_id !== null ? (int) $a->team_id : null,
                'ausgeblendet' => $a->ausgeblendet_at !== null, 'ausgeblendet_grund' => $a->ausgeblendet_grund])->all();

        return ToolResult::success(['id' => (int) $item->id, 'aktiv' => $aktiv !== null ? ['aktion_id' => (int) $aktiv->id, 'preis' => (float) $aktiv->price,
            'ab' => $aktiv->status_valid_from, 'bis' => $aktiv->valid_to, 'quelle' => $aktiv->quelle] : null, 'aktionen' => $alle,
            'hinweis' => 'Aktionen sind Information; Einkaufspreis und Kalkulation nutzen den Normalpreis (foodalchemist.artikel_preise).']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'artikel', 'preis', 'aktion', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.artikel_aktionspreise.POST'],
            'examples' => ['Welche Aktion gilt gerade für Artikel 4711?'],
        ];
    }
}
