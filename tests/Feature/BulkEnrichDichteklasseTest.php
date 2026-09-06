<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BehaelterBedarfService;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · B-10 — `dichteklasse` als Schritt der Basisrezept-Schrittfolge.
 *
 * Zu beweisen ist nicht, dass die KI eine Klasse nennt, sondern dass der Schritt den
 * BEHÄLTERBEDARF rechenbar macht: Klasse allein reicht `BehaelterRechner::varianten` nicht, es
 * braucht einen Behälter je Zweck. Deshalb gilt der Schritt erst als erfüllt, wenn beides steht —
 * und der Accept schreibt beides, mit denselben drei Riegeln wie der ✨-Knopf im Editor
 * (Katalog-id · Freigabe je Zweck · Override-First) und ehrlicher Herkunft (`source='ki'`).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(BulkEnrichService::class);

    $container = fn (string $slug, array $eignung) => DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => $slug, 'name' => strtoupper($slug), 'sort_order' => 1, 'familie' => 'GN',
        'laenge_mm' => 530, 'breite_mm' => 325, 'tiefe_mm' => 65, 'volumen_l' => 8.8,
        'nutzfaktor' => 0.85, 'eignung' => json_encode($eignung),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->gn = $container('gn_11_65', ['abfuellen', 'regenerieren', 'ausgabe']);
    $this->eimer = $container('eimer_10l', ['abfuellen', 'transport']);       // NICHT für regenerieren freigegeben

    // Der Fake antwortet strukturell: nur der Dichteklasse-Schritt gibt den Behälter-Katalog mit.
    $gn = $this->gn;
    $eimer = $this->eimer;
    $this->antwort = ['dichteklasse' => 'fluessig', 'skalierung' => 'tiefer_fuellbar',
        'behaelter_je_zweck' => ['abfuellen' => $gn, 'regenerieren' => $eimer, 'transport' => 999999],
        'referenz_menge_kg_je_zweck' => ['abfuellen' => 6.5, 'regenerieren' => 4, 'ausgabe' => 3]];
    app()->singleton(FakeAiProvider::class, fn () => new class($this) extends FakeAiProvider
    {
        public function __construct(private $t)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $user = collect($messages)->last()['content'];
            $werte = str_contains($user, '"behaelter"') ? $this->t->antwort
                : (str_contains($user, 'Geschmacksrichtung') ? ['taste_direction' => 'herzhaft'] : ['description' => 'Bulk.']);

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.77, 'reasoning' => 'Sud, tiefer füllbar.']), 'model' => 'fake', 'usage' => []];
        }
    });

    $this->rezept = fn (array $attr = []) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'dk-' . bin2hex(random_bytes(3)),
        'name' => 'Fond: Geflügel hell', 'status' => 'draft', 'yield_kg' => 12, ...$attr,
    ]);
});

it('B-10: dichteklasse steht in der Basisrezept-Schrittfolge, nicht in der VK-Folge', function () {
    expect(BulkEnrichService::SCHRITTE)->toContain('dichteklasse')
        ->and(BulkEnrichService::SCHRITTE_VK)->not->toContain('dichteklasse')
        ->and(BulkEnrichService::ZIELFELDER['dichteklasse'])->toBe(['feld' => 'dichteklasse', 'source' => 'dichteklasse_source']);
});

it('Lücke = Klasse ODER Behälter fehlt: die Klasse allein macht den Bedarf nicht rechenbar', function () {
    $ohne = ($this->rezept)();
    $nurKlasse = ($this->rezept)(['dichteklasse' => 'fluessig', 'dichteklasse_source' => 'manual']);
    $voll = ($this->rezept)(['dichteklasse' => 'fluessig', 'dichteklasse_source' => 'manual']);
    DB::table('foodalchemist_recipe_containers')->insert([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $voll->id,
        'zweck' => 'abfuellen', 'container_vocab_id' => $this->gn, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->svc->luecken($ohne, ['dichteklasse']))->toBe(['dichteklasse'])
        ->and($this->svc->luecken($nurKlasse, ['dichteklasse']))->toBe(['dichteklasse'])
        ->and($this->svc->luecken($voll, ['dichteklasse']))->toBe([])
        // Refresh: eine manuell gepflegte Klasse MIT Behälter wird nicht neu vorgeschlagen.
        ->and($this->svc->zuAktualisieren($voll, ['dichteklasse']))->toBe([]);
});

it('Accept schreibt Klasse (Lineage ki) UND Behälter je Zweck mit den drei Riegeln — danach rechnet der Bedarf', function () {
    $r = ($this->rezept)();
    $runId = $this->svc->starte($this->rootTeam, [$r->id], ['dichteklasse']);
    $run = $this->svc->status($this->rootTeam, $runId);
    expect($run->status)->toBe(BulkRunStatus::Done)->and((int) $run->failed)->toBe(0);

    // Vorschlag, kein Fach-Write (GL-07) — und der Wert ist das ganze Objekt.
    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->first();
    expect($prop->status)->toBe('offen')
        ->and(json_decode($prop->value, true)['dichteklasse'])->toBe('fluessig')
        ->and($r->fresh()->dichteklasse)->toBeNull()
        ->and(DB::table('foodalchemist_recipe_containers')->where('recipe_id', $r->id)->count())->toBe(0);

    expect($this->svc->alleUebernehmen($this->rootTeam, $runId))->toBe(1);

    $f = $r->fresh();
    expect($f->dichteklasse)->toBe('fluessig')
        ->and($f->dichteklasse_source)->toBe('ki')
        ->and((float) $f->dichteklasse_ai_confidence)->toBe(0.77)
        ->and($f->dichteklasse_ai_reasoning)->toBe('Sud, tiefer füllbar.');

    $zeilen = DB::table('foodalchemist_recipe_containers')->where('recipe_id', $r->id)->whereNull('deleted_at')->get()->keyBy('zweck');
    // abfuellen: GN im Katalog, freigegeben, leer → Behälter + Skalierung + Referenzmenge (hergeleitet ⇒ ki)
    expect((int) $zeilen['abfuellen']->container_vocab_id)->toBe($this->gn)
        ->and($zeilen['abfuellen']->skalierung)->toBe('tiefer_fuellbar')
        ->and((float) $zeilen['abfuellen']->referenz_menge_kg)->toBe(6.5)
        ->and($zeilen['abfuellen']->source)->toBe('ki')
        ->and((float) $zeilen['abfuellen']->ai_confidence)->toBe(0.77)
        // regenerieren: Eimer ist NICHT für regenerieren freigegeben → Riegel 2, keine Zeile, auch keine Menge
        ->and($zeilen->has('regenerieren'))->toBeFalse()
        // transport: id 999999 nicht im Katalog → Riegel 1
        ->and($zeilen->has('transport'))->toBeFalse()
        // ausgabe: Menge ohne Behälter ist bedeutungslos → keine Zeile
        ->and($zeilen->has('ausgabe'))->toBeFalse();

    // Die Kette, um die es geht: vorher null („nichts hinterlegt"), jetzt rechnet der Bedarf —
    // mit der KI-Referenzmenge als Rang 1, aber ehrlich als hergeleitet ausgewiesen.
    $bedarf = app(BehaelterBedarfService::class)->abfuellen($this->rootTeam, $f, 12.0);
    expect($bedarf)->not->toBeNull()
        ->and($bedarf['zweck'])->toBe('abfuellen')
        ->and(collect($bedarf['varianten'])->firstWhere('ist_basis', true)['konfidenz'])->toBe('mittel');   // ki-Menge rechnet nie „hoch"

    // Und der Schritt gilt danach als erfüllt.
    expect($this->svc->luecken($f, ['dichteklasse']))->toBe([]);
});

it('Override-First: manuelle Klasse bleibt, gepflegte Behälter-Zeile behält ihre Felder — nur Leerstellen werden gefüllt', function () {
    $r = ($this->rezept)(['dichteklasse' => 'dicht', 'dichteklasse_source' => 'manual']);
    DB::table('foodalchemist_recipe_containers')->insert([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'zweck' => 'abfuellen', 'container_vocab_id' => $this->eimer, 'skalierung' => 'lagenware',
        'max_schichthoehe_mm' => 40, 'note' => 'Küche: immer Eimer', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $runId = $this->svc->starte($this->rootTeam, [$r->id], ['dichteklasse']);
    expect($this->svc->alleUebernehmen($this->rootTeam, $runId))->toBe(1);

    $f = $r->fresh();
    expect($f->dichteklasse)->toBe('dicht')->and($f->dichteklasse_source)->toBe('manual');

    $z = DB::table('foodalchemist_recipe_containers')->where('recipe_id', $r->id)->where('zweck', 'abfuellen')->first();
    expect((int) $z->container_vocab_id)->toBe($this->eimer)            // gepflegter Behälter bleibt (Riegel 3)
        ->and($z->skalierung)->toBe('lagenware')                          // gepflegte Skalierung bleibt
        ->and((int) $z->max_schichthoehe_mm)->toBe(40)                    // upsert ersetzt NICHT die ganze Zeile
        ->and($z->note)->toBe('Küche: immer Eimer')
        ->and((float) $z->referenz_menge_kg)->toBe(6.5)                   // Leerstelle gefüllt …
        ->and($z->source)->toBe('ki');                                    // … und ehrlich als hergeleitet markiert
});

it('nichts Verwertbares → Vorschlag leer, kein Fach-Write', function () {
    $this->antwort = ['dichteklasse' => 'mittel', 'behaelter_je_zweck' => ['abfuellen' => 424242]];   // Klasse nicht im Vokabular, id nicht im Katalog
    $r = ($this->rezept)();

    $runId = $this->svc->starte($this->rootTeam, [$r->id], ['dichteklasse']);

    expect(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->value('status'))->toBe('leer')
        ->and($r->fresh()->dichteklasse)->toBeNull()
        ->and(DB::table('foodalchemist_recipe_containers')->where('recipe_id', $r->id)->count())->toBe(0);
});
