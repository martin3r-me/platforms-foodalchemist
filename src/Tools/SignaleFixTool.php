<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalFixService;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * „KI erledigen lassen" per MCP (Lockstep zum Cockpit-Knopf).
 *
 * Plan metrik-fein (SignalCockpit::planFor):
 *  - deterministic → behebt den betroffenen Satz (scoped) und schließt das Signal bei count 0.
 *    Reversibel: das Schließen über foodalchemist.signale.PUT (wieder_oeffnen).
 *  - assist        → erzeugt einen Entwurf/Vorschlag via LLM (kein Schreiben, kein Close).
 *  - kein Plan     → ACTION_NOT_AVAILABLE (reine Urteilssache / externe Daten).
 *
 * Lockstep zu Spec 21 · S3b: `object_ids` schneidet auf eine Teilmenge (der Service
 * schneidet jede Auswahl gegen das Metrik-Prädikat — eine ID außerhalb wird nie
 * angefasst), `dry_run` liefert stattdessen die Fix-Vorschau („n Objekte, diese Felder,
 * diese Werte") ohne jede Mutation. Beides gilt nur für deterministische Pläne.
 */
class SignaleFixTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signale.FIX';
    }

    public function getDescription(): string
    {
        return 'Führt „KI erledigen lassen" für ein Signal aus: deterministischer Auto-Fix (Allergen-Konfidenz, '
            . 'Lead-LA-Repick+Recompute, Flavor-Anker) über den betroffenen Satz → Signal schließt bei 0; ODER '
            . 'eine KI-Assistenz (Lieferanten-Mail-Entwurf, Marge-Hebel, Servierform-Vorschlag) als Entwurf. '
            . 'Nicht jeder Signaltyp ist fixbar (dann ACTION_NOT_AVAILABLE). '
            . 'Beim Auto-Fix zählt fixed die geheilten Objekte und failed die technisch gescheiterten; '
            . 'fixed 0 mit failed 0 heißt „nichts auflösbar" (echte Daten-/Beschaffungslücke, ein '
            . 'erneuter Versuch bringt nichts), fixed 0 mit failed > 0 heißt „hier ist etwas kaputt".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_id' => ['type' => 'integer', 'description' => 'ID des Signals (foodalchemist.signale.SEARCH).'],
                'object_ids' => ['type' => 'array', 'items' => ['type' => 'integer'],
                    'description' => 'Optional: nur diese Objekte (Rezept-/GP-IDs) beheben statt des vollen betroffenen '
                        . 'Satzes. IDs, die das Prädikat des Signals nicht treffen, werden ignoriert.'],
                'dry_run' => ['type' => 'boolean',
                    'description' => 'Optional: nichts schreiben, sondern die Fix-Vorschau liefern (je Objekt: '
                        . 'welche Felder, welche Werte, wirkt/wirkt nicht). Nur für deterministische Fixes.'],
            ],
            'required' => ['signal_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $sig = FoodAlchemistSignal::visibleToTeam($team)->find((int) ($arguments['signal_id'] ?? 0));
        if ($sig === null) {
            return ToolResult::error('Signal nicht gefunden.', 'NOT_FOUND');
        }

        $plan = SignalCockpit::planFor($sig);
        if ($plan === null) {
            return ToolResult::error('Für dieses Signal gibt es keinen automatischen Fix/Assistenz-Schritt (Urteilssache).', 'ACTION_NOT_AVAILABLE');
        }

        $ids = $arguments['object_ids'] ?? null;
        $ids = is_array($ids) && $ids !== [] ? array_values(array_map('intval', $ids)) : null;
        $dryRun = (bool) ($arguments['dry_run'] ?? false);

        $svc = app(SignalFixService::class);
        try {
            if ($plan['kind'] === 'deterministic') {
                if ($dryRun) {
                    $v = $svc->vorschau($team, $sig, $ids);

                    return ToolResult::success([
                        'signal_id' => (int) $sig->id, 'kind' => 'deterministic', 'dry_run' => true,
                        'fixer' => $v['fixer'], 'scope' => $v['scope'], 'total' => $v['total'],
                        'inspected' => $v['gezeigt'], 'would_change' => $v['wirkt'], 'unchanged' => $v['wirkt_nicht'],
                        'items' => $v['items'],
                    ]);
                }

                $res = $svc->execute($team, $sig, $ids);   // MCP = synchron; UI nutzt den Job

                return ToolResult::success([
                    'signal_id' => (int) $sig->id, 'kind' => 'deterministic', 'scope' => $res['scope'],
                    'fixed' => $res['fixed'],
                    // 22·H3b · V-013: technische Fehlschläge getrennt von echten Lücken.
                    // Ohne diese Zahl ist `fixed: 0` zweideutig — „nichts auflösbar" oder
                    // „alles geworfen" — und ein LLM kann nicht entscheiden, ob ein
                    // erneuter Versuch überhaupt Sinn hat.
                    'failed' => $res['failed'],
                    'remaining' => $res['remaining'], 'closed' => $res['closed'],
                ]);
            }
            if ($dryRun) {
                return ToolResult::error('Eine Fix-Vorschau gibt es nur für automatische Fixes (dieser Plan ist KI-Assistenz).', 'ACTION_NOT_AVAILABLE');
            }

            $res = $svc->assist($team, $sig);

            return ToolResult::success([
                'signal_id' => (int) $sig->id, 'kind' => 'assist',
                'draft' => $res['draft'], 'confidence' => $res['confidence'], 'signal_closed' => false,
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'signal', 'fix', 'ki', 'assist', 'datenqualitaet'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.signale.SEARCH', 'foodalchemist.signale.PUT'],
            'examples' => ['Behebe Signal 12 automatisch', 'Erzeuge den KI-Entwurf für Signal 7',
                'Zeig mir die Fix-Vorschau für Signal 12 (dry_run)', 'Behebe an Signal 12 nur die Rezepte 44 und 51'],
        ];
    }
}
