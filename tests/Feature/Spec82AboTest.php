<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboAuskunft;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\AboSicht;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §8 / §12 A · Durchstich Schritt 1: Abo-Tabelle + Sichtbarkeits-Zweig.
 *
 * Aufbau: Master-Team (konfiguriert), zwei Mandanten NEBENEINANDER (kein Eltern-Team), Broich mit Unter-Team Kita,
 * ein autarker Mandant ohne Stamm-Paket. Master-Rezept Jus mit Unter-Rezept Fond, je als Arbeitszeile und als
 * veröffentlichte Fassungs-Zeile (Schritt 2 erzeugt die Fassungen; hier von Hand, ek_* = NULL wie dort).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->broich->id]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->autark = Team::create(['name' => 'Autarke Firma', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    StammPaket::freischalten($this->soul);

    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');

    // Arbeitszeilen des Masters (mit Master-Kosten) …
    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond', ['ek_total_eur' => 3.10]);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus', ['ek_total_eur' => 4.20]);
    $this->makeIngredient($this->jus, 'Kalbsfond')->update(['referenced_recipe_id' => $this->fond->id]);
    $this->geheim = $this->makeRecipe($this->master, 'Master-Geheimrezept');
    // … und ihre veröffentlichten Fassungen (ohne ek_*)
    $this->fondF = $this->makeRecipe($this->master, 'Kalbsfond', ['ist_fassung' => true, 'fassung_von_id' => $this->fond->id, 'ek_total_eur' => null]);
    $this->jusF = $this->makeRecipe($this->master, 'Kalbsjus', ['ist_fassung' => true, 'fassung_von_id' => $this->jus->id, 'ek_total_eur' => null]);
    $this->makeIngredient($this->jusF, 'Kalbsfond')->update(['referenced_recipe_id' => $this->fondF->id]);

    $this->inhalte = app(InhaltsFreigabeService::class);
    $this->abos = app(AboService::class);
    $this->sammlung = $this->inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->jus->id], $this->kurator);

    $this->sieht = fn (Team $t) => FoodAlchemistRecipe::visibleToTeam($t)->pluck('foodalchemist_recipes.id')->map(fn ($v) => (int) $v)->all();
    $this->masterIds = fn () => [$this->fond->id, $this->jus->id, $this->geheim->id, $this->fondF->id, $this->jusF->id];
});

it('§12 A4 · zwei Grundeinstellungen: Master → Mandant ohne Abo sieht NICHTS, Oberteam → Unter-Team ohne Freigabe sieht ALLES', function () {
    $eigen = $this->makeRecipe($this->broich, 'Broich Hausjus');

    foreach ([$this->broich, $this->kita, $this->soul, $this->autark] as $t) {
        expect(array_intersect(($this->sieht)($t), ($this->masterIds)()))->toBe([], $t->name);
    }
    expect(($this->sieht)($this->kita))->toContain($eigen->id)            // 77d: keine Zeile = übernimmt alles
        ->and(AboSicht::aktiv())->toBeFalse();                           // ohne Abo hängt kein Zweig an
});

it('Abo: Mandant sieht die veröffentlichte Fassung samt Unter-Rezept, nie Arbeitszeilen; andere Mandanten nichts', function () {
    $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);

    $broich = ($this->sieht)($this->broich);
    expect($broich)->toContain($this->jusF->id, $this->fondF->id)
        ->not->toContain($this->jus->id, $this->fond->id, $this->geheim->id)
        ->and(array_intersect(($this->sieht)($this->soul), ($this->masterIds)()))->toBe([])
        ->and(array_intersect(($this->sieht)($this->autark), ($this->masterIds)()))->toBe([])
        // Unter-Team ohne Einschränkung sieht Abo-Inhalte wie Inhalte des Oberteams (§12 A2)
        ->and(($this->sieht)($this->kita))->toContain($this->jusF->id, $this->fondF->id)
        // derselbe Zweig im Raw-Scope
        ->and(TeamScope::applyVisible(DB::table('foodalchemist_recipes'), 'foodalchemist_recipes.team_id', $this->broich)
            ->pluck('id')->map(fn ($v) => (int) $v)->all())->toContain($this->jusF->id)->not->toContain($this->jus->id);

    // Leck-Sperre: kein Wert, den der Mandant sieht, trägt Master-ek_*
    expect(FoodAlchemistRecipe::visibleToTeam($this->broich)->whereIn('foodalchemist_recipes.id', ($this->masterIds)())
        ->whereNotNull('ek_total_eur')->count())->toBe(0);
});

it('eingeschränktes Unter-Team: Abo-Inhalte nur, wenn das Oberteam sie ihm freigibt (77d-Hülle)', function () {
    $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $this->inhalte->setzeErbtAlles($this->broich, $this->kita->id, false, $this->broichChef);
    expect(array_intersect(($this->sieht)($this->kita), ($this->masterIds)()))->toBe([]);

    // Broich legt eine eigene Sammlung mit dem Abo-Jus an und gibt sie der Kita frei
    $sid = $this->inhalte->sammlungAnlegen($this->broich, 'Kita-Basis', null, $this->broichChef);
    $this->inhalte->sammlungHinzu($this->broich, $sid, 'recipe', [$this->jusF->id], $this->broichChef);
    $this->inhalte->freigeben($this->broich, 'sammlung', $sid, $this->kita->id, $this->broichChef);
    TeamAncestryRegistry::flushAll();

    expect(($this->sieht)($this->kita))->toContain($this->jusF->id, $this->fondF->id)->not->toContain($this->jus->id);
});

it('Regeln beim Abonnieren: nur Master mit „Freigeben", nur Kunden-Hauptteam, nur mit Stamm-Paket, nur Master-Sammlungen', function () {
    expect(fn () => $this->abos->abonnieren($this->broich, $this->soul, $this->sammlung, $this->broichChef))->toThrow(RuntimeException::class, 'nur das Master-Team')
        ->and(fn () => $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->masterKoch))->toThrow(FaRechtFehltException::class)
        ->and(fn () => $this->abos->abonnieren($this->master, $this->kita, $this->sammlung, $this->kurator))->toThrow(RuntimeException::class, 'nie ein Unter-Team')
        ->and(fn () => $this->abos->abonnieren($this->master, $this->autark, $this->sammlung, $this->kurator))->toThrow(RuntimeException::class, 'Stamm-Paket')
        ->and(fn () => $this->abos->abonnieren($this->master, $this->master, $this->sammlung, $this->kurator))->toThrow(RuntimeException::class, 'selbst');

    $fremd = $this->inhalte->sammlungAnlegen($this->broich, 'Broich intern', null, $this->broichChef);
    expect(fn () => $this->abos->abonnieren($this->master, $this->soul, $fremd, $this->kurator))->toThrow(RuntimeException::class, 'Sammlungen des Master-Teams');

    // idempotent: zweimal abonnieren = ein Abo
    $a = $this->abos->abonnieren($this->master, $this->soul, $this->sammlung, $this->kurator);
    $b = $this->abos->abonnieren($this->master, $this->soul, $this->sammlung, $this->kurator);
    expect($b->id)->toBe($a->id)->and(DB::table('foodalchemist_abos')->count())->toBe(1);
});

it('Hülle enthält nur Fassungen: ohne Veröffentlichung leer, ein Verweis auf eine Arbeitszeile fällt heraus', function () {
    $neu = $this->makeRecipe($this->master, 'Demi-glace (unveröffentlicht)');
    $this->inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$neu->id], $this->kurator);
    // Fehlverweis: Fassung zeigt auf die ARBEITSZEILE des Fonds
    $this->makeIngredient($this->jusF, 'Fond (Arbeitszeile)')->update(['referenced_recipe_id' => $this->fond->id]);

    $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $huelle = $this->abos->huelle($this->broich->id);

    expect($huelle['recipe'])->toBe([$this->fondF->id, $this->jusF->id])
        ->and(($this->sieht)($this->broich))->not->toContain($neu->id, $this->fond->id);
});

it('Plattform-Admin bekommt keine Inhalts-Ausnahme (§13 G): in einem autarken Mandanten 0 Master-Rezepte', function () {
    $admin = $this->makeUser($this->autark, 'Plattform Admin', 'owner');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);

    expect(array_intersect(($this->sieht)($this->autark), ($this->masterIds)()))->toBe([]);
    $this->actingAs($admin);
    $ctx = new ToolContext($admin, $this->autark);
    $liste = app(ToolRegistry::class)->get('foodalchemist.abos.GET')->execute([], $ctx);
    expect($liste->success)->toBeTrue()->and($liste->data['abos'])->toBe([]);
});

it('MCP abos.POST/GET im Lockstep; AboAuskunft liefert die Abos für Paket-Abbestellung und Master-Wechsel', function () {
    $reg = app(ToolRegistry::class);
    $this->actingAs($this->kurator);
    $master = new ToolContext($this->kurator, $this->master);
    $koch = new ToolContext($this->masterKoch, $this->master);
    $mandant = new ToolContext($this->broichChef, $this->broich);

    expect($reg->get('foodalchemist.abos.POST')->execute(['mandant_team_id' => $this->broich->id, 'sammlung_id' => $this->sammlung], $koch)->errorCode)->toBe('FORBIDDEN')
        ->and($reg->get('foodalchemist.abos.POST')->execute(['mandant_team_id' => $this->autark->id, 'sammlung_id' => $this->sammlung], $master)->errorCode)->toBe('VALIDATION_ERROR');

    $post = $reg->get('foodalchemist.abos.POST')->execute(['mandant_team_id' => $this->broich->id, 'sammlung_id' => $this->sammlung], $master);
    expect($post->success)->toBeTrue((string) ($post->error ?? ''))->and($post->data['status'])->toBe('aktiv');

    $get = $reg->get('foodalchemist.abos.GET')->execute(['huelle_fuer_team_id' => $this->broich->id], $master);
    expect($get->data['abos'])->toHaveCount(1)
        ->and($get->data['abos'][0]['sammlung'])->toBe('Basis')
        ->and($get->data['huelle']['recipe'])->toBe([$this->fondF->id, $this->jusF->id]);

    // Mandant sieht sein Abo, aber nicht die Hülle-Abfrage des Masters
    expect($reg->get('foodalchemist.abos.GET')->execute([], $mandant)->data['abos'])->toHaveCount(1)
        ->and($reg->get('foodalchemist.abos.GET')->execute(['huelle_fuer_team_id' => $this->broich->id], $mandant)->errorCode)->toBe('VALIDATION_ERROR');

    $auskunft = app(AboAuskunft::class);
    expect($auskunft->abosAmStammPaket($this->broich))->toHaveCount(1)
        ->and($auskunft->abosAmStammPaket($this->soul))->toBe([])
        ->and($auskunft->abosAmMaster($this->master->id)[0]['mandant_team_id'])->toBe($this->broich->id)
        ->and($auskunft->abosAmMaster(null))->toBe([]);
});

it('Mandant beenden (§13 C3) kündigt die Abos des Mandanten wirklich (Schritt 5) — nie still „0 gekündigt"', function () {
    $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $auskunft = app(AboAuskunft::class);

    expect($auskunft->verfuegbar())->toBeTrue()
        ->and($auskunft->kuendigen($this->broich, $this->kurator))->toHaveCount(1)
        ->and($auskunft->kuendigen($this->soul, $this->kurator))->toBe([])                     // ohne Abo: nichts zu tun
        ->and(DB::table('foodalchemist_abos')->where('mandant_team_id', $this->broich->id)->value('status'))->toBe('gekuendigt')
        ->and(array_intersect(($this->sieht)($this->broich), ($this->masterIds)()))->toBe([]);
});
