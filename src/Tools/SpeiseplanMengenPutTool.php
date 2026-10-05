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
 * Spec 57 · Paket 3: Mengen setzen — einzelne Zellen (Linie × Datum) oder für die ganze Woche
 * „aus Vorwoche übernehmen“ bzw. „skalieren“. Schreibt die Pax-Overrides der Einträge.
 */
class SpeiseplanMengenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_mengen.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt Mengen (Essen) eines team-eigenen Speiseplans. Entweder zellen=[{line_id, datum YYYY-MM-DD, pax}] '
            . '(pax 0 = zurück auf Standard) ODER aktion=vorwoche (Mengen der Vorwoche übernehmen) bzw. aktion=skalieren '
            . 'mit faktor (0–10) für die Woche von montag. mahlzeit Standard mittag.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack'], 'default' => 'mittag'],
                'zellen' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{line_id, datum, pax}]'],
                'aktion' => ['type' => 'string', 'enum' => ['vorwoche', 'skalieren']],
                'montag' => ['type' => 'string', 'description' => 'Woche für aktion (ein Tag der Woche genügt).'],
                'faktor' => ['type' => 'number', 'description' => 'Nur bei aktion=skalieren.'],
            ],
            'required' => ['plan_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }
        $mahlzeit = array_key_exists((string) ($arguments['mahlzeit'] ?? ''), SpeiseplanService::MAHLZEITEN) ? (string) $arguments['mahlzeit'] : 'mittag';
        $svc = app(SpeiseplanService::class);

        try {
            if (isset($arguments['aktion'])) {
                $montag = Carbon::parse((string) ($arguments['montag'] ?? ''));
                $n = match ($arguments['aktion']) {
                    'vorwoche' => $svc->uebernehmeVorwoche($team, $planId, $mahlzeit, $montag),
                    'skalieren' => $svc->skaliereWoche($team, $planId, $mahlzeit, $montag, (float) ($arguments['faktor'] ?? 0)),
                    default => throw new \RuntimeException('aktion muss vorwoche oder skalieren sein.'),
                };

                return ToolResult::success(['plan_id' => $planId, 'aktion' => $arguments['aktion'], 'geaenderte_eintraege' => $n]);
            }
            $zellen = $arguments['zellen'] ?? null;
            if (! is_array($zellen) || $zellen === []) {
                return ToolResult::error('zellen (nicht leer) oder aktion angeben.', 'VALIDATION_ERROR');
            }
            $n = 0;
            foreach ($zellen as $z) {
                if (! is_array($z) || empty($z['line_id']) || empty($z['datum'])) {
                    return ToolResult::error('Jede Zelle braucht line_id und datum.', 'VALIDATION_ERROR');
                }
                $n += $svc->setzeZellenPax($team, $planId, (int) $z['line_id'], (string) $z['datum'], $mahlzeit, $z['pax'] ?? 0);
            }
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => $planId, 'geaenderte_eintraege' => $n]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'mengen', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplan_mengen.GET', 'foodalchemist.speiseplan_eintraege.PAX'],
            'examples' => ['Setze bei Speiseplan 3 die Linie 5 am 2026-10-06 auf 140 Essen.', 'Übernimm bei Speiseplan 3 die Mengen der Vorwoche für KW 42.'],
        ];
    }
}
