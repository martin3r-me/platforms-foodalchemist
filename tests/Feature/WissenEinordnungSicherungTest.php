<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Knowledge\EinordnungSicherungService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Die Einordnung ist Kuration — menschliches Urteil, nicht aus dem Inhalt ableitbar. Und sie
 * lebt ausschliesslich in der Datenbank: `art`, `geltung`, `datenwerte` und die Aliase stehen
 * in keinem Export, das Vault-Manifest fuehrt sie nicht.
 *
 * Ohne diese Sicherung waere ein Korpus-Durchgang ueber 1.074 Dossiers bei einem
 * Datenbankverlust ersatzlos weg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->dienst = app(EinordnungSicherungService::class);
    $this->datei = sys_get_temp_dir().'/einordnung-test-'.uniqid().'.json';

    $this->mkDoc = fn (string $slug, ?int $teamId = null) => DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId ?? $this->rootTeam->id, 'slug' => $slug,
        'title' => 'T '.$slug, 'category' => 'regelwerk', 'content_md' => '# x',
        'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 3,
        'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(fn () => @unlink($this->datei));

it('★ sichert nur, was eine Einordnung traegt — eine Datei mit 1.000 Leerzeilen verdeckt die Kuration', function () {
    ($this->mkDoc)('ohne-alles');
    ($this->mkDoc)('mit-art');
    app(KnowledgeService::class)->update($this->rootTeam, 'mit-art', ['art' => 'fachwissen']);

    $slugs = array_column($this->dienst->zeilen($this->rootTeam), 'slug');

    expect($slugs)->toContain('mit-art')->and($slugs)->not->toContain('ohne-alles');
});

it('sichert Aliase mit — sie sind Teil des Urteils, nicht Beiwerk', function () {
    ($this->mkDoc)('mit-alias');
    app(KnowledgeService::class)->addAlias($this->rootTeam, 'mit-alias', 'Zweitname');

    $zeile = collect($this->dienst->zeilen($this->rootTeam))->firstWhere('slug', 'mit-alias');

    expect($zeile['aliase'])->toBe(['zweitname'])->and($zeile['art'])->toBeNull();
});

it('★ sichert auch die Einordnung INAKTIVER Dossiers — Stilllegen ist eine Entscheidung', function () {
    ($this->mkDoc)('stillgelegt');
    app(KnowledgeService::class)->update($this->rootTeam, 'stillgelegt', ['art' => 'referenz']);
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'stillgelegt')->update(['active' => 0]);

    expect(array_column($this->dienst->zeilen($this->rootTeam), 'slug'))->toContain('stillgelegt');
});

it('★ der Rueckweg traegt: Einordnung loeschen, Datei einspielen, Stand ist zurueck', function () {
    // Das ist der Daseinszweck. Ein Export, der sich nicht einspielen laesst, ist kein Backup.
    ($this->mkDoc)('rueckweg');
    app(KnowledgeService::class)->update($this->rootTeam, 'rueckweg', [
        'art' => 'fachwissen', 'geltung' => ['gang' => ['hg']],
    ]);
    app(KnowledgeService::class)->addAlias($this->rootTeam, 'rueckweg', 'Ersatzbegriff');
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));

    // Verlust simulieren: Art, Geltung und Alias weg.
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'rueckweg')
        ->update(['art' => null, 'geltung' => null]);
    DB::table('foodalchemist_knowledge_aliases')->delete();

    $e = $this->dienst->spielEin($this->rootTeam, $this->dienst->lade($this->datei), apply: true);

    expect($e['fehler'])->toBe([]);
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', 'rueckweg')->first();
    expect($doc->art)->toBe('fachwissen')
        ->and(json_decode($doc->geltung, true))->toBe(['gang' => ['hg']])
        ->and(DB::table('foodalchemist_knowledge_aliases')->value('alias_slug'))->toBe('ersatzbegriff');
});

it('★ die Vorschau schreibt nichts', function () {
    ($this->mkDoc)('vorschau');
    app(KnowledgeService::class)->update($this->rootTeam, 'vorschau', ['art' => 'referenz']);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'vorschau')->update(['art' => null]);

    $e = $this->dienst->spielEin($this->rootTeam, $this->dienst->lade($this->datei), apply: false);

    expect($e['gesetzt'])->toBe(1)
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'vorschau')->value('art'))->toBeNull();
});

it('★ meldet Zeilen, deren Dossier es nicht mehr gibt — die liessen sich NICHT einspielen', function () {
    ($this->mkDoc)('verschwindet');
    app(KnowledgeService::class)->update($this->rootTeam, 'verschwindet', ['art' => 'fachwissen']);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'verschwindet')->delete();

    $ab = $this->dienst->abgleich($this->rootTeam, $this->dienst->lade($this->datei));

    expect($ab['ohne_dossier'])->toBe(['verschwindet']);
});

it('trennt „nur live\" von „nur in der Datei\" — das sind zwei verschiedene Handlungen', function () {
    ($this->mkDoc)('in-datei');
    app(KnowledgeService::class)->update($this->rootTeam, 'in-datei', ['art' => 'referenz']);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));

    ($this->mkDoc)('erst-danach');
    app(KnowledgeService::class)->update($this->rootTeam, 'erst-danach', ['art' => 'fachwissen']);

    $ab = $this->dienst->abgleich($this->rootTeam, $this->dienst->lade($this->datei));

    expect($ab['nur_live'])->toBe(['erst-danach'])
        ->and($ab['nur_datei'])->toBe([])
        ->and($ab['deckungsgleich'])->toBe(1);
});

it('weist eine unbekannte Art beim Laden ab, statt sie halb einzuspielen', function () {
    file_put_contents($this->datei, json_encode(['zeilen' => [['slug' => 'x', 'art' => 'quatsch']]]));

    expect(fn () => $this->dienst->lade($this->datei))->toThrow(InvalidArgumentException::class, 'quatsch');
});

it('★ knowledge.GET gibt Aliase MIT id zurueck — sonst ist remove nicht adressierbar', function () {
    ($this->mkDoc)('mit-id');
    app(KnowledgeService::class)->addAlias($this->rootTeam, 'mit-id', 'Suchbegriff');

    $res = app(ToolRegistry::class)->get('foodalchemist.knowledge.GET')
        ->execute(['slug' => 'mit-id'], new ToolContext($this->user, $this->rootTeam));

    expect($res->data['aliases'])->toHaveCount(1)
        ->and($res->data['aliases'][0]['alias'])->toBe('suchbegriff')
        ->and($res->data['aliases'][0]['id'])->toBeInt();
});

it('das Kommando schreibt die Datei und meldet die Zahl', function () {
    ($this->mkDoc)('fuers-kommando');
    app(KnowledgeService::class)->update($this->rootTeam, 'fuers-kommando', ['art' => 'regel']);

    $this->artisan('foodalchemist:wissen-einordnung-sicherung', [
        'richtung' => 'export', '--team' => $this->rootTeam->id, '--datei' => $this->datei,
    ])->assertSuccessful();

    $inhalt = json_decode((string) file_get_contents($this->datei), true);
    expect(array_column($inhalt['zeilen'], 'slug'))->toContain('fuers-kommando');
});
