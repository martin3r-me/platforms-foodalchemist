<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Livewire\Signale\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SignalObjectService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · Tranche P / Etappe S3a — Signal-DetailPanel: betroffene Objekte (volle
 * Liste, sortierbar, Punkt 1) + objekt-zentrische Sicht (Punkt 2, das Kern-Feature).
 *
 * Der Kern jedes Tests ist das Paar Positiv-/Negativfall: die Objekt-Sicht muss die
 * echten Mehrfach-Befunde zeigen UND darf ein unbeteiligtes Objekt nicht mitreißen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->signals = app(SignalService::class);
    $this->objekte = app(SignalObjectService::class);
});

/** Lücken-Signal wie es der DataQualityService emittiert (Quelle `data-quality` + payload.metrik). */
function lueckenSignal(SignalService $svc, $team, SignalTyp $typ, string $metrik, int $anzahl = 1)
{
    return $svc->erzeuge($team, $typ, SignalSeverity::Warnung, ucfirst($metrik), [
        'dedup_key' => 'dq-'.$metrik,
        'source' => 'data-quality',
        'payload' => ['metrik' => $metrik, 'anzahl' => $anzahl],
    ]);
}

it('löst die betroffenen Objekte eines Lücken-Signals live auf (nicht aus dem Payload)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Zwiebelconfit');
    $this->makeIngredient($rezept, 'Zwiebeln', null);          // gp_id null ⇒ ungemappt
    $rezept->update(['n_ingredients_unmapped' => 1]);

    // Payload behauptet 7 — die Live-Query kennt genau eines. Die Liste muss der Query folgen.
    $sig = lueckenSignal($this->signals, $this->rootTeam, SignalTyp::RezeptZutatenUngemappt, 'rezept_zutaten_ungemappt', 7);

    $res = $this->objekte->betroffene($this->rootTeam, $sig);

    expect(array_column($res['items'], 'name'))->toBe(['Zwiebelconfit'])
        ->and($res['total'])->toBe(1)          // nicht 7: der Payload-Stand ist veraltet
        ->and($res['gezeigt'])->toBe(1);
});

it('Objekt-Sicht zeigt ALLE offenen Signale am selben Rezept — und nichts am unbeteiligten', function () {
    $betroffen = $this->makeRecipe($this->rootTeam, 'Beurre blanc');
    $this->makeIngredient($betroffen, 'Schalotten', null);
    $betroffen->update(['n_ingredients_unmapped' => 1, 'preparation' => 'kurz']);   // 2 Befunde an einem Objekt

    $sauber = $this->makeRecipe($this->rootTeam, 'Sauber und vollständig');

    lueckenSignal($this->signals, $this->rootTeam, SignalTyp::RezeptZutatenUngemappt, 'rezept_zutaten_ungemappt');
    lueckenSignal($this->signals, $this->rootTeam, SignalTyp::RezeptOhneZubereitung, 'rezept_ohne_zubereitung');

    // Zusätzlich ein Einzelobjekt-Signal (ref_type/ref_id) auf demselben Rezept.
    $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Kritisch, 'Marge unter Ziel', [
        'ref_type' => 'recipe', 'ref_id' => $betroffen->id, 'dedup_key' => 'marge-'.$betroffen->id,
    ]);

    $amObjekt = $this->objekte->signaleAmObjekt($this->rootTeam, 'recipe', $betroffen->id);

    expect(array_column($amObjekt, 'type'))->toBe([
        'marge_unter_ziel',            // kritisch zuerst
        // dann die Warnungen alphabetisch nach Label („Rezept mit …" vor „Rezept ohne …")
        'rezept_zutaten_ungemappt',
        'rezept_ohne_zubereitung',
    ])
        // Negativfall: das saubere Rezept trägt keinen der Befunde.
        ->and($this->objekte->signaleAmObjekt($this->rootTeam, 'recipe', $sauber->id))->toBe([])
        // Kind-Verwechslung: eine Rezept-Metrik trifft nie ein GP mit derselben ID.
        ->and($this->objekte->signaleAmObjekt($this->rootTeam, 'gp', $betroffen->id))->toBe([]);
});

it('zählt geschlossene Signale nicht mit (die Objekt-Sicht ist eine Arbeitsliste)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Erledigt-Fall');
    $sig = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge', [
        'ref_type' => 'recipe', 'ref_id' => $rezept->id,
    ]);
    expect($this->objekte->signaleAmObjekt($this->rootTeam, 'recipe', $rezept->id))->toHaveCount(1);

    $this->signals->abschliessen($this->rootTeam, $sig->id);
    expect($this->objekte->signaleAmObjekt($this->rootTeam, 'recipe', $rezept->id))->toBe([]);
});

it('Panel öffnet über signal-selected, sortiert die Liste und klappt die Objekt-Sicht auf', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Panel User'));

    foreach (['Zander', 'Aal', 'Makrele'] as $i => $name) {
        $r = $this->makeRecipe($this->rootTeam, $name);
        $this->makeIngredient($r, 'irgendwas', null, '100', $i + 1);
        $r->update(['n_ingredients_unmapped' => 1]);
    }
    $sig = lueckenSignal($this->signals, $this->rootTeam, SignalTyp::RezeptZutatenUngemappt, 'rezept_zutaten_ungemappt', 3);
    $erstesRezept = FoodAlchemistRecipe::where('name', 'Aal')->firstOrFail();

    $lw = Livewire::test(DetailPanel::class)
        ->assertSet('signalId', null)
        ->dispatch('signal-selected', id: $sig->id)
        ->assertSet('signalId', $sig->id);

    expect(array_column($lw->viewData('betroffen')['items'], 'name'))->toBe(['Aal', 'Makrele', 'Zander']);

    $lw->call('setSort', 'name_desc');
    expect(array_column($lw->viewData('betroffen')['items'], 'name'))->toBe(['Zander', 'Makrele', 'Aal']);

    // Objekt-Sicht: erst zu, dann auf, dann wieder zu (Toggle) — und nur für das gewählte Objekt geladen.
    expect($lw->viewData('objektSignale'))->toBe([]);
    $lw->call('objektWaehlen', 'recipe', $erstesRezept->id)
        ->assertSet('objektId', $erstesRezept->id);
    expect(array_column($lw->viewData('objektSignale'), 'type'))->toBe(['rezept_zutaten_ungemappt']);

    $lw->call('objektWaehlen', 'recipe', $erstesRezept->id)->assertSet('objektId', null);
});

it('Signale-Seite bindet das Panel in die rechte Fläche ein (vorher ungenutzt)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Seiten User'));

    Livewire::test(ReviewQueue::class)
        ->assertSeeLivewire(DetailPanel::class)
        ->assertSee('activity_signale', false);      // eigener Sidebar-Scope, keine Kollision
});

it('Panel zeigt kein fremdes Signal (Tenancy) und überlebt eine gelöschte Auswahl', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));

    // Signal des Geschwister-Teams: nicht in der Vererbungslinie von childA.
    $fremd = $this->signals->erzeuge($this->childB, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Fremd');

    Livewire::test(DetailPanel::class)
        ->dispatch('signal-selected', id: $fremd->id)
        ->assertViewHas('sig', null)
        ->assertSee('Signal in der Liste');
});

// ── Etappe S3b: Dry-Run (Punkt 3) + Teil-Bulk (Punkt 7) im Panel ───────────

/** Basisrezepte ohne EK ⇒ `br_ek_null`, deterministischer Fixer `recompute`. */
function ekSignal(SignalService $svc, $team, int $anzahl)
{
    return $svc->erzeuge($team, SignalTyp::EkKetteUnvollstaendig, SignalSeverity::Warnung, 'Basisrezepte ohne EK', [
        'dedup_key' => 'dq-br-ek-null',
        'source' => 'data-quality',
        'payload' => ['metrik' => 'br_ek_null', 'anzahl' => $anzahl],
    ]);
}

it('zeigt den Dry-Run zur Auswahl und fixt danach genau diese Objekte', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Bulk User'));

    $a = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $b = $this->makeRecipe($this->rootTeam, 'Jus: Wild');
    $sig = ekSignal($this->signals, $this->rootTeam, 2);

    $lw = Livewire::test(DetailPanel::class)
        ->dispatch('signal-selected', id: $sig->id)
        ->set('auswahl', [(string) $a->id])
        ->call('vorschauZeigen');

    // Vorschau nennt genau das gewählte Objekt — und hat nichts geschrieben.
    expect(array_column($lw->get('vorschau')['items'], 'id'))->toBe([(int) $a->id]);
    expect($a->refresh()->allergens_aggregated_at)->toBeNull();

    $lw->call('teilFixAusfuehren')->assertSet('auswahl', []);   // Auswahl nach dem Lauf leer

    expect($a->refresh()->allergens_aggregated_at)->not->toBeNull()
        ->and($b->refresh()->allergens_aggregated_at)->toBeNull();
});

it('verweigert den Teil-Fix ohne Auswahl — „alles fixen" bleibt in der Signal-Zeile', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Leer User'));

    $r = $this->makeRecipe($this->rootTeam, 'Jus: Lamm');
    $sig = ekSignal($this->signals, $this->rootTeam, 1);

    Livewire::test(DetailPanel::class)
        ->dispatch('signal-selected', id: $sig->id)
        ->call('teilFixAusfuehren')
        ->assertSet('meldung', null);

    expect($r->refresh()->allergens_aggregated_at)->toBeNull();
});

it('hakt mit „alle" alle fixbaren Objekte an und verwirft die Vorschau bei Auswahl-Änderung', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Alle User'));

    $this->makeRecipe($this->rootTeam, 'Jus: Ente');
    $this->makeRecipe($this->rootTeam, 'Jus: Reh');
    $sig = ekSignal($this->signals, $this->rootTeam, 2);

    $lw = Livewire::test(DetailPanel::class)
        ->dispatch('signal-selected', id: $sig->id)
        ->call('alleWaehlen');

    expect($lw->get('auswahl'))->toHaveCount(2);

    // Eine Vorschau darf eine spätere Auswahl-Änderung nicht überleben (sonst zeigt sie
    // Werte zu einer anderen Menge als der Knopf darunter fixt).
    $lw->call('vorschauZeigen');
    expect($lw->get('vorschau'))->not->toBeNull();
    $lw->set('auswahl', []);
    expect($lw->get('vorschau'))->toBeNull();
});
