<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Exceptions\KiBudgetErschoepftException;
use Platform\FoodAlchemist\Exceptions\KiDeaktiviertException;
use Platform\FoodAlchemist\Livewire\Settings\Zugriffsrechte;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 77b · Bereiche je Team (nur Plattform-Admin schaltet), Einschränkung je User (Team-Admin, nur
 * abschalten), Kontingente (Standorte, User, KI-Budget € je Monat). Durchgesetzt an Seiten, Klicks, MCP,
 * Navigation und KI-Gateway.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->plattformAdmin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->plattformAdmin->email)]]);
    $this->reg = app(ToolRegistry::class);
});

it('Wächter: jedes registrierte FA-Tool und jede FA-Route ist genau einem Bereich zugeordnet (oder ausdrücklich frei)', function () {
    $ohne = [];
    foreach ($this->reg->all() as $tool) {
        if (str_starts_with($tool->getName(), 'foodalchemist.') && ! FaBereiche::toolZuordnung($tool->getName())[0]) {
            $ohne[] = $tool->getName();
        }
    }
    expect($ohne)->toBe([]);

    $routenOhne = [];
    foreach (Route::getRoutes() as $r) {
        $n = $r->getName();
        if ($n !== null && str_starts_with($n, 'foodalchemist.') && ! FaBereiche::routeZugeordnet($n)) {
            $routenOhne[] = $n;
        }
    }
    expect($routenOhne)->toBe([]);
});

it('Team-Bereich aus: MCP (auch lesend) gesperrt, Navigation ausgeblendet; nur Plattform-Admin schaltet; Unter-Team erbt', function () {
    expect(fn () => $this->rechte->setzeTeamBereich($this->rootTeam, $this->inhaber, 'einkauf', false))->toThrow(FaRechtFehltException::class);
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->plattformAdmin, 'einkauf', false);

    $r = $this->reg->get('foodalchemist.orders.GET')->execute([], new ToolContext($this->inhaber, $this->rootTeam));
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');
    expect($this->reg->get('foodalchemist.recipes.LIST')?->execute([], new ToolContext($this->inhaber, $this->rootTeam))->errorCode)->not->toBe('FORBIDDEN');

    $nav = FaBereiche::sichtbareNavigation(config('foodalchemist.sidebar', []), $this->inhaber);
    $routen = collect($nav)->pluck('items')->flatten(1)->pluck('route')->all();
    expect($routen)->not->toContain('foodalchemist.orders.index')->and($routen)->toContain('foodalchemist.lager.index');

    // Unter-Team hat höchstens, was das Haupt-Team hat
    expect($this->rechte->bereichAktiv($this->childA, 'einkauf'))->toBeFalse()
        ->and($this->rechte->darfBereich($this->plattformAdmin, $this->rootTeam, 'einkauf'))->toBeTrue();   // Plattform-Admin sieht alles
});

it('Seite eines abgeschalteten Bereichs antwortet 403', function () {
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->plattformAdmin, 'lager', false);
    $this->actingAs($this->inhaber);
    $this->get(route('foodalchemist.lager.index'))->assertStatus(403);
});

it('User-Einschränkung: Team-Admin schränkt Mitglied und Betrachter ein, nie Inhaber/Admin; nur abschalten', function () {
    $leser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $this->rechte->setzeUserSperre($this->rootTeam, $this->inhaber, $this->koch->id, 'controlling', true);
    expect($this->rechte->darfBereich($this->koch, $this->rootTeam, 'controlling'))->toBeFalse()
        ->and($this->rechte->darfBereich($this->koch, $this->rootTeam, 'einkauf'))->toBeTrue();
    $r = $this->reg->get('foodalchemist.sales_facts.GET')?->execute([], new ToolContext($this->koch, $this->rootTeam));
    if ($r !== null) {
        expect($r->errorCode)->toBe('FORBIDDEN');
    }

    expect(fn () => $this->rechte->setzeUserSperre($this->rootTeam, $this->koch, $leser->id, 'lager', true))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->rechte->setzeUserSperre($this->rootTeam, $this->inhaber, $this->inhaber->id, 'lager', true))->toThrow(\RuntimeException::class, 'Inhaber und Admins');

    $this->rechte->setzeUserSperre($this->rootTeam, $this->inhaber, $this->koch->id, 'controlling', false);
    expect($this->rechte->darfBereich($this->koch, $this->rootTeam, 'controlling'))->toBeTrue();

    // UI: Team-Admin klappt die Einschränkung auf und schaltet ab
    $this->actingAs($this->inhaber);
    Livewire::test(Zugriffsrechte::class)->call('bereicheUmschalten', $this->koch->id)
        ->assertSeeHtml('data-recht-bereiche="'.$this->koch->id.'"')
        ->call('userBereichSetzen', $this->koch->id, 'lager', false)->assertSet('fehler', null);
    expect($this->rechte->darfBereich($this->koch, $this->rootTeam, 'lager'))->toBeFalse();
});

it('Kontingente: nur Plattform-Admin setzt; Standorte und Benutzer werden geprüft; KI-Budget € je Monat', function () {
    expect(fn () => $this->rechte->setzeKontingente($this->rootTeam, $this->inhaber, ['max_user' => 3]))->toThrow(FaRechtFehltException::class);

    $this->rechte->setzeKontingente($this->rootTeam, $this->plattformAdmin, ['max_standorte' => 2, 'max_user' => 3, 'ki_budget_eur_monat' => '0,01']);
    expect($this->rechte->kontingente($this->childA))->toBe(['max_standorte' => 2, 'max_user' => 3, 'ki_budget_eur_monat' => 0.01]);

    // Root hat childA + childB = 2 Standorte → erreicht; 3 Benutzer (Inhaber, Koch, Plattform-Admin) → erreicht
    expect(fn () => $this->rechte->pruefeKontingent($this->rootTeam, 'standorte'))->toThrow(\RuntimeException::class, '2 Standorte');
    expect(fn () => $this->rechte->pruefeKontingent($this->rootTeam, 'user'))->toThrow(\RuntimeException::class, '3 Benutzer');

    // KI-Budget: ohne Verbrauch frei, mit teurem Aufruf im Monat erschöpft (Unter-Team zählt zum Haupt-Team)
    expect($this->rechte->kiBudgetErschoepft($this->rootTeam))->toBeFalse();
    config(['foodalchemist.ai.usd_eur' => 1.0]);
    DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->childA->id, 'feature' => 'test',
        'model' => 'gpt-4o', 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000, 'created_at' => now(), 'updated_at' => now()]);
    expect($this->rechte->kontingentNutzung($this->rootTeam)['ki_eur_monat'])->toBeGreaterThan(0.01)
        ->and($this->rechte->kiBudgetErschoepft($this->childA))->toBeTrue();
    expect(new KiBudgetErschoepftException())->toBeInstanceOf(KiDeaktiviertException::class);

    $this->rechte->setzeKontingente($this->rootTeam, $this->plattformAdmin, ['ki_budget_eur_monat' => null]);
    expect($this->rechte->kiBudgetErschoepft($this->rootTeam))->toBeFalse();
});

/** Blast-Radius-Test: eine Komponente außerhalb des FA-Moduls (globaler Namespace). */
class Spec77bFremdeKomponente extends \Livewire\Component
{
    public int $n = 0;

    public function ping(): void
    {
        $this->n++;
    }

    public function render(): string
    {
        return '<div>fremd {{ $n }}</div>';
    }
}

it('Blast-Radius: Nicht-FA-Route, Nicht-FA-Komponente und Requests ohne Team laufen unberührt durch', function () {
    // Härtefall: ALLE FA-Bereiche des Teams aus
    foreach (array_keys(FaBereiche::KATALOG) as $b) {
        $this->rechte->setzeTeamBereich($this->rootTeam, $this->plattformAdmin, $b, false);
    }
    Route::middleware('web')->get('/_spec77b/fremd', fn () => 'fremd-ok')->name('fremd.seite');
    Route::middleware('web')->get('/_spec77b/ohne-name', fn () => 'ohne-name-ok');
    app('router')->getRoutes()->refreshNameLookups();

    $this->actingAs($this->inhaber);
    $this->get('/_spec77b/fremd')->assertOk()->assertSee('fremd-ok');
    $this->get('/_spec77b/ohne-name')->assertOk()->assertSee('ohne-name-ok');
    $this->get(route('foodalchemist.lager.index'))->assertStatus(403);   // Gegenprobe: FA-Seite gesperrt

    // Nicht-FA-Komponente: Klick läuft; FA-Komponente: Klick wird abgewiesen
    Livewire::test(Spec77bFremdeKomponente::class)->call('ping')->assertSet('n', 1);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Lager\Index::class)->call('reiterSetzen', 'bewegungen')
        ->assertDispatched('fa-saved')->assertNotSet('reiter', 'bewegungen');

    // Ohne Team-Kontext: Middleware und Klick-Haken prüfen nichts (die Seite selbst entscheidet)
    $ohneTeam = $this->makeUser($this->rootTeam, 'Ohne Team', null);
    $ohneTeam->forceFill(['current_team_id' => null])->save();
    $this->actingAs($ohneTeam->refresh());
    $this->get('/_spec77b/fremd')->assertOk();
    $req = \Illuminate\Http\Request::create('/lager');
    $req->setUserResolver(fn () => $ohneTeam);
    $req->setRouteResolver(fn () => app('router')->getRoutes()->getByName('foodalchemist.lager.index'));
    expect((new \Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware())->handle($req, fn () => 'durch'))->toBe('durch');
    Livewire::test(Spec77bFremdeKomponente::class)->call('ping')->assertSet('n', 1);

    // Ohne Benutzer (Gast) ebenso
    auth()->logout();
    $this->get('/_spec77b/fremd')->assertOk();
});
