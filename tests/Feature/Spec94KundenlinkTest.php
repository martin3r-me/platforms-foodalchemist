<?php

use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorPraesentation;
use Platform\FoodAlchemist\Mail\KonfiguratorAnfrageMail;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 Stufe 3 · Kundenlink: Snapshot ohne Kalkulation (Leak-Test), öffentliche Seite, Anfrage → Angebot + Mail + Signal,
 * Schutz der Anfrage (Honeypot, fremde IDs, zurückgezogener Link), Signal schließt mit dem Status, Design-Layout.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $svc = app(KonfiguratorService::class);
    $this->k = $svc->anlegen($this->rootTeam, ['name' => 'Gala intern', 'kunden_titel' => 'Diva.licious', 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Culture', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 50, 'ek_per_person_cache' => 15]);
    $snack = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Snack', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 6, 'ek_per_person_cache' => 2]);
    $this->haupt = $svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'Culture']);
    $this->addon = $svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'addon', 'concept_id' => $snack->id, 'kunden_titel' => 'Mitternachtssnack', 'abschnitt' => 'Snacks']);
    $svc->leistungHinzu($this->rootTeam, $this->k->id, ['kategorie' => 'personal', 'bezeichnung' => 'Geheime Servicekraft', 'mengen_regel' => 'schluessel',
        'bezug' => 'gaeste', 'schluessel_n' => 30, 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 200, 'vk_modus' => 'fixed', 'vk_satz' => 300, 'vk_begruendung' => 'Test']);
    $svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $this->link = app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
});

function anfrage94(array $mehr = []): array
{
    return $mehr + ['name' => 'Kevin Muster', 'email' => 'kevin@example.org', 'datenschutz' => '1', 'tageszeit' => 'dinner', 'pax' => '150',
        'ts' => encrypt(now()->subSeconds(30)->timestamp), 'website' => ''];
}

it('Snapshot: nur Preistabelle, keine Kalkulation (EK, Klassen, Mengen, Leistungszeilen)', function () {
    $snap = $this->k->fresh()->presentation_snapshot_json;
    $json = json_encode($snap);
    expect($snap['type'])->toBe('konfigurator')
        ->and($json)->not->toContain('Geheime Servicekraft')->not->toContain('ek_')->not->toContain('markup')->not->toContain('anzahl')
        ->and($snap['content']['preise'][0]['k' . $this->haupt->id . ':dinner'])->toBe(round(50 + 5 * 300 / 149, 2))
        ->and(collect($snap['resolved_design']['layout'])->pluck('block_type')->intersect(PresentationDesignService::KONFIG_BLOCKS)->count())->toBe(5);
});

it('öffentliche Seite rendert den Konfigurator', function () {
    auth()->logout();
    $this->get($this->link['url'])->assertOk()->assertSee('Diva.licious')->assertSee('Culture')->assertSee('Mitternachtssnack')->assertSee('pt-konfig-daten', false)
        ->assertSee('pt-konfig-nr', false)->assertDontSee('Schritt 4');   // Nummern fortlaufend per CSS-Zähler, nie fest im HTML
});

it('Anfrage: Angebot mit Status anfrage, Preis aus der Tabelle, Mail an die verantwortliche Person, Signal; Status weiter schließt es', function () {
    Mail::fake();
    auth()->logout();
    $token = $this->k->fresh()->presentation_token;
    $this->post(route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $token]),
        anfrage94(['konzept' => (string) $this->haupt->id, 'addons' => (string) $this->addon->id, 'total_price' => '1']))
        ->assertRedirect()->assertRedirectContains('anfrage=ok')
        ->assertRedirectContains('#k=' . $this->haupt->id . '&t=dinner&a=' . $this->addon->id . '&p=150&s=anfrage');   // Auswahl bleibt nach dem Absenden

    $a = FoodAlchemistAngebot::where('konfigurator_id', $this->k->id)->first();
    $pp = round(50 + 5 * 300 / 149, 2) + 6 + round(0 / 149, 2);
    expect($a)->not->toBeNull()->and($a->status->value)->toBe('anfrage')->and($a->personen)->toBe(150)
        ->and((float) $a->total_price)->toBe(round($pp * 150, 2))
        ->and($a->konfiguration_json['kontakt']['email'])->toBe('kevin@example.org');
    Mail::assertSent(KonfiguratorAnfrageMail::class, fn ($m) => $m->hasTo($this->user->email));
    $signal = FoodAlchemistSignal::where('type', SignalTyp::KonfiguratorAnfrage->value)->where('ref_id', $a->id)->first();
    expect($signal?->status->value ?? $signal?->status)->toBe('offen');

    $this->actingAs($this->user);
    app(AngebotService::class)->setStatus($this->rootTeam, $a->id, 'in_arbeit');
    expect($signal->fresh()->status->value ?? $signal->fresh()->status)->not->toBe('offen');
});

it('Anfrage-Schutz: Honeypot und fremde IDs legen nichts an, zurückgezogener Link nimmt nichts an', function () {
    auth()->logout();
    $token = $this->k->fresh()->presentation_token;
    $ziel = route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $token]);

    $this->post($ziel, anfrage94(['konzept' => (string) $this->haupt->id, 'website' => 'http://spam.example']))->assertRedirectContains('anfrage=ok');
    $this->post($ziel, anfrage94(['konzept' => '999999']))->assertRedirectContains('anfrage=fehler');
    $this->post($ziel, anfrage94(['konzept' => (string) $this->haupt->id, 'pax' => '5000']))->assertRedirectContains('anfrage=fehler');
    expect(FoodAlchemistAngebot::where('konfigurator_id', $this->k->id)->count())->toBe(0);

    $this->actingAs($this->user);
    app(PresentationService::class)->withdraw($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id);
    auth()->logout();
    $this->post($ziel, anfrage94(['konzept' => (string) $this->haupt->id]))->assertNotFound();
    $this->get($this->link['url'])->assertNotFound();
});

it('Editor-Reiter: Branding speichern, aus einem Foodbook übernehmen, veröffentlichen', function () {
    $fb = app(\Platform\FoodAlchemist\Services\FoodbookService::class)->create($this->rootTeam, ['label' => 'FB']);
    $fb->update(['brand_color' => '#112233', 'footer_text' => 'DOEC Catering']);

    Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])
        ->set('quelle', 'foodbook')->set('quelleId', (string) $fb->id)->call('brandingUebernehmen')
        ->assertSet('fehler', null)->assertSet('branding.brand_color', '#112233')
        ->set('branding.band_color', 'rot')->call('brandingSpeichern')->assertSet('fehler', 'Bandfarbe: bitte als #RRGGBB angeben.')
        ->set('branding.band_color', '')->call('veroeffentlichen')->assertSet('fehler', null);
    expect($this->k->fresh()->footer_text)->toBe('DOEC Catering')->and($this->k->fresh()->status)->toBe('live');
});

it('Design: jedes Layout bekommt die fünf Konfigurator-Schritte, fremde Blöcke fallen weg', function () {
    $l = PresentationDesignService::konfiguratorLayout([['block_type' => 'cover', 'style' => []], ['block_type' => 'chapter_loop', 'style' => []],
        ['block_type' => 'konfig_anfrage', 'style' => []], ['block_type' => 'konfig_anfrage', 'style' => []]]);
    expect(array_column($l, 'block_type'))->toBe(['cover', 'konfig_anfrage', 'konfig_konzepte', 'konfig_tageszeit', 'konfig_addons', 'konfig_zusammenfassung']);
    expect(collect(app(PresentationDesignService::class)->pickerOptions($this->rootTeam, 'konfigurator'))->pluck('value'))->toContain('konfigurator');
});
