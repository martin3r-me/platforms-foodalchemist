<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\BausteinFolgeService;

/** Spec 82 Abo Konzepte/Pakete PR 5 · Neuere Abo-Fassung an EINEM Verweis übernehmen. Bei geändertem Allergenbild nur Rolle Freigeben und mit bestaetigt=true. */
class BausteinFassungUebernehmenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.baustein_fassungen.UEBERNEHMEN_POST';
    }

    public function getDescription(): string
    {
        return 'Übernimmt die neuere Abo-Fassung eines Konzepts oder Pakets an einem Verweis des Teams (quelle + row_id aus baustein_fassungen.UEBERNEHMEN_GET). '
            .'Ohne bestaetigt=true kommt nur die Vorschau mit Allergen-Diff. Ändert die neue Fassung das Allergen- oder Zusatzstoffbild, braucht die Übernahme bestaetigt=true und '
            .'die Rolle Freigeben; sonst genügt Kuratieren. Person und Zeitpunkt werden protokolliert; eingefrorene Angebote werden nur auf ausdrückliche Übernahme umgehängt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'art' => ['type' => 'string', 'enum' => ['concept', 'paket', 'format']],
            'quelle' => ['type' => 'string', 'description' => 'konzept_paket | konzept_eingebettet | format | foodbook_kapitel_format | angebot_kapitel_format | speisekarte_rubrik_format | foodbook | speisekarte | angebot | speiseplan_konzept | speiseplan_paket'],
            'row_id' => ['type' => 'integer'],
            'bestaetigt' => ['type' => 'boolean', 'description' => 'false/weglassen = Vorschau; true = übernehmen (Pflicht bei geändertem Allergenbild).'],
        ], 'required' => ['art', 'quelle', 'row_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $bericht = app(BausteinFolgeService::class)->uebernehmen($team, $context->user, (string) ($arguments['art'] ?? ''), (string) ($arguments['quelle'] ?? ''),
                (int) ($arguments['row_id'] ?? 0), ! empty($arguments['bestaetigt']));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Verweis nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['bericht' => $bericht]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'konzepte', 'pakete', 'fassung', 'allergen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.baustein_fassungen.UEBERNEHMEN_GET'],
            'examples' => ['Übernimm die neue Fassung des Grill-Buffets in meiner Speisekarte.'],
        ];
    }
}
