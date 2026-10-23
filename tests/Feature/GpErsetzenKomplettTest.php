<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP komplett ersetzen und löschen (Dominique 24.10.2026): importierte GPs hängen an Lieferantenartikeln und ließen sich nicht löschen.
 * „Ersetzen durch“ hängt jetzt Artikel (Allergen-Guard je Artikel) UND Rezept-Zeilen um; danach ist Löschen möglich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);

    $this->gp = fn (string $name, bool $global = false) => FoodAlchemistGp::create(array_merge(
        ['team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'k_' . bin2hex(random_bytes(4)), 'status' => 'approved', 'requires_la' => true],
        $global ? ['team_id' => null, 'stamm_class' => 'stamm_paket'] : []));
    $this->la = function (FoodAlchemistGp $gp, string $bez, ?string $milch = 'nicht_enthalten') {
        $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => $bez, 'article_number' => uniqid(), 'qty' => 1, 'unit_code' => 'kg']);
        FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $item->id, 'allergen_milk' => $milch]);
        FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
        $gp->update(['n_las_total' => (int) $gp->n_las_total + 1]);

        return $item;
    };
    $this->aktiv = fn (FoodAlchemistGp $gp) => DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gp->id)->whereNull('deleted_at')->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->all();
});

it('Ersetzen mit Artikeln: Artikel und Rezept-Zeilen wandern aufs Ziel, n_las stimmt, Quelle danach löschbar', function () {
    $von = ($this->gp)('Aalfilet: frisch');
    $nach = ($this->gp)('Zanderfilet: frisch');
    $a1 = ($this->la)($von, 'Aal A');
    $a2 = ($this->la)($von, 'Aal B');
    $rezept = $this->makeRecipe($this->rootTeam, 'Fischsuppe');
    $zeile = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'gp_id' => $von->id,
        'raw_text' => '200 g Aal', 'quantity' => '200', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);

    $res = app(GpService::class)->ersetzeKomplett($this->rootTeam, $von, $nach);

    expect($res['artikel_umgehaengt'])->toBe(2)->and($res['artikel_abgelehnt'])->toBe([])->and($res['zeilen'])->toBe(1)
        ->and(($this->aktiv)($von))->toBe([])
        ->and(collect(($this->aktiv)($nach))->sort()->values()->all())->toBe(collect([$a1->id, $a2->id])->sort()->values()->all())
        ->and((int) $von->fresh()->n_las_total)->toBe(0)->and((int) $nach->fresh()->n_las_total)->toBe(2)
        ->and($nach->fresh()->lead_la_supplier_item_id)->not->toBeNull()
        ->and($von->fresh()->lead_la_supplier_item_id)->toBeNull()
        ->and(FoodAlchemistRecipeIngredient::find($zeile->id)->gp_id)->toBe($nach->id);

    app(GpService::class)->deleteGp($von->fresh());
    expect(FoodAlchemistGp::find($von->id))->toBeNull();
});

it('Allergen-Guard: ein abweichender Artikel bleibt am Quell-GP und wird aufgelistet, Löschen bleibt blockiert', function () {
    $von = ($this->gp)('Aalfilet: frisch');
    $nach = ($this->gp)('Zanderfilet: frisch');
    ($this->la)($nach, 'Zander ohne Milch', 'nicht_enthalten');
    $gut = ($this->la)($von, 'Aal ohne Milch', 'nicht_enthalten');
    $schlecht = ($this->la)($von, 'Aal in Sahnesauce', 'enthalten');

    $res = app(GpService::class)->ersetzeKomplett($this->rootTeam, $von, $nach);

    expect($res['artikel_umgehaengt'])->toBe(1)->and($res['artikel_abgelehnt'])->toHaveCount(1)
        ->and($res['artikel_abgelehnt'][0]['id'])->toBe($schlecht->id)->and($res['artikel_abgelehnt'][0]['bezeichnung'])->toBe('Aal in Sahnesauce')
        ->and(($this->aktiv)($von))->toBe([$schlecht->id])->and(($this->aktiv)($nach))->toContain($gut->id)
        ->and((int) $von->fresh()->n_las_total)->toBe(1);

    expect(fn () => app(GpService::class)->deleteGp($von->fresh()))->toThrow(\RuntimeException::class, 'Lieferantenartikel');
});

it('Nicht-Master darf globale Stamm-GPs weder ersetzen noch löschen (Service, Panel, MCP)', function () {
    $von = ($this->gp)('Aalfilet: frisch', true);
    $nach = ($this->gp)('Zanderfilet: frisch');
    ($this->la)($von, 'Aal A');
    config(['foodalchemist.master_team_id' => $this->rootTeam->id + 999]);
    MasterTeam::flush();

    expect(fn () => app(GpService::class)->ersetzeKomplett($this->rootTeam, $von, $nach))->toThrow(GeerbtNurLesenException::class);
    expect(($this->aktiv)($von))->toHaveCount(1);

    Livewire::test(DetailPanel::class, ['gpId' => $von->id])->call('gpErsetzen', $nach->id)->call('gpLoeschen');
    expect(($this->aktiv)($von))->toHaveCount(1)->and(FoodAlchemistGp::find($von->id))->not->toBeNull();

    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    expect($reg->get('foodalchemist.gps.REPLACE')->execute(['from_id' => $von->id, 'to_id' => $nach->id, 'confirm' => true], $ctx)->success)->toBeFalse();
});

it('Master darf globale Stamm-GPs ersetzen und danach löschen', function () {
    $von = ($this->gp)('Aalfilet: frisch', true);
    $nach = ($this->gp)('Zanderfilet: frisch', true);
    ($this->la)($von, 'Aal A');
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();

    $res = app(GpService::class)->ersetzeKomplett($this->rootTeam, $von, $nach);
    app(GpService::class)->deleteGp($von->fresh());

    expect($res['artikel_umgehaengt'])->toBe(1)->and(($this->aktiv)($nach))->toHaveCount(1)->and(FoodAlchemistGp::ohneTeamSicht('test')->find($von->id))->toBeNull();
});

it('Panel: Ersetzen erscheint auch nur mit Artikeln, Löschen ist gesperrt mit Grund; nach dem Ersetzen aktiv und Löschen klappt', function () {
    $von = ($this->gp)('Aalfilet: frisch');
    $nach = ($this->gp)('Zanderfilet: frisch');
    ($this->la)($von, 'Aal A');

    $c = Livewire::test(DetailPanel::class, ['gpId' => $von->id])->call('bearbeitenStarten')
        ->assertSeeHtml('data-gp-tausch')->assertSeeHtml('data-gp-loeschen-gesperrt')->assertSeeHtml('data-gp-loeschen-grund')
        ->assertDontSeeHtml('data-gp-loeschen-knopf')
        ->set('tauschSuche', 'Zander')->call('gpErsetzen', $nach->id)
        ->assertSet('hinweis', fn ($h) => is_string($h) && str_contains($h, '1 Artikel'))
        ->assertSeeHtml('data-gp-loeschen-knopf')->assertDontSeeHtml('data-gp-loeschen-gesperrt');

    $c->call('gpLoeschen');
    expect(FoodAlchemistGp::find($von->id))->toBeNull()->and(($this->aktiv)($nach))->toHaveCount(1);
});

it('Panel: abgelehnte Artikel werden aufgelistet', function () {
    $von = ($this->gp)('Aalfilet: frisch');
    $nach = ($this->gp)('Zanderfilet: frisch');
    ($this->la)($nach, 'Zander', 'nicht_enthalten');
    ($this->la)($von, 'Aal in Sahnesauce', 'enthalten');

    Livewire::test(DetailPanel::class, ['gpId' => $von->id])->call('bearbeitenStarten')->call('gpErsetzen', $nach->id)
        ->assertSeeHtml('data-abgelehnte-artikel')->assertSee('Aal in Sahnesauce')->assertSeeHtml('data-gp-loeschen-gesperrt');
});

it('MCP gps.REPLACE hängt Artikel mit um und meldet die abgelehnten', function () {
    $von = ($this->gp)('Aalfilet: frisch');
    $nach = ($this->gp)('Zanderfilet: frisch');
    ($this->la)($nach, 'Zander', 'nicht_enthalten');
    ($this->la)($von, 'Aal A', 'nicht_enthalten');
    $schlecht = ($this->la)($von, 'Aal in Sahnesauce', 'enthalten');

    $r = app(ToolRegistry::class)->get('foodalchemist.gps.REPLACE')->execute(['from_id' => $von->id, 'to_id' => $nach->id, 'confirm' => true], new ToolContext($this->user, $this->rootTeam));

    expect($r->success)->toBeTrue()->and($r->data['artikel_umgehaengt'])->toBe(1)->and($r->data['artikel_abgelehnt'][0]['id'])->toBe($schlecht->id);
});
