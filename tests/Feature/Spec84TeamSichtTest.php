<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\TeamKontextFehltException;
use Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 Stufe 1 · automatischer Sichtfilter (Global Scope TeamSicht) auf den Abo-Typen + B0b fail-closed.
 *
 * Aufbau: Master (vergibt Abo) · Mandant A (abonniert ein Master-Gericht, sieht nur die Fassung) · Mandant B (kein Abo)
 * · Unter-Team U von A, eingeschränkt (77d), Hülle enthält die Abo-Fassung.
 *
 * Maßstab für „richtig“ ist die heutige Regel: Schalter aus + ausdrückliches visibleToTeam(). Der Schalter darf an keiner
 * Stelle andere Zeilen liefern — weder automatisch noch über die neue Abo-Menge (UNION ALL statt OR, Spec 84 §5a E-3).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.team_sicht.stufe' => 0]);
    \Platform\FoodAlchemist\Models\Scopes\TeamSicht::zuruecksetzen();   // „einmal je Prozess geloggt“ darf nicht aus einem früheren Test stammen

    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    $this->u = Team::create(['name' => 'Standort U', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->a);
    $this->kurator = $this->makeUser($this->master, 'Kurator');
    $this->chefA = $this->makeUser($this->a, 'Chef A');

    foreach (['master' => $this->master, 'a' => $this->a, 'b' => $this->b, 'u' => $this->u] as $k => $t) {
        $this->makeRecipe($t, "R {$k}");
        app(ConceptService::class)->create($t, ['name' => "K {$k}"]);
        app(PaketService::class)->create($t, ['name' => "P {$k}"]);
        app(FormatService::class)->create($t, ['name' => "F {$k}"]);
    }

    $abo = $this->makeRecipe($this->master, 'Abo-Gericht', ['is_sales_recipe' => true]);
    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Sammlung', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'recipe', [$abo->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->a, $sid, $this->kurator);
    $this->aboArbeit = $abo->id;
    $this->aboFassung = app(FassungService::class)->veroeffentlichen($this->master, $abo->id, 'Erst', $this->kurator)['fassung_id'];

    // Eigene Fassungs-Zeile in A (A hat zugleich ein Abo): gehört in keine normale Sicht — prüft die Fassungs-Regel INNERHALB der Abo-Menge
    $this->fassungA = $this->makeRecipe($this->a, 'Fassung A')->id;
    DB::table('foodalchemist_recipes')->where('id', $this->fassungA)->update(['ist_fassung' => true]);

    // 77d: U übernimmt nicht alles; Hülle = die Abo-Fassung (eingeschränkter Abo-Zweig)
    $inhalte->setzeErbtAlles($this->a, $this->u->id, false, $this->chefA);
    DB::table('foodalchemist_freigabe_objekte')->insert(['team_id' => $this->u->id, 'typ' => 'recipe', 'objekt_id' => $this->aboFassung]);
    TeamAncestryRegistry::flushAll();

    $this->modelle = [FoodAlchemistRecipe::class, FoodAlchemistConcept::class, FoodAlchemistPaket::class, FoodAlchemistFormat::class];
    $this->ids = fn ($q) => $q->pluck((new ($q->getModel()::class))->getTable() . '.id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    $this->teams = fn () => ['master' => $this->master, 'a' => $this->a, 'b' => $this->b, 'u' => $this->u];
});

it('Schalter aus: kein automatischer Filter, Abfrage ohne Sicht liefert alles wie bisher', function () {
    $alle = FoodAlchemistRecipe::count();
    FaTeam::imTeam($this->b, function () use ($alle) {
        expect(FoodAlchemistRecipe::count())->toBe($alle);
        expect(FoodAlchemistRecipe::query()->toSql())->not->toContain('foodalchemist_abo_objekte');
    });
});

it('Gleichheit: Schalter an (Abo als Menge) liefert je Team und Modell dieselben Zeilen wie die heutige Regel', function () {
    $vorher = [];
    foreach (($this->teams)() as $k => $t) {
        foreach ($this->modelle as $m) {
            $vorher[$k][$m] = ($this->ids)($m::visibleToTeam($t));
        }
    }
    // Plausibilität des Aufbaus: A sieht die Fassung, nicht die Arbeitszeile; U sieht die Fassung über seine Hülle; B nichts davon
    expect($vorher['a'][FoodAlchemistRecipe::class])->toContain($this->aboFassung)->not->toContain($this->aboArbeit);
    expect($vorher['u'][FoodAlchemistRecipe::class])->toContain($this->aboFassung);
    expect($vorher['b'][FoodAlchemistRecipe::class])->not->toContain($this->aboFassung)->not->toContain($this->aboArbeit);
    expect($vorher['a'][FoodAlchemistRecipe::class])->not->toContain($this->fassungA);

    config(['foodalchemist.team_sicht.stufe' => 1]);
    foreach (($this->teams)() as $k => $t) {
        foreach ($this->modelle as $m) {
            expect(($this->ids)($m::visibleToTeam($t)))->toBe($vorher[$k][$m], "ausdrücklich {$k} {$m}");
            $auto = FaTeam::imTeam($t, fn () => ($this->ids)($m::query()));
            expect($auto)->toBe($vorher[$k][$m], "automatisch {$k} {$m}");
        }
    }
    // Die neue Form ist wirklich im Spiel (sonst prüfte der Test zweimal das OR)
    expect(FaTeam::imTeam($this->a, fn () => FoodAlchemistRecipe::query()->toSql()))->toContain('ts_menge');
});

it('Gleichheit auch für rohe Abfragen mit Alias (TeamScope::applyVisible)', function () {
    $roh = fn (Team $t) => TeamScope::applyVisible(DB::table('foodalchemist_recipes as r'), 'r.team_id', $t)
        ->whereNull('r.deleted_at')->pluck('r.id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    $vorher = array_map($roh, ($this->teams)());
    config(['foodalchemist.team_sicht.stufe' => 1]);
    expect(array_map($roh, ($this->teams)()))->toBe($vorher);
    // Alias-Abfrage blendet Fassungen der eigenen Kette aus (vorher sah der Master per Alias seine Fassungen)
    expect($vorher['master'])->not->toContain($this->aboFassung);
});

it('Ausdrückliches visibleToTeam ersetzt den Scope statt ihn zu verdoppeln', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    $sql = FaTeam::imTeam($this->a, fn () => FoodAlchemistRecipe::visibleToTeam($this->a)->toSql());
    expect(substr_count($sql, 'foodalchemist_abo_objekte'))->toBe(1);
});

it('mitFassungen() unter dem Scope: der Master sieht seine Fassungen, Mandant B weiter keine', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    expect(FaTeam::imTeam($this->master, fn () => FoodAlchemistRecipe::mitFassungen()->pluck('id')->all()))->toContain($this->aboFassung);
    expect(FaTeam::imTeam($this->b, fn () => FoodAlchemistRecipe::mitFassungen()->pluck('id')->all()))->not->toContain($this->aboFassung);
    expect(FaTeam::imTeam($this->a, fn () => FoodAlchemistRecipe::query()->pluck('id')->all()))->toContain($this->aboFassung);
});

it('ohneTeamSicht(grund) ist die Umgehung; Verweis-Relationen lösen auch fremde Zeilen auf', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    $alle = FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('test')->count();
    expect(FaTeam::imTeam($this->b, fn () => FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('test')->count()))->toBe($alle);
    $sql = FoodAlchemistRecipeIngredient::query()->getModel()->recipe()->toBase()->toSql();
    expect($sql)->not->toContain('foodalchemist_abo_objekte');
});

it('Konsole ohne Team-Kontext: offen und geloggt (Abfrage endet, keine Rekursion)', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    Log::spy();
    expect(FaTeam::imTeam(null, fn () => FoodAlchemistRecipe::count()))->toBe(FoodAlchemistRecipe::count());
    Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains($m, 'ohne Team-Kontext, offen'))->atLeast()->once();
});

// ── B0b · fail-closed an den echten Eingängen (Middleware bzw. Tool-Hülle), nicht im offenen Konsolen-Weg ──

it('B0b Seite: angemeldeter Nutzer ohne Team-Kontext → TeamKontextFehltException; Gegenprobe mit Team liefert Zeilen', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    Route::middleware(['web', FaBereichMiddleware::class])->get('/_spec84_b0b', fn () => (string) FoodAlchemistRecipe::count());
    $ohne = $this->makeUser($this->b, 'Ohne Team');
    $ohne->forceFill(['current_team_id' => null])->save();

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($ohne)->get('/_spec84_b0b'))->toThrow(TeamKontextFehltException::class);

    $mit = $this->makeUser($this->b, 'Mit Team');
    $antwort = $this->actingAs($mit)->get('/_spec84_b0b');
    expect((int) $antwort->getContent())->toBe(FoodAlchemistRecipe::visibleToTeam($this->b)->count())->toBeGreaterThan(0);
});

it('B0b Seite: Notschalter leer liefert 0 statt Ausnahme', function () {
    config(['foodalchemist.team_sicht.stufe' => 1, 'foodalchemist.team_sicht.ohne_kontext' => 'leer']);
    Route::middleware(['web', FaBereichMiddleware::class])->get('/_spec84_b0b_leer', fn () => (string) FoodAlchemistRecipe::count());
    $ohne = $this->makeUser($this->b, 'Ohne Team 2');
    $ohne->forceFill(['current_team_id' => null])->save();
    $this->withoutExceptionHandling();
    expect($this->actingAs($ohne)->get('/_spec84_b0b_leer')->getContent())->toBe('0');
});

it('B0b MCP: Tool-Hülle mit Nutzer, aber ohne Team → TeamKontextFehltException; mit Team nur die Sicht', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    $innen = new class implements ToolContract
    {
        public function getName(): string { return 'foodalchemist.spec84_b0b.GET'; }

        public function getDescription(): string { return 'Test'; }

        public function getSchema(): array { return ['type' => 'object', 'properties' => []]; }

        public function execute(array $arguments, ToolContext $context): ToolResult
        {
            return ToolResult::success(['ids' => FoodAlchemistRecipe::query()->pluck('id')->all()]);
        }
    };
    $huelle = new FaRechteToolHuelle($innen);

    $ohne = $this->makeUser($this->b, 'MCP ohne');
    $ohne->forceFill(['current_team_id' => null])->save();
    expect(fn () => $huelle->execute([], new ToolContext($ohne->fresh(), null)))->toThrow(TeamKontextFehltException::class);

    $mitB = $this->makeUser($this->b, 'MCP B');
    $ids = $huelle->execute([], new ToolContext($mitB, $this->b))->data['ids'];
    expect($ids)->not->toContain($this->aboFassung)->not->toContain($this->aboArbeit)->not->toBeEmpty();
});

// ── Stufe 0 (Standard, so geht der Code live): kein Verhaltensunterschied außer dem Alias-Fix ──

it('Stufe 0 (a): TeamSicht hängt nichts an — SQL und Bindungen mit Scope = ohne Scope, für alle vier Modelle, auch mit Kontext', function () {
    expect(config('foodalchemist.team_sicht.stufe'))->toBe(0);
    foreach ($this->modelle as $m) {
        foreach ([$this->a, $this->b, null] as $t) {
            // Scopes werden erst beim Bauen des SQL angewandt — darum toSql() INNERHALB des Team-Rahmens
            [$mit, $ohne] = FaTeam::imTeam($t, fn () => array_map(fn ($q) => [$q->toSql(), $q->getBindings()],
                [$m::query(), $m::query()->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\TeamSicht::class)]));
            expect($mit)->toBe($ohne, $m);
            expect($mit[0])->toContain('ist_fassung');   // OhneFassungenScope greift wie vorher
        }
    }
});

it('Stufe 0 (b): an Seite und MCP-Tool mit Nutzer ohne Team-Kontext wird NIE geworfen', function () {
    expect(config('foodalchemist.team_sicht.stufe'))->toBe(0);
    Route::middleware(['web', FaBereichMiddleware::class])->get('/_spec84_stufe0', fn () => (string) FoodAlchemistRecipe::count());
    $ohne = $this->makeUser($this->b, 'Stufe0 ohne');
    $ohne->forceFill(['current_team_id' => null])->save();
    $this->withoutExceptionHandling();
    expect((int) $this->actingAs($ohne)->get('/_spec84_stufe0')->getContent())->toBe(FoodAlchemistRecipe::count());

    $innen = new class implements ToolContract
    {
        public function getName(): string { return 'foodalchemist.spec84_stufe0.GET'; }

        public function getDescription(): string { return 'Test'; }

        public function getSchema(): array { return ['type' => 'object', 'properties' => []]; }

        public function execute(array $arguments, ToolContext $context): ToolResult
        {
            return ToolResult::success(['n' => FoodAlchemistRecipe::count()]);
        }
    };
    expect((new FaRechteToolHuelle($innen))->execute([], new ToolContext($ohne->fresh(), null))->data['n'])->toBe(FoodAlchemistRecipe::count());
});

it('Stufe 0 (c): alle Verweis-Relationen mit ohneTeamSicht(verweis) erzeugen dasselbe SQL wie mit TeamSicht-Scope (Stufe 1 + 3)', function () {
    expect(config('foodalchemist.team_sicht.stufe'))->toBe(0);
    $geprueft = 0;
    foreach (glob(__DIR__ . '/../../src/Models/*.php') as $datei) {
        $code = file_get_contents($datei);
        if (! str_contains($code, "ohneTeamSicht('verweis')")) {
            continue;
        }
        $klasse = 'Platform\\FoodAlchemist\\Models\\' . basename($datei, '.php');
        // Methodenname je Fundstelle: die letzte `function x(` vor dem Treffer
        preg_match_all("/ohneTeamSicht\\('verweis'\\)/", $code, $treffer, PREG_OFFSET_CAPTURE);
        foreach ($treffer[0] as [, $pos]) {
            preg_match_all('/function\s+(\w+)\s*\(/', substr($code, 0, $pos), $fn);
            $methode = end($fn[1]);
            $rel = FaTeam::imTeam($this->a, fn () => (new $klasse)->{$methode}());
            $mitScope = (clone $rel->getQuery())->withGlobalScope(\Platform\FoodAlchemist\Models\Scopes\TeamSicht::class, new \Platform\FoodAlchemist\Models\Scopes\TeamSicht);
            $a = FaTeam::imTeam($this->a, fn () => [$rel->toBase()->toSql(), $rel->toBase()->getBindings()]);
            $b = FaTeam::imTeam($this->a, fn () => [$mitScope->toBase()->toSql(), $mitScope->toBase()->getBindings()]);
            expect($a)->toBe($b, "{$klasse}::{$methode}");
            $geprueft++;
        }
    }
    expect($geprueft)->toBe(134);   // Lieferanten-Link 2d: +1 FoodAlchemistSupplierDeliveryArea::supplier. Spec 85 D-1 PR-C: −1 (FoodAlchemistRecipe::markupClass gedroppt, Klasse nur an der Darreichung); 50 Verweise der Stufe 1 + 43 der Stufe 3 (GP/LA/Lieferant) + 40 der Stufe 4 (Vokabular/Einstellungen; HatAusgabeZuordnung liegt in Concerns/) + 1 TeamFavorit::gp (Spec 85 FAV-1)
});
