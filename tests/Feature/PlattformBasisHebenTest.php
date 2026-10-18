<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Settings\Einheiten as EinheitenSettings;
use Platform\FoodAlchemist\Livewire\Settings\Warengruppen as WarengruppenSettings;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\FremdSchreiben;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plattform-Basis (Entscheid Dominique 18.10.): Einheiten, Warengruppen und WG-Sub-Kategorien liegen global, gepflegt vom Master-Team.
 * Wie auf io: das Master-Team ist KEIN Elternteam der Mandanten — vor dem Umzug sieht der Mandant die Master-Zeilen also nicht.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    TeamAncestryRegistry::flushAll();
    $this->mandant = $this->rootTeam;   // eigene Kette rootTeam → childA/childB, ohne Master darüber

    $this->kg = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'kg', 'display_de' => 'kg', 'dimension' => 'mass', 'default_in_g' => 1000]);
    $this->altEinheit = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'alt', 'display_de' => 'alt']);
    $this->altEinheit->delete();   // soft-gelöscht — geht mit (Entscheid Kurator F3)
    $this->wg = FoodAlchemistLookupWarengruppe::create(['team_id' => $this->master->id, 'code' => '01', 'name' => 'Gemüse', 'sort_order' => 1]);
    $this->sub = FoodAlchemistWarengruppeSubkategorie::create(['team_id' => $this->master->id, 'commodity_group_code' => '01', 'name' => 'Wurzelgemüse', 'position' => 1]);

    $this->heben = fn (array $opt = []) => $this->artisan('foodalchemist:plattform-basis-heben', $opt);
});

afterEach(function () {
    foreach (glob(storage_path('logs/plattform-basis-heben-*.json')) ?: [] as $f) {
        @unlink($f);
    }
});

it('Trockenlauf schreibt nichts, --apply hebt inkl. gelöschter Zeilen, zweiter Lauf hat nichts zu tun, --verify ist grün', function () {
    ($this->heben)()->assertSuccessful()->expectsOutputToContain('Trockenlauf');
    expect(FoodAlchemistVocabEinheit::withTrashed()->where('team_id', $this->master->id)->count())->toBe(2);

    ($this->heben)(['--apply' => true])->assertSuccessful();
    expect(DB::table('foodalchemist_vocab_units')->whereIn('id', [$this->kg->id, $this->altEinheit->id])->whereNull('team_id')->count())->toBe(2)
        ->and(DB::table('foodalchemist_lookup_commodity_groups')->where('id', $this->wg->id)->value('team_id'))->toBeNull()
        ->and(DB::table('foodalchemist_commodity_group_subcategories')->where('id', $this->sub->id)->value('team_id'))->toBeNull()
        ->and($this->kg->fresh()->id)->toBe($this->kg->id);   // IDs bleiben

    ($this->heben)(['--apply' => true])->assertSuccessful()->expectsOutputToContain('nichts zu tun');
    ($this->heben)(['--verify' => true])->assertSuccessful();
});

it('Dubletten: Master-Zeile mit gleichem Schlüssel wie eine globale Zeile → Abbruch mit Liste, nichts geschrieben', function () {
    FoodAlchemistVocabEinheit::create(['team_id' => null, 'slug' => 'KG', 'display_de' => 'Kilo']);   // MySQL-Sortierung: Gross/klein gleich

    ($this->heben)(['--apply' => true])->assertFailed()->expectsOutputToContain('Dubletten gefunden');
    expect($this->kg->fresh()->team_id)->toBe($this->master->id)
        ->and($this->wg->fresh()->team_id)->toBe($this->master->id);   // auch die anderen Tabellen bleiben unberührt
});

it('Mandanten-eigene Zeile mit gleichem Schlüssel ist nur eine Warnung', function () {
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->childA->id, 'code' => '01', 'name' => 'Eigenes Gemüse']);

    ($this->heben)(['--apply' => true])->assertSuccessful()->expectsOutputToContain('sieht sie nach dem Umzug doppelt');
    expect($this->wg->fresh()->team_id)->toBeNull();
});

it('--zurueck stellt genau die gehobenen Zeilen wieder her', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $protokoll = collect(glob(storage_path('logs/plattform-basis-heben-*.json')))->sort()->last();
    $spaeterGlobal = FoodAlchemistVocabEinheit::create(['team_id' => null, 'slug' => 'stk', 'display_de' => 'Stk']);

    ($this->heben)(['--zurueck' => $protokoll])->assertSuccessful();
    expect($this->kg->fresh()->team_id)->toBe($this->master->id)
        ->and(FoodAlchemistVocabEinheit::withTrashed()->find($this->altEinheit->id)->team_id)->toBe($this->master->id)
        ->and($this->sub->fresh()->team_id)->toBe($this->master->id)
        ->and($spaeterGlobal->fresh()->team_id)->toBeNull();   // nicht im Protokoll → bleibt global
});

it('★ nach dem Umzug: Mandant sieht Einheiten/WG/Sub, Master- und Mandanten-Rezept nutzen dieselbe Einheiten-ID', function () {
    $vocab = app(VocabularyService::class);
    expect($vocab->findEinheit($this->childA, 'kg'))->toBeNull();   // vorher: Master ist kein Elternteam

    ($this->heben)(['--apply' => true])->assertSuccessful();
    TeamAncestryRegistry::flushAll();

    expect($vocab->listEinheiten($this->childA)->pluck('slug'))->toContain('kg')
        ->and($vocab->listWarengruppen($this->childA)->pluck('code'))->toContain('01')
        ->and($vocab->listSubCategories($this->childA, '01')->pluck('sub_category'))->toContain('Wurzelgemüse');

    $zutat = function (Team $team) use ($vocab) {
        $rezept = $this->makeRecipe($team, 'Rezept ' . $team->name);
        DB::table('foodalchemist_recipe_ingredients')->insert([
            'uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'recipe_id' => $rezept->id, 'position' => 1, 'raw_text' => '1 kg Möhren',
            'quantity' => 1, 'unit_vocab_id' => $vocab->findEinheit($team, 'kg')->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $rezept->id)->value('unit_vocab_id');
    };
    expect($zutat($this->master))->toBe($zutat($this->childA))->toBe($this->kg->id);
});

it('★ Master pflegt globale Zeilen nach dem Umzug weiter, Mandant nicht; Neuanlage des Masters landet global', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $vocab = app(VocabularyService::class);

    $vocab->updateEinheit($this->master, $this->kg->id, ['display_de' => 'Kilogramm']);
    $vocab->setEinheitInactive($this->master, $this->kg->id, true);
    $vocab->updateWarengruppeName($this->master, $this->wg->id, 'Gemüse & Salat');
    expect($this->kg->fresh()->display_de)->toBe('Kilogramm')->and($this->wg->fresh()->name)->toBe('Gemüse & Salat');

    expect(fn () => $vocab->updateEinheit($this->childA, $this->kg->id, ['display_de' => 'X']))->toThrow(RuntimeException::class);
    expect(fn () => $vocab->deleteWarengruppe($this->childA, $this->wg->id))->toThrow(RuntimeException::class);

    $neu = $vocab->createEinheit($this->master, ['slug' => 'el', 'display_de' => 'EL']);
    expect($neu->team_id)->toBeNull()   // nie ins Master-Team
        ->and(FoodAlchemistVocabEinheit::where('team_id', $this->master->id)->count())->toBe(0);
});

it('B4: Master-Schreiben auf globale Basis-Zeilen erzeugt keine Zeile im fa-fremdschreiben-Log', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $log = storage_path('logs/fa-fremdschreiben-basis-' . getmypid() . '.log');
    @unlink($log);
    config(['logging.channels.' . FremdSchreiben::KANAL => ['driver' => 'single', 'path' => $log, 'level' => 'debug',
        'formatter' => \Monolog\Formatter\JsonFormatter::class]]);
    Log::forgetChannel(FremdSchreiben::KANAL);
    FremdSchreiben::zuruecksetzen();
    config(['foodalchemist.fremd_schreiben.modus' => 'log']);

    FaTeam::imTeam($this->master, function () {
        $this->kg->fresh()->update(['display_de' => 'Kilogramm']);
        $this->wg->fresh()->update(['name' => 'Gemüse neu']);
        $this->sub->fresh()->update(['position' => 2]);
    });
    FaTeam::imTeam($this->childA, fn () => $this->kg->fresh()->update(['display_de' => 'fremd']));   // Gegenprobe: Mandant schreibt global
    Log::forgetChannel(FremdSchreiben::KANAL);

    $zeilen = is_file($log) ? array_values(array_filter(array_map(fn ($l) => json_decode($l, true)['context'] ?? null, file($log)))) : [];
    @unlink($log);
    expect($zeilen)->toHaveCount(1)
        ->and($zeilen[0])->toMatchArray(['art' => 'fremd', 'modell' => 'FoodAlchemistVocabEinheit', 'kontext_team' => $this->childA->id]);
});

// ── Abnahme Kurator (Dominique: „Global ist das Master-Team. Ich muss sie irgendwo einstellen und pflegen.“) ──

it('★ Livewire Einheiten: Master sieht die globalen Zeilen, legt global an, bearbeitet, deaktiviert, löscht', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $this->actingAs($this->makeUser($this->master, 'Master Pflege'));

    $c = Livewire::test(EinheitenSettings::class)
        ->assertSee('kg')->assertSeeHtml('data-unit-neu')->assertSeeHtml('data-unit-aktionen="edit"')
        ->set('neu.slug', 'el')->set('neu.display_de', 'EL')->call('create')->assertSet('fehler', null);
    $el = FoodAlchemistVocabEinheit::where('slug', 'el')->first();
    expect($el->team_id)->toBeNull();

    $c->call('edit', $this->kg->id)->set('form.display_de', 'Kilogramm')->call('save')->assertSet('fehler', null);
    $c->call('toggleInactive', $this->kg->id, true)->assertSet('fehler', null);
    $c->call('delete', $el->id)->assertSet('fehler', null);
    expect($this->kg->fresh()->display_de)->toBe('Kilogramm')
        ->and($this->kg->fresh()->is_inactive)->toBeTrue()
        ->and(FoodAlchemistVocabEinheit::find($el->id))->toBeNull();
});

it('★ Livewire Einheiten: Mandant sieht die globalen Zeilen nur lesend, direkte Aufrufe werden abgewiesen', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $this->actingAs($this->makeUser($this->childA, 'Mandant Kueche'));

    $c = Livewire::test(EinheitenSettings::class)
        ->assertSee('kg')->assertDontSeeHtml('data-unit-neu')->assertDontSeeHtml('data-unit-aktionen="edit"')
        ->set('neu.slug', 'kiste')->set('neu.display_de', 'Kiste')->call('create');
    expect($c->get('fehler'))->toContain('Plattform-Sprache');
    $c->set('editId', $this->kg->id)->set('form', ['display_de' => 'X'])->call('save');
    $c->call('toggleInactive', $this->kg->id, true);
    $c->call('delete', $this->kg->id);

    expect(FoodAlchemistVocabEinheit::where('slug', 'kiste')->exists())->toBeFalse()
        ->and($this->kg->fresh()->display_de)->toBe('kg')
        ->and($this->kg->fresh()->is_inactive)->toBeFalse()
        ->and(FoodAlchemistVocabEinheit::find($this->kg->id))->not->toBeNull();
});

it('★ Livewire Warengruppen: Master legt WG + Sub global an, benennt um, sortiert, löscht', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $wg2 = FoodAlchemistLookupWarengruppe::create(['team_id' => null, 'code' => '02', 'name' => 'Obst', 'sort_order' => 2]);
    $this->actingAs($this->makeUser($this->master, 'Master Pflege'));

    $c = Livewire::test(WarengruppenSettings::class)
        ->assertSee('Gemüse')->assertSeeHtml('data-wg-neu')->assertSeeHtml('data-sub-neu')
        ->set('neuWg', 'Fermente')->call('wgNeu')->assertSet('fehler', null);
    $ferm = FoodAlchemistLookupWarengruppe::where('name', 'Fermente')->first();
    expect($ferm->team_id)->toBeNull();

    $c->call('waehleWg', '01')->set('neuSub', 'Knollen')->call('addSub')->assertSet('fehler', null);
    expect(FoodAlchemistWarengruppeSubkategorie::where('name', 'Knollen')->value('team_id'))->toBeNull();

    $c->call('startRename', 'Wurzelgemüse')->set('renameNeu', 'Wurzeln')->call('rename')->assertSet('fehler', null);
    expect($this->sub->fresh()->name)->toBe('Wurzeln');

    $c->call('subRunter', 0)->assertSet('fehler', null);
    $c->call('startEditName', $this->wg->id, 'Gemüse')->set('editName', 'Gemüse & Salat')->call('saveName')->assertSet('fehler', null);
    $c->call('wgRunter', $this->wg->id);
    $c->call('deleteWg', $ferm->id)->assertSet('fehler', null);

    expect($this->wg->fresh()->name)->toBe('Gemüse & Salat')
        ->and((int) $this->wg->fresh()->sort_order)->toBeGreaterThan((int) $wg2->fresh()->sort_order)
        ->and(FoodAlchemistLookupWarengruppe::find($ferm->id))->toBeNull()
        ->and(FoodAlchemistWarengruppeSubkategorie::where('team_id', $this->master->id)->count())->toBe(0);
});

it('★ Livewire Warengruppen: Mandant sieht WG + Sub nur lesend, direkte Aufrufe werden abgewiesen', function () {
    ($this->heben)(['--apply' => true])->assertSuccessful();
    $this->actingAs($this->makeUser($this->childA, 'Mandant Kueche'));

    $c = Livewire::test(WarengruppenSettings::class)
        ->assertSee('Gemüse')->assertSee('Wurzelgemüse')
        ->assertDontSeeHtml('data-wg-neu')->assertDontSeeHtml('data-sub-neu')->assertDontSeeHtml('wire:click="startRename(')
        ->assertSeeHtml('data-wg-plattform-hinweis');

    $c->set('neuWg', 'Eigene WG')->call('wgNeu');
    expect($c->get('fehler'))->toContain('Plattform-Sprache');
    $c->call('waehleWg', '01')->set('neuSub', 'Eigene Sub')->call('addSub');
    expect($c->get('fehler'))->toContain('Plattform-Sprache');
    $c->set('renameAlt', 'Wurzelgemüse')->set('renameNeu', 'Gekapert')->call('rename');
    $c->call('subRunter', 0);
    $c->set('editId', $this->wg->id)->set('editName', 'Gekapert')->call('saveName');
    $c->call('deleteWg', $this->wg->id);

    expect(FoodAlchemistLookupWarengruppe::where('name', 'Eigene WG')->exists())->toBeFalse()
        ->and(FoodAlchemistWarengruppeSubkategorie::where('name', 'Eigene Sub')->exists())->toBeFalse()
        ->and($this->sub->fresh()->name)->toBe('Wurzelgemüse')
        ->and($this->wg->fresh()->name)->toBe('Gemüse')
        ->and(FoodAlchemistWarengruppeSubkategorie::where('team_id', $this->childA->id)->count())->toBe(0);   // keine team-lokale Sortier-Kopie
});
