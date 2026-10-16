<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Preise\Fruehere;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Services\PreisVerlaufService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Preis-Stand als Erfassungsfehler ausblenden + automatischer Ausreißer-Hinweis (Dominique 16.10.2026, „FB Apple-Crumble“, Achenbach:
 * 63,82 € für einen Tag statt 1,82 €). Historie wie die echte aus Necta: 1,80 bis 05.10., 63,82 am 06.10., danach 1,82.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));

    $lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Achenbach', 'stamm_class' => StammPaket::PAKET]);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674788, 'supplier_id' => $lieferant->id, 'designation' => 'FB Apple-Crumble',
        'qty' => 1, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);

    $this->admin = $this->makeUser($this->rootTeam, 'Admin M', 'owner');
    $this->kurator = $this->makeUser($this->rootTeam, 'Kurator M', 'member');
    $this->leser = $this->makeUser($this->rootTeam, 'Leser M', 'viewer');
    $this->ownerA = $this->makeUser($this->childA, 'Owner A', 'owner');

    $h = fn (int $id, string $type, float $preis, string $von, string $bis) => ['id' => $id, 'supplierItemId' => 36674788, 'tenantId' => 1494, 'type' => $type,
        'price' => $preis, 'priceOrderingUnit' => $preis, 'amountFrom' => 0, 'validFrom' => $von, 'validTo' => $bis, 'source' => 175492];
    $this->historie = [
        $h(1, 'N', 1.80, '2026-08-01T00:00:00', '2026-10-05T23:59:59'),
        $h(2, 'N', 63.82, '2026-10-06T00:00:00', '2026-10-06T23:59:59'),
        $h(3, 'N', 1.82, '2026-10-07T00:00:00', '3000-12-31T23:59:59'),
    ];
    $this->importiere = function (?array $zeilen = null) {
        $this->app->instance(NectaQuelle::class, new NectaAttrappe(['supplier-item-price-historys' => $zeilen ?? $this->historie], [36674788 => 19491]));
        $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--pause-ms' => 0])->assertSuccessful();
    };
    $this->svc = app(PreisVerlaufService::class);
    $this->verlauf = function () {
        [$von, $bis] = $this->svc->fenster('alles');

        return $this->svc->verlaufFuerLa($this->rootTeam, $this->la->id, $von, $bis);
    };
    $this->zeile = fn (float $preis) => DB::table('foodalchemist_price_history')->where('supplier_item_id', $this->la->id)->whereBetween('price', [$preis - 0.001, $preis + 0.001])->first();
});

it('Ausreißer: 63,82 für einen Tag zwischen 1,80 und 1,82 wird markiert, aber nicht ausgeblendet und nicht aus der Linie genommen', function () {
    ($this->importiere)();

    $v = ($this->verlauf)();
    $segmente = collect($v['linien'][0]['segmente'])->keyBy(fn ($s) => number_format($s['preis_gebinde'], 2, '.', ''));

    expect($segmente['63.82']['verdacht'])->toBeTrue()
        ->and($segmente['1.80']['verdacht'])->toBeFalse()->and($segmente['1.82']['verdacht'])->toBeFalse()
        ->and($v['linien'][0]['trend']['prozent_pro_jahr'])->toBeGreaterThan(100)              // die Spitze steckt noch im Trend (Hinweis, kein automatisches Ausblenden)
        ->and(DB::table('foodalchemist_price_history')->whereNotNull('ausgeblendet_at')->count())->toBe(0);

    $liste = collect($this->svc->fruehere($this->rootTeam, $this->la)['zeilen'])->keyBy(fn ($z) => number_format($z['preis'], 2, '.', ''));
    expect($liste['63.82']['verdacht'])->toBeTrue()->and($liste['1.82']['verdacht'])->toBeFalse()->and($liste['63.82']['ausgeblendet'])->toBeFalse();
});

it('Ausreißer-Gegenproben: kleine Abweichung, lange Dauer, nur ein Nachbar und höhere Schwelle markieren nichts', function () {
    $h = $this->historie;
    // kleine Abweichung (Faktor 2 < 3)
    ($this->importiere)([$h[0], ['price' => 3.6, 'priceOrderingUnit' => 3.6] + $h[1], $h[2]]);
    expect(collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('verdacht')->all())->each->toBeFalse();

    // lange Dauer (20 Tage ≥ 14)
    DB::table('foodalchemist_price_history')->delete();
    ($this->importiere)([$h[0], ['validTo' => '2026-10-25T23:59:59', 'validFrom' => '2026-10-06T00:00:00'] + $h[1], ['validFrom' => '2026-10-26T00:00:00'] + $h[2]]);
    expect(collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('verdacht')->all())->each->toBeFalse();

    // Spitze am Ende: nur ein Nachbar → nie markiert
    DB::table('foodalchemist_price_history')->delete();
    ($this->importiere)([$h[0], ['validTo' => '3000-12-31T23:59:59'] + $h[1]]);
    expect(collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('verdacht')->all())->each->toBeFalse();

    // echter Preissprung: der kurze Zwischenstand weicht vom Vorgänger ab, passt aber zum Nachfolger → nur BEIDE Nachbarn zusammen zählen
    DB::table('foodalchemist_price_history')->delete();
    ($this->importiere)([$h[0], ['price' => 6.0, 'priceOrderingUnit' => 6.0, 'validTo' => '2026-10-08T23:59:59'] + $h[1], ['price' => 6.1, 'priceOrderingUnit' => 6.1, 'validFrom' => '2026-10-09T00:00:00'] + $h[2]]);
    expect(collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('verdacht')->all())->each->toBeFalse();

    // Schwelle aus der config: Faktor 100 → der echte Fall wird nicht mehr markiert
    DB::table('foodalchemist_price_history')->delete();
    config(['foodalchemist.preise.ausreisser.faktor' => 100.0]);
    ($this->importiere)();
    expect(collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('verdacht')->all())->each->toBeFalse();
});

it('Ausblenden: Stand fliegt aus Linie, Trend und Statistik, bleibt gespeichert und steht durchgestrichen mit Grund in „Frühere Preise“; wieder einblendbar', function () {
    ($this->importiere)();
    $vorher = ($this->verlauf)();
    $id = ($this->zeile)(63.82)->id;

    $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'Erfassungsfehler beim Lieferanten', $this->kurator->id);

    $nachher = ($this->verlauf)();
    expect(collect($nachher['linien'][0]['segmente'])->pluck('preis_gebinde')->map(fn ($p) => round($p, 2))->all())->not->toContain(63.82)
        ->and(abs($nachher['linien'][0]['trend']['prozent_pro_jahr']))->toBeLessThan(10)                // war > 100: die Spitze trieb den Trend
        ->and($vorher['linien'][0]['trend']['prozent_pro_jahr'])->toBeGreaterThan(100)
        ->and(DB::table('foodalchemist_price_history')->where('id', $id)->count())->toBe(1);           // nicht gelöscht

    $zeile = DB::table('foodalchemist_price_history')->find($id);
    expect($zeile->ausgeblendet_grund)->toBe('Erfassungsfehler beim Lieferanten')->and((int) $zeile->ausgeblendet_von)->toBe($this->kurator->id)->and($zeile->ausgeblendet_at)->not->toBeNull();
    $liste = collect($this->svc->fruehere($this->rootTeam, $this->la)['zeilen'])->firstWhere('id', $id);
    expect($liste['ausgeblendet'])->toBeTrue()->and($liste['grund'])->toBe('Erfassungsfehler beim Lieferanten')->and($liste['verdacht'])->toBeFalse();

    $this->svc->einblenden($this->rootTeam, $this->la, 'historie', $id, $this->kurator->id);
    expect(round(($this->verlauf)()['linien'][0]['trend']['prozent_pro_jahr'], 1))->toBe(round($vorher['linien'][0]['trend']['prozent_pro_jahr'], 1))
        ->and(DB::table('foodalchemist_price_history')->find($id)->ausgeblendet_at)->toBeNull();
});

it('Ausblenden braucht einen Grund, doppelt ist ein Fehler, fremde/fehlende Stände werden abgewiesen', function () {
    ($this->importiere)();
    $id = ($this->zeile)(63.82)->id;

    expect(fn () => $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, '  ', $this->kurator->id))->toThrow(RuntimeException::class, 'Grund')
        ->and(fn () => $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', 99999, 'Fehler', $this->kurator->id))->toThrow(RuntimeException::class, 'nicht gefunden')
        ->and(fn () => $this->svc->ausblenden($this->rootTeam, $this->la, 'preisliste', $id, 'Fehler', $this->kurator->id))->toThrow(RuntimeException::class, 'historie oder preis');
    $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'Fehler', $this->kurator->id);
    expect(fn () => $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'nochmal', $this->kurator->id))->toThrow(RuntimeException::class, 'schon ausgeblendet')
        ->and(DB::table('foodalchemist_price_history')->find($id)->ausgeblendet_grund)->toBe('Fehler');
});

it('Rechte wie beim Pflegen: Master am Stamm-Artikel ja, Mandant am Stamm-Artikel NEIN, Lesen-Rolle nie', function () {
    ($this->importiere)();
    $id = ($this->zeile)(63.82)->id;

    expect(fn () => $this->svc->ausblenden($this->childA, $this->la, 'historie', $id, 'Fehler', $this->ownerA->id))->toThrow(RuntimeException::class, 'übergeordneten Katalog')
        ->and(fn () => $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'Fehler', $this->leser->id))->toThrow(FaRechtFehltException::class)
        ->and(DB::table('foodalchemist_price_history')->find($id)->ausgeblendet_at)->toBeNull();
    $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'Fehler', $this->kurator->id);
    expect(DB::table('foodalchemist_price_history')->find($id)->ausgeblendet_at)->not->toBeNull();
});

it('Der Necta-Import blendet eine ausgeblendete Zeile NICHT wieder ein und legt sie nicht doppelt an (idempotent über necta_historie_id)', function () {
    ($this->importiere)();
    $id = ($this->zeile)(63.82)->id;
    $this->svc->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'Erfassungsfehler', $this->kurator->id);
    $anzahl = DB::table('foodalchemist_price_history')->count();

    ($this->importiere)();                                             // nächster Lauf, gleiche Necta-Historie

    $zeile = DB::table('foodalchemist_price_history')->find($id);
    expect(DB::table('foodalchemist_price_history')->count())->toBe($anzahl)
        ->and($zeile->ausgeblendet_at)->not->toBeNull()->and($zeile->ausgeblendet_grund)->toBe('Erfassungsfehler')
        ->and(collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('preis_gebinde')->map(fn ($p) => round($p, 2))->all())->not->toContain(63.82);
});

it('Preiszeile: ein ausgeblendeter Wert fällt aus der Linie; korrigiert Necta den Preis der Zeile, gilt der neue Wert wieder (Flag hängt am Fehlerwert)', function () {
    $zeile = DB::table('foodalchemist_prices')->insertGetId(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'supplier_item_id' => $this->la->id, 'status' => '0', 'price' => 63.82, 'status_valid_from' => '2026-10-06 00:00:00', 'valid_to' => '2026-10-06 23:59:59',
        'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'supplier_item_id' => $this->la->id, 'status' => '0', 'price' => 1.82, 'status_valid_from' => '2026-10-07 00:00:00', 'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
    $preise = fn () => collect(($this->verlauf)()['linien'][0]['segmente'])->pluck('preis_gebinde')->map(fn ($p) => round($p, 2))->all();
    expect($preise())->toContain(63.82);

    $this->svc->ausblenden($this->rootTeam, $this->la, 'preis', $zeile, 'Erfassungsfehler', $this->kurator->id);
    expect($preise())->not->toContain(63.82);

    DB::table('foodalchemist_prices')->where('id', $zeile)->update(['price' => 1.81]);        // Necta korrigiert den Wert der Zeile
    expect($preise())->toContain(1.81);
});

it('MCP: preise_historie.AUSBLENDEN nur für FA-Admins; preise_verlauf.GET meldet Verdacht und ausgeblendete Stände mit Grund', function () {
    ($this->importiere)();
    $id = ($this->zeile)(63.82)->id;
    $reg = app(ToolRegistry::class);

    $verboten = $reg->get('foodalchemist.preise_historie.AUSBLENDEN')->execute(['la_id' => $this->la->id, 'stand_id' => $id, 'grund' => 'Fehler'], new ToolContext($this->kurator, $this->rootTeam));
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN');

    $get = $reg->get('foodalchemist.preise_verlauf.GET')->execute(['la_id' => $this->la->id, 'zeitraum' => 'alles'], new ToolContext($this->kurator, $this->rootTeam));
    expect($get->success)->toBeTrue()->and($get->data['verdachts_staende'])->toHaveCount(1)->and($get->data['verdachts_staende'][0]['stand_id'])->toBe($id)
        ->and(collect($get->data['linien'][0]['punkte'])->where('moeglicher_erfassungsfehler', true)->count())->toBe(1);

    $ohneGrund = $reg->get('foodalchemist.preise_historie.AUSBLENDEN')->execute(['la_id' => $this->la->id, 'stand_id' => $id], new ToolContext($this->admin, $this->rootTeam));
    expect($ohneGrund->errorCode)->toBe('VALIDATION_ERROR');
    $ok = $reg->get('foodalchemist.preise_historie.AUSBLENDEN')->execute(['la_id' => $this->la->id, 'stand_id' => $id, 'grund' => 'Erfassungsfehler beim Lieferanten'], new ToolContext($this->admin, $this->rootTeam));
    expect($ok->success)->toBeTrue()->and($ok->data['aktion'])->toBe('ausgeblendet');

    $get = $reg->get('foodalchemist.preise_verlauf.GET')->execute(['la_id' => $this->la->id, 'zeitraum' => 'alles'], new ToolContext($this->kurator, $this->rootTeam));
    expect($get->data['ausgeblendete_staende'][0])->toMatchArray(['stand_id' => $id, 'grund' => 'Erfassungsfehler beim Lieferanten'])
        ->and($get->data['verdachts_staende'])->toBe([]);

    $zurueck = $reg->get('foodalchemist.preise_historie.AUSBLENDEN')->execute(['la_id' => $this->la->id, 'stand_id' => $id, 'aktion' => 'einblenden'], new ToolContext($this->admin, $this->rootTeam));
    expect($zurueck->success)->toBeTrue()->and(DB::table('foodalchemist_price_history')->find($id)->ausgeblendet_at)->toBeNull();
});

it('UI: „Frühere Preise" zeigt den Verdacht, Ausblenden verlangt einen Grund, danach durchgestrichen mit Einblenden; Mandant sieht den Knopf am Stamm-Artikel nicht', function () {
    ($this->importiere)();
    $id = ($this->zeile)(63.82)->id;

    $this->actingAs($this->kurator);
    Livewire::test(Fruehere::class, ['itemId' => $this->la->id])
        ->assertSeeHtml('data-verdacht-badge')
        ->assertSeeHtml('data-fruehere-ausblenden-oeffnen')
        ->call('ausblenden', $id)->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Grund'))
        ->set("gruende.{$id}", 'Erfassungsfehler beim Lieferanten')->call('ausblenden', $id)->assertSet('fehler', null)->assertDispatched('preise-geaendert')
        ->assertSeeHtml('data-ausgeblendet-badge')->assertSee('Grund: Erfassungsfehler beim Lieferanten')->assertSeeHtml('data-fruehere-einblenden')
        ->call('einblenden', $id)->assertSet('fehler', null)->assertDontSeeHtml('data-ausgeblendet-badge');

    $this->alleLieferantenFrei($this->childA);                      // der Mandant sieht die Stamm-Preise erst mit Freischaltung
    DB::table('foodalchemist_price_history')->update(['team_id' => null]);   // globale Stamm-Historie: für jeden sichtbar
    $this->actingAs($this->ownerA);
    Livewire::test(Fruehere::class, ['itemId' => $this->la->id])
        ->assertSeeHtml('data-fruehere-zeile')
        ->assertDontSeeHtml('data-fruehere-ausblenden-oeffnen')
        ->call('ausblenden', $id)->assertSet('fehler', fn ($f) => str_contains((string) $f, 'übergeordneten Katalog'));
    expect(DB::table('foodalchemist_price_history')->find($id)->ausgeblendet_at)->toBeNull();
});
