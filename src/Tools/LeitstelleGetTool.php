<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\LeitstelleService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E5.4): die Lesefläche, mit der eine KI den User
 * durch die 7 Arbeits-Phasen führt. READ-ONLY.
 *
 * Ohne chapter_id: Foodbook-Überblick — 7-Schritt-Checkliste (Status offen|teil|erledigt
 * + Sprungziel) + Kapitel-Matrix (Planungs-Status je Kapitel + WE-Ampel).
 * Mit chapter_id: zusätzlich der aufgelöste Kapitel-Stand (Ziele + Zielgruppen +
 * Dimensionen + Aggregat + WE-Ampel + Inhalts-/Ideen-Zähler + Anlage-Stand) sowie die
 * Coverage-Befunde dieses Kapitels (Scope = Kapitel + Nachfahren).
 *
 * KAPITEL-GO OHNE MCP-TRIGGER: das Anlegen (kapitelFreigeben) ist human-only —
 * dieses Tool liest nur, es materialisiert nichts (Spec MCP-Lockstep).
 */
class LeitstelleGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.leitstelle.GET';
    }

    public function getDescription(): string
    {
        return 'Die Foodbook-Leitstelle lesen — führt eine KI durch die 7 Arbeits-Phasen (Bedarf→Struktur→Tiefe→'
            . 'Kapitel-Aufbau→Kreativ→Anlegen→Preise). OHNE chapter_id: 7-Schritt-Checkliste (Status offen|teil|erledigt '
            . '+ Sprungziel tab/anker) + Kapitel-Matrix (hat_ziele/positionen/hat_inhalt/bepreist/released + WE-Ampel je Kapitel). '
            . 'MIT chapter_id: zusätzlich der aufgelöste Kapitel-Stand (Ziele-Kaskade, Zielgruppen, Servierform/Einsatzmoment, '
            . 'Aggregat, Wareneinsatz-Ampel, Inhalts-/Ideen-Zähler, Anlage-Stand) + die Coverage-Befunde des Kapitels. '
            . 'READ-ONLY — das Kapitel-Go („Anlegen") ist human-only und läuft NICHT über MCP.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'foodbook_id' => ['type' => 'integer', 'description' => 'ID des Foodbooks (team-sichtbar).'],
                'chapter_id' => ['type' => 'integer', 'description' => 'Optional: Kapitel-ID → zusätzlich der aufgelöste Kapitel-Stand + Coverage-Befunde dieses Kapitels.'],
            ],
            'required' => ['foodbook_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->find((int) $arguments['foodbook_id']);
        if ($fb === null) {
            return ToolResult::error('Foodbook nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        }

        $leit = app(LeitstelleService::class);

        $out = [
            'foodbook' => [
                'id' => (int) $fb->id,
                'label' => (string) $fb->label,
                'personen' => $fb->personen !== null ? (int) $fb->personen : null,
            ],
            'checkliste' => $leit->checkliste($team, $fb),
        ];

        if (isset($arguments['chapter_id'])) {
            $kapitel = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $arguments['chapter_id']);
            if ($kapitel === null || (int) $kapitel->foodbook_id !== (int) $fb->id) {
                return ToolResult::error('Kapitel nicht gefunden oder gehört nicht zu diesem Foodbook.', 'NOT_FOUND');
            }

            $out['kapitel'] = $leit->kapitelStand($team, $kapitel);
            // Kapitel-Coverage: Befunde dieses Kapitels aus der Foodbook-Coverage (Scope = Kapitel+Nachfahren, E2.2/E4.3).
            $cov = app(CoverageService::class)->coverage($team, 'foodbook', (int) $fb->id);
            $out['kapitel']['coverage_befunde'] = collect($cov['befunde'] ?? [])
                ->where('chapter_id', (int) $kapitel->id)->values()->all();
        } else {
            $out['kapitel_matrix'] = $leit->kapitelMatrix($team, $fb);
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'foodbook', 'leitstelle', 'checkliste', 'kapitel', 'planung', 'we-ampel'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook.GET', 'foodalchemist.coverage.GET', 'foodalchemist.foodbook_kapitel.PUT', 'foodalchemist.zielgruppen.GET'],
            'examples' => ['Wo steht Foodbook 12 in den 7 Phasen?', 'Zeig mir den Planungs-Stand von Kapitel 40 im Foodbook 12'],
        ];
    }
}
