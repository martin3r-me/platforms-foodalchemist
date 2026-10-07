<?php

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Settings\SpeiseplanChips;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanChip;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 59: Speiseplan-Vorgaben — Chip-Katalog (Einstellungen), Vorgaben je Plan, Auswertung
 * in „Abwechslung · Woche“ (inkl. Zählfehler-Fix „unbekannt ≠ Fleisch“) und MCP im Lockstep.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(SpeiseplanService::class);
    $this->vsvc = app(SpeiseplanVorgabenService::class);
    $this->montag = Carbon::parse('2026-07-06');
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Kantine', 'start_date' => '2026-07-06']);
    $this->gericht = function (string $key, array $felder = []): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => 'G-' . $key, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => 3.0, 'ek_total_eur' => 1.0,
        ]);
        $r->forceFill(array_merge(['allergens_confidence' => 'high'], $felder))->save();

        return $r->refresh();
    };
    $this->eintrag = fn (FoodAlchemistRecipe $g, string $tag, string $mahlzeit = 'mittag') => $this->svc->addEintrag(
        $this->rootTeam, $this->plan->id, ['entry_date' => $tag, 'mahlzeit' => $mahlzeit, 'sales_recipe_id' => $g->id],
    );
    $this->chip = fn (string $label, array $kriterien, array $mehr = []) => $this->vsvc->chipAnlegen(
        $this->rootTeam, ['label' => $label, 'kriterien' => $kriterien] + $mehr,
    );
});

// ── Chip-Katalog ─────────────────────────────────────────────────────────────

it('Katalog: Settings-Sektion legt Chip an, ändert Feld + Kriterien, legt still', function () {
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'SUP', 'label' => 'Suppen']);

    $lw = Livewire::test(SpeiseplanChips::class)
        ->assertSee('Standard-Chips anlegen')
        ->set('neu.label', 'Suppe')
        ->set('neu.kriterien', ['hauptgruppe:' . $hg->id])
        ->set('neu.default_min', '1')
        ->call('create')
        ->assertSet('fehler', null);

    $chip = FoodAlchemistSpeiseplanChip::where('team_id', $this->rootTeam->id)->sole();
    expect($chip->label)->toBe('Suppe')
        ->and($chip->kriterien)->toBe([['art' => 'hauptgruppe', 'id' => $hg->id]])
        ->and($chip->default_min)->toBe(1)->and($chip->is_active)->toBeTrue();

    $lw->call('feldSetzen', $chip->id, 'label', 'Suppe/Eintopf')
        ->call('kriteriumUmschalten', $chip->id, 'diaet:vegan')
        ->call('feldSetzen', $chip->id, 'default_max', '3')
        ->call('aktivToggle', $chip->id)
        ->assertSet('fehler', null);
    $chip->refresh();
    expect($chip->label)->toBe('Suppe/Eintopf')
        ->and($chip->kriterien)->toHaveCount(2)
        ->and($chip->default_max)->toBe(3)
        ->and($chip->is_active)->toBeFalse();

    // Ohne Kriterium kein Chip; min > max abgelehnt.
    $lw->set('neu.label', 'Leer')->set('neu.kriterien', [])->call('create')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Kriterium'));
    $lw->call('feldSetzen', $chip->id, 'default_min', '5')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'größer'));
});

it('Katalog: Startsatz nur auf Knopfdruck und nur für ein Team ohne eigene Chips', function () {
    expect(FoodAlchemistSpeiseplanChip::count())->toBe(0);   // kein automatisches Seeden
    Livewire::test(SpeiseplanChips::class)->call('standardAnlegen');
    expect(FoodAlchemistSpeiseplanChip::where('team_id', $this->rootTeam->id)->pluck('label')->all())
        ->toBe(['Vegan', 'Vegetarisch', 'Fleisch', 'Fisch', 'Schwein']);
    expect($this->vsvc->standardChipsAnlegen($this->rootTeam))->toBe(0);
});

it('Katalog: geerbter Chip ist im Kind-Team sichtbar, aber nicht bearbeitbar (D1)', function () {
    $chip = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);
    expect($this->vsvc->katalog($this->childA)->pluck('id')->all())->toBe([$chip->id]);
    expect(fn () => $this->vsvc->chipAendern($this->childA, $chip->id, ['label' => 'X']))
        ->toThrow(RuntimeException::class);
});

// ── Vorgaben je Plan ─────────────────────────────────────────────────────────

it('Vorgaben: speichern, min > max und fremder Chip werden abgelehnt', function () {
    $vegan = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);
    $fremd = $this->vsvc->chipAnlegen($this->childB, ['label' => 'Fremd', 'kriterien' => [['art' => 'diaet', 'key' => 'fisch']]]);

    $plan = $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'mahlzeit' => '', 'min' => '2', 'max' => null]]);
    expect($plan->vorgaben)->toBe([['chip_id' => $vegan->id, 'mahlzeit' => null, 'min' => 2, 'max' => null]]);

    expect(fn () => $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'min' => 3, 'max' => 1]]))
        ->toThrow(RuntimeException::class, 'größer');
    expect(fn () => $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'min' => -1]]))
        ->toThrow(RuntimeException::class, 'negativ');
    expect(fn () => $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $fremd->id, 'min' => 1]]))
        ->toThrow(RuntimeException::class, 'nicht zu diesem Team');
    expect(fn () => $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'mahlzeit' => 'brunch', 'min' => 1]]))
        ->toThrow(RuntimeException::class, 'Mahlzeit');

    // Abgelehntes bleibt ohne Wirkung.
    expect($this->plan->fresh()->vorgaben)->toBe([['chip_id' => $vegan->id, 'mahlzeit' => null, 'min' => 2, 'max' => null]]);
});

it('Vorgaben: Editor fügt aus dem Chip hinzu (Standardwerte), setzt, validiert und entfernt', function () {
    $schwein = ($this->chip)('Schwein', [['art' => 'diaet', 'key' => 'schwein']], ['default_max' => 1]);

    $lw = Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $this->plan->id)
        ->set('neueVorgabeChip', (string) $schwein->id)->call('vorgabeHinzu')
        ->assertSet('vorgabenFehler', null);
    expect($this->plan->fresh()->vorgaben)->toBe([['chip_id' => $schwein->id, 'mahlzeit' => null, 'min' => null, 'max' => 1]]);

    $lw->call('vorgabeSetzen', 0, 'mahlzeit', 'mittag')->call('vorgabeSetzen', 0, 'min', '2')
        ->assertSet('vorgabenFehler', fn ($f) => str_contains((string) $f, 'größer'));
    expect($this->plan->fresh()->vorgaben[0]['mahlzeit'])->toBe('mittag')
        ->and($this->plan->fresh()->vorgaben[0]['min'])->toBeNull();

    $lw->call('vorgabeEntfernen', 0);
    expect($this->plan->fresh()->vorgaben)->toBeNull();
});

it('Vorgaben: Betriebs-Kopie übernimmt die Vorgaben der Vorlage', function () {
    $vegan = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);
    $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'min' => 2]]);
    $kopie = $this->svc->dupliziere($this->rootTeam, $this->plan->id);

    expect($kopie->vorgaben)->toBe($this->plan->fresh()->vorgaben);
});

// ── Auswertung ───────────────────────────────────────────────────────────────

it('Auswertung: Diät je Gericht — unbekannt ist nicht Fleisch; Vorgaben zu_wenig / ok', function () {
    $v1 = ($this->gericht)('v1', ['spec_is_vegan' => true, 'spec_is_vegetarian' => true]);
    $v2 = ($this->gericht)('v2', ['spec_is_vegan' => true, 'spec_is_vegetarian' => true]);
    $sw = ($this->gericht)('sw', ['spec_is_vegan' => false, 'spec_is_vegetarian' => false, 'spec_contains_pork' => true]);
    $leer = ($this->gericht)('leer');   // keine Diät-Pflege
    $e1 = ($this->eintrag)($v1, '2026-07-06');
    $e2 = ($this->eintrag)($v2, '2026-07-07');
    $e3 = ($this->eintrag)($sw, '2026-07-08');
    $e4 = ($this->eintrag)($leer, '2026-07-09');

    $vegan = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);
    $schwein = ($this->chip)('Schwein', [['art' => 'diaet', 'key' => 'schwein']]);
    $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [
        ['chip_id' => $vegan->id, 'min' => 3],
        ['chip_id' => $schwein->id, 'max' => 1],
    ]);

    $ab = $this->svc->wochenAbwechslung($this->plan->fresh(), 'mittag', $this->montag);

    expect($ab['diaet']['vegan'])->toBe(2)
        ->and($ab['diaet']['schwein'])->toBe(1)
        ->and($ab['diaet']['fleisch'])->toBe(1)
        ->and($ab['diaet']['ohne_angabe'])->toBe(1)
        ->and($ab['diaet']['vegetarisch'])->toBe(0)
        ->and($ab['diaet']['omnivor'])->toBe(1);   // vorher 2: das Gericht ohne Angabe zählte mit

    $vv = collect($ab['vorgaben'])->keyBy('chip_id');
    expect($vv[$vegan->id]['ist'])->toBe(2)->and($vv[$vegan->id]['status'])->toBe('zu_wenig')
        ->and($vv[$schwein->id]['ist'])->toBe(1)->and($vv[$schwein->id]['status'])->toBe('ok')
        ->and($vv[$vegan->id]['gericht_ids'])->toBe([$v1->id, $v2->id]);

    // Hervorheben: Eintrag-Ids je Chip
    expect($ab['treffer'][$vegan->id])->toBe([$e1->id, $e2->id])
        ->and($ab['diaet_eintraege']['ohne_angabe'])->toBe([$e4->id])
        ->and($ab['diaet_eintraege']['fleisch'])->toBe([$e3->id]);

    // Höchstgrenze überschritten → zu_viel
    $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'max' => 1]]);
    $ab = $this->svc->wochenAbwechslung($this->plan->fresh(), 'mittag', $this->montag);
    expect($ab['vorgaben'][0]['status'])->toBe('zu_viel');
});

it('Auswertung: Hauptgruppen-Chip mit ODER-Kriterium + Fleisch nur bei belegtem Fleisch', function () {
    $sup = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'SUP', 'label' => 'Suppen']);
    $suppe = ($this->gericht)('s1', ['dish_main_group_id' => $sup->id]);
    $gefluegel = ($this->gericht)('h1', ['spec_is_vegetarian' => false]);   // Tierart nicht gepflegt, aber belegt
    $vegan = ($this->gericht)('v1', ['spec_is_vegan' => true, 'spec_is_vegetarian' => true]);
    foreach ([[$suppe, '2026-07-06'], [$gefluegel, '2026-07-07'], [$vegan, '2026-07-08']] as [$g, $t]) {
        ($this->eintrag)($g, $t);
    }

    $chip = ($this->chip)('Suppe oder Vegan', [['art' => 'hauptgruppe', 'id' => $sup->id], ['art' => 'diaet', 'key' => 'vegan']]);
    $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $chip->id, 'min' => 1, 'max' => 2]]);

    $ab = $this->svc->wochenAbwechslung($this->plan->fresh(), 'mittag', $this->montag);
    expect($ab['vorgaben'][0]['ist'])->toBe(2)->and($ab['vorgaben'][0]['status'])->toBe('ok')
        ->and($ab['diaet']['fleisch'])->toBe(1)
        ->and($ab['diaet']['ohne_angabe'])->toBe(1)
        ->and(collect($ab['warengruppen'])->firstWhere('id', $sup->id)['count'])->toBe(1);
});

it('Auswertung: Mahlzeit-Filter — Vorgabe nur für ihre Mahlzeit, „alle“ gilt je Mahlzeit einzeln', function () {
    $vg = ($this->gericht)('v1', ['spec_is_vegan' => true, 'spec_is_vegetarian' => true]);
    ($this->eintrag)($vg, '2026-07-06', 'mittag');
    ($this->eintrag)($vg, '2026-07-06', 'abend');
    ($this->eintrag)($vg, '2026-07-07', 'abend');
    $chip = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);
    $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [
        ['chip_id' => $chip->id, 'mahlzeit' => 'abend', 'min' => 2],
        ['chip_id' => $chip->id, 'mahlzeit' => null, 'max' => 1],
    ]);
    $plan = $this->plan->fresh();

    $mittag = $this->svc->wochenAbwechslung($plan, 'mittag', $this->montag)['vorgaben'];
    expect($mittag)->toHaveCount(1)->and($mittag[0]['mahlzeit'])->toBeNull()
        ->and($mittag[0]['ist'])->toBe(1)->and($mittag[0]['status'])->toBe('ok');

    $abend = collect($this->svc->wochenAbwechslung($plan, 'abend', $this->montag)['vorgaben'])->keyBy(fn ($v) => $v['mahlzeit'] ?? 'alle');
    expect($abend)->toHaveCount(2)
        ->and($abend['abend']['ist'])->toBe(2)->and($abend['abend']['status'])->toBe('ok')
        ->and($abend['alle']['status'])->toBe('zu_viel');
});

// ── UI ───────────────────────────────────────────────────────────────────────

it('UI: Editor zeigt Vorgaben-Section (Stammdaten) und die Prüfung in der Abwechslungs-Karte', function () {
    $vegan = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);
    $this->vsvc->setzeVorgaben($this->rootTeam, $this->plan->id, [['chip_id' => $vegan->id, 'min' => 2]]);
    ($this->eintrag)(($this->gericht)('leer'), '2026-07-06');

    $html = Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $this->plan->id)->html();

    expect($html)->toContain('data-sp-vorgaben')
        ->toContain('data-sp-vorgabe="0"')
        ->toContain('data-sp-abwechslung-vorgaben')
        ->toContain('data-sp-vorgabe-status="zu_wenig"')
        ->toContain('data-sp-abwechslung-ohne-angabe')
        ->toContain('markiert');
});

it('UI: ohne Katalog Hinweis-Link in die Einstellungen, ohne Vorgaben Link „Vorgaben festlegen“', function () {
    $html = Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $this->plan->id)->html();

    expect($html)->toContain('data-sp-vorgaben-katalog-leer')
        ->toContain('einstellungen/speiseplan-chips')
        ->toContain('data-sp-vorgaben-festlegen');
});

it('UI: Settings-Sektion speiseplan-chips rendert in den Einstellungen', function () {
    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Index::class, ['sektion' => 'speiseplan-chips'])->html();
    expect($html)->toContain('data-settings-link="speiseplan-chips"')->toContain('data-settings-sektion="speiseplan-chips"');

    ($this->chip)('Fisch', [['art' => 'diaet', 'key' => 'fisch']]);
    Livewire::test(SpeiseplanChips::class)->assertOk()->assertSee('Fisch')->assertSeeHtml('data-chip=');
});

// ── MCP ──────────────────────────────────────────────────────────────────────

it('MCP: speiseplan_chips.POST / PUT / GET führen aus', function () {
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $post = $registry->get('foodalchemist.speiseplan_chips.POST')->execute(
        ['label' => 'Fisch', 'kriterien' => [['art' => 'diaet', 'key' => 'fisch']], 'default_min' => 1], $ctx);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $id = $post->data['id'];

    $bad = $registry->get('foodalchemist.speiseplan_chips.POST')->execute(['label' => 'X', 'kriterien' => [['art' => 'diaet', 'key' => 'lamm']]], $ctx);
    expect($bad->success)->toBeFalse();

    $put = $registry->get('foodalchemist.speiseplan_chips.PUT')->execute(['id' => $id, 'felder' => ['default_max' => 2, 'is_active' => false]], $ctx);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));

    $get = $registry->get('foodalchemist.speiseplan_chips.GET')->execute([], $ctx);
    expect($get->success)->toBeTrue()
        ->and($get->data['chips'][0])->toMatchArray(['id' => $id, 'label' => 'Fisch', 'default_min' => 1, 'default_max' => 2, 'is_active' => false, 'eigen' => true]);

    $std = $registry->get('foodalchemist.speiseplan_chips.POST')->execute(['standard' => true], $ctx);
    expect($std->success)->toBeFalse();   // Team hat schon eigene Chips
});

it('MCP: speiseplaene.PUT setzt vorgaben (gleiche Validierung), GET liefert sie mit', function () {
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $vegan = ($this->chip)('Vegan', [['art' => 'diaet', 'key' => 'vegan']]);

    $put = $registry->get('foodalchemist.speiseplaene.PUT')->execute(
        ['id' => $this->plan->id, 'felder' => ['vorgaben' => [['chip_id' => $vegan->id, 'mahlzeit' => 'mittag', 'min' => 2]]]], $ctx);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''))->and($put->data['updated'])->toBe(['vorgaben']);

    $bad = $registry->get('foodalchemist.speiseplaene.PUT')->execute(
        ['id' => $this->plan->id, 'felder' => ['default_pax' => 77, 'vorgaben' => [['chip_id' => $vegan->id, 'min' => 5, 'max' => 1]]]], $ctx);
    expect($bad->success)->toBeFalse()->and($this->plan->fresh()->default_pax)->not->toBe(77);

    $get = $registry->get('foodalchemist.speiseplaene.GET')->execute(['id' => $this->plan->id], $ctx);
    expect($get->data['speiseplan']['vorgaben'])->toBe([['chip_id' => $vegan->id, 'mahlzeit' => 'mittag', 'min' => 2, 'max' => null]]);
});
