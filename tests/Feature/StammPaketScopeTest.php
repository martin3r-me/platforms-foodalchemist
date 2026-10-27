<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 A3: Globale Zeilen (team_id NULL) tragen eine Klasse basis | stamm_paket.
 * stamm_paket sieht nur, wer das Opt-in hat (Team oder Vorfahre) — oder der konfigurierte Master.
 * Annahme-Tests (Master sieht alles) sind als solche benannt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);

    $this->gpBasis = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'sp|basis', 'name' => 'Salz: Basis']);
    $this->gpPaket = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'sp|paket', 'name' => 'Mehl: Paket', 'stamm_class' => 'stamm_paket']);
    $this->gpEigen = $this->makeGp($this->childA, 'Eigenes GP');

    $this->supBasis = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Basis-Lieferant']);
    $this->supPaket = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Paket-Lieferant', 'stamm_class' => 'stamm_paket']);

    $this->laBasis = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->supBasis->id, 'designation' => 'Salz 1kg']);
    $this->laPaket = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->supPaket->id, 'designation' => 'Mehl 25kg', 'stamm_class' => 'stamm_paket']);
});

function sichtbar(string $modell, $team): array
{
    return $modell::query()->visibleToTeam($team)->pluck('id')->all();
}

it('Default ist basis: Bestand ohne Klasse bleibt für alle Teams sichtbar (Verhalten wie vorher)', function () {
    expect($this->gpBasis->fresh()->stamm_class)->toBe('basis');

    foreach ([$this->rootTeam, $this->childA, $this->childB] as $team) {
        expect(sichtbar(FoodAlchemistGp::class, $team))->toContain($this->gpBasis->id);
    }
});

it('stamm_paket ist ohne Opt-in für niemanden sichtbar — GP, Lieferant, Artikel', function () {
    foreach ([$this->rootTeam, $this->childA, $this->childB] as $team) {
        expect(sichtbar(FoodAlchemistGp::class, $team))->not->toContain($this->gpPaket->id)
            ->and(sichtbar(FoodAlchemistSupplier::class, $team))->not->toContain($this->supPaket->id)
            ->and(sichtbar(FoodAlchemistSupplierItem::class, $team))->not->toContain($this->laPaket->id);
    }
});

it('teamgebundene Zeilen laufen unverändert durch (Team 6 auf demo: alles team-eigen)', function () {
    expect(sichtbar(FoodAlchemistGp::class, $this->childA))->toContain($this->gpEigen->id)
        ->and(sichtbar(FoodAlchemistGp::class, $this->childB))->not->toContain($this->gpEigen->id);
});

it('Opt-in des Oberteams schaltet das Paket für Oberteam und Unter-Teams frei', function () {
    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);

    foreach ([$this->rootTeam, $this->childA, $this->childB] as $team) {
        expect(sichtbar(FoodAlchemistGp::class, $team))->toContain($this->gpPaket->id)
            ->and(sichtbar(FoodAlchemistSupplier::class, $team))->toContain($this->supPaket->id)
            ->and(sichtbar(FoodAlchemistSupplierItem::class, $team))->toContain($this->laPaket->id);
    }
});

it('Opt-in gilt nur abwärts: ein Unter-Team schaltet weder Eltern noch Geschwister frei', function () {
    StammPaket::freischalten($this->childA); $this->alleLieferantenFrei($this->childA);

    expect(sichtbar(FoodAlchemistGp::class, $this->childA))->toContain($this->gpPaket->id)
        ->and(sichtbar(FoodAlchemistGp::class, $this->childB))->not->toContain($this->gpPaket->id)
        ->and(sichtbar(FoodAlchemistGp::class, $this->rootTeam))->not->toContain($this->gpPaket->id);
});

it('abbestellen nimmt das Paket wieder weg (Cache wird geleert)', function () {
    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect(sichtbar(FoodAlchemistGp::class, $this->childB))->toContain($this->gpPaket->id);

    expect(StammPaket::abbestellen($this->rootTeam))->toBeTrue()
        ->and(sichtbar(FoodAlchemistGp::class, $this->childB))->not->toContain($this->gpPaket->id);
});

it('ANNAHME: das konfigurierte Master-Team sieht das Paket ohne Opt-in — auch seine Unter-Teams', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    StammPaket::flush();

    expect(sichtbar(FoodAlchemistGp::class, $this->rootTeam))->toContain($this->gpPaket->id)
        ->and(sichtbar(FoodAlchemistGp::class, $this->childA))->toContain($this->gpPaket->id);
});

it('ohne konfigurierten Master zählt „elternlos" NICHT als Master (auf demo sind alle Teams elternlos)', function () {
    expect($this->rootTeam->parent_team_id)->toBeNull()
        ->and(sichtbar(FoodAlchemistGp::class, $this->rootTeam))->not->toContain($this->gpPaket->id);
});

it('Raw-Pfad TeamScope::applyVisible folgt derselben Regel', function () {
    $ids = fn ($team) => TeamScope::applyVisible(DB::table('foodalchemist_gps'), 'foodalchemist_gps.team_id', $team)->pluck('id')->all();

    expect($ids($this->childA))->toContain($this->gpBasis->id)->not->toContain($this->gpPaket->id);

    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect($ids($this->childA))->toContain($this->gpPaket->id);
});

it('begrenzen() filtert nur globale Paket-Zeilen und lässt teamgebundene durch', function () {
    $roh = fn ($team) => StammPaket::begrenzen(DB::table('foodalchemist_gps'), 'foodalchemist_gps', $team)->pluck('id')->all();

    expect($roh($this->childB))->toContain($this->gpBasis->id, $this->gpEigen->id)->not->toContain($this->gpPaket->id);

    StammPaket::freischalten($this->rootTeam); $this->alleLieferantenFrei($this->rootTeam);
    expect($roh($this->childB))->toContain($this->gpPaket->id);
});

it('Klassen-Regeln: nur basis|stamm_paket, und stamm_paket nur bei globalen Zeilen', function () {
    expect(fn () => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'sp|x', 'name' => 'X', 'stamm_class' => 'irgendwas']))
        ->toThrow(InvalidArgumentException::class, 'ungueltig');

    expect(fn () => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'sp|y', 'name' => 'Y', 'stamm_class' => 'stamm_paket']))
        ->toThrow(InvalidArgumentException::class, 'globalen Zeilen');
});

it('PFLICHT (Demo-Team-6-Fall): ein Team mit ausschließlich team-eigenen Zeilen sieht exakt so viele wie vorher', function () {
    // Demo: alles in Team 6 (GP, Lieferanten, Artikel, Preise), global ist leer. Hier: Team „childB" als Team 6,
    // dazu fremde globale basis-/paket-Zeilen aus dem beforeEach, die NICHT mitzählen dürfen.
    $team = $this->childB;
    $zeilen = ['gp' => 3, 'sup' => 2, 'la' => 4, 'preis' => 5];
    foreach (range(1, $zeilen['gp']) as $i) {
        $this->makeGp($team, "T6-GP {$i}");
    }
    $lieferanten = collect(range(1, $zeilen['sup']))->map(fn ($i) => FoodAlchemistSupplier::create(['team_id' => $team->id, 'name' => "T6-Lief {$i}"]));
    $artikel = collect(range(1, $zeilen['la']))->map(fn ($i) => FoodAlchemistSupplierItem::create(['team_id' => $team->id, 'supplier_id' => $lieferanten[0]->id, 'designation' => "T6-LA {$i}"]));
    foreach (range(1, $zeilen['preis']) as $i) {
        FoodAlchemistPrice::create(['team_id' => $team->id, 'supplier_item_id' => $artikel[0]->id, 'price' => $i, 'status' => '0']);
    }

    // Eigene Zeilen: exakt die angelegte Anzahl, unabhängig vom Opt-in.
    foreach ([false, true] as $optin) {
        $optin ? StammPaket::freischalten($team) : null;
        $eigen = fn (string $m) => $m::query()->visibleToTeam($team)->where('team_id', $team->id)->count();
        expect($eigen(FoodAlchemistGp::class))->toBe($zeilen['gp'])
            ->and($eigen(FoodAlchemistSupplier::class))->toBe($zeilen['sup'])
            ->and($eigen(FoodAlchemistSupplierItem::class))->toBe($zeilen['la'])
            ->and($eigen(FoodAlchemistPrice::class))->toBe($zeilen['preis']);
    }

    // Ohne Opt-in kommen zu den eigenen Zeilen nur die globalen basis-Zeilen dazu, nie paket.
    StammPaket::abbestellen($team);
    expect(FoodAlchemistGp::query()->visibleToTeam($team)->count())->toBe($zeilen['gp'] + 1)            // + gpBasis
        ->and(FoodAlchemistSupplierItem::query()->visibleToTeam($team)->count())->toBe($zeilen['la'] + 1); // + laBasis
});

it('Listenpreis folgt dem Artikel: Preis eines stamm_paket-Artikels ist ohne Opt-in nicht lesbar (Scope), mit Opt-in schon', function () {
    $preisBasis = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->laBasis->id, 'price' => 1.5, 'status' => '0']);
    $preisPaket = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->laPaket->id, 'price' => 9.9, 'status' => '0']);
    $eigenerLa = FoodAlchemistSupplierItem::create(['team_id' => $this->childB->id, 'supplier_id' => $this->supBasis->id, 'designation' => 'Eigen']);
    $preisEigen = FoodAlchemistPrice::create(['team_id' => $this->childB->id, 'supplier_item_id' => $eigenerLa->id, 'price' => 2.2, 'status' => '0']);
    $teamPreisAufPaket = FoodAlchemistPrice::create(['team_id' => $this->childB->id, 'supplier_item_id' => $this->laPaket->id, 'price' => 8.8, 'status' => '0']);

    $ids = fn () => FoodAlchemistPrice::query()->stammSichtbar($this->childB)->pluck('id')->all();

    // Ohne Opt-in: Basis- und Eigenpreise ja, Preise am Paket-Artikel nein (auch der Team-Preis hängt am unsichtbaren Artikel).
    expect($ids())->toContain($preisBasis->id, $preisEigen->id)->not->toContain($preisPaket->id, $teamPreisAufPaket->id);

    // Roh-SQL-Pfad (Subquery wie activePriceSubquery): derselbe Scope im Query-Builder.
    $roh = FoodAlchemistPrice::query()->stammSichtbar($this->childB)->where('supplier_item_id', $this->laPaket->id)->count();
    expect($roh)->toBe(0);

    StammPaket::freischalten($this->childB); $this->alleLieferantenFrei($this->childB);
    expect($ids())->toContain($preisPaket->id, $teamPreisAufPaket->id);
});

it('geschlossen (Folge-PR 3a): alle Preis-Lesewege prüfen die Stamm-Sichtbarkeit selbst — Team ohne Opt-in kein Paket-Preis, ohne Team nur der Listenpreis', function () {
    $ps = app(\Platform\FoodAlchemist\Services\PriceService::class);
    $liste = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->laPaket->id, 'price' => 9.9, 'status' => '0']);
    $team = FoodAlchemistPrice::create(['team_id' => $this->childB->id, 'supplier_item_id' => $this->laPaket->id, 'price' => 8.8, 'status' => '0']);
    $basis = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->laBasis->id, 'price' => 1.5, 'status' => '0']);
    $subq = fn (int $itemId, $t) => DB::table('foodalchemist_supplier_items')->where('id', $itemId)
        ->selectSub($ps->activePriceSubquery('foodalchemist_supplier_items.id', $t)->toBase(), 'p')->value('p');

    // Team ohne Opt-in: kein Weg liefert einen Paket-Preis — auch nicht den eigenen Teampreis
    expect($ps->activeFor($this->laPaket->id, $this->childB))->toBeNull()
        ->and($subq($this->laPaket->id, $this->childB))->toBeNull()
        ->and($ps->historyFor($this->laPaket->id, $this->childB))->toHaveCount(0)
        ->and($ps->preisTrendBulk([$this->laPaket->id], $this->childB))->toBe([]);
    // Ohne Team = Listenpreis-/Systemsicht (Bugfix io 27.10., Akazienhonig): der Listenpreis des Stamm-Artikels zählt, ein Mandanten-Teampreis nie
    expect($ps->activeFor($this->laPaket->id)?->id)->toBe($liste->id)
        ->and((float) $subq($this->laPaket->id, null))->toBe(9.9)
        ->and($ps->nurListenpreis(DB::table('foodalchemist_prices AS p'), 'p')->where('p.supplier_item_id', $this->laPaket->id)->pluck('id')->all())->toBe([$liste->id])
        // basis-Artikel unberührt
        ->and($ps->activeFor($this->laBasis->id)?->id)->toBe($basis->id)
        ->and($ps->activeFor($this->laBasis->id, $this->childB)?->id)->toBe($basis->id);

    // mit Opt-in: der Teampreis gewinnt (Team-Stufe), andere Teams ohne Opt-in sehen weiter nichts
    StammPaket::freischalten($this->childB); $this->alleLieferantenFrei($this->childB);
    expect($ps->activeFor($this->laPaket->id, $this->childB)?->id)->toBe($team->id)
        ->and((float) $subq($this->laPaket->id, $this->childB))->toBe(8.8)
        ->and($ps->activeFor($this->laPaket->id, $this->childA))->toBeNull()
        ->and($ps->activeFor($this->laPaket->id)?->id)->toBe($liste->id);   // ohne Team: Listenpreis, nie der Teampreis von Kind B

    // konfigurierter Master sieht den Stamm (Annahme wie oben), also auch dessen Listenpreis
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    StammPaket::flush();
    expect($ps->activeFor($this->laPaket->id, $this->rootTeam)?->id)->toBe($liste->id);
});
