<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\HandbuchService;

/**
 * Spec 86 — Gemeinsamer Rumpf der Handbuch-Tools (PUBLISH | WITHDRAW | GET je Ausgabe-Dokument).
 * Je Dokumenttyp eigene Tool-Namen (`foodalchemist.<typ>_handbuch.<VERB>`), damit die Rechte-Hülle
 * sie über das Ressourcen-Präfix dem richtigen Bereich zuordnet (Foodbook-Recht ≠ Speisekarten-Recht).
 */
abstract class HandbuchToolBasis extends FoodAlchemistTool
{
    /** foodbook | speisekarte | speiseplan | angebot */
    abstract protected function typ(): string;

    /** PUBLISH | WITHDRAW | GET */
    abstract protected function verb(): string;

    public function getName(): string
    {
        return 'foodalchemist.' . $this->typ() . '_handbuch.' . $this->verb();
    }

    public function getDescription(): string
    {
        $dok = ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'angebot' => 'Angebot'][$this->typ()];

        return match ($this->verb()) {
            'PUBLISH' => "Veröffentlicht das Handbuch (Standards zum Nachschlagen: Zubereitung, Anrichten, Regeneration, Bestandteile, Allergene je Gericht) zu einer {$dok} als eigenen Lese-Link ohne Login, "
                . 'getrennt vom Kundenlink. Eingefroren, ohne Preise. expires_at (gültig bis) ist Pflicht. Antwort enthält die Lücken (Gerichte ohne Zubereitung/Anrichten/Regeneration).',
            'WITHDRAW' => "Zieht das Handbuch einer {$dok} zurück (Link → 404). Snapshot und Token bleiben erhalten, erneutes Veröffentlichen behält den Link.",
            default => "Liefert den Handbuch-Status einer {$dok}: veröffentlicht/erreichbar, Link, Ablauf; mit luecken=true zusätzlich die Gerichte mit fehlenden Abschnitten.",
        };
    }

    public function getSchema(): array
    {
        $props = [$this->typ() . '_id' => ['type' => 'integer']];
        if ($this->verb() === 'PUBLISH') {
            $props['expires_at'] = ['type' => 'string', 'description' => 'Gültig bis (Datum ISO, Pflicht — kein Link ohne Ablauf)'];
            $props['kapitel'] = ['type' => 'array', 'description' => 'Optional: freie Kapitel (Hygiene, Ausgabe-Regeln …) als Liste {title, text}; Klartext, max. 20. Fehlt der Parameter, bleiben die zuletzt veröffentlichten Kapitel stehen.',
                'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'text' => ['type' => 'string']]]];
        }
        if ($this->verb() === 'GET') {
            $props['luecken'] = ['type' => 'boolean', 'description' => 'Zusätzlich die Lücken des aktuellen Standes rechnen (rechnet die ganze Komposition, nur bei Bedarf).'];
        }

        return [
            'type' => 'object',
            'properties' => $props,
            'required' => $this->verb() === 'PUBLISH' ? [$this->typ() . '_id', 'expires_at'] : [$this->typ() . '_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments[$this->typ() . '_id'] ?? 0);
        $svc = app(HandbuchService::class);
        try {
            return match ($this->verb()) {
                'PUBLISH' => ToolResult::success($svc->publish($team, $this->typ(), $id, ['expires_at' => $arguments['expires_at'] ?? null]
                    + (array_key_exists('kapitel', $arguments) ? ['kapitel' => (array) $arguments['kapitel']] : []))),
                'WITHDRAW' => $this->zurueckziehen($svc, $team, $id),
                default => ToolResult::success($svc->status($team, $this->typ(), $id) + (
                    ($arguments['luecken'] ?? false) ? ['luecken' => $svc->luecken($team, $this->typ(), $id)] : []
                )),
            };
        } catch (ModelNotFoundException) {
            return ToolResult::error('Dokument nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    private function zurueckziehen(HandbuchService $svc, $team, int $id): ToolResult
    {
        $svc->withdraw($team, $this->typ(), $id);

        return ToolResult::success(['ok' => true, $this->typ() . '_id' => $id]);
    }

    public function getMetadata(): array
    {
        $lesen = $this->verb() === 'GET';
        $verwandt = array_map(fn ($v) => 'foodalchemist.' . $this->typ() . '_handbuch.' . $v, ['PUBLISH', 'WITHDRAW', 'GET']);

        return [
            'category' => $lesen ? 'query' : 'command',
            'tags' => ['foodalchemist', $this->typ(), 'handbuch', 'standards', $lesen ? 'status' : ($this->verb() === 'PUBLISH' ? 'publish' : 'zurueckziehen')],
            'read_only' => $lesen,
            'idempotent' => $this->verb() !== 'PUBLISH',
            'risk_level' => $lesen ? 'read' : 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => array_values(array_diff($verwandt, [$this->getName()])),
            'examples' => ['Veröffentliche das Handbuch zu ' . $this->typ() . ' 12, gültig bis 2027-12-31.'],
        ];
    }
}
