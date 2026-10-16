<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Support\GeschirrRueckbau;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rückbau der Geschirr-DB: der Riegel der Migration prüft GELESENE Daten (Zeilen und Spaltenwerte), nicht nur ob die Tabelle existiert. Er bricht mit klarer Meldung
 * ab, solange die alte Welt Daten trägt; `foodalchemist:geschirr-typ-uebernehmen --apply --leeren` übernimmt den Typ und räumt ab, danach läuft der Drop.
 * (Auf SQLite — der Testbasis — bleiben Spalten und leere Tabellen stehen; die Prüfung und das Abräumen sind dort dieselben wie auf MySQL.)
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Einkauf'));
    $this->migration = fn () => require __DIR__.'/../../database/migrations/2026_11_08_100000_drop_geschirr_db.php';
    $this->artikel = fn (?int $typ = null) => DB::table('foodalchemist_tableware_items')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'label' => 'Suppenteller', 'unit' => 'Stk', 'vehicle_vocab_id' => $typ, 'is_inactive' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->typ = DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => 'teller', 'name' => 'Teller', 'sort_order' => 1, 'is_inactive' => false, 'created_at' => now(), 'updated_at' => now()]);
});

it('Riegel: ohne Daten in der alten Welt ist nichts offen, die Migration läuft durch', function () {
    expect(GeschirrRueckbau::offeneDaten())->toBe([]);

    GeschirrRueckbau::pruefeOderAbbrechen();
    ($this->migration)()->up();                                                   // wirft nicht

    expect(true)->toBeTrue();
});

it('Riegel greift bei vorhandenen Artikeln: Abbruch mit Zahl und Hinweis aufs Kommando, nichts wird gelöscht', function () {
    ($this->artikel)();

    $offen = GeschirrRueckbau::offeneDaten();
    expect($offen)->toBe(['Geschirr-Artikel' => 1]);
    expect(fn () => ($this->migration)()->up())->toThrow(\RuntimeException::class, 'Geschirr-Artikel: 1');
    expect(fn () => GeschirrRueckbau::pruefeOderAbbrechen())->toThrow(\RuntimeException::class, 'geschirr-typ-uebernehmen --apply --leeren');
    expect(DB::table('foodalchemist_tableware_items')->count())->toBe(1);        // nichts verloren
});

it('Riegel prüft die gelesenen WERTE, nicht nur die Tabelle: ein Slot mit Geschirr-Zeiger hält den Rückbau auf, auch wenn die Artikel-Tabelle leer ist', function () {
    expect(DB::table('foodalchemist_tableware_items')->count())->toBe(0);
    $concepts = app(ConceptService::class);
    $konzept = $concepts->create($this->rootTeam, ['name' => 'Buffet']);
    $slot = $concepts->addSlot($this->rootTeam, $konzept->id, ['role' => 'Vorspeise']);
    DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->update(['tableware_item_id' => 4711]);

    expect(GeschirrRueckbau::offeneDaten())->toBe(['Konzept-Slots mit Haupt-Geschirr' => 1]);
    expect(fn () => ($this->migration)()->up())->toThrow(\RuntimeException::class, 'Konzept-Slots mit Haupt-Geschirr: 1');

    // gelöschte Zeilen zählen nicht (gewollt weg), ein Wert in einer sichtbaren Zeile schon
    DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->update(['deleted_at' => now()]);
    expect(GeschirrRueckbau::offeneDaten())->toBe([]);
});

it('--leeren übernimmt den Typ, räumt die alten Zuordnungen und Tabellen ab, danach läuft der Rückbau; ohne --apply nichts, --leeren allein ist ein Fehler', function () {
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $gericht = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rb', 'name' => 'Suppe', 'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0]);
    $dar = app(DarreichungService::class)->ensureStandard($this->rootTeam, $gericht->id);
    $artikel = ($this->artikel)($this->typ);
    $dar->update(['tableware_item_id' => $artikel]);

    $this->artisan('foodalchemist:geschirr-typ-uebernehmen', ['--leeren' => true])->assertFailed();             // ohne --apply
    $this->artisan('foodalchemist:geschirr-typ-uebernehmen')->assertSuccessful();                              // Dry-Run schreibt nichts
    expect($dar->fresh()->serving_vehicle_vocab_id)->toBeNull()->and(GeschirrRueckbau::offeneDaten())->not->toBe([]);
    expect(fn () => ($this->migration)()->up())->toThrow(\RuntimeException::class);

    $this->artisan('foodalchemist:geschirr-typ-uebernehmen', ['--apply' => true, '--leeren' => true])->assertSuccessful();

    $dar->refresh();
    expect($dar->serving_vehicle_vocab_id)->toBe($this->typ)                                                   // Typ übernommen
        ->and($dar->tableware_item_id)->toBeNull()                                                              // Zuordnung abgeräumt
        ->and(DB::table('foodalchemist_tableware_items')->count())->toBe(0)
        ->and(GeschirrRueckbau::offeneDaten())->toBe([]);
    ($this->migration)()->up();                                                                                 // der Riegel lässt jetzt durch
    expect(true)->toBeTrue();
});

it('Weg: Route, Navigationspunkt, MCP-Tools und Modelle der alten Welt gibt es nicht mehr', function () {
    expect(\Illuminate\Support\Facades\Route::has('foodalchemist.geschirr.index'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('foodalchemist.geschirr.dokument'))->toBeFalse()
        ;
    foreach (['Models/FoodAlchemistGeschirrItem.php', 'Models/FoodAlchemistGeschirrSupplier.php', 'Services/GeschirrService.php', 'Livewire/Geschirr/Index.php',
        'Tools/GeschirrItemsListTool.php', 'Tools/ConceptSlotsGeschirrTool.php', 'Tools/PaketGerichteGeschirrTool.php'] as $datei) {
        expect(file_exists(__DIR__.'/../../src/'.$datei))->toBeFalse($datei);          // Quelltext ist weg (kein class_exists: der Autoloader des Hosts kennt noch den alten Classmap-Eintrag)
    }
    expect(file_exists(__DIR__.'/../../resources/views/livewire/geschirr/index.blade.php'))->toBeFalse();
    $nav = json_encode(config('foodalchemist.navigation', config('foodalchemist')));
    expect($nav)->not->toContain('foodalchemist.geschirr.index');

    $registry = app(\Platform\Core\Tools\ToolRegistry::class);
    foreach (['concept_slots.GESCHIRR', 'paket_gerichte.GESCHIRR', 'geschirr_items.LIST', 'geschirr_items.POST', 'geschirr_items.PUT', 'geschirr_items.DEACTIVATE',
        'geschirr_suppliers.LIST', 'geschirr_suppliers.POST', 'geschirr_suppliers.PUT', 'geschirr_suppliers.DEACTIVATE'] as $tool) {
        expect($registry->get("foodalchemist.{$tool}"))->toBeNull($tool);
    }
});
