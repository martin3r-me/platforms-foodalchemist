<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Settings\Rollen as SettingsRollen;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 77a · Lücken schließen + Rollen durchsetzen: MCP-Grundprüfung (Hülle um jedes FA-Tool),
 * Status-Setter ab Kuratieren, Rezept-Status nur für eigene Rezepte, „bezahlt" ab Freigeben,
 * Einstellungen nur FA-Admin. Ohne angemeldeten Benutzer (System) keine Prüfung.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->leser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $this->reg = app(ToolRegistry::class);
    $this->draft = fn () => $this->orders->addManualLine($this->rootTeam, $this->la['Butter']->id, 1, null, null, now()->addDays(9)->toDateString())->order()->first();
});

it('MCP-Hülle: Betrachter liest, schreibt aber nicht; Mitglied und KI-Mitglied schreiben; KI ohne Mitgliedschaft nicht', function () {
    $lesen = $this->reg->get('foodalchemist.orders.GET')->execute([], new ToolContext($this->leser, $this->rootTeam));
    expect($lesen->success)->toBeTrue();
    // Brille umschalten ist nur Ansicht — auch für Betrachter (Ausnahme FUER_JEDE_ROLLE)
    expect($this->reg->get('foodalchemist.outlets.SET_ACTIVE')->execute(['outlet_id' => null], new ToolContext($this->leser, $this->rootTeam))->errorCode)->not->toBe('FORBIDDEN');

    $draft = ($this->draft)();
    $schreiben = $this->reg->get('foodalchemist.orders.SET_STATUS')->execute(['order_id' => $draft->id, 'status' => 'cancelled'], new ToolContext($this->leser, $this->rootTeam));
    expect($schreiben->success)->toBeFalse()->and($schreiben->errorCode)->toBe('FORBIDDEN')
        ->and($draft->refresh()->status)->toBe(OrderStatus::Draft);

    $ok = $this->reg->get('foodalchemist.orders.SET_STATUS')->execute(['order_id' => $draft->id, 'status' => 'cancelled'], new ToolContext($this->koch, $this->rootTeam));
    expect($ok->success)->toBeTrue()->and($draft->refresh()->status)->toBe(OrderStatus::Cancelled);

    // Leitstellen-Agent: KI-Benutzer, als Mitglied im Team → Kuratieren → darf schreiben
    $agent = $this->makeUser($this->rootTeam, 'Agent Paul', 'member');
    $agent->forceFill(['type' => 'ai_user'])->save();
    $d2 = ($this->draft)();
    expect($this->reg->get('foodalchemist.orders.SET_STATUS')->execute(['order_id' => $d2->id, 'status' => 'cancelled'], new ToolContext($agent, $this->rootTeam))->success)->toBeTrue();

    // KI-Benutzer ohne Mitgliedschaft → gesperrt (gewollt)
    $fremderAgent = $this->makeUser($this->rootTeam, 'Agent Fremd', null);
    $fremderAgent->forceFill(['type' => 'ai_user'])->save();
    $d3 = ($this->draft)();
    expect($this->reg->get('foodalchemist.orders.SET_STATUS')->execute(['order_id' => $d3->id, 'status' => 'cancelled'], new ToolContext($fremderAgent, $this->rootTeam))->errorCode)->toBe('FORBIDDEN');
});

it('Bestellstatus ab Kuratieren, „bezahlt" ab Freigeben; ohne Benutzer (System) keine Prüfung', function () {
    $this->actingAs($this->leser);
    $d = ($this->draft)();
    expect(fn () => $this->orders->setStatus($this->rootTeam, $d->id, OrderStatus::Cancelled))->toThrow(FaRechtFehltException::class, 'Kuratieren');

    $this->actingAs($this->koch);
    $this->orders->updateInvoiceHeader($this->rootTeam, $this->order1->id, ['invoice_number' => 'R-1', 'invoice_date' => now()->toDateString()]);
    expect(fn () => $this->orders->updatePayment($this->rootTeam, $this->order1->id, ['payment_status' => 'paid']))->toThrow(FaRechtFehltException::class, 'Freigeben');
    $this->orders->updatePayment($this->rootTeam, $this->order1->id, ['payment_status' => 'open']);   // offen setzen bleibt Kuratieren

    $this->rechte->setzeFreigabe($this->rootTeam, $this->inhaber, $this->koch->id, true);
    $this->orders->updatePayment($this->rootTeam, $this->order1->id, ['payment_status' => 'paid']);
    expect($this->order1->refresh()->payment_status)->toBe('paid');

    auth()->logout();
    $this->orders->setStatus($this->rootTeam, $d->id, OrderStatus::Cancelled);
    expect($d->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('Rezept-Status: nur eigene Rezepte (geerbt/Master nicht) und ab Kuratieren', function () {
    $master = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'm-sauce', 'name' => 'Master-Sauce', 'status' => 'draft', 'is_sales_recipe' => false]);
    $kindKoch = $this->makeUser($this->childA, 'Kind Koch', 'member');
    $this->actingAs($kindKoch);
    expect(fn () => app(RecipeService::class)->setStatus($this->childA, $master->id, 'approved'))->toThrow(\RuntimeException::class, 'anderen Team');
    expect($master->refresh()->status->value ?? $master->status)->toBe('draft');

    $this->actingAs($this->leser);
    expect(fn () => app(RecipeService::class)->setStatus($this->rootTeam, $master->id, 'approved'))->toThrow(FaRechtFehltException::class);

    $this->actingAs($this->koch);
    app(RecipeService::class)->setStatus($this->rootTeam, $master->id, 'approved');
    expect($master->refresh()->status->value ?? $master->status)->toBe('approved');
});

it('Einstellungen nur FA-Admin: Mitglied sieht lesend und wird abgewiesen, Inhaber darf', function () {
    $this->actingAs($this->koch);
    expect(fn () => app(TeamSettingsService::class)->update($this->rootTeam, ['purchase_journal_trigger' => 'sent']))->toThrow(FaRechtFehltException::class, 'Admin');

    $vorher = FoodAlchemistKitchenRole::count();
    Livewire::test(SettingsRollen::class)
        ->assertSeeHtml('data-nur-lesen-rolle')
        ->set('neu.name', 'Spüler')
        ->call('create');
    expect(FoodAlchemistKitchenRole::count())->toBe($vorher);

    $this->actingAs($this->inhaber);
    app(TeamSettingsService::class)->update($this->rootTeam, ['purchase_journal_trigger' => 'sent']);
    Livewire::test(SettingsRollen::class)->assertDontSeeHtml('data-nur-lesen-rolle');
    expect(app(TeamSettingsService::class)->purchaseJournalTrigger($this->rootTeam))->toBe('sent');
});
