<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaketMenge;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorMengenRechner;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 · Rechenlogik des Konfigurators (Testfälle aus der Spec). Die Tests prüfen die Logik, nicht die DOEC-Excel.
 * Leistungen mit festem VK, damit kein Basissatz hineinspielt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->team = $this->rootTeam;
    $this->svc = app(KonfiguratorService::class);
    $this->pricing = app(KonfiguratorPricingService::class);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Test', 'stunden_lunch' => 6, 'stunden_dinner' => 7, 'stunden_day_night' => 14]);
});

function k94Concept(int $teamId, float $vk, float $ek): FoodAlchemistConcept
{
    return FoodAlchemistConcept::create(['team_id' => $teamId, 'name' => 'C ' . $vk, 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => $vk, 'ek_per_person_cache' => $ek]);
}

/** Leistung mit festem VK; Standard: fest 1 Stück. */
function k94Leistung($test, array $in): \Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorLeistung
{
    return $test->svc->leistungHinzu($test->team, $test->k->id, $in + [
        'kategorie' => 'personal', 'bezeichnung' => 'L', 'mengen_regel' => 'fest', 'anzahl' => 1, 'preis_je' => 'stueck',
        'haeufigkeit' => 'je_event', 'ek_satz' => 10, 'vk_modus' => 'fixed', 'vk_satz' => 20, 'vk_begruendung' => 'Test',
    ]);
}

function k94Anzahl(int $paketId, int $leistungId, ?int $konzeptId = null): ?float
{
    $z = FoodAlchemistKonfiguratorPaketMenge::where('paket_id', $paketId)->where('leistung_id', $leistungId)
        ->where('konfigurator_konzept_id', $konzeptId)->first();

    return $z !== null ? (float) $z->anzahl : null;
}

it('Mengenregeln: fest, Schlüssel mit Min/Max/Reserve/Aufrunden, Stufen, Aktivbereich', function () {
    $r = new KonfiguratorMengenRechner;
    $l = fn (array $w) => (object) ($w + ['mengen_regel' => 'fest', 'anzahl' => null, 'bezug' => null, 'bezug_leistung_id' => null, 'schluessel_n' => null,
        'min' => null, 'max' => null, 'ab_pax' => null, 'bis_pax' => null, 'stufen_json' => null, 'reserve_pct' => null]);

    expect($r->regelAnzahl($l(['anzahl' => 2]), 150))->toBe(2.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30]), 150))->toBe(5.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30]), 151))->toBe(6.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 200, 'min' => 2]), 50))->toBe(2.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 250, 'max' => 3]), 1000))->toBe(3.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 1, 'reserve_pct' => 10]), 100))->toBe(110.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'stufen', 'stufen_json' => [['bis_pax' => 99, 'anzahl' => 1], ['bis_pax' => null, 'anzahl' => 3]]]), 99))->toBe(1.0)
        ->and($r->regelAnzahl($l(['mengen_regel' => 'stufen', 'stufen_json' => [['bis_pax' => 99, 'anzahl' => 1], ['bis_pax' => null, 'anzahl' => 3]]]), 100))->toBe(3.0)
        ->and($r->regelAnzahl($l(['anzahl' => 1, 'ab_pax' => 200]), 199))->toBe(0.0)
        ->and($r->regelAnzahl($l(['anzahl' => 1, 'ab_pax' => 200]), 200))->toBe(1.0)
        ->and($r->regelAnzahl($l(['anzahl' => 1, 'bis_pax' => 99]), 100))->toBe(0.0);
});

it('Bezugskette: Teamleitung je 8 Servicekräfte; ein Kreis wird abgelehnt', function () {
    $service = k94Leistung($this, ['bezeichnung' => 'Service 1:30', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30]);
    $leitung = k94Leistung($this, ['bezeichnung' => 'Teamleitung', 'mengen_regel' => 'schluessel', 'bezug' => 'leistung', 'bezug_leistung_id' => $service->id, 'schluessel_n' => 8]);
    $paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 300, 'bis_pax' => 300]);

    expect(k94Anzahl($paket->id, $service->id))->toBe(10.0)->and(k94Anzahl($paket->id, $leitung->id))->toBe(2.0);

    expect(fn () => $this->svc->leistungAendern($this->rootTeam, $service->id, ['mengen_regel' => 'schluessel', 'bezug' => 'leistung', 'bezug_leistung_id' => $leitung->id, 'schluessel_n' => 1]))
        ->toThrow(InvalidArgumentException::class, 'Kreis');
});

it('Ersetzung: Tischservice ersetzt Buffetservice nur bei „Fire“, die Teamleitung rechnet mit dem Ersatz', function () {
    $c = k94Concept($this->rootTeam->id, 50, 15);
    $buffet = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'Buffet']);
    $fire = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'Fire']);
    $bs = k94Leistung($this, ['bezeichnung' => 'Buffetservice 1:30', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30]);
    $tl = k94Leistung($this, ['bezeichnung' => 'Teamleitung 1:8', 'mengen_regel' => 'schluessel', 'bezug' => 'leistung', 'bezug_leistung_id' => $bs->id, 'schluessel_n' => 8]);
    $ts = k94Leistung($this, ['bezeichnung' => 'Tischservice 1:12', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 12,
        'konfigurator_konzept_id' => $fire->id, 'ersetzt_leistung_id' => $bs->id]);
    $paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 120, 'bis_pax' => 120]);

    expect(k94Anzahl($paket->id, $bs->id))->toBe(4.0)->and(k94Anzahl($paket->id, $tl->id))->toBe(1.0)
        ->and(k94Anzahl($paket->id, $ts->id, $fire->id))->toBe(10.0)
        ->and(k94Anzahl($paket->id, $tl->id, $fire->id))->toBe(2.0);

    // Buffet: 50 + (4 Service + 1 TL) × 20 / 120; Fire: 50 + (10 Tischservice + 2 TL) × 20 / 120
    $buffetPreis = $this->pricing->rechneFall($this->k, $paket, ['typ' => 'einzel', 'k' => $buffet->id, 't' => 'dinner']);
    $firePreis = $this->pricing->rechneFall($this->k, $paket, ['typ' => 'einzel', 'k' => $fire->id, 't' => 'dinner']);
    expect(round($buffetPreis['vk_pp'], 4))->toBe(round(50 + 5 * 20 / 120, 4))
        ->and(round($firePreis['vk_pp'], 4))->toBe(round(50 + 12 * 20 / 120, 4));
});

it('Stunden aus Einsatzzeit plus Zuschlag und feste Stunden; Day-into-Night rechnet je_event einmal, je_tageszeit zweimal', function () {
    $lunch = k94Concept($this->rootTeam->id, 30, 10);
    $dinner = k94Concept($this->rootTeam->id, 50, 15);
    $k = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => $lunch->id, 'dinner_concept_id' => $dinner->id]);
    $personal = k94Leistung($this, ['bezeichnung' => 'Service', 'preis_je' => 'stunde', 'stunden_quelle' => 'einsatzzeit', 'stunden_zuschlag' => 1,
        'haeufigkeit' => 'je_tageszeit', 'anzahl' => 2, 'vk_satz' => 30]);
    $lkw = k94Leistung($this, ['bezeichnung' => 'LKW', 'kategorie' => 'logistik', 'anzahl' => 1, 'vk_satz' => 500]);
    $aufbau = k94Leistung($this, ['bezeichnung' => 'Aufbau', 'preis_je' => 'stunde', 'stunden_quelle' => 'fest', 'stunden' => 7, 'anzahl' => 3, 'vk_satz' => 25]);
    $paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 100]);
    $k->refresh();

    expect($this->pricing->stunden($this->k->fresh(), $personal, 'lunch'))->toBe(7.0)
        ->and($this->pricing->stunden($this->k->fresh(), $personal, 'dinner'))->toBe(8.0)
        ->and($this->pricing->stunden($this->k->fresh(), $personal, 'day_night'))->toBe(15.0)
        ->and($this->pricing->stunden($this->k->fresh(), $aufbau, 'dinner'))->toBe(7.0);

    // Lunch: Service 2 × 7 h × 30 + LKW 500 + Aufbau 3 × 7 h × 25
    $l = $this->pricing->rechneFall($this->k->fresh(), $paket, ['typ' => 'einzel', 'k' => $k->id, 't' => 'lunch']);
    expect($l['leistungen_vk'])->toBe(round(2 * 7 * 30 + 500 + 3 * 7 * 25, 4));

    // Day-into-Night: Service je Tageszeit (2 × 7 h + 2 × 8 h) × 30, LKW und Aufbau einmal
    $dn = $this->pricing->rechneFall($this->k->fresh(), $paket, ['typ' => 'day_night', 'k1' => $k->id, 'k2' => $k->id]);
    expect($dn['leistungen_vk'])->toBe(round((2 * 7 + 2 * 8) * 30 + 500 + 3 * 7 * 25, 4))
        ->and($dn['speisen_vk'])->toBe(80.0);
});

it('Schnüren: Handwerte bleiben, Abweichung wird gemeldet, der Lauf ist idempotent', function () {
    $service = k94Leistung($this, ['bezeichnung' => 'Service 1:30', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30]);
    $paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    expect(k94Anzahl($paket->id, $service->id))->toBe(5.0);   // Referenz 149 → ceil(149/30)

    $this->svc->mengeSetzen($this->rootTeam, $paket->id, $service->id, null, 7, 'Galaabend');
    $bericht = $this->pricing->schnueren($this->rootTeam, $this->k->fresh());
    $zeilen = FoodAlchemistKonfiguratorPaketMenge::where('paket_id', $paket->id)->count();
    $nochmal = $this->pricing->schnueren($this->rootTeam, $this->k->fresh());

    expect(k94Anzahl($paket->id, $service->id))->toBe(7.0)
        ->and($bericht['abweichungen'])->toHaveCount(1)
        ->and($bericht['abweichungen'][0]['regel'])->toBe(5.0)
        ->and(FoodAlchemistKonfiguratorPaketMenge::where('paket_id', $paket->id)->count())->toBe($zeilen)
        ->and($nochmal['zeilen'])->toBe($bericht['zeilen']);

    $this->svc->mengeSetzen($this->rootTeam, $paket->id, $service->id, null, null);
    expect(k94Anzahl($paket->id, $service->id))->toBe(5.0);
});

it('Referenz-Pax: Mitte vom System, ein Handwert bleibt bei geänderten Grenzen; Überschneidung wird abgelehnt', function () {
    $p = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    expect($p->referenz_pax)->toBe(149)->and($p->referenz_manuell)->toBeFalse();

    $p = $this->svc->paketAendern($this->rootTeam, $p->id, ['bis_pax' => 249]);
    expect($p->referenz_pax)->toBe(174);

    $p = $this->svc->paketAendern($this->rootTeam, $p->id, ['referenz_pax' => 150]);
    $p = $this->svc->paketAendern($this->rootTeam, $p->id, ['von_pax' => 120]);
    expect($p->referenz_pax)->toBe(150)->and($p->referenz_manuell)->toBeTrue();

    expect(fn () => $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 200, 'bis_pax' => 300]))
        ->toThrow(InvalidArgumentException::class, 'Überschneidung');
});

it('Randkontrolle rechnet die Mengen nach Regel für von und bis; Rundung, DB und Kombi-Rabatt', function () {
    $lunch = k94Concept($this->rootTeam->id, 30, 10);
    $dinner = k94Concept($this->rootTeam->id, 50, 15);
    $this->svc->aendern($this->rootTeam, $this->k->id, ['rundung' => 0.5, 'kombi_rabatt_pct' => 5, 'paket_toleranz_pct' => 1]);
    $k = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => $lunch->id, 'dinner_concept_id' => $dinner->id, 'kunden_titel' => 'Culture']);
    k94Leistung($this, ['bezeichnung' => 'Service 1:30', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30, 'vk_satz' => 300, 'ek_satz' => 200]);
    k94Leistung($this, ['bezeichnung' => 'LKW', 'kategorie' => 'logistik', 'vk_satz' => 1000, 'ek_satz' => 600]);
    $paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);

    $m = $this->pricing->matrix($this->k->fresh());
    $dinnerFall = collect($m['pakete'][0]['faelle'])->firstWhere('name', 'Culture Dinner');
    // Referenz 149: 50 + (5 × 300 + 1000) / 149 = 66,778… → auf 0,50 gerundet 67,00
    expect($dinnerFall['preis_pp'])->toBe(67.0)
        ->and($dinnerFall['ek_pp'])->toBe(round(15 + (5 * 200 + 600) / 149, 2))
        ->and($dinnerFall['db_pp'])->toBe(round(67 - (15 + (5 * 200 + 600) / 149), 2))
        // Rand: 100 Gäste → 4 Service, 199 Gäste → 7 Service
        ->and($dinnerFall['rand']['von']['genau'])->toBe(round(50 + (4 * 300 + 1000) / 100, 2))
        ->and($dinnerFall['rand']['bis']['genau'])->toBe(round(50 + (7 * 300 + 1000) / 199, 2))
        ->and($dinnerFall['rand']['von']['db'])->toBe(round(67 - (15 + (4 * 200 + 600) / 100), 2));
    expect(collect($m['warnungen'])->contains(fn ($w) => str_contains($w, 'bei 100 Gästen weicht')))->toBeTrue();

    // Day-into-Night mit 5 % Kombi-Rabatt auf den Konzeptanteil (Speisen 80 + Leistungen/r)
    $dn = collect($m['pakete'][0]['faelle'])->firstWhere('fall.typ', 'day_night');
    expect($dn['preis_pp'])->toBe(round(round((80 + (5 * 300 + 1000) / 149) * 0.95 / 0.5) * 0.5, 2));
});

it('Paketgrenzen vorschlagen: Toleranz gehalten, Raster, lückenlos, enge Pakete bei hohem Fixkostenanteil', function () {
    $c = k94Concept($this->rootTeam->id, 40, 12);
    $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id]);
    k94Leistung($this, ['bezeichnung' => 'Küchenchef', 'vk_satz' => 1500, 'ek_satz' => 900]);   // reiner Fixkostenblock

    $vorschlag = $this->pricing->paketgrenzenVorschlagen($this->k->fresh(), 50, 1200, 10, 25, 25);

    expect($vorschlag[0]['von_pax'])->toBe(50)->and(end($vorschlag)['bis_pax'])->toBe(1200);
    foreach ($vorschlag as $i => $p) {
        expect($p['toleranz_gehalten'])->toBeTrue();
        if ($i > 0) {
            expect($p['von_pax'])->toBe($vorschlag[$i - 1]['bis_pax'] + 1);
        }
        if ($p['bis_pax'] !== 1200) {
            expect(($p['bis_pax'] + 1) % 25)->toBe(0);
        }
    }
    $breite = fn ($p) => $p['bis_pax'] - $p['von_pax'] + 1;
    expect($breite($vorschlag[0]))->toBeLessThan($breite(end($vorschlag)));

    // Übernehmen ersetzt die Pakete und schnürt sie
    $n = $this->svc->vorschlagUebernehmen($this->rootTeam, $this->k->id, $vorschlag);
    expect(FoodAlchemistKonfigurator::find($this->k->id)->pakete()->count())->toBe($n);
});

it('Add-on: eigener Speisenpreis plus eigene Leistungen, kein Anteil an der Grundausstattung', function () {
    $c = k94Concept($this->rootTeam->id, 50, 15);
    $snack = k94Concept($this->rootTeam->id, 8, 3);
    $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id]);
    $addon = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'addon', 'concept_id' => $snack->id, 'abschnitt' => 'Mitternachtssnack']);
    k94Leistung($this, ['bezeichnung' => 'LKW', 'vk_satz' => 1000]);
    k94Leistung($this, ['bezeichnung' => 'Snackstation', 'vk_satz' => 200, 'konfigurator_konzept_id' => $addon->id]);
    $paket = $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 100]);

    $a = $this->pricing->rechneFall($this->k->fresh(), $paket, ['typ' => 'addon', 'a' => $addon->id]);
    expect(round($a['vk_pp'], 2))->toBe(10.0);   // 8 + 200 / 100
});
