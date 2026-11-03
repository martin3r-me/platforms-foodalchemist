<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Livewire\Angebote\LeistungVorlagen;
use Platform\FoodAlchemist\Livewire\Settings\Leistungskategorien;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorAufteilung;
use Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94b · Stufe 7a: Leistungskategorien als Vokabular (Team-Ausschalten) und die Aufteilung des Preises pro Person nach
 * Gruppen — intern mit EK/DB im Reiter Kalkulation, für den Kunden nur VK in „Eure Zusammenstellung“. Summe = Preis exakt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $this->kat = app(LeistungsKategorieService::class);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Gala', 'kombi_rabatt_pct' => 5, 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $c = fn (string $n, float $vk) => FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => $n, 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => $vk, 'ek_per_person_cache' => $vk / 3]);
    $this->kz = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => $c('Lunch', 40)->id,
        'dinner_concept_id' => $c('Dinner', 50)->id, 'kunden_titel' => 'Ganztag']);
    $this->paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 100]);
    $leistung = fn (array $in) => $this->svc->leistungHinzu($this->rootTeam, $this->k->id, $in + ['bezeichnung' => 'L', 'mengen_regel' => 'fest', 'anzahl' => 1,
        'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 500, 'vk_modus' => 'fixed', 'vk_satz' => 2000, 'vk_begruendung' => 'Test']);
    $leistung(['kategorie' => 'personal', 'bezeichnung' => 'Servicekraft']);                        // 2000 / 100 Gäste = 20 € pro Person
    $leistung(['kategorie' => 'equipment', 'bezeichnung' => 'Leihgabe', 'ek_satz' => 0, 'vk_satz' => 0]);   // 0 € → erscheint nicht
});

function fall94(array $matrix, string $name): array
{
    return collect($matrix['pakete'][0]['faelle'])->firstWhere('name', $name);
}

it('Kombi-Rabatt anteilig auf alle Konzept-Zeilen: Speisen 85,50 und Personal 19,00 ergeben 104,50', function () {
    $m = app(KonfiguratorPricingService::class)->matrix($this->k->fresh());
    $dn = collect($m['pakete'][0]['faelle'])->first(fn ($f) => $f['fall']['typ'] === 'day_night');
    $g = collect($dn['gruppen'])->keyBy(fn ($g) => $g['art'] === 'kategorie' ? $g['slug'] : $g['art']);

    expect($dn['preis_pp'])->toBe(104.5)
        ->and($g['speisen_tag']['vk_pp'] + $g['speisen_abend']['vk_pp'])->toBe(85.5)   // (40 + 50) × 0,95
        ->and($g['personal']['vk_pp'])->toBe(19.0)                                        // 20 × 0,95, keine negative Rabattzeile
        ->and($g->keys()->all())->toBe(['speisen_tag', 'speisen_abend', 'personal'])      // Equipment mit 0 € fehlt
        ->and(round(collect($dn['gruppen'])->sum('vk_pp'), 2))->toBe(104.5)
        ->and($g['personal']['ek_pp'])->toBe(5.0)->and($g['personal']['db_pp'])->toBe(14.0);   // EK bleibt ohne Rabatt
});

it('Rundung: jede Gruppe auf Cent, der Rest geht in die größte Gruppe — Gegenprobe ohne die Regel geht nicht auf', function () {
    $gruppen = [['art' => 'speisen_abend', 'slug' => null, 'vk_pp' => 100 / 3, 'ek_pp' => 10.0],
        ['art' => 'kategorie', 'slug' => 'personal', 'vk_pp' => 100 / 3, 'ek_pp' => 5.0],
        ['art' => 'kategorie', 'slug' => 'logistik', 'vk_pp' => 100 / 3, 'ek_pp' => 2.0]];
    $teile = KonfiguratorAufteilung::aufteilen($gruppen, 100.0, ['personal' => 'Personal', 'logistik' => 'Logistik']);

    expect(round(array_sum(array_column($teile, 'vk_pp')), 2))->toBe(100.0)
        ->and(array_column($teile, 'name'))->toBe(['Speisen Abend', 'Personal', 'Logistik']);
    // Gegenprobe: nur auf Cent gerundet (ohne Rest-Regel) ergibt 99,99 statt 100,00
    expect(round(array_sum(array_map(fn ($g) => round($g['vk_pp'], 2), $gruppen)), 2))->not->toBe(100.0);

    // Preisrundung des Konfigurators (z. B. auf 0,50 €): der Rest landet ebenfalls in der größten Gruppe
    $teile = KonfiguratorAufteilung::aufteilen([['art' => 'speisen_tag', 'slug' => null, 'vk_pp' => 61.23, 'ek_pp' => 0.0],
        ['art' => 'kategorie', 'slug' => 'personal', 'vk_pp' => 12.04, 'ek_pp' => 0.0]], 73.5, ['personal' => 'Personal']);
    expect(array_column($teile, 'vk_pp'))->toBe([61.46, 12.04]);
});

it('Kundenlink: Aufteilung je Preisschlüssel (auch Tag und Abend), Summe = Preis; nur Name und VK, kein EK/DB/Menge/Regel', function () {
    app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    $c = $this->k->fresh()->presentation_snapshot_json['content'];

    $schluessel = array_keys($c['preise'][0]);
    expect($schluessel)->toContain('k' . $this->kz->id . ':lunch')->toContain('dn' . $this->kz->id . ':' . $this->kz->id);
    foreach ($c['aufteilung'][0] as $key => $teile) {
        expect(round(array_sum(array_column($teile, 'vk')), 2))->toBe((float) $c['preise'][0][$key], "Summe {$key}");
        foreach ($teile as $t) {
            expect(array_keys($t))->toBe(['name', 'vk']);
        }
    }
    expect(array_column($c['aufteilung'][0]['dn' . $this->kz->id . ':' . $this->kz->id], 'name'))->toBe(['Speisen Tag', 'Speisen Abend', 'Personal']);
    $json = json_encode($c['aufteilung']);
    foreach (['ek', 'db', 'menge', 'anzahl', 'regel', 'Servicekraft'] as $verboten) {
        expect($json)->not->toContain('"' . $verboten)->not->toContain($verboten . '"');
    }

    auth()->logout();
    $html = $this->get(route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $this->k->fresh()->presentationPublicRef()]))->assertOk()->getContent();
    expect($html)->toContain('data-konfig-aufteilung')->toContain('"aufteilung"');
    if (getenv('FA_KONFIG_HTML_AUFTEILUNG')) {
        file_put_contents(getenv('FA_KONFIG_HTML_AUFTEILUNG'), $html);
    }
});

it('Editor: Zusammenfassung nach Gruppen im Reiter Kalkulation mit EK und DB', function () {
    Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)
        ->assertSeeHtml('data-kf-zusammenfassung="' . $this->paket->id . '-0"')
        ->assertSeeHtml('data-kf-gruppe="personal"')
        ->assertDontSeeHtml('data-kf-gruppe="equipment"')
        ->assertSee('Zusammenfassung nach Gruppen');
});

it('Kategorien: vier global, Unterteam ergänzt eigene und schaltet geerbte für sich aus; Bestand bleibt gültig', function () {
    expect(array_keys($this->kat->auswahl($this->rootTeam)))->toBe(['personal', 'equipment', 'logistik', 'sonstiges']);

    $technik = $this->kat->anlegen($this->childA, 'Technik');
    $logistik = $this->kat->liste($this->childA)->first(fn ($e) => $e->kategorie->slug === 'logistik')->kategorie;
    $this->kat->schalten($this->childA, $logistik->id, false);
    expect(array_keys($this->kat->auswahl($this->childA)))->toBe(['personal', 'equipment', 'sonstiges', 'technik'])
        ->and(array_keys($this->kat->auswahl($this->rootTeam)))->toContain('logistik')->not->toContain('technik')   // Oberteam merkt nichts
        ->and(array_keys($this->kat->auswahl($this->childB)))->toContain('logistik')->not->toContain('technik');   // Geschwister auch nicht

    // geerbte kann das Unterteam nicht umbenennen
    expect(fn () => $this->kat->aendern($this->childA, $logistik->id, ['name' => 'Transport']))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::class);

    // Bestand mit ausgeschalteter Kategorie bleibt beim Ändern gültig, neu anlegen damit geht nicht
    $this->actingAs($this->makeUser($this->childA, 'A'));
    $vorlagen = app(LeistungVorlageService::class);
    $basis = ['bezeichnung' => 'LKW', 'mengen_regel' => 'fest', 'anzahl' => 1, 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 100];
    expect(fn () => $vorlagen->anlegen($this->childA, $basis + ['kategorie' => 'logistik']))->toThrow(InvalidArgumentException::class, 'nicht wählbar');
    $this->kat->schalten($this->childA, $logistik->id, true);
    $v = $vorlagen->anlegen($this->childA, $basis + ['kategorie' => 'logistik']);
    $this->kat->schalten($this->childA, $logistik->id, false);
    expect($vorlagen->aendern($this->childA, $v->id, ['ek_satz' => 120])->kategorie)->toBe('logistik');

    // eigene Kategorie: in der Vorlagen-Liste wählbar
    Livewire::test(LeistungVorlagen::class)->call('neu', 'technik')->assertSet('form.kategorie', 'technik');
});

it('Einstellungsseite: anlegen, umbenennen, für das Team ausschalten', function () {
    $t = Livewire::test(Leistungskategorien::class)
        ->assertSee('Personal')->assertSeeHtml('data-lk-zeile="logistik"')
        ->set('neuName', 'Floristik')->call('create')->assertSet('fehler', null)->assertSee('Floristik');
    $id = collect($this->kat->liste($this->rootTeam))->first(fn ($e) => $e->kategorie->slug === 'floristik')->kategorie->id;
    $t->call('edit', $id)->set('form.name', 'Blumen und Deko')->call('save')->assertSet('fehler', null)->assertSee('Blumen und Deko')
        ->call('schalten', $id, false);
    expect(array_keys($this->kat->auswahl($this->rootTeam)))->not->toContain('floristik');
    $t->set('neuName', 'Personal')->call('create')->assertSet('fehler', 'Diese Kategorie gibt es schon.');
});
