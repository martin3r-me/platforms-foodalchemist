<?php

use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 · L2 — KI-Kundentext für die Foodbook-Einleitung.
 *
 * Der Kern der Etappe ist NICHT „die KI schreibt einen Text", sondern die
 * Zwei-Stufigkeit: Vorschlag → Vorschau → menschliches Übernehmen. Die Tests
 * halten deshalb vor allem fest, dass NICHTS geschrieben wird, solange niemand
 * geklickt hat, und dass ein bestehender Text nur bewusst überschrieben wird.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->foodbooks = app(FoodbookService::class);
});

/**
 * Provider-Stub: liefert genau `werte.text` (der Fake-Provider spiegelt nur den
 * Kontext und hätte kein `text`-Feld) — Muster aus IdeenServiceTest.
 */
function bindKundentextStub(?string $text): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($text) implements LLMProviderContract
    {
        public function __construct(private ?string $text) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            // Der Kontext-Block der User-Message ist die Prüfmasse der Kontext-Tests
            $GLOBALS['ki_kundentext_user_prompt'] = collect($messages)->where('role', 'user')->last()['content'] ?? '';

            return ['content' => json_encode(['werte' => ['text' => $this->text], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void
        {
            $onDelta($this->chat($messages, $options)['content']);
        }

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

/** Foodbook mit einem Kapitel + einem sichtbaren Gericht-Block. */
function fbMitInhalt(Team $team, string $briefing = ''): FoodAlchemistFoodbook
{
    $svc = app(FoodbookService::class);
    // CRM-only (b08c5c2): Kundenname aus verknüpfter CRM-Firma (nicht mehr Freitext-customer).
    $kunde = \Platform\Crm\Models\CrmCompany::create(['team_id' => $team->id, 'name' => 'Hotel Adler', 'is_active' => true]);
    $fb = $svc->create($team, ['label' => 'Angebot Adler', 'crm_company_id' => $kunde->id, 'personen' => 80]);
    if ($briefing !== '') {
        $svc->update($team, $fb->id, ['description' => $briefing]);
    }
    $kap = $svc->addKapitel($team, $fb->id, ['title' => 'Menü intern']);
    // addKapitel kennt nur title/price_mode — der Konsumententitel kommt per update
    $svc->updateKapitel($team, $kap->id, ['consumer_title' => 'Unser Menü']);
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $team->id, 'recipe_key' => 'l2g1', 'name' => 'Zanderfilet auf Linsen', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 18.50,
    ]);
    $svc->addBlock($team, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $gericht->id]);

    return $fb->refresh();
}

it('L2: Vorschlag schreibt NICHTS an die description (nur Vorschau)', function () {
    bindKundentextStub('Ein Abend, der beim Zander beginnt und lange nachklingt.');
    $fb = fbMitInhalt($this->rootTeam);

    $r = $this->foodbooks->kiKundentextVorschlag($this->rootTeam, $fb->id);

    expect($r['text'])->toContain('Zander')
        ->and($r['confidence'])->toBe(0.8)
        // die eigentliche Zusicherung der Etappe
        ->and($fb->refresh()->description)->toBeNull();
});

it('L2: der Kontext trägt Gliederung über die WORDING-Kette + Leitplanken + das Roh-Briefing', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam, 'Rustikal, viel Gemüse, kein Schwein.');

    $this->foodbooks->kiKundentextVorschlag($this->rootTeam, $fb->id);
    $prompt = $GLOBALS['ki_kundentext_user_prompt'] ?? '';

    expect($prompt)->toContain('"ebene": "foodbook"')
        ->toContain('Hotel Adler')
        ->toContain('Rustikal, viel Gemüse')       // briefing_ist = Umformungs-Vorlage
        ->toContain('Unser Menü')                  // Konsumententitel, nicht der interne
        ->not->toContain('Menü intern')
        ->toContain('Zanderfilet auf Linsen')      // Position aus der Wording-Kette
        ->toContain('leitplanken');
});

it('L2: unsichtbare Blöcke stehen NICHT im Kontext (Export-Filter gilt auch für die KI)', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam);
    $block = $fb->chapters()->first()->blocks()->first();
    app(FoodbookService::class)->updateBlock($this->rootTeam, $block->id, ['visible' => false]);

    $this->foodbooks->kiKundentextVorschlag($this->rootTeam, $fb->id);

    expect($GLOBALS['ki_kundentext_user_prompt'] ?? '')->not->toContain('Zanderfilet auf Linsen');
});

it('L2: leere KI-Antwort wirft statt eine leere Vorschau zu zeigen', function () {
    bindKundentextStub('   ');
    $fb = fbMitInhalt($this->rootTeam);

    expect(fn () => $this->foodbooks->kiKundentextVorschlag($this->rootTeam, $fb->id))
        ->toThrow(RuntimeException::class, 'keinen Text');
});

it('L2: geerbtes Foodbook — Vorschlag nur durchs Besitzer-Team (D1)', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam);

    // Kind-Team SIEHT das Foodbook des Eltern-Teams, darf aber nichts erzeugen
    expect(fn () => $this->foodbooks->kiKundentextVorschlag($this->childA, $fb->id))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('L2: der Prompt-Key hängt in der Food-DNA-Kette (Kundentext = Marken-Stimme)', function () {
    expect(AiGatewayService::FOOD_DNA_KEYS)->toContain('foodbook.kundentext')
        ->and(config('foodalchemist.prompts', [])['foodbook.kundentext']['task'] ?? null)->toContain('werte = {text}');
});

it('L2: Fläche — Übernehmen füllt das Formular, speichert aber noch nicht', function () {
    bindKundentextStub('Ein Abend am Wasser.');
    $fb = fbMitInhalt($this->rootTeam);

    $c = Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->call('kiEinleitung')
        ->assertSet('kiTextVorschau', 'Ein Abend am Wasser.')
        ->assertSet('form.description', '')       // Vorschau berührt das Feld nicht
        ->call('kiTextUebernehmen')
        ->assertSet('form.description', 'Ein Abend am Wasser.')
        ->assertSet('kiTextVorschau', null);

    expect($fb->refresh()->description)->toBeNull();   // erst „Speichern" schreibt

    $c->call('speichern');
    expect($fb->refresh()->description)->toBe('Ein Abend am Wasser.');
});

it('L2: Verwerfen lässt einen bestehenden Text unangetastet', function () {
    bindKundentextStub('Neuer Vorschlag.');
    $fb = fbMitInhalt($this->rootTeam, 'Handgeschrieben, bitte nicht anfassen.');

    Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->call('kiEinleitung')
        ->assertSet('form.description', 'Handgeschrieben, bitte nicht anfassen.')
        ->call('kiTextVerwerfen')
        ->assertSet('kiTextVorschau', null)
        ->assertSet('form.description', 'Handgeschrieben, bitte nicht anfassen.');

    expect($fb->refresh()->description)->toBe('Handgeschrieben, bitte nicht anfassen.');
});

it('L2: ohne Provider bleibt die Fläche stehen und sagt es (kein Crash)', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => throw new \Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException());
    $fb = fbMitInhalt($this->rootTeam, 'Bestandstext.');

    Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->call('kiEinleitung')
        ->assertSet('kiTextVorschau', null)
        ->assertSet('form.description', 'Bestandstext.');
});

// ── L2b — dieselbe Mechanik auf der Kapitel-Ebene ───────────────────────────
//
// Zwei Dinge sind hier NEU gegenüber L2a und darum die Prüfmasse:
//  1. **Ein Prompt-Key, zwei Ebenen.** Unterschieden wird nur über `ebene` im Kontext;
//     der Kapitel-Kontext ist auf DIESES Kapitel geschnitten (Nachbar-Kapitel gehören
//     nicht dazu) und trägt die Buch-Einleitung getrennt als `rahmen_einleitung`.
//  2. **Ein geteilter Vorschau-Zustand für beide Flächen** (`kiTextZiel`). Der teuerste
//     Fehler wäre, dass ein Kapitel-Vorschlag im Buch-Feld landet — oder im Feld des
//     nächsten Kapitels, nachdem jemand weitergeklickt hat.

it('L2b: der Kapitel-Kontext ist auf DIESES Kapitel geschnitten und nennt die Buch-Einleitung getrennt', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam, 'Rustikal, viel Gemüse, kein Schwein.');
    $kap = $fb->chapters()->first();
    // Nachbar-Kapitel mit eigener Position — es darf im Kapitel-Kontext NICHT auftauchen.
    $nachbar = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Desserts']);
    $this->foodbooks->updateKapitel($this->rootTeam, $nachbar->id, ['consumer_title' => 'Süßes zum Schluss']);

    $this->foodbooks->kiKapitelKundentextVorschlag($this->rootTeam, $kap->id);
    $prompt = $GLOBALS['ki_kundentext_user_prompt'] ?? '';

    expect($prompt)->toContain('"ebene": "kapitel"')
        ->toContain('Unser Menü')                       // Konsumententitel des Kapitels
        ->toContain('Zanderfilet auf Linsen')           // seine Position aus der Wording-Kette
        ->toContain('rahmen_einleitung')                // Buch-Briefing als Rahmen, nicht als Vorlage
        ->toContain('Rustikal, viel Gemüse')
        ->not->toContain('Süßes zum Schluss');          // das Nachbar-Kapitel eröffnet ein anderer Text
});

it('L2b: ein bestehender Kapitel-Text ist die Umformungs-Vorlage (briefing_ist)', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam);
    $kap = $fb->chapters()->first();
    $this->foodbooks->updateKapitel($this->rootTeam, $kap->id, ['description' => 'Stichworte: leicht, Fisch, Sommer.']);

    $this->foodbooks->kiKapitelKundentextVorschlag($this->rootTeam, $kap->id);

    expect($GLOBALS['ki_kundentext_user_prompt'] ?? '')->toContain('Stichworte: leicht, Fisch, Sommer.');
});

it('Fix(a)/#2: der KAPITEL-Schreibstil-Override (sprach_duktus) landet im Kundentext-Prompt', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam);
    $kap = $fb->chapters()->first();
    $stil = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'kap-kundentext-duktus', 'name' => 'Verspielt',
        'sprach_duktus' => 'KAP-KUNDENTEXT-DUKTUS: locker, mit Augenzwinkern.',
    ]);
    $this->foodbooks->updateKapitel($this->rootTeam, $kap->id, ['writing_style_id' => $stil->id]);

    $this->foodbooks->kiKapitelKundentextVorschlag($this->rootTeam, $kap->id);
    expect($GLOBALS['ki_kundentext_user_prompt'] ?? '')->toContain('KAP-KUNDENTEXT-DUKTUS');
});

it('L2b: Vorschlag schreibt NICHTS an die Kapitel-description', function () {
    bindKundentextStub('Der Auftakt gehört dem Zander.');
    $fb = fbMitInhalt($this->rootTeam);
    $kap = $fb->chapters()->first();

    $r = $this->foodbooks->kiKapitelKundentextVorschlag($this->rootTeam, $kap->id);

    expect($r['text'])->toContain('Zander')
        ->and($kap->refresh()->description)->toBeNull();
});

it('L2b: geerbtes Kapitel — Vorschlag nur durchs Besitzer-Team (D1)', function () {
    bindKundentextStub('Text.');
    $fb = fbMitInhalt($this->rootTeam);

    expect(fn () => $this->foodbooks->kiKapitelKundentextVorschlag($this->childA, $fb->chapters()->first()->id))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('L2b: Fläche — Übernehmen füllt das Kapitel-Feld, gespeichert wird erst beim Verlassen', function () {
    bindKundentextStub('Wir beginnen leicht.');
    $fb = fbMitInhalt($this->rootTeam);
    $kap = $fb->chapters()->first();

    $c = Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->call('kapitelWaehle', $kap->id)
        ->call('kiKapitelText')
        ->assertSet('kiTextZiel', 'kapitel')
        ->assertSet('kiTextVorschau', 'Wir beginnen leicht.')
        ->assertSet('kapitelForm.description', '')      // Vorschau berührt das Feld nicht
        ->call('kiTextUebernehmen')
        ->assertSet('kapitelForm.description', 'Wir beginnen leicht.')
        // Der Buch-Text bleibt unberührt — das Ziel entscheidet kiTextZiel, nicht der Knopf
        ->assertSet('form.description', '');

    expect($kap->refresh()->description)->toBeNull();

    $c->call('kapitelSpeichern');
    expect($kap->refresh()->description)->toBe('Wir beginnen leicht.');
});

it('L2b: Kapitel-Wechsel verwirft den Vorschlag (er darf nicht im falschen Feld landen)', function () {
    bindKundentextStub('Vorschlag fürs erste Kapitel.');
    $fb = fbMitInhalt($this->rootTeam);
    $erstes = $fb->chapters()->first();
    $zweites = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Desserts']);

    Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->call('kapitelWaehle', $erstes->id)
        ->call('kiKapitelText')
        ->assertSet('kiTextVorschau', 'Vorschlag fürs erste Kapitel.')
        ->call('kapitelWaehle', $zweites->id)
        ->assertSet('kiTextVorschau', null)
        ->call('kiTextUebernehmen')                     // ohne Vorschlag ein No-op
        ->assertSet('kapitelForm.description', '');

    expect($zweites->refresh()->description)->toBeNull();
});

it('L2b: der Kapitel-Text kommt im Kundendokument an (er wurde vorher von niemandem gelesen)', function () {
    $fb = fbMitInhalt($this->rootTeam);
    $kap = $fb->chapters()->first();
    $this->foodbooks->updateKapitel($this->rootTeam, $kap->id, ['description' => "Wir beginnen leicht.\nDann wird es kräftig."]);

    $daten = $this->foodbooks->dokumentDaten($this->rootTeam, $fb->refresh());

    // Ohne diese Projektion wäre L2b ein Feld, das nie beim Kunden ankommt — das Signal
    // `foodbook_kapitel_ohne_text` hätte dann eine Lücke gemeldet, die nichts bewirkt.
    expect($daten['kapitel'][0]['text'])->toBe("Wir beginnen leicht.\nDann wird es kräftig.");

    // Leer/nur-Leerzeichen kommt als null heraus, damit die Views nichts rendern müssen.
    $this->foodbooks->updateKapitel($this->rootTeam, $kap->id, ['description' => '   ']);
    expect($this->foodbooks->dokumentDaten($this->rootTeam, $fb->refresh())['kapitel'][0]['text'])->toBeNull();
});

it('L2b: zurück auf den Buch-Kopf räumt die Kapitel-Vorschau weg', function () {
    bindKundentextStub('Kapitel-Vorschlag.');
    $fb = fbMitInhalt($this->rootTeam);

    Livewire::test(Index::class)
        ->call('waehle', $fb->id)
        ->call('kapitelWaehle', $fb->chapters()->first()->id)
        ->call('kiKapitelText')
        ->assertSet('kiTextVorschau', 'Kapitel-Vorschlag.')
        ->call('kopfAnzeigen')
        ->assertSet('kiTextZiel', 'foodbook')
        ->assertSet('kiTextVorschau', null)
        ->assertSet('form.description', '');
});
