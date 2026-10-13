<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Abos\Index as AbosIndex;
use Platform\FoodAlchemist\Livewire\Recipes\FassungModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 B6 — Oberfläche Abo/Fassungen: Master-Seite „Abos" (vergeben, kündigen, zurückziehen mit Vorschau),
 * „Meine Abos" beim Mandanten, „Veröffentlichen …" im Rezept (Stand, Abweichung, Allergen-Hinweis, Pflicht-Notiz).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->broich->id]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);                     // Soulkitchen ohne Stamm-Paket
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->broichKoch = $this->makeUser($this->broich, 'Broich Koch', 'member');
    $this->kitaChef = $this->makeUser($this->kita, 'Kita Chef', 'owner');

    $this->ei = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ei', 'name' => 'Ei']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Ei', $this->ei, '100');

    $inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->jus->id], $this->kurator);
});

it('Master: Seite zeigt Sammlung mit Fassungs-Stand, vergibt ein Abo, Mandant ohne Stamm-Paket ist nicht wählbar', function () {
    $this->actingAs($this->kurator);
    $lw = Livewire::test(AbosIndex::class)
        ->assertSeeHtml('data-abos-sammlung="'.$this->sammlung.'"')
        ->assertSeeHtml('data-abos-unveroeffentlicht')
        ->assertSee('Soulkitchen (kein Stamm-Paket)');

    $lw->set('neuSammlungId', (string) $this->sammlung)->set('neuMandantId', (string) $this->soul->id)->call('vergeben')
        ->assertSeeHtml('data-abos-fehler');                                      // Dienst-Riegel greift auch beim Umgehen der Auswahl
    $lw->set('neuSammlungId', (string) $this->sammlung)->set('neuMandantId', (string) $this->broich->id)->call('vergeben')
        ->assertSeeHtml('data-abos-meldung');

    $abo = DB::table('foodalchemist_abos')->where('mandant_team_id', $this->broich->id)->first();
    expect($abo?->status)->toBe('aktiv');
    $lw->assertSeeHtml('data-abos-zeile="'.$abo->id.'"');

    app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erste Fassung', $this->kurator);
    Livewire::test(AbosIndex::class)->assertSee('Fassung 1')->assertSee('Erste Fassung')->assertDontSeeHtml('data-abos-unveroeffentlicht');
});

it('Master ohne Rolle „Freigeben": nur lesen, keine Schreib-Knöpfe, Aktion scheitert am Dienst', function () {
    app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $this->actingAs($this->masterKoch);

    Livewire::test(AbosIndex::class)
        ->assertSeeHtml('data-abos-nur-lesen')
        ->assertDontSeeHtml('data-abos-vergeben-knopf')->assertDontSeeHtml('data-abos-kuendigen')->assertDontSeeHtml('data-abos-zurueckziehen')
        ->set('neuSammlungId', (string) $this->sammlung)->set('neuMandantId', (string) $this->broich->id)->call('vergeben')
        ->assertSeeHtml('data-abos-fehler');
});

it('Master kündigt: erst Vorschau (nichts passiert), Ausführen nur mit Häkchen', function () {
    $abo = app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $this->actingAs($this->kurator);

    $lw = Livewire::test(AbosIndex::class)->call('kuendigenVorschau', $abo->id)
        ->assertSeeHtml('data-abos-vorschau')->assertSeeHtml('data-abos-vorschau-verschwindet')->assertSee('Kalbsjus');
    $lw->call('ausfuehren');                                                      // ohne Häkchen: Dienst gibt nur die Vorschau
    expect(DB::table('foodalchemist_abos')->where('id', $abo->id)->value('status'))->toBe('aktiv');

    $lw->set('bestaetigt', true)->call('ausfuehren')->assertSeeHtml('data-abos-meldung')->assertDontSeeHtml('data-abos-vorschau-mandant');
    expect(DB::table('foodalchemist_abos')->where('id', $abo->id)->value('status'))->toBe('gekuendigt');
});

it('Master zieht ein Rezept zurück: Vorschau je Mandant, danach verlässt es die Sammlung', function () {
    app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $this->actingAs($this->kurator);

    Livewire::test(AbosIndex::class)->call('zurueckziehenVorschau', $this->sammlung, $this->jus->id)
        ->assertSee('Zurückziehen: Kalbsjus')->assertSee('Broich')
        ->set('bestaetigt', true)->call('ausfuehren')->assertSeeHtml('data-abos-meldung');

    expect(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $this->sammlung)->where('objekt_id', $this->jus->id)->exists())->toBeFalse();
});

it('Mandant: „Meine Abos" mit Fassung, Notiz und Allergen-Hinweis; Kündigen nur für den Oberteam-Admin', function () {
    $abo = app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $fs = app(FassungService::class);
    $fs->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    // Allergen-Änderung: Fassung 2 mit anderem Allergen-Bild
    $spalte = collect(array_keys(FoodAlchemistRecipe::find($this->jus->id)->getAttributes()))->first(fn ($k) => str_starts_with($k, 'allergen_') && ! str_ends_with($k, '_at') && ! str_contains($k, 'confidence'));
    $this->makeIngredient($this->jus, 'Ei', $this->ei, '50');
    DB::table('foodalchemist_recipes')->where('id', $this->jus->id)->update([$spalte => 'enthalten']);
    $r = $fs->veroeffentlichen($this->master, $this->jus->id, 'Mehr Ei', $this->kurator);
    $f2 = FoodAlchemistRecipe::mitFassungen()->findOrFail($r['fassung_id']);
    DB::table('foodalchemist_recipes')->where('id', $f2->id)->update(['fassung_allergen_geaendert' => true]);   // Allergen-Bild ist Sache der Neuberechnung — hier nur die Anzeige

    $this->actingAs($this->broichChef);
    Livewire::test(AbosIndex::class)
        ->assertSeeHtml('data-abos-mein-abo="'.$abo->id.'"')->assertSee('Fassung 2')->assertSee('Mehr Ei')
        ->assertSeeHtml('data-abos-allergen-hinweis')->assertSeeHtml('data-abos-kuendigen')
        ->assertDontSeeHtml('data-abos-vergeben-knopf')
        ->call('kuendigenVorschau', $abo->id)->assertSeeHtml('data-abos-vorschau');

    $this->actingAs($this->broichKoch);                                            // Mitglied, kein Admin
    Livewire::test(AbosIndex::class)->assertSee('Fassung 2')->assertDontSeeHtml('data-abos-kuendigen')
        ->call('kuendigenVorschau', $abo->id)->assertSeeHtml('data-abos-fehler');

    $this->actingAs($this->kitaChef);                                              // Unter-Team: liest die Abos des Oberteams
    Livewire::test(AbosIndex::class)->assertSeeHtml('data-abos-unterteam')->assertSee('Fassung 2')->assertDontSeeHtml('data-abos-kuendigen');
    expect(DB::table('foodalchemist_abos')->where('id', $abo->id)->value('status'))->toBe('aktiv');
});

it('Veröffentlichen-Fenster: Stand, Abweichung, Pflicht-Notiz, „nichts geändert" ohne neue Fassung', function () {
    $this->actingAs($this->kurator);
    $lw = Livewire::test(FassungModal::class)->call('oeffnen', $this->jus->id)
        ->assertDispatched('modal.open')->assertSee('Noch nie veröffentlicht')->assertSeeHtml('data-fassung-notiz');

    $lw->call('veroeffentlichen')->assertSeeHtml('data-fassung-fehler');           // Notiz fehlt → Dienst lehnt ab
    expect(FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->jus->id)->count())->toBe(0);

    $lw->set('notiz', 'Erste Fassung')->call('veroeffentlichen')->assertSeeHtml('data-fassung-meldung')->assertSee('Fassung 1 veröffentlicht')
        ->assertDispatched('fassung-veroeffentlicht')->assertSeeHtml('data-fassung-gleich');

    $this->makeIngredient($this->jus, 'Ei', $this->ei, '20');
    Livewire::test(FassungModal::class)->call('oeffnen', $this->jus->id)
        ->assertSee('Fassung 1')->assertSeeHtml('data-fassung-weicht-ab')->assertSee('Kalbsjus');
});

it('FassungService::status: Allergen-Änderung und Nicht-Stamm-GP werden VOR dem Veröffentlichen gemeldet', function () {
    $fs = app(FassungService::class);
    $fs->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $spalte = collect(array_keys(FoodAlchemistRecipe::find($this->jus->id)->getAttributes()))->first(fn ($k) => str_starts_with($k, 'allergen_') && ! str_ends_with($k, '_at') && ! str_contains($k, 'confidence'));
    $alt = DB::table('foodalchemist_recipes')->where('id', $this->jus->id)->value($spalte);
    DB::table('foodalchemist_recipes')->where('id', $this->jus->id)->update([$spalte => $alt === 'enthalten' ? 'nicht_enthalten' : 'enthalten']);
    $eigen = $this->makeGp($this->master, 'Master-Trüffel');                       // team-eigener GP = kein Stamm
    $this->makeIngredient($this->jus, 'Trüffel', $eigen, '5');

    $s = $fs->status(FoodAlchemistRecipe::findOrFail($this->jus->id));
    expect($s['letzte']['nr'])->toBe(1)->and($s['weicht_ab'])->toBeTrue()
        ->and($s['allergen_aenderung'])->toBeTrue()->and($s['allergen_felder'])->toContain($spalte)
        ->and($s['stamm_verstoesse'])->toBe(['Master-Trüffel']);

    $this->actingAs($this->kurator);
    Livewire::test(FassungModal::class)->call('oeffnen', $this->jus->id)
        ->assertSeeHtml('data-fassung-allergen')->assertSeeHtml('data-fassung-stamm')
        ->assertSee('gehört dem Master-Team, nicht dem globalen Stamm')->assertSee('nach dem Stamm-Umzug möglich');
});

it('Veröffentlichen-Fenster außerhalb des Masters und ohne Rolle „Freigeben"', function () {
    $this->actingAs($this->masterKoch);
    Livewire::test(FassungModal::class)->call('oeffnen', $this->jus->id)
        ->assertSeeHtml('data-fassung-nur-lesen')->assertDontSeeHtml('data-fassung-veroeffentlichen');

    $eigen = $this->makeRecipe($this->broich, 'Broich Jus');
    $this->actingAs($this->broichChef);
    Livewire::test(FassungModal::class)->call('oeffnen', $eigen->id)->assertSee('nur für Rezepte des Master-Teams');
});

it('Bereich „abos": Route, Navigation und Komponente sind verdrahtet', function () {
    // Vollseiten-GET geht im Harness nicht (Layout braucht Core-Tabellen wie oauth_access_tokens) — Verdrahtung statt Render
    expect(FaBereiche::ROUTEN)->toHaveKey('abos')->and(FaBereiche::KOMPONENTEN['Abos'] ?? null)->toBe('abos')
        ->and(str_contains(json_encode(config('foodalchemist')), 'foodalchemist.abos.index'))->toBeTrue();
    if (! Route::has('foodalchemist.abos.index')) {
        $this->markTestSkipped('Route im Harness nicht registriert.');
    }
    expect(Route::getRoutes()->getByName('foodalchemist.abos.index')->getActionName())->toContain(AbosIndex::class);
});

it('Wording: Seite duzt, leere Sammlungen verlinken auf Einstellungen › Unter-Teams & Freigaben', function () {
    DB::table('foodalchemist_sammlungen')->where('id', $this->sammlung)->update(['deleted_at' => now()]);
    $this->actingAs($this->masterKoch);                       // ohne „Freigeben": Nur-lesen-Hinweis + leere Listen
    $html = Livewire::test(AbosIndex::class)
        ->assertSeeHtml('data-abos-link-sammlungen')
        ->assertSeeHtml('href="'.route('foodalchemist.einstellungen', ['sektion' => 'unter-teams']).'"')
        ->assertSee('Du siehst den Stand')->assertSee('Vergib eine Sammlung')
        ->html();
    expect($html)->not->toMatch('/\b(Sie sehen|Vergeben Sie|legen Sie|Ihren)\b/u');
});
