<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Settings\FoodDna;
use Platform\FoodAlchemist\Livewire\Settings\Index as SettingsIndex;
use Platform\FoodAlchemist\Livewire\Settings\KundeDna;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\CanvasEntryAddTool;
use Platform\FoodAlchemist\Tools\CanvasGetTool;
use Platform\FoodAlchemist\Tools\CanvasPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Bau-Schritt 1: Kaskaden-Fixes (§7).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(CanvasService::class);
});

// ── Schritt 1 · Kaskaden-Fixes ────────────────────────────────────────────────

it('Food-DNA wird vererbt: ein Unter-Team ohne eigene DNA bekommt die des Oberteams', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id), 'leitbild', 'OBERTEAM-LEITBILD-MARKER');

    $kontext = $this->svc->cascadeKontext($this->childA)['marken_kontext'] ?? '';

    expect($kontext)->toContain('OBERTEAM-LEITBILD-MARKER');
});

it('Food-DNA: eine eigene DNA des Unter-Teams gewinnt, die des Oberteams erscheint dann nicht', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id), 'leitbild', 'OBERTEAM-LEITBILD-MARKER');
    $this->svc->setSkalar($this->svc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id), 'leitbild', 'KIND-LEITBILD-MARKER');

    $kontext = $this->svc->cascadeKontext($this->childA)['marken_kontext'] ?? '';

    expect($kontext)->toContain('KIND-LEITBILD-MARKER')->and($kontext)->not->toContain('OBERTEAM-LEITBILD-MARKER');
});

it('Food-DNA: ein Geschwister-Team erbt nichts vom anderen Unter-Team', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id), 'leitbild', 'KIND-A-MARKER');

    expect($this->svc->cascadeKontext($this->childB)['marken_kontext'] ?? '')->not->toContain('KIND-A-MARKER');
});

it('Angebots-Ebene: die Kaskade zieht den Angebots-Canvas, wenn die Angebots-Id mitkommt', function () {
    $angebot = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommerfest', 'status' => 'anfrage', 'personen' => 50]);
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'angebot', 'angebot', $angebot->id), 'rahmen', 'ANGEBOT-RAHMEN-MARKER');

    expect($this->svc->cascadeKontext($this->rootTeam, null, null, (int) $angebot->id)['marken_kontext'] ?? '')->toContain('ANGEBOT-RAHMEN-MARKER')
        ->and($this->svc->cascadeKontext($this->rootTeam)['marken_kontext'] ?? '')->not->toContain('ANGEBOT-RAHMEN-MARKER');
});

it('Angebots-Ebene: der Kundentext-Aufruf übergibt Angebots-Id UND Kunde ans Gateway', function () {
    $angebot = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Sommerfest', 'status' => 'anfrage', 'personen' => 50, 'crm_company_id' => 4711,
    ]);
    $optionen = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$optionen) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext, array $options = []) use (&$optionen) {
            $optionen = $options;

            return new AiProposal(werte: ['text' => 'Text.'], confidence: 0.5);
        });
    });

    app(AngebotService::class)->kiKundentextVorschlag($this->rootTeam, $angebot->id);

    expect($optionen)->not->toBeNull()
        ->and($optionen['food_dna_angebot_id'] ?? null)->toBe((int) $angebot->id)
        ->and($optionen['food_dna_crm_company_id'] ?? null)->toBe(4711);
});

it('Angebots-Kapitel-Kundentext übergibt ebenfalls Angebots-Id und Kunde', function () {
    $angebot = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Sommerfest', 'status' => 'anfrage', 'personen' => 50, 'crm_company_id' => 4711,
    ]);
    $kapitel = app(\Platform\FoodAlchemist\Services\OfferCompositionService::class)->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Menü']);
    $optionen = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$optionen) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext, array $options = []) use (&$optionen) {
            $optionen = $options;

            return new AiProposal(werte: ['text' => 'Text.'], confidence: 0.5);
        });
    });

    app(AngebotService::class)->kiKapitelKundentextVorschlag($this->rootTeam, $kapitel->id);

    expect($optionen['food_dna_angebot_id'] ?? null)->toBe((int) $angebot->id)
        ->and($optionen['food_dna_crm_company_id'] ?? null)->toBe(4711);
});

it('Vererbung endet an der Kundengrenze: ein Mandant unter dem konfigurierten Master erbt dessen DNA nicht', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id), 'leitbild', 'MASTER-LEITBILD-MARKER');
    // Root ist hier das Master-Team, childA ein Mandant, der (fälschlich) unter ihm hängt.
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);

    expect($this->svc->cascadeKontext($this->childA)['marken_kontext'] ?? '')->not->toContain('MASTER-LEITBILD-MARKER');

    // Gegenprobe: ohne konfigurierten Master vererbt dieselbe Kette (Oberteam → Unter-Team)
    config(['foodalchemist.master_team_id' => null]);
    expect($this->svc->cascadeKontext($this->childA)['marken_kontext'] ?? '')->toContain('MASTER-LEITBILD-MARKER');
});

it('Foodbook mit CRM-Firma: das Kapitel-Wording (concept.wording) übergibt die Firma an die Kaskade', function () {
    $stil = FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'kapitel-stil', 'name' => 'Kapitel-Stil',
        'sprach_duktus' => 'Ruhig.', 'is_inactive' => false, 'sort_order' => 0,
    ]);
    $fb = $this->makeFoodbook($this->rootTeam, 'Adler-Gala', ['crm_company_id' => 4711]);
    $kapitel = $this->makeChapter($fb, ['writing_style_id' => $stil->id]);
    $dish = $this->makeRecipe($this->rootTeam, 'Zander auf Linsen');
    $concept = $this->makeConcept($this->rootTeam, 'Menü A');
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id]);
    $this->makeFoodbookBlock($kapitel, ['type' => 'concept_ref', 'concept_id' => $concept->id]);

    $optionen = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$optionen) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext, array $options = []) use (&$optionen) {
            if ($key === 'concept.wording') {
                $optionen = $options;
            }

            return new AiProposal(werte: ['intro' => 'Intro.'], confidence: 0.5);
        });
    });

    app(FoodbookService::class)->kapitelWordingRegenerieren($this->rootTeam, $kapitel->id);

    expect($optionen)->not->toBeNull()
        ->and($optionen['food_dna_foodbook_id'] ?? null)->toBe((int) $fb->id)
        ->and($optionen['food_dna_crm_company_id'] ?? null)->toBe(4711);
});

it('Foodbook mit CRM-Firma: die Kapitel-Ideen (foodbook.kapitel_ideen) übergeben die Firma an die Kaskade', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Adler-Gala', ['crm_company_id' => 4711]);
    $kapitel = $this->makeChapter($fb);
    $optionen = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$optionen) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext, array $options = []) use (&$optionen) {
            if ($key === 'foodbook.kapitel_ideen') {
                $optionen = $options;
            }

            return new AiProposal(werte: ['ideen' => []], confidence: 0.5);
        });
    });

    app(\Platform\FoodAlchemist\Services\IdeenService::class)->kiDivergenz($this->rootTeam, $kapitel->id, 3);

    expect($optionen)->not->toBeNull()
        ->and($optionen['food_dna_crm_company_id'] ?? null)->toBe(4711);
});

it('Foodbook ohne CRM-Firma: keine Kunden-Ebene (Option bleibt leer)', function () {
    $stil = FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'kapitel-stil', 'name' => 'Kapitel-Stil',
        'sprach_duktus' => 'Ruhig.', 'is_inactive' => false, 'sort_order' => 0,
    ]);
    $fb = $this->makeFoodbook($this->rootTeam, 'Ohne Firma');
    $kapitel = $this->makeChapter($fb, ['writing_style_id' => $stil->id]);
    $concept = $this->makeConcept($this->rootTeam, 'Menü A');
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $this->makeRecipe($this->rootTeam, 'Zander')->id]);
    $this->makeFoodbookBlock($kapitel, ['type' => 'concept_ref', 'concept_id' => $concept->id]);
    $optionen = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$optionen) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext, array $options = []) use (&$optionen) {
            $optionen = $options;

            return new AiProposal(werte: ['intro' => 'Intro.'], confidence: 0.5);
        });
    });

    app(FoodbookService::class)->kapitelWordingRegenerieren($this->rootTeam, $kapitel->id);

    expect($optionen)->not->toBeNull()->and($optionen['food_dna_crm_company_id'] ?? null)->toBeNull();
});

it('Zyklus in der Team-Kette (A → B → A): die DNA-Suche bricht ab, statt zu hängen', function () {
    // zirkuläre parent_team_id direkt in der DB — so etwas darf die Plattform nie in eine Endlosschleife ziehen
    \Illuminate\Support\Facades\DB::table('teams')->where('id', $this->rootTeam->id)->update(['parent_team_id' => $this->childA->id]);
    \Illuminate\Support\Facades\DB::table('teams')->where('id', $this->childA->id)->update(['parent_team_id' => $this->rootTeam->id]);
    $team = \Platform\Core\Models\Team::find($this->childA->id);

    $start = microtime(true);
    $kontext = $this->svc->cascadeKontext($team);

    expect($kontext)->toBe([])
        ->and(microtime(true) - $start)->toBeLessThan(5.0);

    // mit DNA im Team selbst: wird auch im kaputten Zustand gefunden und liefert, ohne zu hängen
    $this->svc->setSkalar($this->svc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id), 'leitbild', 'ZYKLUS-MARKER');
    expect($this->svc->cascadeKontext($team)['marken_kontext'] ?? '')->toContain('ZYKLUS-MARKER');
});

it('MCP: kunde_dna ist lesbar, setzbar und für Listen-Einträge offen, nur für Firmen des Haupt-Teams', function () {
    $firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Hotel Adler', 'is_active' => true]);
    $fremdTeam = \Platform\Core\Models\Team::create(['name' => 'Fremder Kunde', 'user_id' => 1, 'personal_team' => false]);
    $fremd = \Platform\Crm\Models\CrmCompany::create(['team_id' => $fremdTeam->id, 'name' => 'Fremd GmbH', 'is_active' => true]);
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $put = (new CanvasPutTool())->execute(['type' => 'kunde_dna', 'owner_id' => $firma->id, 'felder' => ['kommunikation_ton' => 'Locker, per Du.']], $ctx);
    expect($put->success)->toBeTrue();

    $get = (new CanvasGetTool())->execute(['type' => 'kunde_dna', 'owner_id' => $firma->id], $ctx);
    expect($get->success)->toBeTrue()
        ->and($get->data['existiert'])->toBeTrue()
        ->and($get->data['werte']['kommunikation_ton'])->toBe('Locker, per Du.');

    // landet im selben Canvas wie die Einstellungs-Seite (owner_type=crm_company)
    expect($this->svc->find('kunde_dna', 'crm_company', $firma->id))->not->toBeNull();

    $add = (new CanvasEntryAddTool())->execute(['type' => 'kunde_dna', 'owner_id' => $firma->id, 'key' => 'kommunikation_ton', 'value' => 'x'], $ctx);
    expect($add->success === true || $add->errorCode !== 'NOT_FOUND')->toBeTrue();

    foreach ([CanvasGetTool::class => ['type' => 'kunde_dna', 'owner_id' => $fremd->id],
        CanvasPutTool::class => ['type' => 'kunde_dna', 'owner_id' => $fremd->id, 'felder' => ['kommunikation_ton' => 'x']]] as $tool => $args) {
        $r = (new $tool())->execute($args, $ctx);
        expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('NOT_FOUND');
    }
    expect($this->svc->find('kunde_dna', 'crm_company', $fremd->id))->toBeNull();
});

it('Einstellungen: Food-DNA und Kunden-DNA liegen hinter der Bearbeitungssperre (Spec 65)', function () {
    config(['foodalchemist.bearbeitungssperre' => true]);

    // ohne „Bearbeiten": die Sektion ist lesend, Speichern wird serverseitig abgewiesen
    Livewire::test(FoodDna::class)
        ->assertSet('canvasType', 'food_dna')
        ->set('canvasForm.leitbild', 'OHNE-SPERRE')
        ->call('canvasSpeichern');
    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)?->entries->firstWhere('field_key', 'leitbild')?->value)->toBeNull();

    // mit „Bearbeiten": Speichern greift
    Livewire::test(FoodDna::class)
        ->call('bearbeitenStarten')
        ->set('canvasForm.leitbild', 'MIT-SPERRE')
        ->call('canvasSpeichern');
    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'leitbild')->value)->toBe('MIT-SPERRE');

    // Kunden-DNA: Firma wählen bleibt auch im Lesemodus frei, das Schreiben nicht
    $firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Hotel Adler', 'is_active' => true]);
    Livewire::test(KundeDna::class)
        ->call('firmaWaehlen', (int) $firma->id)
        ->assertSet('companyId', (int) $firma->id)
        ->set('canvasForm.kommunikation_ton', 'OHNE-SPERRE')
        ->call('canvasSpeichern');
    expect($this->svc->find('kunde_dna', 'crm_company', $firma->id)->entries->firstWhere('field_key', 'kommunikation_ton')?->value)->toBeNull();
});
