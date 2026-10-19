<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 (vor D-1 PR-C, Kurator 19.10.): `DarreichungService::anlegen`/`aktualisieren` übernahmen Fremdschlüssel roh —
 * ein MCP-Client konnte eine fremde Aufschlagsklasse (z. B. die des Masters) an seine Darreichung hängen. Der Preis war
 * über das catalogPrice-Tor geschützt, das Etikett leckte, die Daten waren falsch. Jetzt: Sichtbarkeit wie
 * `TeamScope::referenz` für Klasse, Einheit, Behälter, Vehikel und (beim Anlegen) Servierform.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $this->put = fn (int $id, array $attrs) => app(ToolRegistry::class)->get('foodalchemist.recipe_darreichung.PUT')
        ->execute(['presentation_id' => $id, 'attrs' => $attrs], new ToolContext($this->user, $this->childA));

    $this->ak = fn (Team $t, string $code) => FoodAlchemistMarkupClass::create(['team_id' => $t->id, 'code' => $code, 'label' => "AK {$code}", 'raw_markup_pct' => 300]);
    $this->form = fn (Team $t, string $code) => FoodAlchemistServierform::create(['team_id' => $t->id, 'code' => $code, 'label' => $code]);

    $gericht = $this->makeRecipe($this->childA, 'HG: Eigenes Gericht', ['is_sales_recipe' => true]);
    $this->darreichung = app(DarreichungService::class)->anlegen($this->childA, $gericht->id, ($this->form)($this->childA, 'teller')->id, [], 'test');
});

it('MCP: eine fremde Aufschlagsklasse wird abgewiesen, die Darreichung bleibt unverändert', function () {
    $fremd = ($this->ak)($this->childB, 'FREMD');

    $res = ($this->put)($this->darreichung->id, ['markup_class_id' => $fremd->id]);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('Aufschlagsklasse')
        ->and($this->darreichung->fresh()->markup_class_id)->toBeNull();
});

it('Gegenprobe MCP: eigene und vom Oberteam geerbte Aufschlagsklasse werden gesetzt', function () {
    $eigen = ($this->ak)($this->childA, 'EIGEN');
    $geerbt = ($this->ak)($this->rootTeam, 'ERBE');

    expect(($this->put)($this->darreichung->id, ['markup_class_id' => $eigen->id])->success)->toBeTrue()
        ->and((int) $this->darreichung->fresh()->markup_class_id)->toBe((int) $eigen->id)
        ->and(($this->put)($this->darreichung->id, ['markup_class_id' => $geerbt->id])->success)->toBeTrue()
        ->and((int) $this->darreichung->fresh()->markup_class_id)->toBe((int) $geerbt->id);
});

it('MCP: fremder Behälter und fremdes Servier-Vehikel werden abgewiesen, eigene gehen', function () {
    $behaelter = fn (Team $t, string $slug) => DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $t->id, 'slug' => $slug, 'name' => $slug, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $vehikel = fn (Team $t, string $slug) => DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $t->id, 'slug' => $slug, 'name' => $slug, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['container_warm_vocab_id' => $behaelter, 'container_cold_vocab_id' => $behaelter, 'serving_vehicle_vocab_id' => $vehikel] as $feld => $mk) {
        $res = ($this->put)($this->darreichung->id, [$feld => $mk($this->childB, "fremd_{$feld}")]);
        expect($res->success)->toBeFalse($feld)->and($this->darreichung->fresh()->{$feld})->toBeNull($feld);

        $eigen = $mk($this->childA, "eigen_{$feld}");
        expect(($this->put)($this->darreichung->id, [$feld => $eigen])->success)->toBeTrue($feld)
            ->and((int) $this->darreichung->fresh()->{$feld})->toBe((int) $eigen, $feld);
    }
});

it('Bestandsschutz: ein unveränderter, inzwischen nicht mehr sichtbarer Wert blockiert das Speichern anderer Felder nicht', function () {
    $fremd = ($this->ak)($this->childB, 'ALT');
    $this->darreichung->forceFill(['markup_class_id' => $fremd->id])->saveQuietly();   // Altbestand vor dieser Prüfung

    $res = ($this->put)($this->darreichung->id, ['markup_class_id' => $fremd->id, 'note' => 'nur die Notiz']);

    expect($res->success)->toBeTrue()
        ->and($this->darreichung->fresh()->note)->toBe('nur die Notiz');
});

it('anlegen: fremde Servierform und fremde Aufschlagsklasse werden abgewiesen', function () {
    $gericht = $this->makeRecipe($this->childA, 'HG: Zweites Gericht', ['is_sales_recipe' => true]);
    $svc = app(DarreichungService::class);

    expect(fn () => $svc->anlegen($this->childA, $gericht->id, ($this->form)($this->childB, 'buffet')->id, [], 'test'))
        ->toThrow(RuntimeException::class, 'Servierform');
    expect(fn () => $svc->anlegen($this->childA, $gericht->id, ($this->form)($this->childA, 'flying')->id,
        ['markup_class_id' => ($this->ak)($this->childB, 'FREMD2')->id], 'test'))
        ->toThrow(RuntimeException::class, 'Aufschlagsklasse');
});
