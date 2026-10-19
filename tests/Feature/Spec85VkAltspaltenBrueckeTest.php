<?php

use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\VkAltspaltenBruecke;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 D-1 PR-C · die Fixture-Brücke ({@see VkAltspaltenBruecke}) und ihre Sperrklinke.
 *
 * Harte Bedingung (Kurator 19.10.): übersetzt wird nur aus `tests/`. Schreibt Produktionscode eine Altspalte, muss der
 * Test ROT werden — sonst wäre er grün und io liefe in einen SQL-Fehler (Spalte fehlt).
 */
const SPEC85_BRUECKE_BASIS_MAX = 166;   // Stand 23.10. — darf nur sinken

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->datei = 'tests/Feature/Spec85VkAltspaltenBrueckeTest.php';
});

/** `AboKuendigungService::kopiereKopf` kopiert ein übergebenes Rezept-Modell samt Attributen und speichert die Kopie (src/). */
function spec85_kopiere_kopf(FoodAlchemistRecipe $fassung, \Platform\Core\Models\Team $mandant): int
{
    $m = new ReflectionMethod(AboKuendigungService::class, 'kopiereKopf');

    return $m->invoke(app(AboKuendigungService::class), $fassung, $mandant, null);
}

it('★ Produktionscode, der eine Altspalte schreibt, wird rot — mit Datei und Zeile aus src/', function () {
    $quelle = $this->makeRecipe($this->rootTeam, 'HG: Quelle', ['is_sales_recipe' => true]);
    $mitAltspalte = FoodAlchemistRecipe::findOrFail($quelle->id);
    $mitAltspalte->setRawAttributes([...$mitAltspalte->getAttributes(), 'sales_net' => 9.0], true);   // wie eine Zeile vor dem Drop

    expect(fn () => spec85_kopiere_kopf($mitAltspalte, $this->childA))
        ->toThrow(LogicException::class, 'Altspalte aus Produktionscode: src/Services/AboKuendigungService.php:');
});

it('Gegenprobe: derselbe Produktionsweg ohne Altspalte legt die Kopie an', function () {
    $quelle = $this->makeRecipe($this->rootTeam, 'HG: Quelle', ['is_sales_recipe' => true]);

    $kopieId = spec85_kopiere_kopf(FoodAlchemistRecipe::findOrFail($quelle->id), $this->childA);

    expect(FoodAlchemistRecipe::query()->ohneTeamSicht('test')->find($kopieId)?->team_id)->toBe($this->childA->id);
});

it('Fixture aus tests/: VK + Klasse landen an einer Standard-Darreichung (fixiert) und werden gezählt', function () {
    $klasse = FoodAlchemistMarkupClass::create(['team_id' => $this->rootTeam->id, 'code' => 'BR', 'label' => 'Brücke', 'raw_markup_pct' => 300]);

    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'br-1', 'name' => 'HG: Brücke', 'status' => 'draft',
        'is_sales_recipe' => true, 'sales_net' => 12.5, 'markup_class_id' => $klasse->id,
    ]);

    $std = FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)->sole();
    expect($std->is_standard)->toBeTrue()
        ->and($std->created_via)->toBe(VkAltspaltenBruecke::HERKUNFT)
        ->and((float) $std->sales_net)->toBe(12.5)
        ->and($std->price_mode)->toBe('fixed')
        ->and((int) $std->markup_class_id)->toBe((int) $klasse->id)
        ->and($r->fresh()->vk_netto)->toBe(12.5)
        ->and(VkAltspaltenBruecke::$treffer[$this->datei] ?? 0)->toBe(1);

    $r->update(['sales_net' => 14.0]);   // Ändern geht in dieselbe Standard-Darreichung
    expect((float) $std->fresh()->sales_net)->toBe(14.0)
        ->and(FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)->count())->toBe(1);
});

it('Fixture legt danach selbst die Standard-Darreichung an: die Brücken-Zeile macht Platz', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'br-2', 'name' => 'HG: Eigene Form', 'status' => 'draft',
        'is_sales_recipe' => true, 'sales_net' => 7.0,
    ]);
    $form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $eigen = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $form->id, 'is_standard' => true,
        'unit_count' => 1, 'price_mode' => 'fixed', 'sales_net' => 8.0, 'created_via' => 'test',
    ]);

    expect(FoodAlchemistRecipeDarreichung::withTrashed()->where('recipe_id', $r->id)->pluck('id')->all())->toBe([$eigen->id])
        ->and($r->fresh()->vk_netto)->toBe(8.0);
});

it('Sperrklinke: eine Testdatei außerhalb des Bestands darf die Brücke nicht nutzen', function () {
    $erlaubt = VkAltspaltenBruecke::erlaubteDateien();
    $ref = new ReflectionProperty(VkAltspaltenBruecke::class, 'erlaubt');
    $ref->setValue(null, array_values(array_diff($erlaubt, [$this->datei])));

    try {
        expect(fn () => FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'br-3', 'name' => 'HG: Neu', 'status' => 'draft', 'sales_net' => 5.0,
        ]))->toThrow(LogicException::class, 'Altspalte in neuer Testdatei: ' . $this->datei);
    } finally {
        $ref->setValue(null, $erlaubt);
    }
});

it('Sperrklinke: der Bestand wächst nicht, und eine Datei ohne Altspalten-Schlüssel muss raus', function () {
    $modul = dirname(__DIR__, 2);
    $liste = VkAltspaltenBruecke::erlaubteDateien();
    $muster = "/'(sales_net|sales_gross|vat_rate|markup_class_id|markup_class_legacy_id)'\\s*=>|->(sales_net|sales_gross|vat_rate|markup_class_id|markup_class_legacy_id)\\s*=[^=>]/";

    $ohneSchluessel = array_values(array_filter($liste, fn (string $f) => ! is_file($modul . '/' . $f)
        || preg_match($muster, (string) file_get_contents($modul . '/' . $f)) !== 1));

    expect(count($liste))->toBeLessThanOrEqual(SPEC85_BRUECKE_BASIS_MAX, 'Neue Datei in VkAltspaltenBrueckeBasis — VK/Klasse an der Darreichung anlegen.')
        ->and(count($liste))->toBe(count(array_unique($liste)))
        ->and($ohneSchluessel)->toBe([], 'Aus VkAltspaltenBrueckeBasis streichen (und SPEC85_BRUECKE_BASIS_MAX senken): ' . implode(', ', $ohneSchluessel));
});
