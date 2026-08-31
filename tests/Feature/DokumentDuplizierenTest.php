<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlockStaffel;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Status cast-agnostisch als String lesen (Model castet teils auf AusgabeStatus-Enum, teils nicht). */
function statusWert($s): string
{
    return $s instanceof \Platform\FoodAlchemist\Enums\AusgabeStatus ? $s->value : (string) $s;
}

/**
 * Tiefe Kopie „Duplizieren" für Foodbook + Speiseplan (Muster SpeisekarteService::dupliziere):
 * Kopf → Struktur → Positionen; Status=Entwurf, Freigabe/Präsentation zurückgesetzt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->team = $this->rootTeam;
});

it('FoodbookService::dupliziere kopiert Kopf + Kapitel + Blöcke + Staffeln und setzt zurück', function () {
    $svc = app(FoodbookService::class);
    $fb = $svc->create($this->team, ['label' => 'Sommer 2027']);
    // Branding + Präsentation direkt am Modell (create whitelistet nur FELDER, kein Branding).
    $fb->update(['brand_color' => '#123456', 'code' => 'FB-1', 'presentation_token' => 'tok123', 'presentation_enabled' => true]);

    $kap = FoodAlchemistFoodbookKapitel::create([
        'team_id' => $this->team->id, 'foodbook_id' => $fb->id, 'position' => 1, 'title' => 'Vorspeisen', 'status' => 'released',
    ]);
    $blk = FoodAlchemistFoodbookBlock::create([
        'team_id' => $this->team->id, 'chapter_id' => $kap->id, 'position' => 1,
        'type' => 'header_neutral', 'label' => 'Kalt', 'price_basis' => 'staffel',
    ]);
    FoodAlchemistFoodbookBlockStaffel::create([
        'team_id' => $this->team->id, 'block_id' => $blk->id, 'position' => 1, 'min_persons' => 10, 'price' => 5.0,
    ]);

    $neu = $svc->dupliziere($this->team, $fb->id);

    expect($neu->id)->not->toBe($fb->id)
        ->and($neu->label)->toBe('Sommer 2027 (Kopie)')
        ->and($neu->code)->toBeNull()
        ->and($neu->brand_color)->toBe('#123456')            // Branding mitkopiert
        ->and($neu->presentation_token)->toBeNull()          // Präsentation zurückgesetzt
        ->and((bool) $neu->presentation_enabled)->toBeFalse();
    expect(statusWert($neu->status))->toBe('entwurf');       // Status zurück auf Entwurf

    $neuKap = $neu->chapters()->get();
    expect($neuKap)->toHaveCount(1)
        ->and($neuKap->first()->title)->toBe('Vorspeisen')
        ->and($neuKap->first()->status)->toBe('draft')
        ->and($neuKap->first()->id)->not->toBe($kap->id);

    $neuBlk = $neuKap->first()->blocks()->get();
    expect($neuBlk)->toHaveCount(1)
        ->and($neuBlk->first()->label)->toBe('Kalt')
        ->and($neuBlk->first()->staffel()->count())->toBe(1);
});

it('SpeiseplanService::dupliziere kopiert Kopf + Linien + Zellen (line_id remappt, keine Starter-Dubletten)', function () {
    $svc = app(SpeiseplanService::class);
    $plan = $svc->create($this->team, ['name' => 'KW-Plan', 'cycle_weeks' => 2]);   // legt 3 Starter-Linien an
    $linie = $plan->lines()->orderBy('sort_order')->first();
    $gericht = $this->makeRecipe($this->team, 'Suppe', ['is_sales_recipe' => true]);
    $svc->addEintrag($this->team, $plan->id, [
        'entry_date' => '2027-06-07', 'mahlzeit' => 'mittag', 'line_id' => $linie->id, 'sales_recipe_id' => $gericht->id,
    ]);

    $neu = $svc->dupliziere($this->team, $plan->id);

    expect($neu->id)->not->toBe($plan->id)
        ->and($neu->name)->toBe('KW-Plan (Kopie)')
        ->and($neu->cycle_weeks)->toBe(2)
        ->and($neu->lines()->count())->toBe(3)               // Original-Linien kopiert, KEINE zusätzlichen Starter
        ->and($neu->entries()->count())->toBe(1);
    expect(statusWert($neu->status))->toBe('entwurf');

    // line_id ist auf eine Linie DES NEUEN Plans remappt (nicht die alte).
    $neuEintrag = $neu->entries()->first();
    expect($neu->lines()->pluck('id')->contains($neuEintrag->line_id))->toBeTrue()
        ->and($neuEintrag->line_id)->not->toBe($linie->id)
        ->and($neuEintrag->sales_recipe_id)->toBe($gericht->id);
});
