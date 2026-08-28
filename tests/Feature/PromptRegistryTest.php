<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M7-04: Prompt-Registry == Anhang-A-Inventar (06_KI). Bewusst NICHT in der
 * Registry (dokumentiert in config): #2 TEMPLATE_FILL + #38 AGENTIC_RESOLVER
 * (Tier-D-Tool-Loops → M7-10/M8-01), #37 FOODBOOK_PLAN (Phase 2 ⚠D5),
 * #39 DISAMBIG (toter Code).
 */
const REGISTRY_SOLL = [
    // GP-Welt
    'gp.suggest' => 'B', 'gp.condition' => null, 'gp.tags' => 'C', 'gp.allergene' => 'A',
    'gp.domain' => 'B', 'gp.piece_default_g' => 'B', 'gp.zaehl_einheiten' => 'B',
    'gp.anker' => 'B', 'gp.role' => 'B', 'gp.la_suggest' => 'B', 'gp.term_la_rank' => 'B',
    // Rezept-Welt
    'recipe.generator' => 'B', 'recipe.description' => 'C', 'recipe.category' => 'D',
    'recipe.garverlust' => 'C', 'recipe.name_putzen' => 'D', 'recipe.titel_vorschlag' => 'B', 'recipe.sektor' => 'B',
    'recipe.level' => 'B', 'recipe.sub_typ' => 'B', 'recipe.production_depth' => 'B',
    'recipe.preparation' => 'A', 'recipe.eigenschaften' => 'B', 'recipe.geschmack' => 'B',
    'recipe.steps' => 'A',                                            // Spec 27: strukturierte Schritte (Master), preparation ist nur ihr Spiegel
    'recipe.review' => 'A', 'recipe.pairing' => 'A', 'recipe.anker' => 'B',
    'recipe.bauart' => 'B',                                           // Spec 21 S5b-2: Gericht-vs-Komponente nach Bauart (Klassifikator, darum Tier B + keine Food-DNA)
    'recipe.equipment' => 'B', 'recipe.extract' => 'C',
    'recipe.ueberarbeiten' => 'A',                                    // R6: KI-Überarbeiten (freie Anweisung, Ist-Button)
    'recipe.sensorik' => 'B',                                         // Sensorik-Bewertung des fertigen Gerichts (recipe_sensorik)
    'gp.naehrwerte' => 'B',                                           // R10: Nährwert-Fallback ohne LA-Daten (Ist-Feature)
    // VK-Welt
    'vk.generator' => 'B', 'vk.speisen_klasse' => 'B', 'vk.rollen' => 'B',
    'vk.plating' => 'A', 'vk.name_putzen' => 'B', 'vk.titel_vorschlag' => 'B', 'vk.marketing' => 'A', 'vk.wording' => 'A',
    'vk.behaelter' => 'B', 'vk.regeneration' => 'B', 'vk.servier_vehikel' => 'B',
    'vk.review' => 'A', 'vk.kohaerenz' => 'A', 'vk.teller_heber' => 'A',
    'vk.ueberarbeiten' => 'A',                                        // Spec 03 L1a: VK-Revise (freie Anweisung, Facetten sind Vorgabe)
    // Concepter
    'concept.wording' => 'A',                                         // Concept-übergreifendes Wording (Schreibstil → Position-Namen + Intro)
    'concept.brief_geruest' => 'A',                                   // R6.1: Kunden-Brief → Planungs-Gerüst (Rahmen; Gericht-Wahl bleibt deterministisch)
    'concept.plan' => 'B',                                            // Et.2b Kreativ-Kopf: Kunden-Brief → kreative Concept-Canvas (Leitidee/USP/Inszenierung/Geschmackswelten)
    'foodbook.kapitel_ideen' => 'B',                                  // Spec 19 E6.4: produkt-blinde Kreativ-Divergenz je Kapitel (nur Skizzen)
    'foodbook.kundentext' => 'A',                                     // Spec 03 L2: kundensichtbarer Einleitungstext, BEIDE Ebenen (ebene: foodbook|kapitel)
    // Schicht 3: generischer Konformitäts-Critic (artefakt-agnostisch, EIN Prompt für Rezept/VK/GP/LA)
    'conformance.check' => 'B',
    // Schicht 3 · Slice 5: GP-Selbstheilung — leitet konforme Feld-Werte aus dem Quell-LA ab (LA-First)
    'gp.conformance_revise' => 'B',
    // Sonstiges
    'price.plausi' => 'B', 'chat.message' => 'A',
    // Signale-Cockpit: KI-Fixer + Assistenzen hinter „KI erledigen lassen" (2026-07-21)
    'signal.supplier_inquiry' => 'B', 'signal.margin_levers' => 'B',
    'signal.vk_release_advice' => 'B', 'signal.serving_form_suggest' => 'B',
    'signal.recipe_category_suggest' => 'B',                          // Spec 21 Tranche A: Assist zu rezept_kategorie_problem
    'signal.recipe_naming_suggest' => 'B',                            // Spec 21 Tranche A: Assist zu rezept_naming_regelwerk
    // Trendradar: Cluster-Benennung in die zweistufige Trend-Taxonomie (Kategorie aus fester
    // Liste, Klasse frei) — der einzige LLM-Call des Radars.
    'trend.cluster_label' => 'B',
];

it('Registry vollständig: alle Soll-Keys vorhanden, mit task + gültigem Tier', function () {
    $registry = config('foodalchemist.prompts');

    foreach (array_keys(REGISTRY_SOLL) as $key) {
        expect($registry)->toHaveKey($key);
        expect($registry[$key]['task'] ?? '')->not->toBe('', "Task fehlt: {$key}");
        expect($registry[$key]['tier'] ?? '')->toBeIn(['A', 'B', 'C', 'D'], "Tier ungültig: {$key}");
    }
});

it('keine unbekannten Keys außer demo.echo (Inventar-Disziplin)', function () {
    $extra = array_diff(array_keys(config('foodalchemist.prompts')), array_keys(REGISTRY_SOLL), ['demo.echo']);

    expect(array_values($extra))->toBe([]);
});

it('Compliance- und V-02-Features sind Tier A (06_KI §2-Begründung)', function () {
    foreach (['gp.allergene', 'recipe.preparation', 'vk.plating', 'recipe.pairing', 'vk.marketing'] as $key) {
        expect(config('foodalchemist.prompts')[$key]['tier'])->toBe('A', $key);
    }
});
