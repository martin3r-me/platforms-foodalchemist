<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Support\SignalCockpit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S0 — Fundament der Rezept-Inhalts-Qualität.
 *
 * Prüft das Gerüst, auf dem Tranche A (S1) aufsetzt: die Enum-Typen, die neue
 * Ebene `rezeptqualitaet` im DataQualityService, den fixen Schweregrad je Check
 * und die betroffene()/countFor()-Auflösung. Jeder Check braucht Positiv- UND
 * Negativfall — ein Check, der sauber gebaute Rezepte mitflaggt, macht das
 * Cockpit schlechter statt besser.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
});

/** Metrik über alle Ebenen hinweg per key finden. */
function rqMetrik(array $ebenen, string $key): array
{
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
    }
    throw new RuntimeException("Metrik {$key} nicht gefunden");
}

it('hat für jeden Signal-Typ Label und Icon (Enum-Vollständigkeit)', function () {
    foreach (SignalTyp::cases() as $typ) {
        expect($typ->label())->not->toBe('')
            ->and($typ->icon())->toStartWith('heroicon-o-');
    }

    // Tranche A ist vollständig angelegt (S1 füllt nur noch die Checks).
    $rezept = array_filter(SignalTyp::cases(), fn (SignalTyp $t) => $t->istRezeptQualitaet());
    expect($rezept)->toHaveCount(11)
        ->and(SignalTyp::EkKetteUnvollstaendig->istRezeptQualitaet())->toBeFalse();
});

it('führt die Ebene Rezept-Qualität getrennt von den Kaskaden-Ebenen', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect($e)->toHaveKey('rezeptqualitaet')
        ->and($e['rezeptqualitaet']['label'])->toBe('Rezept-Qualität')
        ->and($e['rezeptqualitaet']['metriken'])->not->toBeEmpty();
});

it('flaggt Rezepte mit ungemappten Zutaten — und saubere nicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');

    // sauber: alle Zutaten gemappt
    $ok = $this->makeRecipe($this->rootTeam, 'Sauber');
    $this->makeIngredient($ok, 'Zander', $gp);

    // auffällig: eine Zutat ohne GP-Mapping
    $kaputt = $this->makeRecipe($this->rootTeam, 'Ungemappt', ['n_ingredients_unmapped' => 1]);
    $this->makeIngredient($kaputt, 'Irgendwas Unbekanntes', null);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_zutaten_ungemappt');

    expect($m['wert'])->toBe(1)                    // nur das kaputte, nicht das saubere
        ->and($m['severity'])->toBe('gelb')
        ->and($m['signal']['typ'])->toBe(SignalTyp::RezeptZutatenUngemappt)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Warnung);   // fixer Schweregrad statt Mengen-Heuristik
});

it('meldet grün, wenn kein Rezept ungemappte Zutaten hat', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $ok = $this->makeRecipe($this->rootTeam, 'Ganz sauber');
    $this->makeIngredient($ok, 'Lachs', $gp);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_zutaten_ungemappt');

    expect($m['wert'])->toBe(0)->and($m['severity'])->toBe('gruen');
});

it('leakt Rezepte fremder Teams nicht in die Messung', function () {
    $this->makeRecipe($this->childA, 'Fremd', ['n_ingredients_unmapped' => 3]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->childB), 'rezept_zutaten_ungemappt');

    expect($m['wert'])->toBe(0);
});

it('löst die betroffenen Rezepte auf (betroffene/countFor teilen dieselbe Query)', function () {
    $this->makeRecipe($this->rootTeam, 'Alpha', ['n_ingredients_unmapped' => 2]);
    $this->makeRecipe($this->rootTeam, 'Beta', ['n_ingredients_unmapped' => 1]);
    $this->makeRecipe($this->rootTeam, 'Gamma');   // sauber → darf nicht auftauchen

    $items = $this->dq->betroffene($this->rootTeam, 'rezept_zutaten_ungemappt');

    expect($items)->toHaveCount(2)
        ->and(array_column($items, 'name'))->toBe(['Alpha', 'Beta'])
        ->and($items[0]['kind'])->toBe('recipe')
        ->and($this->dq->countFor($this->rootTeam, 'rezept_zutaten_ungemappt'))->toBe(2);
});

// ── S1 · Tranche A: die deterministischen Struktur-Checks ────────────────────
//
// Jeder Check bekommt Positiv- UND Negativfall. Der Negativfall ist hier der
// wichtigere: das Fixture-Standardrezept ist bewusst sauber, es DARF von keinem
// Check angefasst werden. Ein Check, der es mitflaggt, würde das Cockpit fluten.

it('flaggt produktive Rezepte ohne Zubereitung — und lässt Stubs/Entwürfe aus', function () {
    $this->makeRecipe($this->rootTeam, 'Sauber dokumentiert');                                    // langer Text → ok
    $this->makeRecipe($this->rootTeam, 'Leer', ['preparation' => null]);                           // ✗
    $this->makeRecipe($this->rootTeam, 'Zu kurz', ['preparation' => 'Kochen.']);                   // ✗ < 20 Zeichen
    // Auto-Stub und Entwurf haben per Definition noch keine Zubereitung → kein Befund
    // (sonst doppelt sich der Check mit `rezept_sub_stub_offen`).
    $this->makeRecipe($this->rootTeam, 'Stub', ['status' => 'stub', 'preparation' => null]);
    $this->makeRecipe($this->rootTeam, 'Entwurf', ['status' => 'draft', 'preparation' => null]);
    $this->makeRecipe($this->rootTeam, 'Ausgemustert', ['status' => 'deprecated', 'preparation' => null]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_ohne_zubereitung');

    expect($m['wert'])->toBe(2)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Kritisch);   // ohne Zubereitung nicht produzierbar
});

it('flaggt Rezepte mit einer Zutat auf Menge 0 — und vollständig bemengte nicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Butter');

    $ok = $this->makeRecipe($this->rootTeam, 'Vollständig bemengt');
    $this->makeIngredient($ok, 'Butter', $gp, '250');

    $luecke = $this->makeRecipe($this->rootTeam, 'Menge fehlt');
    $this->makeIngredient($luecke, 'Butter', $gp, '250', 1);
    $this->makeIngredient($luecke, 'Salz', $gp, '0', 2);            // 0 = Marker „nicht bekannt"

    // gelöschte Zutaten zählen nicht mit
    $gelöscht = $this->makeRecipe($this->rootTeam, 'Alt-Zutat gelöscht');
    $this->makeIngredient($gelöscht, 'Salz', $gp, '0')->delete();

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_mengen_luecke');

    expect($m['wert'])->toBe(1)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Kritisch)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_mengen_luecke'), 'name'))->toBe(['Menge fehlt']);
});

it('flaggt Rezepte mit höchstens einer Zutat — und normale nicht', function () {
    $this->makeRecipe($this->rootTeam, 'Zwei Zutaten');                                            // Default = 2
    $this->makeRecipe($this->rootTeam, 'Eine Zutat', ['n_ingredients_total' => 1]);                 // ✗
    $this->makeRecipe($this->rootTeam, 'Keine Zutat', ['n_ingredients_total' => 0]);                // ✗
    $this->makeRecipe($this->rootTeam, 'Stub ohne Zutat', ['status' => 'stub', 'n_ingredients_total' => 0]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_ein_zutat');

    expect($m['wert'])->toBe(2)->and($m['signal']['sev'])->toBe(SignalSeverity::Warnung);
});

it('flaggt fehlende und stillgelegte Kategorien — je Rezept-Art auf dem richtigen Taxonomie-Weg', function () {
    $stillgelegt = $this->makeMainGroup($this->rootTeam, 'ALC', true);

    $this->makeRecipe($this->rootTeam, 'Basis ok');                                                 // ok
    $this->makeRecipe($this->rootTeam, 'VK ok', ['is_sales_recipe' => true]);                       // ok
    $this->makeRecipe($this->rootTeam, 'Basis ohne Kategorie', ['category_id' => null]);            // ✗
    $this->makeRecipe($this->rootTeam, 'VK ohne HG', ['is_sales_recipe' => true, 'dish_main_group_id' => null]);   // ✗
    $this->makeRecipe($this->rootTeam, 'VK stillgelegt', ['is_sales_recipe' => true, 'dish_main_group_id' => $stillgelegt->id]);  // ✗
    // Gegenproben: beim VK-Gericht entscheidet allein die Speisen-Hauptgruppe (fehlende
    // Produktions-Kategorie ist dort kein Befund) — und umgekehrt beim Basisrezept.
    $this->makeRecipe($this->rootTeam, 'VK ohne Produktions-Kategorie', ['is_sales_recipe' => true, 'category_id' => null]);
    $this->makeRecipe($this->rootTeam, 'Basis mit stillgelegter Speisen-HG', ['dish_main_group_id' => $stillgelegt->id]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_kategorie_problem');

    expect($m['wert'])->toBe(3)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_kategorie_problem'), 'name'))
        ->toBe(['Basis ohne Kategorie', 'VK ohne HG', 'VK stillgelegt']);
});

it('flaggt verwaiste Rezepte — Nutzung auf einer Fläche genügt zum Freispruch', function () {
    $alt = fn (string $name) => tap($this->makeRecipe($this->rootTeam, $name), fn ($r) => DB::table('foodalchemist_recipes')
        ->where('id', $r->id)->update(['updated_at' => now()->subDays(400)]));

    $verwaist = $alt('Verwaist');                                    // ✗ alt + nirgends referenziert
    $subRezept = $alt('Als Sub-Rezept genutzt');                     // ok — hängt in einem anderen Rezept
    $inFoodbook = $alt('In Foodbook');                               // ok
    $this->makeRecipe($this->rootTeam, 'Frisch angefasst');          // ok — updated_at = jetzt

    $eltern = $this->makeRecipe($this->rootTeam, 'Eltern-Rezept');
    $this->makeIngredient($eltern, 'Sub', null)->update(['referenced_recipe_id' => $subRezept->id]);

    $foodbook = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Testbuch']);
    $kapitel = FoodAlchemistFoodbookKapitel::create([
        'team_id' => $this->rootTeam->id, 'foodbook_id' => $foodbook->id, 'title' => 'Vorspeisen',
    ]);
    FoodAlchemistFoodbookBlock::create([
        'team_id' => $this->rootTeam->id, 'chapter_id' => $kapitel->id, 'position' => 1,
        'type' => 'gericht', 'sales_recipe_id' => $inFoodbook->id,
    ]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_verwaist');

    expect($m['wert'])->toBe(1)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Info)       // Bestand ist kein Fehler
        ->and($m['severity'])->toBe('info')                          // und wird deshalb nie rot/gelb
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_verwaist'), 'id'))->toBe([$verwaist->id]);
});

// ── S1b · Tranche A: die Checks mit eigener Mechanik ─────────────────────────

it('flaggt fehlende Ausbeute und Ausbeute über der Einsatzmasse — nicht aber Zähl-Einheiten', function () {
    $gp = $this->makeGp($this->rootTeam, 'Kalbsfond');

    // ok: Fixture führt die Ausbeute mit der eingewogenen Masse mit
    $ok = $this->makeRecipe($this->rootTeam, 'Kohärent');
    $this->makeIngredient($ok, 'Kalbsknochen', $gp, '800');

    // ✗ Ausbeute fehlt trotz Zutaten
    $this->makeRecipe($this->rootTeam, 'Ohne Ausbeute', ['yield_kg' => null, 'yield_kg_manual' => null]);

    // ✗ 5 kg Ausbeute aus 100 g Einsatz — Verluste können Masse nur senken
    $zuHoch = $this->makeRecipe($this->rootTeam, 'Ausbeute zu hoch', ['yield_kg' => 5.0]);
    $this->makeIngredient($zuHoch, 'Knochen', $gp, '100');

    // Die Falle: identisches Zahlenbild, aber die Zutat steht in einer Zähl-Einheit ohne
    // Gramm-Default → die Einsatzmasse ist unbekannt, das Verhältnis darf NICHT bewertet werden.
    $stk = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'bund', 'display_de' => 'Bund', 'dimension' => 'count',
    ]);
    $zaehl = $this->makeRecipe($this->rootTeam, 'Zähl-Einheit', ['yield_kg' => 5.0]);
    $this->makeIngredient($zaehl, 'Petersilie', $gp, '2')->update(['unit_vocab_id' => $stk->id]);

    // Ausbeute-lose Entwürfe/Stubs bleiben außen vor (kein Über-Flaggen)
    $this->makeRecipe($this->rootTeam, 'Stub ohne Ausbeute', ['status' => 'stub', 'yield_kg' => null]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_yield_implausibel');

    expect($m['wert'])->toBe(2)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Warnung)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_yield_implausibel'), 'name'))
        ->toBe(['Ausbeute zu hoch', 'Ohne Ausbeute']);
});

it('flaggt Naming-Verstöße — Grammatur nur ohne unterscheidende Funktion', function () {
    $this->makeRecipe($this->rootTeam, 'Sauber benannt');                              // ok
    $this->makeRecipe($this->rootTeam, 'Doppelt  Leerzeichen');                        // ✗
    $this->makeRecipe($this->rootTeam, 'Trenner am Ende |');                           // ✗
    $this->makeRecipe($this->rootTeam, ' Führendes Leerzeichen');                      // ✗

    // VK-Gerichte: [HG]-Präfix + Pipe-Skelett (Regelwerk_Verkaufsgerichte §1.1)
    $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet | Portwein-Jus | Selleriepüree', ['is_sales_recipe' => true]);   // ok
    $this->makeRecipe($this->rootTeam, 'Rinderfilet | Portwein-Jus', ['is_sales_recipe' => true]);                        // ✗ kein Präfix
    $this->makeRecipe($this->rootTeam, '[DES] Panna Cotta | Mango (BOX)', ['is_sales_recipe' => true]);                   // ✗ Katalog-Marker

    // Grammatur: als Diskriminator zulässig (§1.2a) → das Paar bleibt sauber …
    $this->makeRecipe($this->rootTeam, 'Törtchen (17g)');
    $this->makeRecipe($this->rootTeam, 'Törtchen (65g)');
    // … alleinstehend ist sie ein Steckbrief-Wert und gehört ins Datenfeld
    $this->makeRecipe($this->rootTeam, 'Brownie (65g)');                               // ✗

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_naming_regelwerk');

    expect($m['wert'])->toBe(6)
        // Reihenfolge bewusst offen: `orderBy('name')` sortiert Klammern/führende Leerzeichen
        // je Kollation unterschiedlich (SQLite binär, MySQL unicode_ci) — geprüft wird die Menge.
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_naming_regelwerk'), 'name'))
        ->toEqualCanonicalizing([' Führendes Leerzeichen', '[DES] Panna Cotta | Mango (BOX)', 'Brownie (65g)',
            'Doppelt  Leerzeichen', 'Rinderfilet | Portwein-Jus', 'Trenner am Ende |']);
});

it('flaggt Namens-Dubletten je Rezept-Art und Kategorie — beide Seiten', function () {
    $andereKat = $this->makeRecipeCategory($this->rootTeam, 'FIX-KAT2');

    // Dublette: Normalisierung ignoriert Groß-/Kleinschreibung und Trennzeichen
    $a = $this->makeRecipe($this->rootTeam, 'Tomaten Sauce');
    $b = $this->makeRecipe($this->rootTeam, 'tomaten-sauce');

    // kein Befund: andere Kategorie ist laut Regelwerk §1 ein zulässiger Diskriminator
    $this->makeRecipe($this->rootTeam, 'Tomaten Sauce', ['category_id' => $andereKat->id]);
    // kein Befund: gleicher Name, andere Rezept-Art (Komponente vs. verkauftes Gericht)
    $this->makeRecipe($this->rootTeam, '[HG] Tomaten Sauce', ['is_sales_recipe' => true]);
    $this->makeRecipe($this->rootTeam, 'Einzelstück');

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_dublette');

    expect($m['wert'])->toBe(2)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_dublette'), 'id'))
        ->toEqualCanonicalizing([$a->id, $b->id]);
});

it('flaggt unbelastbare Allergene nur bei kundenexponierten Rezepten', function () {
    $unbekannt = ['allergens_confidence' => 'unknown'];

    // ✗ steht in einem Foodbook und die Allergen-Auskunft ist unbelastbar
    $imBuch = $this->makeRecipe($this->rootTeam, 'Im Foodbook', $unbekannt + ['is_sales_recipe' => true]);
    // ok: exponiert, aber aggregiert
    $sauber = $this->makeRecipe($this->rootTeam, 'Sauber aggregiert', ['is_sales_recipe' => true]);
    // ok: unbelastbar, aber erreicht keinen Gast → Pflege, keine Haftung
    $this->makeRecipe($this->rootTeam, 'Nur im Regal', $unbekannt);
    // ✗ Sub-Rezept eines exponierten Gerichts erbt die Exposition (eine Ebene)
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce im Gericht', $unbekannt);
    // ✗ einzelnes Allergen auf `unbekannt` genügt (Konfidenz sagt hier nichts)
    $einzeln = $this->makeRecipe($this->rootTeam, 'Ein Allergen offen',
        ['is_sales_recipe' => true, 'allergen_sesame' => 'unbekannt']);

    $this->makeIngredient($imBuch, 'Sauce', null)->update(['referenced_recipe_id' => $sauce->id]);

    $foodbook = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Buch']);
    $kapitel = FoodAlchemistFoodbookKapitel::create([
        'team_id' => $this->rootTeam->id, 'foodbook_id' => $foodbook->id, 'title' => 'Hauptgänge',
    ]);
    foreach ([$imBuch, $sauber, $einzeln] as $i => $gericht) {
        FoodAlchemistFoodbookBlock::create([
            'team_id' => $this->rootTeam->id, 'chapter_id' => $kapitel->id, 'position' => $i + 1,
            'type' => 'gericht', 'sales_recipe_id' => $gericht->id,
        ]);
    }

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_allergen_unbelastbar');

    expect($m['wert'])->toBe(3)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Kritisch)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_allergen_unbelastbar'), 'name'))
        ->toBe(['Ein Allergen offen', 'Im Foodbook', 'Sauce im Gericht']);
});

it('flaggt referenzierte Sub-Rezept-Stubs ohne Inhalt', function () {
    $leer = $this->makeRecipe($this->rootTeam, 'Leerer Stub', ['status' => 'stub', 'n_ingredients_total' => 0]);
    $gefuellt = $this->makeRecipe($this->rootTeam, 'Stub mit Inhalt', ['status' => 'stub']);   // Default 2 Zutaten
    // nicht referenziert → niemand wartet darauf
    $this->makeRecipe($this->rootTeam, 'Freier Stub', ['status' => 'stub', 'n_ingredients_total' => 0]);

    $eltern = $this->makeRecipe($this->rootTeam, 'Eltern');
    $this->makeIngredient($eltern, 'Leer', null)->update(['referenced_recipe_id' => $leer->id]);
    $this->makeIngredient($eltern, 'Gefüllt', null, '100', 2)->update(['referenced_recipe_id' => $gefuellt->id]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_sub_stub_offen');

    expect($m['wert'])->toBe(1)
        ->and(array_column($this->dq->betroffene($this->rootTeam, 'rezept_sub_stub_offen'), 'id'))->toBe([$leer->id]);
});

it('führt jeden Tranche-A-Typ als Metrik mit Signal-Deskriptor (Register vollständig)', function () {
    $metriken = collect($this->dq->messeAlleEbenen($this->rootTeam)['rezeptqualitaet']['metriken']);

    // Jeder gebaute Check hat Typ, dedup_key und fixen Schweregrad — ohne das emittiert er nichts.
    $metriken->each(function (array $m) {
        expect($m['signal'])->not->toBeNull()
            ->and($m['signal']['typ']->istRezeptQualitaet())->toBeTrue()
            ->and($m['signal']['dedup'])->toStartWith('dq-rezept-')
            ->and($m['signal']['sev'])->toBeInstanceOf(SignalSeverity::class)
            ->and($m['signal']['desc'])->not->toBeEmpty();
    });

    // dedup_keys eindeutig — zwei Checks mit demselben Key würden sich gegenseitig überschreiben.
    expect($metriken->pluck('signal.dedup')->duplicates())->toBeEmpty();

    // Jede Metrik ist über queryFor auflösbar (betroffene/countFor teilen das Prädikat der Zähl-Seite).
    $metriken->each(fn (array $m) => expect($this->dq->countFor($this->rootTeam, $m['key']))->toBe($m['wert']));
});

it('kennt für jeden Assist-Plan einen registrierten Prompt-Key (über alle Signal-Typen)', function () {
    $registry = config('foodalchemist.prompts', []);
    $geprueft = 0;

    // Ein Assist-Mapping mit Prompt-Key, den die Registry nicht kennt, fällt heute erst
    // beim Klick auf („Unbekannter Prompt-Key" aus propose()) — hier fällt es zur Testzeit.
    foreach (SignalTyp::cases() as $typ) {
        $sig = new FoodAlchemistSignal();
        $sig->type = $typ->value;
        $sig->payload = ['metrik' => $typ->value, 'anzahl' => 1];
        $plan = SignalCockpit::planFor($sig);
        if (($plan['kind'] ?? null) !== 'assist') {
            continue;
        }
        expect($registry[$plan['prompt']]['task'] ?? null)->not->toBeNull("Prompt fehlt: {$plan['prompt']}");
        $geprueft++;
    }

    expect($geprueft)->toBeGreaterThan(0);   // Schutz gegen eine Schleife, die nie zuschlägt
});

it('emittiert das Rezept-Qualitätssignal idempotent in die Inbox', function () {
    $this->makeRecipe($this->rootTeam, 'Delta', ['n_ingredients_unmapped' => 1]);

    $this->dq->emittiereSignale($this->rootTeam);
    $this->dq->emittiereSignale($this->rootTeam);   // zweiter Lauf: aktualisiert statt dupliziert

    $sig = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::RezeptZutatenUngemappt->value)->get();

    expect($sig)->toHaveCount(1)
        ->and($sig->first()->severity)->toBe(SignalSeverity::Warnung)
        ->and($sig->first()->payload['metrik'])->toBe('rezept_zutaten_ungemappt');
});
