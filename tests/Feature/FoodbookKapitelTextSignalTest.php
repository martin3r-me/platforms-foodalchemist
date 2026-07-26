<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 Tranche D · `foodbook_kapitel_ohne_text` — der fünfte und letzte Typ der Tranche.
 *
 * Er war bis hierher bewusst NICHT gebaut: das Kapitel-Textfeld gab es im Editor gar nicht
 * (`foodbook_chapters.description` wurde gedruckt, aber nirgends gepflegt), und ein Signal
 * ohne Fixer ist Rauschen (Spec 21 §9). Spec 03 · L2b hat den Fixer nachgeliefert.
 *
 * Zwei Auslegungen tragen den Check und sind hier festgehalten:
 *
 * 1. **Nur Kapitel, die Inhalt TRAGEN.** Der Check ist die Umkehrung von
 *    `foodbook_kapitel_leer` und teilt dessen Inhalts-Definition (sichtbarer
 *    `concept_ref`/`recipe_ref`-Block). Ein leeres Kapitel braucht keine Hinführung,
 *    sondern Gerichte — beide Signale auf denselben Sachverhalt wären Doppelzählung.
 * 2. **Severity `info`, nicht `warnung`.** Das Kapitel ist druckbar und inhaltlich
 *    vollständig, es ist nur nicht ausformuliert. Als Warnung stünde eine
 *    Formulierungs-Aufgabe neben einem falschen Kundenpreis.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);

    /**
     * Befülltes Kapitel (sichtbarer Gericht-Block) — die Arbeitsmenge des Checks. Als
     * gebundene Closure statt freier Funktion, weil die Fixture-Helfer des Traits
     * protected sind (Muster aus FoodbookZielStaleSignaleTest).
     */
    $this->kapitelMitInhalt = function (
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook $fb,
        array $attrs = []
    ): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel {
        $k = $this->makeChapter($fb, $attrs);
        $this->makeFoodbookBlock($k);

        return $k;
    };
});

it('führt den fünften Tranche-D-Typ und schließt die Tranche ab', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect(array_column($e['foodbook']['metriken'], 'key'))->toBe([
        'foodbook_kapitel_leer', 'foodbook_skizze_ungeerdet', 'foodbook_ziel_verfehlt',
        'foodbook_stale', 'foodbook_kapitel_ohne_text',
    ]);

    expect(SignalTyp::FoodbookKapitelOhneText->istFoodbookQualitaet())->toBeTrue()
        ->and(SignalTyp::FoodbookKapitelOhneText->istKonzeptQualitaet())->toBeFalse()
        ->and(SignalTyp::FoodbookKapitelOhneText->istRezeptQualitaet())->toBeFalse();
});

it('flaggt ein befülltes Kapitel ohne Hinführung — und nur als info', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Angebot Adler');
    ($this->kapitelMitInhalt)($fb);

    $m = collect($this->dq->messeAlleEbenen($this->rootTeam)['foodbook']['metriken'])
        ->firstWhere('key', 'foodbook_kapitel_ohne_text');

    expect($m['wert'])->toBe(1)
        ->and($m['severity'])->toBe('info')             // gap() färbt info-Checks nie rot/gelb
        ->and($m['signal']['typ'])->toBe(SignalTyp::FoodbookKapitelOhneText)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Info);
});

it('zählt ein Kapitel mit Hinführung nicht mit (Negativfall)', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Angebot mit Text');
    ($this->kapitelMitInhalt)($fb, ['description' => 'Wir beginnen leicht und steigern uns.']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(0);
});

it('behandelt Leerzeichen nicht als Text (Haus-Muster der Wording-Checks)', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Angebot mit Space');
    // Das Formular schreibt '' statt NULL; ein versehentliches Space ist kein Kundentext.
    ($this->kapitelMitInhalt)($fb, ['description' => '   ']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(1);
});

it('meldet ein LEERES Kapitel nicht doppelt — dort fehlt Inhalt, nicht Text', function () {
    $leer = $this->makeFoodbook($this->rootTeam, 'Leeres Kapitel');
    $this->makeChapter($leer);                            // kein Block

    // Struktur-/unsichtbare Blöcke sind ebenfalls kein Inhalt (dieselbe Definition wie
    // `foodbook_kapitel_leer` — sonst liefen die zwei Checks auseinander).
    $nurKopf = $this->makeFoodbook($this->rootTeam, 'Nur Kopfzeile');
    $this->makeFoodbookBlock($this->makeChapter($nurKopf), ['type' => 'header', 'label' => 'Vorspeisen']);

    $versteckt = $this->makeFoodbook($this->rootTeam, 'Inhalt ausgeschaltet');
    $this->makeFoodbookBlock($this->makeChapter($versteckt), ['visible' => false]);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(0)
        // …während der Inhalts-Check alle drei sehr wohl meldet: ein Sachverhalt, ein Signal.
        ->and($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_leer'))->toBe(3);
});

it('wertet eine reine Klammer nicht als textlose Position', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Baum-Buch');
    $eltern = $this->makeChapter($fb, ['title' => 'Buffets']);
    // Das Unterkapitel trägt den Inhalt UND einen Text — das Eltern-Kapitel hat keine
    // eigenen Blöcke und fällt über dieselbe Inhalts-Bedingung heraus.
    ($this->kapitelMitInhalt)($fb, ['title' => 'Warm', 'parent_id' => $eltern->id, 'position' => 2,
        'description' => 'Warm vom Buffet.']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(0);
});

it('greift nur an Büchern in Gebrauch (Arbeitsmenge wie der Inhalts-Check)', function () {
    // Ein Entwurf ist bewusst unfertig — ein fehlender Kundentext ist dort kein Mangel.
    $entwurf = $this->makeFoodbook($this->rootTeam, 'Entwurf', ['status' => 'draft', 'phase' => 'befuellung']);
    ($this->kapitelMitInhalt)($entwurf);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(0);

    $entwurf->update(['phase' => 'kalkulation']);
    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(1);
});

it('ignoriert archivierte Kapitel', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Buch mit Altlast');
    ($this->kapitelMitInhalt)($fb, ['description' => 'Aktuelles Kapitel.']);
    ($this->kapitelMitInhalt)($fb, ['title' => 'Alt', 'position' => 2, 'status' => 'archived']);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_kapitel_ohne_text'))->toBe(0);
});

it('emittiert das Signal am Foodbook und löst es in beide Richtungen auf', function () {
    $kaputt = $this->makeFoodbook($this->rootTeam, 'Ohne Hinführung');
    ($this->kapitelMitInhalt)($kaputt);
    $sauber = $this->makeFoodbook($this->rootTeam, 'Mit Hinführung');
    ($this->kapitelMitInhalt)($sauber, ['description' => 'Ein Abend am Wasser.']);

    expect($this->dq->trifftObjekt($this->rootTeam, 'foodbook_kapitel_ohne_text', 'foodbook', $kaputt->id))->toBeTrue()
        ->and($this->dq->trifftObjekt($this->rootTeam, 'foodbook_kapitel_ohne_text', 'foodbook', $sauber->id))->toBeFalse();

    $items = $this->dq->betroffene($this->rootTeam, 'foodbook_kapitel_ohne_text');
    expect(array_column($items, 'name'))->toBe(['Ohne Hinführung'])
        ->and($items[0]['kind'])->toBe('foodbook');

    $this->dq->emittiereSignale($this->rootTeam);

    $sig = FoodAlchemistSignal::visibleToTeam($this->rootTeam)
        ->where('type', SignalTyp::FoodbookKapitelOhneText->value)->firstOrFail();

    expect($sig->payload['ebene'])->toBe('Foodbooks')
        ->and($sig->payload['metrik'])->toBe('foodbook_kapitel_ohne_text')
        ->and($sig->payload['anzahl'])->toBe(1)
        ->and($sig->severity)->toBe(SignalSeverity::Info)
        ->and($sig->source)->toBe('data-quality');
});

it('scopet den Check auf das Team', function () {
    ($this->kapitelMitInhalt)($this->makeFoodbook($this->childA, 'Kind-A-Buch'));

    expect($this->dq->countFor($this->childA, 'foodbook_kapitel_ohne_text'))->toBe(1)
        ->and($this->dq->countFor($this->childB, 'foodbook_kapitel_ohne_text'))->toBe(0);
});
