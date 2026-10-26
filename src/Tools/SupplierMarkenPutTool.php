<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;

/** GP-Regelwerk v3.7.1 §5 Ausnahme 3 (write): Rolle, Kurzname und Marken-Kürzel pflegen — nur Plattform-Admin und Admin im Master-Team. */
class SupplierMarkenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_marken.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt die Marken-Regel-Daten (Regelwerk §5 Ausnahme 3, global, nur Plattform-Admin/Master-Admin): mit supplier_id rolle (hersteller|haendler|offen) und kurzname '
            . '(Pflicht bei hersteller) sowie Kürzel dieses Lieferanten; ohne supplier_id die globalen Kürzel. kuerzel_setzen = Liste {kuerzel, marke, typ (eigenmarke|herstellermarke)}, '
            . 'kuerzel_entfernen = Liste von Kürzeln. Kürzel klein ohne Punkt; Zertifizierungen (MSC) sind nie eine Marke. Alles in einer Transaktion.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'Lieferant; leer = globale Kürzel'],
                'rolle' => ['type' => 'string', 'enum' => ['hersteller', 'haendler', 'offen']],
                'kurzname' => ['type' => 'string', 'description' => 'Schreibweise in der Klammer, z. B. Heisters (nur bei hersteller)'],
                'kuerzel_setzen' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'kuerzel' => ['type' => 'string'], 'marke' => ['type' => 'string'], 'typ' => ['type' => 'string', 'enum' => ['eigenmarke', 'herstellermarke']],
                    'position' => ['type' => 'string', 'enum' => ['anfang', 'ende'], 'description' => 'anfang = erste drei Wörter (Standard), ende = letztes Wort (z. B. Handelshof „… HENG.“)'],
                    'vorrang' => ['type' => 'integer', 'description' => '0–9; bei mehreren Treffern gewinnt der höhere (Produktlinie vor Dachmarke)']],
                    'required' => ['kuerzel', 'marke']]],
                'kuerzel_entfernen' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $pflege = app(MarkenPflegeService::class);
        $user = $context->user ?? null;
        if (! $pflege->darf($user)) {
            return ToolResult::error('Rolle, Kurzname und Marken-Kürzel pflegen nur Plattform-Admin und Admin im Master-Team.', 'ACCESS_DENIED');
        }
        $id = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
        $lieferant = $id !== null ? FoodAlchemistSupplier::visibleToTeam($team)->find($id) : null;
        if ($id !== null && $lieferant === null) {
            return ToolResult::error('Lieferant nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ($id === null && isset($arguments['rolle'])) {
            return ToolResult::error('rolle/kurzname brauchen eine supplier_id.', 'VALIDATION_ERROR');
        }

        try {
            $ergebnis = DB::transaction(function () use ($pflege, $user, $id, $lieferant, $arguments) {
                $out = ['gesetzt' => [], 'entfernt' => []];
                if ($lieferant !== null && isset($arguments['rolle'])) {
                    $l = $pflege->rolleSetzen($user, $lieferant, (string) $arguments['rolle'], $arguments['kurzname'] ?? null);
                    $out['rolle'] = $l->rolle?->value;
                    $out['kurzname'] = $l->kurzname;
                }
                foreach ((array) ($arguments['kuerzel_setzen'] ?? []) as $k) {
                    $z = $pflege->kuerzelSetzen($user, $id, (string) ($k['kuerzel'] ?? ''), (string) ($k['marke'] ?? ''), (string) ($k['typ'] ?? 'herstellermarke'),
                        (string) ($k['position'] ?? 'anfang'), (int) ($k['vorrang'] ?? 0));
                    $out['gesetzt'][] = ['kuerzel' => $z->kuerzel, 'marke' => $z->marke, 'typ' => $z->typ, 'position' => $z->position, 'vorrang' => (int) $z->vorrang];
                }
                foreach ((array) ($arguments['kuerzel_entfernen'] ?? []) as $k) {
                    if ($pflege->kuerzelEntfernen($user, $id, (string) $k)) {
                        $out['entfernt'][] = MarkenPflegeService::normKuerzel((string) $k);
                    }
                }

                return $out;
            });
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['supplier_id' => $id, 'global' => $id === null] + $ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lieferant', 'marke', 'kuerzel', 'hersteller', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.supplier_marken.GET', 'foodalchemist.artikel.MARKE'],
            'examples' => ['Stufe Bäckerei Heisters als Hersteller mit Kurzname Heisters ein.', 'Lege bei Chefs Culinar das Kürzel BC → Base Culinar (Eigenmarke) an.'],
        ];
    }
}
