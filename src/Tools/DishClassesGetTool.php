<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;

/**
 * Weg-B-Befund 1 (2026-10-12): Ein Agent konnte `verkaufsrezepte.PUT dish_class_id` /
 * `dish_main_group_id` nicht nutzen, weil kein Lese-Tool die Klassen und Hauptgruppen
 * MIT Ids lieferte (verkaufsrezepte.GET/LIST zeigen nur Labels). Read-only, team-sichtbar.
 */
class DishClassesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.dish_classes.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert die Speisen-Klassen (Modell A: vier flache Diätformen Fleisch/Fisch/Vegetarisch/Vegan, '
            . 'ohne Hauptgruppe) und die aktiven Gericht-Hauptgruppen (HG, VOR, DES …) MIT Ids — '
            . 'die Werte für foodalchemist.verkaufsrezepte.PUT dish_class_id / dish_main_group_id. '
            . 'Klassen mit Hauptgruppe (Altbestand) erscheinen nur, wenn ihre Hauptgruppe aktiv ist.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $klassen = FoodAlchemistDishClass::visibleToTeam($team)
            ->with(['mainGroup:id,code,label,is_inactive', 'defaultMarkupClass:id,code,label'])
            ->orderBy('id')->get()
            ->filter(fn ($k) => $k->mainGroup === null || ! $k->mainGroup->is_inactive)
            ->values()
            ->map(fn ($k) => [
                'id' => (int) $k->id,
                'code' => $k->code,
                'label' => $k->label,
                'diet_form' => $k->diet_form,
                'dish_main_group' => $k->mainGroup !== null
                    ? ['id' => (int) $k->mainGroup->id, 'code' => $k->mainGroup->code, 'label' => $k->mainGroup->label]
                    : null,
                'default_markup_class' => $k->defaultMarkupClass !== null
                    ? ['id' => (int) $k->defaultMarkupClass->id, 'code' => $k->defaultMarkupClass->code, 'label' => $k->defaultMarkupClass->label]
                    : null,
            ])->all();

        $hauptgruppen = FoodAlchemistDishMainGroup::visibleToTeam($team)
            ->where('is_inactive', false)
            ->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn ($hg) => ['id' => (int) $hg->id, 'code' => $hg->code, 'label' => $hg->label])
            ->all();

        return ToolResult::success([
            'klassen' => $klassen,
            'hauptgruppen' => $hauptgruppen,
            'hinweis' => 'Klasse = Diätform (eine Achse), Hauptgruppe = Gang/Einsatz (zweite Achse). '
                . 'Beide am Gericht per foodalchemist.verkaufsrezepte.PUT setzen; ein manuell gesetzter Wert gilt als Override.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'verkauf', 'speisen-klasse', 'hauptgruppe', 'taxonomie', 'vokabular'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.verkaufsrezepte.PUT', 'foodalchemist.recipe_klasse.POST', 'foodalchemist.vocab_dish_maingroups.POST'],
            'examples' => ['Welche Speisen-Klassen und Hauptgruppen gibt es, mit Ids?'],
        ];
    }
}
