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

// ── Etappe S3b-2: Policy-Regler (Punkt 8) + Trend-Sparkline (Punkt 4) ──────

it('setzt den Regler für den TYP — nicht für das eine Signal, aus dem er gestellt wurde', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Policy User'));

    // Zwei Signale desselben Typs; der Regler aus dem einen muss am anderen wirken.
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge A', ['dedup_key' => 'm-a']);
    $b = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge B', ['dedup_key' => 'm-b']);

    $lw = Livewire::test(DetailPanel::class)
        ->dispatch('signal-selected', id: $a->id)
        ->call('policyFormUmschalten')
        ->assertSet('policyForm', true)
        ->set('pThreshold', '1')
        ->set('pAcceptedUntil', now()->addMonth()->toDateString())
        ->set('pNote', 'Sourcing läuft')
        ->call('policySpeichern')
        ->assertSet('policyForm', false);

    expect($lw->get('meldung'))->toContain('gilt für alle Signale dieses Typs');

    // Am zweiten Signal desselben Typs steht dieselbe Bewertung.
    $andere = Livewire::test(DetailPanel::class)->dispatch('signal-selected', id: $b->id)->viewData('policy');
    expect($andere['state'])->toBe(\Platform\FoodAlchemist\Services\SignalPolicyService::STATE_AKZEPTIERT)
        ->and($andere['aggregiert'])->toBeTrue()          // 2 offen > Schwelle 1
        ->and($andere['note'])->toBe('Sourcing läuft')
        ->and($andere['gesetzt'])->toBeTrue()
        ->and($andere['geerbt'])->toBeFalse();
});

it('lädt beim Aufklappen die wirksamen Werte und entfernt nur den eigenen Regler', function () {
    // Eltern-Team entscheidet, Kind-Team sieht die Entscheidung (Katalog-Vererbung).
    app(\Platform\FoodAlchemist\Services\SignalPolicyService::class)
        ->setzen($this->rootTeam, SignalTyp::VeraltetePreise, ['threshold' => 7, 'note' => 'Eltern-Entscheid']);

    $this->actingAs($this->makeUser($this->childA, 'Kind Policy User'));
    $sig = $this->signals->erzeuge($this->childA, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'Alt', ['dedup_key' => 'vp-1']);

    $lw = Livewire::test(DetailPanel::class)->dispatch('signal-selected', id: $sig->id);
    expect($lw->viewData('policy')['geerbt'])->toBeTrue();

    // Aufklappen übernimmt die geerbten Werte — sonst würde „Speichern" sie auf leer setzen.
    $lw->call('policyFormUmschalten')
        ->assertSet('pThreshold', '7')
        ->assertSet('pNote', 'Eltern-Entscheid');

    // Ohne eigene Zeile gibt es nichts zu entfernen; die geerbte bleibt unangetastet.
    $lw->call('policyEntfernen');
    expect($lw->get('meldung'))->toContain('keinen eigenen Regler')
        ->and($lw->viewData('policy')['threshold'])->toBe(7);

    // Eigene Zeile überstimmt, danach ist sie entfernbar und die Eltern-Zeile greift wieder.
    $lw->call('policyFormUmschalten')->set('pThreshold', '99')->call('policySpeichern');
    expect($lw->viewData('policy')['threshold'])->toBe(99);
    $lw->call('policyEntfernen');
    expect($lw->viewData('policy')['threshold'])->toBe(7);
});

it('weist eine unbrauchbare Schwelle und ein unlesbares Datum ab, ohne zu speichern', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Murks User'));
    $sig = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Anomalie', ['dedup_key' => 'pa-1']);

    $lw = Livewire::test(DetailPanel::class)
        ->dispatch('signal-selected', id: $sig->id)
        ->call('policyFormUmschalten')
        ->set('pThreshold', 'viele')
        ->call('policySpeichern')
        ->assertSet('policyForm', true);          // Formular bleibt offen, Eingabe erhalten

    expect($lw->get('fehler'))->toContain('Zahl')
        ->and($lw->viewData('policy')['gesetzt'])->toBeFalse();

    $lw->set('pThreshold', '')->set('pAcceptedUntil', 'irgendwann')->call('policySpeichern');
    expect($lw->get('fehler'))->toContain('JJJJ-MM-TT')
        ->and($lw->viewData('policy')['gesetzt'])->toBeFalse();
});

it('zeichnet die Sparkline erst ab zwei Messpunkten und normiert die Reihe', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Trend User'));
    $sig = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge', ['dedup_key' => 'mz-1']);
    $trend = app(\Platform\FoodAlchemist\Services\SignalTrendService::class);

    // Ein Punkt: bewusst KEINE Linie — eine Waagerechte aus einem Punkt behauptet
    // eine Stabilität, die nie gemessen wurde.
    $trend->schreibeSnapshot($this->rootTeam, now()->subDays(2));
    $lw = Livewire::test(DetailPanel::class)->dispatch('signal-selected', id: $sig->id);
    expect($lw->viewData('spark'))->toBeNull();
    $lw->assertSee('Noch keine Reihe');

    // Zwei weitere Läufe mit steigendem Bestand (1 → 2 → 3 offene Signale des Typs).
    $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge 2', ['dedup_key' => 'mz-2']);
    $trend->schreibeSnapshot($this->rootTeam, now()->subDay());
    $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge 3', ['dedup_key' => 'mz-3']);
    $trend->schreibeSnapshot($this->rootTeam, now());

    $spark = Livewire::test(DetailPanel::class)->dispatch('signal-selected', id: $sig->id)->viewData('spark');

    expect($spark['punkte'])->toBe(3)
        ->and($spark['min'])->toBe(1)
        ->and($spark['max'])->toBe(3)
        ->and($spark['letzter'])->toBe(3)
        // ältester links unten (min ⇒ y = Höhe), jüngster rechts oben (max ⇒ y = 0)
        ->and($spark['points'])->toBe('0,24 50,12 100,0');
});

