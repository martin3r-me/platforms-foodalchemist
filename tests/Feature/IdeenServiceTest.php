<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E6.2 — IdeenService: CRUD, Paket-Gruppen, Owner-XOR-Guard,
 * Bestand-Übernahme-als-Idee. Invariante: Skizzen erzeugen NIE Rezepte/GPs/Konzepte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->fbSvc = app(FoodbookService::class);
    $this->svc = app(IdeenService::class);

    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);
    $this->dish = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r1', 'name' => 'HG: Tomaten-Teller', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 12.00, 'dish_class_id' => $klasse->id,
    ]);

    $this->fb = $this->fbSvc->create($this->rootTeam, ['label' => 'Ideen-FB']);
    $this->kapitel = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Vorspeisen']);
});

it('legt freie Skizze als Entwurf an und zählt Positionen hoch', function () {
    $a = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Rote-Bete-Carpaccio']);
    $b = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Kürbis-Velouté', 'description' => 'samtig']);

    expect($a->status)->toBe('entwurf')
        ->and($a->target_form)->toBe('einzel')
        ->and($a->sales_recipe_id)->toBeNull()
        ->and($a->source_meta['quelle'])->toBe('frei')
        ->and($a->position)->toBe(1)
        ->and($b->position)->toBe(2)
        ->and($b->description)->toBe('samtig');

    // Invariante: keine Rezepte/Konzepte erzeugt.
    expect(FoodAlchemistRecipe::count())->toBe(1);
});

it('erzwingt Owner-XOR (weder/beide = Fehler)', function () {
    expect(fn () => $this->svc->add($this->rootTeam, ['title' => 'Waise']))
        ->toThrow(RuntimeException::class, 'GENAU einen Owner');

    // Ein Konzept anzulegen ist für diesen Test unnötig — schon zwei gesetzte IDs müssen scheitern.
    expect(fn () => $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'concept_id' => 999, 'title' => 'Doppel']))
        ->toThrow(RuntimeException::class, 'GENAU einen Owner');
});

it('leerer Titel wird abgelehnt (add + update)', function () {
    expect(fn () => $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => '  ']))
        ->toThrow(RuntimeException::class, 'Titel ist Pflicht');

    $idee = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Da']);
    expect(fn () => $this->svc->update($this->rootTeam, $idee->id, ['title' => '']))
        ->toThrow(RuntimeException::class, 'darf nicht leer sein');
});

it('übernimmt ein Bestands-VK-Gericht als Skizze ohne es zu duplizieren', function () {
    $idee = $this->svc->uebernehmeBestand($this->rootTeam, [
        'chapter_id' => $this->kapitel->id,
        'sales_recipe_id' => $this->dish->id,
    ]);

    expect($idee->sales_recipe_id)->toBe($this->dish->id)
        ->and($idee->title)->toBe('HG: Tomaten-Teller') // Default = Gericht-Name
        ->and($idee->created_via)->toBe('bestand')
        ->and($idee->source_meta['quelle'])->toBe('bestand')
        ->and($idee->status)->toBe('entwurf');

    // Kein neues Rezept angelegt (loser Zeiger).
    expect(FoodAlchemistRecipe::count())->toBe(1);
});

it('lehnt Bestands-Übernahme eines Nicht-VK-Gerichts ab', function () {
    $prod = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'p1', 'name' => 'Basis: Fond', 'status' => 'approved',
        'is_sales_recipe' => false,
    ]);

    expect(fn () => $this->svc->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $prod->id]))
        ->toThrow(RuntimeException::class, 'kein gültiges, sichtbares VK-Gericht');
});

it('bündelt Skizzen zu einem Paket und liste() gruppiert Paket vs. Einzel', function () {
    $gruppe = $this->svc->addGruppe($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'name' => 'Herbst-Menü', 'target_price_pp' => 34.5]);
    expect((float) $gruppe->target_price_pp)->toBe(34.5);

    $imPaket = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Gang 1', 'group_id' => $gruppe->id]);
    $einzeln = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Amuse']);

    // group_id gesetzt ⇒ target_form=paket (Regel M4).
    expect($imPaket->target_form)->toBe('paket')
        ->and($einzeln->target_form)->toBe('einzel');

    $liste = $this->svc->liste($this->rootTeam, $this->kapitel->id);
    expect($liste['gruppen'])->toHaveCount(1)
        ->and($liste['gruppen'][0]['gruppe']->id)->toBe($gruppe->id)
        ->and($liste['gruppen'][0]['ideen']->pluck('id')->all())->toBe([$imPaket->id])
        ->and($liste['einzel']->pluck('id')->all())->toBe([$einzeln->id]);
});

it('lehnt Paket-Form ohne group_id und Quer-Owner-Gruppe ab', function () {
    expect(fn () => $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'X', 'target_form' => 'paket']))
        ->toThrow(RuntimeException::class, 'braucht eine group_id');

    // Gruppe an einem anderen Kapitel → nicht nutzbar.
    $kapitel2 = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Hauptgänge']);
    $fremdGruppe = $this->svc->addGruppe($this->rootTeam, ['chapter_id' => $kapitel2->id, 'name' => 'Anderes Paket']);

    expect(fn () => $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Y', 'group_id' => $fremdGruppe->id]))
        ->toThrow(RuntimeException::class, 'anderen Kapitel');
});

it('verwirft und reaktiviert Skizzen; freigegeben ist gesperrt', function () {
    $idee = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Testidee']);

    $this->svc->setStatus($this->rootTeam, $idee->id, 'verworfen');
    // Verworfene sind standardmäßig NICHT in liste().
    expect($this->svc->liste($this->rootTeam, $this->kapitel->id)['einzel'])->toHaveCount(0)
        ->and($this->svc->liste($this->rootTeam, $this->kapitel->id, null, true)['einzel'])->toHaveCount(1);

    $this->svc->setStatus($this->rootTeam, $idee->id, 'entwurf');
    expect($this->svc->liste($this->rootTeam, $this->kapitel->id)['einzel'])->toHaveCount(1);

    // freigegeben ist dem Kapitel-Go (E7.3) vorbehalten.
    expect(fn () => $this->svc->setStatus($this->rootTeam, $idee->id, 'freigegeben'))
        ->toThrow(RuntimeException::class, 'nicht setzbar');
});

it('kappt Cross-Team-Zugriff auf fremde Skizzen (Tenancy)', function () {
    $idee = $this->svc->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Root-Skizze']);

    $this->actingAs($this->makeUser($this->childA));

    // Kind sieht das geerbte Kapitel (Master-Kette), aber darf keine Skizze daran anlegen …
    expect(fn () => $this->svc->add($this->childA, ['chapter_id' => $this->kapitel->id, 'title' => 'Kind-Skizze']))
        ->toThrow(RuntimeException::class, 'nur durchs Besitzer-Team');

    // … und die Root-Skizze ist zwar sichtbar, aber nicht editierbar.
    expect(fn () => $this->svc->update($this->childA, $idee->id, ['title' => 'geklaut']))
        ->toThrow(RuntimeException::class, 'nur durchs Besitzer-Team');
});

// ── E6.4: KI-Divergenz gegen Provider-Stub ──────────────────────────────────

/** Bindet einen deterministischen Provider-Stub, der ein festes Ideen-JSON liefert. */
function bindDivergenzStub(array $ideen): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(\Platform\Core\Contracts\LLMProviderContract::class, fn () => new class($ideen) implements \Platform\Core\Contracts\LLMProviderContract
    {
        public function __construct(private array $ideen) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['ideen' => $this->ideen], 'confidence' => 0.9, 'reasoning' => 'stub']),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

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

it('KI-Divergenz legt Entwurf-Skizzen an (created_via=ai_gateway), erdet nichts', function () {
    bindDivergenzStub([
        ['titel' => 'Bete-Tatar mit Meerrettich', 'beschreibung' => 'erdig-scharf, roh angemacht'],
        ['title' => 'Fenchel-Orangen-Salat'],                         // englischer Key + ohne Beschreibung
        ['titel' => '   '],                                           // leere Zeile → übersprungen
    ]);

    $res = $this->svc->kiDivergenz($this->rootTeam, $this->kapitel->id, 5);

    expect($res['roh'])->toBe(3)
        ->and($res['angelegt'])->toHaveCount(2)                       // die leere Zeile fiel raus
        ->and($res['confidence'])->toBe(0.9);

    $a = $res['angelegt'][0];
    expect($a->status)->toBe('entwurf')
        ->and($a->created_via)->toBe('ai_gateway')
        ->and($a->chapter_id)->toBe($this->kapitel->id)
        ->and($a->concept_id)->toBeNull()
        ->and($a->target_form)->toBe('einzel')
        ->and($a->sales_recipe_id)->toBeNull()
        ->and($a->source_meta['quelle'])->toBe('ki_divergenz')
        ->and($res['angelegt'][1]->description)->toBeNull();

    // Positionen laufen hoch, Owner-gescoped.
    expect($res['angelegt'][0]->position)->toBe(1)
        ->and($res['angelegt'][1]->position)->toBe(2);

    // Invariante: KEINE Rezepte/Konzepte materialisiert — nur Skizzen.
    expect(FoodAlchemistRecipe::count())->toBe(1)
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistConcept::count())->toBe(0);
});

it('KI-Divergenz bleibt leer, wenn der Provider keine Ideen liefert (Fake-Echo, kein Fehler)', function () {
    config(['foodalchemist.ai.provider' => 'fake']);   // Kontext-Echo → kein `ideen`-Key in werte

    $res = $this->svc->kiDivergenz($this->rootTeam, $this->kapitel->id, 3);

    expect($res['angelegt'])->toHaveCount(0)
        ->and($res['roh'])->toBe(0)
        ->and(FoodAlchemistDishIdea::count())->toBe(0);
});

it('KI-Divergenz kappt Cross-Team-Zugriff (Tenancy)', function () {
    bindDivergenzStub([['titel' => 'Fremd-Idee']]);
    $this->actingAs($this->makeUser($this->childA));

    expect(fn () => $this->svc->kiDivergenz($this->childA, $this->kapitel->id, 3))
        ->toThrow(RuntimeException::class, 'nur durchs Besitzer-Team');

    expect(FoodAlchemistDishIdea::count())->toBe(0);
});
