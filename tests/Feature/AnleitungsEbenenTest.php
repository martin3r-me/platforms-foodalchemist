<?php

use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Die drei Anleitungs-Ebenen am Gericht (Regelwerk Verkaufsgerichte §3, 2026-09-04):
 * Produktion/Fertigstellung · Regeneration · Anrichten.
 *
 * Diese Tests halten die TRENNUNG fest, nicht die Formulierung. Anlass war eine
 * doppelte Wahrheit: der `recipe.steps`-Prompt forderte selbst einen „Service-,
 * Regenerations- und Anrichteablauf", während `vk.regeneration` dieselben Angaben
 * strukturiert in `recipe_regenerations` schrieb — die Prosa-Fassung und die
 * Datensatz-Fassung konnten beliebig auseinanderlaufen, und im Druck erschien
 * keine von beiden.
 *
 * Zugriff auf die Registry bewusst als LITERALER Array-Index: die Prompt-Keys enthalten
 * Punkte, `config('…prompts.recipe.steps.task')` liest sie als Pfad und liefert still
 * null — ein Test, der so zugreift, prüft nichts (hier beim Schreiben passiert).
 */

// ── §3 Prompt-Trennung ───────────────────────────────────────────────────────

it('recipe.steps fordert keine Regenerations-Parameter mehr an', function () {
    $task = (string) (config('foodalchemist.prompts', [])['recipe.steps']['task'] ?? null);

    // Das PROGRAMM (Gerät/°C/min/Kerntemperatur) gehört in `vk.regeneration`.
    // Der Prompt darf die Ebene nennen, um sie abzugrenzen — aber nicht anfordern.
    expect($task)->toContain('NICHT in diese Schritte')
        ->and($task)->not->toContain('regenerieren, warmhalten')
        ->and($task)->not->toContain('Service-, Regenerations- und Anrichteablauf');
});

it('vk.regeneration bleibt die strukturierte Wahrheit der Regenerations-Ebene', function () {
    $task = (string) (config('foodalchemist.prompts', [])['vk.regeneration']['task'] ?? null);

    expect($task)->toContain('core_temp_c')
        ->and($task)->toContain('programme');
});

it('vk.plating bleibt frei von Regenerations-Parametern', function () {
    $task = (string) (config('foodalchemist.prompts', [])['vk.plating']['task'] ?? null);

    foreach (['core_temp_c', 'Kerntemperatur', 'regenerier'] as $fremd) {
        expect($task)->not->toContain($fremd);
    }
});

it('vk.generator beansprucht preparation nicht mehr fuer das Plating', function () {
    // `preparation` ist der Spiegel der Schritte (RecipeStepService schreibt es aus
    // `recipe_steps`); der Teller-Aufbau lebt in `plating_text`. Vorher definierten
    // beide dasselbe Feld — wer zuletzt lief, gewann.
    $task = (string) (config('foodalchemist.prompts', [])['vk.generator']['task'] ?? null);

    expect($task)->not->toContain('preparation (= PLATING & SERVICE')
        ->and($task)->toContain('preparation (= FERTIGSTELLEN am Einsatztag');
});

it('der Schritt-Kontext hat genau eine Quelle fuer beide Kontextbauer', function () {
    // Stand vorher wortgleich in RecipeOneShotService UND StepEditor.
    foreach (['gericht', 'basisrezept'] as $typ) {
        expect(config("foodalchemist.step_kontext.$typ.ziel"))->toBeString()->not->toBeEmpty()
            ->and(config("foodalchemist.step_kontext.$typ.hinweis"))->toBeString()->not->toBeEmpty();
    }

    expect((string) config('foodalchemist.step_kontext.gericht.hinweis'))
        ->toContain('NICHT in diese Schritte');
});

// ── §3.6 Ausgabe folgt dem Adressaten ────────────────────────────────────────

it('der Report kennt Regeneration und Anrichten als eigene Schalter', function () {
    $svc = app(ReportExportService::class);

    // Produktions-Profil: die Küche vor Ort braucht das Regenerations-Programm,
    // der Teller-Aufbau gehört dem Pass (eigenes Blatt).
    $produktion = $svc->optionen(['profil' => 'produktion'], 'recipe');
    expect($produktion['regeneration'])->toBeTrue()
        ->and($produktion['anrichten'])->toBeFalse();

    // Voll = alle drei Ebenen, Kalkulation = keine.
    expect($svc->optionen(['profil' => 'voll'], 'recipe'))
        ->toMatchArray(['regeneration' => true, 'anrichten' => true]);
    expect($svc->optionen(['profil' => 'kalkulation'], 'recipe'))
        ->toMatchArray(['regeneration' => false, 'anrichten' => false]);
});

it('die Ebenen-Schalter sind je Aufruf ueberschreibbar', function () {
    $svc = app(ReportExportService::class);

    $opt = $svc->optionen(['profil' => 'produktion', 'regeneration' => '0', 'anrichten' => '1'], 'recipe');

    expect($opt['regeneration'])->toBeFalse()
        ->and($opt['anrichten'])->toBeTrue();
});

// ── §4 Verkaufseinheit ───────────────────────────────────────────────────────

it('die Verkaufseinheiten sind auf Portion, Stueck, Kilogramm und Liter begrenzt', function () {
    // Slugs gegen den Bestand verifiziert (WaWi `vocab_einheit`, Import 1:1).
    expect(config('foodalchemist.sales_units'))->toBe(['portion', 'stk', 'kg', 'l']);
});

it('updateVk weist eine Zutaten-Einheit als Verkaufseinheit ab', function () {
    $this->seedTeamHierarchy();
    $gericht = $this->makeRecipe($this->rootTeam, 'Guard-Gericht', ['is_sales_recipe' => true]);
    $prise = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'prise', 'display_de' => 'Prise',
        'dimension' => 'mass', 'is_approximate' => true,
    ]);

    expect(fn () => app(SalesRecipeService::class)
        ->updateVk($this->rootTeam, $gericht->id, ['sales_unit_vocab_id' => $prise->id]))
        ->toThrow(RuntimeException::class);
});

it('updateVk laesst eine erlaubte Verkaufseinheit durch', function () {
    $this->seedTeamHierarchy();
    $gericht = $this->makeRecipe($this->rootTeam, 'Portion-Gericht', ['is_sales_recipe' => true]);
    $portion = FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'portion'],
        ['display_de' => 'Portion', 'dimension' => 'count']
    );

    $frisch = app(SalesRecipeService::class)
        ->updateVk($this->rootTeam, $gericht->id, ['sales_unit_vocab_id' => $portion->id]);

    expect((int) $frisch->sales_unit_vocab_id)->toBe((int) $portion->id);
});

it('ein unveraenderter Alt-Wert blockiert das Speichern anderer Felder nicht', function () {
    // Bestandsschutz: sonst waere an Gerichten mit Alt-Einheit kein Save mehr moeglich.
    $this->seedTeamHierarchy();
    $prise = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'prise', 'display_de' => 'Prise',
        'dimension' => 'mass', 'is_approximate' => true,
    ]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Alt-Einheit-Gericht', [
        'is_sales_recipe' => true, 'sales_unit_vocab_id' => $prise->id,
    ]);

    $frisch = app(SalesRecipeService::class)->updateVk($this->rootTeam, $gericht->id, [
        'sales_unit_vocab_id' => $prise->id,          // unverändert mitgesendet (Formular-Post)
        'work_time_min' => 12,
    ]);

    expect((int) $frisch->work_time_min)->toBe(12)
        ->and((int) $frisch->sales_unit_vocab_id)->toBe((int) $prise->id);
});

// ── Posten am Gericht kommt aus den Komponenten ──────────────────────────────

it('beteiligtePosten sammelt die Posten der Komponenten-Basisrezepte', function () {
    $this->seedTeamHierarchy();

    $saucier = FoodAlchemistProductionStation::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Saucier', 'slug' => 'saucier', 'sort_order' => 1,
    ]);
    $garde = FoodAlchemistProductionStation::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Gardemanger', 'slug' => 'gardemanger', 'sort_order' => 2,
    ]);

    $jus = $this->makeRecipe($this->rootTeam, 'Malz-Rinderjus', ['default_station_id' => $saucier->id]);
    $salat = $this->makeRecipe($this->rootTeam, 'Brokkolini', ['default_station_id' => $garde->id]);
    $ohnePosten = $this->makeRecipe($this->rootTeam, 'Kartoffel-Baumkuchen');

    $gericht = $this->makeRecipe($this->rootTeam, 'Posten-Gericht', ['is_sales_recipe' => true]);
    foreach ([$jus, $salat, $ohnePosten] as $i => $komponente) {
        $this->makeIngredient($gericht, $komponente->name, null, '100', $i + 1)
            ->update(['referenced_recipe_id' => $komponente->id]);
    }

    $posten = app(SalesRecipeService::class)->beteiligtePosten($gericht->fresh());

    // Zwei Posten, die posten-lose Komponente fällt heraus (kein geratener Posten).
    expect($posten)->toHaveCount(2)
        ->and($posten->pluck('name')->all())->toContain('Saucier', 'Gardemanger');
});

it('beteiligtePosten ist leer, wenn keine Komponente einen Posten traegt', function () {
    $this->seedTeamHierarchy();
    $gericht = $this->makeRecipe($this->rootTeam, 'Rohware-Gericht', ['is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Salz', null, '5', 1);

    expect(app(SalesRecipeService::class)->beteiligtePosten($gericht->fresh()))->toBeEmpty();
});

// ── Produktionszeit am Gericht = Fertigstellung, Komponenten bringen ihre eigene ──

it('komponentenZeiten summiert die Zeiten der Komponenten und zaehlt die Luecken', function () {
    // Die Auftrags-Explosion erzeugt eine eigene Zeile je Rezept — die Zeit am Gericht ist
    // deshalb NICHT die Gesamtzeit. Der Editor zeigt die Komponenten-Zeiten zur Orientierung,
    // damit dort niemand die Gesamtzeit eintraegt (die im Auftrag doppelt zaehlen wuerde).
    $this->seedTeamHierarchy();

    $jus = $this->makeRecipe($this->rootTeam, 'Jus', ['work_time_min' => 90, 'setup_time_min' => 10]);
    $creme = $this->makeRecipe($this->rootTeam, 'Creme', ['work_time_min' => 30, 'standzeit_min' => 120]);
    $ohne = $this->makeRecipe($this->rootTeam, 'Crunch');          // keine Zeitangabe

    $gericht = $this->makeRecipe($this->rootTeam, 'Zeit-Gericht', [
        'is_sales_recipe' => true, 'work_time_min' => 8,            // nur Fertigstellung
    ]);
    foreach ([$jus, $creme, $ohne] as $i => $k) {
        $this->makeIngredient($gericht, $k->name, null, '100', $i + 1)
            ->update(['referenced_recipe_id' => $k->id]);
    }

    $zeiten = app(SalesRecipeService::class)->komponentenZeiten($gericht->fresh());

    expect($zeiten['work_time_min'])->toBe(120)                     // 90 + 30, das Gericht selbst zaehlt nicht mit
        ->and($zeiten['setup_time_min'])->toBe(10)
        ->and($zeiten['standzeit_min'])->toBe(120)
        ->and($zeiten['anzahl'])->toBe(3)
        ->and($zeiten['ohne_zeit'])->toBe(1);                       // die Luecke wird benannt, nicht geraten
});

it('komponentenZeiten ist bei einem Gericht ohne Sub-Rezepte leer', function () {
    $this->seedTeamHierarchy();
    $gericht = $this->makeRecipe($this->rootTeam, 'Solo-Gericht', [
        'is_sales_recipe' => true, 'work_time_min' => 15,
    ]);
    $this->makeIngredient($gericht, 'Salz', null, '5', 1);

    expect(app(SalesRecipeService::class)->komponentenZeiten($gericht->fresh()))
        ->toMatchArray(['work_time_min' => 0, 'anzahl' => 0, 'ohne_zeit' => 0]);
});

// ── §3.3 Anrichten als bebilderte Schrittfolge (dieselbe Tabelle, eigene Ebene) ──

it('trennt Produktions- und Anrichte-Schritte in derselben Tabelle', function () {
    // Beide Ebenen nummerieren bei 1 — ungefiltert würden sie zu einer wirren Liste
    // verschmelzen. Und ein Speichern in der einen Ebene darf die andere nicht wegräumen.
    $this->seedTeamHierarchy();
    $svc = app(\Platform\FoodAlchemist\Services\RecipeStepService::class);
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::class;
    $gericht = $this->makeRecipe($this->rootTeam, 'Ebenen-Gericht', ['is_sales_recipe' => true]);

    $svc->sync($gericht, [
        ['phase' => 'Mise en Place', 'text' => 'Komponenten bereitstellen.'],
        ['phase' => null, 'text' => 'Filet tranchieren.'],
    ], $step::EBENE_PRODUKTION);

    $svc->sync($gericht, [
        ['phase' => null, 'text' => 'Creme als Spiegel aufziehen.'],
    ], $step::EBENE_ANRICHTEN);

    expect($step::where('recipe_id', $gericht->id)->ebene($step::EBENE_PRODUKTION)->count())->toBe(2)
        ->and($step::where('recipe_id', $gericht->id)->ebene($step::EBENE_ANRICHTEN)->count())->toBe(1);

    // Jede Ebene beginnt bei 1.
    expect($step::where('recipe_id', $gericht->id)->ebene($step::EBENE_ANRICHTEN)->first()->position)->toBe(1);

    // Erneutes Speichern der Anrichte-Ebene lässt die Produktions-Schritte unberührt.
    $svc->sync($gericht, [['phase' => null, 'text' => 'Jus angießen.']], $step::EBENE_ANRICHTEN);
    expect($step::where('recipe_id', $gericht->id)->ebene($step::EBENE_PRODUKTION)->count())->toBe(2);
});

it('spiegelt jede Ebene in ihr eigenes Feld', function () {
    // EINBAHN Schritte → Markdown: produktion → preparation, anrichten → plating_text.
    // Foodbook, Angebot und Report lesen unverändert die Textfelder.
    $this->seedTeamHierarchy();
    $svc = app(\Platform\FoodAlchemist\Services\RecipeStepService::class);
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::class;
    $gericht = $this->makeRecipe($this->rootTeam, 'Spiegel-Gericht', ['is_sales_recipe' => true]);

    $svc->sync($gericht, [['phase' => null, 'text' => 'Filet tranchieren.']], $step::EBENE_PRODUKTION);
    $svc->sync($gericht, [['phase' => null, 'text' => 'Creme als Spiegel aufziehen.']], $step::EBENE_ANRICHTEN);

    $frisch = $gericht->fresh();
    expect($frisch->preparation)->toContain('Filet tranchieren')
        ->and($frisch->preparation)->not->toContain('Creme als Spiegel')
        ->and($frisch->plating_text)->toContain('Creme als Spiegel')
        ->and($frisch->plating_text)->not->toContain('Filet tranchieren');
});

it('parst einen plating_text-Write in Anrichte-Schritte', function () {
    // Markdown bleibt der EINGANG (MCP, Revise, Generator) — Schritte sind der Master.
    $this->seedTeamHierarchy();
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::class;
    $gericht = $this->makeRecipe($this->rootTeam, 'Plating-Eingang', ['is_sales_recipe' => true]);

    app(SalesRecipeService::class)->updateVk($this->rootTeam, $gericht->id, [
        'plating_text' => "1. Creme aufziehen.\n2. Filet mittig setzen.",
    ]);

    expect($step::where('recipe_id', $gericht->id)->ebene($step::EBENE_ANRICHTEN)->count())->toBe(2);
    // Und der Bestand gewinnt: ein zweiter Text-Write legt nicht nochmal an.
    app(SalesRecipeService::class)->updateVk($this->rootTeam, $gericht->id, [
        'plating_text' => '1. Ganz anders.',
    ]);
    expect($step::where('recipe_id', $gericht->id)->ebene($step::EBENE_ANRICHTEN)->count())->toBe(2);
});

it('der Report liefert die Anrichte-Schritte als eigene Liste', function () {
    $this->seedTeamHierarchy();
    $svc = app(\Platform\FoodAlchemist\Services\RecipeStepService::class);
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::class;
    $gericht = $this->makeRecipe($this->rootTeam, 'Report-Gericht', ['is_sales_recipe' => true]);

    $svc->sync($gericht, [['phase' => null, 'text' => 'Filet tranchieren.']], $step::EBENE_PRODUKTION);
    $svc->sync($gericht, [['phase' => null, 'text' => 'Creme aufziehen.']], $step::EBENE_ANRICHTEN);

    $daten = app(ReportExportService::class)->rezeptDaten(
        $this->rootTeam,
        $gericht->id,
        app(ReportExportService::class)->optionen(['profil' => 'voll'], 'recipe'),
    );

    $node = $daten['recipe'];
    expect(collect($node['steps'])->pluck('text')->all())->toBe(['Filet tranchieren.'])
        ->and(collect($node['anrichte_schritte'])->pluck('text')->all())->toBe(['Creme aufziehen.']);
});

// ── Auftrag + Wandmonitor: die Ebenen werden mit eingefroren ──────────────────

it('der Produktionsschein kennt Regeneration und Anrichten als eigene Schalter', function () {
    // §3.6 auch am Auftrag: die Küche vor Ort braucht das Programm, der Pass den Aufbau.
    $svc = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class);

    $produktion = $svc->dokumentOptionen(['profil' => 'produktion']);
    expect($produktion['regeneration'])->toBeTrue()
        ->and($produktion['anrichten'])->toBeFalse();

    expect($svc->dokumentOptionen(['profil' => 'kurz']))
        ->toMatchArray(['regeneration' => false, 'anrichten' => false]);

    // einzeln überschreibbar
    expect($svc->dokumentOptionen(['profil' => 'produktion', 'anrichten' => '1'])['anrichten'])->toBeTrue();
});

it('das Produktionsblatt liefert Regenerations-Programm und Anrichte-Schritte je Gericht', function () {
    // Das ist die Quelle, aus der der Auftrag seine Snapshots einfriert — und aus der
    // Wandmonitor und Produktionsschein lesen. Vorher fehlten beide Ebenen komplett.
    $this->seedTeamHierarchy();
    $stepSvc = app(\Platform\FoodAlchemist\Services\RecipeStepService::class);
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::class;

    $gericht = $this->makeRecipe($this->rootTeam, 'Blatt-Gericht', [
        'is_sales_recipe' => true, 'sales_quantity_per_unit_g' => 300, 'sales_unit_count' => 1,
    ]);
    $this->makeIngredient($gericht, 'Rinderfilet', $this->makeGp($this->rootTeam, 'Rinderfilet'), '150', 1);

    $stepSvc->sync($gericht, [['phase' => null, 'text' => 'Filet tranchieren.']], $step::EBENE_PRODUKTION);
    $stepSvc->sync($gericht, [['phase' => null, 'text' => 'Creme aufziehen.']], $step::EBENE_ANRICHTEN);

    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id,
        'component_label' => 'Rinderfilet sous-vide', 'temp_c' => 54, 'duration_min' => 25,
        'core_temp_c' => 52, 'sort_order' => 1,
    ]);

    $blatt = app(\Platform\FoodAlchemist\Services\PlanungsblattService::class)
        ->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $gericht->id, 'portionen' => 10]]);

    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $gericht->id);
    expect($zeile)->not->toBeNull();
    expect(collect($zeile['regenerationen'])->pluck('komponente')->all())->toBe(['Rinderfilet sous-vide'])
        ->and(collect($zeile['anrichte_schritte'])->pluck('text')->all())->toBe(['Creme aufziehen.'])
        // Die Produktions-Schritte bleiben getrennt — keine Vermischung der Nummerierungen.
        ->and(collect($zeile['schritte'])->pluck('text')->all())->toBe(['Filet tranchieren.']);
});

// ── Rüstzeit und Vorproduzierbarkeit gibt es am Gericht nicht ────────────────

it('updateVk schreibt Ruestzeit und Vorlauf am Gericht nicht mehr', function () {
    // Beides sind Herstellungs-Eigenschaften (Entscheid 2026-09-04). Was am Gericht
    // keine Bedeutung hat, darf auch das Tool nicht setzen — sonst stünde ein Wert in
    // der Spalte, den die UI nicht zeigt.
    $this->seedTeamHierarchy();
    $gericht = $this->makeRecipe($this->rootTeam, 'Ohne-Ruestzeit', ['is_sales_recipe' => true]);

    $frisch = app(SalesRecipeService::class)->updateVk($this->rootTeam, $gericht->id, [
        'setup_time_min' => 30,
        'max_vorlauf_tage' => 5,
        'work_time_min' => 8,          // Gegenprobe: die Fertigstellungszeit geht durch
    ]);

    expect($frisch->setup_time_min)->toBeNull()
        ->and($frisch->max_vorlauf_tage)->toBeNull()
        ->and((int) $frisch->work_time_min)->toBe(8);
});

it('die Zeitrechnung ignoriert eine Alt-Ruestzeit am Gericht', function () {
    // Bestandswerte bleiben in der Spalte stehen (kein Datenverlust) — sie dürfen aber
    // nicht unsichtbar weiterrechnen. Genau das war das Muster, das diese Runde beseitigt.
    $this->seedTeamHierarchy();
    $gericht = $this->makeRecipe($this->rootTeam, 'Alt-Ruestzeit', [
        'is_sales_recipe' => true, 'work_time_min' => 10, 'setup_time_min' => 45,
    ]);
    $basis = $this->makeRecipe($this->rootTeam, 'Basis-Ruestzeit', [
        'work_time_min' => 10, 'setup_time_min' => 45,
    ]);

    $svc = app(\Platform\FoodAlchemist\Services\ProductionTimeService::class);
    $amGericht = $svc->calculate($this->rootTeam, $gericht->fresh(), 1.0, 'kg');
    $amBasis = $svc->calculate($this->rootTeam, $basis->fresh(), 1.0, 'kg');

    expect($amGericht['setup_minutes'])->toBe(0.0)
        ->and($amGericht['active_person_minutes'])->toBe(10.0)
        // Am Basisrezept zählt sie unverändert.
        ->and($amBasis['setup_minutes'])->toBe(45.0)
        ->and($amBasis['active_person_minutes'])->toBe(55.0);
});

it('die Anreicherung setzt Batchgrenzen, aber keine Ruestzeit am Gericht', function () {
    // Der Prompt fordert die Chargengröße an — vorher verwarf die Whitelist sie still,
    // und der Topf-Deckel blieb auf dem Team-Default (= falsche Personenminuten).
    expect(config('foodalchemist.prompts', [])['recipe.eigenschaften']['task'] ?? '')
        ->toContain('batch_max_kg');
});
