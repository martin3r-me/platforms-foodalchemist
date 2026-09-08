<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · F7 — der Kanon AM DOSSIER, die Gegenrichtung zur Wissens-Steuerung.
 *
 * Die Steuerungs-Seite geht vom PROMPT-KEY aus: „was gehört in `recipe.generator`?" Das ist
 * die richtige Hauptsicht, der Kanon IST eine Packliste je Prompt. Ein Kurator steht aber
 * genauso oft vor einem Dossier und fragt die Gegenfrage: „in welchen Prompts ist DAS hier
 * verbindlich — und wie bekomme ich es dorthin?"
 *
 * ★ Diese Hälfte fehlte, und zwar durch mich: Spec 52 · F3 hat den wirkungslosen
 * „+ einbinden"-Knopf aus dem Dossier-Panel entfernt (richtig — er bewirkte nichts) und
 * NICHTS an seine Stelle gesetzt. Das Panel sagte danach, wo das Wissen wirkt, und bot keinen
 * Weg, das zu ändern. Aufgefallen ist es, als Dominique davor stand: „wo kann man das denn
 * dem Kanon einstellen?" (2026-09-08) — die H7-Asymmetrie andersherum.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.prompts' => [
        'recipe.generator' => ['tier' => 'A', 'task' => 'x'],
        'vk.generator' => ['tier' => 'A', 'task' => 'x'],
    ]]);

    $this->mkDoc = function (string $slug, ?int $teamId = null, string $inhalt = '# Regel'): int {
        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'team_id' => $teamId, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => $inhalt,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('macht ein Dossier vom Dossier aus verbindlich', function () {
    $id = ($this->mkDoc)('kd-naming', (int) $this->rootTeam->id);

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->set('kanonPromptKey', 'recipe.generator')
        ->set('kanonMode', 'pflicht')
        ->call('kanonAdd')
        ->assertSet('fehler', null);

    $zeilen = app(KnowledgeCanonService::class)->list($this->rootTeam, 'prompt_key', 'recipe.generator');
    expect(collect($zeilen)->pluck('slug')->all())->toBe(['kd-naming'])
        ->and(collect($zeilen)->first()['mode'])->toBe('pflicht');
});

it('zeigt am Dossier ALLE Prompt-Keys, in denen es steckt — auch stillgelegte Zeilen', function () {
    // `include_inactive`: eine stillgelegte Kanon-Zeile ist Kuration, keine gelöschte Zeile.
    // Verschwände sie aus der Anzeige, sähe das Dossier ungebunden aus und jemand legte sie
    // ein zweites Mal an.
    $id = ($this->mkDoc)('kd-mehrfach', (int) $this->rootTeam->id);
    $kanon = app(KnowledgeCanonService::class);
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'kd-mehrfach']);
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'vk.generator', 'slug' => 'kd-mehrfach',
        'mode' => 'wenn_platz', 'active' => false]);

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->assertViewHas('kanonZeilen', fn ($z) => collect($z)->pluck('scope_key')->all() === ['recipe.generator', 'vk.generator']
            && collect($z)->firstWhere('scope_key', 'vk.generator')['active'] === false);
});

it('nimmt ein Dossier wieder aus dem Kanon — soft, also umkehrbar', function () {
    $id = ($this->mkDoc)('kd-raus', (int) $this->rootTeam->id);
    app(KnowledgeCanonService::class)
        ->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'kd-raus']);

    Livewire::test(Browser::class)->call('select', $id)->call('kanonRemove', 'recipe.generator');

    expect(app(KnowledgeCanonService::class)->list($this->rootTeam, 'prompt_key', 'recipe.generator'))->toBe([]);
});

it('geht auch an GEERBTEM Master-Wissen — Kanon ist Kuration, kein Inhalts-Edit', function () {
    // ★ Der Unterschied zu `$editable`: den Inhalt eines globalen Vault-Dossiers darf das
    // Team nicht ändern, seine VERDRAHTUNG schon. Genau diese Trennung machte schon
    // `knowledge.BIND` (jetzt abgeschafft) — sie geht mit dem Kanon nicht verloren.
    $id = ($this->mkDoc)('kd-global', null);

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->set('kanonPromptKey', 'recipe.generator')
        ->call('kanonAdd')
        ->assertSet('fehler', null);

    expect(collect(app(KnowledgeCanonService::class)->list($this->rootTeam, 'prompt_key', 'recipe.generator'))
        ->pluck('slug')->all())->toBe(['kd-global']);
});

it('reicht den Hinweis des Dienstes durch, statt ihn zu schlucken', function () {
    // Ein Dossier über dem Deckel wird ANGENOMMEN (Kuration entscheidet), aber der Dienst
    // sagt dazu etwas. Verschwände der Satz, sähe ein halb wirksamer Eintrag aus wie ein
    // ganzer — dieselbe Klasse wie ein `success` ohne Wirkung.
    $id = ($this->mkDoc)('kd-zu-gross', (int) $this->rootTeam->id, str_repeat('x', 9000));

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->set('kanonPromptKey', 'recipe.generator')
        ->call('kanonAdd')
        ->assertSet('fehler', null)
        ->assertSet('hinweis', fn ($h) => $h !== null && str_contains($h, 'Deckel'));
});

it('meldet den Changelog-Guard als Fehler, statt ihn wegzuschlucken', function () {
    $id = ($this->mkDoc)('kd-changelog', (int) $this->rootTeam->id, "# Regel\n\n## Changelog\n- v1");

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->set('kanonPromptKey', 'recipe.generator')
        ->call('kanonAdd')
        ->assertSet('fehler', fn ($f) => $f !== null && str_contains($f, 'Changelog'));

    expect(app(KnowledgeCanonService::class)->list($this->rootTeam, 'prompt_key', 'recipe.generator'))->toBe([]);
});

it('ohne Prompt-Key passiert nichts — und es steht da, warum', function () {
    $id = ($this->mkDoc)('kd-ohne-key', (int) $this->rootTeam->id);

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->call('kanonAdd')
        ->assertSet('fehler', 'Bitte einen Prompt-Key wählen.');
});
