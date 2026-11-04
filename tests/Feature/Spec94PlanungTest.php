<?php

use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorLeistung;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorPlanungService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Support\LeistungPlanungText;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94b §3 · Stufe 7c Teil 2: Planungsdetails (`planung_json`) an Vorlage und Konfigurator-Leistung — Prüfung (Non-Food-GP, Behälter,
 * Menge > 0), Text-Pflege, Kopie und Abgleich aus der Vorlage, nie im Kundenlink; Bedarf aus der Anfrage am Angebot eingefroren
 * (Übergabe-Format für OLIVIA mit uuid-Verweisen) und als Lese-Ansicht im Angebot.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $this->tisch = tap($this->makeGp($this->rootTeam, 'Stehtisch: Ø 80 cm'))->update(['commodity_group_code' => '16'])->fresh();
    $this->butter = tap($this->makeGp($this->rootTeam, 'Butter: frisch'))->update(['commodity_group_code' => '06'])->fresh();
    $this->regel = ['kategorie' => 'personal', 'bezeichnung' => 'Servicekraft', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30,
        'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 200, 'vk_modus' => 'fixed', 'vk_satz' => 300, 'vk_begruendung' => 'Test'];
    $this->planung = ['komponenten' => [
        ['menge' => 1, 'je' => 'einheit', 'gp_id' => $this->tisch->id],
        ['bezeichnung' => 'Schürze', 'menge' => '2', 'je' => 'einheit'],
        ['bezeichnung' => 'Gläser', 'menge' => '0,5', 'je' => 'gast'],
    ], 'attribute' => [['schluessel' => 'Rolle', 'wert' => 'Service'], ['schluessel' => '', 'wert' => 'weg']], 'notiz_intern' => 'Geheime Notiz Hussen'];
});

it('Prüfung: Non-Food-GP mit Namen, Freitext, Mengen mit Komma; Lebensmittel-GP, Menge 0 und leere Komponente abgewiesen; leer = null', function () {
    $v = app(LeistungVorlageService::class)->anlegen($this->rootTeam, $this->regel + ['planung_json' => $this->planung]);
    expect($v->planung_json['version'])->toBe(1)
        ->and($v->planung_json['komponenten'][0])->toMatchArray(['bezeichnung' => 'Stehtisch: Ø 80 cm', 'gp_id' => $this->tisch->id])
        ->and($v->planung_json['komponenten'][2]['menge'])->toEqual(0.5)
        ->and($v->planung_json['attribute'])->toHaveCount(1)->and($v->planung_json['notiz_intern'])->toBe('Geheime Notiz Hussen');

    $falsch = fn (array $k) => fn () => app(LeistungVorlageService::class)->anlegen($this->rootTeam, $this->regel + ['planung_json' => ['komponenten' => [$k]]]);
    expect($falsch(['menge' => 1, 'je' => 'einheit', 'gp_id' => $this->butter->id]))->toThrow(InvalidArgumentException::class, 'Non-Food')
        ->and($falsch(['bezeichnung' => 'X', 'menge' => 0]))->toThrow(InvalidArgumentException::class, 'Menge größer 0')
        ->and($falsch(['menge' => 1]))->toThrow(InvalidArgumentException::class, 'Bezeichnung')
        ->and($falsch(['bezeichnung' => 'X', 'menge' => 1, 'je' => 'tag']))->toThrow(InvalidArgumentException::class, 'je Gast');
    expect(app(LeistungVorlageService::class)->anlegen($this->rootTeam, $this->regel + ['planung_json' => ['komponenten' => [], 'notiz_intern' => ' ']])->planung_json)->toBeNull();
});

it('Text-Pflege: Zeilen „Menge; je; Bezeichnung; GP 812“ und „Schlüssel; Wert; Einheit“ hin und zurück', function () {
    $roh = LeistungPlanungText::ausText(['komponenten' => "1; Einheit; ; GP {$this->tisch->id}\n0,5; je Gast; Gläser", 'attribute' => "Kapazität; 8; Gäste", 'notiz' => 'Vorbestellen']);
    expect($roh['komponenten'][0])->toMatchArray(['je' => 'einheit', 'gp_id' => $this->tisch->id])->and($roh['komponenten'][1]['je'])->toBe('gast')
        ->and($roh['attribute'][0])->toBe(['schluessel' => 'Kapazität', 'wert' => '8', 'einheit' => 'Gäste'])
        ->and(LeistungPlanungText::ausText(['komponenten' => '', 'attribute' => '', 'notiz' => '']))->toBeNull();
    $text = LeistungPlanungText::alsText(['komponenten' => [['bezeichnung' => 'Gläser', 'menge' => 0.5, 'je' => 'gast'], ['bezeichnung' => 'GN', 'menge' => 2, 'je' => 'einheit', 'container_vocab_id' => 14]],
        'attribute' => [['schluessel' => 'Rolle', 'wert' => 'Service']], 'notiz_intern' => 'N']);
    expect($text)->toBe(['komponenten' => "0,5; Gast; Gläser\n2; Einheit; GN; Behälter 14", 'attribute' => 'Rolle; Service', 'notiz' => 'N']);
});

it('Vorlage → Konfigurator: planung_json wird kopiert, eine Änderung erscheint im Abgleich; Editor-Formular speichert die Texte', function () {
    $v = app(LeistungVorlageService::class)->anlegen($this->rootTeam, $this->regel + ['planung_json' => $this->planung]);
    $k = $this->svc->anlegen($this->rootTeam, ['name' => 'Fest']);
    $this->svc->ausVorlagen($this->rootTeam, $k->id, [$v->id]);
    $l = FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->firstOrFail();
    expect($l->planung_json['komponenten'])->toHaveCount(3);

    $this->travel(1)->minutes();
    app(LeistungVorlageService::class)->aendern($this->rootTeam, $v->id, ['planung_json' => ['notiz_intern' => 'neu']]);
    expect($this->svc->vorlageGeaendert($this->rootTeam, $k->id))->toBe([(int) $l->id]);

    Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $k->id)->call('leistungBearbeiten', $l->id)
        ->assertSet('leistungPlanung.notiz', 'Geheime Notiz Hussen')
        ->set('leistungPlanung.komponenten', '3; Einheit; Schürze')->call('leistungSpeichern');
    expect($l->fresh()->planung_json['komponenten'])->toEqual([['bezeichnung' => 'Schürze', 'menge' => 3, 'je' => 'einheit']]);   // JSON: ganze Zahl kommt als int zurück
});

it('Bedarf bei 150 Gästen, Anfrage friert ihn am Angebot ein, Lese-Ansicht im Angebot; nie im Kundenlink', function () {
    Mail::fake();
    $k = $this->svc->anlegen($this->rootTeam, ['name' => 'Fest', 'kunden_titel' => 'Sommerfest', 'ort' => 'Köln', 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Culture', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 50, 'ek_per_person_cache' => 15]);
    $haupt = $this->svc->konzeptHinzu($this->rootTeam, $k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'Culture']);
    $l = $this->svc->leistungHinzu($this->rootTeam, $k->id, $this->regel + ['planung_json' => $this->planung]);
    $this->svc->paketAnlegen($this->rootTeam, $k->id, ['von_pax' => 100, 'bis_pax' => 199]);

    $b = app(KonfiguratorPlanungService::class)->bedarf($k->fresh(), ['konzept' => $haupt->id, 'tageszeit' => 'dinner'], 150);
    $zeile = $b['leistungen'][0];
    expect($b['version'])->toBe(1)->and($b['kopf'])->toMatchArray(['gaeste' => 150, 'tageszeit' => 'dinner', 'concepts' => [$c->uuid]])
        ->and($zeile)->toMatchArray(['uuid' => $l->uuid, 'kategorie' => 'personal', 'anzahl' => 5.0, 'stunden' => null])
        ->and(collect($zeile['planung']['komponenten'])->pluck('bedarf')->all())->toBe([5.0, 10.0, 75.0])
        ->and($zeile['planung']['komponenten'][0]['gp_uuid'])->toBe($this->tisch->uuid);

    app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    $json = json_encode($k->fresh()->presentation_snapshot_json);
    expect($json)->not->toContain('Geheime Notiz')->not->toContain('Schürze')->not->toContain('planung');

    auth()->logout();
    $this->post(route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $k->fresh()->presentation_token]), ['name' => 'Kevin', 'email' => 'kevin@example.org',
        'datenschutz' => '1', 'konzept' => (string) $haupt->id, 'tageszeit' => 'dinner', 'pax' => '150', 'datum' => now()->addWeek()->format('Y-m-d'),
        'ts' => encrypt(now()->subSeconds(30)->timestamp), 'website' => ''])->assertRedirectContains('anfrage=ok');
    $a = FoodAlchemistAngebot::where('konfigurator_id', $k->id)->firstOrFail();
    expect($a->konfiguration_json['planung']['kopf'])->toMatchArray(['angebot_uuid' => $a->uuid, 'ort' => 'Köln', 'gaeste' => 150])
        ->and($a->konfiguration_json['planung']['leistungen'][0]['anzahl'])->toEqual(5);

    $this->actingAs($this->user);
    Livewire::test(Editor::class)->call('oeffnen', $a->id)->assertOk()
        ->assertSee('Bedarf für die Planung')->assertSee('Gläser: 75')->assertSee('Notiz: Geheime Notiz Hussen')->assertSee('data-angebot-planung', false);
});
