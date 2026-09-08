<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Services\Knowledge\Wissensart;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · H2 — Achsen-Bindungen als DRITTER Kanon-Scope, nicht als vierte Tabelle.
 *
 * `datenwerk`-Wissen wird **aufgelöst, nicht gesucht** (Grundsatz A): ein Standard, der für
 * einen Anlass verbindlich gilt, darf nicht davon abhängen, ob die Suche das Dossier unter die
 * ersten drei Treffer bekommt. Das Muster existierte schon als `achsenBlock()` — es lag nur als
 * hartkodierter Config-Baum da und war damit nicht pflegbar (Grundsatz E).
 *
 * ★ Bewusst KEINE neue Tabelle: eine Achsen-Bindung beantwortet dieselbe Frage wie der Kanon,
 * nur mit anderem Schlüssel. `scope='achse'`, `scope_key='<achse>:<wert>'`. Damit erben sie
 * Tenancy, `unaufloesbareZeilen()`, alle drei MCP-Tools und die künftige UI kostenlos — statt
 * das Muster zu doppeln, das diese Spec abbaut.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, ?string $art = null, string $kategorie = 'event_playbook'): int {
        $inhalt = "## {$slug}\nRahmen fuer diesen Anlass.";
        $daten = [
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => $kategorie, 'content_md' => $inhalt,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ];
        if ($art !== null) {
            $daten['art'] = $art;
        }

        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId($daten);
    };

    $this->mkAchse = function (int $docId, string $achse, string $wert, int $ord = 10): void {
        DB::table('foodalchemist_knowledge_canon')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'scope' => 'achse', 'scope_key' => KnowledgeCanonService::achsenKey($achse, $wert),
            'role' => 'root', 'ord' => $ord,
            'knowledge_document_id' => $docId, 'mode' => 'pflicht', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('loest ein Dossier ueber den Achsenwert auf, ohne jede Suche', function () {
    // Kein Suchbegriff, der zum Dossier passt — die Beschreibung ist voellig anders. Trotzdem
    // muss es ankommen: das ist der Unterschied zwischen aufloesen und suchen.
    ($this->mkAchse)(($this->mkDoc)('gala-rahmen'), 'occasion', 'dinner');

    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'test.achse', 'Karotte Ingwer Pueree', null, [], ['occasion' => 'dinner']
    );

    expect($wissen['files_used'])->toContain('gala-rahmen@v1')
        ->and($wissen['herkunft']['gala-rahmen']['via'])->toBe('achse:occasion');
});

it('laesst die Kanon-Zeile den hartkodierten Config-Default schlagen', function () {
    config(['foodalchemist.ai.knowledge_axis_map' => ['occasion' => ['dinner' => ['config-dossier']]]]);
    ($this->mkDoc)('config-dossier');
    ($this->mkAchse)(($this->mkDoc)('gepflegtes-dossier'), 'occasion', 'dinner');

    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'test.achse', 'Irgendwas', null, [], ['occasion' => 'dinner']
    );

    expect($wissen['files_used'])->toContain('gepflegtes-dossier@v1')
        ->and($wissen['files_used'])->not->toContain('config-dossier@v1');
});

it('faellt ohne Kanon-Zeile unveraendert auf die Config zurueck', function () {
    // Bestandsschutz: der Config-Baum traegt die Achsen seit jeher. Waere er nach dieser
    // Aenderung tot, verloeren alle Anlaesse ihr Wissen, ohne dass ein Test rot wird.
    config(['foodalchemist.ai.knowledge_axis_map' => ['occasion' => ['dinner' => ['nur-config']]]]);
    ($this->mkDoc)('nur-config');

    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'test.achse', 'Irgendwas', null, [], ['occasion' => 'dinner']
    );

    expect($wissen['files_used'])->toContain('nur-config@v1');
});

it('verdrahtet eine ganz neue Achse ohne Deploy', function () {
    // Achsen-NAMEN kommen aus Config UNION Kanon — sonst braeuchte jede neue Achse eine
    // Code-Aenderung, und Grundsatz E waere nur halb erfuellt.
    config(['foodalchemist.ai.knowledge_axis_map' => []]);
    ($this->mkAchse)(($this->mkDoc)('neue-achse-dossier'), 'serviceform', 'buffet');

    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'test.achse', 'Irgendwas', null, [], ['serviceform' => 'buffet']
    );

    expect($wissen['files_used'])->toContain('neue-achse-dossier@v1');
});

it('nimmt den naechsten Kandidaten, wenn der erste deaktiviert ist', function () {
    config(['foodalchemist.ai.knowledge_axis_map' => []]);
    $erst = ($this->mkDoc)('erste-wahl');
    ($this->mkAchse)($erst, 'occasion', 'dinner', 10);
    ($this->mkAchse)(($this->mkDoc)('zweite-wahl'), 'occasion', 'dinner', 20);
    DB::table('foodalchemist_knowledge_documents')->where('id', $erst)->update(['active' => 0]);

    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'test.achse', 'Irgendwas', null, [], ['occasion' => 'dinner']
    );

    expect($wissen['files_used'])->toContain('zweite-wahl@v1')
        ->and($wissen['files_used'])->not->toContain('erste-wahl@v1');
});

it('meldet ein datenwerk-Dossier, das an keiner Achse haengt', function () {
    // Der Fall aus der Messung: ein Mengen-Standard liegt als Prosa im Suchtopf und landete
    // auf Platz 7 — bei max_docs 6 also nirgends. Als `datenwerk` deklariert und nicht
    // aufgeloest ist das ein Hinweis, kein Fehler: die Suche ist immerhin ein Weg.
    config(['foodalchemist.prompts' => ['test.x' => ['tier' => 'B', 'task' => 'x']]]);
    config(['foodalchemist.ai.knowledge_axis_map' => []]);
    ($this->mkDoc)('mengen-standard', Wissensart::DATENWERK, 'cross_cutting');
    ($this->mkAchse)(($this->mkDoc)('sauber-verdrahtet', Wissensart::DATENWERK), 'occasion', 'dinner');

    $bericht = app(WissensProfilService::class)->integritaet($this->rootTeam);

    expect($bericht['datenwerk_ohne_achse'])->toBe(['mengen-standard']);
});

it('MCP: knowledge_canon.PUT setzt eine Achsen-Bindung', function () {
    // Der Payoff der Wiederverwendung: `achse` ist ueber die BESTEHENDEN Kanon-Tools bedienbar,
    // ohne ein einziges neues Tool — alle drei lesen `KnowledgeCanonService::SCOPES`.
    ($this->mkDoc)('restaurant-profil', null, 'segment');
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    $res = app(ToolRegistry::class)->get('foodalchemist.knowledge_canon.PUT')->execute([
        'scope' => 'achse', 'scope_key' => 'sektor:restaurant', 'slug' => 'restaurant-profil',
    ], new ToolContext($user, $this->rootTeam));

    expect($res->success)->toBeTrue((string) ($res->error ?? ''))
        ->and(app(KnowledgeCanonService::class)->achsenBindungen($this->rootTeam))
        ->toBe(['sektor' => ['restaurant' => ['restaurant-profil']]]);
});

it('ignoriert eine Achsen-Zeile ohne <achse>:<wert>-Form statt zu sterben', function () {
    ($this->mkAchse)(($this->mkDoc)('kaputt'), 'ohnetrenner', '');
    DB::table('foodalchemist_knowledge_canon')->where('scope', 'achse')->update(['scope_key' => 'ohnetrenner']);

    expect(app(KnowledgeCanonService::class)->achsenBindungen($this->rootTeam))->toBe([]);
});
