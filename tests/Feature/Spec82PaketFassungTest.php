<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 2: Paket-Fassung und Abo-Zweig für `packages`.
 * Entscheide Dominique 14.10.: D2/D11 blockieren ohne Rezept-Fassung · D9 Darreichung an die Rezept-Fassung anhängen ·
 * D10 unveröffentlichte Rezept-Änderung = nur Hinweis.
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
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->actingAs($this->kurator);

    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'paket|kartoffel', 'name' => 'Kartoffel']);
    $this->gericht = $this->makeRecipe($this->master, 'Bratkartoffeln', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Kartoffel', $gp, '500');
    $this->basis = $this->makeRecipe($this->master, 'Hausbrot', ['is_sales_recipe' => false]);
    $this->makeIngredient($this->basis, 'Kartoffel', $gp, '300');

    $this->teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->kinder = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'kinder', 'label' => 'Kinder']);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);
    $this->standard = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->master->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $this->teller->id,
        'is_standard' => true, 'quantity_per_unit_g' => 300, 'unit_count' => 1, 'price_mode' => 'auto']);
    $this->kleine = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->master->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $this->kinder->id,
        'is_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 1, 'price_mode' => 'auto']);

    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Beilagen-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);
    $this->paket->update(['price_per_person' => 4.50, 'ek_per_person' => 1.20]);

    $this->fassungen = app(FassungService::class);
    $this->svc = app(PaketFassungService::class);
    $this->reg = app(ToolRegistry::class);
});

function rezeptFassungenVeroeffentlichen($t): void
{
    $t->fassungen->veroeffentlichen($t->master, $t->gericht->id, 'Erst', $t->kurator);
    $t->fassungen->veroeffentlichen($t->master, $t->basis->id, 'Erst', $t->kurator);
}

function paketAbo($t, Team $mandant): int
{
    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($t->master, 'Pakete', null, $t->kurator);
    $inhalte->sammlungHinzu($t->master, $sid, 'paket', [$t->paket->id], $t->kurator);
    app(AboService::class)->abonnieren($t->master, $mandant, $sid, $t->kurator);

    return $sid;
}

it('D2 · ohne veröffentlichte Rezept-Fassung bricht das Veröffentlichen mit der Liste ab, nichts wird angelegt', function () {
    expect(fn () => $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator))
        ->toThrow(\RuntimeException::class, 'Bratkartoffeln');
    expect(FoodAlchemistPaket::mitFassungen()->where('ist_fassung', true)->count())->toBe(0);
});

it('D11 · ein Basisrezept-Posten braucht ebenfalls eine Fassung', function () {
    $this->fassungen->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
    app(PaketService::class)->syncGerichte($this->master, $this->paket->id, [
        ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id],
        ['sales_recipe_id' => $this->basis->id, 'quantity' => 80, 'unit_vocab_id' => $this->portion->id],
    ]);

    expect(fn () => $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator))
        ->toThrow(\RuntimeException::class, 'Hausbrot');
});

it('Veröffentlichen erzeugt eine Fassung ohne Master-Preise, die Gerichte zeigen auf Rezept-Fassungen, die Arbeitszeile bleibt', function () {
    rezeptFassungenVeroeffentlichen($this);
    $r = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Duo erstmals', $this->kurator);

    $f = FoodAlchemistPaket::mitFassungen()->find($r['fassung_id']);
    $rezeptFassung = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->first();
    expect($r['neu'])->toBeTrue()->and($r['fassung_nr'])->toBe(1)
        ->and($f->ist_fassung)->toBeTrue()->and($f->fassung_von_id)->toBe($this->paket->id)->and($f->aenderungsnotiz)->toBe('Duo erstmals')
        ->and($f->price_per_person)->toBeNull()->and($f->ek_per_person)->toBeNull()
        ->and($f->dishes()->pluck('sales_recipe_id')->map(fn ($v) => (int) $v)->all())->toBe([$rezeptFassung->id])
        ->and($this->paket->refresh()->ist_fassung)->toBeFalse()
        ->and((float) $this->paket->price_per_person)->toBe(4.5);
});

it('gleicher Inhalt erzeugt keine neue Fassung, eine Änderung die nächste Nummer', function () {
    rezeptFassungenVeroeffentlichen($this);
    $a = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator);
    $b = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Nochmal', $this->kurator);
    expect($b['neu'])->toBeFalse()->and($b['fassung_id'])->toBe($a['fassung_id']);

    $this->paket->update(['name' => 'Beilagen-Duo Premium']);
    $c = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Umbenannt', $this->kurator);
    expect($c['neu'])->toBeTrue()->and($c['fassung_nr'])->toBe(2)->and($c['fassung_id'])->not->toBe($a['fassung_id']);
});

it('D10 · unveröffentlichte Rezept-Änderung ist nur ein Hinweis', function () {
    rezeptFassungenVeroeffentlichen($this);
    $this->gericht->update(['name' => 'Bratkartoffeln neu']);
    $this->gericht->ingredients()->first()->update(['quantity' => 700]);

    $r = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator);
    expect($r['neu'])->toBeTrue()
        ->and(implode(' ', $r['hinweise']))->toContain('unveröffentlichte Änderungen');
});

it('D9 · Standard-Darreichung zeigt auf die Standard-Form der Fassung, eine andere Form wird angehängt (Hash der Rezept-Fassung bleibt)', function () {
    rezeptFassungenVeroeffentlichen($this);
    $rezeptFassung = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->first();
    $hashVorher = $rezeptFassung->inhalt_hash;
    $zeile = $this->paket->dishes()->first();

    $zeile->update(['presentation_id' => $this->standard->id]);
    $a = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Standard', $this->kurator);
    $fStandard = FoodAlchemistRecipeDarreichung::where('recipe_id', $rezeptFassung->id)->where('is_standard', true)->first();
    expect(FoodAlchemistPaket::mitFassungen()->find($a['fassung_id'])->dishes()->first()->presentation_id)->toBe($fStandard->id);

    $zeile->update(['presentation_id' => $this->kleine->id]);
    $b = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Kinderportion', $this->kurator);
    $angehaengt = FoodAlchemistRecipeDarreichung::where('recipe_id', $rezeptFassung->id)->where('is_standard', false)->get();
    expect($b['neu'])->toBeTrue()
        ->and($angehaengt)->toHaveCount(1)
        ->and((int) $angehaengt->first()->serving_form_id)->toBe($this->kinder->id)
        ->and(FoodAlchemistPaket::mitFassungen()->find($b['fassung_id'])->dishes()->first()->presentation_id)->toBe($angehaengt->first()->id)
        ->and($rezeptFassung->refresh()->inhalt_hash)->toBe($hashVorher);

    // erneut mit anderer Notiz und gleichem Inhalt: nichts wird doppelt angehängt
    $this->paket->update(['note' => 'x']);
    $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Notiz', $this->kurator);
    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $rezeptFassung->id)->where('is_standard', false)->count())->toBe(1);
});

it('nur Master-Team und nur ab Rolle Freigeben', function () {
    rezeptFassungenVeroeffentlichen($this);
    expect(fn () => $this->svc->veroeffentlichen($this->master, $this->paket->id, 'x', $this->masterKoch))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->svc->veroeffentlichen($this->broich, $this->paket->id, 'x', $this->broichChef))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->svc->veroeffentlichen($this->master, $this->paket->id, '  ', $this->kurator))->toThrow(\RuntimeException::class, 'Änderungsnotiz');
});

it('Abo: Mandant sieht Paket-Fassung und deren Rezept-Fassungen, nie die Arbeitszeile; ohne Abo nichts', function () {
    rezeptFassungenVeroeffentlichen($this);
    paketAbo($this, $this->broich);
    $r = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator);
    $rezeptFassung = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->first();

    $paketeBroich = FoodAlchemistPaket::visibleToTeam($this->broich)->pluck('foodalchemist_packages.id')->map(fn ($v) => (int) $v)->all();
    $rezepteBroich = FoodAlchemistRecipe::visibleToTeam($this->broich)->pluck('foodalchemist_recipes.id')->map(fn ($v) => (int) $v)->all();
    expect($paketeBroich)->toBe([$r['fassung_id']])->and($paketeBroich)->not->toContain($this->paket->id)
        ->and($rezepteBroich)->toContain($rezeptFassung->id)->and($rezepteBroich)->not->toContain($this->gericht->id)
        ->and(FoodAlchemistPaket::visibleToTeam($this->soul)->count())->toBe(0)
        ->and(FoodAlchemistPaket::visibleToTeam($this->master)->pluck('foodalchemist_packages.id')->map(fn ($v) => (int) $v)->all())->toBe([$this->paket->id]);
});

it('Abo: eine neue Paket-Fassung zieht in die Hülle nach (jüngste), die Hülle trägt nur Fassungen', function () {
    rezeptFassungenVeroeffentlichen($this);
    paketAbo($this, $this->broich);
    $a = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator);
    $this->paket->update(['name' => 'Beilagen-Duo Premium']);
    $b = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Umbenannt', $this->kurator);

    $huelle = app(AboService::class)->huelle((int) $this->broich->id);
    expect($huelle['paket'])->toBe([$b['fassung_id']])->and($huelle['paket'])->not->toContain($a['fassung_id'])
        ->and(FoodAlchemistRecipe::mitFassungen()->whereIn('id', $huelle['recipe'])->where('ist_fassung', false)->count())->toBe(0);
});

it('eigene Kopie eines Abo-Pakets: gehört dem Mandanten, merkt das Original', function () {
    rezeptFassungenVeroeffentlichen($this);
    paketAbo($this, $this->broich);
    $r = $this->svc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator);
    $this->actingAs($this->broichChef);

    $kopie = app(InhaltsFreigabeService::class)->kopieAnlegen($this->broich, 'paket', $r['fassung_id'], $this->broichChef);
    expect((int) $kopie->team_id)->toBe((int) $this->broich->id)->and($kopie->ist_fassung)->toBeFalse()
        ->and((int) $kopie->kopie_von_id)->toBe($r['fassung_id'])->and($kopie->name)->toBe('Beilagen-Duo');
});

it('MCP: paket_fassungen.PUBLISH und .GET', function () {
    rezeptFassungenVeroeffentlichen($this);
    $ctx = new ToolContext($this->kurator, $this->master);
    $pub = $this->reg->get('foodalchemist.paket_fassungen.PUBLISH')->execute(['paket_id' => $this->paket->id, 'notiz' => 'Erst'], $ctx);
    expect($pub->success)->toBeTrue($pub->error ?? '');

    $get = $this->reg->get('foodalchemist.paket_fassungen.GET')->execute(['paket_id' => $this->paket->id], $ctx);
    expect($get->success)->toBeTrue($get->error ?? '')
        ->and(json_encode($get->data))->toContain('Erst');
});
