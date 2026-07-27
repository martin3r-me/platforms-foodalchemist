<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S2a-1b — GOLDEN-SET der Anker-Auflösung (`PairingService::resolveRecipeAnchors`).
 *
 * **Wozu:** V-045 (zweiter Halbschritt) baut die Auflösung auf `whereIn`-Batches um.
 * Damit wandert die Auswahl „welches der mehreren `kern`-Mappings gilt?" aus dem SQL
 * (`ORDER BY COALESCE(ai_confidence, 1.0) DESC, id` + `value()`) **in PHP**. Weicht der
 * Nachbau subtil ab — NULL sortiert anders, Gleichstand über Einfüge- statt `id`-Reihenfolge
 * —, wählt das System für einzelne Zutaten einen ANDEREN Anker. Nichts schlägt fehl, kein
 * Crash: der Pairing-Graph verschiebt sich still, und mit ihm Kohäsion, Slot-Ranking und
 * die Dramaturgie-Signale. Die Bestands-Fixtures fangen das nicht, weil sie brav genau EIN
 * Mapping je GP anlegen — sie bestätigen die Annahme, die sie enthalten (dieselbe Falle wie
 * V-019/V-020).
 *
 * **Was hier eingefroren wird:** die *Auswahl* (welcher Anker gewinnt) und die
 * *Zusammensetzung* (welche Zeilen mit welchem `via` herauskommen) — projiziert auf
 * Anker-**Slugs** statt Auto-Increment-IDs, damit die Erwartung lesbar und lauf-stabil ist.
 *
 * **Was bewusst NICHT eingefroren wird:** die Reihenfolge *innerhalb* von `prozess` und
 * innerhalb des angehängten Eigen-Zustands-Blocks. Beide kommen heute aus einem `pluck()`
 * bzw. `get()` **ohne ORDER BY** — das ist Treiber-Zufall (SQLite hier, MySQL auf demo), und
 * ein Golden-Set, das Zufall festnagelt, ist auf der falschen Datenbank rot ohne Befund.
 * Sortiert wird darum in der Projektion; dass der Zustands-Block ein zusammenhängender
 * **Suffix** bleibt, prüft ein eigener Riegel. (Die fehlende Sortierung selbst ist ein
 * Code-Befund und gehört ins Backlog, nicht in diesen Test.)
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->ankerId = [];   // slug => id
    $this->ankerSlug = []; // id => slug

    $this->mkAnker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (int) DB::getPdo()->lastInsertId();
        $this->ankerId[$slug] = $id;
        $this->ankerSlug[$id] = $slug;

        return $id;
    };

    /** GP-Mapping. Reihenfolge der Aufrufe = `id`-Reihenfolge — genau darauf zielt der Tiebreaker. */
    $this->mkGpMapping = function (int $gpId, string $ankerSlug, ?string $conf, string $role = 'kern', bool $geloescht = false): int {
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gpId, 'anchor_id' => $this->ankerId[$ankerSlug], 'role' => $role,
            'source' => 'ai_inferred', 'ai_confidence' => $conf,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $geloescht ? now() : null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };

    $this->mkRezeptMapping = function (int $recipeId, string $ankerSlug, ?string $conf, string $role = 'kern'): int {
        DB::table('foodalchemist_recipe_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $recipeId, 'anchor_id' => $this->ankerId[$ankerSlug], 'role' => $role,
            'source' => 'ai_inferred', 'ai_confidence' => $conf,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };

    $this->mkProzessAnker = function (int $recipeId, string $ankerSlug, bool $geloescht = false): void {
        DB::table('foodalchemist_recipe_process_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $recipeId, 'anchor_id' => $this->ankerId[$ankerSlug],
            'source' => 'ai_inferred', 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $geloescht ? now() : null,
        ]);
    };

    /** Zutaten-Zeile mit Sub-Rezept-Verweis (makeIngredient kennt nur GP/raw_text). */
    $this->mkSubZutat = function (FoodAlchemistRecipe $recipe, FoodAlchemistRecipe $sub, int $position): void {
        FoodAlchemistRecipeIngredient::create([
            'team_id' => $recipe->team_id, 'recipe_id' => $recipe->id,
            'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name,
            'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
            'position' => $position,
        ]);
    };

    /**
     * Projektion auf das, was der Umbau nicht verschieben darf. IDs → Slugs, `prozess`
     * sortiert (kein ORDER BY im SQL, s. Kopf-Docblock), Zustands-Suffix sortiert.
     */
    $this->projiziere = function (array $zeilen): array {
        $slug = fn (?int $id) => $id === null ? null : ($this->ankerSlug[$id] ?? "unbekannt#$id");

        $out = [];
        foreach ($zeilen as $z) {
            $prozess = array_map(fn ($id) => $slug((int) $id), $z['prozess']);
            sort($prozess);
            $out[] = ['label' => $z['label'], 'kern' => $slug($z['kern']), 'prozess' => $prozess, 'via' => $z['via']];
        }

        // Zustands-Zeilen (angehängter Block) untereinander sortieren — ihre Reihenfolge
        // ist heute Treiber-Zufall. Dass sie ein Suffix bilden, prüft ein eigener Test.
        $kopf = [];
        $suffix = [];
        foreach ($out as $zeile) {
            if ($zeile['via'] === 'prozess_raw_text') {
                $suffix[] = $zeile;
            } else {
                $kopf[] = $zeile;
            }
        }
        usort($suffix, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return array_merge($kopf, $suffix);
    };

    // ── Anker-Vokabular ───────────────────────────────────────────────────
    foreach (['neutral', 'apfel', 'birne', 'zimt', 'roestaromen', 'rauch'] as $s) {
        ($this->mkAnker)($s);
    }

    // ── R1: fünf Mehrdeutigkeits-Fälle in einem Rezept ────────────────────
    //
    // Die GP-NAMEN enthalten bewusst „Zimt": bräche die Mapping-Auflösung weg, fiele die
    // Zeile auf `name_match` → `zimt` und der Test sagt sofort, WELCHE Stufe gerissen ist.
    $this->r1 = $this->makeRecipe($this->rootTeam, 'Basis: Mehrdeutige Kerne');

    // (a) Confidence schlägt id: NULL ⇒ COALESCE 1.0 gewinnt, obwohl nicht die kleinste id.
    $gpDrei = $this->makeGp($this->rootTeam, 'GP Zimt Drei Kerne');
    ($this->mkGpMapping)($gpDrei->id, 'birne', '0.900');   // kleinste id
    ($this->mkGpMapping)($gpDrei->id, 'apfel', null);      // NULL ⇒ 1.0  → GEWINNER
    ($this->mkGpMapping)($gpDrei->id, 'zimt', '0.900');    // größte id
    $this->makeIngredient($this->r1, 'Drei Kerne', $gpDrei, '100', 1);

    // (b) Gleichstand an der Spitze: NULL und explizite 1.000 sind dasselbe ⇒ kleinere id.
    //     Der diskriminierende Fall — ein `?? 0`-Nachbau wählte hier `birne`.
    $gpGleich = $this->makeGp($this->rootTeam, 'GP Zimt Gleichstand');
    ($this->mkGpMapping)($gpGleich->id, 'apfel', null);    // kleinste id → GEWINNER
    ($this->mkGpMapping)($gpGleich->id, 'birne', '1.000');
    $this->makeIngredient($this->r1, 'Gleichstand', $gpGleich, '100', 2);

    // (c) Gleichstand unterhalb 1.0 ⇒ kleinere id.
    $gpNiedrig = $this->makeGp($this->rootTeam, 'GP Zimt Niedrig');
    ($this->mkGpMapping)($gpNiedrig->id, 'zimt', '0.900'); // kleinste id → GEWINNER
    ($this->mkGpMapping)($gpNiedrig->id, 'apfel', '0.900');
    $this->makeIngredient($this->r1, 'Niedrig', $gpNiedrig, '100', 3);

    // (d) Rolle vor Confidence: `begleiter` mit 1.000 verliert gegen `kern` mit 0.100.
    $gpRolle = $this->makeGp($this->rootTeam, 'GP Zimt Rollen');
    ($this->mkGpMapping)($gpRolle->id, 'apfel', '1.000', 'begleiter');
    ($this->mkGpMapping)($gpRolle->id, 'birne', '0.100');  // → GEWINNER
    $this->makeIngredient($this->r1, 'Rollen', $gpRolle, '100', 4);

    // (e) Soft-Delete vor Confidence: gelöschte 1.000 verliert gegen lebende 0.200.
    $gpWeg = $this->makeGp($this->rootTeam, 'GP Zimt Geloescht');
    ($this->mkGpMapping)($gpWeg->id, 'apfel', '1.000', 'kern', true);
    ($this->mkGpMapping)($gpWeg->id, 'zimt', '0.200');     // → GEWINNER
    $this->makeIngredient($this->r1, 'Geloescht', $gpWeg, '100', 5);

    // ── R2: `neutral` gewinnt / verliert, dann die zwei Fallback-Stufen ───
    $this->r2 = $this->makeRecipe($this->rootTeam, 'Basis: Neutral entscheidet');

    // (f) `neutral` gewinnt die Sortierung ⇒ kern null, via `neutral` — und KEIN
    //     Durchfallen auf `name_match`. „Kein Identitäts-Anker" ist eine Aussage.
    $gpNeutralJa = $this->makeGp($this->rootTeam, 'GP Zimt Neutral Gewinnt');
    ($this->mkGpMapping)($gpNeutralJa->id, 'neutral', null);
    ($this->mkGpMapping)($gpNeutralJa->id, 'apfel', '0.900');
    $this->makeIngredient($this->r2, 'Neutral gewinnt', $gpNeutralJa, '100', 1);

    // (g) `neutral` verliert ⇒ der echte Anker, via `gp_anker`.
    $gpNeutralNein = $this->makeGp($this->rootTeam, 'GP Zimt Neutral Verliert');
    ($this->mkGpMapping)($gpNeutralNein->id, 'neutral', '0.100');
    ($this->mkGpMapping)($gpNeutralNein->id, 'birne', '0.900');
    $this->makeIngredient($this->r2, 'Neutral verliert', $gpNeutralNein, '100', 2);

    // (h) GP ohne Mapping ⇒ Name-Match.
    $gpOhne = $this->makeGp($this->rootTeam, 'GP Zimt Ohne Mapping');
    $this->makeIngredient($this->r2, 'Ohne Mapping', $gpOhne, '100', 3);

    // (i) Nur raw_text, kein Anker-Term ⇒ unresolved (Label = raw_text).
    $this->makeIngredient($this->r2, 'Xylo Quirk', null, '100', 4);

    // ── R3: Sub-Rezepte (eigene Mapping-Tabelle + Prozess-Anker) ─────────
    $this->sub1 = $this->makeRecipe($this->rootTeam, 'Basis: Sub Mehrdeutig');
    ($this->mkRezeptMapping)($this->sub1->id, 'apfel', '0.900'); // kleinste id
    ($this->mkRezeptMapping)($this->sub1->id, 'birne', null);    // NULL ⇒ 1.0 → GEWINNER
    ($this->mkProzessAnker)($this->sub1->id, 'roestaromen');
    ($this->mkProzessAnker)($this->sub1->id, 'rauch');
    ($this->mkProzessAnker)($this->sub1->id, 'birne');           // = kern ⇒ fliegt raus
    ($this->mkProzessAnker)($this->sub1->id, 'zimt', true);      // gelöscht ⇒ fliegt raus

    $this->sub2 = $this->makeRecipe($this->rootTeam, 'Basis: Zimt Sub Ohne Mapping');
    ($this->mkProzessAnker)($this->sub2->id, 'rauch');

    $this->r3 = $this->makeRecipe($this->rootTeam, 'Basis: Sub-Kette');
    ($this->mkSubZutat)($this->r3, $this->sub1, 1);
    ($this->mkSubZutat)($this->r3, $this->sub2, 2);

    // ── R4: Eigen-Zustand am Rezept selbst (angehängter Block + Dedupe) ──
    $this->r4 = $this->makeRecipe($this->rootTeam, 'Basis: Eigen-Zustand');
    $gpApfel = $this->makeGp($this->rootTeam, 'GP Nur Apfel');
    ($this->mkGpMapping)($gpApfel->id, 'apfel', '1.000');
    $this->makeIngredient($this->r4, 'Apfel-Zeile', $gpApfel, '100', 1);
    ($this->mkProzessAnker)($this->r4->id, 'apfel');             // schon kern ⇒ Dedupe
    ($this->mkProzessAnker)($this->r4->id, 'roestaromen');       // → Zustands-Zeile
    ($this->mkProzessAnker)($this->r4->id, 'rauch');             // → Zustands-Zeile
    ($this->mkProzessAnker)($this->r4->id, 'zimt', true);        // gelöscht ⇒ raus

    // Dienst erst NACH den Fixtures holen: `anchorIndex` und der `neutral`-Lookup sind
    // je Instanz memoisiert (V-045-Halbschritt) — eine früher erzeugte Instanz kennte
    // das Vokabular nicht und jede `name_match`-Erwartung wäre still null.
    $this->svc = app(PairingService::class);
});

/**
 * DAS Golden-Set. Jede Zeile ist eine eingefrorene Entscheidung; ändert der Umbau eine
 * davon, ist das per Definition eine stille Verschiebung und muss hier begründet werden.
 */
it('GOLDEN: die Anker-Auflösung liefert für den Fixture-Satz genau diese Zeilen', function () {
    expect(($this->projiziere)($this->svc->resolveRecipeAnchors($this->r1)))->toBe([
        ['label' => 'GP Zimt Drei Kerne', 'kern' => 'apfel', 'prozess' => [], 'via' => 'gp_anker'],
        ['label' => 'GP Zimt Gleichstand', 'kern' => 'apfel', 'prozess' => [], 'via' => 'gp_anker'],
        ['label' => 'GP Zimt Niedrig', 'kern' => 'zimt', 'prozess' => [], 'via' => 'gp_anker'],
        ['label' => 'GP Zimt Rollen', 'kern' => 'birne', 'prozess' => [], 'via' => 'gp_anker'],
        ['label' => 'GP Zimt Geloescht', 'kern' => 'zimt', 'prozess' => [], 'via' => 'gp_anker'],
    ]);

    expect(($this->projiziere)($this->svc->resolveRecipeAnchors($this->r2)))->toBe([
        ['label' => 'GP Zimt Neutral Gewinnt', 'kern' => null, 'prozess' => [], 'via' => 'neutral'],
        ['label' => 'GP Zimt Neutral Verliert', 'kern' => 'birne', 'prozess' => [], 'via' => 'gp_anker'],
        ['label' => 'GP Zimt Ohne Mapping', 'kern' => 'zimt', 'prozess' => [], 'via' => 'name_match'],
        ['label' => 'Xylo Quirk', 'kern' => null, 'prozess' => [], 'via' => 'unresolved'],
    ]);

    expect(($this->projiziere)($this->svc->resolveRecipeAnchors($this->r3)))->toBe([
        ['label' => 'Basis: Sub Mehrdeutig', 'kern' => 'birne', 'prozess' => ['rauch', 'roestaromen'], 'via' => 'recipe_anker'],
        ['label' => 'Basis: Zimt Sub Ohne Mapping', 'kern' => 'zimt', 'prozess' => ['rauch'], 'via' => 'name_match'],
    ]);

    expect(($this->projiziere)($this->svc->resolveRecipeAnchors($this->r4)))->toBe([
        ['label' => 'GP Nur Apfel', 'kern' => 'apfel', 'prozess' => [], 'via' => 'gp_anker'],
        ['label' => 'rauch (Zustand)', 'kern' => 'rauch', 'prozess' => [], 'via' => 'prozess_raw_text'],
        ['label' => 'roestaromen (Zustand)', 'kern' => 'roestaromen', 'prozess' => [], 'via' => 'prozess_raw_text'],
    ]);
});

/**
 * Der Riegel zur Projektions-Freiheit: die Zustands-Zeilen dürfen untereinander in
 * beliebiger Reihenfolge kommen (kein ORDER BY), aber sie müssen ein zusammenhängender
 * Suffix bleiben. Sonst verschöbe die Projektion eine echte Regression aus dem Blick.
 */
it('der Eigen-Zustands-Block bleibt ein zusammenhängender Suffix', function () {
    $vias = array_column($this->svc->resolveRecipeAnchors($this->r4), 'via');
    $ersterZustand = array_search('prozess_raw_text', $vias, true);

    expect($ersterZustand)->not->toBeFalse()
        ->and(array_slice($vias, $ersterZustand))->toBe(array_fill(0, count($vias) - $ersterZustand, 'prozess_raw_text'));
});

/**
 * GEGENBEWEIS — ein Riegel, von dem niemand zeigt, dass er greift, ist keiner (Muster 22·H1/V-012).
 *
 * Zwei plausible Nachbauten der SQL-Sortierung werden hier explizit als FALSCH belegt.
 * Fiele der Fixture-Satz so, dass beide dasselbe Ergebnis liefern wie das Golden-Set,
 * wäre das Golden-Set blind für genau die Fehlerklasse, für die es gebaut wurde.
 */
it('GEGENBEWEIS: die zwei naheliegenden Fehl-Nachbauten liefern ein ANDERES Ergebnis', function () {
    $mappings = fn (string $gpName) => DB::table('foodalchemist_gp_anchor_mappings AS m')
        ->join('foodalchemist_gps AS g', 'g.id', '=', 'm.gp_id')
        ->where('g.name', $gpName)->where('m.role', 'kern')->whereNull('m.deleted_at')
        ->get(['m.id', 'm.anchor_id', 'm.ai_confidence']);

    // Fehl-Nachbau 1: `ai_confidence ?? 0` — NULL wäre damit der SCHLECHTESTE Wert,
    // im SQL ist es über COALESCE(…, 1.0) der beste.
    $naivNullNiedrig = function ($rows) {
        $best = null;
        foreach ($rows as $r) {
            $c = (float) ($r->ai_confidence ?? 0);
            if ($best === null || $c > $best[0]) {
                $best = [$c, (int) $r->anchor_id];
            }
        }

        return $this->ankerSlug[$best[1]];
    };

    // Fehl-Nachbau 2: Confidence ignoriert, nur „erstes Mapping gewinnt" (id/Einfüge-Reihenfolge).
    $naivNurId = fn ($rows) => $this->ankerSlug[(int) collect($rows)->sortBy('id')->first()->anchor_id];

    // Gleichstands-Fall: korrekt ist `apfel` (NULL ⇒ 1.0, kleinere id).
    expect($naivNullNiedrig($mappings('GP Zimt Gleichstand')))->toBe('birne')   // ⇒ falsch
        ->and($naivNurId($mappings('GP Zimt Gleichstand')))->toBe('apfel');     // ⇒ hier zufällig richtig

    // Drei-Kerne-Fall: korrekt ist `apfel`; beide Nachbauten wählen `birne`.
    expect($naivNullNiedrig($mappings('GP Zimt Drei Kerne')))->toBe('birne')
        ->and($naivNurId($mappings('GP Zimt Drei Kerne')))->toBe('birne');
});

/**
 * `anchorsForRecipe` ist der order-tragende Konsument (flache Liste, die in den
 * Kandidaten-Pool geht). Hier wird die MENGE festgenagelt — die Reihenfolge nicht,
 * weil sie aus derselben ORDER-BY-freien Quelle stammt wie `prozess`.
 */
it('die flache Anker-Menge eines Rezepts bleibt dieselbe', function () {
    $slugs = fn (FoodAlchemistRecipe $r) => collect($this->svc->anchorsForRecipe($r))
        ->map(fn ($id) => $this->ankerSlug[$id])->sort()->values()->all();

    expect($slugs($this->r1))->toBe(['apfel', 'birne', 'zimt'])
        ->and($slugs($this->r2))->toBe(['birne', 'zimt'])
        ->and($slugs($this->r3))->toBe(['birne', 'rauch', 'roestaromen', 'zimt'])
        ->and($slugs($this->r4))->toBe(['apfel', 'rauch', 'roestaromen']);
});
