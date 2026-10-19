<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\AusgabeDriftService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S4c-2 — der Rest von Tranche D. Beide Checks messen gegen ein FREMDES Soll,
 * und daraus folgt die tragende Abgrenzung dieser Etappe:
 *
 * 1. **`foodbook_ziel_verfehlt` braucht ein Planungs-Gerüst** — genau wie S4b-1 auf der
 *    Konzept-Ebene. Die Kapitel-Ziele werden ausschließlich innerhalb der Coverage
 *    ausgewertet; ohne Gerüst gibt es keinen Befund und auch keine Fläche, die einen zeigt.
 *    Gemeldet wird nur die rote Lage, „3 von 5 da" bleibt Arbeitsstand.
 * 2. **`foodbook_stale` hat eine EIGENE, dritte Arbeitsmenge** — nur freigegebene Bücher.
 *    In der Phase `kalkulation` sollen Preise sich bewegen; dort zu alarmieren wäre genau
 *    das Über-Flaggen aus Spec 21 §9. Die Drift selbst kommt aus `AusgabeDriftService` (veröffentlichter Link gegen
 *    Live-VK, Spec 85 F5) — dieselbe Wahrheit wie `vk_anpassung_empfohlen`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
    $this->frames = app(PlanningFrameService::class);

    /**
     * Ein VK-Gericht mit Standard-Darreichung (Preis-Wahrheit an der Darreichung). Als gebundene Closure statt freier
     * Funktion, weil die Fixture-Helfer des Traits protected sind.
     *
     * @return array{0:\Platform\FoodAlchemist\Models\FoodAlchemistRecipe,1:FoodAlchemistRecipeDarreichung}
     */
    $this->gerichtMitVk = function (string $name, float $vk, ?\Platform\Core\Models\Team $team = null): array {
        $team ??= $this->rootTeam;
        $sf = FoodAlchemistServierform::firstOrCreate(
            ['code' => 'unbestimmt', 'team_id' => $team->id], ['label' => 'Unbestimmt']
        );
        $gericht = $this->makeRecipe($team, $name, [
            'is_sales_recipe' => true, 'sales_wording_standard' => $name, 'sales_net' => $vk,
        ]);
        $darr = FoodAlchemistRecipeDarreichung::create([
            'team_id' => $team->id, 'recipe_id' => $gericht->id, 'serving_form_id' => $sf->id,
            'is_standard' => true, 'sales_net' => $vk, 'sales_gross' => round($vk * 1.19, 2),
        ]);

        return [$gericht, $darr];
    };
});

/** Metrik über alle Ebenen hinweg per key finden. */
function fzMetrik(array $ebenen, string $key): array
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

// ── foodbook_ziel_verfehlt ──────────────────────────────────────────────

it('führt beide S4c-2-Typen in der Foodbook-Ebene und schließt Tranche D ab', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    // Reihenfolge des Registers bis hierher; `foodbook_kapitel_ohne_text` kam mit Spec 03
    // L2b dazu (der Fixer fehlte vorher) und wird von FoodbookKapitelTextSignalTest geführt.
    expect(array_slice(array_column($e['foodbook']['metriken'], 'key'), 0, 4))->toBe([
        'foodbook_kapitel_leer', 'foodbook_skizze_ungeerdet', 'foodbook_ziel_verfehlt', 'foodbook_stale',
    ]);

    $foodbook = array_filter(SignalTyp::cases(), fn (SignalTyp $t) => $t->istFoodbookQualitaet());
    expect($foodbook)->toHaveCount(5)
        ->and(SignalTyp::FoodbookZielVerfehlt->istKonzeptQualitaet())->toBeFalse()
        ->and(SignalTyp::FoodbookStale->istRezeptQualitaet())->toBeFalse();
});

it('meldet ohne Planungs-Gerüst kein verfehltes Kapitel-Ziel', function () {
    // Kapitel-Ziel gesetzt, NULL Gerichte — inhaltlich der Positivfall. Ohne Gerüst wertet
    // aber weder Coverage noch UI es aus; ein Signal wäre ein Befund ohne Fläche.
    $fb = $this->makeFoodbook($this->rootTeam, 'Ohne Gerüst');
    $this->makeChapter($fb, ['title' => 'Vorspeisen', 'target_count' => 3]);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_ziel_verfehlt'))->toBe(0);
});

it('flaggt ein unbesetztes Mengengerüst, nicht aber ein teilweise erfülltes', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Menüfolge 2027');
    $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $kapitel = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'target_count' => 3]);

    // NULL von 3 ⇒ rot.
    $items = $this->dq->betroffene($this->rootTeam, 'foodbook_ziel_verfehlt');
    expect(array_column($items, 'name'))->toBe(['Menüfolge 2027'])
        ->and($items[0]['kind'])->toBe('foodbook');

    // 1 von 3 ⇒ gelb (teilerfuellt) — Arbeitsstand, kein Signal.
    [$gericht] = ($this->gerichtMitVk)('Ceviche', 12.00);
    $this->makeFoodbookBlock($kapitel, ['sales_recipe_id' => $gericht->id]);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_ziel_verfehlt'))->toBe(0);
});

it('vererbt das Kapitel-Ziel n-tief und rollt den Ist-Bezug über die Nachfahren hoch', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Baum-Buch');
    $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);

    // Ziel steht an der Klammer, Inhalt hängt zwei Ebenen darunter.
    $eltern = $this->makeChapter($fb, ['title' => 'Buffets', 'target_count' => 1]);
    $mitte = $this->makeChapter($fb, ['title' => 'Warm', 'parent_id' => $eltern->id, 'position' => 2]);
    $blatt = $this->makeChapter($fb, ['title' => 'Fleisch', 'parent_id' => $mitte->id, 'position' => 3]);

    // Noch nichts drin: das geerbte Ziel reißt auf allen drei Ebenen.
    expect($this->dq->countFor($this->rootTeam, 'foodbook_ziel_verfehlt'))->toBe(1);

    // Ein Gericht ganz unten erfüllt das Ziel der Klammer (Rollup über Nachfahren).
    [$gericht] = ($this->gerichtMitVk)('Kalbsbraten', 24.00);
    $this->makeFoodbookBlock($blatt, ['sales_recipe_id' => $gericht->id]);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_ziel_verfehlt'))->toBe(0);
});

it('flaggt eine gerissene Kapitel-Preisspanne, nicht aber eine Anker-Abweichung', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Preis-Buch');
    $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $kapitel = $this->makeChapter($fb, ['title' => 'Hauptgänge', 'price_max' => 20]);
    [$gericht] = ($this->gerichtMitVk)('Steinbutt', 30.00);
    $this->makeFoodbookBlock($kapitel, ['sales_recipe_id' => $gericht->id]);

    // Ø 30 € über dem Deckel 20 € ⇒ rot.
    expect($this->dq->countFor($this->rootTeam, 'foodbook_ziel_verfehlt'))->toBe(1);

    // Nur ein Anker (kein Deckel), >15 % daneben ⇒ gelb, also kein Signal.
    $kapitel->update(['price_max' => null, 'price_anchor' => 20]);
    expect($this->dq->countFor($this->rootTeam, 'foodbook_ziel_verfehlt'))->toBe(0);
});

// ── foodbook_stale ──────────────────────────────────────────────────────
// Spec 85 F5 (Dominique 19.10.): Quelle ist die Ausgabe-Drift des veröffentlichten Links ({@see AusgabeDriftService}); ob ein
// Link driftet, prüft AusgabeDriftTest end-to-end. Hier: welche Bücher daraus als „überholt" gelten (dritte Arbeitsmenge).

/** AusgabeDrift liefert für das Team die genannten Ausgaben als gedriftet (doc_type => [ids]). */
function fzDrift(object $test, array $jeTeam): void
{
    $mock = Mockery::mock(AusgabeDriftService::class);
    app()->instance(AusgabeDriftService::class, $mock);
    $mock->shouldReceive('abgedriftet')->andReturnUsing(
        function (\Platform\Core\Models\Team $team) use ($jeTeam) {
            $out = [];
            foreach ($jeTeam[(int) $team->id] ?? [] as [$typ, $id]) {
                $out[] = ['ref_type' => $typ, 'ref_id' => $id, 'doc_type' => $typ, 'doc_id' => $id, 'label' => 'x', 'dedup_key' => "d-{$typ}-{$id}",
                    'outlet_id' => null, 'zeilen' => [['label' => 'G', 'frozen' => 30.0, 'live' => 36.0, 'delta_pct' => 20.0, 'richtung' => 'erhoehen']], 'max_delta_pct' => 20.0];
            }

            return $out;
        });
    $test->dq = app(DataQualityService::class);
}

it('flaggt ein freigegebenes Buch, dessen veröffentlichter Link Preise neben der Kalkulation zeigt', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Beim Kunden', ['status' => 'versendet']);

    fzDrift($this, []);   // Link deckungsgleich
    expect($this->dq->countFor($this->rootTeam, 'foodbook_stale'))->toBe(0);

    fzDrift($this, [$this->rootTeam->id => [['foodbook', $fb->id]]]);
    $m = fzMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'foodbook_stale');
    expect($m['wert'])->toBe(1)
        ->and($m['signal']['typ'])->toBe(SignalTyp::FoodbookStale)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'foodbook_stale'), 'name'))->toBe(['Beim Kunden']);
});

it('lässt ein Buch in der Kalkulation in Ruhe — dort sollen Preise sich bewegen', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'In Kalkulation', ['status' => 'draft', 'phase' => 'kalkulation']);
    fzDrift($this, [$this->rootTeam->id => [['foodbook', $fb->id]]]);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_stale'))->toBe(0);

    // Freigabe-Phase ⇒ dasselbe Buch zählt.
    $fb->update(['phase' => 'freigabe']);
    expect($this->dq->countFor($this->rootTeam, 'foodbook_stale'))->toBe(1);
});

it('Drift an einer anderen Ausgabe mit derselben ID (Speisekarte, Angebot) trifft das Buch nicht', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Beim Kunden', ['status' => 'versendet']);
    fzDrift($this, [$this->rootTeam->id => [['speisekarte', $fb->id], ['angebot', $fb->id]]]);

    expect($this->dq->countFor($this->rootTeam, 'foodbook_stale'))->toBe(0);
});

it('löst den Typ am Objekt auf und scopet ihn aufs Team', function () {
    $fb = $this->makeFoodbook($this->childA, 'Kind-A-Buch', ['status' => 'versendet']);
    fzDrift($this, [$this->childA->id => [['foodbook', $fb->id]]]);

    expect($this->dq->trifftObjekt($this->childA, 'foodbook_stale', 'foodbook', $fb->id))->toBeTrue()
        // Der kind muss passen — dieselbe ID als Konzept gelesen darf nie treffen.
        ->and($this->dq->trifftObjekt($this->childA, 'foodbook_stale', 'concept', $fb->id))->toBeFalse()
        // Kein Leak über die Hierarchie hinaus.
        ->and($this->dq->countFor($this->childB, 'foodbook_stale'))->toBe(0);
});
