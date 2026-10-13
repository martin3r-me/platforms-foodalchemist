<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Settings\Index as SettingsIndex;
use Platform\FoodAlchemist\Livewire\Settings\Mandanten as MandantenKomponente;
use Platform\FoodAlchemist\Livewire\Settings\PlanNutzung as PlanNutzungKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\GebuehrenZaehler;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MandantenStammblattService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlanPreisService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FoodbooksListTool;
use Platform\FoodAlchemist\Tools\MandantenGetTool;
use Platform\FoodAlchemist\Tools\RecipesListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 C1 (Mandanten-Stammblatt, „Plan und Nutzung“ in den Einstellungen) und §13 G (Betreiber und Kurator getrennt):
 * Der Plattform-Admin sieht je Mandant nur Verwaltungsdaten, bei einem autarken Mandanten nie Inhalte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    \Carbon\Carbon::setTestNow('2026-10-14 09:00:00');

    if (! \Illuminate\Support\Facades\Schema::hasTable('team_billable_usages')) {
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
            $t->unique(['team_id', 'billable_model', 'usage_date']);
        });
    }

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');   // Mitglied des Seed-Rootteams, NICHT von Soulkitchen
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->soulInhaber = $this->makeUser($this->soul, 'Soul Inhaber', 'owner');
    $this->soulMember = $this->makeUser($this->soul, 'Soul Mitglied', 'member');
    $this->masterAdmin = $this->makeUser($this->master, 'Master Admin', 'admin');
    $this->masterMember = $this->makeUser($this->master, 'Master Mitglied', 'member');
    $protokoll = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $protokoll);
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    app(MandantenService::class)->setze($this->rootTeam, ['status' => 'beendet'], $this->admin);
    app(PlanPreisService::class)->startwerteAnlegen($this->admin);
    \Platform\FoodAlchemist\Models\FoodAlchemistPlanPreis::query()->update(['gueltig_ab' => '2026-01-01']);
    $this->blatt = fn () => app(MandantenStammblattService::class);
});

afterEach(fn () => \Carbon\Carbon::setTestNow());

// ── Stammblatt (C1) ──────────────────────────────────────────────────────────

it('Stammblatt: Verwaltungsspalten je Mandant, nie Inhalte; Bereiche, Paket, Unter-Teams, Kontingente, Gebühren', function () {
    Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);
    \Platform\FoodAlchemist\Support\StammPaket::freischalten($this->soul, $this->admin->id);
    app(\Platform\FoodAlchemist\Services\FaRechte::class)->setzeKontingente($this->soul, $this->admin, ['max_user' => 5]);
    app(GebuehrenZaehler::class)->zaehle($this->soul, '2026-10-01');

    $b = ($this->blatt)()->stammblatt($this->soul, $this->admin);

    expect($b['name'])->toBe('Soulkitchen')->and($b['modus'])->toBe('autark')->and($b['status'])->toBe('aktiv')
        ->and($b['stamm_paket']['gebucht'])->toBeTrue()->and($b['unter_teams'])->toBe(['LAGO'])->and($b['abos'])->toBe(0)
        ->and($b['kontingente']['max_user'])->toBe(5)->and($b['kontingente']['user'])->toBe(2)->and($b['gebuehren']['summe_gebucht'])->toBeGreaterThan(0)
        ->and($b['inhalte_sichtbar'])->toBeFalse()->and($b['bereiche_gebucht'])->toBe($b['bereiche_gesamt']);
    $schluessel = implode(' ', array_keys($b));
    foreach (['recipe', 'rezept', 'preis', 'foodbook', 'angebot', 'dna', 'profil'] as $verboten) {
        expect($schluessel)->not->toContain($verboten);   // kein Inhalts-Schluessel im Stammblatt
    }
    expect(collect(($this->blatt)()->alle($this->admin))->pluck('name')->all())->toContain('Soulkitchen')->not->toContain('BHG.DIGITAL');
});

it('Stammblatt und Mandanten-Liste: nur Plattform-Admin und Admin im Master-Team; Master-Mitglied, Inhaber und Mitglieder des Mandanten nicht', function () {
    foreach ([$this->admin, $this->masterAdmin] as $user) {
        expect(($this->blatt)()->stammblatt($this->soul, $user)['team_id'])->toBe($this->soul->id);
    }
    foreach ([$this->masterMember, $this->soulInhaber, $this->soulMember, null] as $user) {
        expect(fn () => ($this->blatt)()->stammblatt($this->soul, $user))->toThrow(FaRechtFehltException::class)
            ->and(fn () => ($this->blatt)()->alle($user))->toThrow(FaRechtFehltException::class);
    }
});

it('MCP mandanten.GET mit stammblatt=true liefert das Stammblatt; Inhaber FORBIDDEN', function () {
    $ctx = fn ($user) => new ToolContext($user, $this->rootTeam);

    $r = (new MandantenGetTool)->execute(['team_id' => $this->soul->id, 'stammblatt' => true], $ctx($this->masterAdmin));
    expect($r->success)->toBeTrue(json_encode([$r->errorCode, $r->error ?? null]))->and($r->data['mandant']['gebuehren']['monat'])->toBe('2026-10')->and($r->data['mandant']['inhalte_sichtbar'])->toBeFalse();
    expect((new MandantenGetTool)->execute(['stammblatt' => true], $ctx($this->admin))->data['mandanten'])->not->toBeEmpty();
    expect((new MandantenGetTool)->execute(['stammblatt' => true], $ctx($this->soulInhaber))->errorCode)->toBe('FORBIDDEN');
});

// ── Einstellungen ────────────────────────────────────────────────────────────

it('Einstellungen: „Plan und Nutzung“ sehen Inhaber/Admin des Mandanten, Plattform-Admin und Master-Admin; Mitglied und das Master-Team selbst nicht', function () {
    $this->actingAs($this->soulInhaber);
    expect(SettingsIndex::rechteErlaubt('plan-nutzung'))->toBeTrue()->and(SettingsIndex::rechteErlaubt('mandanten'))->toBeFalse();

    $this->actingAs($this->soulMember);
    expect(SettingsIndex::rechteErlaubt('plan-nutzung'))->toBeFalse();

    $this->admin->forceFill(['current_team_id' => $this->soul->id])->save();
    $this->actingAs($this->admin->refresh());
    expect(SettingsIndex::rechteErlaubt('plan-nutzung'))->toBeTrue()->and(SettingsIndex::rechteErlaubt('mandanten'))->toBeTrue();

    $this->admin->forceFill(['current_team_id' => $this->master->id])->save();
    $this->actingAs($this->admin->refresh());
    expect(SettingsIndex::rechteErlaubt('plan-nutzung'))->toBeFalse()->and(SettingsIndex::rechteErlaubt('mandanten'))->toBeTrue();   // Master hat keine Gebühren

    $this->actingAs($this->masterAdmin);
    expect(SettingsIndex::rechteErlaubt('mandanten'))->toBeTrue();
    $this->actingAs($this->masterMember);
    expect(SettingsIndex::rechteErlaubt('mandanten'))->toBeFalse();
});

it('Komponente Plan und Nutzung: Inhaber sieht Positionen und Summen und blättert Monate; Mitglied bekommt 404', function () {
    app(GebuehrenZaehler::class)->zaehle($this->soul, '2026-10-01');
    $this->actingAs($this->soulInhaber);

    Livewire::test(PlanNutzungKomponente::class)->assertSeeHtml('data-plan-nutzung-zeile="basis"')->assertSeeHtml('overflow-x-auto')->assertSee('Soulkitchen')
        ->assertSee('Oktober 2026')->call('vorher')->assertSee('September 2026')->assertSet('monat', '2026-09')
        ->call('nachher')->call('nachher')->assertSet('monat', '2026-10')->assertSee('Oktober 2026');   // nicht über den laufenden Monat hinaus

    $this->actingAs($this->soulMember);
    Livewire::test(PlanNutzungKomponente::class)->assertNotFound();
});

it('Komponente Mandanten: Liste, Stammblatt aufklappen; Inhaber des Mandanten bekommt 404', function () {
    $this->actingAs($this->masterAdmin);
    Livewire::test(MandantenKomponente::class)->assertSeeHtml('data-mandant-zeile="'.$this->soul->id.'"')->assertSeeHtml('overflow-x-auto')->assertSee('Autark')
        ->call('auf', $this->soul->id)->assertSeeHtml('data-mandant-blatt="'.$this->soul->id.'"')->assertSee('Gebuchte Bereiche')
        ->call('auf', $this->soul->id)->assertDontSeeHtml('data-mandant-blatt');

    $this->actingAs($this->soulInhaber);
    Livewire::test(MandantenKomponente::class)->assertNotFound();
});

// ── §13 G: Betreiber und Kurator getrennt ────────────────────────────────────

it('§13 G Pflicht: Plattform-Admin + autarker Mandant = 0 Rezepte, 0 Preise, 0 Foodbooks über Oberfläche (Dienste) und MCP', function () {
    // Inhalte des autarken Mandanten Soulkitchen
    $this->makeRecipe($this->soul, 'Soul Geheimrezept');
    $this->makeFoodbook($this->soul, 'Soul Foodbook');
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->soul->id, 'name' => 'Soul Lieferant']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->soul->id, 'supplier_id' => $sup->id, 'designation' => 'Soul Mehl', 'article_number' => 'S-1', 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
    FoodAlchemistPrice::create(['team_id' => $this->soul->id, 'supplier_item_id' => $la->id, 'price' => 7.77, 'status' => '0']);
    expect(app(MandantenService::class)->modusVon($this->soul))->toBe('autark');

    // Plattform-Admin arbeitet in SEINEM Team (Mitglied nur im Seed-Rootteam)
    $ctx = new ToolContext($this->admin, $this->rootTeam);
    $namen = fn ($daten) => json_encode($daten);

    // Oberfläche: dieselben Dienste, die Rezept-Browser und Foodbook-Liste benutzen
    expect(app(RecipeService::class)->paginateBrowser(['search' => ''], $this->rootTeam, 100)->total())->toBe(0)
        ->and(app(FoodbookService::class)->paginateBrowser([], $this->rootTeam, 100)->total())->toBe(0)
        ->and(app(PriceService::class)->preisSichtFuer(FoodAlchemistPrice::query(), $this->rootTeam)->where('foodalchemist_prices.team_id', $this->soul->id)->count())->toBe(0)
        ->and(FoodAlchemistSupplierItem::query()->visibleToTeam($this->rootTeam)->where('team_id', $this->soul->id)->count())->toBe(0);

    // MCP: Rezepte und Foodbooks im Kontext des Plattform-Admins
    $rezepte = (new RecipesListTool)->execute([], $ctx);
    $foodbooks = (new FoodbooksListTool)->execute([], $ctx);
    expect($rezepte->success)->toBeTrue()->and($namen($rezepte->data))->not->toContain('Soul Geheimrezept')
        ->and($foodbooks->success)->toBeTrue()->and($foodbooks->data['total'])->toBe(0)->and($namen($foodbooks->data))->not->toContain('Soul Foodbook');

    // Das Stammblatt und die Mandanten-Auskunft tragen weder Namen noch Zahlen der Inhalte
    $blatt = json_encode(app(MandantenStammblattService::class)->stammblatt($this->soul, $this->admin));
    expect($blatt)->not->toContain('Geheimrezept')->not->toContain('Soul Foodbook')->not->toContain('7.77')->not->toContain('Soul Mehl');
});

it('§13 G Befund: ein Plattform-Admin, der den Team-Kontext des autarken Mandanten bekäme, sieht dessen Inhalte nicht', function () {
    // Heute NICHT erfüllt, gemessen 14.10.: Kontext (Plattform-Admin, autarker Mandant) ergibt FaRechte::rolle() = Admin, darf(Freigeben) = true
    // und recipes.LIST liefert das Rezept des Mandanten. Ursache: FaRechte::rolle() macht den Plattform-Admin in JEDEM Team zum Admin (Befund Peter),
    // und Inhaltslesen hängt nur am Team-Kontext, nicht am Benutzer. Erreichbar ist der Kontext nur, wenn Core ihn zulässt (MCP-Wechsel und
    // Team-Liste verlangen Mitgliedschaft, aber TeamFlyout::switchTeam setzt current_team_id ohne Prüfung). Vorschlag steht im PR; offen bis zur Entscheidung des Kurators.
})->todo();
