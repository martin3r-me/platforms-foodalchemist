<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-036/037 (Audit 23, P0): Der Wissens-Browser las Dokumente über rohe `DB::table`-Queries
 * OHNE Team-Scope (Liste, Auswahl, `select()`), obwohl `save()`/`toggleActive()` `TeamScope`
 * korrekt nutzen — ein Team sah damit fremde Dokumente. Und `addAlias/removeAlias/addBinding/
 * removeBinding` schrieben ohne jede Eigentumsprüfung; `removeAlias/removeBinding` löschten sogar
 * eine beliebige Kind-ID quer über alle Teams.
 *
 * Regel: Lesen und Schreiben am Wissens-Dokument nur im eigenen Sichtbarkeits-/Eigentumsraum.
 * Globaler Seed (team_id NULL) bleibt für alle LESBAR (geteiltes Vault-Wissen), aber niemand
 * mutiert ihn außer per Import.
 */
function mkDoc(?int $teamId, string $slug, string $title): int
{
    return DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
        'title' => $title, 'category' => 'domain', 'content_md' => '# ' . $title,
        'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 10,
        'active' => 1, 'source_path' => null, 'created_via' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->eigenId = mkDoc($this->childA->id, 'kind-a', 'Kind-A-Wissen');
    $this->fremdId = mkDoc($this->childB->id, 'kind-b', 'Kind-B-Geheim');
    $this->globalId = mkDoc(null, 'global-vault', 'Vault-Regelwerk');

    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));
});

it('zeigt in der Liste keine Dokumente fremder Teams (MVP-036)', function () {
    Livewire::test(Browser::class)
        ->assertViewHas('docs', function ($docs) {
            $slugs = collect($docs)->pluck('slug')->all();
            return in_array('kind-a', $slugs, true)          // eigen sichtbar
                && in_array('global-vault', $slugs, true)    // global sichtbar
                && ! in_array('kind-b', $slugs, true);       // fremd NICHT sichtbar
        });
});

it('lädt kein fremdes Dokument über select() (MVP-036)', function () {
    Livewire::test(Browser::class)
        ->call('select', $this->fremdId)
        // Fremd-Doc wird nicht in den Editor geladen.
        ->assertSet('selectedId', fn ($id) => $id !== $this->fremdId);
});

it('setzt keinen Alias an einem fremden Dokument (MVP-037)', function () {
    Livewire::test(Browser::class)
        ->set('selectedId', $this->fremdId)      // manipuliert
        ->set('newAlias', 'schmuggel')
        ->call('addAlias');

    expect(DB::table('foodalchemist_knowledge_aliases')
        ->where('knowledge_document_id', $this->fremdId)->where('alias_slug', 'schmuggel')->exists())->toBeFalse();
});

it('löscht keinen Alias eines fremden Dokuments (MVP-037)', function () {
    // Alias am Fremd-Doc direkt anlegen …
    $aliasId = DB::table('foodalchemist_knowledge_aliases')->insertGetId([
        'alias_slug' => 'fremd_alias', 'knowledge_document_id' => $this->fremdId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Browser::class)->call('removeAlias', $aliasId);

    expect(DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->exists())->toBeTrue();
});

it('löscht keine Bindung eines fremden Dokuments (MVP-037)', function () {
    $bindingId = DB::table('foodalchemist_knowledge_bindings')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->childB->id,
        'knowledge_document_id' => $this->fremdId, 'binding_type' => 'layer', 'target_key' => 'x',
        'mode' => 'discovery', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Browser::class)->call('removeBinding', $bindingId);

    expect(DB::table('foodalchemist_knowledge_bindings')->where('id', $bindingId)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('setzt und entfernt Alias am EIGENEN Dokument weiterhin', function () {
    $c = Livewire::test(Browser::class)
        ->call('select', $this->eigenId)
        ->set('newAlias', 'mein_alias')
        ->call('addAlias');

    $aliasId = DB::table('foodalchemist_knowledge_aliases')
        ->where('knowledge_document_id', $this->eigenId)->where('alias_slug', 'mein_alias')->value('id');
    expect($aliasId)->not->toBeNull();

    $c->call('removeAlias', $aliasId);
    expect(DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->exists())->toBeFalse();
});
