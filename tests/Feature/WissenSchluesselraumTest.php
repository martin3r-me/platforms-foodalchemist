<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · Paket 2 — ein Schlüsselraum.
 *
 * Bis 2026-09-08 trug derselbe Aufruf zwei Identitäten: `RecipeGenerationContextService:88`
 * rief `contextFor()` mit dem hartkodierten `'ai_generate_recipe'`, während der Kanon über
 * `recipe.generator`/`vk.generator` aufgelöst wurde. Zwei gemessene Folgen:
 *
 *   · Basisrezept und Gericht teilten **zwangsweise** eine Suchpolitik — eine Routing-Zeile
 *     für beide, keine Möglichkeit, VK anders zu steuern.
 *   · Ein `knowledge_routings.PUT` auf `vk.generator` schrieb **stumm ins Leere**: die Zeile
 *     entstand, aber der Generator sah dort nie nach.
 *
 * ★ Die Umstellung ist nur dann harmlos, wenn sie **verhaltensneutral** ist. Der erste Test
 * hier ist deshalb der wichtigste: `$recipeBudget` hing an einem String-Vergleich und gatete
 * acht Verhaltensweisen, darunter jeden Pro-Dossier-Deckel. Ein naiver Austausch hätte jeden
 * Rezept-Prompt anders gekappt, ohne dass ein Test rot wird.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // ★ Die Slugs tragen hier bewusst Anfrage-Tokens. Die lexikalische Haelfte der Discovery
    // bewertet AUSSCHLIESSLICH Slug-Tokens (+ gepflegte Aliase):
    //   score = jaccard(query, slugTokens) + 0.1 * substringTreffer + (alias ? 1.0 : 0)
    // Der INHALT geht nicht ein. Semantik ist per Default aus (ENV
    // `FOODALCHEMIST_SEMANTIC_SEARCH`), also entscheidet in Tests und auf einer frischen
    // Installation allein der Slug. Meine ersten drei Faelle hiessen `nur-fuer-vk` und
    // `gemeinsam` — kein Wort davon steht in der Anfrage, also fielen sie unter
    // DISCOVERY_MIN_SCORE. Das war kein Testfehler in der Sache, sondern der Befund selbst.
    $this->mkDoc = function (string $slug, string $kategorie): void {
        $inhalt = "## {$slug}\n".str_repeat('Karotte Ingwer Pueree Technik. ', 200);
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => $kategorie, 'content_md' => $inhalt,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkRouting = fn (string $feature, string $kategorie, string $mode, ?int $docs = null, ?int $chars = null) => DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => $feature, 'category' => $kategorie],
        ['mode' => $mode, 'max_docs' => $docs, 'max_chars_per_doc' => $chars, 'created_at' => now(), 'updated_at' => now()],
    );
});

it('liefert unter recipe.generator BYTE-IDENTISCH dasselbe wie unter dem Alt-Namen', function () {
    // Der Sicherheitsnetz-Test der ganzen Etappe. `$recipeBudget` hing an
    // `$feature === 'ai_generate_recipe'` und steuerte RECIPE_MAX_CHARS_PER_DOC (2.400) statt
    // der Kategorie-Defaults (1.800/2.500) — ein naiver Austausch haette jeden Rezept-Prompt
    // anders gekappt. Jetzt entscheidet REZEPT_BUDGET_KEYS, und beide Wege muessen dasselbe tun.
    ($this->mkDoc)('karotte-ingwer-technik', 'kueche');
    ($this->mkDoc)('karotte-domaene', 'domain');
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2, 2500);
    ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery');

    $dienst = app(KnowledgeContextService::class);
    $alt = $dienst->contextFor($this->rootTeam, 'ai_generate_recipe', 'Karotte Ingwer Pueree');
    $neu = $dienst->contextFor($this->rootTeam, 'recipe.generator', 'Karotte Ingwer Pueree');

    expect($neu['block'])->toBe($alt['block'])
        ->and($neu['files_used'])->toBe($alt['files_used'])
        ->and($neu['total_chars'])->toBe($alt['total_chars'])
        ->and($neu['dropped_chars'])->toBe($alt['dropped_chars']);
});

it('behaelt die Rezept-Deckel fuer beide Generator-Keys', function () {
    // Die Deckel sind der eigentliche Inhalt der Neutralitaet: `cross_cutting` wird bei
    // Rezept-Aufrufen auf 2.400 gekappt, sonst auf 1.800.
    expect(KnowledgeContextService::REZEPT_BUDGET_KEYS)
        ->toContain('ai_generate_recipe')
        ->toContain('recipe.generator')
        ->toContain('vk.generator');
});

it('macht ein Routing auf vk.generator erstmals wirksam', function () {
    // Vorher schrieb `knowledge_routings.PUT` auf `vk.generator` stumm ins Leere.
    ($this->mkDoc)('karotte-weltkueche-vk', 'weltkueche');
    ($this->mkRouting)('vk.generator', 'weltkueche', 'discovery', 1, 4000);

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'vk.generator', 'Karotte Ingwer Pueree');

    expect($wissen['files_used'])->toContain('karotte-weltkueche-vk@v1');
});

it('laesst eine einzelne VK-Zeile die anderen Kategorien NICHT verlieren', function () {
    // ★ Konstruktionsfehler meiner ersten Fassung: der Rueckfall wirkte alles-oder-nichts pro
    // FEATURE. Wer eine VK-Zeile setzt, haette damit still die anderen elf verloren —
    // `vk.generator` haette nur noch `weltkueche` gehabt, ohne dass irgendwo steht, dass
    // `kueche` und `domain` weg sind.
    //
    // Jetzt wirkt der Rueckfall PRO KATEGORIE, wie ueberall sonst im Modul: der Kanon
    // ueberschreibt Bindungen pro Prompt-Key, eine Achsen-Zeile die Config pro Achsenwert.
    // „Bewusst leer" sagt man mit `mode = none`, nicht durch das Fehlen einer Zeile.
    ($this->mkDoc)('karotte-kueche-geerbt', 'kueche');
    ($this->mkDoc)('karotte-weltkueche-eigen', 'weltkueche');
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2, 2500);
    ($this->mkRouting)('ai_generate_recipe', 'weltkueche', 'discovery', 1, 2000);
    // EINE eigene Zeile, mit anderem Deckel als der Alt-Name.
    ($this->mkRouting)('vk.generator', 'weltkueche', 'discovery', 1, 4000);

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'vk.generator', 'Karotte Ingwer Pueree');

    expect($wissen['files_used'])
        ->toContain('karotte-weltkueche-eigen@v1')   // eigene Zeile greift
        ->toContain('karotte-kueche-geerbt@v1');     // und die uebrigen bleiben geerbt
});

it('laesst eine eigene Zeile mit mode=none die geerbte ausdruecklich abschalten', function () {
    // Der Gegen-Weg: „VK soll diese Kategorie NICHT" wird explizit gesagt, nicht durch
    // Weglassen. Sonst waere „bewusst leer" von „noch nicht gepflegt" nicht unterscheidbar.
    ($this->mkDoc)('karotte-kueche-geerbt', 'kueche');
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2, 2500);
    ($this->mkRouting)('vk.generator', 'kueche', 'none');

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'vk.generator', 'Karotte Ingwer Pueree');

    expect($wissen['files_used'])->not->toContain('karotte-kueche-geerbt@v1');
});

it('trennt Basisrezept und Gericht, sobald VK eigene Zeilen hat', function () {
    // DAS ist die neue Faehigkeit: eine eigene Zeile fuer VK gewinnt gegen den geteilten
    // Alt-Namen. Vorher war eine unterschiedliche Politik nicht einstellbar.
    ($this->mkDoc)('karotte-gemeinsam', 'kueche');
    ($this->mkDoc)('karotte-nur-vk', 'weltkueche');
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2, 2500);
    ($this->mkRouting)('vk.generator', 'weltkueche', 'discovery', 1, 4000);
    // Ausdrueckliches Abschalten fuer VK — durch Weglassen geht es bewusst NICHT (s. o.).
    ($this->mkRouting)('vk.generator', 'kueche', 'none');

    $dienst = app(KnowledgeContextService::class);
    $basis = $dienst->contextFor($this->rootTeam, 'recipe.generator', 'Karotte Ingwer Pueree');
    $vk = $dienst->contextFor($this->rootTeam, 'vk.generator', 'Karotte Ingwer Pueree');

    // Basis erbt den Alt-Namen, VK hat eine eigene Politik → wirklich getrennt steuerbar.
    expect($basis['files_used'])->toContain('karotte-gemeinsam@v1')
        ->and($vk['files_used'])->toContain('karotte-nur-vk@v1')
        ->and($vk['files_used'])->not->toContain('karotte-gemeinsam@v1');
});

it('faellt ohne eigene Zeilen auf den Alt-Namen zurueck, statt leer zu laufen', function () {
    // Bestandsschutz: die 13 `ai_generate_recipe`-Zeilen wirken weiter. Ohne diesen Rueckfall
    // haette die Umstellung dem Generator sein ganzes Discovery-Wissen genommen.
    ($this->mkDoc)('karotte-erbt-vom-altnamen', 'kueche');
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2, 2500);

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'recipe.generator', 'Karotte Ingwer Pueree');

    expect($wissen['files_used'])->toContain('karotte-erbt-vom-altnamen@v1');
});

it('gibt dem Gericht-Vorgang das VK-Regelwerk, nicht das Basisrezepte-Regelwerk', function () {
    // Latenter Fehler, gefunden in Paket 2: `vk.generator` hatte keinen Eintrag in
    // REGELWERK_SLUG_LIKE, und der Blind-Default lieferte `%basisrezept%`. Ohne Kanon bekam der
    // Gericht-Pfad damit das falsche Regelwerk. Auf demo verdeckte der Kanon das.
    ($this->mkDoc)('regelwerk-basisrezepte-1-naming', 'regelwerk');
    ($this->mkDoc)('regelwerk.regelwerk_verkaufsgerichte--1-naming', 'regelwerk');
    $dienst = app(KnowledgeContextService::class);

    expect($dienst->regelwerkDossiersFuer($this->rootTeam, 'vk.generator')->pluck('slug')->all())
        ->toBe(['regelwerk.regelwerk_verkaufsgerichte--1-naming'])
        ->and($dienst->regelwerkDossiersFuer($this->rootTeam, 'recipe.generator')->pluck('slug')->all())
        ->toBe(['regelwerk-basisrezepte-1-naming']);
});

it('nennt ALLE Dossiers des Bereichs, nicht das alphabetisch erste', function () {
    // ★ Spec 52 · F4. Vorher nahm die Methode `orderBy('slug')->first()` — das war richtig,
    // solange sie einen PROMPT-Block baute (dort passt nur eines). Als reines Nachschlagen
    // für `regelwerk.GET` und das Vorgangs-Register ist es falsch: `%basisrezept%` trifft
    // rund zwanzig §-Dossiers, und dem Agenten §1.0 zu nennen statt aller ist schlechter
    // als gar keine Antwort — er hält es für vollständig.
    foreach (['regelwerk-basisrezepte-1-naming', 'regelwerk-basisrezepte-2-verarbeitung',
        'regelwerk-basisrezepte-6-mengen'] as $slug) {
        ($this->mkDoc)($slug, 'regelwerk');
    }

    expect(app(KnowledgeContextService::class)
        ->regelwerkDossiersFuer($this->rootTeam, 'recipe.generator')->pluck('slug')->all())
        ->toBe([
            'regelwerk-basisrezepte-1-naming',
            'regelwerk-basisrezepte-2-verarbeitung',
            'regelwerk-basisrezepte-6-mengen',
        ]);
});

it('nennt lieber KEIN Regelwerk als ein falsches', function () {
    // Der Blind-Default (`?? ['ai_generate_recipe']`) widersprach dem Prinzip, das direkt
    // ueber der Karte steht. Er traf real drei Vorgaenge: Angebot, Speiseplan und
    // Preis-Monitoring bekamen das Basisrezepte-Regelwerk, obwohl es fuer sie keines gibt.
    ($this->mkDoc)('regelwerk-basisrezepte-1-naming', 'regelwerk');

    expect(app(KnowledgeContextService::class)
        ->regelwerkDossiersFuer($this->rootTeam, 'angebot.irgendwas'))->toBeEmpty();
});

it('gibt dem GP-Vorgang das GP-Regelwerk', function () {
    // `gp_aus_la_anlegen` fiel ebenfalls auf `%basisrezept%` — obwohl `regelwerk-gp-*` existiert.
    ($this->mkDoc)('regelwerk-basisrezepte-1-naming', 'regelwerk');
    ($this->mkDoc)('regelwerk-gp-6-benennungsschema', 'regelwerk');

    expect(app(KnowledgeContextService::class)
        ->regelwerkDossiersFuer($this->rootTeam, 'gp.suggest')->pluck('slug')->all())
        ->toBe(['regelwerk-gp-6-benennungsschema']);
});

it('meldet fuer einen Vorgang ohne Regelwerk `keine`, statt eines zu erfinden', function () {
    ($this->mkDoc)('regelwerk-basisrezepte-1-naming', 'regelwerk');

    $angebot = app(VorgangsRegisterService::class)->vorgang('angebot_erstellen', $this->rootTeam);

    expect($angebot['regelwerke']['quelle'])->toBe('keine')
        ->and($angebot['regelwerke']['dokumente'])->toBe([]);
});

/**
 * ★ Der Drift-Wächter, der `G1` gefunden hätte.
 *
 * `WissenSteuerdatenPolitikTest` hält heute `KnowledgePolicySeedCommand::ROUTINGS` und
 * `WissenSteuerdatenW0Command::ROUTINGS` gegeneinander — aber **nur für
 * `ai_generate_recipe`**. Genau deshalb blieb ein Jahr lang unbemerkt, dass drei Zeilen in
 * einer Migration mit `always` und im Seed mit `discovery` standen: zwei verschiedene
 * Auswahl-Algorithmen für dieselbe Zeile, und welcher gilt, entschied die Skript-Reihenfolge.
 *
 * Dieser Test vergleicht **Code gegen DB** über ALLE Features — nicht Code gegen Code.
 */
it('haelt die Seed-Politik gegen den tatsaechlichen DB-Stand, ueber alle Features', function () {
    $soll = [];
    foreach (\Platform\FoodAlchemist\Console\KnowledgePolicySeedCommand::ROUTINGS as [$feature, $kategorie, $modus, $docs, $chars]) {
        $soll[$feature.'|'.$kategorie] = ['mode' => $modus, 'max_docs' => $docs, 'max_chars_per_doc' => $chars];
    }

    // ★ EINE bekannte, BEDINGTE Abweichung — und sie steht hier, statt still zu bleiben.
    //
    // `ai_generate_recipe × regelwerk`: die Migration setzt `always 1×9500`, der Seed will
    // `none`. Der Seed hat recht, SOLANGE ein Kanon existiert (der liefert das Regelwerk
    // vollständig). Ohne Kanon — also genau im Wiederherstellungs-Fall — wäre `none` die
    // schlechtere Wahl: der Generator bekäme dann GAR KEIN Regelwerk, während `always`
    // wenigstens ein (beliebiges) Dossier liefert.
    //
    // Diese Zeile ist deshalb nicht „noch nicht gefixt", sondern der Beleg, dass der Kanon
    // einen Wiederherstellungs-Pfad braucht (Spec 52/H7). Erst danach ist `none` richtig, und
    // dann fliegt der Eintrag hier raus.
    $bedingtErlaubt = ['ai_generate_recipe|regelwerk'];

    $widersprueche = [];
    foreach (DB::table('foodalchemist_knowledge_routings')->get() as $ist) {
        $key = $ist->feature.'|'.$ist->category;
        if (! isset($soll[$key]) || in_array($key, $bedingtErlaubt, true)) {
            continue;                       // nur im Bestand → kein Widerspruch, nur ungedeckt
        }
        // Der MODUS ist die Weiche. Für `regelwerk` ist `always` seit Spec 52 · F4 sogar tot
        // (lädt nichts), für die übrigen Kategorien wählt er zwischen festem Set und Jaccard.
        // Abweichende Deckel sind Feinjustage; ein abweichender Modus ist ein anderer Algorithmus.
        if ((string) $ist->mode !== $soll[$key]['mode']) {
            $widersprueche[] = $key.': DB='.$ist->mode.' vs. Seed='.$soll[$key]['mode'];
        }
    }

    expect($widersprueche)->toBe([], 'Routing-Modus weicht zwischen Migrationsstand und Seed-Politik ab: '.implode(' · ', $widersprueche));
});

it('seedet die sechs Kategorien, die im Routing stehen aber im Vokabular fehlten', function () {
    // Ohne sie sind die Routing-Zeilen Vorwaertsdeklarationen ohne Wirkung, und
    // `knowledge.POST` in einer dieser Kategorien scheitert an assertKategorie() — obwohl die
    // Steuerung sie kennt. Auf demo existieren sie als TEAM-Zeilen, deshalb fiel es nie auf.
    $vokabular = DB::table('foodalchemist_knowledge_categories')
        ->whereNull('deleted_at')->pluck('slug')->all();

    $ausRoutings = DB::table('foodalchemist_knowledge_routings')
        ->distinct()->pluck('category')->map(fn ($c) => (string) $c)
        // `pairing` ist ein veralteter Routing-Schluessel ohne Kategorie (Spec 50: die
        // Pairing-Dossiers gibt es nicht mehr, der Anker-Graph stuetzt) — bewusst ausgenommen.
        ->reject(fn ($c) => in_array($c, ['pairing', 'trend'], true))
        ->all();

    expect(array_values(array_diff($ausRoutings, $vokabular)))->toBe([]);
});

/**
 * ★ Der Bericht muss zeigen, was zur LAUFZEIT gilt — nicht, was in einer Tabelle steht.
 *
 * Gefunden beim Verifizieren auf demo: eine gesetzte `vk.generator`-Zeile wirkte im
 * Prompt-Bau, und `knowledge_profil.GET` zeigte weiter den geerbten Alias-Wert. Der Grund war
 * eine zweite, eigene Query im Berichts-Dienst — also eine zweite Wahrheit neben der, die der
 * Generator benutzt.
 *
 * Ein Diagnose-Werkzeug, das über die Laufzeit lügt, ist schlimmer als keines: es ist genau die
 * Fehlerklasse, wegen der diese Spec existiert. Jetzt teilen Prompt-Bau, Profil-Bericht und
 * Versorgungs-Bericht EINE Auflösung (`wirksameRoutings()`).
 */
it('zeigt im Profil-Bericht die wirksame Politik, nicht die Alias-Zeile', function () {
    config(['foodalchemist.prompts' => ['vk.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkRouting)('ai_generate_recipe', 'weltkueche', 'discovery', 1, 2000);
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2, 2500);
    // Eigene VK-Zeile mit ANDEREN Deckeln als der Alt-Name.
    ($this->mkRouting)('vk.generator', 'weltkueche', 'discovery', 4, 6000);

    $profil = app(\Platform\FoodAlchemist\Services\Knowledge\WissensProfilService::class)
        ->profil('vk.generator', $this->rootTeam);
    $nach = collect($profil['routing'])->keyBy('category');

    expect($nach['weltkueche']['max_docs'])->toBe(4)             // eigene Zeile, nicht 1
        ->and($nach['weltkueche']['max_chars_per_doc'])->toBe(6000)
        ->and($nach['kueche']['max_docs'])->toBe(2);             // geerbt bleibt geerbt
});

it('zeigt im Versorgungs-Bericht dieselbe wirksame Politik', function () {
    config(['foodalchemist.prompts' => ['vk.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkRouting)('ai_generate_recipe', 'weltkueche', 'discovery', 1, 2000);
    ($this->mkRouting)('vk.generator', 'weltkueche', 'discovery', 4, 6000);

    $zeile = app(\Platform\FoodAlchemist\Services\Knowledge\WissensVersorgungService::class)
        ->zeileFuer('vk.generator', $this->rootTeam);

    expect(collect($zeile['routing'])->firstWhere('category', 'weltkueche')['max_docs'])->toBe(4);
});
