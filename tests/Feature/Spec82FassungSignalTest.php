<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\SignalCockpit;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 B · Signale zu neuen Master-Fassungen: „Abo-Rezept aktualisiert“ (Info, immer bei neuer Fassung in der Hülle) und „Neuere Fassung verfügbar“
 * (Aktion, eingefrorene Angebote). Ein Signal je Rezept und Mandant, Allergen-Änderung = Kritisch, schließt sich bei Übernahme/Verwerfen, nur das betroffene Team.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->broich->id]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->ohneAbo = Team::create(['name' => 'Ohne Abo', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    foreach ([$this->broich, $this->soul, $this->ohneAbo] as $t) {
        StammPaket::freischalten($t);
    }
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->koch = $this->makeUser($this->broich, 'Broich-Koch', 'member');
    $this->kitaKoch = $this->makeUser($this->kita, 'Kita-Koch', 'member');
    $this->soulUser = $this->makeUser($this->soul, 'Soul', 'owner');
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform-Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);

    $this->knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|knochen', 'name' => 'Kalbsknochen']);
    $this->butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|butter', 'name' => 'Butter', 'allergen_milk' => 'enthalten']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsknochen', $this->knochen, '2000');
    $inhalte = app(InhaltsFreigabeService::class);
    $sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sammlung, 'recipe', [$this->jus->id], $this->kurator);
    foreach ([$this->broich, $this->soul] as $t) {
        app(AboService::class)->abonnieren($this->master, $t, $sammlung, $this->kurator);
    }
    $this->publiziere = fn (string $notiz) => app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, $notiz, $this->kurator);
    $this->fassung = fn (int $nr) => FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->jus->id)->where('fassung_nr', $nr)->first();
    $this->signale = fn (Team $t, ?SignalTyp $typ = null) => FoodAlchemistSignal::where('team_id', $t->id)->when($typ, fn ($q) => $q->where('type', $typ->value))->get();
    $this->knochenMenge = fn (string $m) => $this->jus->ingredients()->where('raw_text', 'Kalbsknochen')->update(['quantity' => $m]);
});

it('Jede neue Fassung in der Abo-Hülle meldet „Abo-Rezept aktualisiert“ (Info) mit Änderungsnotiz — auch ohne Angebot; Master, Nicht-Abonnent und Plattform-Admin sehen nichts', function () {
    ($this->publiziere)('Erstveröffentlichung');
    ($this->knochenMenge)('2500');
    ($this->publiziere)('Mehr Knochen für Tiefe');

    $b = ($this->signale)($this->broich, SignalTyp::FassungAktualisiert);
    expect($b)->toHaveCount(1)   // gebündelt: ein Signal je Rezept und Mandant, nicht je Fassung
        ->and($b[0]->severity)->toBe(SignalSeverity::Info)->and($b[0]->title)->toContain('Kalbsjus')->and($b[0]->title)->toContain('#2')
        ->and($b[0]->description)->toContain('Mehr Knochen für Tiefe')->and($b[0]->description)->toContain('Erstveröffentlichung')
        ->and(collect($b[0]->payload['fassungen'])->pluck('nr')->all())->toBe([1, 2]);
    expect(($this->signale)($this->soul, SignalTyp::FassungAktualisiert))->toHaveCount(1);
    expect(($this->signale)($this->master))->toHaveCount(0)->and(($this->signale)($this->ohneAbo))->toHaveCount(0);

    // Sichtbarkeit: nur das betroffene Team (Unter-Team über die Kette), nie ein anderer Mandant, nie ein Plattform-Admin außerhalb
    $svc = app(SignalService::class);
    expect($svc->paginate([], $this->broich)->total())->toBe(1)->and($svc->paginate([], $this->kita)->total())->toBe(1)
        ->and($svc->paginate([], $this->soul)->total())->toBe(1)->and($svc->paginate([], $this->rootTeam)->total())->toBe(0)
        ->and($svc->paginate([], $this->master)->total())->toBe(0)->and($svc->paginate([], $this->ohneAbo)->total())->toBe(0);
});

it('Allergen-Änderung hebt das Signal auf Kritisch und nennt den Stoff', function () {
    ($this->publiziere)('Erstveröffentlichung');
    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Butter zur Bindung');

    $s = ($this->signale)($this->broich, SignalTyp::FassungAktualisiert)->first();

    expect($s->severity)->toBe(SignalSeverity::Kritisch)->and($s->title)->toContain('Allergen-Änderung')
        ->and($s->description)->toContain('Milch:')->toContain('→ enthalten')->and($s->payload['allergen_geaendert'])->toBeTrue();
});

it('Unveränderte Wiederveröffentlichung meldet nichts neues', function () {
    ($this->publiziere)('Erstveröffentlichung');
    $anzahl = FoodAlchemistSignal::count();
    ($this->publiziere)('Nochmal ohne Änderung');

    expect(FoodAlchemistSignal::count())->toBe($anzahl);
});

it('„Neuere Fassung verfügbar“ (Aktion) entsteht für das Team mit dem eingefrorenen Angebot, nennt es, schließt sich bei Übernahme und trägt Kritisch bei Allergen-Änderung', function () {
    ($this->publiziere)('Erstveröffentlichung');
    $this->actingAs($this->koch);
    $lamm = $this->makeRecipe($this->broich, 'Lamm mit Jus');
    $this->makeIngredient($lamm, 'Kalbsjus')->update(['referenced_recipe_id' => ($this->fassung)(1)->id, 'gp_id' => null]);
    $angebote = app(AngebotService::class);
    $angebot = $angebote->create($this->broich, ['name' => 'Hochzeit Müller']);
    app(OfferCompositionService::class)->addKapitel($this->broich, $angebot->id, ['title' => 'Menü'])->blocks()
        ->create(['team_id' => $this->broich->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $lamm->id, 'position' => 1, 'visible' => true]);
    $angebote->setStatus($this->broich, $angebot->id, 'angebot');   // friert ein
    expect(($this->signale)($this->broich, SignalTyp::FassungNeuerVerfuegbar))->toHaveCount(0);   // noch nichts Neueres

    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Butter zur Bindung');

    $a = ($this->signale)($this->broich, SignalTyp::FassungNeuerVerfuegbar);
    expect($a)->toHaveCount(1)->and($a[0]->severity)->toBe(SignalSeverity::Kritisch)->and($a[0]->title)->toContain('Allergen-Änderung')
        ->and($a[0]->description)->toContain('Hochzeit Müller')->and($a[0]->description)->toContain('Milch')
        ->and($a[0]->payload['angebote'][0]['angebot_id'])->toBe((int) $angebot->id)->and($a[0]->status)->toBe(SignalStatus::Offen);
    expect(($this->signale)($this->soul, SignalTyp::FassungNeuerVerfuegbar))->toHaveCount(0);   // Soulkitchen hat kein eingefrorenes Angebot

    $freigeber = $this->makeUser($this->broich, 'Freigeber', 'admin');
    $this->actingAs($freigeber);
    $einfrier = app(AngebotEinfrierService::class);
    $zeile = $einfrier->aktive($angebot)->zeilen->first()->id;
    $einfrier->uebernehmen($this->broich, $freigeber, $angebot, $zeile, $this->jus->id, true);

    expect(FoodAlchemistSignal::find($a[0]->id)->status)->toBe(SignalStatus::Erledigt)
        ->and(FoodAlchemistSignal::find($a[0]->id)->payload['auto_geschlossen'])->toContain('Übernommen');
});

it('Eine zweite neue Fassung bündelt im selben Aktions-Signal; Verwerfen des Snapshots schließt es', function () {
    ($this->publiziere)('Erstveröffentlichung');
    $this->actingAs($this->koch);
    $lamm = $this->makeRecipe($this->broich, 'Lamm mit Jus');
    $this->makeIngredient($lamm, 'Kalbsjus')->update(['referenced_recipe_id' => ($this->fassung)(1)->id, 'gp_id' => null]);
    $angebote = app(AngebotService::class);
    $angebot = $angebote->create($this->broich, ['name' => 'Taufe']);
    app(OfferCompositionService::class)->addKapitel($this->broich, $angebot->id, ['title' => 'Menü'])->blocks()
        ->create(['team_id' => $this->broich->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $lamm->id, 'position' => 1, 'visible' => true]);
    $angebote->setStatus($this->broich, $angebot->id, 'angebot');

    ($this->knochenMenge)('2500');
    ($this->publiziere)('Fassung 2');
    ($this->knochenMenge)('3000');
    ($this->publiziere)('Fassung 3');

    $a = ($this->signale)($this->broich, SignalTyp::FassungNeuerVerfuegbar);
    expect($a)->toHaveCount(1)->and($a[0]->title)->toContain('#1 → #3')->and($a[0]->severity)->toBe(SignalSeverity::Warnung)   // keine Allergen-Änderung
        ->and($a[0]->seen_count)->toBeGreaterThan(1);

    $angebote->setStatus($this->broich, $angebot->id, 'in_arbeit', true);   // Snapshot bewusst verworfen

    expect(FoodAlchemistSignal::find($a[0]->id)->status)->toBe(SignalStatus::Erledigt);
});

it('Die neuen Signal-Typen haben Label, Icon und einen Weg (Registry), und das Panel zeigt Allergen und Sprung zu „Übernehmen“', function () {
    foreach ([SignalTyp::FassungAktualisiert, SignalTyp::FassungNeuerVerfuegbar] as $t) {
        expect($t->label())->not->toBe('')->and($t->icon())->toStartWith('heroicon-')->and(SignalCockpit::wegRegistry()['navigate'])->toContain($t->value);
    }

    $s = app(SignalService::class)->erzeuge($this->broich, SignalTyp::FassungNeuerVerfuegbar, SignalSeverity::Kritisch, 'Allergen-Änderung: Kalbsjus (#1 → #2)', [
        'dedup_key' => 'x', 'source' => 'fassung', 'payload' => ['allergene' => ['Milch: nicht_enthalten → enthalten'], 'angebote' => [['angebot_id' => 7, 'name' => 'Hochzeit', 'status' => 'angebot', 'zeile_id' => 1, 'eingefroren_nr' => 1]]]]);
    $this->actingAs($this->koch);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Signale\DetailPanel::class, ['signalId' => $s->id])
        ->assertSeeHtml('data-signal-fassung')->assertSeeHtml('data-signal-fassung-allergen')->assertSee('Milch')->assertSeeHtml('data-signal-fassung-uebernehmen="7"');
});

it('Ein Fehler bei der Signal-Erzeugung bremst die Veröffentlichung nicht und rollt sie nicht zurück: er wird gemeldet, nicht geworfen', function () {
    app()->instance(\Platform\FoodAlchemist\Services\FassungSignalService::class, new class(app(SignalService::class), app(\Platform\FoodAlchemist\Services\FassungsVergleich::class)) extends \Platform\FoodAlchemist\Services\FassungSignalService {
        public function nachVeroeffentlichung(array $neueFassungIds): int
        {
            throw new \RuntimeException('Signal-Dienst kaputt');
        }
    });
    \Illuminate\Support\Facades\Log::spy();

    $r = ($this->publiziere)('Erstveröffentlichung');

    expect($r['neu'])->not->toBe([])->and(($this->fassung)(1))->not->toBeNull()   // die Fassung steht
        ->and(DB::table('foodalchemist_abo_objekte')->where('mandant_team_id', $this->broich->id)->where('objekt_id', ($this->fassung)(1)->id)->exists())->toBeTrue()   // die Hülle des Mandanten zog nach
        ->and(($this->signale)($this->broich))->toHaveCount(0);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Signal-Dienst kaputt'))->once();   // geloggt, nicht verschluckt und nicht geworfen
});

it('Ein Fehler beim Nachführen nach dem Übernehmen rollt die Übernahme nicht zurück', function () {
    ($this->publiziere)('Erstveröffentlichung');
    $this->actingAs($this->koch);
    $lamm = $this->makeRecipe($this->broich, 'Lamm mit Jus');
    $this->makeIngredient($lamm, 'Kalbsjus')->update(['referenced_recipe_id' => ($this->fassung)(1)->id, 'gp_id' => null]);
    $angebote = app(AngebotService::class);
    $angebot = $angebote->create($this->broich, ['name' => 'Taufe']);
    app(OfferCompositionService::class)->addKapitel($this->broich, $angebot->id, ['title' => 'Menü'])->blocks()
        ->create(['team_id' => $this->broich->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $lamm->id, 'position' => 1, 'visible' => true]);
    $angebote->setStatus($this->broich, $angebot->id, 'angebot');
    ($this->knochenMenge)('2500');
    ($this->publiziere)('Mehr Knochen');
    app()->instance(\Platform\FoodAlchemist\Services\FassungSignalService::class, new class(app(SignalService::class), app(\Platform\FoodAlchemist\Services\FassungsVergleich::class)) extends \Platform\FoodAlchemist\Services\FassungSignalService {
        public function nachAngebotsAenderung(FoodAlchemistAngebot $angebot, ?\Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung $einfrierung = null): void
        {
            throw new \RuntimeException('Signal-Dienst kaputt');
        }
    });

    \Illuminate\Support\Facades\Log::spy();
    $einfrier = app(AngebotEinfrierService::class);
    $einfrier->uebernehmen($this->broich, $this->koch, $angebot, $einfrier->aktive($angebot)->zeilen->first()->id, $this->jus->id);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Signale ist fehlgeschlagen'))->once();

    expect($lamm->ingredients()->where('raw_text', 'Kalbsjus')->value('referenced_recipe_id'))->toBe((int) ($this->fassung)(2)->id);   // die Übernahme steht
});
