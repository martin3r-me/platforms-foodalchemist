<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Settings\Mandanten as MandantenKomponente;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamKontingent;
use Platform\FoodAlchemist\Services\Ai\KiDeckelService;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MandantenGetTool;
use Platform\FoodAlchemist\Tools\MandantenPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Abrechnung PR 2 (Dominique 23.10.2026): der Master-Admin setzt den KI-Monatsdeckel je Mandant in der Mandanten-Verwaltung
 * und per MCP — Betrag, Standard (leer) oder „unbegrenzt“. Ohne Mandanten-Zeile bleibt der Status unverändert (sonst würde ein
 * Projekt-gedeckeltes Team still „aktiv“).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null, 'foodalchemist.ai.projekt_deckel_eur' => 20]);
    if (! \Illuminate\Support\Facades\Schema::hasTable('team_billable_usages')) {   // Core-Tabelle, die das Stammblatt (Gebühren) liest — wie MandantenStammblattTest
        \Illuminate\Support\Facades\Schema::create('team_billable_usages', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->string('billable_model');
            $t->string('billable_type');
            $t->string('label');
            $t->date('usage_date');
            $t->unsignedInteger('count')->default(0);
            $t->decimal('cost_per_unit', 8, 4);
            $t->decimal('total_cost', 10, 2);
            $t->json('pricing_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['team_id', 'billable_model', 'usage_date']);
        });
    }
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->kunde = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->masterAdmin = $this->makeUser($this->master, 'Master Admin', 'admin');
    $this->kundeInhaber = $this->makeUser($this->kunde, 'Soul Inhaber', 'owner');
    app()->instance(PlattformProtokoll::class, Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing());
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    $this->svc = app(MandantenService::class);
});

it('Master-Admin setzt Betrag, dann unbegrenzt, dann Standard; die Darstellung trägt Deckel, Quelle und Verbrauch', function () {
    $m = $this->svc->setze($this->kunde, ['ki_deckel_eur' => '35,50'], $this->masterAdmin);
    expect($m['ki_deckel_eur'])->toBe(35.5)->and($m['ki_deckel_quelle'])->toBe('kontingent')->and($m['ki_verbrauch_eur_monat'])->toBe(0.0);

    $m = $this->svc->setze($this->kunde, ['ki_deckel_unbegrenzt' => true], $this->masterAdmin);
    expect($m['ki_deckel_eur'])->toBeNull()->and($m['ki_deckel_quelle'])->toBe('unbegrenzt')->and($m['ki_deckel_unbegrenzt'])->toBeTrue();

    $m = $this->svc->setze($this->kunde, ['ki_deckel_eur' => '', 'ki_deckel_unbegrenzt' => false], $this->masterAdmin);
    expect($m['ki_deckel_eur'])->toBe(20.0)->and($m['ki_deckel_quelle'])->toBe('projekt_standard');
});

it('Nur der Deckel: keine Mandanten-Zeile entsteht (Team bleibt ohne Status = Projekt-Deckel)', function () {
    $this->svc->setze($this->kunde, ['ki_deckel_eur' => 50], $this->masterAdmin);

    expect(FoodAlchemistMandant::where('team_id', $this->kunde->id)->exists())->toBeFalse()
        ->and((float) FoodAlchemistTeamKontingent::where('team_id', $this->kunde->id)->value('ki_budget_eur_monat'))->toBe(50.0);
});

it('Ungültiger Betrag und fremde Rollen werden abgewiesen', function () {
    expect(fn () => $this->svc->setze($this->kunde, ['ki_deckel_eur' => '-5'], $this->masterAdmin))->toThrow(RuntimeException::class, 'Betrag ≥ 0');
    expect(fn () => $this->svc->setze($this->kunde, ['ki_deckel_eur' => 'viel'], $this->masterAdmin))->toThrow(RuntimeException::class);
    expect(fn () => $this->svc->setze($this->kunde, ['ki_deckel_eur' => 100], $this->kundeInhaber))->toThrow(FaRechtFehltException::class);
    expect(FoodAlchemistTeamKontingent::where('team_id', $this->kunde->id)->exists())->toBeFalse();
});

it('MCP: mandanten.PUT setzt den Deckel, mandanten.GET liefert ihn', function () {
    $ctx = new ToolContext($this->masterAdmin, $this->master);
    $put = app(MandantenPutTool::class)->execute(['team_id' => $this->kunde->id, 'ki_deckel_eur' => 12, 'status' => 'projekt'], $ctx);
    expect($put->success)->toBeTrue();

    $get = app(MandantenGetTool::class)->execute(['team_id' => $this->kunde->id], $ctx);
    expect($get->success)->toBeTrue()->and($get->data['mandant']['ki_deckel_eur'])->toBe(12.0)->and($get->data['mandant']['ki_deckel_quelle'])->toBe('kontingent')
        ->and($get->data['mandant']['status'])->toBe('projekt');
    expect(app(KiDeckelService::class)->deckel($this->kunde->id)['eur'])->toBe(12.0);
});

it('UI: Stammblatt öffnen, Deckel eintragen, speichern; Tabelle zeigt Verbrauch / Deckel', function () {
    $this->actingAs($this->masterAdmin);
    Livewire::test(MandantenKomponente::class)
        ->assertSeeHtml('data-mandant-ki="'.$this->kunde->id.'"')
        ->assertSeeHtml('data-mandant-ohne-status')->assertSee('ohne Status (Projekt-Deckel)')   // ohne Mandanten-Zeile nicht „aktiv“ (Dominique 23.10.)
        ->call('auf', $this->kunde->id)
        ->assertSeeHtml('data-mandant-ki-deckel="'.$this->kunde->id.'"')
        ->set('kiDeckel.'.$this->kunde->id, '7,5')
        ->call('kiDeckelSpeichern', $this->kunde->id)
        ->assertSet('kiFehler', null)->assertSet('hinweis', 'KI-Deckel gespeichert.');

    expect(app(KiDeckelService::class)->deckel($this->kunde->id))->toBe(['eur' => 7.5, 'quelle' => 'kontingent']);
});
