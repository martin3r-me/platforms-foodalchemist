<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D3: Gericht per Freitext-Anweisung überarbeiten (grounded, `vk.ueberarbeiten`).
 *
 * Wie recipes.REVISE, aber VK: Wording/Plating/Facetten-Kontext + vk-Regelwerk-Routing (Workstream W).
 * Draft-Quarantäne (nur stub/draft). accept=false = Vorschau (GL-07); accept=true übernimmt via die
 * geteilten Services (Zutaten-Sync + VK-Textfelder mit Lineage ki, Override-First).
 */
class VerkaufsrezepteReviseTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** VK-Textfelder → Source-Spalte (Override-First). Spiegel von VkModal::REVISE_TEXTE. */
    private const REVISE_TEXTE = [
        'description' => 'description_source',
        'plating_text' => 'plating_source',
        'sales_wording_standard' => 'sales_wording_source',
    ];

    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.REVISE';
    }

    public function getDescription(): string
    {
        return 'Überarbeitet ein team-eigenes Gericht (stub/draft) per Freitext-Anweisung, geerdet am '
            . 'Regelwerk (mit VK-Facetten). accept=false = Vorschlag; accept=true übernimmt Zutaten + '
            . 'Wording/Plating/Beschreibung (KI-Lineage, manuell gepflegte Felder bleiben).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen, stub/draft).'],
                'anweisung' => ['type' => 'string', 'description' => 'Freitext-Anweisung.'],
                'accept' => ['type' => 'boolean', 'description' => 'true übernimmt den Vorschlag; sonst nur Vorschau.'],
            ],
            'required' => ['recipe_id', 'anweisung'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $anweisung = trim((string) ($arguments['anweisung'] ?? ''));
        if ($anweisung === '') {
            return ToolResult::error('anweisung ist Pflicht.', 'VALIDATION_ERROR');
        }

        $r = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)
            ->whereKey((int) ($arguments['recipe_id'] ?? 0))->first();
        if ($r === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $r->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Gericht — Überarbeitung nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }
        if (($gesperrt = $this->kiEditGesperrt($r)) !== null) {
            return ToolResult::error($gesperrt, 'ACCESS_DENIED');   // Draft-Quarantäne
        }

        try {
            $roh = app(RecipeReviseService::class)->vkFreitextVorschlag($team, $r, $anweisung);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $werte = $roh['werte'];

        $hatText = false;
        foreach (array_keys(self::REVISE_TEXTE) as $feld) {
            $hatText = $hatText || (is_string($werte[$feld] ?? null) && trim($werte[$feld]) !== '');
        }
        if (empty($werte['zutaten']) && ! $hatText) {
            return ToolResult::success([
                'recipe_id' => (int) $r->id,
                'revision' => null,
                'hinweis' => 'KI lieferte keine verwertbare Überarbeitung (evtl. FakeProvider-Grenze).',
            ]);
        }

        if (($arguments['accept'] ?? false) !== true) {
            return ToolResult::success([
                'recipe_id' => (int) $r->id,
                'accepted' => false,
                'revision' => ['werte' => $werte, 'confidence' => $roh['confidence']],
            ]);
        }

        $applied = [];
        try {
            if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
                $zeilen = app(RecipeReviseService::class)->syncZeilen($r, $werte['zutaten']);
                if ($zeilen !== []) {
                    app(RecipeService::class)->syncIngredients($team, (int) $r->id, $zeilen);
                    $applied[] = 'zutaten';
                }
            }
            $frisch = $r->fresh();
            foreach (self::REVISE_TEXTE as $feld => $lineage) {
                $wert = $werte[$feld] ?? null;
                if (! is_string($wert) || trim($wert) === '' || $frisch->{$lineage} === 'manual') {
                    continue;
                }
                $frisch->update([
                    $feld => trim($wert),
                    $lineage => 'ki',
                    str_replace('_source', '_ai_confidence', $lineage) => $roh['confidence'],
                ]);
                $applied[] = $feld;
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => (int) $r->id, 'accepted' => true, 'applied' => $applied]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'revise', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.GET', 'foodalchemist.recipes.REVISE'],
            'examples' => ['Überarbeite Gericht 501: mach das Wording eleganter (nur Vorschau).'],
        ];
    }
}
