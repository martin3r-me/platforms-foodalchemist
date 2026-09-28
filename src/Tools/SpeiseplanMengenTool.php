<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Spec 57 · Paket 3: Mengen einer Speiseplan-Woche lesen (GET) — Linie × Öffnungstag, Vorwoche,
 * Ø 4 Wochen, Summe, Anteil, Wareneinsatz, Umsatz. Schreiben über {@see SpeiseplanMengenPutTool}.
 */
class SpeiseplanMengenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_mengen.GET';
    }

    public function getDescription(): string
    {
        return 'Mengen (Essen) einer Speiseplan-Woche: je Linie × Öffnungstag die Essen der Zelle (null = unbelegt), '
            . 'Vorwoche und Ø 4 Wochen (Planwerte), Summe, Anteil, Wareneinsatz %, Ø VK netto, Umsatz. montag = ein Tag der Woche.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'montag' => ['type' => 'string', 'description' => 'Ein Tag der Woche, YYYY-MM-DD.'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack'], 'default' => 'mittag'],
            ],
            'required' => ['plan_id', 'montag'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SpeiseplanService::class);
        $plan = $svc->detail($team, (int) ($arguments['plan_id'] ?? 0));
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $montag = Carbon::parse((string) ($arguments['montag'] ?? ''));
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error('montag unlesbar: ' . $e->getMessage(), 'VALIDATION_ERROR');
        }
        $mahlzeit = array_key_exists((string) ($arguments['mahlzeit'] ?? ''), SpeiseplanService::MAHLZEITEN) ? (string) $arguments['mahlzeit'] : 'mittag';

        return ToolResult::success(['plan_id' => (int) $plan->id, 'mahlzeit' => $mahlzeit] + $svc->mengenMatrix($team, $plan, $mahlzeit, $montag));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'mengen', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplan_mengen.PUT', 'foodalchemist.speiseplan.ANPRODUKTION'],
            'examples' => ['Wie viele Essen sind bei Speiseplan 3 in KW 41 mittags geplant?'],
        ];
    }
}
