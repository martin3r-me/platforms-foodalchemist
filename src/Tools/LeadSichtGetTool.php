<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Preis\LeadSichtNachlauf;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Lead-Sicht (Kurator 29.10.2026), read-only: Vorschau des Nachlaufs — Mandanten, deren globaler Lead-Artikel für sie nicht sichtbar ist, mit künftigem
 * effektivem Lead und betroffenen Rezepten. Spiegel von `foodalchemist:lead-sicht-nachlauf` ohne --apply (Dominique: Läufe per MCP).
 */
class LeadSichtGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lead_sicht.GET';
    }

    public function getDescription(): string
    {
        return 'Vorschau Lead-Sicht (read-only): GPs, deren globaler Lead-Artikel für einen Mandanten nicht sichtbar ist (Stamm-Lieferant nicht freigeschaltet) '
            . 'und die in Rezepten stehen — je GP der bisherige Lead, der künftige effektive Lead (Ersatz nach Rangliste; null = kein sichtbarer Ersatz, dann Ø '
            . 'oder unbepreist), die betroffenen eigenen Rezepte und die Zahl der Team-Kosten-Zeilen. Das Master-Team sieht alle Mandanten (optional team_id), '
            . 'jedes andere Team nur sich selbst. Schreibt nichts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional, nur für das Master-Team: nur diesen Mandanten prüfen'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (StammPaket::sieheAlleLieferanten($team)) {
            $ziel = isset($arguments['team_id']) ? Team::find((int) $arguments['team_id']) : null;
            if (isset($arguments['team_id']) && $ziel === null) {
                return ToolResult::error('Team nicht gefunden.', 'NOT_FOUND');
            }
        } else {
            $ziel = $team;   // Mandant: nur die eigene Sicht
        }
        $teams = app(LeadSichtNachlauf::class)->vorschau($ziel);

        return ToolResult::success([
            'teams' => $teams,
            'summe' => ['teams' => count($teams), 'gps' => array_sum(array_map(fn ($t) => count($t['gps']), $teams)),
                'rezepte_eigen' => array_sum(array_map(fn ($t) => count($t['rezepte_eigen']), $teams))],
            'hinweis' => 'Read-only. Ausführen: Befehl foodalchemist:lead-sicht-nachlauf --apply (nach Freigabe).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lead', 'lieferant', 'stamm', 'mandant', 'sicht', 'kosten'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gp_lead.GET', 'foodalchemist.lead_la.REPICK'],
            'examples' => ['Welche Mandanten rechnen mit einem Lead-Artikel, den sie gar nicht beziehen können?'],
        ];
    }
}
