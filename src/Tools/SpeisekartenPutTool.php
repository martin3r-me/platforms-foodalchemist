<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Werkstrang M Phase A (Spec 40 §6): Speisekarten-KOPF aktualisieren — schließt die MCP-Lockstep-Lücke
 * (bisher nur POST/create). Erlaubt v. a. die Kontext-Leitplanken (kundentyp/default_niveau/
 * default_convenience/writing_style_id), die als Defaults nach unten in KI-Wording/Karten-Text fließen,
 * plus Name/Typ/Status/Fenster/Outlet. Team-scoped über {@see SpeisekarteService::update}.
 */
class SpeisekartenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarten.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert den KOPF einer Speisekarte (nur gesetzte Felder). Neben Name/Typ/Status/Fenster/'
            . 'Outlet v. a. die Kontext-Leitplanken kundentyp, default_niveau (buergerlich|gehoben|fine_dining), '
            . 'default_convenience (from_scratch|teil_convenience|voll_convenience), writing_style_id — sie wirken '
            . 'als Defaults nach unten (KI-Wording/Karten-Text). Rubriken/Positionen laufen über eigene Tools.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID der Speisekarte (team-sichtbar).'],
                'name' => ['type' => 'string'],
                'karten_typ' => ['type' => 'string', 'enum' => ['alacarte', 'tageskarte', 'saisonkarte', 'getraenkekarte', 'weinkarte']],
                'status' => ['type' => 'string', 'enum' => ['entwurf', 'aktiv', 'veroeffentlicht', 'archiviert']],
                'outlet_id' => ['type' => 'integer'],
                'description' => ['type' => 'string'],
                'kundentyp' => ['type' => 'string'],
                'default_niveau' => ['type' => 'string', 'enum' => ['buergerlich', 'gehoben', 'fine_dining']],
                'default_convenience' => ['type' => 'string', 'enum' => ['from_scratch', 'teil_convenience', 'voll_convenience']],
                'writing_style_id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        // Nur explizit übergebene Felder durchreichen (Patch-Semantik) — SpeisekarteService::FELDER
        // filtert final auf erlaubte Spalten.
        $felder = ['name', 'karten_typ', 'status', 'outlet_id', 'description',
            'kundentyp', 'default_niveau', 'default_convenience', 'writing_style_id'];
        $daten = [];
        foreach ($felder as $f) {
            if (array_key_exists($f, $arguments)) {
                $daten[$f] = $arguments[$f];
            }
        }
        if ($daten === []) {
            return ToolResult::error('Keine Felder zum Aktualisieren übergeben.', 'VALIDATION_ERROR');
        }

        try {
            $karte = app(SpeisekarteService::class)->update($team, (int) $arguments['id'], $daten);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'speisekarte' => [
                'id' => $karte->id, 'name' => $karte->name, 'status' => $karte->status,
                'karten_typ' => $karte->karten_typ, 'kundentyp' => $karte->kundentyp,
                'default_niveau' => $karte->default_niveau, 'default_convenience' => $karte->default_convenience,
                'writing_style_id' => $karte->writing_style_id,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'gastronomie', 'update', 'kontext', 'leitplanken'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarten.POST', 'foodalchemist.speisekarten.GET'],
            'examples' => ['Setze bei Speisekarte 12 das Niveau auf fine_dining', 'Aktualisiere den Kundentyp der Abendkarte'],
        ];
    }
}
