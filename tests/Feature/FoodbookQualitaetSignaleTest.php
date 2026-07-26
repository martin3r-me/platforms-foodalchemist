<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\SignalObjectService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S4c-1 — Tranche D Fundament + die zwei struktur-basierten Foodbook-Checks.
 *
 * Zwei Dinge unterscheiden diese Tranche von C und werden hier festgehalten:
 *
 * 1. **Zwei Arbeitsmengen statt einer.** `foodbook_kapitel_leer` misst nur benutzte
 *    Bücher (Status/Phase/Versand-Snapshot) — ein Entwurf hat leere Kapitel, weil er
 *    ein Entwurf ist. `foodbook_skizze_ungeerdet` hängt dagegen am Kapitel-Go: dort hat
 *    ein Mensch „Anlegen" gedrückt, das ist die Grenze, nicht der Buch-Status.
 * 2. **Karenzzeit beim Go.** Ohne LLM-Provider bleibt eine Skizze bewusst queued und
 *    retrybar (`materialisiereFreitextIdee`, graceful) — sofort zu alarmieren wäre
 *    Rauschen. Erst nach 48 h steckt sie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
});

/** Metrik über alle Ebenen hinweg per key finden. */
function fqMetrik(array $ebenen, string $key): array
{
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
    }
    throw new RuntimeException("Metrik {$key} nicht gefunden");
}

/** Kapitel-Go, dessen Karenzzeit (48 h) abgelaufen ist — überall derselbe Abstand. */
function fqGoAbgelaufen(): array
{
    return ['released_at' => now()->subDays(3)];
}

it('führt die Foodbook-Ebene getrennt und kennt beide Tranche-D-Typen', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect($e)->toHaveKey('foodbook')
        ->and($e['foodbook']['label'])->toBe('Foodbooks')
        // Vollständige, geordnete Liste der Ebene — S4c-2 (Ziel/Stale) hängt hier an.
        ->and(array_column($e['foodbook']['metriken'], 'key'))
        ->toBe(['foodbook_kapitel_leer', 'foodbook_skizze_ungeerdet']);

    $foodbook = array_filter(SignalTyp::cases(), fn (SignalTyp $t) => $t->istFoodbookQualitaet());
    expect($foodbook)->toHaveCount(2)
        // Die drei Tranchen dürfen sich nicht überschneiden — sonst zählt ein Typ doppelt.
        ->and(SignalTyp::FoodbookKapitelLeer->istKonzeptQualitaet())->toBeFalse()
        ->and(SignalTyp::FoodbookKapitelLeer->istRezeptQualitaet())->toBeFalse()
        ->and(SignalTyp::KonzeptSlotLuecke->istFoodbookQualitaet())->toBeFalse();
});

it('zählt ein sauber befülltes Foodbook in Gebrauch bei keinem Check mit (Negativfall)', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Sommer 2027');
    $this->makeFoodbookBlock($this->makeChapter($fb, ['title' => 'Buffets']));

    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect(fqMetrik($e, 'foodbook_kapitel_leer')['wert'])->toBe(0)
        ->and(fqMetrik($e, 'foodbook_skizze_ungeerdet')['wert'])->toBe(0);
});

it('ignoriert Entwürfe und archivierte Bücher beim Kapitel-Check (Arbeitsmengen-Grenze)', function () {
    // Beide maximal kaputt: kein einziges Kapitel.
    $this->makeFoodbook($this->rootTeam, 'Entwurf', ['status' => 'draft']);
    $this->makeFoodbook($this->rootTeam, 'Alt', ['status' => 'archiviert']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(0);
});

it('zählt einen Entwurf ab Phase kalkulation zur Arbeitsmenge', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Buch in Kalkulation', ['status' => 'draft', 'phase' => 'befuellung']);
    $this->makeChapter($fb, ['title' => 'Vorspeisen']);

    // Befüllung = Arbeitsstand, das leere Kapitel ist normal.
    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(0);

    // Kalkulation = die Befüllung ist erklärt-fertig ⇒ die Lücke zählt jetzt.
    $fb->update(['phase' => 'kalkulation']);
    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(1);
});

it('erkennt beide Schreibweisen des Aktiv-Status (Vokabular-Drift aktiv/active)', function () {
    // Die Migration kommentiert `aktiv`, das Leitstellen-Dropdown schreibt `active`
    // (index.blade.php:135). Solange der Kanon offen ist, darf ein live geschaltetes Buch
    // an keiner der beiden Schreibweisen durchfallen — sonst misst der Check am UI vorbei.
    $this->makeFoodbook($this->rootTeam, 'Deutsch geschrieben', ['status' => 'aktiv']);
    $this->makeFoodbook($this->rootTeam, 'Englisch geschrieben', ['status' => 'active']);
    $this->makeFoodbook($this->rootTeam, 'Versendet', ['status' => 'versendet']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(3);
});

it('flaggt ein aktives Buch ohne jedes befüllte Kapitel (zweiter Zweig)', function () {
    // Ein Buch ohne Kapitel hat kein LEERES Kapitel — ohne zweiten Zweig käme es durch.
    $this->makeFoodbook($this->rootTeam, 'Leeres Buch');

    $m = fqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'foodbook_kapitel_leer');

    expect($m['wert'])->toBe(1)
        ->and($m['signal']['typ'])->toBe(SignalTyp::FoodbookKapitelLeer)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Warnung);
});

it('behandelt Struktur-Blöcke und unsichtbare Blöcke nicht als Inhalt', function () {
    $nurKopf = $this->makeFoodbook($this->rootTeam, 'Nur Kopfzeilen');
    $this->makeFoodbookBlock($this->makeChapter($nurKopf), ['type' => 'header', 'label' => 'Vorspeisen']);

    $versteckt = $this->makeFoodbook($this->rootTeam, 'Inhalt ausgeschaltet');
    $this->makeFoodbookBlock($this->makeChapter($versteckt), ['visible' => false]);

    $konzeptBlock = $this->makeFoodbook($this->rootTeam, 'Konzept-Block');
    $this->makeFoodbookBlock($this->makeChapter($konzeptBlock), [
        'type' => 'concept_ref', 'concept_id' => $this->makeConcept($this->rootTeam, 'Paket')->id,
    ]);

    $items = $this->dq->betroffene($this->rootTeam, 'foodbook_kapitel_leer');

    // concept_ref befüllt genauso wie recipe_ref (Spec 19 Duality) ⇒ nur die zwei anderen.
    expect(array_column($items, 'name'))->toBe(['Inhalt ausgeschaltet', 'Nur Kopfzeilen'])
        ->and($items[0]['kind'])->toBe('foodbook');
});

it('wertet ein Eltern-Kapitel als Klammer, nicht als leeres Kapitel', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Baum-Buch');
    $eltern = $this->makeChapter($fb, ['title' => 'Buffets']);
    $kind = $this->makeChapter($fb, ['title' => 'Warm', 'parent_id' => $eltern->id, 'position' => 2]);
    $this->makeFoodbookBlock($kind);

    // Das Eltern-Kapitel hat selbst keinen Block — sein Inhalt hängt am Unterkapitel.
    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(0);

    // Fällt der Inhalt des Unterkapitels weg, ist es als Blatt selbst der Befund.
    $kind->blocks()->delete();
    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(1);
});

it('flaggt eine queued Skizze erst nach der Karenzzeit nach dem Go', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Kreativ-Buch', ['status' => 'draft']);
    $frisch = $this->makeChapter($fb, ['released_at' => now()->subHour()]);
    $this->makeDishIdea($frisch);

    // Der Go dispatcht die Jobs sofort; eine Stunde später ist die Skizze in Arbeit.
    expect($this->dq->countFor($this->rootTeam, 'foodbook_skizze_ungeerdet'))->toBe(0);

    $frisch->update(['released_at' => now()->subDays(3)]);
    expect($this->dq->countFor($this->rootTeam, 'foodbook_skizze_ungeerdet'))->toBe(1);
});

it('greift beim Skizzen-Check auch im Entwurf — der Go ist die Grenze, nicht der Status', function () {
    // Bewusst ein Entwurf, der beim Kapitel-Check ausgeschlossen wäre.
    $fb = $this->makeFoodbook($this->rootTeam, 'Entwurf mit Go', ['status' => 'draft', 'phase' => 'befuellung']);
    $this->makeDishIdea($this->makeChapter($fb, fqGoAbgelaufen()));

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(0)
        ->and($this->dq->countFor($this->rootTeam, 'foodbook_skizze_ungeerdet'))->toBe(1);
});

it('zählt keine Skizze ohne Go und keine, aus der etwas geworden ist', function () {
    // Kein Go: die Skizze ist reine Kreativ-Arbeit, niemand hat sie erden wollen.
    $ohneGo = $this->makeFoodbook($this->rootTeam, 'Ohne Go');
    $this->makeDishIdea($this->makeChapter($ohneGo));

    // Mit Go, aber jeder Ausgang außer „queued und nichts passiert" ist kein Befund.
    $erledigt = $this->makeFoodbook($this->rootTeam, 'Erledigt');
    $kap = $this->makeChapter($erledigt, fqGoAbgelaufen());
    $this->makeDishIdea($kap, ['title' => 'Bestand-Ref', 'sales_recipe_id' => $this->makeRecipe($this->rootTeam, 'Gericht A')->id, 'generation_status' => null]);
    $this->makeDishIdea($kap, ['title' => 'Erzeugt', 'position' => 2, 'generated_recipe_id' => $this->makeRecipe($this->rootTeam, 'Gericht B')->id]);
    $this->makeDishIdea($kap, ['title' => 'Materialisiert', 'position' => 3, 'materialized_at' => now()]);
    $this->makeDishIdea($kap, ['title' => 'Verworfen', 'position' => 4, 'status' => 'verworfen']);
    // KI gelaufen und verloren: anderer Sachverhalt, wird am Kapitel selbst gemeldet.
    $this->makeDishIdea($kap, ['title' => 'Fehlgeschlagen', 'position' => 5, 'generation_status' => 'fehlgeschlagen']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_skizze_ungeerdet'))->toBe(0);
});

it('löst Foodbook-Objekte in beide Richtungen auf (trifftObjekt + signaleAmObjekt)', function () {
    $kaputt = $this->makeFoodbook($this->rootTeam, 'Leeres Buch');
    $sauber = $this->makeFoodbook($this->rootTeam, 'Volles Buch');
    $this->makeFoodbookBlock($this->makeChapter($sauber));

    expect($this->dq->trifftObjekt($this->rootTeam, 'foodbook_kapitel_leer', 'foodbook', $kaputt->id))->toBeTrue()
        ->and($this->dq->trifftObjekt($this->rootTeam, 'foodbook_kapitel_leer', 'foodbook', $sauber->id))->toBeFalse()
        // Der kind muss passen: dieselbe ID als Konzept gelesen darf die Foodbook-Metrik nie treffen.
        ->and($this->dq->trifftObjekt($this->rootTeam, 'foodbook_kapitel_leer', 'concept', $kaputt->id))->toBeFalse();

    $this->dq->emittiereSignale($this->rootTeam);

    $treffer = app(SignalObjectService::class)->signaleAmObjekt($this->rootTeam, 'foodbook', $kaputt->id);

    expect(array_column($treffer, 'type'))->toContain('foodbook_kapitel_leer')
        ->and(app(SignalObjectService::class)->signaleAmObjekt($this->rootTeam, 'foodbook', $sauber->id))->toBe([]);
});

it('emittiert je Foodbook-Check ein Signal mit Metrik im Payload', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Buch mit zwei Befunden');
    $this->makeDishIdea($this->makeChapter($fb, fqGoAbgelaufen()));

    $this->dq->emittiereSignale($this->rootTeam);

    $signale = FoodAlchemistSignal::visibleToTeam($this->rootTeam)
        ->get()->filter(fn ($s) => $s->type->istFoodbookQualitaet());

    expect($signale)->toHaveCount(2);
    foreach ($signale as $s) {
        expect($s->payload['ebene'])->toBe('Foodbooks')
            ->and($s->payload['metrik'])->toStartWith('foodbook_')
            ->and($s->payload['anzahl'])->toBe(1)
            ->and($s->source)->toBe('data-quality');
    }
});

it('rendert das Foodbook im Signal-Panel mit Leitstellen-Sprung und Objekt-Sicht', function () {
    // Die Fläche muss mit: ohne kind-Zweig fiele das Buch auf reinen Text zurück, ein
    // Route-Fehler wäre erst im Browser sichtbar (s. V-012).
    $this->actingAs($this->makeUser($this->rootTeam, 'Panel User'));
    $fb = $this->makeFoodbook($this->rootTeam, 'Leeres Buch');
    $this->dq->emittiereSignale($this->rootTeam);

    $sig = FoodAlchemistSignal::visibleToTeam($this->rootTeam)
        ->where('type', SignalTyp::FoodbookKapitelLeer->value)->firstOrFail();

    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Signale\DetailPanel::class)
        ->dispatch('signal-selected', id: $sig->id);

    expect(array_column($lw->viewData('betroffen')['items'], 'kind'))->toBe(['foodbook']);
    $lw->assertSee('Leeres Buch')
        ->assertSee('foodbooks?fb=' . $fb->id, false);

    // „was noch?" muss auch für Foodbooks auflösen (kind-Allowlist SignalObjectService::KINDS).
    $lw->call('objektWaehlen', 'foodbook', $fb->id)->assertSet('objektId', $fb->id);
    expect(array_column($lw->viewData('objektSignale'), 'type'))->toBe(['foodbook_kapitel_leer']);
});

it('scopet Foodbook-Checks auf das Team (kein Leak über die Hierarchie hinaus)', function () {
    $this->makeFoodbook($this->childA, 'Kind-A-Buch');

    expect($this->dq->countFor($this->childA, 'foodbook_kapitel_leer'))->toBe(1)
        ->and($this->dq->countFor($this->childB, 'foodbook_kapitel_leer'))->toBe(0);
});
