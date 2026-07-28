<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkProposal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H3c-2 — GOLDEN-RIEGEL vor dem Model-Umbau der beiden Vorschlags-Speicher
 * (`foodalchemist_bulk_proposals` / `foodalchemist_bulk_gp_proposals`, Rest von V-032).
 *
 * **Warum ein Freeze und nicht einfach „Model anlegen".** Beide Tabellen werden heute
 * ausschließlich über `DB::table()` mit handgeschriebenem `json_encode`/`json_decode`
 * beschrieben und gelesen — an acht Stellen. Ein `value`-Cast am Model verlegt genau
 * diese Kodierung in Eloquent; die Fehlerklasse dabei ist nicht der Crash, sondern die
 * **stille Verschiebung**: eine Spalte, die statt `"Text"` (JSON) plötzlich `Text` (roh)
 * enthält, liest sich beim Schreiber unauffällig und bricht erst beim nächsten
 * `json_decode` eines anderen Lesers — bzw. gar nicht, sondern liefert `null` und damit
 * einen still verworfenen Vorschlag.
 *
 * Der Riegel friert darum die **Ablage-Form auf der Spalte** ein, nicht nur den
 * Round-Trip durch denselben Code: nach dem Umbau muss byte-identisch dasselbe in der
 * Datenbank stehen. Zusätzlich eingefroren ist die **Asymmetrie der Leer-Bewertung**
 * zwischen Rezept- und GP-Pfad (V-072) — sie ist ein bekannter, hochgegebener Befund
 * und darf beim Model-Umbau weder versehentlich geheilt noch verschoben werden; „im
 * Vorbeigehen richtig machen" wäre hier genau der unbeaufsichtigte Verhaltenswechsel,
 * den die Bug-Politik Klasse 3 ausschließt.
 *
 * Ergebnis des Umbaus: **eine** Zusicherung hat sich bewegt (der null-Vorschlag liegt jetzt
 * als SQL-NULL statt als String `'null'` — eigener Test, dort begründet), alle übrigen
 * halten byte-identisch. Die beiden `Naht:`-Tests am Ende belegen zusätzlich, dass die
 * neuen Models tatsächlich der Weg sind und nicht bloß danebenstehen (V-025).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(BulkEnrichService::class);

    // Ein Provider, dessen Rückgabe der Test je Fall setzt — die Ablage-Form hängt am
    // Wert-TYP (String · Array · leeres Array · null), nicht am Prompt.
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public static array $werte = [];

        public function chat(array $messages, array $options = []): array
        {
            return [
                'content' => json_encode(['werte' => self::$werte, 'confidence' => 0.8, 'reasoning' => 'Freeze']),
                'model' => 'fake-freeze', 'usage' => [],
            ];
        }
    });
    $setzeWerte = function (array $werte) {
        $p = app(FakeAiProvider::class);
        $p::$werte = $werte;
    };

    $this->rezept = $this->makeRecipe($this->rootTeam, 'Fond: Freeze');
    $this->gp = $this->makeGp($this->rootTeam, 'Freeze-GP');

    /** Ein Ein-Rezept-Lauf über genau einen Schritt — gibt die abgelegte Roh-Zeile zurück. */
    $this->rezeptZeile = function (string $feld, array $werte) use ($setzeWerte): object {
        $setzeWerte($werte);
        $runId = $this->svc->starte($this->rootTeam, [$this->rezept->id], [$feld]);

        return DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', $feld)->first();
    };

    /** Dasselbe für den GP-Zwilling. */
    $this->gpZeile = function (string $feld, array $werte) use ($setzeWerte): object {
        $setzeWerte($werte);
        $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], [$feld]);

        return DB::table('foodalchemist_bulk_gp_proposals')->where('run_id', $runId)->where('field', $feld)->first();
    };
});

it('Freeze: ein Text-Vorschlag liegt als JSON-String auf der Spalte, nicht roh', function () {
    $zeile = ($this->rezeptZeile)('description', ['description' => 'Freeze-Text.']);

    // Die Roh-Spalte, nicht der Round-Trip: `"Freeze-Text."` MIT Anführungszeichen.
    expect($zeile->value)->toBe('"Freeze-Text."')
        ->and(json_decode((string) $zeile->value, true))->toBe('Freeze-Text.')
        ->and($zeile->status)->toBe('offen')
        ->and($zeile->uuid)->not->toBe('')
        ->and((float) $zeile->confidence)->toBe(0.8)
        ->and((int) $zeile->recipe_id)->toBe($this->rezept->id)
        ->and((int) $zeile->team_id)->toBe($this->rootTeam->id);
});

it('Freeze: ein strukturierter Vorschlag liegt als JSON-Objekt auf der Spalte', function () {
    $zeile = ($this->gpZeile)('tags', ['tags' => ['vegan' => true, 'bio' => false]]);

    expect($zeile->value)->toBe('{"vegan":true,"bio":false}')
        ->and(json_decode((string) $zeile->value, true))->toBe(['vegan' => true, 'bio' => false])
        ->and($zeile->status)->toBe('offen')
        ->and((int) $zeile->gp_id)->toBe($this->gp->id);
});

it('Freeze: die Fehler-Zeile lässt `value` als SQL-NULL stehen — nicht als String "null"', function () {
    // Unbekannter Schritt ⇒ `proposeFeld` wirft ⇒ der catch-Zweig legt die Fehler-Zeile an.
    $zeile = ($this->rezeptZeile)('gibtesnicht', []);

    expect($zeile->value)->toBeNull()                                  // NICHT 'null' (4 Zeichen)
        ->and($zeile->status)->toBe('leer')
        ->and($zeile->error)->toContain('Unbekannter Bulk-Schritt');
});

/**
 * DIE EINZIGE VERSCHIEBUNG DES UMBAUS — benannt statt versteckt.
 *
 * Vorher: `json_encode(null)` schrieb den **String** `'null'` (4 Zeichen) in die Spalte;
 * die Fehler-Zeile daneben ließ dieselbe Spalte auf SQL-NULL. Zwei Formen für „kein Wert".
 * Nachher: Laravels JSON-Cast lässt `null` als SQL-NULL stehen (`setAttribute` kodiert
 * ausdrücklich nur nicht-null-Werte) — beide Lagen sind jetzt dieselbe Form.
 *
 * Warum das kein stiller Verhaltenswechsel an einem Konsumenten ist: `json_decode('null')`
 * und SQL-NULL liefern dem Lese-Pfad **beide** `null`, der Accept-Zweig verhält sich also
 * identisch. Die Review-Queue listet nur `status = 'offen'`, und eine null-Zeile ist per
 * Konstruktion `leer` — sie taucht dort nie auf. Es gibt keinen Leser, der zwischen den
 * beiden Formen unterschieden hat. Der Test hält beides fest: die geänderte Ablage-Form
 * UND den unveränderten Status.
 */
it('Umbau: ein null-Vorschlag liegt ab H3c-2 als SQL-NULL statt als String "null"', function () {
    $zeile = ($this->rezeptZeile)('description', ['description' => null]);

    expect($zeile->value)->toBeNull()                                  // vorher: '"null"' als 4-Zeichen-String
        ->and($zeile->status)->toBe('leer')                            // unverändert
        ->and(json_decode((string) $zeile->value, true))->toBeNull();  // Lese-Pfad: vorher wie nachher null
});

it('Freeze: die Leer-Bewertung ist in beiden Pfaden VERSCHIEDEN (V-072) — und bleibt es', function () {
    // Dieselbe Frage, zwei Antworten: das leere Array gilt am Rezept als Vorschlag,
    // am GP als Lücke. Bekannt und hochgegeben — hier eingefroren, NICHT geheilt.
    $rezept = ($this->rezeptZeile)('description', ['description' => []]);
    $gp = ($this->gpZeile)('tags', ['tags' => []]);

    expect($rezept->value)->toBe('[]')
        ->and($rezept->status)->toBe('offen')
        ->and($gp->value)->toBe('[]')
        ->and($gp->status)->toBe('leer');
});

it('Freeze: der Lese-Pfad übernimmt den Wert unverändert ins Fach-Feld', function () {
    $zeile = ($this->rezeptZeile)('description', ['description' => 'Übernahme-Text.']);

    expect($this->svc->uebernehmen($this->rootTeam, (int) $zeile->id))->toBeTrue();
    $this->rezept->refresh();
    expect($this->rezept->description)->toBe('Übernahme-Text.')
        ->and($this->rezept->description_source)->toBe('ki')
        ->and(DB::table('foodalchemist_bulk_proposals')->where('id', $zeile->id)->value('status'))->toBe('uebernommen');
});

it('Freeze: der GP-Lese-Pfad übernimmt ein strukturiertes Feld unverändert', function () {
    $zeile = ($this->gpZeile)('condition', ['condition' => 'frisch']);

    expect($this->svc->uebernehmenGp($this->rootTeam, (int) $zeile->id))->toBeTrue();
    $this->gp->refresh();
    expect($this->gp->condition)->toBe('frisch')
        ->and($this->gp->condition_source)->toBe('ki')
        ->and(DB::table('foodalchemist_bulk_gp_proposals')->where('id', $zeile->id)->value('status'))->toBe('uebernommen');
});

it('Naht: das Model liest dieselbe Zeile typisiert — Wert dekodiert, Status als Vokabular', function () {
    $roh = ($this->rezeptZeile)('description', ['description' => 'Naht-Text.']);
    $m = FoodAlchemistBulkProposal::findOrFail($roh->id);

    // Was das Model zusätzlich leistet: Cast statt Handbetrieb, Vokabular statt Magic String,
    // uuid aus dem Trait statt aus dem Insert-Block.
    expect($m->value)->toBe('Naht-Text.')                              // dekodiert, nicht '"Naht-Text."'
        ->and($m->status)->toBe(BulkProposalStatus::Offen)
        ->and($m->status->istOffen())->toBeTrue()
        ->and($m->uuid)->toBe($roh->uuid)
        ->and($m->confidence)->toBe(0.8);

    $gpRoh = ($this->gpZeile)('tags', ['tags' => ['vegan' => true]]);
    expect(FoodAlchemistBulkGpProposal::findOrFail($gpRoh->id)->value)->toBe(['vegan' => true]);
});

it('Naht: eine Übernahme schreibt das Vokabular, nicht einen freien String', function () {
    $roh = ($this->rezeptZeile)('description', ['description' => 'Vokabular-Text.']);
    $this->svc->uebernehmen($this->rootTeam, (int) $roh->id);

    expect(FoodAlchemistBulkProposal::findOrFail($roh->id)->status)->toBe(BulkProposalStatus::Uebernommen)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('id', $roh->id)->value('status'))->toBe('uebernommen');
});
