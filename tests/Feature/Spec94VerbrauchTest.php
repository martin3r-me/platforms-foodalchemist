<?php

use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94c · Stufe 8c: Getränkepaket nach Verbrauch auf dem Aufwands-Mechanismus — Schätzung = Concept-Preis je Gast (je Event, je Stunde
 * oder Staffel wie die Pauschale) × Gäste, nie im Preis pro Person; optionaler Deckel begrenzt sie (Link, Druck, Angebots-Position mit
 * `payload_json.art = verbrauch` und `deckel`). Anfrage → Position price_basis aufwand „nach Verbrauch“, kein neuer Rechenweg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $c = fn (string $name, float $vk, float $ek) => FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => $name, 'kind' => 'concept',
        'status' => 'active', 'price_per_person_cache' => $vk, 'ek_per_person_cache' => $ek]);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Fest', 'kunden_titel' => 'Sommerfest', 'stunden_lunch' => 4, 'stunden_dinner' => 6,
        'stunden_day_night' => 10, 'kombi_rabatt_pct' => 10, 'gueltig_bis' => now()->addMonth()->format('Y-m-d'), 'verantwortlich_user_id' => $this->user->id]);
    $this->haupt = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c('Abend', 50, 15)->id, 'kunden_titel' => 'Culture']);
    $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $this->paket = $this->k->fresh()->pakete->first();
    $this->wein = $c('Wein intern', 18, 6);
    $this->g = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'getraenk', 'concept_id' => $this->wein->id, 'kunden_titel' => 'Weinbegleitung',
        'getraenk_abrechnung' => 'verbrauch', 'verbrauch_deckel' => '2000']);
    $this->fall = fn (?int $pax = 150) => app(KonfiguratorPricingService::class)->rechneFall($this->k->fresh(), $this->paket,
        ['typ' => 'getraenk', 'g' => $this->g->id, 't' => 'dinner'], $pax);
});

it('Preis: Verbrauch nie im Preis pro Person, Schätzung = Preis je Gast × Gäste in der Einzelabrechnung, Deckel begrenzt (EK anteilig)', function () {
    $f = ($this->fall)();
    $zeile = collect($f['einzel'])->firstWhere('art', 'verbrauch');
    expect($f['vk_pp'])->toEqual(0)->and(collect($f['gruppen'])->pluck('art')->all())->not->toContain('getraenke')
        ->and($zeile)->toMatchArray(['getraenk_id' => $this->g->id, 'bezeichnung' => 'Weinbegleitung', 'einheit' => 'Gäste', 'satz' => 18.0, 'menge' => 150.0,
            'deckel' => 2000.0, 'betrag' => 2000.0, 'ek_betrag' => round(900 * 2000 / 2700, 2)]);   // 18 × 150 = 2.700 → höchstens 2.000

    $this->svc->konzeptAendern($this->rootTeam, $this->g->id, ['verbrauch_deckel' => '', 'getraenk_dauer' => 'stunde']);
    expect(collect(($this->fall)(100)['einzel'])->firstWhere('art', 'verbrauch'))->toMatchArray(['satz' => 108.0, 'betrag' => 10800.0, 'deckel' => null]);   // 18 × 6 Std. × 100

    $this->svc->konzeptAendern($this->rootTeam, $this->g->id, ['verbrauch_deckel' => 500, 'getraenk_abrechnung' => 'pauschale']);
    expect($this->g->fresh()->verbrauch_deckel)->toBeNull();   // Deckel nur bei Verbrauch
    expect(fn () => $this->svc->konzeptAendern($this->rootTeam, $this->g->id, ['getraenk_abrechnung' => 'verbrauch', 'verbrauch_deckel' => 0]))
        ->toThrow(InvalidArgumentException::class, 'größer 0');
});

it('Kundenlink: Preisschlüssel ohne Aufschlag, zzgl.-Zeile art verbrauch mit Satz je Gast und Deckel, keine Mengen je Gast, kein EK', function () {
    $link = app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    $body = $this->k->fresh()->presentation_snapshot_json['content'];
    $zz = $body['zzgl'][0]["g{$this->g->id}:dinner"];
    expect($body['preise'][0]["g{$this->g->id}:dinner"])->toEqual(0)
        ->and($zz)->toEqual([['titel' => 'Weinbegleitung', 'satz' => 18.0, 'einheit' => 'Gäste', 'menge' => 149.0, 'betrag' => 2000.0, 'art' => 'verbrauch', 'deckel' => 2000.0]])
        ->and(collect($body['getraenke'])->firstWhere('id', $this->g->id)['verbrauch'])->toBeTrue()
        ->and(json_encode($body))->not->toContain('ek_')->not->toContain('Wein intern');

    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->getContent();
    expect($html)->toContain('data-konfig-zzgl-titel')->toContain('nach Verbrauch, ca. ')->toContain('höchstens ')
        ->toContain('Aufwand bzw. Verbrauch');
});

it('Anfrage: Verbrauch nicht im Gesamtpreis, Position aufwand „nach Verbrauch“ mit Deckel; Angebot rechnet höchstens den Deckel', function () {
    Mail::fake();
    app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    auth()->logout();
    $this->post(route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $this->k->fresh()->presentation_token]), ['name' => 'Kevin',
        'email' => 'kevin@example.org', 'datenschutz' => '1', 'konzept' => (string) $this->haupt->id, 'tageszeit' => 'dinner', 'pax' => '150',
        'getraenke' => (string) $this->g->id, 'ts' => encrypt(now()->subSeconds(30)->timestamp), 'website' => ''])->assertRedirectContains('anfrage=ok');

    $a = FoodAlchemistAngebot::where('konfigurator_id', $this->k->id)->firstOrFail();
    $pos = FoodAlchemistOfferBlock::whereHas('chapter', fn ($q) => $q->where('offer_id', $a->id))->where('type', 'position')->firstOrFail();
    expect((float) $a->total_price)->toBe(7500.0)   // 50 × 150, Getränke nicht im Preis
        ->and($a->brief)->toContain('Weinbegleitung nach Verbrauch ≈ 2.000,00 € (höchstens 2.000,00 €)')
        ->and($a->konfiguration_json['zzgl'][0])->toMatchArray(['art' => 'verbrauch', 'deckel' => 2000.0, 'betrag' => 2000.0])
        ->and($pos->price_basis)->toBe('aufwand')->and((float) $pos->quantity)->toBe(150.0)->and((float) $pos->price_value)->toBe(18.0)
        ->and($pos->payload_json)->toMatchArray(['art' => 'verbrauch', 'deckel' => 2000, 'getraenk_id' => $this->g->id])
        ->and($pos->istVerbrauch())->toBeTrue()->and($pos->positionBetrag())->toBe(2000.0)
        ->and($pos->chapter->title)->toBe('Nach Verbrauch')->and($pos->customer_text)->toContain('höchstens 2.000,00 €');

    $this->actingAs($this->user);
    $kalk = app(AngebotService::class)->kalkulation($this->rootTeam, $a->fresh());
    expect($kalk['aufwand']['vk'])->toEqual(2000)->and($kalk['aufwand']['ek'])->toEqual(round(900 * 2000 / 2700, 2));
    Livewire::test(Editor::class)->call('oeffnen', $a->id)->call('kapitelWaehle', $pos->chapter_id)->assertOk()
        ->assertSee('zzgl. nach Verbrauch')->assertSeeHtml('data-angebot-position-deckel');
});

it('Editor: Abrechnung „nach Verbrauch“ und Höchstbetrag im Reiter Getränke, Kalkulation zeigt die Schätzung', function () {
    $this->svc->konzeptAendern($this->rootTeam, $this->g->id, ['getraenk_abrechnung' => 'pauschale']);
    $lw = Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)->call('konzeptBearbeiten', $this->g->id)
        ->assertSee('data-kfg-abrechnung', false)->assertSee('data-kfg-deckel', false)
        ->set('konzeptForm.getraenk_abrechnung', 'verbrauch')->set('konzeptForm.verbrauch_deckel', '1500')->call('konzeptSpeichern');
    expect($this->g->fresh()->getraenk_abrechnung)->toBe('verbrauch')->and((float) $this->g->fresh()->verbrauch_deckel)->toBe(1500.0)
        ->and($lw->html())->toContain('Nach Verbrauch, höchstens')->toContain('bei 149 Gästen');
});
