<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware;
use Platform\FoodAlchemist\Livewire\Settings\Zugriffsrechte;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\InhaltsSperre;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;
use Platform\FoodAlchemist\Tools\FoodbooksListTool;
use Platform\FoodAlchemist\Tools\MandantenGetTool;
use Platform\FoodAlchemist\Tools\PlanNutzungGetTool;
use Platform\FoodAlchemist\Tools\RecipesListTool;
use Platform\FoodAlchemist\Tools\RecipesPostTool;
use Platform\FoodAlchemist\Tools\StammPaketGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 G (Sicherheit): Plattform-Admin ohne echte team_user-Mitgliedschaft hat in einem Kunden-Team KEINE Inhalts-Rolle und sieht
 * keine Inhalte — auch wenn Core den Team-Wechsel ohne Prüfung zulässt (TeamFlyout::switchTeam). Die Plattform-Steuerung bleibt erreichbar.
 * Gemessener Befund vorher: rolle() = Admin, darf(Freigeben) = true, recipes.LIST lieferte das Rezept des autarken Mandanten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');   // Mitglied nur des Seed-Rootteams
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);   // autarker Mandant (Default)
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->soulInhaber = $this->makeUser($this->soul, 'Soul Inhaber', 'owner');
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'member');   // Master-Mitglied, später auch Plattform-Admin
    app()->instance(PlattformProtokoll::class, Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing());
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    $this->rechte = app(FaRechte::class);
    if (! \Illuminate\Support\Facades\Schema::hasTable('team_billable_usages')) {   // Core-Tabelle fehlt in der Test-Allowlist (nur für plan_nutzung.GET)
        \Illuminate\Support\Facades\Schema::create('team_billable_usages', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->string('billable_model');
            $t->string('billable_type');
            $t->string('label');
            $t->date('usage_date');
            $t->unsignedInteger('count')->default(0);
            $t->decimal('cost_per_unit', 8, 4);
            $t->decimal('total_cost', 10, 2);
            $t->json('pricing_snapshot')->nullable();
            $t->timestamps();
        });
    }

    $this->makeRecipe($this->soul, 'Soul Geheimrezept');
    $this->makeFoodbook($this->soul, 'Soul Foodbook');
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->soul->id, 'name' => 'Soul Lieferant']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->soul->id, 'supplier_id' => $sup->id, 'designation' => 'Soul Mehl', 'article_number' => 'S-1', 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
    FoodAlchemistPrice::create(['team_id' => $this->soul->id, 'supplier_item_id' => $la->id, 'price' => 7.77, 'status' => '0']);

    $this->huelle = fn ($tool) => new FaRechteToolHuelle($tool);
    $this->ctx = fn ($user, $team) => new ToolContext($user, $team);
    // current_team_id setzen, wie es TeamFlyout::switchTeam im Core ohne Mitgliedschaftsprüfung tut
    $this->wechsle = function ($user, Team $team) {
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->refresh();
    };
});

// ── FaRechte::rolle() ────────────────────────────────────────────────────────

it('rolle(): Plattform-Admin ist Admin nur im Master-Team und in Teams, in denen er echtes Mitglied ist; im fremden Kunden-Team keine Inhalts-Rolle', function () {
    expect($this->rechte->rolle($this->admin, $this->rootTeam))->toBe(FaRolle::Admin)        // eigenes Team (Mitglied)
        ->and($this->rechte->rolle($this->admin, $this->master))->toBe(FaRolle::Admin)        // Master-Team ist kein Kunden-Team
        ->and($this->rechte->rolle($this->admin, $this->soul))->toBe(FaRolle::Lesen)          // fremder autarker Mandant: gesperrt
        ->and($this->rechte->darf($this->admin, $this->soul, FaRolle::Freigeben))->toBeFalse()
        ->and($this->rechte->darf($this->admin, $this->soul, FaRolle::Kuratieren))->toBeFalse();
    expect(InhaltsSperre::istGesperrt($this->admin, $this->soul))->toBeTrue()->and(InhaltsSperre::istGesperrt($this->admin, $this->rootTeam))->toBeFalse()
        ->and(InhaltsSperre::istGesperrt($this->admin, $this->master))->toBeFalse();

    // echte Mitgliedschaft hebt die Sperre auf: dann hat er als Plattform-Admin im Team wieder Admin-Rechte
    \Illuminate\Support\Facades\DB::table('team_user')->insert(['team_id' => $this->soul->id, 'user_id' => $this->admin->id, 'role' => 'member']);
    expect(InhaltsSperre::istGesperrt($this->admin, $this->soul))->toBeFalse()->and($this->rechte->rolle($this->admin, $this->soul))->toBe(FaRolle::Admin);
});

it('rolle(): normale Benutzer sind unverändert (keine Sperre, keine Abfrage); Unter-Teams eines Mandanten sind genauso gesperrt', function () {
    expect(InhaltsSperre::istGesperrt($this->soulInhaber, $this->soul))->toBeFalse()->and($this->rechte->rolle($this->soulInhaber, $this->soul))->toBe(FaRolle::Admin);
    $kind = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);
    expect(InhaltsSperre::istGesperrt($this->admin, $kind))->toBeTrue()->and($this->rechte->rolle($this->admin, $kind))->toBe(FaRolle::Lesen);
});

// ── MCP (Tool-Hülle) ─────────────────────────────────────────────────────────

it('§13 G Pflicht: Plattform-Admin im Kontext des autarken Mandanten bekommt über MCP 0 Rezepte, 0 Foodbooks, keinen Schreibzugriff', function () {
    $ctx = ($this->ctx)($this->admin, $this->soul);

    $rezepte = ($this->huelle)(new RecipesListTool)->execute([], $ctx);
    $foodbooks = ($this->huelle)(new FoodbooksListTool)->execute([], $ctx);
    $schreiben = ($this->huelle)(new RecipesPostTool)->execute(['name' => 'Eingeschleust'], $ctx);

    foreach ([$rezepte, $foodbooks, $schreiben] as $r) {
        expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN')->and($r->error)->toContain('§13 G');
    }
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::where('name', 'Eingeschleust')->count())->toBe(0);

    // dasselbe Kontext-Bild im eigenen Team: läuft normal (und zeigt nichts vom Mandanten)
    $eigene = ($this->huelle)(new RecipesListTool)->execute([], ($this->ctx)($this->admin, $this->rootTeam));
    expect($eigene->success)->toBeTrue()->and(json_encode($eigene->data))->not->toContain('Soul Geheimrezept');
});

it('Plattform-Steuerung bleibt für den Admin erreichbar, auch im Kontext eines gesperrten Kunden-Teams (Verwaltungsdaten, keine Inhalte)', function () {
    $ctx = ($this->ctx)($this->admin, $this->soul);

    $m = ($this->huelle)(new MandantenGetTool)->execute(['stammblatt' => false], $ctx);
    $sp = ($this->huelle)(new StammPaketGetTool)->execute([], $ctx);
    $pn = ($this->huelle)(new PlanNutzungGetTool)->execute(['team_id' => $this->soul->id], $ctx);

    expect($m->success)->toBeTrue(json_encode([$m->errorCode, $m->error ?? null]))->and($sp->success)->toBeTrue()->and($pn->success)->toBeTrue(json_encode([$pn->errorCode, $pn->error ?? null]));
    expect(json_encode([$m->data, $sp->data, $pn->data]))->not->toContain('Geheimrezept')->not->toContain('7.77');
});

// ── Dienste der Oberfläche, Seiten, Livewire ─────────────────────────────────

it('Oberfläche: der Kontext des Admins im eigenen Team zeigt nichts vom Mandanten (0 Rezepte, 0 Foodbooks, 0 Preise)', function () {
    expect(app(RecipeService::class)->paginateBrowser(['search' => ''], $this->rootTeam, 100)->total())->toBe(0)
        ->and(app(FoodbookService::class)->paginateBrowser([], $this->rootTeam, 100)->total())->toBe(0)
        ->and(app(PriceService::class)->preisSichtFuer(FoodAlchemistPrice::query(), $this->rootTeam)->where('foodalchemist_prices.team_id', $this->soul->id)->count())->toBe(0);
});

it('Seiten: Middleware verweigert dem Admin im fremden Kunden-Team 403; Ausnahmen (Präsentation) und das eigene Team laufen; normale Benutzer unberührt', function () {
    $mw = new FaBereichMiddleware;
    $anfrage = function (string $routeName, $user) {
        $route = (new LaravelRoute(['GET'], '/x', fn () => 'ok'))->name($routeName);
        $r = Request::create('/x');
        $r->setRouteResolver(fn () => $route);
        $r->setUserResolver(fn () => $user);

        return $r;
    };
    $durch = fn (string $name, $user) => $mw->handle($anfrage($name, $user), fn () => response('ok'))->getContent() === 'ok';

    $imMandant = ($this->wechsle)($this->admin, $this->soul);
    expect(fn () => $mw->handle($anfrage('foodalchemist.recipes.index', $imMandant), fn () => response('ok')))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, '§13 G');
    expect($durch('foodalchemist.presentation.show', $imMandant))->toBeTrue();

    $imEigenen = ($this->wechsle)($this->admin, $this->rootTeam);
    expect($durch('foodalchemist.recipes.index', $imEigenen))->toBeTrue();
    expect($durch('foodalchemist.recipes.index', $this->soulInhaber))->toBeTrue();
});

it('Livewire: Aktionen einer FA-Komponente laufen für den Admin im fremden Kunden-Team nicht; Verwaltungs-Komponenten sind ausgenommen', function () {
    expect(InhaltsSperre::komponenteErlaubt(\Platform\FoodAlchemist\Livewire\Verwaltung\PlanPreise::class))->toBeTrue()
        ->and(InhaltsSperre::komponenteErlaubt(Zugriffsrechte::class))->toBeFalse()
        ->and(InhaltsSperre::komponenteErlaubt(\stdClass::class))->toBeTrue();

    $this->actingAs(($this->wechsle)($this->admin, $this->soul));
    Livewire::test(Zugriffsrechte::class)->call('bereicheUmschalten', $this->soulInhaber->id)->assertSet('bereicheFuer', null);   // blockiert vom Haken

    $this->actingAs(($this->wechsle)($this->admin, $this->rootTeam));
    $inhaber = $this->makeUser($this->rootTeam, 'Koch Root', 'member');
    Livewire::test(Zugriffsrechte::class)->call('bereicheUmschalten', $inhaber->id)->assertSet('bereicheFuer', $inhaber->id);          // eigenes Team: läuft
});

// ── Master bei kuratiert: lesend ─────────────────────────────────────────────

it('Master-Mitglied (zugleich Plattform-Admin) liest einen KURATIERTEN Mandanten, schreibt aber nicht; im autarken Mandanten gesperrt', function () {
    config(['platform-shell.admins' => [strtolower($this->admin->email), strtolower($this->kurator->email)]]);
    $ctx = ($this->ctx)($this->kurator, $this->soul);

    // autark: gesperrt
    expect(InhaltsSperre::istGesperrt($this->kurator, $this->soul))->toBeTrue()
        ->and(($this->huelle)(new RecipesListTool)->execute([], $ctx)->errorCode)->toBe('FORBIDDEN');

    // kuratiert: Lesen ja, Schreiben nein
    app(MandantenService::class)->setze($this->soul, ['modus' => 'kuratiert'], $this->admin);
    $lesen = ($this->huelle)(new RecipesListTool)->execute([], $ctx);
    $schreiben = ($this->huelle)(new RecipesPostTool)->execute(['name' => 'Kurator schreibt'], $ctx);
    expect(InhaltsSperre::istGesperrt($this->kurator, $this->soul))->toBeFalse()->and($lesen->success)->toBeTrue()->and(json_encode($lesen->data))->toContain('Soul Geheimrezept')
        ->and($this->rechte->rolle($this->kurator, $this->soul))->toBe(FaRolle::Lesen)
        ->and($schreiben->success)->toBeFalse()->and($schreiben->errorCode)->toBe('FORBIDDEN');

    // der Plattform-Admin OHNE Master-Mitgliedschaft bleibt auch im kuratierten Mandanten gesperrt
    expect(InhaltsSperre::istGesperrt($this->admin, $this->soul))->toBeTrue();
});
