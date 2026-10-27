<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Suppliers\MarkeOffen;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Marke offen, Bedienung (Dominique 27.10.2026, io): Fehler und Erfolg stehen im aufgeklappten Formular der Zeile, die Knöpfe sind ohne Marke gesperrt,
 * Standards: gilt für diesen Lieferanten, Händler → Eigenmarke, sonst Herstellermarke. Nach dem Zuordnen bleibt die Zeile mit Meldung stehen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => false, 'foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower((string) $this->admin->email)]]);
    $this->mitglied = $this->makeUser($this->rootTeam, 'Koch Mitglied', 'member');   // im Master-Team, aber kein Admin
    $this->mandant = $this->makeUser($this->childA, 'Mandant Inhaber');               // anderes Team
    $this->cc = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Chefs Culinar West Weeze', 'rolle' => 'haendler']);
    $this->hh = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Handelshof Mönchengladbach', 'rolle' => 'haendler']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->hh->id, 'kuerzel' => 'heng', 'marke' => 'Henglein', 'typ' => 'herstellermarke', 'position' => 'ende']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => 'kn', 'marke' => 'Knorr', 'typ' => 'herstellermarke']);
    app(MarkeAusArtikel::class)->vergessen();
    $this->nr = 0;
    $this->gp = fn (string $name) => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mn-' . (++$this->nr) . '||', 'name' => $name,
        'main_ingredient_display' => trim(strstr($name . ':', ':', true)), 'condition' => 'TK', 'commodity_group_code' => '13', 'rezepturware' => true, 'marke_offen' => true]);
    $this->la = function (FoodAlchemistSupplier $s, string $bez, ?FoodAlchemistGp $gp = null) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $s->id, 'designation' => $bez, 'article_number' => 'MN' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);
        if ($gp !== null) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        }

        return $la;
    };
    $this->tool = fn (string $name, array $args, $user) => app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($user, $this->rootTeam));
});

it('leere Marke: Knöpfe gesperrt, und ein Zuordnen ohne Marke meldet den Fehler im Formular', function () {
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', ($this->gp)('Torte Apfel-Pecan: TK'));
    $this->actingAs($this->admin);
    $c = Livewire::test(MarkeOffen::class)->call('oeffnen', $this->cc->id . '|anfang|b&b');

    // Attribut prüfen, nicht Text: die Klasse „disabled:opacity-50“ steht an jedem Knopf (Gegenprobe 27.10.)
    $knopf = function (string $html, string $marker): ?DOMElement {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        foreach ($dom->getElementsByTagName('button') as $b) {
            if ($b->hasAttribute($marker)) {
                return $b;
            }
        }

        return null;
    };
    expect($knopf($c->html(), 'data-kuerzel-zuordnen')?->hasAttribute('disabled'))->toBeTrue()
        ->and($c->html())->toContain('placeholder="Markenname wie auf dem Artikel"');
    expect($knopf($c->set('form.marke', 'Bake & Bite')->html(), 'data-kuerzel-zuordnen')?->hasAttribute('disabled'))->toBeFalse();   // mit Marke frei
    $c->set('form.marke', '');
    $c->call('zuordnen')->assertSeeHtml('data-form-fehler')->assertSee('Bitte die Marke eintragen');
});

it('Erfolg: Meldung im Formular, die Zeile bleibt als „zugeordnet“ stehen, obwohl die Gruppe aus der Liste fällt', function () {
    $gp = ($this->gp)('Torte Apfel-Pecan: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $gp);
    $this->actingAs($this->admin);

    Livewire::test(MarkeOffen::class)->call('oeffnen', $this->cc->id . '|anfang|b&b')->set('form.marke', 'Bake & Bite')
        ->assertDontSeeHtml('data-form-fehler')
        ->call('zuordnen')->assertSet('fehler', null)
        ->assertSeeHtml('data-form-meldung')->assertSeeHtml('data-gruppe-erledigt')->assertSeeHtml('data-kuerzel-fertig');
    expect($gp->fresh()->name)->toBe('Torte Apfel-Pecan: TK / (Bake & Bite)');
});

it('Standards: gilt für diesen Lieferanten; Händler → Eigenmarke, Hersteller → Herstellermarke', function () {
    $hersteller = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Bäckerei Heisters', 'rolle' => 'hersteller']);
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', ($this->gp)('Torte Apfel-Pecan: TK'));
    ($this->la)($hersteller, 'XQZ Brötchen Mohn 60g', ($this->gp)('Brötchen Mohn: frisch'));
    $this->actingAs($this->admin);

    Livewire::test(MarkeOffen::class)->call('oeffnen', $this->cc->id . '|anfang|b&b')
        ->assertSet('form.typ', 'eigenmarke')->assertSet('form.geltung', 'lieferant');
    Livewire::test(MarkeOffen::class)->call('oeffnen', $hersteller->id . '|anfang|xqz')
        ->assertSet('form.typ', 'herstellermarke')->assertSet('form.geltung', 'lieferant');
});
