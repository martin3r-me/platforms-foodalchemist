<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Support\KiUmleitung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Umleitung: ein MCP-Aufruf eines KI-Tools (FaRechteToolHuelle → KiUmleitung) liefert einen KI-Auftrag statt eines
 * Provider-Calls. Je umgeleitetem Tool ein Test; dazu Transaktions-/Rollback-Verhalten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    // externer MCP-Server: der Core-Adapter setzt metadata['team_source'] immer; interne Wege (callWithTools, Core-Chat) bauen den Kontext ohne Metadaten
    $this->kontext = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->internKontext = new ToolContext($this->user, $this->rootTeam);
    $this->vkId = $this->registry->get('foodalchemist.verkaufsrezepte.POST')->execute(['name' => 'HG: Umleitung'], $this->kontext)->data['id'];
    $this->gpId = $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Ei'], $this->kontext)->data['id'];
    $this->basisId = $this->makeRecipe($this->rootTeam, 'Reduktion: Rotwein-Schalotte', ['status' => 'draft', 'description' => 'Sirup.', 'preparation' => '1. Reduzieren.'])->id;
    $this->conceptId = $this->makeConcept($this->rootTeam, 'Sommer-Buffet')->id;
    // Foodbook/Kapitel, Angebot/Kapitel und Speisekarte über die Tools anlegen (die Antwort-Form variiert: id oder {objekt: {id}})
    $idAus = function ($res) {
        expect($res->success)->toBeTrue($res->error ?? '');
        foreach ([$res->data['id'] ?? null, ...array_map(fn ($v) => is_array($v) ? ($v['id'] ?? null) : null, array_values((array) $res->data))] as $id) {
            if (is_int($id)) {
                return $id;
            }
        }
        throw new \RuntimeException('keine id in ' . json_encode($res->data));
    };
    $this->foodbookId = $idAus($this->registry->get('foodalchemist.foodbooks.POST')->execute(['label' => 'Sommerbuch'], $this->kontext));
    $this->kapitelId = $idAus($this->registry->get('foodalchemist.foodbook_kapitel.POST')->execute(['foodbook_id' => $this->foodbookId, 'title' => 'Vorspeisen'], $this->kontext));
    $this->offerId = $idAus($this->registry->get('foodalchemist.angebote.POST')->execute(['name' => 'Sommerfest-Angebot'], $this->kontext));
    $this->offerChapterId = $idAus($this->registry->get('foodalchemist.offer_chapter.POST')->execute(['offer_id' => $this->offerId, 'title' => 'Buffet'], $this->kontext));
    $this->karteId = $idAus($this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $this->kontext));
    // Lieferantenartikel ohne Klassifikation (→ gp.suggest) und ein GP als Kandidat (→ gp.la_abgleich)
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->laId = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Eier Bodenhaltung M 30er',
        'article_number' => 'A1', 'qty' => 1, 'unit_code' => 'kg'])->id;
    $this->ohneProviderCall = fn () => DB::table('foodalchemist_ai_call_log')->whereNull('error')->count() === 0;
});

/** Tool → [Argumente, erwartetes Feature]. Schlüssel müssen exakt Support/KiUmleitung::TOOLS entsprechen. */
dataset('umgeleitete_tools', function () {
    return [
        'gp_forms.ESTIMATE' => ['foodalchemist.gp_forms.ESTIMATE', fn ($t) => ['gp_id' => $t->gpId], 'gp.zaehl_einheiten'],
        'presentation_designs.GENERATE_CSS' => ['foodalchemist.presentation_designs.GENERATE_CSS', fn ($t) => ['brief' => 'Dunkel, edel'], 'praesentation.design_css'],
        'recipe_coherence.POST' => ['foodalchemist.recipe_coherence.POST', fn ($t) => ['recipe_id' => $t->vkId, 'mode' => 'judge'], 'vk.kohaerenz'],
        'recipe_klasse.POST' => ['foodalchemist.recipe_klasse.POST', fn ($t) => ['recipe_id' => $t->vkId], 'vk.speisen_klasse'],
        'recipe_rollen.POST' => ['foodalchemist.recipe_rollen.POST', fn ($t) => ['recipe_id' => $t->vkId], 'vk.rollen'],
        'recipe_sensorik.POST' => ['foodalchemist.recipe_sensorik.POST', fn ($t) => ['recipe_id' => $t->vkId], 'recipe.sensorik'],
        'foodbook.KUNDENTEXT_GENERATE' => ['foodalchemist.foodbook.KUNDENTEXT_GENERATE', fn ($t) => ['foodbook_id' => $t->foodbookId], 'foodbook.kundentext'],
        'foodbook_kapitel.KUNDENTEXT_GENERATE' => ['foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE', fn ($t) => ['kapitel_id' => $t->kapitelId], 'foodbook.kundentext'],
        'angebot.KUNDENTEXT_GENERATE' => ['foodalchemist.angebot.KUNDENTEXT_GENERATE', fn ($t) => ['offer_id' => $t->offerId], 'foodbook.kundentext'],
        'concept_wording.GENERATE' => ['foodalchemist.concept_wording.GENERATE', fn ($t) => ['concept_id' => $t->conceptId], 'concept.wording'],
        'planung_leitplanken.EXTRACT' => ['foodalchemist.planung_leitplanken.EXTRACT', fn ($t) => ['briefing' => 'Sommerfest, vegetarisch, eher frisch und regional'], 'planung.leitplanken'],
        'planung_vorschlag.POST' => ['foodalchemist.planung_vorschlag.POST', fn ($t) => ['scope' => 'rezept', 'brief' => 'Sommerfest, vegetarisch, eher frisch und regional'], 'planung.leitplanken'],
        'recipes.REVIEW' => ['foodalchemist.recipes.REVIEW', fn ($t) => ['recipe_id' => $t->basisId], '*'],
        'recipes.REVISE' => ['foodalchemist.recipes.REVISE', fn ($t) => ['recipe_id' => $t->basisId, 'anweisung' => 'Etwas weniger süß'], 'recipe.ueberarbeiten'],
        'verkaufsrezepte.REVISE' => ['foodalchemist.verkaufsrezepte.REVISE', fn ($t) => ['recipe_id' => $t->vkId, 'anweisung' => 'Etwas leichter'], 'vk.ueberarbeiten'],
        'la.GP_ABGLEICH' => ['foodalchemist.la.GP_ABGLEICH', fn ($t) => ['artikel_id' => $t->laId], 'gp.suggest'],
        'recipes.GENERATE' => ['foodalchemist.recipes.GENERATE', fn ($t) => ['description' => 'Dunkle Rotwein-Schalotten-Reduktion'], '*'],
    ];
});

it('der Test deckt genau die umgeleiteten Tools ab', function () {
    $ds = array_map(fn ($z) => $z[0], (function () {
        return [
            ['foodalchemist.gp_forms.ESTIMATE'], ['foodalchemist.presentation_designs.GENERATE_CSS'], ['foodalchemist.recipe_coherence.POST'],
            ['foodalchemist.recipe_klasse.POST'], ['foodalchemist.recipe_rollen.POST'], ['foodalchemist.recipe_sensorik.POST'],
            ['foodalchemist.recipes.GENERATE'], ['foodalchemist.la.GP_ABGLEICH'], ['foodalchemist.concept_wording.GENERATE'], ['foodalchemist.foodbook.KUNDENTEXT_GENERATE'],
            ['foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE'], ['foodalchemist.angebot.KUNDENTEXT_GENERATE'], ['foodalchemist.planung_leitplanken.EXTRACT'],
            ['foodalchemist.planung_vorschlag.POST'], ['foodalchemist.recipes.REVIEW'], ['foodalchemist.recipes.REVISE'], ['foodalchemist.verkaufsrezepte.REVISE'],
        ];
    })());
    expect($ds)->toEqualCanonicalizing(array_keys(KiUmleitung::TOOLS));
});

it('Umleitung: Tool liefert einen KI-Auftrag statt eines Provider-Calls, kein zweiter bei Wiederholung', function (string $tool, Closure $args, string $feature) {
    $lauf = fn () => $this->registry->get($tool)->execute($args($this), $this->kontext);

    $r = $lauf();
    expect($r->success)->toBeTrue($tool . ': ' . ($r->error ?? ''))
        ->and($r->data['status'])->toBe('ki_auftrag_offen')
        ->and($r->data['auftraege'])->toHaveCount(1)
        ->and($r->data['auftraege'][0]['feature'])->toBeString()->not->toBe('');
    // '*' = der Tool-Lauf hat mehrere KI-Glieder, das erste Feature steht im Vergleichstest (KiExternGenerateTest)
    if ($feature !== '*') {
        expect($r->data['auftraege'][0]['feature'])->toBe($feature);
    }

    $a = DB::table('foodalchemist_ki_auftraege')->get();
    expect($a)->toHaveCount(1)
        ->and($a[0]->status)->toBe('offen')
        ->and($a[0]->uuid)->toBe($r->data['auftraege'][0]['auftrag_id'])
        ->and((int) $a[0]->team_id)->toBe($this->rootTeam->id)
        ->and(($this->ohneProviderCall)())->toBeTrue('Provider darf nicht gerufen werden');

    $r2 = $lauf();
    expect($r2->data['status'])->toBe('ki_auftrag_offen')
        ->and($r2->data['auftraege'][0]['auftrag_id'])->toBe($r->data['auftraege'][0]['auftrag_id'])
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1);
})->with('umgeleitete_tools');

it('Schalter aus: dasselbe Tool läuft über den Plattform-Provider, kein Auftrag', function (string $tool, Closure $args, string $feature) {
    config(['foodalchemist.ai.mcp_extern' => false]);
    try {
        $r = $this->registry->get($tool)->execute($args($this), $this->kontext);
    } catch (\Throwable) {
        $r = null;                                               // der Plattformweg darf mit dem Fake-Provider scheitern (z. B. „strukturell unbrauchbar“) — wichtig ist: kein Auftrag
    }

    expect($r?->data['status'] ?? null)->not->toBe('ki_auftrag_offen')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
})->with('umgeleitete_tools');

it('interner Weg (ToolContext ohne MCP-Herkunft): dasselbe Tool läuft über den Plattform-Provider, kein Auftrag', function (string $tool, Closure $args, string $feature) {
    try {
        $r = $this->registry->get($tool)->execute($args($this), $this->internKontext);
    } catch (\Throwable) {
        $r = null;                                               // wie oben: der Plattformweg darf mit dem Fake-Provider scheitern
    }

    expect($r?->data['status'] ?? null)->not->toBe('ki_auftrag_offen')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
})->with('umgeleitete_tools');

it('Plattform-KI per callWithTools ruft ein umgeleitetes Tool: Provider wird gerufen, Ergebnis statt Auftrag', function () {
    $spy = new class extends FakeAiProvider
    {
        public int $agent = 0;

        public int $inner = 0;

        public array $toolErgebnisse = [];

        public int $gpId = 0;

        public function chat(array $messages, array $options = []): array
        {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, 'Sprachassistent')) {   // der Agent-Loop selbst
                $this->agent++;
                $letzte = (string) end($messages)['content'];
                if (str_contains($letzte, 'Tool-Ergebnis') || $this->agent > 1) {
                    $this->toolErgebnisse[] = $letzte;

                    return ['content' => '{"action":"final","text":"fertig"}', 'model' => 'fake-voice', 'usage' => []];
                }

                return ['content' => json_encode(['action' => 'tool', 'name' => 'foodalchemist.gp_forms.ESTIMATE', 'arguments' => ['gp_id' => $this->gpId]]), 'model' => 'fake-voice', 'usage' => []];
            }
            $this->inner++;                                   // das Tool ruft propose() → Provider

            return parent::chat($messages, $options);
        }
    };
    $spy->gpId = $this->gpId;
    app()->singleton(FakeAiProvider::class, fn () => $spy);

    app(AiGatewayService::class)->callWithTools('Schätze die Formen', ['foodalchemist.gp_forms.ESTIMATE'], 3, []);

    expect($spy->inner)->toBeGreaterThan(0, 'das Tool muss den Plattform-Provider rufen')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)
        ->and(implode(' ', $spy->toolErgebnisse))->not->toContain('ki_auftrag_offen');
});

it('nicht umgeleitete Tools (Klasse c / Queue) bleiben beim Plattform-Provider', function () {
    foreach (array_keys(KiUmleitung::AUSGENOMMEN) as $tool) {
        expect(KiUmleitung::umleiten($tool, $this->kontext))->toBeFalse($tool)
            ->and(KiUmleitung::TOOLS)->not->toHaveKey($tool);
        expect(KiUmleitung::AUSGENOMMEN[$tool])->not->toBe('');
    }
});

it('Wache in der Tool-Hülle: Cache-Schreiben vor dem Rollback wird gemeldet, harmloses Rauschen nicht', function () {
    KiUmleitung::ausfuehren(function () {
        \Illuminate\Support\Facades\Cache::put('zz-hat-wirkung', 1, 60);
        \Illuminate\Support\Facades\Cache::put('fa:worker:heartbeat', 1, 60);

        return ToolResult::success([]);
    }, 'foodalchemist.test.TOOL');

    expect(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::$nebenwirkungen)->toBe(['Cache::put zz-hat-wirkung']);

    KiUmleitung::ausfuehren(fn () => ToolResult::success([]), 'foodalchemist.test.TOOL');
    expect(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::$nebenwirkungen)->toBe([]);
});

it('Rollback: Tool schreibt, fängt die Ausnahme (wie recipe_klasse.POST), Lauf wartet → kein Halbzustand, Auftrag existiert', function () {
    Schema::create('ki_umleitung_probe', function ($t) {
        $t->id();
        $t->string('x')->nullable();
    });
    $gw = app(AiGatewayService::class);

    $r = KiUmleitung::ausfuehren(function () use ($gw) {
        DB::table('ki_umleitung_probe')->insert(['x' => 'a']);
        try {
            $gw->propose('recipe.description', ['description' => 'Klarer Fond.']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PROPOSE_FAILED');   // Tool verschluckt die Ausnahme
        }

        return ToolResult::success(['fertig' => true]);
    });

    expect($r->data['status'])->toBe('ki_auftrag_offen')
        ->and(DB::table('ki_umleitung_probe')->count())->toBe(0, 'Halbzustand')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(1);
});

it('Savepoint: Tool mit eigener Transaktion wird mit zurückgerollt, ohne die äußere zu brechen', function () {
    Schema::create('ki_umleitung_probe', function ($t) {
        $t->id();
        $t->string('x')->nullable();
    });
    $gw = app(AiGatewayService::class);
    $niveau = DB::transactionLevel();

    $r = KiUmleitung::ausfuehren(function () use ($gw) {
        DB::transaction(function () use ($gw) {
            DB::table('ki_umleitung_probe')->insert(['x' => 'a']);
            $gw->propose('recipe.description', ['description' => 'Klarer Fond.']);
        });

        return ToolResult::success([]);
    });

    expect($r->data['status'])->toBe('ki_auftrag_offen')
        ->and(DB::table('ki_umleitung_probe')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe($niveau, 'Transaktionsstand wiederhergestellt')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1);
});

it('ohne Auftrag: Lauf wird committet, auch mit innerer Transaktion', function () {
    Schema::create('ki_umleitung_probe', function ($t) {
        $t->id();
        $t->string('x')->nullable();
    });
    $niveau = DB::transactionLevel();

    $r = KiUmleitung::ausfuehren(function () {
        DB::transaction(fn () => DB::table('ki_umleitung_probe')->insert(['x' => 'a']));

        return ToolResult::success(['ok' => 1]);
    });

    expect($r->data)->toBe(['ok' => 1])
        ->and(DB::table('ki_umleitung_probe')->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe($niveau)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('anderer Fehler im Tool: Rollback, Ausnahme bleibt', function () {
    Schema::create('ki_umleitung_probe', function ($t) {
        $t->id();
        $t->string('x')->nullable();
    });
    $niveau = DB::transactionLevel();

    expect(fn () => KiUmleitung::ausfuehren(function () {
        DB::table('ki_umleitung_probe')->insert(['x' => 'a']);
        throw new \LogicException('kaputt');
    }))->toThrow(\LogicException::class);

    expect(DB::table('ki_umleitung_probe')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe($niveau);
});

it('Antwort holen: nach ANTWORT nimmt der wiederholte Tool-Aufruf die Antwort (Plattform-Prüfung, source=mcp)', function () {
    $lauf = fn () => $this->registry->get('foodalchemist.recipe_klasse.POST')->execute(['recipe_id' => $this->vkId], $this->kontext);
    $r = $lauf();
    $id = $r->data['auftraege'][0]['auftrag_id'];

    $ant = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute([
        'antworten' => [['auftrag_id' => $id, 'werte' => ['werte' => ['klasse_id' => null], 'confidence' => 0.4]]],
        'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5',
    ], $this->kontext);
    expect($ant->data['angenommen'])->toBe(1);

    $r2 = $lauf();
    expect($r2->data['status'] ?? null)->not->toBe('ki_auftrag_offen')
        ->and(DB::table('foodalchemist_ki_auftraege')->value('status'))->toBe('verbraucht');
    $log = DB::table('foodalchemist_ai_call_log')->whereNull('error')->first();
    expect(json_decode($log->prompt_parts, true)['quelle'])->toBe('mcp');
});
