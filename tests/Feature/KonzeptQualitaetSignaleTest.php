<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\SignalObjectService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/** VK-Gericht-Attribute mit kundenfähigem Standard-Wording ⇒ löst `konzept_ohne_wording` nicht aus. */
const KQ_GERICHT_SAUBER = ['is_sales_recipe' => true, 'sales_wording_standard' => 'Kundenfähiger Gerichtname'];

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S4a — Tranche C Fundament + die zwei frame-freien Konzept-Checks.
 *
 * Der Kern dieser Etappe ist nicht die Zahl der Checks, sondern die **Arbeitsmenge**:
 * gemessen wird nur, was in Gebrauch ist (aktiv / am Angebot / im Foodbook). Ein
 * Entwurf ist bewusst unfertig — würde er zählen, wäre die normale Arbeit ein Fehler.
 * Deshalb hat hier jeder Check einen Negativfall auf der Entwurfs-Seite.
 *
 * Zweitens wird die Objekt-Auflösung um den kind `concept` erweitert (betroffene /
 * trifftObjekt / signaleAmObjekt) — vorher kannte das Panel nur Rezepte und GPs.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
});

/** Metrik über alle Ebenen hinweg per key finden. */
function kqMetrik(array $ebenen, string $key): array
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

it('führt die Konzept-Ebene getrennt und kennt beide Tranche-C-Typen', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    // Eigene Keys hier, die vollständige geordnete Liste im jüngsten Tranche-C-Test.
    expect($e)->toHaveKey('konzept')
        ->and($e['konzept']['label'])->toBe('Konzepte')
        ->and(array_column($e['konzept']['metriken'], 'key'))
        ->toContain('konzept_slot_luecke')
        ->toContain('konzept_ohne_wording');

    // S4a = die zwei frame-freien Checks, S4b-1 = die zwei frame-gestützten
    // (s. KonzeptFrameSignaleTest), S4b-2 = Dramaturgie (s. KonzeptDramaturgieSignalTest).
    $konzept = array_filter(SignalTyp::cases(), fn (SignalTyp $t) => $t->istKonzeptQualitaet());
    expect($konzept)->toHaveCount(5)
        // Die zwei Tranchen dürfen sich nicht überschneiden — sonst zählt ein Typ doppelt.
        ->and(SignalTyp::KonzeptSlotLuecke->istRezeptQualitaet())->toBeFalse()
        ->and(SignalTyp::RezeptVerwaist->istKonzeptQualitaet())->toBeFalse();
});

it('zählt ein sauberes Konzept in Gebrauch bei keinem Check mit (Negativfall)', function () {
    $k = $this->makeConcept($this->rootTeam, 'Sommer-Buffet');
    $this->makeConceptSlot($k, ['sales_recipe_id' => $this->makeRecipe($this->rootTeam, '[FIX] Lachs | Spargel', KQ_GERICHT_SAUBER)->id]);

    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect(kqMetrik($e, 'konzept_slot_luecke')['wert'])->toBe(0)
        ->and(kqMetrik($e, 'konzept_ohne_wording')['wert'])->toBe(0);
});

it('ignoriert Entwürfe, Vorlagen und archivierte Konzepte (Arbeitsmengen-Grenze)', function () {
    // Alle drei sind maximal kaputt: kein einziger Slot.
    $this->makeConcept($this->rootTeam, 'Entwurf', ['status' => 'draft']);
    $this->makeConcept($this->rootTeam, 'Vorlage', ['is_template' => true]);
    $this->makeConcept($this->rootTeam, 'Alt', ['status' => 'archiviert']);

    expect(kqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'konzept_slot_luecke')['wert'])->toBe(0);
});

it('flaggt ein aktives Konzept ohne belegten Inhalts-Slot', function () {
    $k = $this->makeConcept($this->rootTeam, 'Leeres Menü');
    // Struktur-Slots sind kein Inhalt — ein Konzept aus nur Kopfzeilen bleibt leer.
    $this->makeConceptSlot($k, ['type' => 'header', 'title' => 'Vorspeisen', 'is_pflicht' => false]);

    $m = kqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'konzept_slot_luecke');

    expect($m['wert'])->toBe(1)
        ->and($m['signal']['typ'])->toBe(SignalTyp::KonzeptSlotLuecke)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Warnung);
});

it('flaggt einen unbesetzten Pflicht-Slot, aber nicht einen unbesetzten optionalen', function () {
    $pflicht = $this->makeConcept($this->rootTeam, 'Menü mit Loch');
    $this->makeConceptSlot($pflicht, ['sales_recipe_id' => $this->makeRecipe($this->rootTeam, '[FIX] Lachs | Spargel', KQ_GERICHT_SAUBER)->id]);
    $this->makeConceptSlot($pflicht, ['position' => 2]);                       // leer + Pflicht

    $optional = $this->makeConcept($this->rootTeam, 'Menü mit Kann-Slot');
    $this->makeConceptSlot($optional, ['sales_recipe_id' => $this->makeRecipe($this->rootTeam, '[FIX] Reh | Rotkohl', KQ_GERICHT_SAUBER)->id]);
    $this->makeConceptSlot($optional, ['position' => 2, 'is_pflicht' => false]); // leer, aber optional

    $items = $this->dq->betroffene($this->rootTeam, 'konzept_slot_luecke');

    expect(array_column($items, 'name'))->toBe(['Menü mit Loch'])
        ->and($items[0]['kind'])->toBe('concept')
        ->and($items[0]['id'])->toBe($pflicht->id);
});

it('flaggt eine Gericht-Zeile, deren Wording-Kette auf den internen Namen fällt', function () {
    // Ohne sales_wording_standard und ohne Slot-Wording druckt die Kette „[FIX] A | B".
    $ohne = $this->makeConcept($this->rootTeam, 'Menü ohne Wording');
    $nackt = $this->makeRecipe($this->rootTeam, '[FIX] Zander | Linsen', ['is_sales_recipe' => true]);
    $this->makeConceptSlot($ohne, ['sales_recipe_id' => $nackt->id, 'wording' => null]);

    // Dasselbe nackte Gericht, aber der Slot trägt den Kundentext ⇒ Kette greift.
    $mit = $this->makeConcept($this->rootTeam, 'Menü mit Slot-Wording');
    $this->makeConceptSlot($mit, ['sales_recipe_id' => $nackt->id, 'wording' => 'Zander auf Berglinsen']);

    $items = $this->dq->betroffene($this->rootTeam, 'konzept_ohne_wording');

    expect(array_column($items, 'name'))->toBe(['Menü ohne Wording'])
        ->and($this->dq->countFor($this->rootTeam, 'konzept_ohne_wording'))->toBe(1);
});

it('zählt ein Konzept über Foodbook-Nutzung zur Arbeitsmenge, auch als Entwurf', function () {
    $k = $this->makeConcept($this->rootTeam, 'Entwurf im Buch', ['status' => 'draft']);

    expect($this->dq->countFor($this->rootTeam, 'konzept_slot_luecke'))->toBe(0);

    $fb = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::create([
        'team_id' => $this->rootTeam->id, 'label' => 'Buch 2027',
    ]);
    $kap = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::create([
        'team_id' => $this->rootTeam->id, 'foodbook_id' => $fb->id, 'title' => 'Buffets', 'position' => 1,
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::create([
        'team_id' => $this->rootTeam->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref',
        'concept_id' => $k->id, 'position' => 1, 'visible' => true,
    ]);

    // Im Buch referenziert = beim Kunden sichtbar ⇒ die Lücke zählt jetzt.
    expect($this->dq->countFor($this->rootTeam, 'konzept_slot_luecke'))->toBe(1);
});

it('löst Konzept-Objekte in beide Richtungen auf (trifftObjekt + signaleAmObjekt)', function () {
    $kaputt = $this->makeConcept($this->rootTeam, 'Menü mit Loch');
    $sauber = $this->makeConcept($this->rootTeam, 'Menü sauber');
    $this->makeConceptSlot($sauber, ['sales_recipe_id' => $this->makeRecipe($this->rootTeam, '[FIX] Lachs | Spargel', KQ_GERICHT_SAUBER)->id]);

    expect($this->dq->trifftObjekt($this->rootTeam, 'konzept_slot_luecke', 'concept', $kaputt->id))->toBeTrue()
        ->and($this->dq->trifftObjekt($this->rootTeam, 'konzept_slot_luecke', 'concept', $sauber->id))->toBeFalse()
        // Der kind muss passen: dieselbe ID als Rezept gelesen darf die Konzept-Metrik nie treffen.
        ->and($this->dq->trifftObjekt($this->rootTeam, 'konzept_slot_luecke', 'recipe', $kaputt->id))->toBeFalse();

    $this->dq->emittiereSignale($this->rootTeam);

    $treffer = app(SignalObjectService::class)->signaleAmObjekt($this->rootTeam, 'concept', $kaputt->id);

    expect(array_column($treffer, 'type'))->toContain('konzept_slot_luecke')
        ->and(app(SignalObjectService::class)->signaleAmObjekt($this->rootTeam, 'concept', $sauber->id))->toBe([]);
});

it('emittiert je Konzept-Check ein Signal mit Metrik im Payload', function () {
    $k = $this->makeConcept($this->rootTeam, 'Menü mit Loch');
    $nackt = $this->makeRecipe($this->rootTeam, '[FIX] Zander | Linsen', ['is_sales_recipe' => true]);
    $this->makeConceptSlot($k, ['sales_recipe_id' => $nackt->id, 'wording' => null]);
    $this->makeConceptSlot($k, ['position' => 2]);   // leerer Pflicht-Slot

    $this->dq->emittiereSignale($this->rootTeam);

    $signale = \Platform\FoodAlchemist\Models\FoodAlchemistSignal::visibleToTeam($this->rootTeam)
        ->get()->filter(fn ($s) => $s->type->istKonzeptQualitaet());

    expect($signale)->toHaveCount(2);
    foreach ($signale as $s) {
        expect($s->payload['ebene'])->toBe('Konzepte')
            ->and($s->payload['metrik'])->toStartWith('konzept_')
            ->and($s->payload['anzahl'])->toBe(1)
            ->and($s->source)->toBe('data-quality');
    }
});

it('rendert das Konzept im Signal-Panel mit Concepter-Sprung und Objekt-Sicht', function () {
    // Die Fläche selbst muss mit: kein Test prüfte bisher, dass ein NICHT-Rezept-Objekt
    // im Panel überhaupt eine Zeile bekommt — ohne kind-Zweig fiele es auf reinen Text
    // zurück (Route-Fehler wären erst im Browser sichtbar, s. V-012).
    $this->actingAs($this->makeUser($this->rootTeam, 'Panel User'));
    $k = $this->makeConcept($this->rootTeam, 'Menü mit Loch');
    $this->dq->emittiereSignale($this->rootTeam);

    $sig = \Platform\FoodAlchemist\Models\FoodAlchemistSignal::visibleToTeam($this->rootTeam)
        ->where('type', SignalTyp::KonzeptSlotLuecke->value)->firstOrFail();

    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Signale\DetailPanel::class)
        ->dispatch('signal-selected', id: $sig->id);

    expect(array_column($lw->viewData('betroffen')['items'], 'kind'))->toBe(['concept']);
    // Query-Trenner steht im HTML escaped (&amp;) — darum in zwei Teilen geprüft.
    $lw->assertSee('Menü mit Loch')
        ->assertSee('concepter?tab=concepts', false)
        ->assertSee('sel=' . $k->id, false);

    // „was noch?" muss auch für Konzepte auflösen (kind-Allowlist im SignalObjectService).
    $lw->call('objektWaehlen', 'concept', $k->id)->assertSet('objektId', $k->id);
    expect(array_column($lw->viewData('objektSignale'), 'type'))->toBe(['konzept_slot_luecke']);
});

it('scopet Konzept-Checks auf das Team (kein Leak über die Hierarchie hinaus)', function () {
    $this->makeConcept($this->childA, 'Kind-A-Menü');

    expect($this->dq->countFor($this->childA, 'konzept_slot_luecke'))->toBe(1)
        ->and($this->dq->countFor($this->childB, 'konzept_slot_luecke'))->toBe(0);
});
