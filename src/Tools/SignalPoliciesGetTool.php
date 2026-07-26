<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SignalPolicyService;

/**
 * Spec 21 · E2 — Zustands-Sicht auf die Signale: je Typ eine Zeile mit Bestand,
 * Delta zum Vorlauf und Rausch-Guard-Bewertung. Beantwortet „welche Lagen sind
 * bekannt/akzeptiert und welche sind echter Alarm?"; die Einzel-Signale liefert
 * weiter `signale.SEARCH`. Read-only.
 */
class SignalPoliciesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signal_policies.GET';
    }

    public function getDescription(): string
    {
        return 'Zustands-Zeilen der Signale je Typ: offene Anzahl, Delta zum letzten Lauf und die '
            . 'Rausch-Guard-Policy (threshold = ab wann eine aggregierte Zeile statt Einzel-Alarmen, '
            . 'accepted_until = Lage bewusst akzeptiert bis Datum, muted = Typ ausgeblendet inkl. '
            . 'Drift-Alarm, note = Begründung). state ist alarm | akzeptiert | frist_abgelaufen | stumm; '
            . 'aggregiert=true heißt, die Signale-Seite zeigt für diesen Typ eine Zeile statt n. '
            . 'Gesetzt werden Policies mit signal_policy.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nur_alarm' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur Zeilen im Zustand alarm bzw. frist_abgelaufen.'],
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

        $zeilen = app(SignalPolicyService::class)->zustand($team);
        if ((bool) ($arguments['nur_alarm'] ?? false)) {
            $zeilen = array_values(array_filter($zeilen, fn ($z) => in_array($z['state'], [
                SignalPolicyService::STATE_ALARM,
                SignalPolicyService::STATE_FRIST_ABGELAUFEN,
            ], true)));
        }

        return ToolResult::success([
            'anzahl' => count($zeilen),
            'zustaende' => $zeilen,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'signal', 'policy', 'rauschen', 'schwelle', 'zustand', 'datenqualitaet'],
            'related_tools' => ['foodalchemist.signal_policy.PUT', 'foodalchemist.signal_trend.GET', 'foodalchemist.signale.SEARCH'],
            'examples' => [
                'Welche Signal-Lagen sind bekannt und akzeptiert?',
                'Zeig mir nur die echten Signal-Alarme',
            ],
        ];
    }
}
