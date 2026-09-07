<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Etappe 8 (E-3/E-4) — das Ablauf-Wissen ausliefern.
 *
 * Kernzusage der Spec §5.1: beide Tools funktionieren, BEVOR der Kanon steht (dann ganze
 * Dossiers), und werden präziser, sobald er steht (dann die kuratierte Auswahl) — bei
 * unverändertem Vertrag. Genau das prüfen die ersten Tests hier, in beiden Zuständen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a = []) => $this->registry->get($n)->execute($a, $this->kontext);

    $this->mkDoc = function (string $slug, string $kategorie, string $inhalt): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel ' . $slug, 'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('ohne Vorgang listet es die Vorgänge — der Einstieg, wenn der Code noch unbekannt ist', function () {
    $res = ($this->run)('foodalchemist.ablauf.GET');

    expect($res->success)->toBeTrue('ablauf: ' . ($res->error ?? ''))
        ->and($res->data['vorgaenge'])->not->toBeEmpty()
        ->and(array_column($res->data['vorgaenge'], 'code'))
        ->toContain('basisrezept_anlegen', 'gericht_anlegen', 'konzept_anlegen', 'foodbook_anlegen');

    foreach ($res->data['vorgaenge'] as $v) {
        expect($v)->toHaveKey('titel')->toHaveKey('einstieg')->toHaveKey('kind');
    }
});

it('ein Vorgang liefert Einstieg, Soll-Aspekte und den Prüf-Weg — auch ohne jedes Dossier', function () {
    // `workflow.gericht_anlegen_mcp` liegt per Migration global in der DB — der Vorgang hat
    // also Prosa. Die Soll-Aspekte kommen davon unabhaengig aus dem Code.
    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'gericht_anlegen']);

    expect($res->success)->toBeTrue('ablauf: ' . ($res->error ?? ''))
        ->and($res->data['artefakt'])->toBe('gericht')
        ->and($res->data['einstieg'])->toBe('foodalchemist.verkaufsrezepte.POST')
        ->and($res->data['pruefen_mit']['tool'])->toBe('foodalchemist.reife.GET')
        ->and($res->data['soll_aspekte'])->not->toBeEmpty();

    $codes = array_column($res->data['soll_aspekte'], 'code');
    expect($codes)->toContain('portion', 'darreichung', 'work_time_min', 'wording');

    // Fuer die Speisekarte gibt es kein Dossier — der Vorgang sagt das, statt Prosa zu erfinden.
    $ohneDossier = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'speisekarte_anlegen']);
    expect(array_column($ohneDossier->data['nicht_verfuegbar'], 'was'))->toContain('ablauf_prosa')
        ->and($ohneDossier->data['soll_aspekte'])->not->toBeEmpty();
});

it('mit Dossier kommen Kette, Trigger-Phrasen und Abschnitte aus dem Frontmatter', function () {
    ($this->mkDoc)('workflow.konzept_anlegen_mcp', 'workflow', <<<'MD'
---
typ: Skill_Workflow
code: fa.konzept_anlegen
required_tools:
  - foodalchemist.concepts.POST
  - foodalchemist.concept_slots.POST
trigger_phrases:
  - Konzept anlegen
  - Menü zusammenstellen
---

# Skill: Konzept anlegen

## Schritt 1 — Rahmen
Text.

## Anti-Patterns
- Slots ohne Wording.
MD);

    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'konzept_anlegen']);

    expect($res->data['kette'])->toBe(['foodalchemist.concepts.POST', 'foodalchemist.concept_slots.POST'])
        ->and($res->data['trigger_phrasen'])->toBe(['Konzept anlegen', 'Menü zusammenstellen'])
        ->and($res->data['dossiers'][0]['slug'])->toBe('workflow.konzept_anlegen_mcp')
        ->and($res->data['dossiers'][0]['abschnitte'])->toContain('Anti-Patterns')
        ->and($res->data['dossiers'][0]['lesen_mit']['tool'])->toBe('foodalchemist.knowledge.GET')
        // Vollständig: kein nicht_verfuegbar-Eintrag mehr für Prosa oder Kette.
        ->and(array_column($res->data['nicht_verfuegbar'] ?? [], 'was'))->not->toContain('ablauf_prosa', 'kette');
});

it('ein Dossier ohne required_tools meldet die fehlende Kette, statt eine zu raten', function () {
    // Genau der Ist-Zustand der sechs alten Dossiers (Stand 2026-07-14): Prosa ja, Kopf-Felder nein.
    ($this->mkDoc)('workflow.speiseplan_erstellen_mcp', 'workflow', "# Skill: Speiseplan\n\n## Schritt 1\nText.");

    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'speiseplan_erstellen']);

    expect($res->data)->not->toHaveKey('kette')
        ->and(array_column($res->data['nicht_verfuegbar'], 'was'))->toContain('kette')
        ->and($res->data['dossiers'][0]['slug'])->toBe('workflow.speiseplan_erstellen_mcp');
});

it('ein Vorgang ohne Reife-Artefakt meldet ehrlich, dass es kein Soll gibt', function () {
    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'preis_margen_monitoring']);

    expect($res->success)->toBeTrue()
        ->and($res->data)->not->toHaveKey('soll_aspekte')
        ->and($res->data)->not->toHaveKey('pruefen_mit')
        ->and(array_column($res->data['nicht_verfuegbar'], 'was'))->toContain('soll_aspekte');
});

it('unbekannter Vorgang nennt die bekannten', function () {
    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'weltherrschaft']);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('basisrezept_anlegen');
});

it('regelwerk.GET fällt ohne Kanon auf das ganze Dossier zurück und sagt das', function () {
    ($this->mkDoc)('regelwerk.regelwerk_basisrezepte', 'regelwerk', str_repeat('Regel Basisrezept. ', 50));

    $res = ($this->run)('foodalchemist.regelwerk.GET', ['vorgang' => 'basisrezept_anlegen']);

    expect($res->success)->toBeTrue('regelwerk: ' . ($res->error ?? ''))
        ->and($res->data['quelle'])->toBe('dossier')
        ->and($res->data['dokumente'])->toHaveCount(1)
        ->and($res->data['dokumente'][0]['slug'])->toBe('regelwerk.regelwerk_basisrezepte')
        ->and($res->data['lesen_mit'])->toBe('foodalchemist.knowledge.GET');
});

it('mit Kanon liefert regelwerk.GET die kuratierte Auswahl — gleicher Vertrag, feinere Auflösung', function () {
    ($this->mkDoc)('regelwerk.regelwerk_basisrezepte', 'regelwerk', str_repeat('Ganzes Dossier. ', 50));
    ($this->mkDoc)('regelwerk.par1_naming', 'regelwerk', str_repeat('Naming. ', 40));
    ($this->mkDoc)('regelwerk.par10_anti', 'regelwerk', str_repeat('Anti-Patterns. ', 40));
    $kanon = app(KnowledgeCanonService::class);
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'regelwerk.par1_naming', 'ord' => 10]);
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'regelwerk.par10_anti', 'ord' => 20, 'mode' => 'wenn_platz']);

    $res = ($this->run)('foodalchemist.regelwerk.GET', ['vorgang' => 'basisrezept_anlegen']);

    expect($res->data['quelle'])->toBe('kanon')
        ->and(array_column($res->data['dokumente'], 'slug'))->toBe(['regelwerk.par1_naming', 'regelwerk.par10_anti'])
        ->and($res->data['dokumente'][0]['mode'])->toBe('pflicht')
        ->and($res->data['dokumente'][1]['mode'])->toBe('wenn_platz');

    // Derselbe Kanon ist auch über den Prompt-Key erreichbar — der zweite Eingang, gleicher Inhalt.
    $perKey = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.generator']);
    expect($perKey->data['quelle'])->toBe('kanon')
        ->and(array_column($perKey->data['dokumente'], 'slug'))->toBe(['regelwerk.par1_naming', 'regelwerk.par10_anti']);

    // Und ablauf.GET zeigt dieselbe Auswahl — eine Kuration, zwei Abnehmer.
    $ablauf = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'basisrezept_anlegen']);
    expect($ablauf->data['regelwerke']['quelle'])->toBe('kanon')
        ->and(array_column($ablauf->data['regelwerke']['dokumente'], 'slug'))->toContain('regelwerk.par1_naming');
});

it('regelwerk.GET weist unbekannte Prompt-Keys und Rollen ab', function () {
    expect(($this->run)('foodalchemist.regelwerk.GET', [])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'gibt.es.nicht'])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.regelwerk.GET', ['vorgang' => 'basisrezept_anlegen', 'role' => 'chef'])->errorCode)->toBe('VALIDATION_ERROR');

    // Spec 52/A4 hat den Vertrag für den Fall „kein Kanon" GEÄNDERT: statt `quelle: 'keine'`
    // mit dem Verweis auf `ablauf.GET` (das denselben leeren Kanon liest — der Agent lief im
    // Kreis) wird jetzt das Routing wirklich aufgelöst. Für `recipe.generator` heisst das
    // `routing` unter dem Alt-Schlüssel `ai_generate_recipe`, weil die Migrationen dort eine
    // Regelwerk-Route seeden. `keine` gibt es nicht mehr — die drei Zustände sind
    // `routing` | `bindung` | `ungesteuert`.
    $ohneKanon = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.generator']);
    expect($ohneKanon->success)->toBeTrue()
        ->and($ohneKanon->data['quelle'])->toBeIn(['routing', 'bindung', 'ungesteuert'])
        ->and($ohneKanon->data['quelle'])->not->toBe('keine')
        ->and($ohneKanon->data['dokumente'])->toBeArray();
});

it('jeder Vorgang ist konsistent verdrahtet: Einstiegs-Tool und Reife-Kind existieren wirklich', function () {
    // Der Wächter gegen die häufigste stille Fehlerquelle: ein Tool-Name im Register, den es
    // nicht (mehr) gibt. Der Agent würde ihm folgen und ins Leere laufen.
    $kinds = \Platform\FoodAlchemist\Services\ReifeService::KINDS;
    $fehlend = [];

    foreach (VorgangsRegisterService::VORGAENGE as $code => $v) {
        if ($this->registry->get($v['einstieg']) === null) {
            $fehlend[] = "$code: einstieg {$v['einstieg']}";
        }
        if ($v['kind'] !== null && ! in_array($v['kind'], $kinds, true)) {
            $fehlend[] = "$code: kind {$v['kind']}";
        }
        foreach ($v['prompt_keys'] as $key) {
            if (! array_key_exists($key, (array) config('foodalchemist.prompts', []))) {
                $fehlend[] = "$code: prompt_key $key";
            }
        }
    }

    expect($fehlend)->toBe([], 'Kaputte Verdrahtung: ' . implode(', ', $fehlend));
});


it('jedes Einstiegs-Tool zeigt auf seinen Ablauf (E-5)', function () {
    // Der Einstieg in das Ablauf-Wissen darf nicht davon abhaengen, dass ein Agent von sich aus
    // nach `ablauf.GET` sucht. Der Zeiger steht dort, wo er tatsaechlich anfaengt: in der
    // Beschreibung des Tools, mit dem der Vorgang beginnt.
    $ohneZeiger = [];

    foreach (VorgangsRegisterService::VORGAENGE as $code => $v) {
        $tool = $this->registry->get($v['einstieg']);
        expect($tool)->not->toBeNull("Einstiegs-Tool {$v['einstieg']} nicht registriert");

        $beschreibung = $tool->getDescription();
        if (! str_contains($beschreibung, 'foodalchemist.ablauf.GET')
            || ! str_contains($beschreibung, $code)) {
            $ohneZeiger[] = "$code ({$v['einstieg']})";
        }
    }

    expect($ohneZeiger)->toBe([], 'Einstiegs-Tools ohne Ablauf-Zeiger: ' . implode(', ', $ohneZeiger));
});


it('ein Vorgang darf aus mehreren Dossiers bestehen — und meldet, wenn eines davon fehlt', function () {
    // „Gericht anlegen" ist EIN Prozess mit zwei Ausführenden (KI über die Leitstelle vs. Agent in
    // ihrer Rolle). Das passt nicht in ein Dossier, weil keines über 4.000 Zeichen gehen darf
    // (Spec 50 Strang III, Ein-Thema-Regel) — also drei: Regeln, Weg A, Weg B.
    ($this->mkDoc)('workflow.verkaufsgericht_anlegen_mcp', 'workflow', <<<'MD'
---
code: fa.gericht_anlegen
required_tools:
  - foodalchemist.recipes.POST
trigger_phrases:
  - Gericht anlegen
---

# Gericht anlegen

## Eiserne Regeln
Text.
MD);
    ($this->mkDoc)('workflow.gericht_weg_a_leitstelle', 'workflow', "# Weg A

## Schritt 1
Text.");

    $res = ($this->run)('foodalchemist.ablauf.GET', ['vorgang' => 'gericht_anlegen']);

    expect($res->data['dossiers'])->toHaveCount(2)
        ->and(array_column($res->data['dossiers'], 'slug'))
        ->toBe(['workflow.verkaufsgericht_anlegen_mcp', 'workflow.gericht_weg_a_leitstelle'])
        // Kopf-Felder kommen aus dem Leit-Dossier, nicht aus allen gemischt.
        ->and($res->data['kette'])->toBe(['foodalchemist.recipes.POST'])
        // Der dritte Teil fehlt — das wird gesagt, nicht verschwiegen.
        ->and(array_column($res->data['nicht_verfuegbar'], 'was'))->toContain('ablauf_prosa_teilweise');

    $warum = collect($res->data['nicht_verfuegbar'])->firstWhere('was', 'ablauf_prosa_teilweise')['warum'];
    expect($warum)->toContain('workflow.gericht_weg_b_eigenregie');
});

it('kein Dossier eines Vorgangs überschreitet den 4.000-Zeichen-Deckel', function () {
    // Der Deckel ist keine Kosmetik: jenseits des Embedding-Fensters ist Inhalt semantisch kaum
    // findbar, und die Wissens-Oberfläche meldet zu grosse Dossiers als Fehler.
    //
    // BEKANNTE ALTLAST — bewusst als Liste statt als aufgeweichte Prüfung: diese Dossiers wurden
    // per Migration als GLOBALES Master-Wissen angelegt und sind darum über MCP weder editier-
    // noch deaktivierbar. Sie brauchen eine Migration (Split in Ein-Thema-Dossiers oder
    // Stilllegung). Bis dahin steht die Schuld hier sichtbar; wird eines von ihnen bereinigt,
    // schlägt der zweite Teil des Tests an und erinnert daran, es hier zu streichen.
    // Seit Migration 2026_09_07_000001 sind beide globalen Alt-Dossiers stillgelegt und durch
    // Ein-Thema-Teile ersetzt — die Liste ist leer und soll es bleiben.
    $altlast = [];

    $deckel = app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->dossierMaxChars();
    $zuGross = [];
    $unnoetigeAusnahme = [];

    foreach (VorgangsRegisterService::VORGAENGE as $code => $v) {
        foreach ($v['doc_slugs'] as $slug) {
            $doc = DB::table('foodalchemist_knowledge_documents')
                ->where('slug', $slug)->where('active', 1)->whereNull('deleted_at')->first(['slug', 'char_count']);
            if ($doc === null) {
                continue;
            }
            $ueber = (int) $doc->char_count > $deckel;
            if ($ueber && ! in_array($slug, $altlast, true)) {
                $zuGross[] = "$code: {$doc->slug} ({$doc->char_count} > $deckel)";
            }
            if (! $ueber && in_array($slug, $altlast, true)) {
                $unnoetigeAusnahme[] = $slug;
            }
        }
    }

    expect($zuGross)->toBe([], 'Neue Dossiers über dem Deckel: ' . implode(', ', $zuGross))
        ->and($unnoetigeAusnahme)->toBe([], 'Aus der Altlast-Liste streichen: ' . implode(', ', $unnoetigeAusnahme));
});
