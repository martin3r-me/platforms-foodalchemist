<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94b · Stufe 7b: Einzelabrechnung nach Aufwand — nicht im Preis pro Person (auch nicht im Kombi-Rabatt), Satz = VK-Satz,
 * Menge geschätzt aus der Mengenregel an der Referenz-Pax (× Stunden bei Preis je Stunde); intern im Reiter Kalkulation,
 * im Kundenlink „zzgl. nach Aufwand: ca. …“ mit Satz, Einheit, Menge und Betrag — ohne EK und ohne Regel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Gala', 'kombi_rabatt_pct' => 5, 'stunden_lunch' => 6, 'stunden_dinner' => 7, 'stunden_day_night' => 14,
        'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $c = fn (string $n, float $vk) => FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => $n, 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => $vk, 'ek_per_person_cache' => $vk / 3]);
    $this->kz = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => $c('Lunch', 40)->id,
        'dinner_concept_id' => $c('Dinner', 50)->id, 'kunden_titel' => 'Ganztag']);
    $this->paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 100]);
    // All-in: Servicekraft fest 1 × 2000 → 20 € pro Person. Aufwand: Garderobe 1 je 50 Gäste, je Stunde (Einsatzzeit), 45 € VK
    $basis = ['mengen_regel' => 'fest', 'anzahl' => 1, 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'vk_modus' => 'fixed', 'vk_begruendung' => 'Test'];
    $this->svc->leistungHinzu($this->rootTeam, $this->k->id, $basis + ['kategorie' => 'personal', 'bezeichnung' => 'Servicekraft', 'ek_satz' => 500, 'vk_satz' => 2000]);
    $this->garderobe = $this->svc->leistungHinzu($this->rootTeam, $this->k->id, ['kategorie' => 'personal', 'bezeichnung' => 'Garderobe', 'mengen_regel' => 'schluessel',
        'bezug' => 'gaeste', 'schluessel_n' => 50, 'preis_je' => 'stunde', 'stunden_quelle' => 'einsatzzeit', 'haeufigkeit' => 'je_event',
        'ek_satz' => 25, 'vk_modus' => 'fixed', 'vk_satz' => 45, 'vk_begruendung' => 'Test', 'abrechnung' => 'aufwand']);
});

function fall94b(array $m, string $typ, ?string $t = null): array
{
    return collect($m['pakete'][0]['faelle'])->first(fn ($f) => $f['fall']['typ'] === $typ && ($t === null || ($f['fall']['t'] ?? null) === $t));
}

it('Aufwand zählt nicht in den Preis pro Person, steht getrennt mit geschätzter Menge (Regel × Stunden) und Satz = VK', function () {
    $m = app(KonfiguratorPricingService::class)->matrix($this->k->fresh());
    $abend = fall94b($m, 'einzel', 'dinner');
    expect($abend['preis_pp'])->toBe(70.0)   // 50 Speisen + 20 Servicekraft, ohne Garderobe
        ->and(collect($abend['gruppen'])->sum('vk_pp'))->toBe(70.0)
        ->and($abend['einzel'])->toHaveCount(1)
        ->and($abend['einzel'][0])->toMatchArray(['bezeichnung' => 'Garderobe', 'einheit' => 'Std.', 'satz' => 45.0, 'menge' => 14.0, 'betrag' => 630.0, 'ek_betrag' => 350.0]);   // 2 Kräfte × 7 Std.

    // Tag und Abend: Kombi-Rabatt nur auf den All-in-Teil, die Schätzung läuft über die Day-into-Night-Einsatzzeit (2 × 14 Std.)
    $dn = fall94b($m, 'day_night');
    expect($dn['preis_pp'])->toBe(104.5)->and($dn['einzel'][0]['menge'])->toBe(28.0)->and($dn['einzel'][0]['betrag'])->toBe(1260.0);
});

it('Einheit aus dem Vokabular, Prüfung von Abrechnung und Einheit; Vorlage überträgt die Abrechnung', function () {
    $gast = FoodAlchemistVocabEinheit::create(['team_id' => null, 'slug' => 'gast94b', 'display_de' => 'Gast']);
    $this->svc->leistungAendern($this->rootTeam, $this->garderobe->id, ['abrechnung_einheit_vocab_id' => $gast->id]);
    expect(fall94b(app(KonfiguratorPricingService::class)->matrix($this->k->fresh()), 'einzel', 'dinner')['einzel'][0]['einheit'])->toBe('Gast');

    expect(fn () => $this->svc->leistungAendern($this->rootTeam, $this->garderobe->id, ['abrechnung' => 'pauschal']))->toThrow(InvalidArgumentException::class, 'Aufwand');
    $fremd = FoodAlchemistVocabEinheit::create(['team_id' => $this->childB->id, 'slug' => 'fremd94b', 'display_de' => 'Fremd']);
    expect(fn () => $this->svc->leistungAendern($this->rootTeam, $this->garderobe->id, ['abrechnung_einheit_vocab_id' => $fremd->id]))->toThrow(InvalidArgumentException::class, 'Einheit');

    // zurück auf All-in: Einheit wird geleert, die Leistung zählt wieder in den Preis
    $l = $this->svc->leistungAendern($this->rootTeam, $this->garderobe->id, ['abrechnung' => 'all_in']);
    expect($l->abrechnung_einheit_vocab_id)->toBeNull()
        ->and(fall94b(app(KonfiguratorPricingService::class)->matrix($this->k->fresh()), 'einzel', 'dinner')['einzel'])->toBe([]);

    $v = app(LeistungVorlageService::class)->anlegen($this->rootTeam, ['kategorie' => 'logistik', 'bezeichnung' => 'Sonderfahrt', 'mengen_regel' => 'fest', 'anzahl' => 1,
        'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 80, 'abrechnung' => 'aufwand']);
    $kopie = collect($this->svc->ausVorlagen($this->rootTeam, $this->k->id, [$v->id])['leistungen'])->first();
    expect($kopie->fresh()->abrechnung)->toBe('aufwand');
});

it('Kundenlink: zzgl. nach Aufwand je Preisschlüssel mit Titel, Satz, Einheit, Menge, Betrag — ohne EK und Regel; Preis unverändert', function () {
    app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
    $c = $this->k->fresh()->presentation_snapshot_json['content'];
    $abend = 'k' . $this->kz->id . ':dinner';

    expect($c['preise'][0][$abend])->toEqual(70.0)   // Snapshot ist JSON: ganze Zahlen kommen als int zurück
        ->and($c['zzgl'][0][$abend])->toEqual([['titel' => 'Garderobe', 'satz' => 45.0, 'einheit' => 'Std.', 'menge' => 14.0, 'betrag' => 630.0]])
        ->and($c['zzgl'][0]['dn' . $this->kz->id . ':' . $this->kz->id][0]['menge'])->toEqual(28.0)
        ->and(collect($c['aufteilung'][0][$abend])->pluck('name')->all())->not->toContain('Garderobe');
    $json = json_encode($c['zzgl']);
    foreach (['ek', 'ek_satz', 'ek_betrag', 'schluessel_n', 'mengen_regel', 'markup'] as $verboten) {
        expect($json)->not->toContain('"' . $verboten . '"');
    }

    auth()->logout();
    $html = $this->get(route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $this->k->fresh()->presentationPublicRef()]))->assertOk()->getContent();
    expect($html)->toContain('data-konfig-zzgl')->toContain('data-konfig-zzgl-leiste')->toContain('zzgl. nach Aufwand');
    if (getenv('FA_KONFIG_HTML_ZZGL')) {
        file_put_contents(getenv('FA_KONFIG_HTML_ZZGL'), $html);
    }
});

it('Editor: Abrechnung im Leistungsformular, Einzelabrechnung im Reiter Kalkulation; MCP setzt die Abrechnung', function () {
    Livewire::test(KonfiguratorEditor::class)->call('oeffnen', $this->k->id)
        ->assertSeeHtml('data-kf-einzel="' . $this->paket->id . '-')
        ->assertSee('Zzgl. nach Aufwand')
        ->call('leistungBearbeiten', $this->garderobe->id)
        ->assertSeeHtml('data-regel-abrechnung');

    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.konfigurator_leistungen.POST');
    $r = $tool->execute(['konfigurator_id' => $this->k->id, 'kategorie' => 'logistik', 'bezeichnung' => 'Shuttle', 'mengen_regel' => 'fest', 'anzahl' => 2,
        'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 90, 'vk_modus' => 'fixed', 'vk_satz' => 150, 'vk_begruendung' => 'Test', 'abrechnung' => 'aufwand'],
        new \Platform\Core\Contracts\ToolContext($this->user, $this->rootTeam));
    expect($r->success)->toBeTrue($r->error ?? '');
    $einzel = fall94b(app(KonfiguratorPricingService::class)->matrix($this->k->fresh()), 'einzel', 'dinner')['einzel'];
    expect(collect($einzel)->pluck('bezeichnung')->all())->toContain('Shuttle');
});
