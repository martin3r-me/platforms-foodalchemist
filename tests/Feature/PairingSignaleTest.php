<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\Pairing\PairingSignale;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\RezeptAnker;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P8: die vier Pairing-Signale, das Freigabe-Werkzeug und das Schließen des Altbestands.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->a = [];
    foreach (['pumpkin' => 'Kürbis', 'anise' => 'Anis', 'sage' => 'Salbei', 'salmon' => 'Lachs'] as $slug => $name) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
        $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    }
    $this->wissen = fn (string $t, array $z) => DB::table($t)->insertGetId($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $this->konflikt = fn (int $x, int $y) => ($this->wissen)('foodalchemist_anchor_beziehungen',
        ['anchor_a_id' => $x, 'anchor_b_id' => $y, 'art' => 'konflikt', 'achse' => '', 'grundlage' => 'dossier']);
    // n Basisrezepte, die den Anker als (einzigen) Kern tragen
    $this->rezepte = function (string $slug, int $n, bool $gericht = false): array {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $r = $this->makeRecipe($this->rootTeam, "{$slug} {$i}");
            $r->update(['is_sales_recipe' => $gericht]);
            RezeptAnker::gib($r, $this->a[$slug]);
            $out[] = $r;
        }

        return $out;
    };
    $this->svc = fn () => app(PairingSignale::class);
    $this->offen = fn (SignalTyp $t) => FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', $t->value)->where('status', 'offen')->orderBy('id')->get();
    $this->tool = fn (string $name, array $args) => app(ToolRegistry::class)->get($name)
        ->execute($args, new ToolContext($this->user, $this->rootTeam));
});

it('Wissen zur Prüfung: erst ab drei Rezepten, schließt sich nach der Freigabe', function () {
    ($this->wissen)('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['pumpkin'], 'achse' => 'saeure', 'staerke' => 'muss']);
    ($this->rezepte)('pumpkin', 2);
    expect(($this->svc)()->wissenZurPruefung($this->rootTeam))->toBe(0);

    ($this->rezepte)('pumpkin', 1);
    expect(($this->svc)()->wissenZurPruefung($this->rootTeam))->toBe(1);
    $s = ($this->offen)(SignalTyp::PairingWissenPruefen)->sole();
    expect($s->title)->toBe('Kürbis: 1 Wissens-Einträge im Entwurf · Kern in 3 Rezepten')
        ->and($s->payload['eintraege']['bedarfe'])->toBe(1);

    $res = ($this->tool)('foodalchemist.anker_wissen.STATUS', ['anker_id' => $this->a['pumpkin'], 'status' => 'geprueft']);
    expect($res->success)->toBeTrue()
        ->and($res->data['geaendert'])->toBe(1)
        ->and($res->data['profile_neu'])->toBe(3);

    ($this->svc)()->wissenZurPruefung($this->rootTeam);
    expect(($this->offen)(SignalTyp::PairingWissenPruefen))->toHaveCount(0);
});

it('Wissens-Signale nur beim Kurator; schreiben darf auch nur er', function () {
    ($this->wissen)('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['pumpkin'], 'achse' => 'saeure', 'staerke' => 'muss']);
    ($this->rezepte)('pumpkin', 3);
    config(['foodalchemist.master_team_id' => $this->rootTeam->id + 1000]);

    expect(($this->svc)()->wissenZurPruefung($this->rootTeam))->toBe(0)
        ->and(($this->tool)('foodalchemist.anker_wissen.STATUS', ['anker_id' => $this->a['pumpkin'], 'status' => 'geprueft'])->errorCode)
        ->toBe('ACCESS_DENIED');
});

it('Widerspruch: Dossier sagt Konflikt, Messung sagt 3★ — verworfen schließt das Signal', function () {
    ($this->rezepte)('pumpkin', 1);
    $id = ($this->konflikt)($this->a['pumpkin'], $this->a['sage']);
    expect(($this->svc)()->widerspruchWissenMessung($this->rootTeam))->toBe(0);       // ohne 3★ kein Widerspruch

    Harmonie::kante($this->a['pumpkin'], $this->a['sage'], 3);
    expect(($this->svc)()->widerspruchWissenMessung($this->rootTeam))->toBe(1)
        ->and(($this->offen)(SignalTyp::PairingWiderspruchMessung)->sole()->title)
        ->toBe('Kürbis und Salbei: Dossier sagt „stört sich", Foodpairing misst 3★');

    $get = ($this->tool)('foodalchemist.anker_wissen.GET', ['anker_id' => $this->a['pumpkin']]);
    expect($get->data['beziehungen'][0]['messung_3_sterne'])->toBeTrue();

    ($this->tool)('foodalchemist.anker_wissen.STATUS', ['anker_id' => $this->a['pumpkin'], 'status' => 'verworfen',
        'bereich' => 'beziehungen', 'eintrag_id' => $id]);
    ($this->svc)()->widerspruchWissenMessung($this->rootTeam);
    expect(($this->offen)(SignalTyp::PairingWiderspruchMessung))->toHaveCount(0);
});

it('Wissenslücke: erst ab zehn Rezepten und nur ohne jedes Dossier-Wissen', function () {
    ($this->rezepte)('salmon', 9);
    expect(($this->svc)()->wissensluecke($this->rootTeam))->toBe(0);

    ($this->rezepte)('salmon', 1);
    expect(($this->svc)()->wissensluecke($this->rootTeam))->toBe(1)
        ->and(($this->offen)(SignalTyp::PairingWissensluecke)->sole()->title)->toBe('Lachs: Kern in 10 Rezepten, noch kein Anker-Wissen');

    ($this->wissen)('foodalchemist_anchor_komponenten', ['anchor_id' => $this->a['salmon'], 'name' => 'Lachs gebeizt']);
    ($this->svc)()->wissensluecke($this->rootTeam);
    expect(($this->offen)(SignalTyp::PairingWissensluecke))->toHaveCount(0);
});

it('Konflikt im Gericht: zwischen zwei Bestandteilen ja, innerhalb eines Bestandteils nein', function () {
    ($this->konflikt)($this->a['pumpkin'], $this->a['anise']);
    [$kuerbis] = ($this->rezepte)('pumpkin', 1);
    [$anis] = ($this->rezepte)('anise', 1);
    $kuerbis->update(['name' => 'Püree: Kürbis']);
    $anis->update(['name' => 'Sauce: Anis']);
    $einsetzen = fn (FoodAlchemistRecipe $g, FoodAlchemistRecipe $sub, int $pos) => FoodAlchemistRecipeIngredient::create([
        'team_id' => $g->team_id, 'recipe_id' => $g->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name,
        'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $pos]);

    $gericht = $this->makeRecipe($this->rootTeam, 'Kürbis mit Anis');
    $gericht->update(['is_sales_recipe' => true]);
    $einsetzen($gericht, $kuerbis, 1);
    $einsetzen($gericht, $anis, 2);
    app(RezeptProfil::class)->fuer($gericht->id);

    // Beide Anker in EINEM Basisrezept: das ist kein Konflikt zwischen Bestandteilen des Gerichts.
    $mix = $this->makeRecipe($this->rootTeam, 'Mix: Kürbis-Anis');
    RezeptAnker::gib($mix, $this->a['pumpkin']);
    RezeptAnker::gib($mix, $this->a['anise']);
    $nur = $this->makeRecipe($this->rootTeam, 'Suppe: Kürbis-Anis');
    $nur->update(['is_sales_recipe' => true]);
    $einsetzen($nur, $mix, 1);
    app(RezeptProfil::class)->fuer($nur->id);

    expect(($this->svc)()->konfliktImGericht($this->rootTeam))->toBe(1);
    $s = ($this->offen)(SignalTyp::PairingKonfliktImGericht)->sole();
    expect($s->ref_id)->toBe($gericht->id)
        ->and($s->title)->toBe('Kürbis mit Anis: Konflikt: Püree: Kürbis und Sauce: Anis stören sich');
});

it('laufen() ruft die Pairing-Signale statt des alten Detektors', function () {
    ($this->wissen)('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['pumpkin'], 'achse' => 'saeure', 'staerke' => 'muss']);
    ($this->rezepte)('pumpkin', 3);

    app(\Platform\FoodAlchemist\Services\SignalDetektorService::class)->laufen($this->rootTeam);

    expect(($this->offen)(SignalTyp::PairingWissenPruefen))->toHaveCount(1)
        ->and(method_exists(\Platform\FoodAlchemist\Services\SignalDetektorService::class, 'widerspruchWissenGraph'))->toBeFalse();
});

it('Migration schließt die offenen Alt-Signale widerspruch_wissen_graph, Historie bleibt', function () {
    $alt = FoodAlchemistSignal::create(['team_id' => $this->rootTeam->id, 'type' => 'widerspruch_wissen_graph',
        'severity' => 'info', 'status' => 'offen', 'title' => 'Basilikum — 1 Paarung ohne Kante', 'payload' => ['doc_slug' => 'basilikum'],
        'dedup_key' => 'widerspruch-doc-1', 'source' => 'detektor']);
    $erledigt = FoodAlchemistSignal::create(['team_id' => $this->rootTeam->id, 'type' => 'widerspruch_wissen_graph',
        'severity' => 'info', 'status' => 'erledigt', 'title' => 'alt', 'dedup_key' => 'widerspruch-doc-2', 'source' => 'detektor']);

    (require __DIR__.'/../../database/migrations/2026_10_06_100008_close_widerspruch_wissen_graph_signals.php')->up();

    $alt->refresh();
    expect($alt->status->value)->toBe('erledigt')
        ->and($alt->title)->toBe('Basilikum — 1 Paarung ohne Kante')
        ->and($alt->payload['doc_slug'])->toBe('basilikum')
        ->and($alt->payload['auto_geschlossen'])->toContain('Spec 60')
        ->and($erledigt->fresh()->payload)->toBeNull();
});
