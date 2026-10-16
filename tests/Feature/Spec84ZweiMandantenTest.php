<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 · B0a · Zwei-Mandanten-Regressionsnetz für die HEUTIGEN Handfilter (vor dem automatischen Filter B3).
 *
 * Aufbau wie auf foodalchemist.io: Master (kuratiert, vergibt Abos) · Mandant A (Hauptteam ohne Eltern, hat ein Abo)
 * · Mandant B (Hauptteam ohne Eltern, kein Abo) · Unter-Team U von A mit ausgeschaltetem „übernimmt alles" (77d-Hülle,
 * nichts freigegeben).
 *
 * Jede Zeile trägt einen Marker im Namen. Ein Marker in der Antwort eines Tools / einer Seite ist ein Leck — egal ob
 * als Treffer, in einer Fehlermeldung oder in einem Vorschlag:
 *   QXAINH  Inhalte von A (Rezepte, Gerichte, Konzepte, Pakete, Formate, Foodbooks, Karten, Pläne, Angebote)
 *   QXASTM  Stammdaten von A (GP, Lieferant, Artikel) — für U sichtbar gewollt (77d: Stammdaten bleiben)
 *   QXAANG  Angebot von A — steht nicht in der 77d-Hülle (InhaltsFreigabe::MODELLE), U sieht es heute; offen, s. todo
 *   QXMABO  Master-Inhalt, den A abonniert hat (A sieht nur die Fassung)
 *   QXMNIX  Master-Inhalt ohne Abo — sieht außer dem Master niemand
 *
 * Gegenprobe (sonst wäre ein grüner Lauf wertlos): Dieselben Aufrufe als Eigentümer MÜSSEN die eigenen Marker finden.
 *
 * Bewusst NICHT hier: der fail-closed-Modus (kommt erst mit B3, Spec 84 §4.2 B0b) und die vier Regeneration/Behälter-
 * Tools (Abweisungs-Tests in Spec84SchreibLeckTest, PR 0).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    Http::fake();
    Mail::fake();
    Queue::fake();

    $this->master = Team::create(['name' => 'Master QXMTEAM', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    $this->u = Team::create(['name' => 'Standort U', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->a);   // Abo setzt ein gebuchtes Stamm-Paket voraus (Spec 82 E1 = C)
    StammPaket::freischalten($this->b);

    $this->kurator = $this->makeUser($this->master, 'Kurator');
    $this->chefA = $this->makeUser($this->a, 'Chef A');
    $this->chefB = $this->makeUser($this->b, 'Chef B');
    $this->chefU = $this->makeUser($this->u, 'Chef U');

    // Ein vollständiger Satz Inhalte + Stammdaten je Team, jeder Name mit Marker.
    $this->satz = function (Team $t, string $inhalt, string $stamm, ?string $angebot = null): array {
        $ids = [];
        $ids['recipe'] = $this->makeRecipe($t, "{$inhalt} Basisfond", ['is_sales_recipe' => false])->id;
        $ids['dish'] = $this->makeRecipe($t, "{$inhalt} Gericht", ['is_sales_recipe' => true])->id;
        $ids['concept'] = app(ConceptService::class)->create($t, ['name' => "{$inhalt} Konzept"])->id;
        $ids['paket'] = app(PaketService::class)->create($t, ['name' => "{$inhalt} Paket"])->id;
        $ids['format'] = app(FormatService::class)->create($t, ['name' => "{$inhalt} Format"])->id;
        $fb = FoodAlchemistFoodbook::create(['team_id' => $t->id, 'label' => "{$inhalt} Foodbook"]);
        $ids['foodbook'] = $fb->id;
        $ids['chapter'] = FoodAlchemistFoodbookKapitel::create(['team_id' => $t->id, 'foodbook_id' => $fb->id, 'title' => "{$inhalt} Kapitel", 'position' => 0])->id;
        $ids['speisekarte'] = FoodAlchemistSpeisekarte::create(['team_id' => $t->id, 'name' => "{$inhalt} Karte", 'status' => 'aktiv'])->id;
        $ids['speiseplan'] = FoodAlchemistSpeiseplan::create(['team_id' => $t->id, 'name' => "{$inhalt} Wochenplan", 'status' => 'draft', 'start_date' => '2026-10-12'])->id;
        $ids['angebot'] = app(AngebotService::class)->create($t, ['name' => ($angebot ?? $inhalt) . ' Angebot', 'personen' => 20])->id;
        $ids['gp'] = FoodAlchemistGp::create(['team_id' => $t->id, 'gp_key' => mb_strtolower($stamm) . '|gp|test', 'name' => "{$stamm} Zanderfilet"])->id;
        $ids['supplier'] = FoodAlchemistSupplier::create(['team_id' => $t->id, 'name' => "{$stamm} Lieferant"])->id;
        $ids['item'] = FoodAlchemistSupplierItem::create(['team_id' => $t->id, 'supplier_id' => $ids['supplier'], 'designation' => "{$stamm} Zanderfilet 1 kg", 'qty' => 1.0, 'unit_code' => 'kg'])->id;
        $ids['team'] = $t->id;

        return $ids;
    };

    // Zeilen-Abdruck aller FA-Tabellen: [tabelle => [rowid => hash]]. Dient dem Schreib-Durchlauf als Ist-Stand und hält
    // fest, welche Zeilen (auch in Kind-Tabellen ohne team_id) beim Anlegen eines Satzes für welches Team entstanden.
    $this->alleTabellen = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'foodalchemist_%'"))->pluck('name')->all();
    $this->zeilen = function (): array {
        $out = [];
        foreach ($this->alleTabellen as $t) {
            foreach (DB::select("SELECT rowid AS __r, * FROM \"{$t}\"") as $row) {
                $row = (array) $row;
                $out[$t][$row['__r']] = md5(json_encode($row));
            }
        }

        return $out;
    };
    $this->neu = function (array $vorher, array $nachher): array {
        $out = [];
        foreach ($nachher as $t => $rows) {
            foreach (array_diff_key($rows, $vorher[$t] ?? []) as $rid => $_) {
                $out[$t][$rid] = true;
            }
        }

        return $out;
    };
    $this->eigene = [];
    $mitAbdruck = function (string $wer, callable $anlegen) {
        $vorher = ($this->zeilen)();
        $erg = $anlegen();
        $this->eigene[$wer] = array_replace_recursive($this->eigene[$wer] ?? [], ($this->neu)($vorher, ($this->zeilen)()));

        return $erg;
    };

    $this->ids = [
        'A' => $mitAbdruck('A', fn () => ($this->satz)($this->a, 'QXAINH', 'QXASTM', 'QXAANG')),
        'MNIX' => $mitAbdruck('M', fn () => ($this->satz)($this->master, 'QXMNIX', 'QXMNIX')),
        'B' => $mitAbdruck('B', fn () => ($this->satz)($this->b, 'EIGENB', 'EIGENB')),
    ];
    // Globaler Bestand (team_id NULL; Rezepte können nicht global sein): sichtbar für alle, pflegen darf ihn nur der Master.
    $this->ids['GLOBAL'] = ['gp' => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'qxglob|gp|test', 'name' => 'QXGLOB Zanderfilet'])->id];
    $this->ids['GLOBAL']['supplier'] = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'QXGLOB Lieferant'])->id;
    $this->ids['GLOBAL']['item'] = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->ids['GLOBAL']['supplier'], 'designation' => 'QXGLOB Zanderfilet 1 kg', 'qty' => 1.0, 'unit_code' => 'kg'])->id;

    // Abo: A abonniert eine Master-Sammlung mit einem Gericht; veröffentlicht als Fassung.
    $abdruckAbo = ($this->zeilen)();
    $abo = $this->makeRecipe($this->master, 'QXMABO Abo-Gericht', ['is_sales_recipe' => true]);
    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'QXMABO Sammlung', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'recipe', [$abo->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->a, $sid, $this->kurator);
    $this->aboArbeit = $abo->id;
    $this->aboFassung = app(FassungService::class)->veroeffentlichen($this->master, $abo->id, 'Erst', $this->kurator)['fassung_id'];

    $this->eigene['M'] = array_replace_recursive($this->eigene['M'], ($this->neu)($abdruckAbo, ($this->zeilen)()));
    $this->eigeneU = $this->makeRecipe($this->u, 'EIGENU Standortfond', ['is_sales_recipe' => false])->id;

    // 77d: U übernimmt nicht alles, nichts freigegeben → sieht nur Eigenes + Stammdaten.
    $inhalte->setzeErbtAlles($this->a, $this->u->id, false, $this->chefA);

    $this->reg = app(ToolRegistry::class);

    // Werkzeug-Kandidaten: nur nach Eigenschaft (read_only), nie nach Namensmuster.
    $this->tools = function (bool $readOnly): array {
        $out = [];
        foreach ($this->reg->all() as $name => $tool) {
            if (! str_starts_with($name, 'foodalchemist.')) {
                continue;
            }
            $ro = method_exists($tool, 'getMetadata') ? (bool) ($tool->getMetadata()['read_only'] ?? false) : false;
            if ($ro === $readOnly) {
                $out[$name] = $tool;
            }
        }
        ksort($out);

        return $out;
    };

    // Welche Art von Zeile meint ein Parameter? Erst der Parametername, dann das Tool-Segment, dann ein Enum-Wert.
    $this->artAus = function (string $text): ?string {
        $t = mb_strtolower($text);
        foreach ([
            'speisekarte' => 'speisekarte', 'speiseplan' => 'speiseplan', 'plan_id' => 'speiseplan',
            'foodbook' => 'foodbook', 'chapter' => 'chapter', 'kapitel' => 'chapter',
            'verkauf' => 'dish', 'sales' => 'dish', 'dish' => 'dish', 'gericht' => 'dish',
            'recipe' => 'recipe', 'rezept' => 'recipe',
            'concept' => 'concept', 'konzept' => 'concept',
            'paket' => 'paket', 'package' => 'paket', 'format' => 'format',
            'angebot' => 'angebot', 'offer' => 'angebot',
            'supplier_item' => 'item', 'artikel' => 'item', 'la_id' => 'item', 'produktpass' => 'item',
            'supplier' => 'supplier', 'lieferant' => 'supplier',
            'gp' => 'gp', 'grundprodukt' => 'gp', 'team' => 'team', 'mandant' => 'team',
        ] as $nadel => $art) {
            if (str_contains($t, $nadel)) {
                return $art;
            }
        }

        return null;
    };

    // Objekt-Parameter (z. B. `felder`): ein leeres Objekt scheitert an der Validierung, bevor ein Riegel greift. Darum
    // die Unterfelder aus dem Schema befüllen, sonst ein paar übliche Textfelder.
    $this->objektFuellen = function (array $unter, array $ziel): array {
        if ($unter === []) {
            return ['name' => 'QXNEU', 'label' => 'QXNEU', 'title' => 'QXNEU', 'note' => 'QXNEU', 'notiz' => 'QXNEU', 'beschreibung' => 'QXNEU'];
        }
        $out = [];
        foreach ($unter as $p => $def) {
            $def = (array) $def;
            $typ = $def['type'] ?? 'string';
            $typ = is_array($typ) ? $typ[0] : $typ;
            $out[$p] = match (true) {
                isset($def['enum']) => $def['enum'][0],
                str_ends_with($p, '_id') => ($ziel[($this->artAus)($p) ?? ''] ?? null),
                $typ === 'integer', $typ === 'number' => 1,
                $typ === 'boolean' => true,
                $typ === 'array' => [],
                default => 'QXNEU',
            };
        }

        return array_filter($out, fn ($v) => $v !== null);
    };

    // Argumente für einen Aufruf: Pflichtfelder + alle *_id-Felder, Ids aus dem Ziel-Satz, Suchtext „QX“.
    $this->argumente = function (string $name, array $schema, array $ziel, ?string $enumWahl = null): array {
        $props = (array) ($schema['properties'] ?? []);
        $req = $schema['required'] ?? [];
        $segment = explode('.', $name)[1] ?? '';
        $args = [];
        $enumArt = null;
        foreach ($props as $p => $def) {
            $def = (array) $def;
            if (isset($def['enum'])) {
                $wert = $enumWahl !== null && in_array($enumWahl, $def['enum'], true) ? $enumWahl : $def['enum'][0];
                if (in_array($p, $req, true) || $enumWahl !== null) {
                    $args[$p] = $wert;
                    $enumArt ??= ($this->artAus)((string) $wert);
                }
            }
        }
        foreach ($props as $p => $def) {
            $def = (array) $def;
            $typ = $def['type'] ?? 'string';
            $typ = is_array($typ) ? $typ[0] : $typ;
            $istId = $p === 'id' || str_ends_with($p, '_id') || str_ends_with($p, '_ids');
            if (array_key_exists($p, $args) || (! $istId && ! in_array($p, $req, true))) {
                continue;
            }
            if ($istId) {
                $art = match (true) {
                    $p === 'id', $p === 'owner_id', $p === 'objekt_id' => $enumArt ?? ($this->artAus)($segment),
                    default => ($this->artAus)($p) ?? ($this->artAus)($segment),
                };
                $wert = $art !== null && isset($ziel[$art]) ? $ziel[$art] : ($ziel['recipe'] ?? 1);
                $args[$p] = str_ends_with($p, '_ids') || $typ === 'array' ? [$wert] : $wert;

                continue;
            }
            $args[$p] = match ($typ) {
                'integer', 'number' => 1,
                'boolean' => true,
                'array' => in_array($p, ['ziele'], true) ? [['typ' => 'recipe', 'id' => $ziel['recipe'] ?? 1]] : ['QX'],
                'object' => ($this->objektFuellen)((array) ($def['properties'] ?? []), $ziel),
                default => match (true) {
                    in_array($p, ['montag', 'von', 'bis', 'datum', 'date'], true) => '2026-10-12',
                    default => 'QX',
                },
            };
        }

        return $args;
    };

    // Ein Aufruf; Antwort, Fehler und Ausnahme werden zu einem Text, in dem nach Markern gesucht wird.
    $this->aufruf = function ($tool, array $args, $user, Team $team): array {
        $this->actingAs($user);
        try {
            $r = $tool->execute($args, new ToolContext($user, $team));
            $text = json_encode(['data' => $r->data, 'error' => $r->error], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';

            return ['ok' => $r->success, 'text' => $text];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => $e->getMessage()];
        }
    };

    // Alle lesenden Tools als $user/$team: einmal ohne Ziel-Ids (Listen/Suchen), dann je Ziel-Satz (GET auf fremde Ids),
    // Enum-Varianten einzeln. Liefert [Leck-Liste, Zahl der erfolgreichen Aufrufe, Treffer-Tools je Marker].
    $this->lesenAls = function ($user, Team $team, array $zielSaetze, array $verbotene, array $sucheMarker = []): array {
        $lecks = [];
        $ok = 0;
        $treffer = [];
        foreach (($this->tools)(true) as $name => $tool) {
            $schema = (array) $tool->getSchema();
            $enums = [null];
            foreach ((array) ($schema['properties'] ?? []) as $def) {
                if (isset(((array) $def)['enum'])) {
                    $enums = array_merge($enums, ((array) $def)['enum']);
                    break;
                }
            }
            foreach ($zielSaetze as $satzName => $ziel) {
                foreach ($enums as $enum) {
                    $args = ($this->argumente)($name, $schema, $ziel, $enum);
                    DB::beginTransaction();
                    try {
                        $res = ($this->aufruf)($tool, $args, $user, $team);
                    } finally {
                        DB::rollBack();
                    }
                    $ok += $res['ok'] ? 1 : 0;
                    foreach ($verbotene as $marker) {
                        if (str_contains($res['text'], $marker)) {
                            $lecks["{$name} [{$satzName}" . ($enum !== null ? "/{$enum}" : '') . "] {$marker}"] = mb_substr($res['text'], max(0, mb_strpos($res['text'], $marker) - 80), 200);
                        }
                    }
                    foreach ($sucheMarker as $marker) {
                        if (str_contains($res['text'], $marker)) {
                            $treffer[$marker][$name] = true;
                        }
                    }
                }
            }
        }

        return [$lecks, $ok, array_map('count', $treffer)];
    };
});

it('Spec 84 B0a · Mandant B (ohne Abo) sieht über kein lesendes MCP-Tool etwas von A oder vom Master', function () {
    [$lecks, $ok] = ($this->lesenAls)($this->chefB, $this->b,
        ['ohne' => [], 'A' => $this->ids['A'], 'MNIX' => $this->ids['MNIX'], 'ABO' => ['dish' => $this->aboFassung, 'recipe' => $this->aboFassung]],
        ['QXAINH', 'QXASTM', 'QXAANG', 'QXMABO', 'QXMNIX']);

    expect($ok)->toBeGreaterThan(50, 'zu wenige erfolgreiche Aufrufe — der Durchlauf prüft sonst nur Fehlermeldungen');
    expect($lecks)->toBe([], 'Lecks als B:' . PHP_EOL . print_r($lecks, true));
});

it('Spec 84 B0a · Mandant A sieht vom Master nur die abonnierte Fassung, nie Inhalte ohne Abo', function () {
    [$lecks] = ($this->lesenAls)($this->chefA, $this->a,
        ['ohne' => [], 'MNIX' => $this->ids['MNIX'], 'ARBEIT' => ['dish' => $this->aboArbeit, 'recipe' => $this->aboArbeit]],
        ['QXMNIX']);

    expect($lecks)->toBe([], 'Lecks als A:' . PHP_EOL . print_r($lecks, true));
});

it('Spec 84 B0a · Unter-Team U mit leerer 77d-Hülle sieht keine Inhalte von A und nichts vom Master', function () {
    [$lecks] = ($this->lesenAls)($this->chefU, $this->u,
        ['ohne' => [], 'A' => $this->ids['A'], 'MNIX' => $this->ids['MNIX']],
        ['QXAINH', 'QXMABO', 'QXMNIX']);

    expect($lecks)->toBe([], 'Lecks als U:' . PHP_EOL . print_r($lecks, true));
});

it('Spec 84 B0a · Gegenprobe: als Eigentümer finden dieselben Aufrufe die eigenen Marker', function () {
    [, $ok, $treffer] = ($this->lesenAls)($this->chefA, $this->a, ['ohne' => [], 'A' => $this->ids['A']], [], ['QXAINH', 'QXASTM']);

    expect($ok)->toBeGreaterThan(50)
        ->and($treffer['QXAINH'] ?? 0)->toBeGreaterThan(20, 'Inhalts-Marker in zu wenigen Tools gefunden — Argument-Bau prüfen')
        ->and($treffer['QXASTM'] ?? 0)->toBeGreaterThan(5, 'Stamm-Marker in zu wenigen Tools gefunden — Argument-Bau prüfen');
});

it('Spec 84 B0a · Gegenprobe 77d: U sieht die Stammdaten von A (gewollt), der Master sieht seine Inhalte', function () {
    [, , $u] = ($this->lesenAls)($this->chefU, $this->u, ['ohne' => [], 'A' => $this->ids['A']], [], ['QXASTM']);
    [, , $m] = ($this->lesenAls)($this->kurator, $this->master, ['ohne' => [], 'MNIX' => $this->ids['MNIX']], [], ['QXMNIX']);

    expect($u['QXASTM'] ?? 0)->toBeGreaterThan(2)
        ->and($m['QXMNIX'] ?? 0)->toBeGreaterThan(20);
});

it('Spec 84 B0a · offen: Unter-Team mit leerer 77d-Hülle sieht die Angebote des Oberteams (angebote.GET/LIST/SEARCH, reife.GET)')
    ->todo(note: 'Angebote stehen nicht in InhaltsFreigabe::MODELLE. Gewollt (Angebot = Betrieb des Oberteams) oder Lücke? Entscheidung Dominique; bis dahin für U nicht als Leck gewertet.');

dataset('spec84_seiten', [
    'Basisrezepte' => [\Platform\FoodAlchemist\Livewire\Recipes\Browser::class, 'QXAINH Basisfond'],
    'Gerichte' => [\Platform\FoodAlchemist\Livewire\Verkauf\Browser::class, 'QXAINH Gericht'],
    'Grundprodukte' => [\Platform\FoodAlchemist\Livewire\Gps\Browser::class, 'QXASTM Zanderfilet'],
    'Formate' => [\Platform\FoodAlchemist\Livewire\Formate\Browser::class, 'QXAINH Format'],
    'Konzepte' => [\Platform\FoodAlchemist\Livewire\Concepts\Index::class, 'QXAINH Konzept'],
    'Pakete' => [\Platform\FoodAlchemist\Livewire\Pakete\Index::class, 'QXAINH Paket'],
    'Foodbooks' => [\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class, 'QXAINH Foodbook'],
    'Speisekarten' => [\Platform\FoodAlchemist\Livewire\Speisekarte\Index::class, 'QXAINH Karte'],
    'Speisepläne' => [\Platform\FoodAlchemist\Livewire\Speiseplan\Index::class, 'QXAINH Wochenplan'],
    'Angebote' => [\Platform\FoodAlchemist\Livewire\Angebote\Index::class, 'QXAANG Angebot'],
    'Lieferanten' => [\Platform\FoodAlchemist\Livewire\Suppliers\Index::class, 'QXASTM Lieferant'],
]);

it('Spec 84 B0a · Haupt-Seiten: A sieht das Eigene, B sieht nichts von A oder vom Master', function (string $seite, string $eigen) {
    $this->actingAs($this->chefA);
    expect(Livewire::test($seite)->html())->toContain($eigen);   // Gegenprobe: die Seite zeigt Zeilen dieser Art überhaupt

    $this->actingAs($this->chefB);
    $html = Livewire::test($seite)->html();
    foreach (['QXAINH', 'QXASTM', 'QXAANG', 'QXMABO', 'QXMNIX'] as $marker) {
        expect($html)->not->toContain($marker, "{$seite} zeigt B den Marker {$marker}");
    }
})->with('spec84_seiten');


// ── Schreiben ─────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Die vier Regeneration/Behälter-Schreibwege deckt Spec84SchreibLeckTest (PR 0) ab — hier bewusst ausgenommen. */
const SPEC84_PR0_TOOLS = [
    'foodalchemist.recipe_regeneration.PUT', 'foodalchemist.recipe_regeneration.DELETE',
    'foodalchemist.recipe_container.PUT', 'foodalchemist.recipe_container.DELETE',
];

/**
 * Gewollte Fremd-Schreibwege (Tool → Grund). Nur mit fachlicher Begründung erweitern.
 */
const SPEC84_ERLAUBT = [
    'foodalchemist.standort_inhalte.PUT' => 'Spec 77d: der Admin des Oberteams schaltet „übernimmt alles" für sein Unter-Team (Zeile gehört dem Unter-Team)',
];

/**
 * Bekannte Lecks (Tool → Befund), am 16.10. mit diesem Test gefunden und an Spec 84 gemeldet. Die Liste darf nur
 * schrumpfen: Ist ein Leck behoben, schlägt der Sperrklinken-Test unten an — dann die Zeile hier löschen.
 */
const SPEC84_BEKANNTE_LECKS = [];   // L1 Brief-Vorlagen behoben (Spec 84 PR 0)

beforeEach(function () {
    // Alle schreibenden Tools als $user/$team gegen fremde Ziel-Sätze. Ein Leck ist:
    //  · eine vorher vorhandene Zeile, die nicht dem Betrachter gehört, wurde geändert oder gelöscht;
    //  · eine neue Zeile mit team_id eines fremden Teams oder (außer beim Master) mit team_id NULL;
    //  · eine neue Zeile ohne team_id, die über eine *_id-Spalte an einer Ziel-Zeile hängt (Kind unter fremdem Eltern).
    $this->schreibenAls = function ($user, Team $team, string $wer, array $zielSaetze): array {
        $basis = ($this->zeilen)();
        $eigen = $this->eigene[$wer] ?? [];
        $istMaster = $team->id === $this->master->id;
        $spalten = [];
        $lecks = [];
        foreach (($this->tools)(false) as $name => $tool) {
            if ((in_array($name, SPEC84_PR0_TOOLS, true) && ! getenv('SPEC84_MIT_PR0')) || isset(SPEC84_ERLAUBT[$name])) {
                continue;
            }
            $schema = (array) $tool->getSchema();
            foreach ($zielSaetze as $satzName => $ziel) {
                $args = ($this->argumente)($name, $schema, $ziel);
                DB::beginTransaction();
                try {
                    $vorZaehler = DB::selectOne('SELECT total_changes() AS c')->c;
                    ($this->aufruf)($tool, $args, $user, $team);
                    $nachher = DB::selectOne('SELECT total_changes() AS c')->c === $vorZaehler ? null : ($this->zeilen)();
                    $neueZeilen = [];
                    if ($nachher !== null) {
                        foreach (($this->neu)($basis, $nachher) as $t => $rids) {
                            foreach (array_keys($rids) as $rid) {
                                $neueZeilen[] = [$t, (array) DB::selectOne("SELECT * FROM \"{$t}\" WHERE rowid = ?", [$rid])];
                            }
                        }
                    }
                } finally {
                    DB::rollBack();
                }
                if ($nachher === null) {
                    continue;
                }
                $befund = [];
                foreach ($basis as $t => $rows) {
                    foreach ($rows as $rid => $hash) {
                        if (($nachher[$t][$rid] ?? null) === $hash || isset($eigen[$t][$rid])) {
                            continue;
                        }
                        $spalten[$t] ??= collect(DB::select("PRAGMA table_info('{$t}')"))->pluck('name')->all();
                        if (in_array('team_id', $spalten[$t], true)) {
                            $tid = DB::table($t)->whereRaw('rowid = ?', [$rid])->value('team_id');
                            if ($tid !== null && (int) $tid === $team->id) {
                                continue;
                            }
                        }
                        $befund[] = (isset($nachher[$t][$rid]) ? 'geändert ' : 'gelöscht ') . "{$t}#{$rid}";
                    }
                }
                foreach ($neueZeilen as [$t, $row]) {
                    if (array_key_exists('team_id', $row)) {
                        if ($row['team_id'] === null ? ! $istMaster : (int) $row['team_id'] !== $team->id) {
                            $befund[] = "neu {$t} team_id=" . var_export($row['team_id'], true);
                        }

                        continue;
                    }
                    foreach ($row as $spalte => $wert) {
                        $art = str_ends_with($spalte, '_id') ? ($this->artAus)($spalte) : null;
                        if ($art !== null && $wert !== null && isset($ziel[$art]) && (int) $wert === (int) $ziel[$art]) {
                            $befund[] = "neu {$t} unter fremdem {$spalte}={$wert}";
                        }
                    }
                }
                if ($befund !== []) {
                    $lecks["{$name} [{$satzName}]"] = implode(', ', array_unique($befund));
                }
            }
        }

        return $lecks;
    };

    // Bekannte Lecks herausnehmen, aber festhalten, welche noch auftreten (Sperrklinke).
    $this->ohneBekannte = function (array $lecks): array {
        $offen = [];
        foreach ($lecks as $schluessel => $befund) {
            $tool = strstr($schluessel, ' ', true);
            if (isset(SPEC84_BEKANNTE_LECKS[$tool])) {
                $this->nochOffen[$tool] = true;

                continue;
            }
            $offen[$schluessel] = $befund;
        }

        return $offen;
    };
    $this->nochOffen = [];
});

it('Spec 84 B0a · Mandant B ändert über kein schreibendes MCP-Tool fremde oder globale Zeilen', function () {
    $lecks = ($this->schreibenAls)($this->chefB, $this->b, 'B',
        ['A' => $this->ids['A'], 'MNIX' => $this->ids['MNIX'], 'GLOBAL' => $this->ids['GLOBAL'],
            'ABO' => ['dish' => $this->aboFassung, 'recipe' => $this->aboFassung]]);
    $lecks = ($this->ohneBekannte)($lecks);

    expect($lecks)->toBe([], 'Schreib-Lecks als B:' . PHP_EOL . print_r($lecks, true));
});

it('Spec 84 B0a · Mandant A ändert weder die Abo-Fassung noch die Master-Arbeitszeile noch globale Zeilen', function () {
    $lecks = ($this->schreibenAls)($this->chefA, $this->a, 'A',
        ['FASSUNG' => ['dish' => $this->aboFassung, 'recipe' => $this->aboFassung], 'ARBEIT' => ['dish' => $this->aboArbeit, 'recipe' => $this->aboArbeit],
            'GLOBAL' => $this->ids['GLOBAL'], 'MNIX' => $this->ids['MNIX']]);
    $lecks = ($this->ohneBekannte)($lecks);

    expect($lecks)->toBe([], 'Schreib-Lecks als A:' . PHP_EOL . print_r($lecks, true));
});

it('Spec 84 B0a · Unter-Team U ändert keine Zeilen des Oberteams A (auch nicht die sichtbaren Stammdaten)', function () {
    $lecks = ($this->ohneBekannte)(($this->schreibenAls)($this->chefU, $this->u, 'U', ['A' => $this->ids['A'], 'GLOBAL' => $this->ids['GLOBAL']]));

    expect($lecks)->toBe([], 'Schreib-Lecks als U:' . PHP_EOL . print_r($lecks, true));
});

it('Spec 84 B0a · Sperrklinke: jedes bekannte Leck tritt noch auf (sonst ist es behoben → aus der Liste löschen)', function () {
    ($this->ohneBekannte)(($this->schreibenAls)($this->chefB, $this->b, 'B', ['GLOBAL' => $this->ids['GLOBAL'], 'MNIX' => $this->ids['MNIX'], 'A' => $this->ids['A']]));

    expect(array_keys(array_diff_key(SPEC84_BEKANNTE_LECKS, $this->nochOffen)))->toBe([], 'behoben — bitte aus SPEC84_BEKANNTE_LECKS löschen');
});

it('Spec 84 B0a · Gegenprobe Schreiben: als Eigentümer ändern die Aufrufe die eigenen Zeilen', function () {
    $basis = ($this->zeilen)();
    $greifen = 0;
    foreach (($this->tools)(false) as $name => $tool) {
        $args = ($this->argumente)($name, (array) $tool->getSchema(), $this->ids['A']);
        DB::beginTransaction();
        try {
            $vor = DB::selectOne('SELECT total_changes() AS c')->c;
            ($this->aufruf)($tool, $args, $this->chefA, $this->a);
            $greifen += DB::selectOne('SELECT total_changes() AS c')->c !== $vor && ($this->zeilen)() !== $basis ? 1 : 0;
        } finally {
            DB::rollBack();
        }
    }

    expect($greifen)->toBeGreaterThan(90,   // gemessen 16.10.: 112 von 413
         'zu wenige Schreib-Tools greifen als Eigentümer — Argument-Bau prüfen');
});

