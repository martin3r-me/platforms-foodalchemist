<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Preislisten\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PreislisteApplyTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §3 / Schritt 4d, Stufe 3: die Schreib-Schicht. Treffer eines Laufs werden als TEAMPREISE geschrieben
 * (append-only, nie ein Listenpreis, unveränderter Preis = keine neue Zeile, idempotent).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    $this->admin = $this->makeUser($this->rootTeam, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->team = $this->rootTeam;                              // Mandant = Kunden-Hauptteam
    $this->svc = app(PreislistenImportService::class);

    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);          // globaler Stamm-Lieferant
    $this->mehl = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Mehl', 'article_number' => '1001']);
    $this->oel = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Öl', 'article_number' => '2002']);
    $this->listenpreis = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->mehl->id, 'price' => 9.99, 'status' => '0']);

    $this->lauf = function (string $csv): \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun {
        $pfad = tempnam(sys_get_temp_dir(), 'pl') . '.csv';
        file_put_contents($pfad, $csv);

        return $this->svc->stage($this->team, $pfad, [], $this->sup->id);
    };
    $this->teamPreise = fn (int $itemId) => FoodAlchemistPrice::where('team_id', $this->team->id)->where('supplier_item_id', $itemId)->orderBy('id')->get();
});

it('schreibt Teampreise: Zeile mit team_id des Mandanten, Listenpreis unberührt, alter Preis bleibt in der Historie', function () {
    $run = ($this->lauf)("Artikelnummer;Preis netto;gültig ab\n1001;12,50;01.01.2026\n2002;3,20;\n");
    $listenVorher = FoodAlchemistPrice::where('team_id', null)->get()->toArray();

    $b = $this->svc->anwenden($this->team, $this->admin, $run);

    expect($b)->toMatchArray(['angewendet' => 2, 'unveraendert' => 0, 'zukuenftig' => 0, 'fehler' => 0, 'lauf_status' => 'applied']);
    $mehl = ($this->teamPreise)($this->mehl->id);
    expect($mehl)->toHaveCount(1)->and((float) $mehl[0]->price)->toBe(12.5)->and($mehl[0]->team_id)->toBe($this->team->id)
        ->and($mehl[0]->status_valid_from->toDateString())->toBe('2026-01-01')
        ->and(FoodAlchemistPrice::where('team_id', null)->get()->toArray())->toBe($listenVorher);   // globale Zeile identisch

    // Zweiter Lauf mit neuem Preis: der eigene Vorgänger wird geschlossen, die neue Zeile ist aktiv — nichts wird überschrieben.
    $run2 = ($this->lauf)("Artikelnummer;Preis netto\n1001;13,00\n");
    $this->svc->anwenden($this->team, $this->admin, $run2);
    $mehl = ($this->teamPreise)($this->mehl->id);
    expect($mehl)->toHaveCount(2)->and($mehl[0]->valid_to)->not->toBeNull()->and($mehl[1]->valid_to)->toBeNull()->and((float) $mehl[1]->price)->toBe(13.0);

    // Lisas Preissicht: der Mandant sieht seinen Teampreis, der Listenpreis bleibt für alle anderen.
    $preise = app(PriceService::class);
    expect((float) $preise->activeFor($this->mehl->id, $this->team)->price)->toBe(13.0);
});

it('unveränderter Preis erzeugt keine neue Zeile; ein zweites Anwenden desselben Laufs schreibt nichts doppelt', function () {
    $run = ($this->lauf)("Artikelnummer;Preis netto\n1001;12,50\n");
    $this->svc->anwenden($this->team, $this->admin, $run);
    expect(($this->teamPreise)($this->mehl->id))->toHaveCount(1);

    $nochmal = $this->svc->anwenden($this->team, $this->admin, $run->refresh());
    expect($nochmal['angewendet'])->toBe(0)->and(($this->teamPreise)($this->mehl->id))->toHaveCount(1);

    $run2 = ($this->lauf)("Artikelnummer;Preis netto\n1001;12,50\n");          // gleiche Liste noch einmal hochgeladen
    $b = $this->svc->anwenden($this->team, $this->admin, $run2);
    expect($b)->toMatchArray(['angewendet' => 0, 'unveraendert' => 1])->and(($this->teamPreise)($this->mehl->id))->toHaveCount(1)
        ->and(FoodAlchemistPriceImportLine::where('run_id', $run2->id)->value('status'))->toBe('unveraendert');
});

it('„gültig ab“ in der Zukunft wird erst bei einem späteren Anwenden geschrieben', function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    $run = ($this->lauf)("Artikelnummer;Preis netto;gültig ab\n1001;12,50;01.12.2026\n2002;3,20;13.10.2026\n");

    $b = $this->svc->anwenden($this->team, $this->admin, $run);
    expect($b)->toMatchArray(['angewendet' => 1, 'zukuenftig' => 1, 'lauf_status' => 'staged'])->and(($this->teamPreise)($this->mehl->id))->toHaveCount(0);
    expect(FoodAlchemistPriceImportLine::where('run_id', $run->id)->where('status', 'zukuenftig')->value('reason'))->toContain('01.12.2026');

    Carbon::setTestNow('2026-12-02 08:00:00');
    $b2 = $this->svc->anwenden($this->team, $this->admin, $run->refresh());
    expect($b2)->toMatchArray(['angewendet' => 1, 'zukuenftig' => 0, 'lauf_status' => 'applied'])->and(($this->teamPreise)($this->mehl->id))->toHaveCount(1);
    Carbon::setTestNow();
});

it('Rechte: Unter-Team und Betrachter dürfen nicht anwenden; fremde Läufe nicht; es entsteht keine Preiszeile', function () {
    $run = ($this->lauf)("Artikelnummer;Preis netto\n1001;12,50\n");
    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    $viewer = $this->makeUser($this->rootTeam, 'Gast', 'viewer');
    $anzahl = FoodAlchemistPrice::count();

    expect(fn () => $this->svc->anwenden($this->childA, $kind, $run))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->anwenden($this->team, $viewer, $run))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->anwenden($this->team, null, $run))->toThrow(FaRechtFehltException::class);

    $fremd = ($this->lauf)("Artikelnummer;Preis netto\n1001;1\n");
    $fremd->forceFill(['team_id' => $this->childA->id])->save();
    expect(fn () => $this->svc->anwenden($this->team, $this->admin, $fremd))->toThrow(RuntimeException::class, 'anderen Team');
    expect(FoodAlchemistPrice::count())->toBe($anzahl);
});

it('Artikel nicht mehr sichtbar (Stamm-Paket abbestellt): Zeile wird Fehler mit Grund, kein Preis', function () {
    $paket = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Paketartikel', 'article_number' => '7007', 'stamm_class' => 'stamm_paket']);
    StammPaket::freischalten($this->team); $this->alleLieferantenFrei($this->team);
    $run = ($this->lauf)("Artikelnummer;Preis netto\n7007;5,00\n");
    expect(FoodAlchemistPriceImportLine::where('run_id', $run->id)->value('status'))->toBe('treffer');

    StammPaket::abbestellen($this->team);
    $b = $this->svc->anwenden($this->team, $this->admin, $run);

    expect($b['fehler'])->toBe(1)->and(($this->teamPreise)($paket->id))->toHaveCount(0)
        ->and(FoodAlchemistPriceImportLine::where('run_id', $run->id)->value('reason'))->toContain('nicht mehr sichtbar');
});

it('Prüfliste zeigt nach dem Anwenden nur noch, was wirklich zu prüfen ist; die Post-Import-Kette läuft einmal', function () {
    $run = ($this->lauf)("Artikelnummer;Preis netto\n1001;12,50\n9999;1\n");
    $b = $this->svc->anwenden($this->team, $this->admin, $run);

    expect($b['kette'])->toHaveKeys(['bewegt', 'gps', 'rezepte', 'neu_berechnet', 'signale'])->and($b['kette']['bewegt'])->toBe(1);
    expect($this->svc->pruefliste($run->refresh())->pluck('status')->all())->toBe(['ohne_treffer'])
        ->and($run->counts)->toMatchArray(['angewendet' => 1, 'treffer' => 0]);
});

it('Oberfläche: „Preise übernehmen“ schreibt Teampreise und zeigt das Ergebnis; für Unter-Team und Betrachter ohne Knopf', function () {
    $run = ($this->lauf)("Artikelnummer;Preis netto\n1001;12,50\n");

    $c = Livewire::test(Index::class)->call('laufWaehlen', $run->id)->assertSeeHtml('data-preisliste-anwenden')->assertSee('1 Preis übernehmen');
    $c->call('anwenden', $run->id)->assertSet('fehler', null)->assertSee('1 Preis übernommen');
    expect(($this->teamPreise)($this->mehl->id))->toHaveCount(1);
    $c->assertDontSeeHtml('data-preisliste-anwenden')->assertSee('Übernommen');

    $run2 = ($this->lauf)("Artikelnummer;Preis netto\n2002;3\n");
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'viewer'));
    Livewire::test(Index::class)->call('laufWaehlen', $run2->id)->assertDontSeeHtml('data-preisliste-anwenden')
        ->call('anwenden', $run2->id)->assertSet('fehler', fn ($f) => $f !== null);
    expect(($this->teamPreise)($this->oel->id))->toHaveCount(0);
});

it('MCP: preisliste.APPLY übernimmt die Treffer, ist idempotent und prüft Rechte und Lauf', function () {
    $run = ($this->lauf)("Artikelnummer;Preis netto\n1001;12,50\n");
    $ctx = new ToolContext($this->admin, $this->team);

    $r = (new PreislisteApplyTool)->execute(['lauf_id' => $run->id], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['angewendet'])->toBe(1)->and($r->data['lauf_status'])->toBe('applied');
    expect((new PreislisteApplyTool)->execute(['lauf_id' => $run->id], $ctx)->data['angewendet'])->toBe(0)->and(($this->teamPreise)($this->mehl->id))->toHaveCount(1);

    expect((new PreislisteApplyTool)->execute(['lauf_id' => 99999], $ctx)->errorCode)->toBe('NOT_FOUND');
    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    expect((new PreislisteApplyTool)->execute(['lauf_id' => $run->id], new ToolContext($kind, $this->childA))->errorCode)->toBe('NOT_FOUND');   // fremder Lauf, nicht sichtbar
});
