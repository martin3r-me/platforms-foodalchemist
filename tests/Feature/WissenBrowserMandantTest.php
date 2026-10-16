<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * io 16.10. (Screenshots Dominique): Kunden-Teams ohne Eltern sahen im Wissens-Browser die 17 Agenten-Anleitungen
 * (art=ablauf) — die Ausnahme vom 13.10. ist für den Agentenpfad ablauf.GET, nicht für die Anzeige. Dazu standen
 * „Bearbeiten"/„Speichern" an globalem Wissen. Hier: Browser ohne Ablauf-Ausnahme, Knöpfe nur mit Schreibrecht,
 * Kategorie-Filter nur mit sichtbaren Dokumenten — ablauf.GET bleibt für alle lesbar (Gegenprobe).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.knowledge_master_regel' => true]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();

    $this->doc = function (?int $teamId, string $slug, string $kategorie, ?string $art = null): int {
        // Die workflow.*-Dossiers liegen per Migration schon in der DB — updateOrInsert auf den Slug.
        DB::table('foodalchemist_knowledge_documents')->updateOrInsert(['slug' => $slug], [
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'title' => 'Titel ' . $slug,
            'category' => $kategorie, 'art' => $art, 'content_md' => "---\n" . ($art === 'ablauf' && ! str_starts_with($slug, 'intern.') ? "gilt_fuer_vorgang: basisrezept_anlegen\n" : '') . "required_tools: [foodalchemist.recipes.POST]\n---\n## Schritt\nText " . $slug,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 40, 'active' => 1, 'deleted_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id');
    };
    // Ablauf-Dossiers des Vorgangs basisrezept_anlegen (global bzw. Master) + ein Regelwerk + ein Fachdossier
    ($this->doc)(null, 'workflow.basisrezept_regeln', 'workflow', 'ablauf');
    ($this->doc)($this->master->id, 'workflow.basisrezept_erzeugen', 'workflow', 'ablauf');
    ($this->doc)(null, 'intern.wissensmodul_schreibrechte', 'workflow', 'ablauf');   // art=ablauf, aber kein Vorgang
    $this->regelId = ($this->doc)(null, 'regelwerk.basisrezepte_p2', 'regelwerk');
    ($this->doc)($this->master->id, 'zutat.fachdossier_p2', 'zutat');

    $this->registry = app(ToolRegistry::class);
    $zaehler = 0;
    $this->als = function (Team $team) use (&$zaehler): ToolContext {
        $user = $this->makeUser($team, 'U ' . $team->id . ' ' . (++$zaehler));
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
});


it('Kunden-Team sieht im Browser keine Ablauf-Anleitungen, ablauf.GET liefert sie weiter; der Master sieht alles', function () {
    $kunde = Team::create(['name' => 'Broich.Catering', 'user_id' => 1, 'personal_team' => false]);   // ohne Eltern, nicht Master
    ($this->doc)($kunde->id, 'zutat.eigenes_dossier', 'zutat');

    $k = ($this->als)($kunde);
    $lw = Livewire::test(Browser::class);
    $slugs = collect($lw->viewData('docs')->items())->pluck('slug')->all();
    expect($slugs)->toBe(['zutat.eigenes_dossier'])
        ->and($lw->html())->toContain('1 Dokument');
    $lw->call('select', (int) DB::table('foodalchemist_knowledge_documents')->where('slug', 'workflow.basisrezept_regeln')->value('id'));
    expect($lw->get('selectedId'))->toBeNull();                                    // Detail per Deep-Link ebenfalls zu

    $res = $this->registry->get('foodalchemist.ablauf.GET')->execute(['vorgang' => 'basisrezept_anlegen'], $k);
    expect($res->success)->toBeTrue()
        ->and(json_encode($res->data, JSON_UNESCAPED_UNICODE))->toContain('basisrezept');

    ($this->als)($this->master);
    $master = collect(Livewire::test(Browser::class)->viewData('docs')->items())->pluck('slug')->all();
    expect($master)->toContain('workflow.basisrezept_regeln', 'regelwerk.basisrezepte_p2', 'zutat.fachdossier_p2');
});

it('Kunden-Team: an globalem Wissen weder Bearbeiten noch Speichern; save/toggleActive/delete schreiben nichts', function () {
    $kunde = Team::create(['name' => 'Sandbox', 'user_id' => 1, 'personal_team' => false]);
    $eigen = ($this->doc)($kunde->id, 'zutat.eigen_schreiben', 'zutat');
    $global = ($this->doc)(null, 'zutat.global_fremd', 'zutat');
    // Kunde darf globales Wissen hier nicht einmal lesen — Schreibversuch direkt auf die ID
    ($this->als)($kunde);
    $lw = Livewire::test(Browser::class)->call('select', $eigen);
    expect($lw->html())->toContain('data-wissen-save')->and($lw->html())->toContain('data-wissen-modus="bearbeiten"');

    $vorher = DB::table('foodalchemist_knowledge_documents')->where('id', $global)->first(['title', 'active', 'deleted_at']);
    $lw->set('selectedId', $global)->set('form.title', 'Gekapert')->call('save')
        ->call('toggleActive', $global)->call('delete', $global);
    $nachher = DB::table('foodalchemist_knowledge_documents')->where('id', $global)->first(['title', 'active', 'deleted_at']);
    expect((array) $nachher)->toBe((array) $vorher);

    // Master-Team liest global, darf als Nicht-Besitzer eines fremden Team-Dokuments aber auch keine Knöpfe sehen
    ($this->als)($this->master);
    $lwm = Livewire::test(Browser::class)->call('select', $global);
    expect($lwm->html())->toContain('data-wissen-save');                         // global = Master pflegt
    $fremd = ($this->doc)($this->childA->id, 'zutat.fremd_kind', 'zutat');
    ($this->als)($kunde);
    expect(Livewire::test(Browser::class)->call('select', $fremd)->get('selectedId'))->toBeNull();
});

it('Kategorie-Filter: Kunden-Team nur Kategorien mit sichtbaren Dokumenten, der Master alle; Anlegen bietet den ganzen Katalog', function () {
    foreach (['zutat' => 'Zutat', 'regelwerk' => 'Regelwerk', 'workflow' => 'Ablauf'] as $slug => $label) {
        DB::table('foodalchemist_knowledge_categories')->updateOrInsert(['slug' => $slug], ['uuid' => (string) UuidV7::generate(), 'label' => $label, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    $kunde = Team::create(['name' => 'Sandbox', 'user_id' => 1, 'personal_team' => false]);
    ($this->doc)($kunde->id, 'zutat.eigen_kat', 'zutat');

    ($this->als)($kunde);
    $lw = Livewire::test(Browser::class);
    expect(collect($lw->viewData('filterKategorien'))->pluck('slug')->all())->toBe(['zutat'])
        ->and(collect($lw->viewData('kategorien'))->pluck('slug')->all())->toContain('zutat', 'regelwerk', 'workflow');

    ($this->als)($this->master);
    expect(collect(Livewire::test(Browser::class)->viewData('filterKategorien'))->pluck('slug')->all())->toContain('zutat', 'regelwerk', 'workflow');
});
