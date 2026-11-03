<?php

use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 · Druckfassung des Kundenlinks (Drucken / PDF): eigener Container, der beim Drucken den Assistenten ersetzt. Server rendert
 * Kopf · Eure Auswahl · Speisen · Extras · Preis · Hinweise, alle Auswahl-Bausteine verborgen; das Skript zeigt nur die Auswahl.
 * Keine Bedienelemente, keine Leer-Hinweise; zzgl. nach Aufwand nur mit Zeilen (vorher machte das Print-CSS Verborgenes sichtbar).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $svc = app(KonfiguratorService::class);
    $gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rf', 'name' => '[HG] Rind|Filet|intern 7',
        'status' => 'approved', 'is_sales_recipe' => true, 'ek_total_eur' => 4]);
    $fire = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Fire intern', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 45, 'ek_per_person_cache' => 14]);
    $slot = fn (int $pos, array $w) => FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $fire->id, 'position' => $pos] + $w);
    $gericht2 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'lc', 'name' => '[VS] Lachs', 'status' => 'approved',
        'is_sales_recipe' => true, 'allergen_fish' => 'enthalten']);
    $slot(1, ['type' => 'header', 'title' => 'Vorspeise']);
    $slot(2, ['type' => 'gericht', 'sales_recipe_id' => $gericht2->id, 'wording' => 'Gebeizter Lachs, Gurke, Dill']);
    $slot(3, ['type' => 'header', 'title' => 'Hauptgang']);
    $slot(4, ['type' => 'gericht', 'sales_recipe_id' => $gericht->id, 'wording' => 'Rinderfilet vom Grill']);
    $slot(5, ['type' => 'gericht', 'sales_recipe_id' => $gericht2->id, 'wording' => 'Lachs vom Holzfeuer, Fenchel']);
    $snack = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Snack', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 6, 'ek_per_person_cache' => 2]);
    $this->k = $svc->anlegen($this->rootTeam, ['name' => 'Druck', 'kunden_titel' => 'Sommerfest', 'gueltig_bis' => now()->addMonth()->format('Y-m-d'),
        'verantwortlich_user_id' => $this->user->id]);
    $this->kz = $svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $fire->id, 'kunden_titel' => 'Fire']);
    $this->addon = $svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'addon', 'concept_id' => $snack->id, 'kunden_titel' => 'Mitternachts-Bites']);
    $svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $svc->leistungHinzu($this->rootTeam, $this->k->id, ['kategorie' => 'personal', 'bezeichnung' => 'Garderobe', 'mengen_regel' => 'schluessel',
        'bezug' => 'gaeste', 'schluessel_n' => 50, 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event',
        'ek_satz' => 25, 'vk_modus' => 'fixed', 'vk_satz' => 45, 'vk_begruendung' => 'Test', 'abrechnung' => 'aufwand']);
    $this->link = app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    auth()->logout();
    $html = $this->get($this->link['url'])->assertOk()->getContent();
    $this->druck = Str::between($html, 'data-konfig-druck', '</article>');
    $this->html = $html;
    if (getenv('FA_KONFIG_HTML_DRUCK')) {   // Sichtprüfung im Print-Preview
        file_put_contents(getenv('FA_KONFIG_HTML_DRUCK'), $html);
    }
});

it('Druck-Container: Abschnitte in fester Reihenfolge, außerhalb des Assistenten', function () {
    $pos = fn (string $a) => strpos($this->druck, 'data-druck-abschnitt="' . $a . '"');
    expect($pos('kopf'))->toBeLessThan($pos('auswahl'))
        ->and($pos('auswahl'))->toBeLessThan($pos('speisen'))
        ->and($pos('speisen'))->toBeLessThan($pos('extras'))
        ->and($pos('extras'))->toBeLessThan($pos('preis'))
        ->and($pos('preis'))->toBeLessThan($pos('hinweise'))
        ->and(strpos($this->html, 'data-konfig-druck'))->toBeGreaterThan(strrpos($this->html, 'data-konfig-schritt='))   // nach dem Assistenten
        ->and($this->druck)->toContain('Sommerfest')->toContain('Eure Auswahl')
        ->toContain('Preise gültig bis')->toContain('wird im Angebot bestätigt');
});

it('Druck-Container: Speisen je Konzept und Tageszeit wie im Foodbook, Extras mit Gerichten, alles verborgen bis zur Auswahl', function () {
    expect($this->druck)->toContain('data-druck-speisen="' . $this->kz->id . ':dinner" hidden')
        ->toContain('Rinderfilet vom Grill')->toContain('class="pt-block"')
        ->toContain('data-druck-addon="' . $this->addon->id . '" hidden')->toContain('Mitternachts-Bites')
        ->toContain('data-druck-speisen-block hidden')->toContain('data-druck-extras-block hidden')->toContain('data-druck-preis-block hidden')
        ->not->toContain('Rind|Filet')->not->toContain('class="pt-item-foto');   // kein interner Name, keine Gericht-Fotos auf A4
});

it('Druck-Container: keine Bedienelemente und keine Leer-Hinweise', function () {
    expect($this->druck)->not->toContain('<button')->not->toContain('<input')->not->toContain('<select')->not->toContain('<form')
        ->not->toContain('Bitte ein Konzept wählen')->not->toContain('gibt es kein Konzept')->not->toContain('Drucken / PDF')->not->toContain('Details');
});

it('zzgl. nach Aufwand: Block verborgen und ohne feste Zeilen; Print-CSS macht Verborgenes nicht mehr sichtbar', function () {
    expect($this->druck)->toContain('data-druck-zzgl hidden')->toContain('<ul data-druck-zzgl-liste></ul>')
        ->and($this->html)->not->toMatch('/@media print \{[^}]*\[hidden\] \{ display: block/')
        ->and($this->html)->toContain('.pt-konfig-js .pt-konfig-druck { display: block;')
        ->and($this->html)->toContain('.pt-konfig-js .pt-konfig-seite');
});

it('Kontakt und Betrieb aus dem Snapshot (Anfragen gehen an), ohne Kontakt kein Eintrag', function () {
    expect($this->druck)->toContain('Ihr Kontakt: ' . $this->user->name);
    $meta = $this->k->fresh()->presentation_snapshot_json['meta'];
    expect($meta['kontakt']['name'])->toBe($this->user->name)->and(array_keys($meta['kontakt']))->toBe(['name', 'email']);
});
