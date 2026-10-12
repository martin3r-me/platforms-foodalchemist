<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Weg-B-Befund 4 (2026-10-12): Das Typ-Vokabular der Basisrezepte (Regelwerk §1.2, seit Spec 81 die Regel
 * `basisrezept.1.2.typ`) war per MCP nur über `rules.GET` lesbar — und das nur für den Plattform-Admin. Ein
 * Agent im Team vergab darum regelkonform den falschen Präfix („Garmethode:" statt „Fleisch:"). Dieses Tool
 * ist read-only und für jedes Teammitglied: Hauptgruppe → erlaubte Typen, dazu das Benennungsschema.
 */
class RecipeTypenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_typen.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert das kontrollierte Typ-Vokabular der Basisrezepte (Regelwerk §1.2): je Hauptgruppe die erlaubten '
            . 'Typen, die vor dem Doppelpunkt im Namen stehen („Püree: Petersilienwurzel", „Fleisch: Senfrostbraten"). '
            . 'Vor foodalchemist.recipes.POST lesen — ein Präfix außerhalb des Vokabulars wird dort als typ_hinweis gemeldet. '
            . 'Quelle ist die Regel basisrezept.1.2.typ; sie zu ändern bleibt Admin-Sache (rules.PUT).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($this->team($context) === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $tabelle = RezeptTypVokabular::tabelle();

        return ToolResult::success([
            'geladen' => $tabelle !== [],
            'regel' => RezeptTypVokabular::REGEL,
            'schema' => 'Typ: Bezeichnung (Zusatz)',
            'gruppen' => collect($tabelle)->map(fn (array $typen, string $gruppe) => ['gruppe' => $gruppe, 'typen' => $typen])->values()->all(),
            'typen' => RezeptTypVokabular::alle(),
            'hinweis' => $tabelle === []
                ? 'Die Regel basisrezept.1.2.typ ist nicht aktiv — ohne Vokabular keine Präfix-Prüfung.'
                : 'Der Typ steht vor dem Doppelpunkt; Groß/Klein und Umlaute sind beim Abgleich egal. Nur Basisrezepte tragen einen Typ, Gerichte den Gang in eckigen Klammern ([HG] …).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'basisrezept', 'typ', 'vokabular', 'naming', 'regelwerk'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipes.POST', 'foodalchemist.rules.GET'],
            'examples' => ['Welche Typen darf ein Basisrezept-Name tragen?'],
        ];
    }
}
