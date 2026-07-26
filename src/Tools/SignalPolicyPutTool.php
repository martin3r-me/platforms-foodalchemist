<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalPolicyService;

/**
 * Spec 21 · E2 — Rausch-Guard setzen. Bewusst ein **expliziter, menschlich
 * getriggerter** Call (Spec §8): kein Detektor darf seine eigenen Alarme dämpfen.
 * Vollständig reversibel — `zuruecksetzen` entfernt die Policy des eigenen Teams,
 * die Signale selbst bleiben in jedem Fall unangetastet.
 */
class SignalPolicyPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signal_policy.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt den Rausch-Guard für einen Signal-Typ: threshold (ab so vielen offenen Signalen '
            . 'zeigt die Signale-Seite eine aggregierte Zustands-Zeile statt n Einzel-Alarme), '
            . 'accepted_until (Lage bekannt und akzeptiert bis Datum JJJJ-MM-TT), note (Begründung), '
            . 'muted (Typ ausblenden — unterdrückt als einziger Regler auch das Drift-Signal). '
            . 'Wichtig: Schwelle und Akzeptanz dämpfen nur den BESTAND, ein Zuwachs schlägt weiter als '
            . 'qualitaet_drift durch. Kein Signal wird gelöscht. zuruecksetzen=true entfernt die Policy.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => array_map(fn (SignalTyp $t) => $t->value, SignalTyp::cases())],
                'threshold' => ['type' => ['integer', 'null'], 'minimum' => 0, 'description' => 'Ab dieser Anzahl aggregieren; null hebt auf.'],
                'accepted_until' => ['type' => ['string', 'null'], 'description' => 'Datum JJJJ-MM-TT (inklusive); null hebt auf.'],
                'note' => ['type' => ['string', 'null'], 'description' => 'Begründung — wird in der Zustands-Zeile angezeigt.'],
                'muted' => ['type' => 'boolean'],
                'zuruecksetzen' => ['type' => 'boolean', 'default' => false, 'description' => 'Policy des eigenen Teams entfernen.'],
            ],
            'required' => ['type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $typ = SignalTyp::tryFrom((string) ($arguments['type'] ?? ''));
        if ($typ === null) {
            return ToolResult::error('Unbekannter Signal-Typ.', 'VALIDATION_ERROR');
        }
        $svc = app(SignalPolicyService::class);

        if ((bool) ($arguments['zuruecksetzen'] ?? false)) {
            return ToolResult::success(['type' => $typ->value, 'entfernt' => $svc->loeschen($team, $typ)]);
        }

        $attrs = array_intersect_key($arguments, array_flip(['threshold', 'accepted_until', 'note', 'muted']));
        if ($attrs === []) {
            return ToolResult::error('Kein Regler angegeben (threshold, accepted_until, note, muted).', 'VALIDATION_ERROR');
        }

        try {
            $policy = $svc->setzen($team, $typ, $attrs);
        } catch (\Throwable $e) {
            return ToolResult::error('Policy konnte nicht gesetzt werden (Datum im Format JJJJ-MM-TT?).', 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'type' => $typ->value,
            'threshold' => $policy->threshold,
            'accepted_until' => $policy->accepted_until?->toDateString(),
            'note' => $policy->note,
            'muted' => (bool) $policy->muted,
            'hinweis' => 'Der Bestand wird gedämpft, ein Zuwachs meldet sich weiter als qualitaet_drift.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'signal', 'policy', 'rauschen', 'schwelle', 'muted', 'akzeptiert'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates', 'updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.signal_policies.GET', 'foodalchemist.signal_trend.GET'],
            'examples' => [
                'Akzeptiere die teil-unbepreisten Basisrezepte bis 31.08.2026',
                'Ab 200 offenen Signalen dieses Typs nur noch eine Zustands-Zeile zeigen',
            ],
        ];
    }
}
