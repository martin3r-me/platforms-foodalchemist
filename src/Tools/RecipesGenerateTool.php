<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * 03·L5: Beschreibung + Richtungs-Parameter → Draft-Rezept über denselben
 * `RecipeGeneratorService`, den die beiden UI-Modals fahren (GeneratorModal /
 * VkGeneratorModal). Schließt die Lockstep-Schuld aus #505: der Concepter hatte
 * sein `concepts.GENERATE`, der Rezept-Generator hing NUR an der UI.
 *
 * Kern-Invarianten (identisch zur UI, kein zweiter Pfad):
 *   - Bestand-Hybrid-Resolver: vorhandene GPs/Sub-Rezepte zuerst, Neues NUR für
 *     echte Lücken (Sub-Stub bei Halbfabrikat, LA-First-GP-Mint sonst).
 *   - Zutat ohne Treffer bleibt `unmatched` und kommt als Hard-Stop-Zeile
 *     zurück — nie geraten.
 *   - Ergebnis IMMER status=draft (`RecipeService::create`), hier zusätzlich
 *     created_via=mcp. Freigabe bleibt menschlich.
 *
 * VK-Modus ist ein Parameter (`vk: true`), kein zweites Tool — die UI-Trennung
 * in zwei Modals ist eine Flächen-, keine Fach-Grenze (derselbe Service-Call
 * mit `vkModus`).
 */
class RecipesGenerateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Richtungs-Parameter, die 1:1 aus den Pills der beiden Modals kommen. */
    private const PILL_KEYS = [
        'convenience', 'frische', 'bestand', 'level', 'sektor', 'aroma',
        'occasion', 'serviceform', 'kompositions_stil',
    ];

    public function getName(): string
    {
        return 'foodalchemist.recipes.GENERATE';
    }

    public function getDescription(): string
    {
        return 'Generiert aus einer Beschreibung ein ENTWURFS-Rezept (status=draft, created_via=mcp — nie automatisch aktiv). '
            . 'Mit vk=true entsteht statt eines Basisrezepts ein VK-Gericht (is_sales_recipe, Speisen-Klasse/Aufschlagsklasse aus dem Vorschlag) '
            . 'inklusive Kohärenz-Messung. Der Bestand wird ZUERST genutzt: Zutaten werden gegen vorhandene GPs und Basisrezepte gematcht, '
            . 'Neues entsteht nur für echte Lücken (Sub-Rezept-Stub bei Halbfabrikaten, GP-Mint aus passendem Lieferantenartikel). '
            . 'Zutaten ohne Treffer bleiben ungematcht und kommen als "offene" zurück — nie geraten; sie erst mit foodalchemist.gps.MATCH '
            . 'erden bzw. per foodalchemist.gp_proposals.POST anlegen und dann mit foodalchemist.recipe_ingredients.PUT nachziehen. '
            . 'Braucht einen LLM-Provider und dauert je nach Modell ~20–40 s. Freigabe (approved) macht nur ein Mensch im Editor.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => ['type' => 'string', 'description' => 'Was soll entstehen? Freitext, z. B. "Dunkle Rotwein-Schalotten-Reduktion" oder "Vorspeise mit gebeiztem Lachs für einen Empfang"'],
                'vk' => ['type' => 'boolean', 'default' => false, 'description' => 'true = VK-Gericht (Verkauf) statt Basisrezept: Komponenten sind Basisrezepte zuerst, Klasse/Aufschlagsklasse werden vorgeschlagen, Kohärenz wird gemessen'],
                // Kein `name`: den benennt der Vorschlag nach Basisrezept-Regelwerk §1
                // (Umbenennen danach mit foodalchemist.recipes.PUT).
                // ── Richtungs-Parameter (Pills der beiden Generator-Modals) ──
                'convenience' => ['type' => 'string', 'enum' => ['from_scratch', 'teil_convenience', 'voll_convenience'], 'description' => 'Eigenleistung. from_scratch dreht den Pool auf Roh-GPs/Sub-Rezepte; weglassen = keine Vorgabe'],
                'frische' => ['type' => 'string', 'enum' => ['frisch', 'tk', 'konserve'], 'default' => 'frisch', 'description' => 'Harter Resolver-Hook auf die GP-Variante (fresh_first/frozen_first/preserved_first)'],
                'bio' => ['type' => 'boolean', 'default' => false, 'description' => 'Bio-Ware bevorzugen. Default aus — Bio kommt nie zufällig (4.4r)'],
                'bestand' => ['type' => 'string', 'enum' => ['hybrid', 'nur_bestand', 'komplett_neu'], 'default' => 'hybrid', 'description' => 'Bestand-Nutzung als Prompt-Hinweis; hybrid = Bestand zuerst, Neues nur für Lücken'],
                'level' => ['type' => 'string', 'enum' => ['haute_cuisine', 'gehoben', 'klassisch']],
                'sektor' => ['type' => 'string', 'enum' => ['betriebsgastronomie', 'catering', 'restaurant', 'care', 'schule_kita'], 'description' => 'Verpflegungskontext'],
                'diaet_hart' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['vegan', 'vegetarisch', 'glutenfrei', 'laktosefrei', 'halal', 'low_carb']], 'description' => 'Diät-Constraints, hart erzwungen (Multi)'],
                'aroma' => ['type' => 'string', 'description' => 'Freie Aroma-Richtung, z. B. "rauchig-karamellig"'],
                // ── nur mit vk=true wirksam ──
                'occasion' => ['type' => 'string', 'enum' => ['fruehstueck', 'lunch', 'konferenz', 'empfang', 'dinner', 'late_night'], 'description' => 'Nur vk=true: Anlass'],
                'serviceform' => ['type' => 'string', 'enum' => ['tellerservice', 'buffet', 'flying', 'stehempfang', 'boxed'], 'description' => 'Nur vk=true: Serviceform'],
                'kompositions_stil' => ['type' => 'string', 'enum' => ['klassisch', 'kreativ', 'gewagt'], 'description' => 'Nur vk=true: filtert den Pairing-Wissensblock (gewagt = nur belegte Paarungen)'],
                'use_favorites_list' => ['type' => 'boolean', 'default' => false, 'description' => '06·H3: bevorzugt aus der kuratierten Favoriten-GP-Liste bauen (bevorzugt, nicht ausschließlich)'],
                'favorites_convenience_only' => ['type' => 'boolean', 'default' => false, 'description' => '06·H4b: Favoriten-Block auf Convenience-getaggte GPs verengen (nur wirksam mit use_favorites_list)'],
            ],
            'required' => ['description'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $description = trim((string) ($arguments['description'] ?? $arguments['beschreibung'] ?? ''));
        if ($description === '') {
            return ToolResult::error('description ist Pflicht — beschreibe, was entstehen soll.', 'VALIDATION_ERROR');
        }
        $vkModus = (bool) ($arguments['vk'] ?? false);

        // Parameter-Bau wie in den beiden Modals: leere Strings strippen (sonst
        // landet "(egal)" als Vorgabe im Prompt), bools NACH dem Filter setzen.
        $parameter = [];
        foreach (self::PILL_KEYS as $key) {
            $wert = $arguments[$key] ?? null;
            if (is_string($wert) && trim($wert) !== '') {
                $parameter[$key] = trim($wert);
            }
        }
        if (is_array($arguments['diaet_hart'] ?? null)) {
            $harte = array_values(array_filter(array_map('strval', $arguments['diaet_hart']), fn ($v) => $v !== ''));
            if ($harte !== []) {
                $parameter['diaet_hart'] = $harte;
            }
        }
        $parameter['bio'] = (bool) ($arguments['bio'] ?? false);
        $parameter['use_favorites_list'] = (bool) ($arguments['use_favorites_list'] ?? false);
        $parameter['favorites_convenience_only'] = $parameter['use_favorites_list'] && (bool) ($arguments['favorites_convenience_only'] ?? false);

        try {
            $resultat = app(RecipeGeneratorService::class)
                ->generiere($team, $description, $parameter, null, $vkModus, 'mcp');
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
            return ToolResult::error('KI ist für dieses Team deaktiviert — der Generator braucht sie. Rezept manuell mit foodalchemist.recipes.POST anlegen.', 'KI_DEAKTIVIERT');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $recipe = $resultat['recipe'];
        $statistik = $resultat['statistik'];
        $offen = (int) ($statistik['offen'] ?? 0);

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
            'statistik' => $statistik,
            'offene' => array_map(fn (array $o) => [
                'text' => $o['text'],
                'primaer' => $o['primaer'],                       // gp_anlegen | basisrezept_anlegen
                'shortlist' => array_map(fn (array $k) => [
                    'kind' => $k['kind'], 'id' => $k['id'], 'name' => $k['name'], 'score' => $k['score'],
                ], array_slice($o['shortlist'] ?? [], 0, 3)),
            ], $resultat['offene']),
            'kohaerenz' => $statistik['kohaerenz'] ?? null,        // nur VK-Modus
            'hinweis' => ($offen > 0
                    ? "⚠ {$offen} Zutat(en) ohne Treffer — bewusst NICHT geraten. Pro Zeile: foodalchemist.gps.MATCH prüfen, "
                        . 'sonst GP/Basisrezept anlegen und mit foodalchemist.recipe_ingredients.PUT nachziehen. '
                    : '')
                . 'Entwurf (Draft-Quarantäne): Freigabe bleibt menschlich.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'generator', 'vk', 'draft', 'ki'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'llm_call',
            'related_tools' => ['foodalchemist.recipes.POST', 'foodalchemist.gps.MATCH', 'foodalchemist.recipe_ingredients.PUT', 'foodalchemist.concepts.GENERATE'],
            'examples' => [
                'Generiere ein Basisrezept für eine dunkle Rotwein-Schalotten-Reduktion, from scratch',
                'Baue ein veganes VK-Gericht für einen Empfang (vk=true, diaet_hart=[vegan], occasion=empfang)',
            ],
        ];
    }
}
