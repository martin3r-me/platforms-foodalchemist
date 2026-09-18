<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Spec 53 Paket J: Bulk-Import von GP↔Anker-Zuordnungen (bis 500 Einträge je Aufruf) — die
 * Brücke für die Vault-Altdaten (alt→neu, Namensabgleich). Idempotent je (gp_id, anchor_slug):
 * existiert die Zuordnung schon aktiv → `unveraendert`; sonst → `angenommen` (respektiert
 * CAP_GP im Service, ein Überschreiten → `abgelehnt` mit Grund). `ersetze_alle=true` löscht
 * VORHER alle bestehenden Anker DIESES GP (einmal je gp_id im Batch, auch wenn mehrere Einträge
 * desselben GP mit ersetze_alle=true kommen) — die gelieferten Einträge ersetzen den Bestand.
 */
class GpAnchorsImportTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const MAX_EINTRAEGE = 500;

    public function getName(): string
    {
        return 'foodalchemist.gp_anchors.IMPORT';
    }

    public function getDescription(): string
    {
        return 'Bulk-Import von GP↔Aroma-Anker-Zuordnungen (bis '.self::MAX_EINTRAEGE.' Einträge). '
            . 'Idempotent je (gp_id, anchor_slug): unveränderte Zuordnung → status=unveraendert, neue → '
            . 'angenommen, unbekannter gp_id/anchor_slug oder CAP_GP überschritten → abgelehnt (mit Grund). '
            . 'ersetze_alle=true je Eintrag löscht vorher ALLE bestehenden Anker dieses GP (einmal pro gp_id '
            . 'im Batch) — die gelieferten Einträge ersetzen den Bestand vollständig. source ist frei '
            . '(z. B. bridge_alt_neu, exact_name, manual).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'eintraege' => [
                    'type' => 'array', 'maxItems' => self::MAX_EINTRAEGE,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'gp_id' => ['type' => 'integer'],
                            'anchor_slug' => ['type' => 'string'],
                            'source' => ['type' => 'string', 'description' => 'Frei, z. B. bridge_alt_neu, exact_name, manual.'],
                            'role' => ['type' => 'string', 'enum' => ['kern', 'neben'], 'default' => 'kern'],
                            'ai_confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'ersetze_alle' => ['type' => 'boolean', 'default' => false, 'description' => 'Löscht vorher alle bestehenden Anker dieses GP (einmal je gp_id im Batch).'],
                        ],
                        'required' => ['gp_id', 'anchor_slug', 'source'],
                    ],
                ],
            ],
            'required' => ['eintraege'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $eintraege = (array) ($arguments['eintraege'] ?? []);
        if ($eintraege === []) {
            return ToolResult::error('eintraege darf nicht leer sein.', 'VALIDATION_ERROR');
        }
        if (count($eintraege) > self::MAX_EINTRAEGE) {
            return ToolResult::error('Höchstens '.self::MAX_EINTRAEGE.' Einträge je Aufruf.', 'VALIDATION_ERROR');
        }

        $svc = app(PairingService::class);
        $gpService = app(GpService::class);
        $bereitsGeleert = [];   // gp_id => true — ersetze_alle nur EINMAL je gp_id im Batch
        $ergebnis = [];

        foreach ($eintraege as $eintrag) {
            $gpId = (int) ($eintrag['gp_id'] ?? 0);
            $slug = trim((string) ($eintrag['anchor_slug'] ?? ''));
            $source = trim((string) ($eintrag['source'] ?? ''));
            $rolleRoh = $eintrag['role'] ?? 'kern';
            $role = in_array($rolleRoh, ['kern', 'neben'], true) ? $rolleRoh : 'kern';
            $ersetzeAlle = (bool) ($eintrag['ersetze_alle'] ?? false);
            $confidence = isset($eintrag['ai_confidence']) ? max(0.0, min(1.0, (float) $eintrag['ai_confidence'])) : null;

            $gp = $gpId > 0 ? $gpService->find($gpId, $team) : null;
            if ($gp === null) {
                $ergebnis[] = ['gp_id' => $gpId, 'anchor_slug' => $slug, 'status' => 'abgelehnt', 'grund' => 'GP nicht sichtbar/vorhanden.'];

                continue;
            }
            if ($source === '') {
                $ergebnis[] = ['gp_id' => $gpId, 'anchor_slug' => $slug, 'status' => 'abgelehnt', 'grund' => 'source ist Pflicht.'];

                continue;
            }
            $ankerId = $this->pairingAnkerIdFuerSlug($team, $slug);
            if ($ankerId === null) {
                $ergebnis[] = ['gp_id' => $gpId, 'anchor_slug' => $slug, 'status' => 'abgelehnt', 'grund' => 'Anker-Slug unbekannt/nicht sichtbar.'];

                continue;
            }

            if ($ersetzeAlle && ! isset($bereitsGeleert[$gp->id])) {
                $svc->clearGpAnker($team, $gp->id);
                $bereitsGeleert[$gp->id] = true;
            }

            $vorhanden = \Illuminate\Support\Facades\DB::table('foodalchemist_gp_anchor_mappings')
                ->where('gp_id', $gp->id)->where('anchor_id', $ankerId)->whereNull('deleted_at')->first();
            if ($vorhanden !== null && $vorhanden->role === $role && $vorhanden->source === $source) {
                $ergebnis[] = ['gp_id' => (int) $gp->id, 'anchor_slug' => $slug, 'status' => 'unveraendert'];

                continue;
            }

            try {
                $svc->setGpAnker($team, $gp->id, $ankerId, $role, $source, $confidence);
                $ergebnis[] = ['gp_id' => (int) $gp->id, 'anchor_slug' => $slug, 'status' => 'angenommen', 'role' => $role];
            } catch (\RuntimeException $e) {
                $ergebnis[] = ['gp_id' => (int) $gp->id, 'anchor_slug' => $slug, 'status' => 'abgelehnt', 'grund' => $e->getMessage()];
            }
        }

        return ToolResult::success([
            'gesamt' => count($ergebnis),
            'angenommen' => count(array_filter($ergebnis, fn ($e) => $e['status'] === 'angenommen')),
            'unveraendert' => count(array_filter($ergebnis, fn ($e) => $e['status'] === 'unveraendert')),
            'abgelehnt' => count(array_filter($ergebnis, fn ($e) => $e['status'] === 'abgelehnt')),
            'eintraege' => $ergebnis,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'anker', 'pairing', 'aroma', 'import', 'write', 'bulk'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_anchors.PUT', 'foodalchemist.gp_anchors.GET', 'foodalchemist.composer.ANKER_SUCHE'],
            'examples' => ['Importiere 200 GP↔Anker-Zuordnungen aus der Vault-Brücke alt→neu.'],
        ];
    }
}
