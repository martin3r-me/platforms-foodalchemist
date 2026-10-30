<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Paket Konzepte (c): concepts.GENERATE, foodbook_kapitel.WORDING_GENERATE und speisekarte_wording.GENERATE laufen per MCP
 * gleichwertig — dieselben Prompts, danach dieselbe DB-Wirkung wie auf dem Plattformweg. Die Antworten kommen aus demselben Fake-Provider,
 * den der Plattformweg ruft (Auftrags-Prompt → Fake → ANTWORT), so ist der Vergleich unabhängig vom Antwort-Inhalt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    // Skript-Provider: beantwortet jede Anfrage nach Kontext-Form — der Plattformweg ruft ihn direkt, der MCP-Weg bekommt dieselben Antworten als Auftrags-Antwort
    $this->stub = new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            $roh = json_encode($messages, JSON_UNESCAPED_UNICODE);
            if (str_contains($roh, 'diaet_vokabular')) {
                $werte = ['name' => 'Sommerfest', 'target_price_pp' => 45, 'slots' => [
                    ['label' => 'Vorspeise', 'slot_type' => 'gang'], ['label' => 'Hauptgang', 'slot_type' => 'gang'], ['label' => 'Dessert', 'slot_type' => 'gang']]];
            } elseif (preg_match_all('/slot_id\W+(\d+)/', $roh, $m) && $m[1] !== []) {
                $werte = ['intro' => 'Ein Menü zum Genießen.', 'slots' => array_fill_keys(array_unique($m[1]), 'Zartes Rinderfilet mit Sommergemüse')];
            } else {
                $werte = ['text' => 'Zartes Rinderfilet mit Sommergemüse', 'intro' => 'Ein Menü zum Genießen.', 'titel' => 'Sommerfest'];
            }

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(\Platform\Core\Contracts\LLMProviderContract::class, $this->stub);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);

    // Schleife des MCP-Clients: Tool rufen → offene Aufträge holen → mit dem Fake-Provider beantworten → wiederholen, bis das Ergebnis da ist
    $this->loese = function (string $tool, array $args, int $maxRunden = 12): array {
        $fake = $this->stub;
        for ($runde = 1; $runde <= $maxRunden; $runde++) {
            $r = $this->registry->get($tool)->execute($args, $this->mcp);
            expect($r->success)->toBeTrue($tool . ': ' . ($r->error ?? ''));
            if (($r->data['status'] ?? null) !== 'ki_auftrag_offen') {
                return [$r, $runde];
            }
            $offen = $this->registry->get('foodalchemist.ki_auftraege.GET')->execute([], $this->mcp)->data['auftraege'];
            expect($offen)->not->toBeEmpty();
            $antworten = [];
            foreach ($offen as $a) {
                $roh = $fake->chat($a['messages'], []);
                $antworten[] = ['auftrag_id' => $a['auftrag_id'], 'werte' => json_decode($roh['content'], true)];
            }
            $ok = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => $antworten], $this->mcp);
            expect($ok->success)->toBeTrue($ok->error ?? '');
        }
        throw new \RuntimeException("{$tool}: nach {$maxRunden} Runden nicht fertig");
    };
    $this->mcpFeatures = fn () => DB::table('foodalchemist_ki_auftraege')->where('status', 'verbraucht')->orderBy('id')->pluck('feature')->all();

    $this->stil = FoodAlchemistWritingStyle::create(['team_id' => $this->rootTeam->id, 'slug' => 'kz-stil', 'name' => 'Verspielt', 'sprach_duktus' => 'locker.']);
    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'kz-g1', 'name' => 'Rinderfilet', 'status' => 'approved', 'is_sales_recipe' => true]);
});

it('Vergleichstest concepts.GENERATE (brief): Plattformweg und MCP-Weg bauen dasselbe Konzept', function () {
    $brief = 'Sommerfest, 60 Gäste, vegetarisch, regional, 45 EUR p. P.';
    $rp = $this->registry->get('foodalchemist.concepts.GENERATE')->execute(['brief' => $brief, 'name' => 'Plattform'], $this->intern);
    expect($rp->success)->toBeTrue($rp->error ?? '')->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
    $form = fn (int $id) => FoodAlchemistConcept::with('slots')->find($id)->slots->map(fn ($s) => [$s->role, $s->sales_recipe_id !== null])->all();
    $callsPlattform = DB::table('foodalchemist_ai_call_log')->whereNull('error')->pluck('feature')->all();

    [$rm, $runden] = ($this->loese)('foodalchemist.concepts.GENERATE', ['brief' => $brief, 'name' => 'MCP']);

    expect($rm->data['concept_id'])->not->toBe($rp->data['concept_id'])
        ->and($rm->data['status'])->toBe($rp->data['status'])->and($rm->data['created_via'])->toBe($rp->data['created_via'])
        ->and($form($rm->data['concept_id']))->toBe($form($rp->data['concept_id']))
        ->and(count($rm->data['protokoll']))->toBe(count($rp->data['protokoll']))
        ->and($runden)->toBeGreaterThan(1);
    // jeder Plattform-KI-Aufruf kam im MCP-Weg als Auftrag an (gleiche Feature-Menge)
    expect(array_count_values(($this->mcpFeatures)()))->toBe(array_count_values($callsPlattform));
});

it('Vergleichstest foodbook_kapitel.WORDING_GENERATE: dieselben Overrides am Block, ein Auftrag je Konzept-Block', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Buch']);
    $concepts = app(ConceptService::class);
    $menue = $concepts->create($this->rootTeam, ['name' => 'Menü', 'status' => 'active']);
    $slot = $concepts->addSlot($this->rootTeam, $menue->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->gericht->id]);
    $bau = function (string $titel) use ($fb, $menue) {
        $this->registry->get('foodalchemist.foodbook_kapitel.POST')->execute(['foodbook_id' => $fb->id, 'title' => $titel], $this->mcp);
        $k = FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->where('title', $titel)->value('id');
        FoodAlchemistFoodbookKapitel::whereKey($k)->update(['writing_style_id' => $this->stil->id]);

        return [$k, app(FoodbookService::class)->addBlock($this->rootTeam, $k, ['type' => 'concept_ref', 'concept_id' => $menue->id])];
    };
    [$kp, $bp] = $bau('Plattform');
    [$km, $bm] = $bau('MCP');

    $rp = $this->registry->get('foodalchemist.foodbook_kapitel.WORDING_GENERATE')->execute(['kapitel_id' => $kp], $this->intern);
    expect($rp->success)->toBeTrue($rp->error ?? '');
    [$rm, $runden] = ($this->loese)('foodalchemist.foodbook_kapitel.WORDING_GENERATE', ['kapitel_id' => $km]);

    $overrides = fn ($b) => array_values((array) ($b->fresh()->payload_json['wording_overrides'] ?? []));
    expect($rm->data)->toBe(array_replace($rp->data, array_intersect_key($rm->data, ['kapitel_id' => 1])))->and($runden)->toBe(2)
        ->and($overrides($bm))->toBe($overrides($bp))->and($overrides($bm))->not->toBeEmpty()
        ->and(($this->mcpFeatures)())->toBe(['concept.wording']);
});

it('Vergleichstest speisekarte_wording.GENERATE: dieselben Positions-Texte, ein Auftrag je Position', function () {
    $karten = app(SpeisekarteService::class);
    $bau = function (string $name) use ($karten) {
        $karte = $karten->create($this->rootTeam, ['name' => $name]);
        $rubrik = $karten->addRubrik($this->rootTeam, $karte->id);
        $p1 = $karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);
        FoodAlchemistSpeisekarte::whereKey($karte->id)->update(['writing_style_id' => $this->stil->id]);

        return [$karte->id, $p1];
    };
    [$kp, $pp] = $bau('Plattform');
    [$km, $pm] = $bau('MCP');

    $rp = $this->registry->get('foodalchemist.speisekarte_wording.GENERATE')->execute(['speisekarte_id' => $kp], $this->intern);
    expect($rp->success)->toBeTrue($rp->error ?? '')->and($rp->data['updated_positions'])->toBe(1);
    [$rm, $runden] = ($this->loese)('foodalchemist.speisekarte_wording.GENERATE', ['speisekarte_id' => $km]);

    expect($rm->data['updated_positions'])->toBe(1)->and($runden)->toBe(2)
        ->and($pm->fresh()->wording)->toBe($pp->fresh()->wording)->and($pm->fresh()->wording)->not->toBeEmpty()
        ->and(($this->mcpFeatures)())->toBe(['foodbook.kundentext']);
});

it('Wording-Tools ohne Schreibstil: kein KI-Aufruf, kein Auftrag (unverändert)', function () {
    $karten = app(SpeisekarteService::class);
    $karte = $karten->create($this->rootTeam, ['name' => 'Ohne Stil']);
    $rubrik = $karten->addRubrik($this->rootTeam, $karte->id);
    $karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $r = $this->registry->get('foodalchemist.speisekarte_wording.GENERATE')->execute(['speisekarte_id' => $karte->id], $this->mcp);
    expect($r->success)->toBeTrue()->and($r->data['updated_positions'])->toBe(0)->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});
