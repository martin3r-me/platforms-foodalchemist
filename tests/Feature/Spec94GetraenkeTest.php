<?php

use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorKonzept;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94c · Stufe 8b: Getränkepakete als `rolle = getraenk` (Concept aus dem Concepter, Kommerzielles an der Konfigurator-Zeile). Pauschale
 * je Event, je Stunde (Einsatzzeit) oder Staffel; je Zeitfenster ein eigenes Paket (Lunch tagsüber, Dinner abends, Tag und Abend = beide Teile,
 * Dominique 03.11.); Kombi-Rabatt auf Tag und Abend;
 * Preistabelle g{id}:lunch|dinner|dn, Gruppe „Getränke“; eigener Schritt im Link, Pflicht und Bindung; Reiter im Editor.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $this->c = fn (string $name, float $vk, float $ek) => FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => $name, 'kind' => 'concept',
        'status' => 'active', 'price_per_person_cache' => $vk, 'ek_per_person_cache' => $ek]);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Fest', 'kunden_titel' => 'Sommerfest', 'stunden_lunch' => 4, 'stunden_dinner' => 6,
        'stunden_day_night' => 10, 'kombi_rabatt_pct' => 10, 'gueltig_bis' => now()->addMonth()->format('Y-m-d'), 'verantwortlich_user_id' => $this->user->id]);
    $this->haupt = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => ($this->c)('Mittag', 30, 10)->id,
        'dinner_concept_id' => ($this->c)('Abend', 50, 15)->id, 'kunden_titel' => 'Culture']);
    $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $this->paket = $this->k->fresh()->pakete->first();
    $this->wein = ($this->c)('Wein intern', 18, 6);
    $this->preis = fn (FoodAlchemistKonfiguratorKonzept $g, string $t) => round(app(KonfiguratorPricingService::class)->rechneFall($this->k->fresh(), $this->paket,
        ['typ' => 'getraenk', 'g' => $g->id, 't' => $t])['vk_pp'], 2);
});

it('Je Zeitfenster ein eigenes Paket: Tag + Abend = beide Teile mit Kombi-Rabatt; nur Abend-Concept = abends und Tag und Abend nur der Abend-Teil', function () {
    $g = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'lunch_concept_id' => $this->wein->id, 'dinner_concept_id' => $this->wein->id,
        'kunden_titel' => 'Weinbegleitung']);
    expect($g->getraenk_abrechnung)->toBe('pauschale')->and($g->getraenk_dauer)->toBe('event')
        ->and(($this->preis)($g, 'lunch'))->toBe(18.0)->and(($this->preis)($g, 'dinner'))->toBe(18.0)
        ->and(($this->preis)($g, 'day_night'))->toBe(32.4);   // tagsüber + abends, 10 % Kombi-Rabatt

    $abend = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => $this->wein->id]);   // concept_id = abends
    expect($abend->lunch_concept_id)->toBeNull()->and(($this->preis)($abend, 'day_night'))->toBe(16.2);
    $faelle = collect(app(KonfiguratorPricingService::class)->matrix($this->k->fresh())['pakete'][0]['faelle']);
    expect($faelle->filter(fn ($f) => $f['fall']['typ'] === 'getraenk' && $f['fall']['g'] === $abend->id)->pluck('fall.t')->values()->all())->toBe(['dinner', 'day_night']);

    $fall = $faelle->first(fn ($f) => $f['fall']['typ'] === 'getraenk' && $f['fall']['g'] === $g->id && $f['fall']['t'] === 'dinner');
    expect(collect($fall['gruppen'])->pluck('name')->all())->toBe(['Getränke'])->and($fall['db_pp'])->toBe(12.0);
});

it('Pauschale je Stunde mit der Einsatzzeit; Staffel: 4 Std. Grundpreis 18 € + 3,50 € je Folgestunde = 25 € bei 6 Std.', function () {
    $soft = ($this->c)('Softdrinks', 4.5, 1);
    $stunde = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'lunch_concept_id' => $soft->id, 'dinner_concept_id' => $soft->id, 'getraenk_dauer' => 'stunde']);
    expect(($this->preis)($stunde, 'lunch'))->toBe(18.0)->and(($this->preis)($stunde, 'dinner'))->toBe(27.0)
        ->and(($this->preis)($stunde, 'day_night'))->toBe(round(45 * 0.9, 2));

    $staffel = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'lunch_concept_id' => $this->wein->id, 'dinner_concept_id' => $this->wein->id,
        'grund_stunden' => 4, 'folgestunde_satz' => '3,50']);
    expect(($this->preis)($staffel, 'dinner'))->toBe(25.0)->and(($this->preis)($staffel, 'lunch'))->toBe(18.0)   // 4 Std. ohne Folgestunde
        // EK nach Verbrauch: 6 € für 4 Std. = 1,50 € je Stunde, 2 Folgestunden → 9 € (nicht 6 × 25/18 = 8,33 €)
        ->and(round(app(KonfiguratorPricingService::class)->rechneFall($this->k->fresh(), $this->paket, ['typ' => 'getraenk', 'g' => $staffel->id, 't' => 'dinner'])['ek_pp'], 2))->toBe(9.0)
        ->and(($this->preis)($staffel, 'day_night'))->toBe(round((18 + 25) * 0.9, 2));

    expect(fn () => $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => $this->wein->id, 'grund_stunden' => 4]))
        ->toThrow(InvalidArgumentException::class, 'gehören zusammen');
    expect(fn () => $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => $this->wein->id, 'getraenk_abrechnung' => 'kiste']))
        ->toThrow(InvalidArgumentException::class, 'pauschale oder verbrauch');
});

it('Verschiedene Concepts je Zeitfenster: Tag und Abend = Tag-Teil + Abend-Teil mit Kombi-Rabatt', function () {
    $g = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'lunch_concept_id' => ($this->c)('Alkoholfrei', 8, 2)->id,
        'dinner_concept_id' => $this->wein->id]);
    expect(($this->preis)($g, 'lunch'))->toBe(8.0)->and(($this->preis)($g, 'dinner'))->toBe(18.0)
        ->and(($this->preis)($g, 'day_night'))->toBe(round(26 * 0.9, 2));
});

it('Kundenlink: Schritt Getränke vor den Extras, Preistabelle g…, kein EK; Anfrage addiert die Pakete, Pflicht greift, Bindung wird geprüft', function () {
    Mail::fake();
    $g1 = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => $this->wein->id, 'kunden_titel' => 'Weinbegleitung']);
    $g2 = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => ($this->c)('Softdrinks', 6, 1.5)->id, 'kunden_titel' => 'Softdrinks',
        'gebunden_an_id' => $this->haupt->id]);
    expect(fn () => $this->svc->konzeptAendern($this->rootTeam, $g2->id, ['gebunden_an_id' => $g1->id]))->toThrow(InvalidArgumentException::class, 'Hauptkonzept');
    $this->svc->aendern($this->rootTeam, $this->k->id, ['getraenke_pflicht' => true]);
    $link = app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    $body = $this->k->fresh()->presentation_snapshot_json['content'];
    expect($body['preise'][0]["g{$g1->id}:dinner"])->toEqual(18)->and($body['preise'][0]["g{$g1->id}:dn"])->toEqual(16.2)
        ->and($body['preise'][0])->not->toHaveKey("g{$g1->id}:lunch")   // nur Abend-Concept: tagsüber nicht wählbar
        ->and($body['getraenke_pflicht'])->toBeTrue()
        ->and(json_encode($body['getraenke']))->not->toContain('ek_')->not->toContain('Wein intern');

    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->getContent();
    if (getenv('FA_KONFIG_HTML_GETRAENKE')) {   // Sichtprüfung
        file_put_contents(getenv('FA_KONFIG_HTML_GETRAENKE'), $html);
    }
    expect($html)->toContain('data-konfig-zu="getraenke"')->toContain('data-konfig-getraenk-zeile="' . $g1->id . '"')->toContain('Weinbegleitung')
        ->and(strpos($html, 'data-konfig-zu="getraenke"'))->toBeLessThan(strpos($html, 'data-konfig-zu="anfrage"'))
        ->and(strpos($html, 'id="konfig-getraenke"'))->toBeGreaterThan(strpos($html, 'id="konfig-konzepte"'));
    // Druckfassung: Getränke-Abschnitt zwischen Speisen und Extras bzw. Preis, Pakete verborgen bis zur Auswahl
    $druck = \Illuminate\Support\Str::between($html, 'data-konfig-druck', '</article>');
    expect($druck)->toContain('data-druck-getraenk="' . $g1->id . '" hidden')->toContain('data-druck-feld="getraenke" hidden')
        ->and(strpos($druck, 'data-druck-abschnitt="getraenke"'))->toBeGreaterThan(strpos($druck, 'data-druck-abschnitt="speisen"'))
        ->and(strpos($druck, 'data-druck-abschnitt="getraenke"'))->toBeLessThan(strpos($druck, 'data-druck-abschnitt="preis"'))
        ->and($druck)->not->toContain('Wein intern');

    $token = $this->k->fresh()->presentation_token;
    $basis = ['name' => 'Kevin', 'email' => 'kevin@example.org', 'datenschutz' => '1', 'konzept' => (string) $this->haupt->id, 'tageszeit' => 'dinner',
        'pax' => '150', 'ts' => encrypt(now()->subSeconds(30)->timestamp), 'website' => ''];
    $this->post(route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $token]), $basis)->assertRedirectContains('anfrage=fehler');
    $this->post(route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $token]), $basis + ['getraenke' => $g1->id . ',' . $g2->id])
        ->assertRedirectContains('anfrage=ok')->assertRedirectContains('g=' . $g1->id . '.' . $g2->id);
    $a = FoodAlchemistAngebot::where('konfigurator_id', $this->k->id)->first();
    expect((float) $a->total_price)->toBe(round((50 + 18 + 6) * 150, 2))
        ->and($a->konfiguration_json['getraenke'])->toBe([$g1->id, $g2->id])->and($a->brief)->toContain('Getränke: Weinbegleitung, Softdrinks');
});

it('Layout: ein eigenes Design ohne Getränke-Block bekommt ihn vor den Extras', function () {
    $layout = PresentationDesignService::konfiguratorLayout([['block_type' => 'konfig_konzepte', 'style' => []], ['block_type' => 'konfig_addons', 'style' => []],
        ['block_type' => 'konfig_anfrage', 'style' => []]]);
    expect(array_column($layout, 'block_type'))->toBe(['konfig_konzepte', 'konfig_tageszeit', 'konfig_getraenke', 'konfig_addons', 'konfig_zusammenfassung', 'konfig_anfrage']);
});

it('Editor: Reiter Getränke zeigt Concepts der Kategorie „Getränke“ ohne Suche, fügt als Getränkepaket hinzu und speichert die Staffel', function () {
    $kat = FoodAlchemistConceptCategory::create(['team_id' => $this->rootTeam->id, 'name' => 'Getränke']);
    $this->wein->update(['category_id' => $kat->id]);
    $lw = Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id);
    expect($lw->html())->toContain('data-konfigurator-panel="getraenke"')->toContain('data-kf-getraenk-hinzu="' . $this->wein->id . '"')
        ->not->toContain('data-kf-getraenk-hinzu="' . $this->haupt->dinner_concept_id . '"');

    $lw->call('getraenkHinzu', $this->wein->id);
    $g = FoodAlchemistKonfiguratorKonzept::where('konfigurator_id', $this->k->id)->where('rolle', 'getraenk')->firstOrFail();
    $lw->call('konzeptBearbeiten', $g->id)->set('konzeptForm.grund_stunden', '4')->set('konzeptForm.folgestunde_satz', '3,5')->call('konzeptSpeichern');
    expect((float) $g->fresh()->folgestunde_satz)->toBe(3.5)->and($lw->html())->toContain('Grundpreis für 4 Std.');
});

it('Planungs-Bedarf: Zusatz-Leistung des gewählten Getränkepakets zählt mit, Concept des Zeitfensters im Kopf', function () {
    $g = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => $this->wein->id, 'kunden_titel' => 'Weinbegleitung']);
    $this->svc->leistungHinzu($this->rootTeam, $this->k->id, ['kategorie' => 'personal', 'bezeichnung' => 'Barkeeper', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste',
        'schluessel_n' => 75, 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 150, 'vk_modus' => 'fixed', 'vk_satz' => 250, 'vk_begruendung' => 'Test',
        'konfigurator_konzept_id' => $g->id, 'planung_json' => ['komponenten' => [['bezeichnung' => 'Shaker', 'menge' => 1, 'je' => 'einheit']]]]);
    $planung = app(\Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorPlanungService::class);
    $mit = $planung->bedarf($this->k->fresh(), ['konzept' => $this->haupt->id, 'tageszeit' => 'dinner', 'getraenke' => [$g->id]], 150);
    $ohne = $planung->bedarf($this->k->fresh(), ['konzept' => $this->haupt->id, 'tageszeit' => 'dinner'], 150);
    $bar = collect($mit['leistungen'])->firstWhere('bezeichnung', 'Barkeeper');
    expect($bar['anzahl'])->toEqual(2)->and($bar['planung']['komponenten'][0]['bedarf'])->toEqual(2)
        ->and($mit['kopf']['getraenke'])->toBe([$g->uuid])->and($mit['kopf']['concepts'])->toContain($this->wein->uuid)
        ->and(collect($ohne['leistungen'])->pluck('bezeichnung')->all())->not->toContain('Barkeeper');
});

