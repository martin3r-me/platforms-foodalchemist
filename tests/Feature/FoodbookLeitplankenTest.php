<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kreative Leitplanken: Foodbook-Guideline (Kundentyp + Default-Niveau + Default-Convenience)
 * + Auflösungs-Kaskade Kapitel/Konzept (concept.level) → Foodbook-Default → Segment.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->fb = app(FoodbookService::class);
    $this->settings = app(TeamSettingsService::class);
});

it('normNiveau kanonisiert haute → haute_cuisine, lässt Kanon durch, null bleibt null', function () {
    expect(TeamSettingsService::normNiveau('haute'))->toBe('haute_cuisine')
        ->and(TeamSettingsService::normNiveau('gehoben'))->toBe('gehoben')
        ->and(TeamSettingsService::normNiveau(''))->toBeNull()
        ->and(TeamSettingsService::normNiveau(null))->toBeNull();
});

it('Kaskade: Segment-Default greift, wenn Foodbook + Kapitel leer sind', function () {
    $this->settings->update($this->rootTeam, ['kitchen_type' => 'catering']); // → niveau gehoben, convenience teil
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook);
    expect($lp['niveau'])->toBe('gehoben')
        ->and($lp['convenience'])->toBe('teil_convenience')
        ->and($lp['niveau_quelle'])->toBe('segment');
});

it('Kaskade: Foodbook-Default überschreibt das Segment', function () {
    $this->settings->update($this->rootTeam, ['kitchen_type' => 'catering']); // Segment = gehoben
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $this->fb->update($this->rootTeam, $foodbook->id, [
        'kundentyp' => 'hotellerie',
        'default_niveau' => 'haute_cuisine',
        'default_convenience' => 'from_scratch',
    ]);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh());
    expect($lp['kundentyp'])->toBe('hotellerie')
        ->and($lp['niveau'])->toBe('haute_cuisine')
        ->and($lp['convenience'])->toBe('from_scratch')
        ->and($lp['niveau_quelle'])->toBe('foodbook');
});

it('Kaskade: Kapitel-Niveau (concept.level, haute) führt über den Foodbook-Default — normalisiert', function () {
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $this->fb->update($this->rootTeam, $foodbook->id, ['default_niveau' => 'klassisch']);
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Premium-Menü', 'level' => 'haute']);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh(), $concept);
    expect($lp['niveau'])->toBe('haute_cuisine')      // haute → kanonisch, gewinnt über klassisch
        ->and($lp['niveau_quelle'])->toBe('kapitel');
});

// ── Spec 19 E3.4: Dimensions-Kaskade (Zielgruppen + Foodbook-Default-Dimensionen + quellen) ──

it('E3.4: neue Dimension-Keys existieren + sind leer ohne Stempelung', function () {
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook);
    expect($lp)->toHaveKeys(['zielgruppen', 'event_type_id', 'serving_form_id', 'service_moment_ids', 'quellen'])
        ->and($lp['zielgruppen'])->toBe([])
        ->and($lp['event_type_id'])->toBeNull()
        ->and($lp['serving_form_id'])->toBeNull()
        ->and($lp['service_moment_ids'])->toBe([])
        ->and($lp['quellen']['zielgruppen'])->toBeNull();
});

it('E3.4: Zielgruppen — Foodbook-Default greift ohne Kapitel', function () {
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $tg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Tagungsgast']);
    $foodbook->targetGroups()->sync([$tg->id]);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh());
    expect($lp['zielgruppen'])->toBe([['id' => (int) $tg->id, 'name' => 'Tagungsgast']])
        ->and($lp['quellen']['zielgruppen'])->toBe('foodbook');
});

it('E3.4: Zielgruppen — Kapitel-Stempelung schlägt den Foodbook-Default', function () {
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $fbTg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Mitarbeiter']);
    $kapTg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'VIP-Gala']);
    $foodbook->targetGroups()->sync([$fbTg->id]);
    $kapitel = $this->fb->addKapitel($this->rootTeam, $foodbook->id, ['title' => 'Gala-Abend']);
    $kapitel->targetGroups()->sync([$kapTg->id]);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh(), null, $kapitel->refresh());
    expect($lp['zielgruppen'])->toBe([['id' => (int) $kapTg->id, 'name' => 'VIP-Gala']])
        ->and($lp['quellen']['zielgruppen'])->toBe('kapitel');
});

it('E3.4: Zielgruppen — leeres Kapitel erbt vom Eltern-Kapitel', function () {
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $tg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett-Gast']);
    $eltern = $this->fb->addKapitel($this->rootTeam, $foodbook->id, ['title' => 'Bankett']);
    $eltern->targetGroups()->sync([$tg->id]);
    $kind = $this->fb->addKapitel($this->rootTeam, $foodbook->id, ['title' => 'Vorspeisen'], $eltern->id);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh(), null, $kind->refresh());
    expect($lp['zielgruppen'])->toBe([['id' => (int) $tg->id, 'name' => 'Bankett-Gast']])
        ->and($lp['quellen']['zielgruppen'])->toBe('kapitel');
});

it('E3.4: Eventtyp/Servierform/Einsatzmomente lösen aus dem Foodbook-Default auf', function () {
    $eventType = \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Gala/Bankett']);
    $servingForm = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::firstOrCreate(['team_id' => $this->rootTeam->id, 'code' => 'buffet'], ['label' => 'Buffet']);
    $lunch = \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Lunch']);

    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $this->fb->update($this->rootTeam, $foodbook->id, [
        'default_event_type_id' => $eventType->id,
        'default_serving_form_id' => $servingForm->id,
    ]);
    $foodbook->serviceMoments()->sync([$lunch->id]);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh());
    expect($lp['event_type_id'])->toBe((int) $eventType->id)
        ->and($lp['serving_form_id'])->toBe((int) $servingForm->id)
        ->and($lp['service_moment_ids'])->toBe([(int) $lunch->id])
        ->and($lp['quellen']['event_type_id'])->toBe('foodbook')
        ->and($lp['quellen']['serving_form_id'])->toBe('foodbook')
        ->and($lp['quellen']['service_moment_ids'])->toBe('foodbook');
});

it('Livewire: leitplankeSetzen persistiert + leer setzt zurück auf Erben', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $foodbook = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::first();

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $foodbook->id)
        ->call('leitplankeSetzen', 'default_niveau', 'haute_cuisine')
        ->call('leitplankeSetzen', 'kundentyp', 'enterprise_kette');

    $foodbook->refresh();
    expect($foodbook->default_niveau)->toBe('haute_cuisine')
        ->and($foodbook->kundentyp)->toBe('enterprise_kette');

    // Leer → zurück auf NULL (erbt wieder)
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $foodbook->id)
        ->call('leitplankeSetzen', 'default_niveau', '');
    expect($foodbook->refresh()->default_niveau)->toBeNull();
});
