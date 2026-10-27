<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Jobs\NonFoodKiBatchJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 27.10.2026 (§8.18.3): Non-Food-Unterkategorien tragen die Regelwerk-Nummer („16.1 Reinigung & Hygiene“ …). Die Daten benennt ein
 * eigener Lauf NACH dem Deploy um; bis dahin bilden Lesen und Validieren alte Namen ab (Alias), und `nonfood-einrichten` legt keine Dubletten an.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(NonFoodService::class);
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = fn (string $bez) => FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => $bez, 'qty' => 1, 'unit_code' => 'kg']);
    $this->alt = array_keys(NonFoodRegeln::ALIAS);
});

it('Unterkategorien tragen die Nummer 16.1 bis 16.7 in Regelwerk-Reihenfolge', function () {
    expect(NonFoodRegeln::unterkategorien())->toBe([
        '16.1 Reinigung & Hygiene', '16.2 Einweg & Verpackung', '16.3 Küchenbedarf & Kleingeräte', '16.4 Arbeitskleidung & Schutz',
        '16.5 Deko & Tischkultur', '16.6 Geschirr & Ausstattung', '16.7 Büro & Sonstiges',
    ]);
});

it('Alias: jeder alte Name bildet auf genau den neuen ab; neue und unbekannte Werte bleiben, mitAlias liefert neu + alt', function () {
    foreach (NonFoodRegeln::unterkategorien() as $neu) {
        $alt = preg_replace('/^16\.\d /', '', $neu);
        expect(NonFoodRegeln::kanonisch($alt))->toBe($neu)->and(NonFoodRegeln::kanonisch($neu))->toBe($neu)
            ->and(NonFoodRegeln::mitAlias($neu))->toBe([$neu, $alt]);
    }
    expect(NonFoodRegeln::kanonisch('Irgendwas'))->toBe('Irgendwas')->and(NonFoodRegeln::kanonisch(null))->toBeNull();
});

it('Übergang: gespeicherter alter Name liest sich als neuer; von Hand gesetzter alter Name wird als neuer gespeichert statt verworfen', function () {
    $la = ($this->la)('Serviette 2-lagig');
    $z = $this->svc->setzeManuell($la, true, 'Deko & Tischkultur');
    expect(DB::table('foodalchemist_supplier_item_nonfood')->where('id', $z->id)->value('subkategorie'))->toBe(NonFoodRegeln::SUB_DEKO);

    DB::table('foodalchemist_supplier_item_nonfood')->where('id', $z->id)->update(['subkategorie' => 'Deko & Tischkultur']);   // Datenstand vor dem Lauf
    expect(FoodAlchemistSupplierItemNonfood::find($z->id)->subkategorie)->toBe(NonFoodRegeln::SUB_DEKO);

    expect(fn () => $this->svc->setzeManuell($la, true, 'Gibt es nicht'))->toThrow(RuntimeException::class);
});

it('KI-Stapel: antwortet die KI mit dem alten Namen, wird der neue gespeichert', function () {
    $a = ($this->la)('Becher 180 ml');
    $this->svc->regelLauf(null, true);
    $this->mock(AiGatewayService::class)->shouldReceive('propose')->once()
        ->andReturn(new AiProposal(['urteile' => [['id' => $a->id, 'nonfood' => true, 'unterkategorie' => 'Einweg & Verpackung', 'konfidenz' => 0.95]]], 0.9));

    app()->call([new NonFoodKiBatchJob([$a->id]), 'handle']);

    expect(DB::table('foodalchemist_supplier_item_nonfood')->where('supplier_item_id', $a->id)->value('subkategorie'))->toBe(NonFoodRegeln::SUB_EINWEG);
});

it('nonfood-einrichten: frisch legt die nummerierten Namen an; stehen die alten noch da, keine Dublette, sondern „umzubenennen“; idempotent', function () {
    $zeilen = fn () => FoodAlchemistWarengruppeSubkategorie::query()->whereNull('team_id')->where('commodity_group_code', NonFoodRegeln::WG_CODE)->pluck('name')->sort()->values()->all();

    // Bestand vor dem Datenlauf: alte Namen
    foreach ($this->alt as $i => $name) {
        FoodAlchemistWarengruppeSubkategorie::create(['team_id' => null, 'commodity_group_code' => NonFoodRegeln::WG_CODE, 'name' => $name, 'position' => $i + 1]);
    }
    $r = $this->svc->einrichten(true);
    expect($r['unterkategorien_angelegt'])->toBe([])->and($r['umzubenennen'])->toBe(array_combine($this->alt, array_map(fn ($a) => NonFoodRegeln::ALIAS[$a], $this->alt)))
        ->and($zeilen())->toHaveCount(7);
    $this->artisan('foodalchemist:nonfood-einrichten', ['--apply' => true])->expectsOutputToContain('Umbenennung auf „16.1 Reinigung & Hygiene“')->assertSuccessful();

    // nach dem Datenlauf: nur neue Namen, zweiter Lauf ändert nichts
    FoodAlchemistWarengruppeSubkategorie::query()->whereNull('team_id')->where('commodity_group_code', NonFoodRegeln::WG_CODE)->get()
        ->each(fn ($z) => $z->update(['name' => NonFoodRegeln::kanonisch($z->name)]));
    $r = $this->svc->einrichten(true);
    expect($r['unterkategorien_angelegt'])->toBe([])->and($r['umzubenennen'])->toBe([])->and($zeilen())->toBe(collect(NonFoodRegeln::unterkategorien())->sort()->values()->all());

    // frische Datenbank: legt die sieben neuen an
    FoodAlchemistWarengruppeSubkategorie::query()->whereNull('team_id')->where('commodity_group_code', NonFoodRegeln::WG_CODE)->forceDelete();
    expect($this->svc->einrichten(true)['unterkategorien_angelegt'])->toBe(NonFoodRegeln::unterkategorien())->and($zeilen())->toHaveCount(7);
});
