<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeExtractService;

/**
 * Rezept-IMPORT via MCP (2026-08-22): nimmt den ROHTEXT einer bestehenden Rezeptur
 * (eingefügt, aus dem Web kopiert, oder — der eigentliche Foto-Umweg — vom Assistenten
 * aus einem BILD abgelesen) und legt daraus ein GEERDETES Entwurfs-Rezept an.
 *
 * Abgrenzung zu recipes.GENERATE: KEINE Veredelung/Erfindung — TREUE Extraktion
 * (recipe.extract, Wissenskontext leer) + Erdung am Resolver (GP-/Sub-Rezept-Bindung,
 * syncIngredients + Recompute). Verschachtelte Quellen (Gericht mit Sauce/Püree als
 * eigenen Komponenten) werden rekursiv als verknüpfte Sub-Basisrezepte angelegt.
 *
 * Bild-Weg: der MCP-Assistent hat eigene Vision → liest das Foto → ruft dieses Tool mit
 * dem abgelesenen Text. Damit ist der Foto-Import möglich, ohne den (noch fehlenden)
 * Vision-Transport im Plattform-LLM.
 */
class RecipesExtractTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.EXTRACT';
    }

    public function getDescription(): string
    {
        return 'Importiert eine BESTEHENDE Rezeptur aus Rohtext (raw_text) TREU in ein Entwurfs-Rezept '
            . '(status=draft, created_via=import — nie automatisch aktiv). Anders als recipes.GENERATE wird '
            . 'NICHTS erfunden/veredelt: der Text wird 1:1 strukturiert und dann GEERDET (Zutaten gegen '
            . 'vorhandene GPs/Basisrezepte gematcht, EK/Allergene gerechnet). Verschachtelte Rezepte werden '
            . 'unterstützt: eigenständige Komponenten (Sauce/Fond/Püree mit eigenen Zutaten) werden als '
            . 'verknüpfte Sub-Basisrezepte angelegt. Mit vk=true entsteht ein VK-Gericht, sonst richtet sich '
            . 'der Typ nach dem erkannten typ (gericht|basisrezept). Zutaten ohne Treffer bleiben "offen" — '
            . 'nie geraten; danach mit foodalchemist.gps.MATCH erden bzw. GP anlegen. Mit dry_run=true wird '
            . 'nur die geparste Struktur zurückgegeben (nichts geschrieben) — nützlich zum Prüfen vor der Anlage. '
            . 'FOTO-IMPORT: gib den aus dem Bild abgelesenen Rezept-Text als raw_text — die Bilderkennung '
            . 'macht der Assistent, dieses Tool erwartet Text. Braucht einen LLM-Provider (~10–20 s).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'raw_text' => ['type' => 'string', 'description' => 'Der vollständige Rohtext der Rezeptur (Zutaten + Zubereitung; bei Fotos: der abgelesene Text). Sektionen wie "Für die Sauce: …" werden als eigene Komponenten erkannt.'],
                'vk' => ['type' => 'boolean', 'default' => false, 'description' => 'true = als VK-Gericht anlegen (überschreibt den erkannten Typ). Weglassen = Typ aus dem Text (gericht|basisrezept).'],
                'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'true = nur extrahieren + geparste Struktur zurückgeben, NICHTS anlegen.'],
            ],
            'required' => ['raw_text'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $rohText = trim((string) ($arguments['raw_text'] ?? ''));
        if ($rohText === '') {
            return ToolResult::error('raw_text ist Pflicht — der Rezept-Text (bei Fotos der abgelesene Text).', 'VALIDATION_ERROR');
        }
        $dryRun = (bool) ($arguments['dry_run'] ?? false);
        $vkOverride = array_key_exists('vk', $arguments) ? (bool) $arguments['vk'] : null;

        $svc = app(RecipeExtractService::class);
        try {
            $extrakt = $svc->extrahiere($team, $rohText);
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
            return ToolResult::error('KI ist für dieses Team deaktiviert — die Extraktion braucht sie.', 'KI_DEAKTIVIERT');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        if ($dryRun) {
            return ToolResult::success([
                'dry_run' => true,
                'extrakt' => [
                    'typ' => $extrakt['typ'] ?? 'basisrezept',
                    'name' => $extrakt['name'] ?? null,
                    'zutaten' => array_values($extrakt['zutaten'] ?? []),
                    'preparation' => $extrakt['preparation'] ?? null,
                    'komponenten' => array_map(fn ($k) => [
                        'name' => $k['name'] ?? null,
                        'zutaten' => array_values($k['zutaten'] ?? []),
                    ], array_values($extrakt['komponenten'] ?? [])),
                ],
                'hinweis' => 'Nur Vorschau (dry_run) — nichts angelegt. Ohne dry_run wird ein geerdeter Draft erzeugt.',
            ]);
        }

        try {
            $resultat = $svc->legeAn($team, $extrakt, $vkOverride);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $recipe = $resultat['recipe'];
        $statistik = $resultat['statistik'] ?? [];
        $offen = (int) ($statistik['offen'] ?? ($recipe->n_ingredients_unmapped ?? 0));

        return ToolResult::success([
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'recipe_key' => $recipe->recipe_key,
                'status' => $this->statusWert($recipe),
                'created_via' => $recipe->created_via,
                'is_sales_recipe' => (bool) $recipe->is_sales_recipe,
                'yield_kg' => $recipe->yield_kg,
                'ek_total_eur' => $recipe->ek_total_eur,
                'n_ingredients_total' => $recipe->n_ingredients_total,
                'n_ingredients_unmapped' => $recipe->n_ingredients_unmapped,
            ],
            'sub_recipes' => $resultat['sub_recipes'] ?? [],
            'statistik' => $statistik,
            'offene' => array_map(fn (array $o) => [
                'text' => $o['text'] ?? null,
                'primaer' => $o['primaer'] ?? null,
            ], array_values($resultat['offene'] ?? [])),
            'hinweis' => ($offen > 0
                    ? "⚠ {$offen} Zutat(en) ohne GP-Treffer — bewusst NICHT geraten; per foodalchemist.gps.MATCH erden bzw. GP anlegen. "
                    : '')
                . 'GEERDETER Entwurf (Draft-Quarantäne): Freigabe bleibt menschlich.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'import', 'extract', 'ocr', 'draft', 'ki'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.recipes.GENERATE', 'foodalchemist.recipes.POST', 'foodalchemist.gps.MATCH'],
            'examples' => ['Importiere dieses Rezept: 200 g Mehl, 3 Eier … (raw_text)'],
        ];
    }
}
