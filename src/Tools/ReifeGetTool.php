<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ReifeService;

/**
 * Spec 50 · Etappe 7 — „Was fehlt an diesem Artefakt noch?" für ALLE Ebenen. **Read-only.**
 *
 * Generalisiert {@see RecipeReifeGetTool} auf die Container: Konzept/Paket, Format, Foodbook,
 * Speisekarte, Speiseplan, Angebot — plus Rezept/Gericht, damit der Agent ein Werkzeug für die
 * Frage hat. `recipes.REIFE` bleibt als Rezept-Einstieg bestehen (Ebene wird dort selbst erkannt).
 *
 * Kein Provider-Call, keine Schreibwirkung.
 */
class ReifeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.reife.GET';
    }

    public function getDescription(): string
    {
        return 'Vollständigkeit eines Artefakts auf jeder Ebene: Rezept/Gericht, Konzept (auch Paket), Format, '
            . 'Foodbook, Speisekarte, Speiseplan, Angebot. Liefert `luecken` (je mit schwere blockiert|wichtig|hinweis, '
            . 'Weg als Tool + ggf. Slot-/Kapitel-/Block-Ids), `erfuellt`, `nicht_messbar` (ehrliche Degradation: '
            . 'kein Gerüst, keine Ampel-Metrik, C-9), `kennzahlen` und `naechste_schritte`. Ampel: rot = etwas '
            . 'blockiert (leere Pflicht-Position, Referenz ohne Ziel, keine Edition), gelb = Wichtiges offen, '
            . 'grün = nichts Gemessenes offen. Read-only, kein KI-Call. Die Reife referenzierter Objekte '
            . '(Editionen eines Formats, Konzepte eines Foodbooks) misst man mit demselben Tool je Objekt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => array_values(ReifeService::KINDS),
                    'description' => 'Artefakt-Typ: recipe|gericht|concept|paket|format|foodbook|speisekarte|speiseplan|angebot (offer).'],
                'id' => ['type' => 'integer', 'description' => 'Id des Artefakts (team-sichtbar).'],
            ],
            'required' => ['kind', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kind = strtolower(trim((string) ($arguments['kind'] ?? '')));
        $id = (int) ($arguments['id'] ?? 0);
        if ($kind === '' || $id <= 0) {
            return ToolResult::error('kind und id sind Pflicht.', 'VALIDATION_ERROR');
        }
        if (! in_array($kind, ReifeService::KINDS, true)) {
            return ToolResult::error("Unbekannter Artefakt-Typ «{$kind}». Erlaubt: " . implode(', ', ReifeService::KINDS) . '.', 'VALIDATION_ERROR');
        }

        $reife = app(ReifeService::class)->reife($team, $kind, $id);
        if ($reife === null) {
            return ToolResult::error(ucfirst($kind) . " {$id} nicht gefunden oder für dieses Team nicht sichtbar.", 'NOT_FOUND');
        }

        return ToolResult::success($reife + [
            'hinweis' => $reife['ampel'] === 'gruen'
                ? 'Nichts Gemessenes offen — das heisst nicht „freigabereif": die Freigabe bleibt menschlich.'
                : 'Offene Punkte in `luecken`; `naechste_schritte` nennt je einen Weg. `wie: null` = kein MCP-Werkzeug '
                    . 'kennt den Weg (UI). `nicht_messbar` sind Punkte ohne ehrliche Aussage.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'reife', 'vollstaendigkeit', 'luecken', 'konzept', 'format', 'foodbook', 'speisekarte', 'speiseplan', 'angebot', 'qualitaet'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.recipes.REIFE', 'foodalchemist.concepts.ENRICH', 'foodalchemist.concept_wording.GENERATE',
                'foodalchemist.foodbook.KUNDENTEXT_GENERATE', 'foodalchemist.format_editions.POST',
                'foodalchemist.coverage.GET',
            ],
            'examples' => [
                'Was fehlt an Konzept 812 noch, bevor es ins Foodbook kann?',
                'Ist Foodbook 41 druckreif — welche Kapitel sind leer?',
                'Format 7: hat es Editionen, und tragen die Header Titel?',
            ],
        ];
    }
}
