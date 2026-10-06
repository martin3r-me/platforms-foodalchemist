<?php

use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Livewire\Settings\Einkauf;
use Platform\FoodAlchemist\Mail\BestellungMail;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderMail;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\OrderMailService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 63 · Bestellversand per Mail. Fixture: Kuchen (10 Portionen) = 1000 g Mehl von „Chefs".
 * Bei 100 Portionen entsteht eine Draft-Bestellung bei Chefs.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(OrderService::class);
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs', 'email_order' => 'einkauf@chefs.test']);
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->chefs->id,
        'designation' => 'Mehl 1kg', 'article_number' => 'ART-MEH', 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 2.00, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    $kuchen = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.50, 'sales_unit_count' => 10,
    ]);
    $kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $g->id]);
    app(RecipeRecomputeService::class)->recomputePipeline($kuchen->id);

    $this->svc->addNeedFromTarget($this->rootTeam, ['recipe_id' => $kuchen->id, 'portions' => 100], 'recipe:kuchen@100');
    $this->order = FoodAlchemistOrder::where('supplier_id', $this->chefs->id)->firstOrFail();

    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->serverVersand = fn (array $extra = []) => app(TeamSettingsService::class)->update($this->rootTeam, ['bestellversand' => 'server'] + $extra);
});

it('Standard „Mailprogramm": Absenden verschickt nichts und protokolliert nichts (bisheriges Verhalten)', function () {
    Mail::fake();
    $this->svc->setStatus($this->rootTeam, $this->order->id, OrderStatus::Sent);

    Mail::assertNothingSent();
    expect(FoodAlchemistOrderMail::count())->toBe(0);
});

it('„Direkt per E-Mail": Absenden schickt die Bestellung mit PDF an den Lieferanten und protokolliert', function () {
    Mail::fake();
    ($this->serverVersand)(['bestellversand_kopie_an' => 'kueche@team.test', 'bestellversand_signatur' => 'Gruß, die Küche']);

    $this->svc->setStatus($this->rootTeam, $this->order->id, OrderStatus::Sent);

    Mail::assertSent(BestellungMail::class, function (BestellungMail $m) {
        return $m->hasTo('einkauf@chefs.test')
            && $m->hasBcc('kueche@team.test')
            && str_contains($m->betreff, 'Bestellung Chefs')
            && str_contains($m->text, 'Mehl 1kg')
            && str_contains($m->text, 'Gruß, die Küche')
            && $m->pdf !== null && str_starts_with($m->pdf, '%PDF');
    });
    $log = FoodAlchemistOrderMail::sole();
    expect($log->typ)->toBe('bestellung')
        ->and($log->status)->toBe('versendet')
        ->and($log->antwort_an)->toBe($this->user->email)
        ->and($log->versendet_am)->not->toBeNull();
});

it('„Direkt per E-Mail" ohne Bestell-E-Mail beim Lieferanten: Absenden wird verweigert, Status bleibt Entwurf', function () {
    Mail::fake();
    ($this->serverVersand)();
    $this->chefs->update(['email_order' => null]);

    expect(fn () => $this->svc->setStatus($this->rootTeam, $this->order->id, OrderStatus::Sent))
        ->toThrow(RuntimeException::class, 'Bestell-E-Mail');
    expect($this->order->refresh()->status)->toBe(OrderStatus::Draft);
    Mail::assertNothingSent();
});

it('Storno einer versendeten Bestellung schickt eine Storno-Mail (ohne PDF)', function () {
    Mail::fake();
    ($this->serverVersand)();
    $this->svc->setStatus($this->rootTeam, $this->order->id, OrderStatus::Sent);
    $this->svc->setStatus($this->rootTeam, $this->order->id, OrderStatus::Cancelled);

    Mail::assertSent(BestellungMail::class, 2);
    Mail::assertSent(BestellungMail::class, fn (BestellungMail $m) => str_starts_with($m->betreff, 'Stornierung') && $m->pdf === null);
    expect(FoodAlchemistOrderMail::where('typ', 'storno')->value('status'))->toBe('versendet');
});

it('Vorlage mit Platzhaltern ersetzt Betreff und Text', function () {
    Mail::fake();
    ($this->serverVersand)([
        'bestellversand_betreff_bestellung' => 'Auftrag {team} an {lieferant}',
        'bestellversand_text_bestellung' => "Hallo {lieferant},\n{positionen}\nSumme {summe}\n{besteller}",
    ]);
    $this->svc->setStatus($this->rootTeam, $this->order->id, OrderStatus::Sent);

    Mail::assertSent(BestellungMail::class, function (BestellungMail $m) {
        return $m->betreff === 'Auftrag '.$this->rootTeam->name.' an Chefs'
            && str_starts_with($m->text, "Hallo Chefs,\n- ")
            && str_contains($m->text, '€')
            && str_contains($m->text, $this->user->name)
            && ! str_contains($m->text, '{');
    });
});

it('Fehler beim Versand wird protokolliert und lässt sich erneut senden', function () {
    // echter Mailer (array), ungültige Adresse ⇒ Symfony wirft beim Versand
    config(['mail.default' => 'array']);
    ($this->serverVersand)();
    $dienst = app(OrderMailService::class);
    $mail = FoodAlchemistOrderMail::create([
        'team_id' => $this->rootTeam->id, 'order_id' => $this->order->id, 'typ' => 'bestellung',
        'an' => 'keine gültige adresse', 'betreff' => 'Test', 'status' => 'geplant',
    ]);

    expect(fn () => $dienst->senden($mail->id))->toThrow(InvalidArgumentException::class);
    $mail->refresh();
    expect($mail->status)->toBe('fehlgeschlagen')->and($mail->fehler)->not->toBeEmpty()->and($mail->versuche)->toBe(1);

    $mail->update(['an' => 'einkauf@chefs.test']);
    $dienst->erneutSenden($this->rootTeam, $mail->id); // Queue = sync im Test
    expect($mail->refresh()->status)->toBe('versendet')->and($mail->versuche)->toBe(2);
});

it('Einstellungen: Versandart und Vorlage speichern, ungültige Adressen abgelehnt', function () {
    Livewire::test(Einkauf::class)
        ->set('versand.art', 'server')
        ->set('versand.kopie_an', 'kein-mail')
        ->call('bestellversandSpeichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'kein-mail'))
        ->set('versand.kopie_an', 'a@b.test, c@d.test')
        ->call('vorlageStandardEinsetzen', 'bestellung')
        ->call('bestellversandSpeichern')
        ->assertSet('fehler', null);

    $s = app(TeamSettingsService::class)->for($this->rootTeam);
    expect($s->bestellversand)->toBe('server')
        ->and($s->bestellversand_kopie_an)->toBe('a@b.test, c@d.test')
        ->and($s->bestellversand_text_bestellung)->toContain('{positionen}');
});
