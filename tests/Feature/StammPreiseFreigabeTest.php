<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Verwaltung\StammPaket as StammPaketKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PreislisteArtikelExportTool;
use Platform\FoodAlchemist\Tools\StammPaketLieferantenGetTool;
use Platform\FoodAlchemist\Tools\StammPaketLieferantenPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stamm-Preise (Dominique 08.11.2026):
 *  - „Preise mitgeben“ je freigeschaltetem Lieferant, LAUFEND: der Mandant sieht die Master-Preise (Necta) der globalen Stamm-Artikel dieses Lieferanten;
 *    eigene Preise gewinnen immer; ohne Freigabe bleibt der Master-Preis unsichtbar (Leck-Klasse #491); Rücknahme wirkt sofort; nur Plattform-Admin.
 *  - Artikelliste zum Ausfüllen: alle sichtbaren Artikel, Preis = eigener Teampreis oder leer, Re-Upload ordnet zu ohne Duplikate.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    StammPaket::flush();

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->kind = Team::create(['name' => 'Soul Unter', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->soul->id]);
    $this->inhaber = $this->makeUser($this->soul, 'Inhaber Soul', 'owner');
    $this->svc = fn () => app(StammPaketService::class);
    $this->preise = app(PriceService::class);

    $this->hanos = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);
    $this->kluth = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Kluth', 'stamm_class' => 'stamm_paket']);
    $this->la = fn (FoodAlchemistSupplier $s, string $nr) => FoodAlchemistSupplierItem::create([
        'team_id' => null, 'supplier_id' => $s->id, 'designation' => 'LA '.$nr, 'article_number' => $nr, 'qty' => 1, 'unit_code' => 'kg', 'stamm_class' => 'stamm_paket',
    ]);
    $this->laH = ($this->la)($this->hanos, 'H1');
    $this->laK = ($this->la)($this->kluth, 'K1');
    $this->preis = fn (FoodAlchemistSupplierItem $la, int $teamId, float $preis) => DB::table('foodalchemist_prices')->insert(['uuid' => (string) Str::uuid(),
        'supplier_item_id' => $la->id, 'team_id' => $teamId, 'status' => '0', 'price' => $preis, 'is_blocked' => false,
        'status_valid_from' => '2026-11-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    ($this->preis)($this->laH, $this->master->id, 9.95);   // Necta-Preis im Master-Team
    ($this->preis)($this->laK, $this->master->id, 4.20);

    ($this->svc)()->buchen($this->soul, $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id, $this->kluth->id], $this->admin);
    $this->ek = fn (FoodAlchemistSupplierItem $la, ?Team $t) => ($p = $this->preise->activeFor($la->id, $t)) !== null ? (float) $p->price : null;
});

it('★ ohne Freigabe sieht der Mandant keinen Master-Preis (Leck-Gegenprobe); die Listenpreis-Sicht bleibt unverändert', function () {
    expect(($this->ek)($this->laH, $this->soul))->toBeNull()
        ->and(($this->ek)($this->laH, $this->kind))->toBeNull()
        ->and(($this->ek)($this->laH, null))->toBe(9.95);
});

it('★ Freigabe je Lieferant: Mandant und Unter-Team sehen den Master-Preis, anderer Lieferant bleibt ohne Preis, Liste und Subquery gleich', function () {
    $r = ($this->svc)()->setzePreisFreigabe($this->soul, [$this->hanos->id], $this->admin);

    expect($r['preise_freigegeben'])->toBe(1)->and($r['hinzu'])->toBe(1)
        ->and(($this->ek)($this->laH, $this->soul))->toBe(9.95)
        ->and(($this->ek)($this->laH, $this->kind))->toBe(9.95)
        ->and(($this->ek)($this->laK, $this->soul))->toBeNull();
    $sub = FoodAlchemistSupplierItem::query()->whereKey($this->laH->id)->select('foodalchemist_supplier_items.id')
        ->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $this->soul)->toBase(), 'p')->value('p');
    expect((float) $sub)->toBe(9.95);
});

it('★ eigener Preis gewinnt immer; neue Necta-Zeile im Master kommt laufend an; Rücknahme wirkt sofort', function () {
    ($this->svc)()->setzePreisFreigabe($this->soul, 'alle', $this->admin);
    ($this->preis)($this->laH, $this->master->id, 10.50);   // nächtlicher Necta-Abgleich: neue Zeile
    expect(($this->ek)($this->laH, $this->soul))->toBe(10.5);

    ($this->preis)($this->laH, $this->soul->id, 7.50);      // eigener Preis des Mandanten
    expect(($this->ek)($this->laH, $this->soul))->toBe(7.5)->and(($this->ek)($this->laK, $this->soul))->toBe(4.2);

    ($this->svc)()->setzePreisFreigabe($this->soul, 'keine', $this->admin);
    expect(($this->ek)($this->laK, $this->soul))->toBeNull()->and(($this->ek)($this->laH, $this->soul))->toBe(7.5);
});

it('★ Freischaltung weg = Preis-Freigabe weg; neu freigeschaltet startet ohne Preise; nur freigeschaltete Lieferanten und nur Plattform-Admin', function () {
    ($this->svc)()->setzePreisFreigabe($this->soul, 'alle', $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, [$this->kluth->id], $this->admin);
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id, $this->kluth->id], $this->admin);

    $zeilen = collect(($this->svc)()->lieferanten($this->soul)['lieferanten'])->keyBy('id');
    expect($zeilen[$this->hanos->id]['preise_frei'])->toBeFalse()->and($zeilen[$this->kluth->id]['preise_frei'])->toBeTrue()
        ->and(($this->ek)($this->laH, $this->soul))->toBeNull();

    ($this->svc)()->setzeLieferanten($this->soul, [$this->kluth->id], $this->admin);
    expect(fn () => ($this->svc)()->setzePreisFreigabe($this->soul, [$this->hanos->id], $this->admin))->toThrow(RuntimeException::class, 'nicht freigeschaltet');
    expect(fn () => ($this->svc)()->setzePreisFreigabe($this->soul, 'alle', $this->inhaber))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
});

it('MCP: GET zeigt preise_frei, PUT setzt preise_lieferant_ids einzeln oder zusammen mit der Freischaltung, Nicht-Admin FORBIDDEN', function () {
    $ctx = fn ($u) => new ToolContext($u, $this->rootTeam);
    $put = (new StammPaketLieferantenPutTool)->execute(['team_id' => $this->soul->id, 'preise_lieferant_ids' => [$this->kluth->id]], $ctx($this->admin));
    expect($put->success)->toBeTrue()->and($put->data['preise']['preise_freigegeben'])->toBe(1);

    $get = collect((new StammPaketLieferantenGetTool)->execute(['team_id' => $this->soul->id], $ctx($this->admin))->data['lieferanten'])->keyBy('id');
    expect($get[$this->kluth->id]['preise_frei'])->toBeTrue()->and($get[$this->hanos->id]['preise_frei'])->toBeFalse()
        ->and(($this->ek)($this->laK, $this->soul))->toBe(4.2);

    $beides = (new StammPaketLieferantenPutTool)->execute(['team_id' => $this->soul->id, 'alle' => true, 'preise_keine' => true], $ctx($this->admin));
    expect($beides->success)->toBeTrue()->and($beides->data['freigegeben'])->toBe(2)->and($beides->data['preise']['preise_freigegeben'])->toBe(0);

    $nein = (new StammPaketLieferantenPutTool)->execute(['team_id' => $this->soul->id, 'preise_alle' => true], new ToolContext($this->inhaber, $this->soul));
    expect($nein->success)->toBeFalse()->and(($this->ek)($this->laK, $this->soul))->toBeNull();
});

it('Verwaltung: Haken „Preise“ je Lieferant speichert die Freigabe, ohne Freischaltung wirkt er nicht', function () {
    $c = Livewire::actingAs($this->admin)->withQueryParams(['lieferanten' => $this->soul->id])->test(StammPaketKomponente::class);
    $c->assertSeeHtml('data-stamm-paket-preise="' . $this->hanos->id . '"')
        ->set('preiseAuswahl', [$this->hanos->id, $this->kluth->id])->set('auswahl', [$this->hanos->id])->call('lieferantenSpeichern');

    $zeilen = collect(($this->svc)()->lieferanten($this->soul)['lieferanten'])->keyBy('id');
    expect($zeilen[$this->hanos->id]['preise_frei'])->toBeTrue()->and($zeilen[$this->kluth->id]['frei'])->toBeFalse()
        ->and(($this->ek)($this->laH, $this->soul))->toBe(9.95);
});

it('★ Artikelliste: alle freigeschalteten Artikel, eigener Preis oder leer (nie Master-Preis); ausgefüllt zurück ordnet alles zu, ohne Duplikate', function () {
    ($this->svc)()->setzePreisFreigabe($this->soul, 'alle', $this->admin);   // auch mit Freigabe kommt der Master-Preis nicht in die Liste
    ($this->preis)($this->laK, $this->soul->id, 5.10);
    $svc = app(PreislistenImportService::class);

    $liste = collect($svc->artikelListe($this->soul))->keyBy(1);
    expect($liste->keys()->sort()->values()->all())->toBe(['H1', 'K1'])
        ->and($liste['H1'][0])->toBe('Hanos')->and($liste['H1'][5])->toBe('')
        ->and($liste['K1'][5])->toBe('5,10')
        ->and(collect($svc->artikelListe($this->soul, $this->kluth->id))->pluck(1)->all())->toBe(['K1']);

    // ausfüllen: H1 neu, K1 unverändert; eine eigene Zeile ohne Preis bleibt leer
    $zeilen = $svc->artikelListe($this->soul);
    foreach ($zeilen as &$z) {
        if ($z[1] === 'H1') {
            $z[5] = '8,40';
        }
    }
    unset($z);
    $zeilen[] = ['Hanos', 'H-LEER', '', 'leer gelassen', '', '', '', ''];
    $pfad = tempnam(sys_get_temp_dir(), 'fa-artikelliste-test-') . '.csv';
    $fp = fopen($pfad, 'w');
    fputcsv($fp, PreislistenImportService::ARTIKELLISTE_KOPF, ';');
    foreach ($zeilen as $z) {
        fputcsv($fp, $z, ';');
    }
    fclose($fp);

    $lauf = $svc->stage($this->soul, $pfad, [], null, $this->inhaber->id, 'csv', 'artikel.csv');
    @unlink($pfad);
    expect($lauf->counts['treffer'] ?? 0)->toBe(2)->and($lauf->counts['ohne_preis'] ?? 0)->toBe(1)->and($lauf->counts['zeilen'])->toBe(2);

    $b = $svc->anwenden($this->soul, $this->inhaber, $lauf);
    expect($b['angewendet'])->toBe(1)->and($b['unveraendert'])->toBe(1)
        ->and(DB::table('foodalchemist_prices')->where('team_id', $this->soul->id)->where('supplier_item_id', $this->laK->id)->count())->toBe(1)
        ->and(($this->ek)($this->laH, $this->soul))->toBe(8.4);
});

it('MCP: preisliste.ARTIKEL_EXPORT liefert CSV mit Vorlagen-Kopf und prüft den Lieferanten', function () {
    $ctx = new ToolContext($this->inhaber, $this->soul);
    $r = (new PreislisteArtikelExportTool)->execute([], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['zeilen'])->toBe(2)
        ->and($r->data['csv'])->toContain('Lieferant;Artikelnummer;EAN')->toContain('H1')->not->toContain('9,95')
        ->and($r->data['dateiname'])->toBe('preisliste_artikel_alle.csv');

    $fremd = FoodAlchemistSupplier::create(['team_id' => $this->master->id, 'name' => 'Nur Master']);
    expect((new PreislisteArtikelExportTool)->execute(['supplier_id' => $fremd->id], $ctx)->success)->toBeFalse();
});
