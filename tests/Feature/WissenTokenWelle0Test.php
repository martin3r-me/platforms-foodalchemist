<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
});

/** Hilfsroutine: ein Wissens-Dokument mit steuerbarer Größe. */
function w0Doc(string $slug, string $category, int $chars, string $text = 'Technik'): void
{
    $inhalt = str_repeat($text . ' ', (int) ceil($chars / (mb_strlen($text) + 1)));
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(),
        'slug' => $slug, 'title' => ucfirst(str_replace('-', ' ', $slug)),
        'category' => $category, 'content_md' => mb_substr($inhalt, 0, $chars),
        'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => $chars,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Upsert, nicht Insert: `foodalchemist_knowledge_routings` hat UNIQUE(feature, category)
 * OHNE `mode` — und die Migrationen seeden für mehrere Features schon Zeilen (u. a.
 * `concept.wording/cross_cutting`). Genau deshalb kann ein `insertOrIgnore` in
 * seedRoutings() auch keinen Modus mehr kippen.
 */
function w0Routing(string $feature, string $category, string $mode, ?int $docs = null, ?int $chars = null): void
{
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => $feature, 'category' => $category],
        [
            'mode' => $mode, 'max_docs' => $docs, 'max_chars_per_doc' => $chars,
            'created_at' => now(), 'updated_at' => now(),
        ],
    );
}

/*
 * W0-5 — vor Welle 0 hatte NUR `ai_generate_recipe` ein featureweites Budget. Alle anderen
 * Features summierten ihre Pro-Doc-Deckel unbegrenzt auf (gemessen: recipe.steps 18.121 Tk,
 * foodbook.kapitel_ideen 23.603 Tk ⌀ Input). Dieser Test hält fest, dass ein Feature OHNE
 * Config-Override den Default trifft — sonst wäre der Deckel wieder still weg.
 */
it('deckelt auch Features ohne eigenen Override auf das Default-Wissensbudget', function () {
    foreach (range(1, 8) as $i) {
        w0Doc("rinderfilet-technik-{$i}", "w0cat{$i}", 6000, "Rinderfilet Schmoren {$i}");
        w0Routing('recipe.steps', "w0cat{$i}", 'discovery', 3, 8000);
    }

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'recipe.steps', 'Rinderfilet schmoren', null, [], []
    );

    // recipe.steps hat einen Override — der muss greifen, nicht der Default.
    $override = (int) config('foodalchemist.ai.knowledge_budget')['recipe.steps'];
    expect($override)->toBeGreaterThan(0)
        ->and($override)->not->toBe(KnowledgeContextService::MAX_KNOWLEDGE_CHARS_DEFAULT)
        ->and($ctx['total_chars'])->toBeLessThanOrEqual($override + 40)
        ->and($ctx['built_chars'])->toBeGreaterThan($ctx['total_chars'])
        ->and($ctx['dropped_chars'])->toBeGreaterThan(0);
});

it('nutzt den Default-Deckel für ein Feature ohne Config-Override', function () {
    config()->set('foodalchemist.ai.knowledge_budget', []);        // Overrides bewusst leeren
    foreach (range(1, 8) as $i) {
        w0Doc("lammruecken-technik-{$i}", "w0dcat{$i}", 6000, "Lammruecken Niedertemperatur {$i}");
        w0Routing('foodbook.kapitel_ideen', "w0dcat{$i}", 'discovery', 3, 8000);
    }

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'foodbook.kapitel_ideen', 'Lammruecken Niedertemperatur', null, [], []
    );

    expect($ctx['total_chars'])
        ->toBeLessThanOrEqual(KnowledgeContextService::MAX_KNOWLEDGE_CHARS_DEFAULT + 40);
});

/*
 * W0-6 — die Retrieval-Lexik hatte keine Stoppwortliste, wohl aber einen Substring-Term.
 * Dadurch wurde „der" aus der Beschreibung zu einem Ranking-Token und traf über
 * str_contains beliebige Slugs (real beobachtet: `der` ⊂ `moderne`). Gleichzeitig darf die
 * Mindestlänge NICHT auf 4 steigen — `aal`, `oel`, `jus`, `roh`, `bio` sind Fachvokabular.
 */
it('filtert Funktionswoerter aus der Retrieval-Lexik, behaelt aber kurze Fachtokens', function () {
    $tokens = app(KnowledgeContextService::class)->tokenize('Der Aal mit Oel und die Jus aus dem Sud');

    expect($tokens)->toContain('aal')
        ->and($tokens)->toContain('oel')
        ->and($tokens)->toContain('jus')
        ->and($tokens)->toContain('sud')
        ->and($tokens)->not->toContain('der')
        ->and($tokens)->not->toContain('die')
        ->and($tokens)->not->toContain('und')
        ->and($tokens)->not->toContain('mit');
});

it('vergibt keinen Substring-Bonus fuer kurze Query-Tokens', function () {
    // „moderne" enthält „ode"/„der"-artige Fragmente; ohne Längen-Gate hätte ein
    // 3-Zeichen-Token hier +0,1 kassiert und das Dossier fälschlich hochgezogen.
    w0Doc('moderne-kuechentechnik', 'w0substr', 3000, 'Moderne Technik');
    w0Routing('ai_generate_recipe', 'w0substr', 'discovery', 2, 3000);

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Sud aus Aal', null, [], []
    );

    expect($ctx['files_used'])->not->toContain('moderne-kuechentechnik@v1');
});

/*
 * W0-6 — Herkunfts-Messung. Ohne `via`/`score` je Treffer ist ein Score-Gate behauptet,
 * nicht kalibriert; und es ist nicht feststellbar, ob Lexik oder semantischer Recall
 * die Auswahl trägt.
 */
it('weist je gewaehltem Dossier Herkunft und Groesse aus', function () {
    w0Doc('rinderfilet-kerntemperatur', 'w0herk', 5000, 'Rinderfilet Kerntemperatur');
    w0Routing('ai_generate_recipe', 'w0herk', 'discovery', 2, 2000);

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Rinderfilet Kerntemperatur', null, [], []
    );

    expect($ctx)->toHaveKey('herkunft')
        ->and($ctx['herkunft'])->toHaveKey('rinderfilet-kerntemperatur');

    $h = $ctx['herkunft']['rinderfilet-kerntemperatur'];
    expect($h['via'])->toBeIn(['lexical', 'alias', 'semantic'])
        ->and($h['chars'])->toBe(5000)
        // `sent` ist das, was nach dem Pro-Doc-Deckel wirklich rausging — der Unterschied
        // zu `chars` war bis Welle 0 nirgends sichtbar (`files_used` listete das Dossier
        // als „verwendet", auch wenn der Deckel 60 % davon abgeschnitten hatte).
        ->and($h['sent'])->toBeLessThan($h['chars']);
});

/*
 * W0-4 — `cross_cutting:always` und `domain:discovery` lesen ihre Routing-Zeile NICHT
 * (sie ist dort nur ein Boolean-Gate). Wer den Deckel dieser beiden Kanäle über SQL
 * ändern will, ändert nichts. Dieser Test hält fest, dass die Konstante regiert.
 */
it('deckelt cross_cutting ueber die Konstante, nicht ueber die Routing-Zeile', function () {
    w0Doc('substitutionen', 'cross_cutting', 9000, 'Substitution Regel');
    // Routing gibt absichtlich einen viel höheren Deckel vor — er muss ins Leere laufen.
    // Synthetisches Feature OHNE B3-Slug-Überschreibung, damit hier wirklich nur der
    // Konstanten-Vorrang geprüft wird und nicht die feature-genaue Slug-Auswahl.
    w0Routing('w0cc.konstante', 'cross_cutting', 'always', 9, 9000);

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'w0cc.konstante', 'Substitution', null, [], []
    );

    // Kern der Aussage: der Routing-Wert (9.000) läuft ins Leere, die Konstante (1.800)
    // regiert. Toleranz deckt Block-Header („# VAULT-WISSEN …", „## CROSS_CUTTING: …")
    // plus Kürzungs-Marker ab — zusammen ~310 Zeichen.
    expect($ctx['built_chars'])->toBeLessThan(9000)
        ->and($ctx['built_chars'])->toBeLessThanOrEqual(KnowledgeContextService::CROSS_CUTTING_TRUNCATE_CHARS + 400)
        ->and($ctx['built_chars'])->toBeGreaterThan(KnowledgeContextService::CROSS_CUTTING_TRUNCATE_CHARS)
        ->and($ctx['block'])->toContain('[…gekürzt für KI-Kontext…]');
});

/** Layer-Binding auf einen Prompt-Key/Bereich legen. */
function w0Bind(string $slug, string $targetKey, string $mode = 'always', int $weight = 0): void
{
    $docId = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id');
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(),
        'knowledge_document_id' => $docId, 'binding_type' => 'layer',
        'target_key' => $targetKey, 'mode' => $mode, 'weight' => $weight,
        'active' => 1, 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/*
 * W0-3 — der Kern des Bugs: an `recipe.generator` hängen 9 bewusst gebundene Dossiers,
 * von denen vor Welle 0 nur 3 den Prompt erreichten (jedes auf 1.400 Zeichen gekappt,
 * §5 Default-GPs also als 29-%-Fragment). Dieser Test hält fest, dass jetzt ALLE
 * Pflicht-Dossiers ganz ankommen.
 */
it('liefert alle gebundenen Bau-Dossiers vollstaendig an den Rezeptgenerator', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    $slugs = [];
    foreach ([1968, 2459, 2630, 4796, 2609, 1599, 1409] as $i => $chars) {
        $slug = "regelwerk-basisrezepte-w0-{$i}";
        w0Doc($slug, 'w0bindcat', $chars, 'Bau Regel');
        w0Bind($slug, 'recipe.generator', 'always', 100 - $i);
        $slugs[] = $slug;
    }

    app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose('recipe.generator', ['description' => 'Rinderfilet'], []);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.generator')->first();
    $verwendet = json_decode((string) $log->knowledge_used, true) ?: [];

    // Alle 7 Pflicht-Dossiers sind im Audit — vorher waren es 3.
    foreach ($slugs as $slug) {
        expect(implode(' ', $verwendet))->toContain($slug);
    }

    $parts = json_decode((string) $log->prompt_parts, true);
    expect($parts['bound'])->toBeGreaterThan(17000)         // Summe 17.470 kommt ganz an
        ->and($parts['bound'])->toBeLessThanOrEqual(20000 + 400)
        ->and($parts['dropped'])->toBe(0);                  // nichts gekappt
});

/*
 * Die Gegenprobe, und der Grund für die prompt-key-scoped Budgets: Bindings matchen auch
 * auf das BEREICHS-Präfix. An `target_key='recipe'` hängen live 24.520 Zeichen. Würde der
 * große Deckel global gelten, verteuerte W0-3 jeden `recipe.*`-Prompt statt nur die
 * Generatoren — aus einer Blutstillung würde eine Kostenausweitung.
 */
it('laesst Prompts ohne eigenes Bound-Budget beim konservativen Default', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    foreach ([10670, 7089, 6761] as $i => $chars) {
        $slug = "bereich-recipe-dossier-{$i}";
        w0Doc($slug, 'cross_cutting', $chars, 'Bereichs Wissen');
        w0Bind($slug, 'recipe', 'always', 50 - $i);
    }

    // recipe.description erbt die Bindings über das Präfix `recipe`.
    app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose('recipe.description', ['description' => 'Klarer Fond.'], []);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->first();
    $parts = json_decode((string) $log->prompt_parts, true);

    // Default-Deckel 4.200 (+ Block-Overhead), NICHT 20.000.
    expect($parts['bound'])->toBeLessThanOrEqual(4200 + 400)
        // Und das Verworfene wird ausgewiesen statt still zu verschwinden.
        ->and($parts['dropped'])->toBeGreaterThan(0);
});

/*
 * W0-0 — ohne diese Spalten sind alle Token-Aussagen der folgenden Wellen Schätzungen.
 */
it('protokolliert Promptgroesse und Topf-Zerlegung im Call-Log', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose('recipe.description', ['description' => 'Klarer Fond aus Rinderknochen.'], []);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->first();
    $parts = json_decode((string) $log->prompt_parts, true);

    expect((int) $log->prompt_chars)->toBeGreaterThan(0)
        ->and($parts)->toHaveKeys(['kanon', 'retrieval', 'bound', 'task', 'kontext', 'huelle', 'dropped'])
        ->and($parts['task'])->toBeGreaterThan(0)
        ->and($parts['kontext'])->toBeGreaterThan(0)
        // W0-2: kompaktes JSON — der Kontext dieser kleinen Nutzlast bleibt dreistellig;
        // mit JSON_PRETTY_PRINT wäre er rund doppelt so groß.
        ->and($parts['kontext'])->toBeLessThan(300)
        ->and((int) $log->prompt_chars)->toBeGreaterThanOrEqual($parts['task'] + $parts['kontext']);
});

/*
 * W0-5 — die Invariante, deren Verletzung mir beim Bauen selbst passiert ist: mit einem
 * Deckel von 11.000 für `concept.plan` (concept:always 4 × 4.000 = 16.000) fiel das vierte
 * Pflicht-Dossier lautlos aus dem Block. Der Deckel kappt am ENDE der Assembly — zu klein
 * gesetzt frisst er genau das Wissen, das er schützen soll.
 */
it('Budget traegt die Pflicht-Inhalte jedes Features mit always-Routing', function () {
    $svc = app(KnowledgeContextService::class);

    $features = DB::table('foodalchemist_knowledge_routings')
        ->where('mode', 'always')->distinct()->pluck('feature');

    expect($features)->not->toBeEmpty();                             // sonst prüft der Test nichts

    $verletzt = [];
    foreach ($features as $feature) {
        $pflicht = $svc->pflichtZeichen((string) $feature);
        $budget = $svc->budgetFuer((string) $feature);
        if ($budget < $pflicht) {
            $verletzt[] = "{$feature}: budget {$budget} < pflicht {$pflicht}";
        }
    }

    expect($verletzt)->toBe([]);
});

it('rechnet die Pflichtmenge nach den Ist-Deckeln der Block-Builder', function () {
    // cross_cutting ignoriert die Routing-Werte und lädt 7 feste Slugs.
    w0Routing('w0pflicht.cc', 'cross_cutting', 'always', 99, 99000);
    expect(app(KnowledgeContextService::class)->pflichtZeichen('w0pflicht.cc'))
        ->toBe(7 * KnowledgeContextService::CROSS_CUTTING_TRUNCATE_CHARS);

    // regelwerk holt per ->first() genau EIN Doc — max_docs ist dort irrelevant.
    w0Routing('w0pflicht.rw', 'regelwerk', 'always', 5, 6000);
    expect(app(KnowledgeContextService::class)->pflichtZeichen('w0pflicht.rw'))->toBe(6000);

    // concept: max_docs × Doc-Deckel.
    w0Routing('w0pflicht.co', 'concept', 'always', 4, 4000);
    expect(app(KnowledgeContextService::class)->pflichtZeichen('w0pflicht.co'))->toBe(16000);
});

/*
 * W0-3b — der teuerste Fund der Welle, und der subtilste.
 *
 * RecipeGenerationContextService spiegelte die gebundenen Dossiers nach `files_used`, um sie
 * im „Verwendetes Wissen"-Chip anzuzeigen. `files_used` geht aber als `knowledge_used` an
 * propose() und ist dort der DEDUP-EINGANG von selectBoundKnowledge(). Ergebnis: von 7 als
 * `always` gebundenen Bau-Dossiers kam NULL im Prompt an — die Transparenz-Anzeige hatte
 * genau den Kanal abgeschaltet, den sie sichtbar machen wollte. Gemessen auf demo am
 * 2026-09-02: prompt_parts.bound = 0 bei vk.generator.
 *
 * Der Test prüft beide Richtungen: der Kanal liefert, UND die Anzeige bekommt ihre Liste.
 */
it('spiegelt gebundene Dossiers NICHT in files_used — sonst frisst der Dedup den Bound-Kanal', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    // Fixture-Hinweis: Kategorie ohne Routing-Zeile und Slug OHNE „basisrezept" — sonst würde
    // contextFor() die Docs selbst laden (regelwerkBlock matcht `slug LIKE '%basisrezept%'`,
    // und die Migrationen seeden `regelwerk:always`). Der Test soll den SPIEGEL prüfen,
    // nicht den legitimen Retrieval-Pfad.

    $slugs = [];
    foreach ([1968, 2459, 2630] as $i => $chars) {
        $slug = "w0b-bau-regel-{$i}";
        w0Doc($slug, 'w0bindcat', $chars, 'Bau Regel');
        w0Bind($slug, 'recipe.generator', 'always', 100 - $i);
        $slugs[] = $slug;
    }

    $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
        ->build($this->rootTeam, 'Rinderfilet als Hauptgang', [], false);

    // 1. files_used bleibt SAUBER — nur was contextFor wirklich geladen hat.
    foreach ($slugs as $slug) {
        expect(implode(' ', $ctx['knowledge_used']))->not->toContain($slug);
        expect(implode(' ', $ctx['snapshot']['knowledge_files']))->not->toContain($slug);
    }

    // 2. Die Anzeige bekommt sie trotzdem — im Kanal, den der Inspektor auch liest.
    expect($ctx['kontext']['wissen'])->toHaveKey('gebunden')
        ->and(implode(' ', $ctx['kontext']['wissen']['gebunden']))->toContain($slugs[0]);
});

it('liefert gebundene Dossiers in den Prompt, wenn der Kontext-Dienst sie nicht als verwendet meldet', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    foreach ([1968, 2459, 2630] as $i => $chars) {
        $slug = "w0c-bau-regel-{$i}";
        w0Doc($slug, 'w0bindcat', $chars, 'Bau Regel');
        w0Bind($slug, 'recipe.generator', 'always', 100 - $i);
    }

    $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
        ->build($this->rootTeam, 'Rinderfilet als Hauptgang', [], false);

    app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
        'recipe.generator',
        $ctx['prompt'],
        ['knowledge' => $ctx['knowledge'], 'knowledge_used' => $ctx['knowledge_used']],
    );

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.generator')->latest('id')->first();
    $parts = json_decode((string) $log->prompt_parts, true);

    // 1968 + 2459 + 2630 = 7.057 Zeichen Pflichtwissen müssen ankommen.
    expect($parts['bound'])->toBeGreaterThan(7000);
});

/*
 * ACHSEN-AUFLÖSUNG — Nachschlagen statt Suchen.
 *
 * `occasion` und `sektor` sind geschlossene Enums (RecipesGenerateTool:76/:80) und die
 * Dossiers sind danach benannt. Vorher waren `event_playbook` (13 Docs) und `segment`
 * (7 Docs) für Gerichte GAR NICHT geroutet — 20 Docs Catering-Fachwissen unerreichbar.
 * Jetzt: deterministischer Join, keine Rate-Mechanik.
 */
it('loest Anlass und Segment deterministisch aus den Leitplanken auf', function () {
    w0Doc('event_playbook_gala', 'event_playbook', 3629, 'Gala Ablauf');
    w0Doc('segment.event_bankett_catering', 'segment', 1688, 'Bankett Profil');
    // Ein Dossier, das NICHT gewählt werden darf — Gegenprobe gegen Zufallstreffer.
    w0Doc('event_playbook_brunch', 'event_playbook', 2552, 'Brunch Ablauf');

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Geschmorte Ochsenbacke', null, [],
        ['occasion' => 'dinner', 'sektor' => 'catering'],
    );

    // Der Block rendert den TITEL (lesbar für die KI), der SLUG steht im Audit —
    // deshalb wird die Auflösung an files_used/herkunft geprüft, nicht am Fließtext.
    expect($ctx['used_by_category'])->toHaveKey('achse')
        ->and($ctx['used_by_category']['achse'])->toContain('event_playbook_gala@v1')
        ->and($ctx['used_by_category']['achse'])->toContain('segment.event_bankett_catering@v1')
        ->and($ctx['herkunft']['event_playbook_gala']['via'])->toBe('achse:occasion')
        ->and($ctx['herkunft']['segment.event_bankett_catering']['via'])->toBe('achse:sektor')
        // Inhalt ist wirklich drin …
        ->and($ctx['block'])->toContain('Gala Ablauf')
        ->and($ctx['block'])->toContain('Bankett Profil')
        // … und `dinner` zieht NICHT das Brunch-Playbook.
        ->and($ctx['block'])->not->toContain('Brunch Ablauf')
        ->and($ctx['used_by_category']['achse'])->not->toContain('event_playbook_brunch@v1');
});

it('bleibt still, wenn die Achse keinen Wert hat — ein Basisrezept hat keinen Anlass', function () {
    w0Doc('event_playbook_gala', 'event_playbook', 3629, 'Gala Ablauf');

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Selleriepüree als Komponente', null, [], ['level' => 'gehoben'],
    );

    expect($ctx['block'])->not->toContain('Gala Ablauf')
        ->and($ctx['used_by_category'])->not->toHaveKey('achse');
});

it('weicht bei fehlendem Dossier NICHT auf ein fremdes Profil aus', function () {
    // Für `sektor=restaurant` existiert kein segment-Dossier. Andere sind vorhanden —
    // genau die Situation, in der ein Fuzzy-Ranking irgendetwas geliefert hätte.
    w0Doc('segment.betriebsgastronomie', 'segment', 1951, 'Betriebsgastro Profil');
    w0Doc('segment.klinik_senioren_care', 'segment', 1806, 'Care Profil');

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Rinderroulade', null, [], ['sektor' => 'restaurant'],
    );

    expect($ctx['block'])->not->toContain('Betriebsgastro Profil')
        ->and($ctx['block'])->not->toContain('Care Profil');
});

it('nimmt den ersten AKTIVEN Kandidaten der Achse', function () {
    // schule_kita listet drei Kandidaten; der erste ist deaktiviert → der zweite gewinnt.
    w0Doc('segment.kita_schule_ernaehrung_dge', 'segment', 5809, 'DGE Standards');
    DB::table('foodalchemist_knowledge_documents')
        ->where('slug', 'segment.kita_schule_ernaehrung_dge')->update(['active' => 0]);
    w0Doc('segment.schulverpflegung', 'segment', 2097, 'Schulverpflegung Profil');

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Gemüseauflauf', null, [], ['sektor' => 'schule_kita'],
    );

    expect($ctx['block'])->toContain('Schulverpflegung Profil')
        ->and($ctx['block'])->not->toContain('DGE Standards');
});

it('ueberlebt das Gesamtbudget — Achsen-Wissen wird nicht als Erstes gekappt', function () {
    w0Doc('event_playbook_gala', 'event_playbook', 3629, 'Gala Ablauf');
    // Discovery mit reichlich Material, das um dasselbe Budget konkurriert.
    foreach (range(1, 6) as $i) {
        w0Doc("ochsenbacke-technik-{$i}", "w0achscat{$i}", 6000, "Ochsenbacke Schmoren {$i}");
        w0Routing('ai_generate_recipe', "w0achscat{$i}", 'discovery', 3, 8000);
    }

    $ctx = app(KnowledgeContextService::class)->contextFor(null,
        'ai_generate_recipe', 'Ochsenbacke schmoren', null, [], ['occasion' => 'dinner'],
    );

    // Der Deckel schneidet am ENDE ab — das Achsen-Wissen steht vorn und bleibt.
    expect($ctx['block'])->toContain('Gala Ablauf')
        ->and($ctx['dropped_chars'])->toBeGreaterThan(0);
});

/*
 * W3-1 — MESSAGE-LAYOUT FÜR DEN PREFIX-CACHE.
 *
 * Der Bound-Block (nur `always`-Dossiers) ist über alle Calls eines Prompt-Keys
 * byte-identisch. Nur wenn er VOR allem Variablen steht, kann der implizite Prefix-Cache
 * greifen (10 % des Input-Preises). Vorher stand das variable Retrieval-Wissen als Erstes
 * im User-Content — gemessene Cache-Quote: 0,35 %.
 */
it('legt das verbindliche Regelwerk als system-Message VOR alles Variable', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    // 900 Zeichen: passt unter den konservativen Default-Deckel (1.400/Doc) von
    // `recipe.description` — so ist die Zerlegung ohne Kürzung nachrechenbar.
    w0Doc('w31-regel', 'w0bindcat', 900, 'Bau Regel');
    w0Bind('w31-regel', 'recipe.description', 'always');

    app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose('recipe.description', ['description' => 'Klarer Fond.'], ['knowledge' => "# RETRIEVAL\n\nVariables Wissen."]);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    $parts = json_decode((string) $log->prompt_parts, true);

    // Der Block ist da, als eigener Topf ausgewiesen …
    expect($parts['bound'])->toBeGreaterThan(900)
        ->and($parts['bound'])->toBeLessThan(1100)          // 900 + Block-/Doc-Header, ungekürzt
        // … und die Zerlegung geht auf: kein Doppelzählen zwischen huelle und bound.
        ->and($parts['huelle'])->toBeGreaterThan(0)
        // Die Zerlegung muss prompt_chars EXAKT ergeben — sonst ist die Sonde als Grundlage
        // für Budget-Entscheidungen wertlos. Separatoren: "\n\n" vor dem Retrieval-Block (2)
        // und "\n\nKontext:\n" vor dem Kontext-JSON (11).
        ->and((int) $log->prompt_chars)->toBe(
            $parts['huelle'] + $parts['bound'] + $parts['task']
            + ($parts['retrieval'] > 0 ? 2 + $parts['retrieval'] : 0)
            + 11 + $parts['kontext']
        );
});

it('stellt den Task VOR das variable Retrieval-Wissen', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    // Der Fake-Provider spiegelt den Kontext hinter »Kontext:« — der User-Content muss
    // also weiterhin mit dem Task beginnen und mit dem Kontext-JSON enden.
    $p = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose('recipe.description', ['description' => 'Klarer Fond.'], ['knowledge' => "# RETRIEVAL\n\nVariables Wissen."]);

    // Der Echo-Provider hat den Kontext gefunden ⇒ die Reihenfolge task → wissen → Kontext
    // ist intakt (sonst greift sein /Kontext:\s*(\{.*\})/s-Muster nicht).
    expect($p->werte)->toHaveKey('description')
        ->and($p->werte['description'])->toBe('Klarer Fond.');
});

/*
 * B1 — VERKETTETE WISSENSBLÖCKE.
 *
 * Aufrufer komponieren: `IdeenService` baut für `foodbook.kapitel_ideen` zwei Blöcke
 * (concept.plan + concept.brief_geruest) und verkettet sie. Das Budget lebt pro Feature in
 * contextFor — die Komposition kannte es nicht. Gemessen auf demo am 2026-09-02:
 * 29.028 + 10.028 = 39.056 Zeichen, darin `kalkulation_event_angebot` DOPPELT.
 */
it('respektiert einen Budget-Override des Aufrufers', function () {
    // Slug-Tokens müssen die Query treffen — das Ranking ist lexikalisch (W0-6).
    foreach (range(1, 6) as $i) {
        w0Doc("buffet-technik-{$i}", "w0kompocat{$i}", 6000, "Buffet Technik {$i}");
        w0Routing('concept.plan', "w0kompocat{$i}", 'discovery', 3, 8000);
    }
    $svc = app(KnowledgeContextService::class);

    $ohne = $svc->contextFor(null, 'concept.plan', 'Flying Buffet Technik', null, [], []);
    $mit = $svc->contextFor(null, 'concept.plan', 'Flying Buffet Technik', null, [], ['_max_chars' => 8000]);

    // Erst belegen, dass die Fixture überhaupt greift — sonst prüft der Test nichts.
    expect($ohne['total_chars'])->toBeGreaterThan(8000)
        // Der Override greift und ist strikt kleiner als das Feature-Budget …
        ->and($mit['total_chars'])->toBeLessThan($ohne['total_chars'])
        // … aber NIE unter die Pflichtmenge: ein Override, der `always`-Inhalte abschneidet,
        // wäre genau der stille Fehler, den die W0-5-Invariante verhindern soll.
        ->and($mit['total_chars'])->toBeGreaterThanOrEqual($svc->pflichtZeichen('concept.plan'));
});

it('klemmt einen zu kleinen Override auf die Pflichtmenge statt Pflichtwissen zu kappen', function () {
    // Pflicht aufbauen: eine always-Kategorie mit realem Inhalt.
    w0Doc('kompo-pflicht-a', 'w0pflichtkat', 3000, 'Pflicht A');
    w0Doc('kompo-pflicht-b', 'w0pflichtkat', 3000, 'Pflicht B');
    w0Routing('kompo.feature', 'w0pflichtkat', 'always', 2, 3000);
    $svc = app(KnowledgeContextService::class);

    $pflicht = $svc->pflichtZeichen('kompo.feature');
    expect($pflicht)->toBe(6000);

    // Absurd kleiner Override — darf die Pflicht nicht unterschreiten.
    $ctx = $svc->contextFor(null, 'kompo.feature', 'irgendwas', null, [], ['_max_chars' => 500]);

    expect($ctx['total_chars'])->toBeGreaterThanOrEqual($pflicht);
});

it('liefert ausgeschlossene Slugs nicht erneut — keine Dopplung im verketteten Block', function () {
    w0Doc('buffet-geteilt', 'w0sharedcat', 3000, 'Geteiltes Wissen');
    w0Doc('buffet-eigen', 'w0sharedcat', 3000, 'Eigenes Wissen');
    // Dieselbe Kategorie für BEIDE Features — genau die Konstellation, die die Dopplung erzeugt.
    w0Routing('kompo.erst', 'w0sharedcat', 'discovery', 2, 3000);
    w0Routing('kompo.zweit', 'w0sharedcat', 'discovery', 2, 3000);
    $svc = app(KnowledgeContextService::class);

    $erst = $svc->contextFor(null, 'kompo.erst', 'buffet geteilt', null, [], []);
    expect($erst['files_used'])->not->toBeEmpty();

    $zweit = $svc->contextFor(null, 'kompo.zweit', 'buffet geteilt', null, [], [
        '_exclude_slugs' => $erst['files_used'],
    ]);

    // Kein Slug aus dem ersten Block taucht im zweiten wieder auf.
    $ersteSlugs = array_map(fn ($f) => explode('@', $f)[0], $erst['files_used']);
    $zweiteSlugs = array_map(fn ($f) => explode('@', $f)[0], $zweit['files_used']);
    expect(array_intersect($ersteSlugs, $zweiteSlugs))->toBe([]);
});

it('akzeptiert Ausschluss-Slugs mit und ohne Versions-Suffix', function () {
    w0Doc('buffet-versioniert', 'w0vercat', 2000, 'Versioniert');
    w0Routing('kompo.ver', 'w0vercat', 'discovery', 2, 2000);
    $svc = app(KnowledgeContextService::class);

    // Gegenprobe: ohne Ausschluss greift die Fixture.
    expect($svc->contextFor(null, 'kompo.ver', 'buffet versioniert', null, [], [])['files_used'])->not->toBeEmpty();

    // Der Aufrufer übergibt files_used („slug@v1"); intern wird das Suffix gestrippt.
    $mitSuffix = $svc->contextFor(null, 'kompo.ver', 'buffet versioniert', null, [], ['_exclude_slugs' => ['buffet-versioniert@v1']]);
    $ohneSuffix = $svc->contextFor(null, 'kompo.ver', 'buffet versioniert', null, [], ['_exclude_slugs' => ['buffet-versioniert']]);

    expect($mitSuffix['files_used'])->toBe([])
        ->and($ohneSuffix['files_used'])->toBe([]);
});

/*
 * B3 — Cross-Cutting je Feature.
 *
 * `ALWAYS_LOAD_CROSS_CUTTING` sind sieben PRODUKTIONS-Dossiers (12.600 Z.). Für einen
 * Generator richtig, für einen Kundentext falsch: `foodbook.kundentext` schreibt 2–4 Sätze
 * (max_tokens 1.500) und bekam Mengen-Defaults und Brühen-Rezepturen mitgeliefert.
 */
it('laedt fuer einen Kundentext nur die passenden Cross-Cutting-Dossiers', function () {
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        w0Doc($slug, 'cross_cutting', 4000, 'Produktionswissen ' . $slug);
    }
    w0Routing('foodbook.kundentext', 'cross_cutting', 'always');
    // Vergleichs-Feature ohne Überschreibung (statt ai_generate_recipe, dessen
    // RECIPE_MAX_CHARS_PER_DOC-Klemme das Bild verfälschen würde).
    w0Routing('w0cc.generator', 'cross_cutting', 'always');
    $svc = app(KnowledgeContextService::class);

    $text = $svc->contextFor(null, 'foodbook.kundentext', 'Sommerliches Buffet', null, [], []);
    $gen = $svc->contextFor(null, 'w0cc.generator', 'Sommerliches Buffet', null, [], []);

    $textSlugs = array_map(fn ($f) => explode('@', $f)[0], $text['used_by_category']['cross_cutting'] ?? []);
    $genSlugs = array_map(fn ($f) => explode('@', $f)[0], $gen['used_by_category']['cross_cutting'] ?? []);

    // Kundentext: nur was er braucht …
    expect($textSlugs)->toContain('saisonkalender')->toContain('synonyme')
        ->and($textSlugs)->not->toContain('mengen_defaults')
        ->and($textSlugs)->not->toContain('bruehen_fonds')
        ->and($textSlugs)->not->toContain('sauce_mutterstrukturen')
        // … der Generator behält den vollen Produktions-Satz.
        ->and($genSlugs)->toContain('mengen_defaults')
        ->and($genSlugs)->toContain('bruehen_fonds')
        ->and(count($genSlugs))->toBe(count(KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING));
});

it('rechnet die Pflichtmenge feature-genau — sonst prueft die Invariante Phantasiewerte', function () {
    // Synthetische Features: die echten tragen aus den Migrationen weitere always-Routings
    // (regelwerk, produktion_kapazitat …), die die Summe verunreinigen würden.
    // ERGÄNZEN, nicht ersetzen — ein config()->set() mit nur dem Testschlüssel würde die
    // echten Einträge (foodbook.kundentext, concept.wording) wegwerfen und die Prüfung
    // weiter unten sinnlos machen.
    config()->set('foodalchemist.ai.cross_cutting_slugs', array_merge(
        (array) config('foodalchemist.ai.cross_cutting_slugs', []),
        ['w0cc.schmal' => ['saisonkalender', 'synonyme']],
    ));
    w0Routing('w0cc.schmal', 'cross_cutting', 'always');
    w0Routing('w0cc.voll', 'cross_cutting', 'always');
    $svc = app(KnowledgeContextService::class);

    expect($svc->pflichtZeichen('w0cc.schmal'))
        ->toBe(2 * KnowledgeContextService::CROSS_CUTTING_TRUNCATE_CHARS)
        ->and($svc->pflichtZeichen('w0cc.voll'))
        ->toBe(count(KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING) * KnowledgeContextService::CROSS_CUTTING_TRUNCATE_CHARS);

    // Und die echten Kundentext-Features müssen ihre (kleinere) Pflicht tragen können.
    foreach (['foodbook.kundentext', 'concept.wording'] as $f) {
        expect($svc->budgetFuer($f))->toBeGreaterThanOrEqual($svc->pflichtZeichen($f));
    }
});
