<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\IdeenService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E6.5): Kreativ-Skizze ODER Paket-Gruppe anlegen (nur Entwürfe).
 * Owner-XOR chapter_id/concept_id. Diskriminator `objekt`:
 *  - objekt=idee (Default): freie Skizze (title Pflicht) ODER Bestands-Übernahme
 *    (sales_recipe_id = echtes VK-Gericht, loser Zeiger — dupliziert NICHTS). ziel_form
 *    einzel|paket; paket ⇒ paket_gruppe (group_id) nötig.
 *  - objekt=gruppe: leere Paket-Gruppe (name Pflicht, paket_zielpreis_pp = €/Gast-Ziel).
 *
 * **Invariante (M4):** Ideen/Gruppen erzeugen NIE Rezepte/GPs/Konzepte — erst der Kapitel-Go
 * (E7, human-only, KEIN MCP-Trigger) materialisiert. Vokabular-Pflicht: ziel_form per Const.
 */
class KapitelIdeenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kapitel_ideen.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Kreativ-Skizze (objekt=idee) oder eine Paket-Gruppe (objekt=gruppe) an, '
            . 'immer als Entwurf am Owner (chapter_id XOR concept_id). Skizze: title (Pflicht) oder '
            . 'sales_recipe_id (Bestands-Gericht als Idee). Paket-Zuordnung via ziel_form=paket + '
            . 'paket_gruppe. Gruppe: name (Pflicht) + paket_zielpreis_pp. '
            . 'Erzeugt KEINE Rezepte/Konzepte — das Anlegen macht ein Mensch beim Kapitel-Go.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'objekt' => ['type' => 'string', 'enum' => ['idee', 'gruppe'], 'default' => 'idee', 'description' => 'idee = Skizze, gruppe = Paket-Gruppe'],
                'chapter_id' => ['type' => 'integer', 'description' => 'Owner-Kapitel (XOR concept_id)'],
                'concept_id' => ['type' => 'integer', 'description' => 'Owner-Konzept (XOR chapter_id)'],
                // objekt=idee
                'title' => ['type' => 'string', 'description' => 'Skizzen-Titel (Pflicht bei freier Idee)'],
                'description' => ['type' => 'string', 'description' => 'Optionale Skizzen-Beschreibung'],
                'sales_recipe_id' => ['type' => 'integer', 'description' => 'Bestands-Übernahme: echtes VK-Gericht als Idee (loser Zeiger)'],
                'ziel_form' => ['type' => 'string', 'enum' => FoodAlchemistDishIdea::TARGET_FORMS, 'description' => 'einzel | paket (paket ⇒ paket_gruppe nötig)'],
                'paket_gruppe' => ['type' => 'integer', 'description' => 'group_id einer bestehenden Paket-Gruppe (bei ziel_form=paket)'],
                // objekt=gruppe
                'name' => ['type' => 'string', 'description' => 'Paket-Name (Pflicht bei objekt=gruppe; wird beim Go zum Konzept-Namen)'],
                'paket_zielpreis_pp' => ['type' => 'number', 'description' => '€/Gast-Ziel des Pakets'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(IdeenService::class);
        $objekt = (string) ($arguments['objekt'] ?? 'idee');

        $owner = [
            'chapter_id' => isset($arguments['chapter_id']) ? (int) $arguments['chapter_id'] : null,
            'concept_id' => isset($arguments['concept_id']) ? (int) $arguments['concept_id'] : null,
        ];

        try {
            if ($objekt === 'gruppe') {
                $gruppe = $svc->addGruppe($team, $owner + [
                    'name' => $arguments['name'] ?? null,
                    'target_price_pp' => $arguments['paket_zielpreis_pp'] ?? null,
                ]);

                return ToolResult::success(['gruppe' => $this->paketArr($gruppe)]);
            }
            if ($objekt !== 'idee') {
                return ToolResult::error('objekt muss idee|gruppe sein.', 'VALIDATION_ERROR');
            }

            $in = $owner + [
                'title' => $arguments['title'] ?? null,
                'description' => $arguments['description'] ?? null,
                'created_via' => 'mcp',
            ];
            if (isset($arguments['ziel_form'])) {
                $in['target_form'] = (string) $arguments['ziel_form'];
            }
            if (isset($arguments['paket_gruppe'])) {
                $in['group_id'] = (int) $arguments['paket_gruppe'];
            }

            if (isset($arguments['sales_recipe_id'])) {
                $in['sales_recipe_id'] = (int) $arguments['sales_recipe_id'];
                $idee = $svc->uebernehmeBestand($team, $in);
            } else {
                $idee = $svc->add($team, $in);
            }

            return ToolResult::success(['idee' => $this->skizzeArr($idee)]);
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Owner/Referenz nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'Geerbt') ? 'ACCESS_DENIED' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'ideen', 'skizzen', 'kreativ', 'leitstelle', 'entwurf'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.kapitel_ideen.GET', 'foodalchemist.kapitel_ideen.PUT', 'foodalchemist.verkaufsrezepte.SEARCH'],
            'examples' => ['Lege für Kapitel 8 eine Idee "Rote-Bete-Tatar" an', 'Erstelle ein Paket "Vorspeisen-Trio" mit 12 € pro Gast'],
        ];
    }
}
