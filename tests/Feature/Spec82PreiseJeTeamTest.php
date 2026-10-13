<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §3 Stufe 3a — Preise je Mandant.
 *
 * Preissuche: Team → Vorfahren → global. Ohne Team: nur Listenpreis (team_id NULL oder
 * Besitzer-Team des Artikels). Schreiben: ein Team schreibt nur Zeilen mit SEINER team_id.
 *
 * Aufbau: ein globaler Stamm-Artikel (team_id NULL) mit globalem Listenpreis, zwei Mandanten
 * NEBENEINANDER (kein Eltern-Team, Spec 82 §8), dazu die Root/Kind-Kette aus dem Harness.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->preise = app(PriceService::class);

    $this->mandant1 = Team::create(['name' => 'Mandant Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->mandant2 = Team::create(['name' => 'Mandant Soulkitchen', 'user_id' => 1, 'personal_team' => false]);

    // globaler Stamm: Lieferant, Artikel (1 kg), GP, Struktur, Listenpreis 10,00
    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Lieferant']);
    $this->stammLa = FoodAlchemistSupplierItem::create([
        'team_id' => null, 'supplier_id' => $sup->id, 'designation' => 'Butter 1 kg', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    $this->stammGp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'spec82-butter|test|test', 'name' => 'Butter']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $this->stammLa->id, 'gp_id' => $this->stammGp->id]);
    $this->stammGp->update(['lead_la_supplier_item_id' => $this->stammLa->id]);
    $this->listenpreis = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->stammLa->id, 'price' => 10.00, 'status' => '0']);

    // Root-eigener Artikel mit Listenpreis 20,00 (Besitzer = rootTeam)
    $rootSup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Root-Lieferant']);
    $this->rootLa = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $rootSup->id, 'designation' => 'Sahne 1 l', 'qty' => 1.0, 'unit_code' => 'l',
    ]);
    $this->rootListe = $this->preise->createFor($this->rootTeam, $this->rootLa, 20.00);

    $this->preisVia = function (int $itemId, ?Team $team) {
        return DB::table('foodalchemist_supplier_items')->where('id', $itemId)
            ->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $team)->toBase(), 'p')
            ->value('p');
    };
});

it('zwei Mandanten: jeder rechnet mit seinem Preis, ohne Team bleibt der Listenpreis — kein Mandantenpreis rutscht in globale Werte', function () {
    $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 8.00);
    $this->preise->createTeamPrice($this->mandant2, $this->stammLa, 12.00);

    expect((float) $this->preise->activeFor($this->stammLa->id, $this->mandant1)->price)->toBe(8.00)
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->mandant2)->price)->toBe(12.00)
        ->and((float) $this->preise->activeFor($this->stammLa->id)->price)->toBe(10.00)          // global = Listenpreis
        ->and((float) ($this->preisVia)($this->stammLa->id, $this->mandant1))->toBe(8.00)
        ->and((float) ($this->preisVia)($this->stammLa->id, $this->mandant2))->toBe(12.00)
        ->and((float) ($this->preisVia)($this->stammLa->id, null))->toBe(10.00)
        // ein Team ohne eigenen Preis fällt auf den Listenpreis zurück
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->childA)->price)->toBe(10.00);

    // Historie: Mandant 1 sieht seine Zeile + den Listenpreis, NIE die von Mandant 2
    $teams = $this->preise->historyFor($this->stammLa->id, $this->mandant1)->pluck('team_id')->all();
    expect($teams)->toBe([$this->mandant1->id, null])
        ->and($this->preise->historyFor($this->stammLa->id)->pluck('team_id')->all())->toBe([null]);

    // Lead-Ampel/Reports lesen roh: nurListenpreis lässt beide Mandantenzeilen weg
    $n = $this->preise->nurListenpreis(DB::table('foodalchemist_prices AS p'), 'p')
        ->where('p.supplier_item_id', $this->stammLa->id)->count();
    expect($n)->toBe(1);
});

it('Team-Stufe schlägt Alter: älterer Teampreis gewinnt gegen neueren Listenpreis', function () {
    $team = $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 7.00);
    // neuerer Listenpreis (höhere id, unbefristet) — der globale Stamm wird nachgepflegt
    $this->listenpreis->update(['valid_to' => now()]);
    $neuListe = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->stammLa->id, 'price' => 11.00, 'status' => '0']);
    expect($neuListe->id)->toBeGreaterThan($team->id);

    expect($this->preise->activeFor($this->stammLa->id, $this->mandant1)->id)->toBe($team->id)
        ->and((float) ($this->preisVia)($this->stammLa->id, $this->mandant1))->toBe(7.00)
        ->and($this->preise->activeFor($this->stammLa->id)->id)->toBe($neuListe->id);

    // innerhalb der Stufe gilt die Aktiv-Ordnung weiter: neuer Teampreis schließt den alten
    $team2 = $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 7.50);
    expect($this->preise->activeFor($this->stammLa->id, $this->mandant1)->id)->toBe($team2->id)
        ->and($team->fresh()->valid_to)->not->toBeNull();
});

it('Vorfahren-Kette: Unter-Teams erben den Preis des Oberteams und schreiben keinen eigenen (Leitplanke e)', function () {
    $ober = $this->preise->createTeamPrice($this->rootTeam, $this->stammLa, 9.00);
    expect((float) $this->preise->activeFor($this->stammLa->id, $this->childA)->price)->toBe(9.00)
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->childB)->price)->toBe(9.00)
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->rootTeam)->price)->toBe(9.00)
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->mandant1)->price)->toBe(10.00); // anderer Mandant: Listenpreis

    expect(fn () => $this->preise->createTeamPrice($this->childA, $this->stammLa, 8.50))
        ->toThrow(RuntimeException::class, 'Unter-Teams erben')
        ->and(PriceService::darfTeampreisSchreiben($this->childA))->toBeFalse()
        ->and(PriceService::darfTeampreisSchreiben($this->rootTeam))->toBeTrue()
        ->and(PriceService::darfTeampreisSchreiben($this->mandant1))->toBeTrue()
        ->and($ober->fresh()->valid_to)->toBeNull();
});

it('nur aktive Teampreise gewinnen: ein abgelaufener Teampreis schlägt keinen Listenpreis (Leitplanke a)', function () {
    $team = $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 7.00);
    $team->update(['valid_to' => now()->subDay()]);

    expect((float) $this->preise->activeFor($this->stammLa->id, $this->mandant1)->price)->toBe(10.00)
        ->and((float) ($this->preisVia)($this->stammLa->id, $this->mandant1))->toBe(10.00);

    // noch gültig (valid_to in der Zukunft) gewinnt weiter
    $team->update(['valid_to' => now()->addDay()]);
    expect((float) $this->preise->activeFor($this->stammLa->id, $this->mandant1)->price)->toBe(7.00);

    // der Listenpreis des Besitzers behält die alte Regel: auch gestempelt bleibt er der Preis
    $this->rootListe->update(['valid_to' => now()->subDay()]);
    expect((float) $this->preise->activeFor($this->rootLa->id, $this->childA)->price)->toBe(20.00)
        ->and((float) $this->preise->activeFor($this->rootLa->id)->price)->toBe(20.00);
});

it('Besitzer-Listenpreis nur bei Sichtbarkeit: ein fremder Mandant sieht den Root-Preis nicht (Leitplanke c)', function () {
    expect($this->preise->activeFor($this->rootLa->id, $this->mandant1))->toBeNull()
        ->and(($this->preisVia)($this->rootLa->id, $this->mandant1))->toBeNull()
        ->and($this->preise->historyFor($this->rootLa->id, $this->mandant1))->toHaveCount(0)
        // wer den Artikel sieht, bekommt den Listenpreis des Besitzers
        ->and((float) $this->preise->activeFor($this->rootLa->id, $this->childB)->price)->toBe(20.00);
});

it('Kette endet an der Kundengrenze: nie Preise des Master-Teams (Leitplanke b)', function () {
    // Altbestand: Mandant hängt unter dem Master (Spec 82 sieht das NICHT vor, §8) — trotzdem kein Durchsickern.
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $master = $this->preise->createTeamPrice($this->rootTeam, $this->stammLa, 5.00);

    expect(PriceService::preisKette($this->childA))->toBe([$this->childA->id])
        ->and(PriceService::preisKette($this->rootTeam))->toBe([$this->rootTeam->id])
        ->and(app(\Platform\FoodAlchemist\Services\FaRechte::class)->kundenHauptTeam($this->childA)->id)->toBe($this->childA->id)
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->childA)->price)->toBe(10.00)  // nicht 5,00 vom Master
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->rootTeam)->price)->toBe(5.00)
        // als Kunden-Hauptteam darf childA hier eigene Preise schreiben
        ->and(PriceService::darfTeampreisSchreiben($this->childA))->toBeTrue()
        ->and($master->fresh()->valid_to)->toBeNull();
});

it('createTeamPrice schreibt nur die eigene team_id, lässt den Listenpreis offen und braucht Sichtbarkeit', function () {
    $p = $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 8.00, '2');
    expect((int) $p->team_id)->toBe($this->mandant1->id)
        ->and($p->status)->toBe('2')
        ->and($this->listenpreis->fresh()->valid_to)->toBeNull();

    // Root-Artikel ist für Mandant 1 nicht sichtbar → kein Preis
    expect(fn () => $this->preise->createTeamPrice($this->mandant1, $this->rootLa, 5.00))
        ->toThrow(RuntimeException::class, 'nicht sichtbar');

    // Besitzer über createTeamPrice = Listenpreis (gleicher Weg wie createFor, schließt Vorgänger)
    $neu = $this->preise->createTeamPrice($this->rootTeam, $this->rootLa, 21.00);
    expect((int) $neu->team_id)->toBe($this->rootTeam->id)
        ->and($this->rootListe->fresh()->valid_to)->not->toBeNull();

    // Kind-Team: weder Teampreis noch Listenpreis am geerbten Artikel
    expect(fn () => $this->preise->createTeamPrice($this->childA, $this->rootLa, 19.00))->toThrow(RuntimeException::class, 'Unter-Teams erben');
    expect(fn () => $this->preise->createFor($this->childA, $this->rootLa, 19.00))->toThrow(RuntimeException::class, 'Besitzer-Team');

    // Guards gelten auch hier
    expect(fn () => $this->preise->createTeamPrice($this->mandant1, $this->stammLa, -1.0))->toThrow(RuntimeException::class, 'Service-Zuschläge');
    expect(fn () => $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 1.0, '7'))->toThrow(RuntimeException::class, 'Status');
});

it('ändern/löschen nur eigene Zeilen: nie fremde Mandanten-, Oberteam- oder Listenpreise', function () {
    $ober = $this->preise->createTeamPrice($this->rootTeam, $this->stammLa, 9.00);
    $m2 = $this->preise->createTeamPrice($this->mandant2, $this->stammLa, 12.00);

    expect(fn () => $this->preise->deleteFor($this->childA, $this->stammLa, $ober->id))->toThrow(RuntimeException::class, 'nicht an diesem Artikel');
    expect(fn () => $this->preise->updatePrice($this->childA, $this->stammLa, $ober->id, ['price' => 1]))->toThrow(RuntimeException::class, 'nicht an diesem Artikel');
    expect(fn () => $this->preise->deleteFor($this->mandant1, $this->stammLa, $m2->id))->toThrow(RuntimeException::class, 'nicht an diesem Artikel');
    expect(fn () => $this->preise->deleteFor($this->mandant1, $this->stammLa, $this->listenpreis->id))->toThrow(RuntimeException::class, 'nicht an diesem Artikel');

    // eigene Zeilen gehen
    expect((float) $this->preise->updatePrice($this->rootTeam, $this->stammLa, $ober->id, ['price' => '8,50'])->price)->toBe(8.50);
    $this->preise->deleteFor($this->mandant2, $this->stammLa, $m2->id);
    expect(FoodAlchemistPrice::find($m2->id))->toBeNull()
        ->and(FoodAlchemistPrice::find($ober->id))->not->toBeNull();
});

it('Preis-Trend bleibt in der Team-Stufe: Mandantenpreis gegen Listenpreis ist kein Sprung', function () {
    $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 30.00);
    expect($this->preise->preisTrendBulk([$this->stammLa->id], $this->mandant1))->toBe([]);   // nur eine Teamzeile

    $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 33.00);
    $t = $this->preise->preisTrendBulk([$this->stammLa->id], $this->mandant1)[$this->stammLa->id];
    expect($t['aktuell'])->toBe(33.00)->and($t['vorher'])->toBe(30.00)
        ->and($this->preise->preisTrendBulk([$this->stammLa->id]))->toBe([]);               // global: nur Listenpreis, kein Vorgänger
});

it('Recompute: Rezept eines Mandanten rechnet mit dessen Preis, globales Rezept mit dem Listenpreis', function () {
    $recompute = app(RecipeRecomputeService::class);
    $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 8.00);
    $this->preise->createTeamPrice($this->mandant2, $this->stammLa, 12.00);

    $r1 = $this->makeRecipe($this->mandant1, 'Beurre blanc Broich');
    $this->makeIngredient($r1, 'Butter', $this->stammGp, '1000');
    $r2 = $this->makeRecipe($this->mandant2, 'Beurre blanc Soulkitchen');
    $this->makeIngredient($r2, 'Butter', $this->stammGp, '1000');

    $recompute->recomputePipeline($r1->id);
    $recompute->recomputePipeline($r2->id);

    expect((float) $r1->fresh()->ek_total_eur)->toBe(8.00)
        ->and((float) $r2->fresh()->ek_total_eur)->toBe(12.00)
        // dasselbe Rezept ohne Team gerechnet = Listenpreis
        ->and(array_sum(array_filter($recompute->zeilenKosten($r1->fresh(), null))))->toBe(10.00);

    // Mandant 1 ändert seinen Preis → Mandant 2 bleibt unberührt
    $this->preise->createTeamPrice($this->mandant1, $this->stammLa, 9.00);
    $recompute->recomputePipeline($r1->id);
    expect((float) $r1->fresh()->ek_total_eur)->toBe(9.00)
        ->and((float) $r2->fresh()->ek_total_eur)->toBe(12.00);
});

it('MCP artikel_preise: Oberteam legt Teampreis an, Unter-Team und fremder Mandant dürfen nicht, Oberteam ändert/löscht selbst', function () {
    $registry = app(ToolRegistry::class);
    $root = new ToolContext($this->makeUser($this->rootTeam, 'Root'), $this->rootTeam);
    $kind = new ToolContext($this->makeUser($this->childA, 'Kind'), $this->childA);
    $fremd = new ToolContext($this->makeUser($this->mandant1, 'Fremd'), $this->mandant1);
    $run = fn (string $n, array $a, ToolContext $k) => $registry->get($n)->execute($a, $k);

    $post = $run('foodalchemist.artikel_preise.POST', ['id' => $this->stammLa->id, 'preis' => 9.0], $root);
    expect($post->success)->toBeTrue((string) ($post->error ?? ''))
        ->and($post->data['art'])->toBe('teampreis')
        ->and($post->data['team_id'])->toBe($this->rootTeam->id)
        ->and($this->listenpreis->fresh()->valid_to)->toBeNull();

    expect($run('foodalchemist.artikel_preise.POST', ['id' => $this->rootLa->id, 'preis' => 22.0], $root)->data['art'])->toBe('listenpreis')
        ->and($run('foodalchemist.artikel_preise.POST', ['id' => $this->stammLa->id, 'preis' => 8.0], $kind)->errorCode)->toBe('ACCESS_DENIED')
        ->and($run('foodalchemist.artikel_preise.POST', ['id' => $this->rootLa->id, 'preis' => 1.0], $fremd)->errorCode)->toBe('NOT_FOUND');

    $pid = $post->data['price_id'];
    expect($run('foodalchemist.artikel_preise.DELETE', ['id' => $this->stammLa->id, 'price_id' => $pid], $fremd)->errorCode)->toBe('ACCESS_DENIED')
        ->and($run('foodalchemist.artikel_preise.PUT', ['id' => $this->stammLa->id, 'price_id' => $pid, 'felder' => ['price' => 1]], $kind)->errorCode)->toBe('ACCESS_DENIED')
        ->and($run('foodalchemist.artikel_preise.PUT', ['id' => $this->stammLa->id, 'price_id' => $this->listenpreis->id, 'felder' => ['price' => 1]], $root)->errorCode)->toBe('ACCESS_DENIED');

    expect($run('foodalchemist.artikel_preise.PUT', ['id' => $this->stammLa->id, 'price_id' => $pid, 'felder' => ['price' => 8.5]], $root)->success)->toBeTrue()
        ->and((float) FoodAlchemistPrice::find($pid)->price)->toBe(8.5)
        ->and((float) $this->preise->activeFor($this->stammLa->id, $this->childA)->price)->toBe(8.5)   // Kind erbt
        ->and($run('foodalchemist.artikel_preise.DELETE', ['id' => $this->stammLa->id, 'price_id' => $pid], $root)->data['deleted'])->toBeTrue()
        ->and(FoodAlchemistPrice::find($pid))->toBeNull();
});
