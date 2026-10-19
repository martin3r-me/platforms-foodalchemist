<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Suppliers\ProduktpassPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Websuche\WebSucheTransport;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Produktpass „Im Web recherchieren“ (Kurator 19.10., Befund io): synchron starb der Request am FPM-Timeout (60 s), eine Nicht-JSON-Antwort
 * endete als 500. Jetzt: Job im Hintergrund mit Status am Produktpass (liest → gelesen | fehler), jede Ausnahme wird zur lesbaren Meldung;
 * Abschalter FOODALCHEMIST_WEBSUCHE_AKTIV. Sync-Queue im Test ⇒ der Job läuft inline.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id, 'foodalchemist.ai.websuche_aktiv' => true]);   // Standard ist aus (Dominique 19.10.)
    $this->kurator = $this->makeUser($this->rootTeam, 'Kurator M', 'member');
    $lief = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Altoni', 'stamm_class' => StammPaket::PAKET]);
    $this->item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $lief->id, 'designation' => "Bigoli all'uovo 1 kg", 'stamm_class' => StammPaket::PAKET]);
    $this->transport = function (callable $antwort) {
        $this->app->bind(WebSucheTransport::class, fn () => new class($antwort) implements WebSucheTransport {
            public function __construct(private $antwort) {}

            public function suche(string $anweisung, array $kontext): array
            {
                return ($this->antwort)();
            }
        });
    };
    $this->actingAs($this->kurator);
});

it('Erfolg: der Job legt den Vorschlag an (Status gelesen, Quelle websuche), das Panel meldet ihn', function () {
    ($this->transport)(fn () => ['antwort' => ['felder' => ['herkunft' => ['wert' => 'Italien', 'konfidenz' => 0.9, 'url' => 'https://altoni.example/bigoli']]],
        'quellen' => [['url' => 'https://altoni.example/bigoli', 'title' => 'Altoni']]]);

    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->item->id])
        ->call('recherchieren')->assertSet('fehler', null)
        ->assertSet('hinweis', 'Vorschlag aus der Websuche angelegt. Übernommen wird erst per Klick.');

    $pass = FoodAlchemistProduktpass::where('supplier_item_id', $this->item->id)->sole();
    expect($pass->status)->toBe('gelesen')->and($pass->quelle)->toBe('websuche')
        ->and($pass->extrakt['felder']['herkunft']['wert'] ?? null)->toBe('Italien');
});

it('keine belastbaren Quellen: Status fehler mit Meldung, das Panel zeigt sie (kein 500)', function () {
    ($this->transport)(fn () => throw new RuntimeException('Keine belastbaren Quellen gefunden.'));

    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->item->id])
        ->call('recherchieren')->assertSet('fehler', 'Keine belastbaren Quellen gefunden.')->assertSet('hinweis', null);

    expect(FoodAlchemistProduktpass::where('supplier_item_id', $this->item->id)->value('status'))->toBe('fehler');
});

it('unerwarteter Fehler im Job (kein RuntimeException) wird zur neutralen Meldung, der Pass bleibt nicht auf „liest“', function () {
    ($this->transport)(fn () => throw new TypeError('kaputt'));

    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->item->id])
        ->call('recherchieren')->assertSet('fehler', 'Die Web-Recherche ist fehlgeschlagen.');

    expect(FoodAlchemistProduktpass::where('supplier_item_id', $this->item->id)->value('status'))->toBe('fehler');
});

it('Standard AUS (Dominique 19.10.): ohne FOODALCHEMIST_WEBSUCHE_AKTIV kein Knopf und kein Job', function () {
    config(['foodalchemist.ai.websuche_aktiv' => false]);   // = Standard

    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->item->id])
        ->assertDontSeeHtml('data-produktpass-recherchieren')
        ->call('recherchieren')->assertSet('fehler', 'Die Websuche ist nicht eingerichtet.');

    expect(FoodAlchemistProduktpass::where('supplier_item_id', $this->item->id)->count())->toBe(0);
});
