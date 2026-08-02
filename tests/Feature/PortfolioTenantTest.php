<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Portfolio;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PortfolioGetTool;
use Platform\FoodAlchemist\Tools\PortfolioPromotionGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 — adversariale Mandantentrennung für die neuen Schreib- und Lesewege.
 *
 * Der Aktivschalter ist eine öffentliche Schreibaktion, und die Zuordnungsachsen aus P2 sind
 * eine neue Art von Fremdreferenz: `outlet_id` ist keine ID auf dem eigenen Datensatz, sondern
 * ein Zeiger auf ein Vokabular, das einem Team gehört. Genau die Sorte Feld, die durch das
 * Raster fällt, mit dem man Tenant-Sicherheit üblicherweise prüft — die FELDER-Liste eines
 * Service prüft *ob* ein Feld gesetzt werden darf, nicht *worauf* es zeigt.
 *
 * Aufbau: rootTeam → childA / childB (Geschwister), Nutzer sitzt in childA.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    $this->eigenerBetrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Kantine A']);
    $this->fremderBetrieb = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Kantine B']);
});

// ── Fremde Zuordnung untergeschoben ───────────────────────────────────────────

it('hängt keine der drei Ausgabeformen an einen fremden Betrieb', function () {
    // Die Outlet-Auswahl kommt aus einem Dropdown und ist damit manipulierbar. Ginge das
    // durch, stünde die eigene Karte in der Konzern-Sicht eines fremden Betriebs.
    $karte = app(SpeisekarteService::class)->create($this->childA, ['name' => 'Karte']);
    $plan = app(SpeiseplanService::class)->create($this->childA, ['name' => 'Plan']);
    $buch = app(FoodbookService::class)->create($this->childA, ['label' => 'Buch']);

    app(SpeisekarteService::class)->update($this->childA, (int) $karte->id, ['outlet_id' => $this->fremderBetrieb->id]);
    app(SpeiseplanService::class)->update($this->childA, (int) $plan->id, ['outlet_id' => $this->fremderBetrieb->id]);
    app(FoodbookService::class)->update($this->childA, (int) $buch->id, ['outlet_id' => $this->fremderBetrieb->id]);

    expect($karte->refresh()->outlet_id)->toBeNull()
        ->and($plan->refresh()->outlet_id)->toBeNull()
        ->and($buch->refresh()->outlet_id)->toBeNull();
});

it('nimmt den eigenen Betrieb sehr wohl an', function () {
    // Gegenprobe: der Guard darf die Achse nicht insgesamt lahmlegen.
    $karte = app(SpeisekarteService::class)->create($this->childA, ['name' => 'Karte']);
    app(SpeisekarteService::class)->update($this->childA, (int) $karte->id, ['outlet_id' => $this->eigenerBetrieb->id]);

    expect((int) $karte->refresh()->outlet_id)->toBe((int) $this->eigenerBetrieb->id);
});

it('nimmt einen fremden Betrieb auch beim Anlegen nicht an', function () {
    // FELDER wirkt nur auf update() — beim create() ist die Feldliste eine andere. Genau diese
    // Asymmetrie hat in P2 und P3 schon zweimal Felder verschluckt.
    $karte = app(SpeisekarteService::class)->create($this->childA, [
        'name' => 'Neu', 'outlet_id' => $this->fremderBetrieb->id,
    ]);

    expect($karte->refresh()->outlet_id)->toBeNull();
});

// ── Aktivschalter ─────────────────────────────────────────────────────────────

it('schaltet keine fremde Ausgabe aktiv', function () {
    $fremdeKarte = app(SpeisekarteService::class)->create($this->childB, [
        'name' => 'Fremde Karte', 'status' => 'entwurf',
    ]);

    // Die id kommt aus dem Browser. Ein untergeschobener Wert darf nicht dazu führen, dass
    // beim Geschwister-Team eine Karte scharf gestellt wird.
    Livewire::test(\Platform\FoodAlchemist\Livewire\Speisekarte\Index::class)
        ->set('karteId', $fremdeKarte->id)
        ->call('aktivUmschalten');

    expect($fremdeKarte->refresh()->statusWert())->toBe(AusgabeStatus::Entwurf);
});

it('schaltet die eigene Ausgabe an und wieder aus', function () {
    $karte = app(SpeisekarteService::class)->create($this->childA, ['name' => 'Karte', 'status' => 'entwurf']);

    $lw = Livewire::test(\Platform\FoodAlchemist\Livewire\Speisekarte\Index::class)->call('waehle', $karte->id);

    $lw->call('aktivUmschalten');
    expect($karte->refresh()->statusWert())->toBe(AusgabeStatus::Aktiv);

    $lw->call('aktivUmschalten');
    expect($karte->refresh()->statusWert())->toBe(AusgabeStatus::Inaktiv);
});

// ── Controlling-Fläche ────────────────────────────────────────────────────────

it('zeigt im Portfolio-Tab weder fremde Ausgaben noch fremde Betriebe', function () {
    app(SpeisekarteService::class)->create($this->childB, [
        'name' => 'Fremde Karte', 'status' => 'aktiv', 'outlet_id' => $this->fremderBetrieb->id,
    ]);

    Livewire::test(Portfolio::class)
        ->assertDontSee('Fremde Karte')
        // Auch die Zeilenachse selbst ist Mandantendaten: „Kantine B" verrät, dass es sie gibt.
        ->assertDontSee('Kantine B')
        ->assertSee('Kantine A');
});

// ── MCP ───────────────────────────────────────────────────────────────────────

it('liefert über portfolio.GET keine fremden Ausgaben', function () {
    app(SpeisekarteService::class)->create($this->childB, ['name' => 'Fremde Karte', 'status' => 'aktiv']);
    app(SpeisekarteService::class)->create($this->childA, ['name' => 'Eigene Karte', 'status' => 'aktiv']);

    $r = app(PortfolioGetTool::class)->execute([], new ToolContext($this->user, $this->childA));

    expect($r->success)->toBeTrue()
        ->and(collect($r->data['ausgaben'])->pluck('name'))->toContain('Eigene Karte')
        ->and(collect($r->data['ausgaben'])->pluck('name'))->not->toContain('Fremde Karte');
});

it('filtert über portfolio.GET nicht auf einen fremden Betrieb', function () {
    // Ein Filter auf eine fremde outlet_id darf nicht als Sonde taugen: die Antwort muss leer
    // sein, nicht etwa die Ausgaben des Nachbarn zeigen.
    app(SpeisekarteService::class)->create($this->childB, [
        'name' => 'Fremde Karte', 'status' => 'aktiv', 'outlet_id' => $this->fremderBetrieb->id,
    ]);

    $r = app(PortfolioGetTool::class)->execute(
        ['outlet_id' => $this->fremderBetrieb->id], new ToolContext($this->user, $this->childA),
    );

    expect($r->success)->toBeTrue()->and($r->data['ausgaben'])->toBe([]);
});

it('rechnet über portfolio_promotion.GET nur auf eigenen Umsätzen', function () {
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'eigen', 'name' => 'Eigen',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1,
    ]);
    foreach ([[$this->childA, 100.0], [$this->childB, 9999.0]] as [$team, $betrag]) {
        FoodAlchemistSalesFact::create([
            'team_id' => $team->id, 'recipe_id' => $gericht->id, 'raw_label' => 'Eigen',
            'qty_sold' => 1, 'revenue_net' => $betrag, 'sold_at' => now()->subDay()->toDateString(),
            'source' => 'csv_import', 'source_hash' => 'h' . $team->id,
        ]);
    }

    $r = app(PortfolioPromotionGetTool::class)->execute([], new ToolContext($this->user, $this->childA));

    expect($r->success)->toBeTrue()->and($r->data['umsatz_gesamt'])->toBe(100.0);
});

it('sagt im Ergebnis ausdrücklich, dass die Zeilen nicht summierbar sind', function () {
    // Kein Tenant-Thema, aber dieselbe Sorte Fehler: eine Zahl, die stiller falsch gelesen wird,
    // als sie gemeint ist. Der Konsument muss es aus der Antwort erfahren, nicht aus der Doku.
    $r = app(PortfolioPromotionGetTool::class)->execute([], new ToolContext($this->user, $this->childA));

    expect($r->data['summierbar'])->toBeFalse()
        ->and($r->data['summen_hinweis'])->toContain('umsatz_exklusiv');
});

it('verlangt für beide neuen Tools ein Team', function () {
    // Ein NULL-Team im Kontext ist NICHT „kein Team" — alle FA-Tools fallen bewusst auf
    // `currentTeamRelation` zurück. Der echte teamlose Fall ist ein User ohne aktuelles Team.
    $heimatlos = \Platform\Core\Models\User::forceCreate([
        'name' => 'Ohne Team', 'email' => 'ohne.team.pf@test.local',
        'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $ohneTeam = new ToolContext($heimatlos, null);

    foreach ([PortfolioGetTool::class, PortfolioPromotionGetTool::class] as $tool) {
        expect(app($tool)->execute([], $ohneTeam)->success)->toBeFalse();
    }
});
