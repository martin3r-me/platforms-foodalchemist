<?php

use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\GrammaturMessung;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\GrammaturUmstellungService;
use Platform\FoodAlchemist\Services\GrammaturUmstellung\SchreibWache;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B, Schritt 3: `foodalchemist:grammatur-automatisch` auf der Charakterisierungs-Welt (A–D) plus einem nicht ableitbaren
 * Gericht E. Erwartet nach Weg A (Kurator 23.11., vorläufig für Dominique): EK und Produktion bleiben überall, die Grammatur nur
 * dort nicht, wo der Ertrag fest ist (C: Rohgewicht der Bestandteile). Trockenlauf schreibt nichts, Rückweg stellt alles her.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->e = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'leer', 'name' => 'Ohne Mengen',
        'status' => 'approved', 'is_sales_recipe' => true]);
    ($this->darreichung)($this->e, 'teller', ['is_standard' => true, 'menge_vom_standard' => false]);
    $this->dar = fn (FoodAlchemistRecipe $r, string $form) => FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)
        ->whereHas('servingForm', fn ($q) => $q->where('code', $form))->firstOrFail();
    $this->travel(1)->minutes();   // Phasen-Zählung über updated_at: Fixture-Zeilen liegen davor
});

function grammaturBericht(array $b): array
{
    return collect($b['rezepte'])->flatMap(fn ($r) => collect($r['darreichungen'])->map(fn ($d) => $d + ['recipe_id' => $r['recipe_id']]))
        ->keyBy('darreichung_id')->all();
}

it('Trockenlauf: Kategorien wie erwartet, nichts geschrieben', function () {
    // F · ein Gericht, wie es auf io liegt (Modell 23.11.: eine Portion): freie Grammatur = Summe der Bestandteile
    $kalb = ($this->gp)('Kalbsrücken', 30.00);
    [$f] = ($this->rezept)('kalbsruecken', 'Kalbsrücken', [[$kalb, 160]]);
    ($this->darreichung)($f, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 160]);
    app(\Platform\FoodAlchemist\Services\Rezept\DarreichungService::class)->recomputeFuerRezept($f->id);
    $this->travel(1)->minutes();   // Phasen-Zählung über updated_at: auch diese Fixture liegt davor
    $vorher = FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'ek_portion'])->toArray();
    $zutatenVorher = \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients')->orderBy('id')->pluck('quantity', 'id')->all();
    $b = app(GrammaturUmstellungService::class)->lauf(false);
    $z = grammaturBericht($b);

    expect($b['verstoesse'])->toBe([])->and($b['ok'])->toBeTrue();
    // F: Standard wird automatisch (Summe der Bestandteile), nichts ändert sich
    expect($z[($this->dar)($f, 'teller')->id])->toMatchArray(['aktion' => 'summe', 'fester_ertrag' => false, 'kategorie' => 'gleich']);
    // A (Chargen-Form, fester Ertrag 1,2 kg): Standard → „ertrag“, gegart = 1200 g statt 300 g → Entscheidungsliste. Kinder und Doppel
    // über Deltas gleich; „ohne Speck“ (teilweise Deltas) wird vorher festgehalten und bleibt gleich; „wie Standard“ zieht mit.
    // Früher: Standard 1,2 kg ÷ 300 g = 4 Portionen — Modell 23.11.: keine Portionszahl > 1 an Gerichten.
    $at = $z[($this->dar)($this->a, 'teller')->id];
    expect($at)->toMatchArray(['aktion' => 'ertrag', 'fester_ertrag' => true, 'kategorie' => 'nicht_verlustfrei'])
        ->and([$at['alt']['gramm'], $at['neu']['gramm']])->toBe([300.0, 1200.0])
        ->and($z[($this->dar)($this->a, 'kinder')->id])->toMatchArray(['aktion' => 'komposition', 'kategorie' => 'gleich'])
        ->and($z[($this->dar)($this->a, 'doppel')->id]['kategorie'])->toBe('gleich')
        ->and($z[($this->dar)($this->a, 'ohne-speck')->id]['kategorie'])->toBe('gleich')
        ->and($z[($this->dar)($this->a, 'wie-standard')->id]['aktion'])->toBe('folgt_standard');
    // C (fester Ertrag 0,8 kg auf 1 kg roh, Chargen-Form): Standard → gegart 800 g statt 200 g. Die Kinder-Variante über Weg A
    // zeigt jetzt gegart 100 g wie vorher — früher Rohgewicht 125 g (+25 %).
    $c = $z[($this->dar)($this->c, 'teller')->id];
    expect($c)->toMatchArray(['aktion' => 'ertrag', 'kategorie' => 'nicht_verlustfrei'])
        ->and([$c['alt']['gramm'], $c['neu']['gramm']])->toBe([200.0, 800.0])->and($c['hinweis'])->toContain('gegart')
        ->and($z[($this->dar)($this->c, 'kinder')->id]['kategorie'])->toBe('gleich');
    // D: freie Grammatur 250 g ≠ Summe 1000 g → Entscheidungsliste: alte Grammatur, neue Summe, EK vorher/nachher
    $d = $z[($this->dar)($this->d, 'teller')->id];
    expect($d)->toMatchArray(['aktion' => 'summe', 'fester_ertrag' => false, 'kategorie' => 'nicht_verlustfrei'])
        ->and($d['unterschiede'])->toContain('gramm', 'ek_portion')
        ->and([$d['alt']['gramm'], $d['neu']['gramm']])->toBe([250.0, 1000.0])
        ->and($d['neu']['ek_portion'])->toBeGreaterThan($d['alt']['ek_portion'])
        ->and($z[($this->dar)($this->d, 'kinder')->id]['kategorie'])->toBe('gleich');
    // B hat keine freie Grammatur, E ist nicht ableitbar und bleibt draußen
    expect(collect($b['rezepte'])->pluck('recipe_id')->all())->not->toContain($this->b->id)->not->toContain($this->e->id)
        ->and(collect($b['nicht_ableitbar'])->pluck('recipe_id')->all())->toBe([$this->e->id]);
    // Zählung je Phase und Team; Phase 1 nur Umbau-Tabellen
    expect(array_keys($b['zaehlung']))->toBe([0, 1, 2])   // PHP macht aus '0'…'2' Int-Schlüssel
        ->and($b['phasen'][0])->toContain('unabhängig von der Umstellung')   // Kurator 23.11.: Phase 0 klar abgegrenzt
        ->and(array_keys($b['zaehlung']['1']))->each->toBeIn(['foodalchemist_recipes', 'foodalchemist_recipe_presentations', 'foodalchemist_recipe_presentation_deltas']);

    expect(FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'ek_portion'])->toArray())->toBe($vorher)
        ->and(\Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients')->orderBy('id')->pluck('quantity', 'id')->all())->toBe($zutatenVorher);
});

it('--apply stellt um (Rezept-Kopf gibt es seit 98 · 3.5 6c nicht mehr), und der Rückweg stellt alles wieder her', function () {
    $vorher = FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'komponenten_referenz_g', 'ek_portion'])->toArray();

    $this->artisan('foodalchemist:grammatur-automatisch', ['--apply' => true])->assertExitCode(0);

    // A (fester Ertrag): automatisch, gegart = Ertrag 1200 g; Zutatenmengen unberührt
    expect((bool) ($this->dar)($this->a, 'teller')->menge_vom_standard)->toBeTrue()
        ->and(($this->dar)($this->a, 'teller')->deltas()->count())->toBe(0)
        ->and((float) ($this->dar)($this->a, 'teller')->quantity_per_unit_g)->toBe(1200.0)
        ->and((float) $this->a->ingredients()->where('raw_text', 'Kartoffeln')->value('quantity'))->toBe(1000.0)
        ->and(($this->dar)($this->a, 'kinder')->deltas()->count())->toBe(2)
        ->and((float) ($this->dar)($this->a, 'kinder')->quantity_per_unit_g)->toBe(150.0)
        ->and((float) ($this->dar)($this->c, 'teller')->ek_portion)->toBe(14.0)       // ganzer Ansatz 1 kg à 14 €/kg (Entscheidungsliste)
        ->and((float) ($this->dar)($this->c, 'kinder')->quantity_per_unit_g)->toBe(100.0)   // Weg A, gegart
        // D: Standard automatisch = Summe der Bestandteile (1000 g)
        ->and((bool) ($this->dar)($this->d, 'teller')->menge_vom_standard)->toBeTrue()
        ->and((float) ($this->dar)($this->d, 'teller')->quantity_per_unit_g)->toBe(1000.0);
    // Nichts Freies mehr außer dem nicht ableitbaren E
    expect(app(GrammaturUmstellungService::class)->plan())->toHaveCount(1);

    $journal = collect(glob(storage_path('app/foodalchemist/grammatur-umstellung/journal-*.json')))->sort()->last();
    $this->artisan('foodalchemist:grammatur-automatisch', ['--rueckweg' => $journal])->assertExitCode(0);
    @unlink($journal);

    expect(FoodAlchemistRecipeDarreichung::orderBy('id')->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'komponenten_referenz_g', 'ek_portion'])->toArray())->toBe($vorher)
        ->and(($this->dar)($this->a, 'kinder')->deltas()->count())->toBe(0);
});

it('Fertigungstiefe: Hochstufen nach Inhalt zählt als Aufholen in Phase 0, nicht als Folge der Umstellung', function () {
    // A wird von der Kaskade geführt, steht auf from_scratch, enthält aber Convenience-Ware (WG 13) → der Normalweg stuft hoch
    $this->a->ingredients()->first()->gp()->update(['commodity_group_code' => '13']);
    FoodAlchemistRecipe::whereKey($this->a->id)->update(['production_depth' => 'from_scratch', 'production_depth_source' => 'kaskade']);

    $b = app(GrammaturUmstellungService::class)->lauf(false);

    expect($b['ok'])->toBeTrue()
        ->and($b['phase0_fertigungstiefe_hochgestuft'])->toBe(1)
        ->and($b['fertigungstiefe_hochgestuft'])->toBe(0)
        ->and($this->a->fresh()->production_depth)->toBe('from_scratch');   // Trockenlauf: zurückgerollt
});

it('Trockenlauf ohne Rezept-Kopf (98 · 3.5 6c): kein Wachen-Verstoß, Kopf-Kennzahl leer', function () {
    $b = app(GrammaturUmstellungService::class)->lauf(false);

    expect($b['verstoesse'])->toBe([])->and($b['ok'])->toBeTrue()
        ->and($b['fertigungstiefe_hochgestuft'])->toBe(0)
        ->and(collect($b['rezepte'])->pluck('kennzahlen_alt.kopf_g')->filter(fn ($g) => $g !== null)->all())->toBe([]);
});

it('Verwender außerhalb der Auswahl (je Masse und je Stück) bleiben unverändert und werden geprüft', function () {
    // Master 23.11.: Eltern, die per --rezept ausgenommen sind, rechnet Phase 2 trotzdem mit
    $t = $this->rootTeam->id;
    $stk = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $this->a->update(['yield_pieces' => 4]);
    $eltern = [];
    foreach (['masse' => [300, $this->g->id], 'stueck' => [1, $stk->id]] as $key => [$menge, $einheit]) {
        $p = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'eltern-'.$key, 'name' => 'Menü '.$key, 'status' => 'approved', 'is_sales_recipe' => true]);
        $p->ingredients()->create(['team_id' => $t, 'position' => 0, 'referenced_recipe_id' => $this->a->id, 'raw_text' => 'Bratkartoffeln',
            'quantity' => $menge, 'unit_vocab_id' => $einheit, 'match_method' => 'manual']);
        ($this->darreichung)($p, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 300]);
        app(RecipeRecomputeService::class)->recomputePipeline($p->id);
        $eltern[] = $p->id;
    }

    $b = app(GrammaturUmstellungService::class)->lauf(true, [$this->a->id]);

    expect($b['verstoesse'])->toBe([])->and($b['ok'])->toBeTrue()
        ->and($b['eltern_geprueft'])->toBe(['rezepte' => 2, 'darreichungen' => 2])
        ->and(collect($b['rezepte'])->pluck('recipe_id')->all())->toBe([$this->a->id]);   // Eltern selbst nicht umgestellt
    foreach ($eltern as $id) {
        expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $id)->first()->menge_vom_standard)->toBeFalse();
    }
    @array_map('unlink', glob(storage_path('app/foodalchemist/grammatur-umstellung/journal-*.json')) ?: []);
});

it('Verwender-Prüfung: jede Bewegung einer Eltern-Darreichung ist ein Verstoß', function () {
    $d = ['gramm' => 300.0, 'ek_portion' => 1.0, 'calculated_sales_net' => 4.0, 'sales_net' => 4.0, 'price_mode' => 'auto', 'je_zutat' => [1 => 0.25]];
    $alt = [9 => ['rezept' => [], 'darreichungen' => [5 => $d, 6 => $d]]];
    $neu = [9 => ['rezept' => [], 'darreichungen' => [5 => ['gramm' => 300.05] + $d, 6 => ['ek_portion' => 1.2] + $d]]];
    $v = GrammaturMessung::elternVerstoesse($alt, $neu);
    expect($v)->toHaveCount(1)->and($v[0])->toContain('Darreichung 6')->toContain('ek_portion');
    expect(GrammaturMessung::elternVerstoesse($alt, [9 => ['rezept' => [], 'darreichungen' => [5 => $d]]]))->toHaveCount(1);   // fehlt
});

it('--json legt das Zielverzeichnis an', function () {
    $datei = storage_path('app/foodalchemist/test-'.Str::random(8).'/bericht.json');
    $this->artisan('foodalchemist:grammatur-automatisch', ['--json' => $datei])->assertExitCode(0);
    expect(json_decode((string) file_get_contents($datei), true))->toHaveKeys(['ok', 'rezepte', 'zaehlung', 'nicht_ableitbar']);
    @unlink($datei);
    @rmdir(dirname($datei));
});

it('Phase-0-EK-Sprünge: je Tabelle und Team Zahl der Rezepte, größter Sprung mit Rezept, Zahl ab 20 %', function () {
    $vorher = ['foodalchemist_recipe_presentations' => [1 => [5, 10, 2.0], 2 => [5, 10, 4.0], 3 => [5, 11, 1.0], 4 => [6, 12, 3.0], 5 => [6, 13, null]]];
    $nachher = ['foodalchemist_recipe_presentations' => [1 => [5, 10, 2.002], 2 => [5, 10, 5.0], 3 => [5, 11, 1.5], 4 => [6, 12, 3.0], 5 => [6, 13, 2.0]]];
    expect(GrammaturMessung::ekSpruenge($vorher, $nachher))->toBe(['foodalchemist_recipe_presentations' => [
        '5' => ['rezepte' => 2, 'max_prozent' => 50.0, 'max_recipe_id' => 11, 'ab_warnschwelle' => 2],   // R10 +25 %, R11 +50 %; 0,2 ct unter Toleranz zählt nicht
        '6' => ['rezepte' => 1, 'max_prozent' => null, 'max_recipe_id' => null, 'ab_warnschwelle' => 0],   // leer → 2 €: geändert, ohne Prozent
    ]]);
});

it('Schreibwache: fremde Tabelle, fremde Rezept-Spalte und fremdes Rezept sind Verstöße; fremde Warnungen nicht', function () {
    $w = new SchreibWache;
    $w->phase('1', [7]);
    $w->pruefe('update "foodalchemist_recipes" set "sales_unit_count" = ?, "updated_at" = ? where "id" = ?', [4, 'x', 7]);
    expect($w->verstoesse())->toBe([]);
    $w->pruefe('update "foodalchemist_recipes" set "ek_per_kg_eur" = ? where "id" = ?', [1, 7]);
    $w->pruefe('update "foodalchemist_recipes" set "sales_unit_count" = ? where "id" = ?', [4, 8]);
    $w->pruefe('insert into "foodalchemist_concepts" ("name") values (?)', ['x']);
    $w->phase('2');
    $w->pruefe('delete from "foodalchemist_price_change_audits" where "id" = ?', [1]);
    $w->pruefe('select * from "foodalchemist_concepts"', []);
    expect($w->verstoesse())->toHaveCount(4);
});

it('Schreibwache: Datenbank-Cache des Frameworks (CACHE_STORE=database) ist in jeder Phase erlaubt, wird aber gezählt', function () {
    // SQL, wie es der Store `database` erzeugt (Trockenlauf io 23.11.: Abbruch in Phase 0 wegen DELETE/INSERT auf `cache`)
    $cacheSql = [
        ['delete from "cache" where "key" in (?)', ['fa:k']],
        ['insert into "cache" ("expiration", "key", "value") values (?, ?, ?) on conflict ("key") do update set "expiration" = "excluded"."expiration", "value" = "excluded"."value"', [1, 'fa:k', 'v']],
        ['insert into `cache` (`expiration`, `key`, `value`) values (?, ?, ?) on duplicate key update `expiration` = values(`expiration`), `value` = values(`value`)', [1, 'fa:k', 'v']],
        ['update `cache_locks` set `owner` = ?, `expiration` = ? where `key` = ?', ['o', 1, 'fa:l']],
        ['insert into "cache_locks" ("key", "owner", "expiration") values (?, ?, ?)', ['fa:l', 'o', 1]],
        ['delete from `cache_locks` where `key` = ? and `owner` = ?', ['fa:l', 'o']],
    ];
    $w = new SchreibWache;
    foreach (['0', '1', '2'] as $phase) {
        $w->phase($phase, [7]);
        foreach ($cacheSql as [$sql, $b]) {
            $w->pruefe($sql, $b);
        }
    }
    expect($w->verstoesse())->toBe([])
        ->and($w->anweisungen()['0']['cache'])->toBe(['delete' => 1, 'insert' => 2])
        ->and($w->anweisungen()['2']['cache_locks'])->toBe(['update' => 1, 'insert' => 1, 'delete' => 1]);

    // Umbenannte Cache-Tabelle aus der Konfiguration; eine fremde Tabelle bleibt ein Verstoß
    config(['cache.stores.database.table' => 'fa_cache']);
    $w->phase('0');
    $w->pruefe('delete from "fa_cache" where "key" in (?)', ['x']);
    $w->pruefe('delete from "jobs" where "id" = ?', [1]);
    expect($w->verstoesse())->toHaveCount(1)->and($w->verstoesse()[0])->toContain('jobs');
});

it('R149 (Trockenlauf io 23.11.): 0,5 Stück Ei ohne Stückgewicht — Gericht bleibt unangetastet, EK, VK und Produktion unverändert', function () {
    $t = $this->rootTeam->id;
    $stk = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    // Ei: Preis je Stück (10er-Karton 3,00 € → 0,30 €/Stück), aber KEIN Stückgewicht → im Komponentenmodell 0 g
    $ei = $this->makeGp($this->rootTeam, 'Ei');
    $lieferant = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Eierhof']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lieferant->id, 'designation' => 'Eier 10 Stk',
        'article_number' => 'EI-10', 'qty' => 10.0, 'unit_code' => 'Stk', 'packaging_unit' => 'Karton']);
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $ei->id]);
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => 3.00, 'status' => '0']);
    $ei->update(['lead_la_supplier_item_id' => $la->id, 'piece_default_g' => null]);

    [$salat] = ($this->rezept)('linsensalat', '[VOR] Linsensalat | Ei', [[($this->gp)('Belugalinsen', 4.00), 200]]);
    $salat->ingredients()->create(['team_id' => $t, 'position' => 9, 'gp_id' => $ei->id, 'raw_text' => 'Ei', 'quantity' => 0.5,
        'unit_vocab_id' => $stk->id, 'match_method' => 'manual']);
    app(RecipeRecomputeService::class)->recomputePipeline($salat->id);
    ($this->darreichung)($salat, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 200]);
    ($this->darreichung)($salat, 'klein', ['is_standard' => false, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 100]);
    $dar = app(DarreichungService::class);
    $dar->recomputeFuerRezept($salat->id);
    $stand = fn () => FoodAlchemistRecipeDarreichung::where('recipe_id', $salat->id)->orderBy('id')
        ->get(['id', 'quantity_per_unit_g', 'menge_vom_standard', 'komponenten_referenz_g', 'ek_portion', 'sales_net'])->toArray();
    $produktion = fn () => FoodAlchemistRecipeDarreichung::where('recipe_id', $salat->id)->orderBy('id')->get()
        ->map(fn ($d) => $dar->produktionsAnteil($salat->fresh(), $d, 10, $this->rootTeam))->all();
    $vorher = [$stand(), $produktion()];
    expect((float) $salat->fresh()->ek_total_eur)->toBeGreaterThan(0.8);   // 200 g Linsen 0,80 € + 0,5 Ei 0,15 €

    // Trockenlauf: beide Darreichungen nicht ableitbar, mit dem Ei im Grund
    $b = app(GrammaturUmstellungService::class)->lauf(false, [$salat->id]);
    $nicht = collect($b['nicht_ableitbar'])->where('recipe_id', $salat->id);
    expect($b['ok'])->toBeTrue()
        ->and($nicht)->toHaveCount(2)
        ->and($nicht->pluck('grund')->all())->each->toContain('Bestandteile ohne Gewicht: Ei (0,5 Stück)')
        ->and(collect($b['rezepte'])->pluck('recipe_id')->all())->not->toContain($salat->id);

    // Apply: nichts wird umgestellt — Grammatur, EK, VK und die Produktion (das halbe Ei) bleiben
    app(GrammaturUmstellungService::class)->lauf(true, [$salat->id]);
    expect([$stand(), $produktion()])->toEqual($vorher)
        ->and(FoodAlchemistRecipeDarreichungDelta::whereIn('presentation_id', array_column($vorher[0], 'id'))->count())->toBe(0);
});
