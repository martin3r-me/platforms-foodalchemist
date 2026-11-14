<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\FettAngabe;
use Platform\FoodAlchemist\Support\GpSammelware;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.10.0 (Entscheid Dominique 13.11.2026) — Namensbildung und Prüfung für den GP-Lauf:
 * Regel 92/830 (Honig, Essig ohne Zustandswort), 94 (Klammer-Reihenfolge, Land, MSC/ASC), 92 (TK + Behandlung), 826/75 (Fett hinter dem Zustand,
 * Stückgewicht exakt), §6.1 (Sammelware bleibt Plural), 91/820 (Kennzeichen angenommen/offen), 94 Nr. 5 (reiner Bio-Händler).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false]);
    $this->n = app(GpNamingService::class);
    $this->tool = fn (string $name, array $args) => app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($this->user, $this->rootTeam));
});

it('Honig und Essig tragen kein Zustandswort (Regel 92/830, O1) — auch Essig in 11.3 und außerhalb von WG 11', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Akazienhonig', 'condition' => 'konserviert', 'pflichtangabe' => 'flüssig', 'commodity_group_code' => '10']))
        ->toBe('Akazienhonig: flüssig')
        ->and($this->n->renderGpName(['hauptzutat' => 'Weißweinessig', 'condition' => 'konserviert', 'commodity_group_code' => '11', 'sub_category' => '11.3 Essig']))
        ->toBe('Weißweinessig')
        ->and($this->n->renderGpName(['hauptzutat' => 'Apfelessig', 'condition' => 'konserviert']))->toBe('Apfelessig')
        // Gegenprobe: Ware MIT Honig ist nicht der Honig selbst
        ->and($this->n->renderGpName(['hauptzutat' => 'Senf mit Honig', 'condition' => 'konserviert']))->toBe('Senf mit Honig: konserviert')
        ->and($this->n->renderGpName(['hauptzutat' => 'Honigmelone', 'condition' => 'frisch']))->toBe('Honigmelone: frisch');
});

it('Klammern in fester Reihenfolge Marke / Land / Bio / MSC / Vegan / Glutenfrei / Laktosefrei, unabhängig von der Eingabe (Regel 94)', function () {
    $alle = ['hauptzutat' => 'Testware', 'condition' => 'frisch', 'laktosefrei' => true, 'glutenfrei' => true, 'vegan' => true, 'msc' => true,
        'bio' => true, 'herkunft_land' => 'Argentinien', 'zusatz' => 'Hersteller'];
    expect($this->n->renderGpName($alle))->toBe('Testware: frisch / (Hersteller) / (Argentinien) / (Bio) / (MSC) / (Vegan) / (Glutenfrei) / (Laktosefrei)')
        ->and($this->n->renderGpName(['hauptzutat' => 'Pangasiusfilet', 'condition' => 'TK', 'asc' => true]))->toBe('Pangasiusfilet: TK / (ASC)')
        ->and($this->n->renderGpName(['hauptzutat' => 'Steakhüfte', 'condition' => 'frisch', 'herkunft_land' => 'Uruguay']))->toBe('Steakhüfte: frisch / (Uruguay)');

    // Zurücklesen: Land und Siegel sind kein Fantasiename; nameOhneMarke behält Land und Siegel, entfernt nur die Marke
    $f = $this->n->felderAusName('Kabeljaufilet: ohne Haut, TK / (Iglo) / (Norwegen) / (MSC)', true);
    expect($f['zusatz'])->toBe('Iglo')->and($f['herkunft_land'])->toBe('Norwegen')->and($f['msc'])->toBeTrue()->and($f['asc'])->toBeFalse()
        ->and($this->n->nameOhneMarke('Kabeljaufilet: ohne Haut, TK / (Iglo) / (Norwegen) / (MSC)'))->toBe('Kabeljaufilet: ohne Haut, TK / (Norwegen) / (MSC)');
});

it('Land- und Siegel-Klammer: Anlage speichert, Teil-Update rendert sie weiter, Hinweise bei EU-Land und falscher Ware', function () {
    $gp = $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Steakhüfte', 'condition' => 'frisch', 'commodity_group_code' => '04', 'herkunft_land' => 'Argentinien']);
    expect($gp->name)->toBe('Steakhüfte: frisch / (Argentinien)')->and($gp->herkunft_land)->toBe('Argentinien');

    // Teil-Schreiber ohne herkunft_land rendert die Klammer weiter (sonst fiele das Land still aus dem Namen)
    $gp = $this->n->updateGp($this->rootTeam, $gp, ['hauptzutat' => 'Steakhüfte', 'condition' => 'frisch', 'processing' => 'Dry Aged']);
    expect($gp->name)->toBe('Steakhüfte: Dry Aged, frisch / (Argentinien)')->and($gp->herkunft_land)->toBe('Argentinien');
    $gp = $this->n->updateGp($this->rootTeam, $gp, ['hauptzutat' => 'Steakhüfte', 'condition' => 'frisch', 'herkunft_land' => '']);
    expect($gp->name)->toBe('Steakhüfte: frisch')->and($gp->herkunft_land)->toBeNull();

    $warn = fn (array $in) => $this->n->validateGpName($this->n->renderGpName($in), $in)['warnings'];
    expect(implode(' ', $warn(['hauptzutat' => 'Rinderfilet', 'condition' => 'frisch', 'commodity_group_code' => '04', 'herkunft_land' => 'Deutschland'])))->toContain('ohne Klammer')
        ->and(implode(' ', $warn(['hauptzutat' => 'Kabeljaufilet', 'condition' => 'TK', 'commodity_group_code' => '05', 'msc' => true, 'asc' => true])))->toContain('nur eines')
        ->and(implode(' ', $warn(['hauptzutat' => 'Kabeljaufilet', 'condition' => 'TK', 'commodity_group_code' => '05', 'msc' => true])))->not->toContain('Regel 94');
});

it('TK + Behandlung: „…, geräuchert, TK“ — TK wandert aus jeder Stelle der Verarbeitung hinter den Zustand (Regel 92)', function () {
    foreach (['TK, geschnitten', 'geschnitten, TK', 'geschnitten, tiefgekühlt'] as $proc) {
        expect($this->n->renderGpName(['hauptzutat' => 'Lachs', 'processing' => $proc, 'condition' => 'geräuchert']))->toBe('Lachs: geschnitten, geräuchert, TK', $proc);
    }
    expect($this->n->renderGpName(['hauptzutat' => 'Forellenfilet', 'processing' => 'TK', 'condition' => 'geräuchert']))->toBe('Forellenfilet: geräuchert, TK')
        // ohne Behandlung bleibt TK der Zustand
        ->and($this->n->renderGpName(['hauptzutat' => 'Erbse', 'condition' => 'TK']))->toBe('Erbse: TK');
});

it('Fettwert exakt als „N % Fett“ bzw. „N % Fett i. Tr.“ direkt hinter dem Zustand, aus jedem Feld (Regel 826, M2/M4)', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Milch', 'condition' => 'frisch', 'pflichtangabe' => '3.5% Fett']))->toBe('Milch: frisch, 3,5 % Fett')
        ->and($this->n->renderGpName(['hauptzutat' => 'Sahne', 'processing' => 'Fett 33 %', 'condition' => 'frisch']))->toBe('Sahne: frisch, 33 % Fett')
        ->and($this->n->renderGpName(['hauptzutat' => 'Quark', 'condition' => 'frisch', 'pflichtangabe' => '20% Fett i.Tr.']))->toBe('Quark: frisch, 20 % Fett i. Tr.')
        ->and($this->n->renderGpName(['hauptzutat' => 'Frischkäse', 'form' => 'Portion', 'condition' => 'frisch', 'portion' => '20 g', 'pflichtangabe' => '60 % F. i. Tr.']))
        ->toBe('Frischkäse: Portion, frisch, 60 % Fett i. Tr., 20 g pro Stück')
        // nacktes „N %“ ist kein Fettwert (Kakao, Frucht) und bleibt unverändert
        ->and($this->n->renderGpName(['hauptzutat' => 'Kuvertüre', 'condition' => 'trocken', 'pflichtangabe' => '70 %']))->toBe('Kuvertüre: trocken, 70 %')
        ->and(FettAngabe::kanon('Fettgehalt 3,8 %'))->toBe('3,8 % Fett')
        ->and(FettAngabe::kanon('mild'))->toBeNull();
});

it('Stückgewicht exakt wie im Artikel: kein Runden, Spannen bleiben, 150 g und 160 g sind zwei GPs (Regel 75, G14)', function () {
    expect($this->n->renderGpName(['hauptzutat' => 'Wolfsbarschfilet', 'condition' => 'TK', 'portion' => '80-120 g']))->toBe('Wolfsbarschfilet: TK, 80-120 g pro Stück')
        ->and($this->n->renderGpName(['hauptzutat' => 'Rostbratwurst', 'processing' => 'gebrüht', 'condition' => 'frisch', 'portion' => '22 g']))->toBe('Rostbratwurst: gebrüht, frisch, 22 g pro Stück');

    $a = $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Zanderfilet', 'condition' => 'TK', 'portion' => '150 g']);
    $b = $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Zanderfilet', 'condition' => 'TK', 'portion' => '160 g', 'name' => 'Zanderfilet: TK, 160 g pro Stück']);
    expect($a->id)->not->toBe($b->id)->and($this->n->anlageGuard($this->rootTeam, 'x|y|z', 'Zanderfilet: TK, 155 g pro Stück')['blockiert'])->toBeFalse();
});

it('Sammelware bleibt Plural: Eier, Hülsenfrüchte, Nüsse & Kerne, Beeren, Kapern, Oliven (§6.1, Nachtrag 13.11.)', function () {
    expect(GpSammelware::mehrzahlStatt('Walnuss'))->toBe('Walnüsse')
        ->and(GpSammelware::mehrzahlStatt('Olive grün'))->toBe('Oliven grün')
        ->and(GpSammelware::mehrzahlStatt('Kaper'))->toBe('Kapern')
        ->and(GpSammelware::mehrzahlStatt('Kichererbse'))->toBe('Kichererbsen')
        ->and(GpSammelware::mehrzahlStatt('Walnüsse'))->toBeNull()
        ->and(GpSammelware::mehrzahlStatt('Garnele'))->toBeNull()
        ->and(GpSammelware::mehrzahlStatt('Olivenöl'))->toBeNull()
        ->and(GpSammelware::verbotenerSingular('Erdnüsse: geröstet, trocken', 'Erdnuss: geröstet, trocken'))->toBeTrue()
        ->and(GpSammelware::verbotenerSingular('Bananen: frisch', 'Banane: frisch'))->toBeFalse();

    $w = $this->n->validateGpName('Walnuss: gehackt, trocken', ['hauptzutat' => 'Walnuss', 'processing' => 'gehackt', 'condition' => 'trocken'])['warnings'];
    expect(implode(' ', $w))->toContain('»Walnüsse« statt »Walnuss«');
    $ok = $this->n->validateGpName('Walnüsse: gehackt, trocken', ['hauptzutat' => 'Walnüsse', 'processing' => 'gehackt', 'condition' => 'trocken'])['warnings'];
    expect(implode(' ', $ok))->not->toContain('§6.1');
});

it('Bereinigungs-Lauf: ein Singular-Vorschlag für Sammelware wird verworfen (Konflikt), andere Plural-Fixes laufen', function () {
    $mk = fn (string $name) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => $this->n->slugify($name),
        'status' => 'approved', 'commodity_group_code' => '07', 'condition' => 'trocken', 'main_ingredient_slug' => $this->n->slugify(strstr($name, ':', true))]);
    $nuss = $mk('Erdnüsse: geröstet, trocken');
    $datei = tempnam(sys_get_temp_dir(), 'gpb') . '.csv';
    $h = fopen($datei, 'wb');
    fputcsv($h, ['gp_id', 'name', 'rule', 'proposal'], ';', '"', '');
    fputcsv($h, [$nuss->id, $nuss->name, '§6.1 Plural statt Singular', 'Erdnuss: geröstet, trocken'], ';', '"', '');
    fclose($h);

    $plan = app(\Platform\FoodAlchemist\Services\GpBereinigenService::class)->planNamen($this->rootTeam->id, $datei);
    expect($plan['aenderungen'])->toBe([])->and(implode(' ', array_column($plan['konflikte'], 'grund')))->toContain('Sammelware bleibt in der Mehrzahl');
    @unlink($datei);
});

it('Kennzeichen angenommen/offen (Regel 91, 820): Anlage, MCP-Pflege, Prüflisten-Filter, nie im Namen', function () {
    $gp = $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Sardellenfilet', 'condition' => 'konserviert', 'commodity_group_code' => '05',
        'angabe_status' => 'offen', 'angabe_grund' => 'Konservierungsart nicht belegt (§8.20)']);
    expect($gp->name)->toBe('Sardellenfilet: konserviert')->and($gp->angabe_status)->toBe('offen')->and($gp->angabe_grund)->toBe('Konservierungsart nicht belegt (§8.20)');

    expect(fn () => $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Möhre', 'condition' => 'TK', 'angabe_status' => 'vermutet']))
        ->toThrow(RuntimeException::class, 'angenommen, offen');

    // gps.PUT: Teil-Update ohne Kennzeichen lässt es stehen; angenommen ersetzt; leer löscht samt Grund
    $put = fn (array $a) => ($this->tool)('foodalchemist.gps.PUT', ['id' => $gp->id, 'hauptzutat' => 'Sardellenfilet', 'condition' => 'konserviert'] + $a);
    expect($put([])->success)->toBeTrue()->and($gp->fresh()->angabe_status)->toBe('offen');
    $r = $put(['angabe_status' => 'angenommen', 'angabe_grund' => 'in Öl angenommen']);
    expect($r->success)->toBeTrue()->and($r->data['angabe_status'])->toBe('angenommen');
    $andere = $this->n->createGp($this->rootTeam, ['hauptzutat' => 'Ananas', 'form' => 'Scheiben', 'condition' => 'konserviert', 'angabe_status' => 'offen']);
    $liste = ($this->tool)('foodalchemist.gps.LIST', ['angabe_status' => 'offen', 'felder' => 'voll']);
    expect(array_column($liste->data['gps'], 'id'))->toBe([$andere->id]);
    expect(($this->tool)('foodalchemist.gps.GET', ['id' => $gp->id])->data['angabe_status'])->toBe('angenommen');
    expect($put(['angabe_status' => ''])->success)->toBeTrue()->and($gp->fresh()->angabe_status)->toBeNull()->and($gp->fresh()->angabe_grund)->toBeNull();

    foreach (['foodalchemist.gps.POST', 'foodalchemist.gps.PUT'] as $t) {
        expect(app(ToolRegistry::class)->get($t)->getSchema()['properties'])->toHaveKeys(['msc', 'asc', 'herkunft_land', 'angabe_status', 'angabe_grund']);
    }
});

it('Dubletten-Plan: ein GP mit Kennzeichen „offen“ wird nie mit einem belegten GP zusammengeführt (Regel 91 Nr. 3)', function () {
    $mk = fn (string $name, ?string $status) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => $this->n->slugify($name) . uniqid(),
        'status' => 'approved', 'commodity_group_code' => '05', 'condition' => 'konserviert', 'main_ingredient_slug' => 'sardellenfilet', 'angabe_status' => $status]);
    $belegt = $mk('Sardellenfilet: konserviert, in Öl', null);
    $offen = $mk('Sardellenfilet: konserviert', 'offen');
    $datei = tempnam(sys_get_temp_dir(), 'gpd') . '.csv';
    $h = fopen($datei, 'wb');
    fputcsv($h, ['group_id', 'gp_id', 'name', 'keep_proposal', 'reason'], ';', '"', '');
    fputcsv($h, ['N0001', $belegt->id, $belegt->name, 'behalten', ''], ';', '"', '');
    fputcsv($h, ['N0001', $offen->id, $offen->name, 'merge→' . $belegt->id, ''], ';', '"', '');
    fclose($h);

    $plan = app(\Platform\FoodAlchemist\Services\GpBereinigenService::class)->planDubletten($this->rootTeam->id, $datei);
    expect($plan['gruppen'])->toBe([])->and(implode(' ', array_column($plan['konflikte'], 'grund')))->toContain('Kennzeichen „offen“');
    @unlink($datei);
});

it('Reiner Bio-Händler (Regel 94 Nr. 5): Merkmal per suppliers.PUT, jeder Artikel gilt als Bio, Stammblatt zeigt es', function () {
    $s = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Bio Partner Süd']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $s->id, 'designation' => 'Möhren lose', 'article_number' => 'B1', 'qty' => 1, 'unit_code' => 'kg', 'is_organic' => false]);
    expect($la->istBio())->toBeFalse();

    expect(($this->tool)('foodalchemist.suppliers.PUT', ['id' => $s->id, 'input' => ['name' => 'Bio Partner Süd', 'reiner_bio_haendler' => true]])->success)->toBeTrue();
    expect($s->fresh()->reiner_bio_haendler)->toBeTrue()->and($la->fresh()->istBio())->toBeTrue()
        ->and(($this->tool)('foodalchemist.suppliers.GET', ['supplier_id' => $s->id])->data['reiner_bio_haendler'])->toBeTrue();

    // Teil-Schreiber ohne den Schlüssel lässt das Merkmal stehen
    ($this->tool)('foodalchemist.suppliers.PUT', ['id' => $s->id, 'input' => ['name' => 'Bio Partner Süd GmbH']]);
    expect($s->fresh()->reiner_bio_haendler)->toBeTrue();
    ($this->tool)('foodalchemist.suppliers.PUT', ['id' => $s->id, 'input' => ['name' => 'Bio Partner Süd GmbH', 'reiner_bio_haendler' => 'nein']]);
    expect($s->fresh()->reiner_bio_haendler)->toBeFalse();

    $normal = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $s->id, 'designation' => 'Bio Möhren', 'article_number' => 'B2', 'qty' => 1, 'unit_code' => 'kg', 'is_organic' => true]);
    expect($normal->istBio())->toBeTrue();
});

it('Migrationen der neuen Felder sind idempotent (2× up)', function () {
    foreach (['2026_12_28_100000_add_regelwerk_v310_felder_to_foodalchemist_gps.php', '2026_12_28_110000_add_reiner_bio_haendler_to_foodalchemist_suppliers.php'] as $datei) {
        $m = require dirname(__DIR__, 2) . '/database/migrations/' . $datei;
        $m->up();
        $m->up();
    }
    expect(\Illuminate\Support\Facades\Schema::hasColumns('foodalchemist_gps', ['herkunft_land', 'namens_msc', 'namens_asc', 'angabe_status', 'angabe_grund']))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_suppliers', 'reiner_bio_haendler'))->toBeTrue()
        ->and(DB::table('foodalchemist_gps')->count())->toBeInt();
});
