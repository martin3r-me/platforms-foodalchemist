<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Knowledge\WissensSichtbarkeit;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Agentenpfad (Dominique 13.11.2026): Ein Agent legte für Broich.Catering 9 Gerichte an und kannte die Namenssyntax nicht —
 * das VK-Regelwerk war für Mandanten per MCP unlesbar, POST/PUT nahmen jeden Namen ohne Warnung an.
 *
 * Teil 1: Regelwerke + Abläufe per MCP für JEDES Team lesbar; Oberfläche bleibt bei Spec 82 §13 H; Schreiben bleibt Master;
 *         andere Kategorien bleiben per MCP gesperrt.
 * Teil 2: verkaufsrezepte.POST/PUT und recipes.POST(is_sales_recipe) prüfen den Namen (warnen, speichern trotzdem) und nennen
 *         die nächsten Schritte mit Hauptgruppen, Klassen und Servierformen.
 */
const AGENTENPFAD_VK_SLUG = 'regelwerk.regelwerk_verkaufsgerichte--1-naming-hg-praefix-pipe-skelett-verbindlich-use';

beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.knowledge_master_regel' => true]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kunde = Team::create(['name' => 'Broich.Catering', 'user_id' => 1, 'personal_team' => false]);   // Mandant ohne Eltern

    $this->doc = function (?int $teamId, string $slug, string $kategorie, string $text = 'Text'): int {
        DB::table('foodalchemist_knowledge_documents')->updateOrInsert(['slug' => $slug], [
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'title' => 'Titel ' . $slug, 'category' => $kategorie, 'art' => null,
            'content_md' => "---\nthema: x\n---\n## §1 Naming\n{$text} {$slug}", 'version' => 1, 'content_hash' => hash('sha256', $slug),
            'char_count' => 60, 'active' => 1, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id');
    };
    // Plattform-Regelwerk VK §1 (Master-Team, wie auf io) + globales GP-Regelwerk + Ablauf + Fachdossier (bleibt gesperrt)
    $this->vkId = ($this->doc)($this->master->id, AGENTENPFAD_VK_SLUG, 'regelwerk', 'Name = [HG] Leitkomponente | Baustein | Baustein.');
    ($this->doc)(null, 'regelwerk.regelwerk-gp-6-benennung', 'regelwerk');
    ($this->doc)($this->master->id, 'workflow.gericht_abschluss_test', 'workflow');
    ($this->doc)($this->master->id, 'zutat.fachdossier_agentenpfad', 'zutat');
    ($this->doc)(null, 'domain.global_agentenpfad', 'domain');

    $this->registry = app(ToolRegistry::class);
    $zaehler = 0;
    $this->als = function (Team $team) use (&$zaehler): ToolContext {
        $user = $this->makeUser($team, 'U ' . $team->id . ' ' . (++$zaehler));
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
    $this->run = fn (string $tool, array $args, ToolContext $k) => $this->registry->get($tool)->execute($args, $k);
});

// ── Teil 1: Lesen per MCP ──────────────────────────────────────────────────────────────────────────────────────────────

it('★ Mandant liest Plattform-Regelwerke und -Abläufe per knowledge.GET / LIST / SEARCH', function () {
    $k = ($this->als)($this->kunde);

    foreach ([AGENTENPFAD_VK_SLUG, 'regelwerk.regelwerk-gp-6-benennung', 'workflow.gericht_abschluss_test'] as $slug) {
        $res = ($this->run)('foodalchemist.knowledge.GET', ['slug' => $slug], $k);
        expect($res->success)->toBeTrue($slug)->and($res->data['content_md'])->toContain($slug);
    }
    expect(($this->run)('foodalchemist.knowledge.GET', ['slug' => AGENTENPFAD_VK_SLUG], $k)->data['content_md'])->toContain('[HG] Leitkomponente');

    $liste = json_encode(($this->run)('foodalchemist.knowledge.LIST', ['limit' => 100], $k)->data);
    expect($liste)->toContain(AGENTENPFAD_VK_SLUG)->toContain('workflow.gericht_abschluss_test');

    $such = json_encode(($this->run)('foodalchemist.knowledge.SEARCH', ['q' => 'verkaufsgerichte naming'], $k)->data);
    expect($such)->toContain(AGENTENPFAD_VK_SLUG);
});

it('★ Gegenprobe: andere Kategorien bleiben für den Mandanten per MCP gesperrt', function () {
    $k = ($this->als)($this->kunde);

    expect(($this->run)('foodalchemist.knowledge.GET', ['slug' => 'zutat.fachdossier_agentenpfad'], $k)->errorCode)->toBe('NOT_FOUND')
        ->and(($this->run)('foodalchemist.knowledge.GET', ['slug' => 'domain.global_agentenpfad'], $k)->errorCode)->toBe('NOT_FOUND');
    $liste = json_encode(($this->run)('foodalchemist.knowledge.LIST', ['limit' => 100], $k)->data);
    expect($liste)->not->toContain('zutat.fachdossier_agentenpfad')->not->toContain('domain.global_agentenpfad');
});

it('★ Derselbe Mandant sieht die Regelwerke in der Oberfläche NICHT (Wissens-Browser, UI-Abfrage)', function () {
    ($this->als)($this->kunde);

    $slugs = collect(Livewire::test(Browser::class)->viewData('docs')->items())->pluck('slug')->all();
    expect($slugs)->not->toContain(AGENTENPFAD_VK_SLUG)->not->toContain('workflow.gericht_abschluss_test');

    $ui = WissensSichtbarkeit::lesbarOderAlt(DB::table('foodalchemist_knowledge_documents'), 'team_id', $this->kunde)->pluck('slug')->all();
    expect($ui)->not->toContain(AGENTENPFAD_VK_SLUG)->not->toContain('regelwerk.regelwerk-gp-6-benennung');

    // Gegenprobe: der Master sieht sie im Browser
    ($this->als)($this->master);
    expect(collect(Livewire::test(Browser::class)->viewData('docs')->items())->pluck('slug')->all())->toContain(AGENTENPFAD_VK_SLUG);
});

it('★ Mandant kann Regelwerke nicht schreiben (PUT, SET_ACTIVE, DELETE)', function () {
    $k = ($this->als)($this->kunde);

    $put = ($this->run)('foodalchemist.knowledge.PUT', ['slug' => AGENTENPFAD_VK_SLUG, 'content_md' => 'überschrieben'], $k);
    $aus = ($this->run)('foodalchemist.knowledge.SET_ACTIVE', ['slug' => AGENTENPFAD_VK_SLUG, 'active' => false], $k);
    $weg = ($this->run)('foodalchemist.knowledge.DELETE', ['slug' => AGENTENPFAD_VK_SLUG, 'confirm' => true], $k);

    expect($put->success)->toBeFalse()->and($aus->success)->toBeFalse()->and($weg->success)->toBeFalse();
    $doc = DB::table('foodalchemist_knowledge_documents')->where('id', $this->vkId)->first();
    expect($doc->content_md)->toContain('[HG] Leitkomponente')->and((int) $doc->active)->toBe(1)->and($doc->deleted_at)->toBeNull();
});

it('★ ablauf.GET(gericht_anlegen): Mandant bekommt die Regelwerke mit Auszug und lesen_mit, mit_wissen den Volltext', function () {
    $k = ($this->als)($this->kunde);

    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'gericht_anlegen'], $k);
    expect($res->success)->toBeTrue()
        ->and($res->data['regelwerke']['quelle'])->toBe('nachschlagen');
    $vk = collect($res->data['regelwerke']['dokumente'])->firstWhere('slug', AGENTENPFAD_VK_SLUG);
    expect($vk)->not->toBeNull()
        ->and($vk['auszug'])->toContain('[HG] Leitkomponente')->not->toContain('thema:')
        ->and($vk['lesen_mit'])->toBe(['tool' => 'foodalchemist.knowledge.GET', 'args' => ['slug' => AGENTENPFAD_VK_SLUG]]);

    $voll = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'gericht_anlegen', 'mit_wissen' => true], $k);
    $text = collect($voll->data['regelwerke']['dokumente'])->firstWhere('slug', AGENTENPFAD_VK_SLUG)['text'] ?? null;
    expect($text)->toContain('[HG] Leitkomponente');
});

it('★ ablauf.GET: Mandant liest den Kanon des Masters, gefiltert auf Regelwerk/Ablauf', function () {
    DB::table('foodalchemist_knowledge_documents')->where('id', $this->vkId)->update(['team_id' => null]);
    $fach = (int) DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.fachdossier_agentenpfad')->value('id');
    foreach ([[$this->vkId, 1], [$fach, 2]] as [$docId, $ord]) {
        DB::table('foodalchemist_knowledge_canon')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => null, 'scope' => 'prompt_key', 'scope_key' => 'vk.generator',
            'role' => 'root', 'knowledge_document_id' => $docId, 'mode' => 'pflicht', 'ord' => $ord, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('foodalchemist_knowledge_documents')->where('id', $fach)->update(['team_id' => null]);

    $mandant = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'gericht_anlegen'], ($this->als)($this->kunde))->data['regelwerke'];
    $master = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'gericht_anlegen'], ($this->als)($this->master))->data['regelwerke'];

    expect($mandant['quelle'])->toBe('kanon')
        ->and(array_column($mandant['dokumente'], 'slug'))->toBe([AGENTENPFAD_VK_SLUG])
        ->and($mandant['nicht_lesbar'])->toBe(1)
        ->and(array_column($master['dokumente'], 'slug'))->toBe([AGENTENPFAD_VK_SLUG, 'zutat.fachdossier_agentenpfad']);

    $rg = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'vk.generator'], ($this->als)($this->kunde));
    expect($rg->data['quelle'])->toBe('kanon')->and(array_column($rg->data['dokumente'], 'slug'))->toBe([AGENTENPFAD_VK_SLUG]);
});

it('regelwerk.GET(vorgang): Mandant bekommt dieselben Regelwerks-Slugs wie der Master', function () {
    $mandant = ($this->run)('foodalchemist.regelwerk.GET', ['vorgang' => 'gericht_anlegen'], ($this->als)($this->kunde));
    $master = ($this->run)('foodalchemist.regelwerk.GET', ['vorgang' => 'gericht_anlegen'], ($this->als)($this->master));

    expect($mandant->data['quelle'])->not->toBe('nicht_lesbar')
        ->and(array_column($mandant->data['dokumente'], 'slug'))->toBe(array_column($master->data['dokumente'], 'slug'))
        ->and(array_column($mandant->data['dokumente'], 'slug'))->toContain(AGENTENPFAD_VK_SLUG);
});

// ── Teil 2: Gericht anlegen ────────────────────────────────────────────────────────────────────────────────────────────

function agentenpfadVkRahmen(object $t, Team $team): void
{
    foreach (['vk.1.1.hg', 'vk.1.1.bausteine', 'vk.1.2.diaet', 'vk.1.2.fuellwoerter'] as $k) {
        app(RegelService::class)->setzeAktiv(FoodAlchemistRule::where('schluessel', $k)->firstOrFail()->id, true);
    }
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang', 'team_id' => $team->id]);
    FoodAlchemistDishClass::create(['dish_main_group_id' => null, 'code' => 'FLEISCH', 'label' => 'Fleisch', 'diet_form' => 'omnivor', 'team_id' => $team->id]);
    DB::table('foodalchemist_serving_forms')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $team->id, 'code' => 'weckglas',
        'label' => 'Weckglas', 'created_at' => now(), 'updated_at' => now()]);
}

it('★ verkaufsrezepte.POST mit Verstößen: speichert, warnt je Regel-ID, nennt nächste Schritte', function () {
    $k = ($this->als)($this->rootTeam);
    agentenpfadVkRahmen($this, $this->rootTeam);

    $res = ($this->run)('foodalchemist.verkaufsrezepte.POST', ['name' => 'Rinderfilet mit Portweinjus (vegan)'], $k);

    expect($res->success)->toBeTrue()
        ->and(FoodAlchemistRecipe::find($res->data['id'])->name)->toBe('Rinderfilet mit Portweinjus (vegan)');
    $regeln = array_column($res->data['warnungen'], 'regel');
    expect($regeln)->toContain('vk.1.1.hg', 'vk.1.2.diaet', 'vk.1.2.fuellwoerter')
        ->and(count($regeln))->toBe(count(array_unique($regeln)));   // Präfix aus Regel + Datenqualität nur einmal
    $diaet = collect($res->data['warnungen'])->firstWhere('regel', 'vk.1.2.diaet');
    expect($diaet['vorschlag'])->toBe('Rinderfilet mit Portweinjus')
        ->and(collect($res->data['warnungen'])->firstWhere('regel', 'vk.1.1.hg')['vorschlag'])->toContain('[<Kürzel>]');

    $ns = $res->data['naechste_schritte'];
    expect($ns)->not->toHaveKey('fehler')
        ->and(array_column($ns['hauptgruppen'], 'code'))->toBe(['HG'])
        ->and(array_column($ns['klassen'], 'label'))->toContain('Fleisch')
        ->and(array_column($ns['servierformen'], 'code'))->toContain('weckglas')
        ->and(implode(' ', $ns['reihenfolge']))->toContain('foodalchemist.recipe_darreichung.POST')->toContain('foodalchemist.reife.GET');
});

it('★ verkaufsrezepte.POST mit regelkonformem Namen: keine Warnung', function () {
    $k = ($this->als)($this->rootTeam);
    agentenpfadVkRahmen($this, $this->rootTeam);

    $res = ($this->run)('foodalchemist.verkaufsrezepte.POST', ['name' => '[HG] Rinderfilet | Portwein-Jus | Selleriepüree'], $k);

    expect($res->success)->toBeTrue()->and($res->data['warnungen'])->toBe([]);
});

it('★ leere dish_classes: naechste_schritte meldet den Fehler, statt still weiterzugehen', function () {
    $k = ($this->als)($this->kunde);
    expect(FoodAlchemistDishMainGroup::visibleToTeam($this->kunde)->where('is_inactive', false)->count())->toBe(0);

    $res = ($this->run)('foodalchemist.verkaufsrezepte.POST', ['name' => '[HG] Zander | Beurre Blanc | Spinat'], $k);

    expect($res->success)->toBeTrue()
        ->and($res->data['naechste_schritte']['fehler'])->toHaveCount(2)
        ->and($res->data['naechste_schritte']['fehler'][0])->toContain('KEINE Hauptgruppen')
        ->and($res->data['naechste_schritte']['hauptgruppen'])->toBe([]);
});

it('verkaufsrezepte.PUT: geänderter Name wird geprüft, ohne Namen kein warnungen-Schlüssel', function () {
    $k = ($this->als)($this->rootTeam);
    agentenpfadVkRahmen($this, $this->rootTeam);
    $id = ($this->run)('foodalchemist.verkaufsrezepte.POST', ['name' => '[HG] Rinderfilet | Portwein-Jus | Selleriepüree'], $k)->data['id'];

    $schlecht = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => ['name' => 'Rinderfilet auf Selleriepüree']], $k);
    expect($schlecht->success)->toBeTrue()
        ->and(FoodAlchemistRecipe::find($id)->name)->toBe('Rinderfilet auf Selleriepüree')
        ->and(array_column($schlecht->data['warnungen'], 'regel'))->toContain('vk.1.1.hg', 'vk.1.2.fuellwoerter');

    $gut = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => ['name' => '[HG] Rinderfilet | Portwein-Jus | Selleriepüree']], $k);
    expect($gut->data['warnungen'])->toBe([]);

    $ohne = ($this->run)('foodalchemist.verkaufsrezepte.PUT', ['id' => $id, 'felder' => ['description' => 'Zart.']], $k);
    expect($ohne->success)->toBeTrue()->and($ohne->data)->not->toHaveKey('warnungen');
});

it('recipes.POST mit is_sales_recipe prüft den Namen und nennt Schritte; Basisrezept bleibt ohne diese Schlüssel', function () {
    $k = ($this->als)($this->rootTeam);
    agentenpfadVkRahmen($this, $this->rootTeam);

    $vk = ($this->run)('foodalchemist.recipes.POST', ['name' => 'Lachs mit Dill', 'is_sales_recipe' => true], $k);
    expect($vk->success)->toBeTrue()
        ->and(array_column($vk->data['warnungen'], 'regel'))->toContain('vk.1.1.hg', 'vk.1.2.fuellwoerter')
        ->and($vk->data['naechste_schritte']['hauptgruppen'])->not->toBe([]);

    $basis = ($this->run)('foodalchemist.recipes.POST', ['name' => 'Sauce: Hollandaise'], $k);
    expect($basis->success)->toBeTrue()->and($basis->data)->not->toHaveKeys(['warnungen', 'naechste_schritte']);
});

it('Tool-Beschreibungen nennen die Gericht-Namenssyntax (VK §1)', function () {
    foreach (['foodalchemist.verkaufsrezepte.POST', 'foodalchemist.verkaufsrezepte.PUT', 'foodalchemist.recipes.POST'] as $name) {
        $d = $this->registry->get($name)->getDescription();
        expect($d)->toContain('[HG] Leitkomponente | Baustein')->toContain('Gefäß')->toContain('Diät-Tags');
    }
});
