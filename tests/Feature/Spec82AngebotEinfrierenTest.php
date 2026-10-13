<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\EinfrierKosten;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\AngeboteEinfrierenGetTool;
use Platform\FoodAlchemist\Tools\AngeboteEinfrierenPostTool;
use Platform\FoodAlchemist\Tools\AngeboteStatusTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 B3 · Durchstich Schritt 4a: eingefrorenes Angebot. Snapshot je Gericht mit Abo-Fassung im Baum (Fassungs-IDs, EK mit Status,
 * Allergene), Auslöser = Status „angebot“, Rückweg verwirft nach Bestätigung, neue Master-Fassung ändert den Stand nie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    StammPaket::freischalten($this->soul);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->koch = $this->makeUser($this->broich, 'Broich-Koch', 'member');
    $this->fremd = $this->makeUser($this->soul, 'Soul-Koch', 'owner');

    $this->knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|knochen', 'name' => 'Kalbsknochen']);
    $this->butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|butter', 'name' => 'Butter', 'allergen_milk' => 'enthalten']);
    $this->mehl = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|mehl', 'name' => 'Weizenmehl', 'allergen_gluten' => 'enthalten']);

    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsknochen', $this->knochen, '2000');
    $this->fassungen = app(FassungService::class);
    $inhalte = app(InhaltsFreigabeService::class);
    $sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sammlung, 'recipe', [$this->jus->id], $this->kurator);
    foreach ([$this->broich, $this->soul] as $t) {
        app(AboService::class)->abonnieren($this->master, $t, $sammlung, $this->kurator);
    }
    $this->publiziere = fn (string $notiz) => $this->fassungen->veroeffentlichen($this->master, $this->jus->id, $notiz, $this->kurator);
    $this->fassung = fn (int $nr) => FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->jus->id)->where('fassung_nr', $nr)->first();
    ($this->publiziere)('Erstveröffentlichung');

    // Broichs Gerichte: eines mit Abo-Fassung (Lamm mit Jus), eines ohne (Salat)
    $this->lamm = $this->makeRecipe($this->broich, 'Lamm mit Jus');
    $this->makeIngredient($this->lamm, 'Kalbsjus')->update(['referenced_recipe_id' => ($this->fassung)(1)->id, 'gp_id' => null]);
    $this->salat = $this->makeRecipe($this->broich, 'Blattsalat');
    $this->makeIngredient($this->salat, 'Salat', $this->knochen, '100');

    $this->actingAs($this->koch);
    $this->angebote = app(AngebotService::class);
    $this->angebot = $this->angebote->create($this->broich, ['name' => 'Hochzeit Müller']);
    $this->kapitel = app(OfferCompositionService::class)->addKapitel($this->broich, $this->angebot->id, ['title' => 'Menü']);
    $this->block = fn ($recipe) => $this->kapitel->blocks()->create(['team_id' => $this->broich->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $recipe->id, 'position' => 1 + $this->kapitel->blocks()->count(), 'visible' => true]);
    $this->svc = app(AngebotEinfrierService::class);
    $this->kostenFest = function (array $k) {
        app()->instance(EinfrierKosten::class, new class($k) extends EinfrierKosten {
            public function __construct(private array $k) {}

            public function fuer(FoodAlchemistRecipe $gericht, Team $team): array
            {
                return $this->k;
            }
        });
        $this->svc = app()->make(AngebotEinfrierService::class);
    };
});

it('Einfrieren: nur das Gericht mit Abo-Fassung im Baum wird gespeichert (Fassungs-ID, Allergene, EK-Status), eigene Gerichte bleiben wie heute', function () {
    ($this->block)($this->lamm);
    ($this->block)($this->salat);

    $e = $this->svc->einfrieren($this->broich, $this->koch, $this->angebot);

    expect($e->zeilen)->toHaveCount(1);
    $z = $e->zeilen->first();
    expect($z->recipe_id)->toBe((int) $this->lamm->id)->and($z->name)->toBe('Lamm mit Jus')
        ->and($z->fassungen)->toHaveCount(1)->and($z->fassungen[0]['fassung_id'])->toBe((int) ($this->fassung)(1)->id)
        ->and($z->fassungen[0]['fassung_von_id'])->toBe((int) $this->jus->id)->and($z->fassungen[0]['fassung_nr'])->toBe(1)
        ->and($z->fassungen[0]['allergene'])->toHaveKey('allergen_milk')->and($z->allergene)->toHaveKey('allergen_gluten');
    // ohne Kostenschicht gibt es keinen belastbaren EK: unvollständig, VK nie 0
    expect($z->vollstaendig)->toBeFalse()->and($z->grund)->toBe('kostenschicht_fehlt')->and($z->vk)->toBeNull();
    expect($this->svc->einfrieren($this->broich, $this->koch, $this->angebot)->id)->toBe($e->id);   // schon eingefroren: bleibt, kein Überschreiben
});

it('Angebot ohne Gericht mit Abo-Fassung: nichts zu frieren', function () {
    ($this->block)($this->salat);

    expect($this->svc->einfrieren($this->broich, $this->koch, $this->angebot))->toBeNull()
        ->and($this->svc->aktive($this->angebot))->toBeNull();
});

it('Gerichte aus Concepts (Slot) werden gefunden', function () {
    $concept = $this->makeConcept($this->broich, 'Hochzeitsmenü');
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $this->lamm->id]);
    $this->kapitel->blocks()->create(['team_id' => $this->broich->id, 'type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1, 'visible' => true]);

    $e = $this->svc->einfrieren($this->broich, $this->koch, $this->angebot);

    expect($e->zeilen->pluck('recipe_id')->all())->toBe([(int) $this->lamm->id]);
});

it('EK-Status: unvollständig bleibt unvollständig (Teilsumme, VK null, nie 0), vollständig trägt EK und VK', function () {
    ($this->block)($this->lamm);

    ($this->kostenFest)(['ek_summe' => 3.4, 'ek_per_kg' => 8.5, 'n_priced' => 1, 'n_total' => 3, 'vollstaendig' => false, 'grund' => 'zutaten_ohne_preis',
        'fehlende' => [['name' => 'Butter', 'gp_id' => 9]], 'vk' => 12.0, 'berechnet_at' => now()->toIso8601String()]);
    $z = $this->svc->einfrieren($this->broich, $this->koch, $this->angebot)->zeilen->first();
    expect($z->vollstaendig)->toBeFalse()->and($z->ek_summe)->toBe(3.4)->and($z->grund)->toBe('zutaten_ohne_preis')->and($z->vk)->toBeNull()   // VK einer unvollständigen Basis wird nicht gespeichert
        ->and($z->fehlende[0]['name'])->toBe('Butter');

    $this->svc->verwerfen($this->broich, $this->koch, $this->angebot);
    ($this->kostenFest)(['ek_summe' => 8.2, 'ek_per_kg' => 9.1, 'n_priced' => 3, 'n_total' => 3, 'vollstaendig' => true, 'grund' => null, 'fehlende' => [], 'vk' => 21.0, 'berechnet_at' => now()->toIso8601String()]);
    $z = $this->svc->einfrieren($this->broich, $this->koch, $this->angebot)->zeilen->first();
    expect($z->vollstaendig)->toBeTrue()->and($z->ek_summe)->toBe(8.2)->and($z->vk)->toBe(21.0)->and($z->grund)->toBeNull();
});

it('Eine neue Master-Fassung ändert den eingefrorenen Stand nicht, sie wird als „neuere Fassung verfügbar“ mit Allergen-Diff angezeigt', function () {
    ($this->block)($this->lamm);
    $e = $this->svc->einfrieren($this->broich, $this->koch, $this->angebot);
    expect($this->svc->veraltet($this->angebot))->toBe([]);

    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);   // Master tauscht/ergänzt: Milch kommt dazu
    ($this->publiziere)('Butter zur Bindung');

    $alt = $e->fresh('zeilen')->zeilen->first();
    expect($alt->fassungen[0]['fassung_nr'])->toBe(1);   // Stand unverändert
    $v = $this->svc->veraltet($this->angebot);
    expect($v)->toHaveCount(1)->and($v[0]['gericht'])->toBe('Lamm mit Jus')->and($v[0]['eingefroren_nr'])->toBe(1)->and($v[0]['neue_nr'])->toBe(2)
        ->and($v[0]['aenderungsnotiz'])->toBe('Butter zur Bindung')->and($v[0]['allergen_geaendert'])->toBeTrue()
        ->and($v[0]['allergen_diff'])->toHaveKey('allergen_milk')->and($v[0]['allergen_diff']['allergen_milk']['nach'])->toBe('enthalten');
});

it('Zwei Fassungen übersprungen: der Diff läuft über den Endstand, nicht über das Flag der letzten Fassung', function () {
    ($this->block)($this->lamm);
    $this->svc->einfrieren($this->broich, $this->koch, $this->angebot);

    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Fassung 2: Butter');
    $this->jus->ingredients()->where('raw_text', 'Kalbsknochen')->update(['quantity' => '2500']);
    ($this->publiziere)('Fassung 3: nur Menge, keine Allergen-Änderung');
    expect((bool) ($this->fassung)(3)->fassung_allergen_geaendert)->toBeFalse();   // Lisas Flag der neuesten Fassung sieht die Änderung der Fassung 2 NICHT

    $v = $this->svc->veraltet($this->angebot)[0];

    expect($v['neue_nr'])->toBe(3)->and($v['allergen_geaendert'])->toBeTrue()->and($v['allergen_diff'])->toHaveKey('allergen_milk')
        ->and(array_column($v['zwischen'], 'fassung_nr'))->toBe([2, 3])->and($v['zwischen'][0]['allergen_geaendert'])->toBeTrue()
        ->and($v['zwischen'][1]['allergen_geaendert'])->toBeFalse();
});

it('Status: „angebot“ friert ein, Rückweg verlangt Bestätigung, danach folgt das Angebot wieder der neuesten Fassung; später wieder „angebot“ = neuer Stand', function () {
    ($this->block)($this->lamm);
    $this->angebote->setStatus($this->broich, $this->angebot->id, 'in_arbeit');
    expect($this->svc->aktive($this->angebot))->toBeNull();

    $this->angebote->setStatus($this->broich, $this->angebot->id, 'angebot');
    $erste = $this->svc->aktive($this->angebot);
    expect($erste)->not->toBeNull()->and($erste->status_beim_einfrieren)->toBe('angebot')->and($erste->zeilen)->toHaveCount(1);

    $this->angebote->setStatus($this->broich, $this->angebot->id, 'versendet');   // bleibt eingefroren
    expect($this->svc->aktive($this->angebot)->id)->toBe($erste->id);

    expect(fn () => $this->angebote->setStatus($this->broich, $this->angebot->id, 'in_arbeit'))->toThrow(\RuntimeException::class, 'eingefroren');
    expect($this->angebot->fresh()->status->value)->toBe('versendet')->and($this->svc->aktive($this->angebot)->id)->toBe($erste->id);   // nichts halb geändert

    $this->angebote->setStatus($this->broich, $this->angebot->id, 'in_arbeit', true);
    expect($this->svc->aktive($this->angebot))->toBeNull()->and($this->svc->veraltet($this->angebot))->toBe([])
        ->and(FoodAlchemistAngebotEinfrierung::where('angebot_id', $this->angebot->id)->whereNotNull('verworfen_at')->count())->toBe(1);   // Historie bleibt

    $this->angebote->setStatus($this->broich, $this->angebot->id, 'angebot');
    expect($this->svc->aktive($this->angebot)->id)->not->toBe($erste->id);
});

it('Wer direkt auf „versendet“ springt, wird ebenfalls eingefroren', function () {
    ($this->block)($this->lamm);

    $this->angebote->setStatus($this->broich, $this->angebot->id, 'versendet');

    expect($this->svc->aktive($this->angebot))->not->toBeNull();
});

it('Rechte und Mandantentrennung: ein Leser friert nichts ein, ein fremder Mandant sieht weder Stand noch Diff', function () {
    ($this->block)($this->lamm);
    $this->svc->einfrieren($this->broich, $this->koch, $this->angebot);

    $leser = $this->makeUser($this->broich, 'Gast', 'viewer');
    $this->actingAs($leser);
    expect(fn () => $this->svc->verwerfen($this->broich, $leser, $this->angebot))->toThrow(FaRechtFehltException::class);
    expect($this->svc->aktive($this->angebot))->not->toBeNull();

    $get = app(AngeboteEinfrierenGetTool::class)->execute(['id' => $this->angebot->id], new ToolContext($this->fremd, $this->soul));
    expect($get->success)->toBeFalse();
    $this->actingAs($this->fremd);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierZeile::visibleToTeam($this->soul)->count())->toBe(0);
});

it('MCP: EINFRIEREN_POST friert ein, verwerfen braucht bestaetigt, EINFRIEREN_GET zeigt Stand und veraltet; STATUS kennt snapshot_verwerfen', function () {
    ($this->block)($this->lamm);
    $ctx = new ToolContext($this->koch, $this->broich);

    $ein = app(AngeboteEinfrierenPostTool::class)->execute(['id' => $this->angebot->id, 'aktion' => 'einfrieren'], $ctx);
    expect($ein->success)->toBeTrue()->and($ein->data['eingefroren'])->toBeTrue()->and($ein->data['zeilen'])->toBe(1);

    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Butter');
    $get = app(AngeboteEinfrierenGetTool::class)->execute(['id' => $this->angebot->id], $ctx);
    expect($get->data['eingefroren'])->toBeTrue()->and($get->data['einfrierung']['zeilen'][0]['fassungen'][0]['fassung_nr'])->toBe(1)
        ->and($get->data['veraltet'][0]['allergen_diff'])->toHaveKey('allergen_milk');

    expect(app(AngeboteEinfrierenPostTool::class)->execute(['id' => $this->angebot->id, 'aktion' => 'verwerfen'], $ctx)->success)->toBeFalse();
    $status = app(AngeboteStatusTool::class)->execute(['id' => $this->angebot->id, 'status' => 'in_arbeit'], $ctx);
    expect($status->success)->toBeFalse()->and($status->error)->toContain('eingefroren');
    expect(app(AngeboteStatusTool::class)->execute(['id' => $this->angebot->id, 'status' => 'in_arbeit', 'snapshot_verwerfen' => true], $ctx)->success)->toBeTrue()
        ->and($this->svc->aktive($this->angebot))->toBeNull();
});
