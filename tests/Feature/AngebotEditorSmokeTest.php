<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #380 Composer — Fork-Integrations-Smoke: der geforkte Foodbook-Editor (Angebote\Editor)
 * mountet + rendert ohne Fehler und die Kern-Aktionen (Kapitel/Concept/Kalkulation) laufen.
 * Fängt Naht-Fehler (undefined Property/Methode/render-Key) zwischen den parallel geforkten
 * Bausteinen (Komponente ↔ Blade ↔ Service).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(AngebotService::class);
});

it('Editor mountet + rendert ohne Fehler (leeres Angebot)', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Smoke-Angebot', 'personen' => 20]);
    Livewire::test(Editor::class)->call('oeffnen', $angebot->id)->assertOk();
});

it('Editor: Kapitel anlegen + Concept einbuchen + Kalkulation rendert', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Smoke-Voll', 'personen' => 30]);
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'VorspeiseSmoke', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 9.0, 'ek_per_person_cache' => 3.0,
    ]);

    $t = Livewire::test(Editor::class)->call('oeffnen', $angebot->id)->assertOk();
    $t->call('kapitelNeu')->assertOk();

    // erstes Kapitel wählen + ein Concept als Block einbuchen
    $kapId = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::where('offer_id', $angebot->id)->orderBy('position')->value('id');
    expect($kapId)->not->toBeNull();
    $t->call('kapitelWaehle', $kapId)->assertOk()
        ->call('conceptHinzu', $c->id)->assertOk();

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock::where('chapter_id', $kapId)->where('type', 'concept_ref')->count())->toBeGreaterThan(0);
});

it('Editor: Präsentation veröffentlichen erzeugt einen Kunden-Link', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Smoke-Präsi', 'personen' => 25]);
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'MenüSmoke', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 15.0, 'ek_per_person_cache' => 5.0,
    ]);
    $comp = app(\Platform\FoodAlchemist\Services\OfferCompositionService::class);
    $kap = $comp->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Menü']);
    $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);

    $t = \Livewire\Livewire::test(Editor::class)->call('oeffnen', $angebot->id)
        ->set('presentationGueltigBis', now()->addDays(30)->toDateString())
        ->call('veroeffentlichen')->assertOk();

    expect($t->get('presentationFehler'))->toBeNull();
    $angebot->refresh();
    expect($angebot->presentation_enabled)->toBeTrue()
        ->and($angebot->isPresentationLive())->toBeTrue()
        ->and($angebot->presentationPublicRef())->not->toBeNull();

    // öffentlicher Link rendert (200) + interna-frei.
    $this->get('/p/angebot/' . $angebot->presentationPublicRef())->assertOk()->assertSee('Smoke-Präsi');
});

it('Editor berechnet Komposition und Preiseinheiten beim Öffnen jeweils nur einmal', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Performance', 'personen' => 20]);
    $spy = new class(app(\Platform\FoodAlchemist\Services\ConceptService::class)) extends \Platform\FoodAlchemist\Services\OfferCompositionService
    {
        public int $kompositionen = 0;
        public int $preiseinheiten = 0;

        public function komposition(
            \Platform\Core\Models\Team $team,
            \Platform\FoodAlchemist\Models\FoodAlchemistAngebot $angebot,
            ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null,
            bool $intern = false,
        ): array {
            $this->kompositionen++;

            return parent::komposition($team, $angebot, $outlet, $intern);
        }

        public function preisEinheiten(
            \Platform\Core\Models\Team $team,
            \Platform\FoodAlchemist\Models\FoodAlchemistAngebot $angebot,
            ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null,
        ): array {
            $this->preiseinheiten++;

            return parent::preisEinheiten($team, $angebot, $outlet);
        }
    };
    app()->instance(\Platform\FoodAlchemist\Services\OfferCompositionService::class, $spy);

    Livewire::test(Editor::class)->call('oeffnen', $angebot->id)->assertOk();

    expect($spy->kompositionen)->toBe(1)
        ->and($spy->preiseinheiten)->toBe(1);
});

it('Editor erzeugt Kapitel-Wording als angebots-lokalen Snapshot', function () {
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $concepts = app(\Platform\FoodAlchemist\Services\ConceptService::class);
    $gericht = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'angebot-wording', 'name' => 'Sellerie',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.0,
    ]);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Sommermenü', 'status' => 'active']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeise']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $gericht->id]);
    $stil = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'angebot-stil', 'name' => 'Charmant',
        'sprach_duktus' => 'ANGEBOT-DUKTUS: leicht und charmant.',
    ]);
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Wording-Angebot', 'personen' => 20]);
    $composer = app(\Platform\FoodAlchemist\Services\OfferCompositionService::class);
    $kapitel = $composer->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Menü']);
    $block = $composer->addBlock($this->rootTeam, $kapitel->id, ['type' => 'concept_ref', 'concept_id' => $concept->id]);

    $ai = new class($slot->id) extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public array $messages = [];

        public function __construct(public int $slotId) {}

        public function chat(array $messages, array $options = []): array
        {
            $this->messages = $messages;

            return ['content' => json_encode(['werte' => [
                'intro' => 'Ein charmanter Auftakt.',
                'slots' => [$this->slotId => 'Sellerie mit Sommerlaune'],
            ], 'confidence' => 0.9]), 'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'model' => 'spy', 'tool_calls' => null];
        }
    };
    app()->instance(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, $ai);

    Livewire::test(Editor::class)->call('oeffnen', $angebot->id)
        ->call('kapitelWaehle', $kapitel->id)
        ->set('kapitelForm.writing_style_id', $stil->id)
        ->call('kapitelWordingGenerieren')
        ->assertHasNoErrors('kapitelWording');

    $prompt = collect($ai->messages)->pluck('content')->implode("\n");
    $payload = $block->fresh()->payload_json ?? [];
    expect($prompt)->toContain('ANGEBOT-DUKTUS')
        ->and($payload['wording_overrides'][(string) $slot->id] ?? null)->toBe('Sellerie mit Sommerlaune')
        ->and($block->fresh()->customer_text)->toBe('Ein charmanter Auftakt.')
        ->and($slot->fresh()->wording)->toBeNull();
});
