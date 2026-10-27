<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\Matching\VerknuepfbareArtikelSuche;
use Platform\FoodAlchemist\Services\StammLieferantService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bugfix Dominique 27.10.2026 (GP-Detail → „Artikel verknüpfen“): vorher alphabetisch mit LIMIT 8 — bei „apfelsaft“ nur Handelshof „0,2 …“.
 * Jetzt Relevanz → Lieferanten-Rang → Bezeichnung, 20 Treffer mit Hinweis auf weitere, Gebinde + Preis der Team-Sicht, Suche auch über die Marke.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->handelshof = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Handelshof']);
    $this->fako = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Fako']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Chefs Culinar']);
    $this->la = fn (FoodAlchemistSupplier $sup, string $bez, array $extra = []) => FoodAlchemistSupplierItem::create(array_merge(['team_id' => null,
        'supplier_id' => $sup->id, 'designation' => $bez, 'article_number' => 'A' . Str::random(8), 'qty' => 1, 'unit_code' => 'l'], $extra));
    $this->suche = fn (string $q, $team = null) => app(VerknuepfbareArtikelSuche::class)->suche($team ?? $this->rootTeam, $q);
    $this->namen = fn (string $q, $team = null) => ($this->suche)($q, $team)['treffer']->pluck('designation')->all();
});

it('Relevanz: Wort am Anfang (ohne Gebinde vorn) vor ganzem Wort vor Teilstring — nicht mehr alphabetisch', function () {
    ($this->la)($this->chefs, 'Granatapfelsaft Bio');
    ($this->la)($this->chefs, 'Happy Day Apfelsaft');
    ($this->la)($this->handelshof, '0,2 Apfelsaft klar', ['qty' => 0.2]);
    ($this->la)($this->fako, 'Apfelsaft trüb');

    expect(($this->namen)('apfelsaft'))->toBe(['0,2 Apfelsaft klar', 'Apfelsaft trüb', 'Happy Day Apfelsaft', 'Granatapfelsaft Bio']);
});

it('Lieferanten-Rang des Teams: Prioritätskette vor Stamm-Lieferant vor Rest, erst bei gleicher Relevanz', function () {
    ($this->la)($this->handelshof, '0,2 Apfelsaft klar');
    ($this->la)($this->fako, 'Apfelsaft klar');
    ($this->la)($this->chefs, 'Apfelsaft klar');
    ($this->la)($this->chefs, 'Happy Day Apfelsaft');   // schwächere Relevanz bleibt hinten, auch beim priorisierten Lieferanten

    expect(($this->suche)('apfelsaft')['treffer']->pluck('supplier_id')->take(3)->all())->toBe([$this->handelshof->id, $this->fako->id, $this->chefs->id]);

    app(StammLieferantService::class)->setStamm($this->rootTeam, $this->fako->id);
    app(TeamSettingsService::class)->update($this->rootTeam, ['lead_la_prioritaeten' => [$this->chefs->id]]);
    $treffer = ($this->suche)('apfelsaft')['treffer'];

    expect($treffer->pluck('supplier_id')->all())->toBe([$this->chefs->id, $this->fako->id, $this->handelshof->id, $this->chefs->id])
        ->and($treffer->last()->designation)->toBe('Happy Day Apfelsaft');
});

it('20 Treffer und Hinweis auf weitere; über dem Kandidatenfenster „mehr als“', function () {
    foreach (range(1, 25) as $i) {
        ($this->la)($this->fako, "Apfelsaft Sorte {$i}");
    }
    $r = ($this->suche)('apfelsaft');
    expect($r['treffer'])->toHaveCount(20)->and($r['weitere'])->toBe(5)->and($r['mehr_als'])->toBeNull();

    foreach (range(26, VerknuepfbareArtikelSuche::KANDIDATEN + 5) as $i) {
        ($this->la)($this->fako, "Apfelsaft Sorte {$i}");
    }
    $r = ($this->suche)('apfelsaft');
    expect($r['treffer'])->toHaveCount(20)->and($r['mehr_als'])->toBe(VerknuepfbareArtikelSuche::KANDIDATEN);
});

it('nur freie und lieferbare Artikel; LeadLaService::sucheVerknuepfbare liefert dieselben Treffer', function () {
    $frei = ($this->la)($this->fako, 'Apfelsaft frei');
    $gebunden = ($this->la)($this->fako, 'Apfelsaft gebunden');
    ($this->la)($this->fako, 'Apfelsaft eingestellt', ['lieferstatus' => 'eingestellt', 'lieferstatus_quelle' => 'necta']);
    $gp = $this->makeGp($this->rootTeam, 'Apfelsaft');
    FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $gebunden->id, 'gp_id' => $gp->id]);

    expect(($this->namen)('apfelsaft'))->toBe(['Apfelsaft frei'])
        ->and(app(LeadLaService::class)->sucheVerknuepfbare($this->rootTeam, 'apfelsaft')->pluck('id')->all())->toBe([$frei->id]);
});

it('Artikelnummer findet den Artikel', function () {
    $la = ($this->la)($this->fako, 'Apfelsaft klar', ['article_number' => '40909330']);
    ($this->la)($this->fako, 'Apfelsaft trüb');

    expect(($this->suche)('40909330')['treffer']->pluck('id')->all())->toBe([$la->id]);
});

it('Marke: Kürzel aus supplier_marken (lieferantengebunden) und Necta-brand, Marken-Treffer hinter Bezeichnungs-Treffern', function () {
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->chefs->id, 'kuerzel' => 'ht', 'marke' => 'Hi Taste', 'typ' => FoodAlchemistSupplierMarke::TYP_EIGENMARKE, 'position' => 'anfang']);
    ($this->la)($this->chefs, 'TK HT Bami Goreng 6x400g');
    ($this->la)($this->handelshof, 'TK HT Fremdware');                  // Kürzel gilt nur bei Chefs
    ($this->la)($this->fako, 'Nudelsuppe', ['brand' => 'Hi Taste']);      // Necta-Marke
    ($this->la)($this->fako, 'Hi Taste Sauce Curry');                     // Bezeichnungs-Treffer
    ($this->la)($this->fako, 'Chicken Taste Pulver');                     // nur Teilstring „hi“ → hinter der Marke

    expect(($this->namen)('hi taste'))->toBe(['Hi Taste Sauce Curry', 'Nudelsuppe', 'TK HT Bami Goreng 6x400g', 'Chicken Taste Pulver']);
});

it('Artikel inaktiver Lieferanten erscheinen nicht (Handelshof neben Handelshof EDI)', function () {
    $alt = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Handelshof Mönchengladbach', 'is_inactive' => true]);
    $edi = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Handelshof Mönchengladbach EDI']);
    ($this->la)($alt, '0,2 Apfelsaft klar MW');
    $neu = ($this->la)($edi, '0,2 Apfelsaft klar MW');

    expect(($this->suche)('apfelsaft')['treffer']->pluck('id')->all())->toBe([$neu->id]);
});

it('mit Preis der Team-Sicht vor ohne Preis — innerhalb gleicher Wortstufe, vor dem Lieferanten-Rang; schlechtere Wortstufe bleibt hinten', function () {
    $preis = fn ($la, $team, float $p) => DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(), 'supplier_item_id' => $la->id, 'team_id' => $team->id,
        'status' => '0', 'price' => $p, 'is_blocked' => false, 'status_valid_from' => '2026-10-22 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $ohne = ($this->la)($this->chefs, 'Apfelsaft klar');              // Prioritäts-Lieferant, aber ohne Preis
    $mit = ($this->la)($this->fako, 'Apfelsaft trüb');
    $wort = ($this->la)($this->fako, 'Happy Day Apfelsaft');          // mit Preis, aber nur „ganzes Wort“
    $preis($mit, $this->childA, 1.10);                                 // Teampreise von Kind A (Eltern-Preise sähe auch Kind B)
    $preis($wort, $this->childA, 0.90);
    app(TeamSettingsService::class)->update($this->childA, ['lead_la_prioritaeten' => [$this->chefs->id]]);

    expect(($this->suche)('apfelsaft', $this->childA)['treffer']->pluck('id')->all())->toBe([$mit->id, $ohne->id, $wort->id])
        ->and(($this->suche)('apfelsaft', $this->childB)['treffer']->pluck('id')->all())->toBe([$ohne->id, $mit->id, $wort->id]);   // fremde Sicht: kein Preis, Name entscheidet
});

it('Gebinde und Preis in der Preis-Sicht des Teams (Mandantenpreis nie bei fremdem Team)', function () {
    $la = ($this->la)($this->fako, 'Apfelsaft klar', ['qty' => 0.2]);
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(), 'supplier_item_id' => $la->id, 'team_id' => $this->childA->id, 'status' => '0',
        'price' => 0.84, 'is_blocked' => false, 'status_valid_from' => '2026-10-22 00:00:00', 'created_at' => now(), 'updated_at' => now()]);

    $a = ($this->suche)('apfelsaft', $this->childA)['treffer']->first();
    $b = ($this->suche)('apfelsaft', $this->childB)['treffer']->first();

    expect($a->gebinde_text)->toBe('0,2 l')->and((float) $a->aktiver_preis)->toBe(0.84)
        ->and($b->id)->toBe($la->id)->and($b->aktiver_preis)->toBeNull();
});

it('GP-Detail zeigt Treffer mit Gebinde und Preis und den Hinweis auf weitere; GP-Modal ebenso', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    foreach (range(1, 23) as $i) {
        ($this->la)($this->fako, "Apfelsaft Sorte {$i}", ['qty' => 0.2]);
    }
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Apfelsaft: klar', 'gp_key' => 'apfelsaft|klar', 'status' => 'approved']);

    Livewire::test(DetailPanel::class, ['gpId' => $gp->id])->set('laSuche', 'apfelsaft')
        ->assertSeeHtml('data-la-treffer')->assertSee('0,2 l')->assertSee('kein Preis')->assertSee('3 weitere Treffer, Suche verfeinern.');
    Livewire::test(GpModal::class)->call('oeffnen')->set('laSuche', 'apfelsaft')
        ->assertSeeHtml('data-gp-la-results')->assertSee('0,2 l')->assertSee('3 weitere Treffer, Suche verfeinern.')
        ->assertSeeHtml('Bezeichnung, Artikelnummer oder Marke');
});

it('Tempo-Wächter (io 27.10.): Kandidaten-Abfrage schmal und ohne is_discontinued-Index-Bedingung, volle Artikel nur für die Treffer', function () {
    foreach (range(1, 3) as $i) {
        ($this->la)($this->fako, "Apfelsaft Sorte {$i}", ['qty' => 0.2]);
    }
    $abfragen = [];
    DB::listen(function ($q) use (&$abfragen) {
        $abfragen[] = $q->sql;
    });
    $treffer = ($this->suche)('apfelsaft')['treffer'];
    $kandidaten = collect($abfragen)->first(fn ($sql) => str_contains($sql, 'LIKE') && str_contains($sql, 'foodalchemist_supplier_item_structures'));

    // EXPLAIN läuft im SQLite-Testlauf nicht: geprüft wird die Formulierung, die MySQL vom Index abhält (lokal belegt: ref → ALL, 0,33 → 0,18 s)
    expect($kandidaten)->not->toBeNull()
        ->and($kandidaten)->toContain('is_discontinued <> 1')
        ->and($kandidaten)->not->toContain('"is_discontinued" = ?')
        ->and($kandidaten)->not->toContain('"foodalchemist_supplier_items".*')
        ->and($treffer->first()->gebinde_text)->toBe('0,2 l')                 // qty/unit_code kommen nur über das Nachladen
        ->and($treffer->first()->unit_code)->toBe('l');
});

it('lieferbar(vollscan) liefert dieselben Zeilen wie lieferbar()', function () {
    ($this->la)($this->fako, 'Apfelsaft frei');
    ($this->la)($this->fako, 'Apfelsaft ausgelistet', ['is_discontinued' => true]);
    ($this->la)($this->fako, 'Apfelsaft eingestellt', ['lieferstatus' => 'eingestellt', 'lieferstatus_quelle' => 'necta']);

    expect(FoodAlchemistSupplierItem::query()->lieferbar(vollscan: true)->pluck('id')->sort()->values()->all())
        ->toBe(FoodAlchemistSupplierItem::query()->lieferbar()->pluck('id')->sort()->values()->all())
        ->and(FoodAlchemistSupplierItem::query()->lieferbar(vollscan: true)->count())->toBe(1);
});
