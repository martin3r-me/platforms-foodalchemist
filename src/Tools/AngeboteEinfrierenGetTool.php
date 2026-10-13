<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;

/** Spec 82 §12 B3 · Eingefrorener Stand eines Angebots lesen: Zeilen (Fassungs-IDs, EK mit Status, Allergene) und „neuere Fassung verfügbar“ mit Allergen-Diff. */
class AngeboteEinfrierenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.EINFRIEREN_GET';
    }

    public function getDescription(): string
    {
        return 'Liest den eingefrorenen Stand eines Angebots: eingefroren am/von, je Gericht mit Abo-Fassung die Fassungs-IDs, EK (Teilsumme + vollständig-Flag, nie 0 € als Preis), VK (null ohne vollständigen EK) und Allergene, '
            .'dazu „veraltet“: eingefrorene Fassungen, zu denen es beim Mandanten eine neuere gibt, mit Allergen-Diff (Endstand) und den Zwischenfassungen. Schreibt nichts.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Angebot-Id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find((int) ($arguments['id'] ?? 0));
        if ($angebot === null) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $svc = app(AngebotEinfrierService::class);
        $e = $svc->aktive($angebot);

        return ToolResult::success([
            'angebot_id' => (int) $angebot->id, 'eingefroren' => $e !== null,
            'einfrierung' => $e === null ? null : [
                'eingefroren_at' => $e->eingefroren_at?->toIso8601String(), 'eingefroren_von' => $e->eingefroren_von, 'status_beim_einfrieren' => $e->status_beim_einfrieren,
                'zeilen' => $e->zeilen->map(fn ($z) => ['id' => $z->id, 'recipe_id' => $z->recipe_id, 'name' => $z->name, 'fassungen' => $z->fassungen,
                    'ek_summe' => $z->ek_summe, 'ek_per_kg' => $z->ek_per_kg, 'vollstaendig' => $z->vollstaendig, 'grund' => $z->grund, 'vk' => $z->vk, 'allergene' => $z->allergene])->all(),
            ],
            'veraltet' => $svc->veraltet($angebot),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'angebot', 'einfrieren', 'fassung', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.angebote.EINFRIEREN_POST', 'foodalchemist.angebote.STATUS'],
            'examples' => ['Ist Angebot 5 eingefroren, und gibt es neuere Fassungen?'],
        ];
    }
}
