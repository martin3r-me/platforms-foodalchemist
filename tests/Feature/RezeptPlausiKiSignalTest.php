<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Services\RecipeReviewService;
use Platform\FoodAlchemist\Support\SignalCockpit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S5b (Tranche B) — `rezept_plausi_ki`.
 *
 * Das Signal ist die einzige Rezept-Zeile mit KI-Urteil im Rücken; geprüft wird
 * hier aber nichts mehr (kein Provider im Test nötig), sondern nur gelesen: der
 * Egress lag im Batch aus S5a. Zu beweisen sind vier Dinge:
 *
 *  1. Gezählt wird NUR, was offen UND über der Schwelle ist — ein leiser oder ein
 *     entschiedener Befund darf das Cockpit nicht füllen (Über-Flaggen, §9).
 *  2. Die Auflöse-Kette (`betroffene`/`trifftObjekt`/`countFor`) trägt den Typ wie
 *     jede andere Tranche-A-Zeile.
 *  3. Es gibt bewusst KEINEN Knopf am Signal — der Weg führt ins Rezept.
 *  4. Die Schleife schließt sich: eine Entscheidung am Befund senkt den Zähler
 *     sofort, nicht erst beim nächsten Batch-Lauf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
});

/** Abgelegter Befund (S5a-Zeile) ohne Umweg über den Provider. */
function plausiBefund(
    \Platform\Core\Models\Team $team,
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe,
    float $konfidenz = 0.85,
    string $status = 'offen',
    ?int $zutatId = null,
    string $art = 'hinweis'
): FoodAlchemistRecipeFinding {
    return FoodAlchemistRecipeFinding::create([
        'team_id' => $team->id,
        'recipe_id' => $recipe->id,
        'fingerprint' => sha1($art.'|'.$recipe->id.'|'.$konfidenz.'|'.$status.'|'.(string) $zutatId),
        'kind' => $art,
        'ingredient_id' => $zutatId,
        'ingredient_text' => $zutatId !== null ? 'Kartoffel' : null,
        'reason' => 'Der Name verspricht Pfefferrahm, es steht aber nur Pfefferkorn drin.',
        'confidence' => $konfidenz,
        'auto_applicable' => false,
        'applicability' => 'nur_hinweis',
        'status' => $status,
        'seen_count' => 1,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function plausiMetrik(DataQualityService $dq, \Platform\Core\Models\Team $team): array
{
    foreach ($dq->messeAlleEbenen($team)['rezeptqualitaet']['metriken'] as $m) {
        if ($m['key'] === 'rezept_plausi_ki') {
            return $m;
        }
    }
    throw new RuntimeException('Metrik rezept_plausi_ki nicht gefunden');
}

it('S5b: zählt nur offene Befunde über der Schwelle — leise und entschiedene nicht', function () {
    $laut = $this->makeRecipe($this->rootTeam, 'Pfefferrahm-Sauce');
    $leise = $this->makeRecipe($this->rootTeam, 'Sauce Hollandaise');
    $entschieden = $this->makeRecipe($this->rootTeam, 'Bratensauce');
    $sauber = $this->makeRecipe($this->rootTeam, 'Jus');

    plausiBefund($this->rootTeam, $laut, 0.85);
    // Unter der Schwelle: der Copilot liefert auch Geschmacksfragen — die sind kein Signal.
    plausiBefund($this->rootTeam, $leise, 0.4);
    // Bewusst akzeptiert: „Lass das so" muss halten, sonst ist der Regler wirkungslos.
    plausiBefund($this->rootTeam, $entschieden, 0.9, 'verworfen');

    $m = plausiMetrik($this->dq, $this->rootTeam);
    expect($m['wert'])->toBe(1)
        ->and($m['signal']['typ'])->toBe(SignalTyp::RezeptPlausiKi);

    $ids = array_column($this->dq->betroffene($this->rootTeam, 'rezept_plausi_ki'), 'id');
    expect($ids)->toBe([$laut->id])
        ->and($this->dq->countFor($this->rootTeam, 'rezept_plausi_ki'))->toBe(1)
        ->and($this->dq->trifftObjekt($this->rootTeam, 'rezept_plausi_ki', 'recipe', $laut->id))->toBeTrue()
        ->and($this->dq->trifftObjekt($this->rootTeam, 'rezept_plausi_ki', 'recipe', $sauber->id))->toBeFalse()
        // Ein Rezept-Prädikat trifft nie einen GP, auch bei gleicher id.
        ->and($this->dq->trifftObjekt($this->rootTeam, 'rezept_plausi_ki', 'gp', $laut->id))->toBeFalse();
});

it('S5b: eine Entscheidung am Befund senkt den Zähler sofort', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Pfefferrahm-Sauce');
    $befund = plausiBefund($this->rootTeam, $r, 0.9);

    expect($this->dq->countFor($this->rootTeam, 'rezept_plausi_ki'))->toBe(1);

    app(RecipeFindingService::class)->entscheide($this->rootTeam, $befund->id, 'uebernommen');

    // Ohne diese Kopplung stünde das Signal bis zum nächsten Batch-Lauf auf einem
    // erledigten Befund — genau das Abstumpfen, gegen das Tranche E gebaut wurde.
    expect($this->dq->countFor($this->rootTeam, 'rezept_plausi_ki'))->toBe(0);
});

it('S5b: der Typ ist ein Rezept-Qualitätssignal mit KI-Herkunft und ohne Knopf', function () {
    expect(SignalTyp::RezeptPlausiKi->istRezeptQualitaet())->toBeTrue()
        ->and(SignalTyp::RezeptPlausiKi->istKiUrteil())->toBeTrue()
        // Die deterministische Tranche A bleibt deterministisch (Spec 21 §9).
        ->and(SignalTyp::RezeptZutatenUngemappt->istKiUrteil())->toBeFalse()
        ->and(SignalTyp::KonzeptDramaturgie->istKiUrteil())->toBeFalse();

    $sig = FoodAlchemistSignal::create([
        'team_id' => $this->rootTeam->id,
        'type' => SignalTyp::RezeptPlausiKi,
        'severity' => \Platform\FoodAlchemist\Enums\SignalSeverity::Warnung,
        'title' => 'Rezepte mit offenem KI-Befund',
        'dedup_key' => 'dq-rezept-plausi-ki',
        'payload' => ['metrik' => 'rezept_plausi_ki'],
    ]);

    // Kein Fixer, kein Assist: hinter dem Signal liegt bereits ein KI-Urteil je Befund.
    // Seit 22·H4b sagt das Panel dafür, WO entschieden wird (`navigate`) — der Knopf
    // bleibt aus, und genau das prüft `kiPlan()`.
    expect(SignalCockpit::kiPlan($sig))->toBeNull()
        ->and(SignalCockpit::planFor($sig)['kind'])->toBe('navigate')
        ->and(SignalCockpit::metrik($sig))->toBe('rezept_plausi_ki');
});

it('S5b: die abgelegten Befunde kommen in der Copilot-Form zurück und behalten ihre Zeilen-id', function () {
    $gp = $this->makeGp($this->rootTeam, 'Kartoffel: frisch');
    $r = $this->makeRecipe($this->rootTeam, 'Kartoffelpüree');
    $zutat = $this->makeIngredient($r, 'Kartoffel', $gp, '1000');

    $offen = plausiBefund($this->rootTeam, $r, 0.9, 'offen', $zutat->id, 'hinweis');
    plausiBefund($this->rootTeam, $r, 0.3);                          // leise → nicht im Landeplatz

    $abgelegt = app(RecipeFindingService::class)->offeneBefundeFuer($this->rootTeam, $r->id);

    expect($abgelegt)->toHaveCount(1)
        ->and($abgelegt[0]['finding_id'])->toBe($offen->id)
        ->and($abgelegt[0]['art'])->toBe('hinweis')
        ->and($abgelegt[0]['zutat_id'])->toBe($zutat->id)
        ->and($abgelegt[0]['konfidenz'])->toBe(0.9);

    // Die Anwendbarkeit wird frisch entschieden (nicht aus der Ablage gelesen),
    // die Zeilen-id reist mit — sonst könnte die Fläche die Übernahme nicht vermerken.
    $bewertet = app(RecipeReviewService::class)->bewerte($this->rootTeam, $r->id, $abgelegt);

    expect($bewertet)->toHaveCount(1)
        ->and($bewertet[0]['finding_id'])->toBe($offen->id)
        ->and($bewertet[0]['status'])->toBe('nur_hinweis')
        ->and($bewertet[0]['auto_applicable'])->toBeFalse();
});
