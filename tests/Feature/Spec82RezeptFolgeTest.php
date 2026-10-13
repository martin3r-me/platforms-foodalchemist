<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Jobs\RezeptFolgeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotUebernahme;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FassungsUmstellung;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\RezeptFolgeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Support\TeamSperre;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RecipeFassungUebernehmenGetTool;
use Platform\FoodAlchemist\Tools\RecipeFassungUebernehmenPostTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 B / Schritt 4d: Eigene Rezepte folgen der neuen Abo-Fassung — außer bei Allergen-/Zusatzstoffänderung (dann Aktions-Signal „Übernehmen am
 * Rezept“) und außer im eingefrorenen Angebot. Nur bei sichtbarer neuer Fassung, nie bei gesperrten Teams, idempotent, Fehler geloggt, Job ab Grenze.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->broich->id]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->keinAbo = Team::create(['name' => 'Ohne Abo', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    foreach ([$this->broich, $this->soul, $this->keinAbo] as $t) {
        StammPaket::freischalten($t);
    }
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->koch = $this->makeUser($this->broich, 'Broich-Koch', 'member');
    $this->chef = $this->makeUser($this->broich, 'Broich-Chef', 'admin');

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
    ($this->publiziere)('Erstveröffentlichung');

    $this->eigenes = function (Team $team, string $name) {
        $r = $this->makeRecipe($team, $name);
        $this->makeIngredient($r, 'Kalbsjus')->update(['referenced_recipe_id' => ($this->fassung)(1)->id, 'gp_id' => null]);

        return $r;
    };
    $this->ref = fn (FoodAlchemistRecipe $r) => (int) $r->ingredients()->where('raw_text', 'Kalbsjus')->value('referenced_recipe_id');
    $this->menge = fn (string $m) => $this->jus->ingredients()->where('raw_text', 'Kalbsknochen')->update(['quantity' => $m]);
    $this->lamm = ($this->eigenes)($this->broich, 'Lamm mit Jus');
    $this->soulGericht = ($this->eigenes)($this->soul, 'Soul-Gericht');
    $this->aktion = fn (Team $t) => FoodAlchemistSignal::where('team_id', $t->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)->first();
});

it('Folgen: ohne Allergen-Änderung zeigt die Zutat danach auf die neue Fassung, mit Historie je Rezept; Master, andere Mandanten ohne Rezept unberührt', function () {
    ($this->menge)('2500');
    ($this->publiziere)('Mehr Knochen');

    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(2)->id)->and(($this->ref)($this->soulGericht))->toBe((int) ($this->fassung)(2)->id);
    $h = FoodAlchemistAngebotUebernahme::where('rezept_id', $this->lamm->id)->sole();
    expect($h->art)->toBe('auto')->and($h->angebot_id)->toBeNull()->and($h->von_fassung_id)->toBe((int) ($this->fassung)(1)->id)->and($h->nach_fassung_id)->toBe((int) ($this->fassung)(2)->id)
        ->and($h->allergen_bestaetigt)->toBeFalse();
    expect(($this->aktion)($this->broich))->toBeNull();   // nichts zu übernehmen, kein Aktions-Signal
});

it('Allergen-Änderung (Endstand): das Rezept bleibt auf der alten Fassung, ein kritisches Aktions-Signal „Übernehmen am Rezept“ nennt es', function () {
    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Butter zur Bindung');

    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id)->and(FoodAlchemistAngebotUebernahme::where('rezept_id', $this->lamm->id)->count())->toBe(0);
    $s = ($this->aktion)($this->broich);
    expect($s)->not->toBeNull()->and($s->severity)->toBe(SignalSeverity::Kritisch)->and($s->description)->toContain('Lamm mit Jus')->and($s->description)->toContain('Milch')
        ->and($s->payload['rezepte'][0]['recipe_id'])->toBe((int) $this->lamm->id)->and($s->payload['rezepte'][0]['grund'])->toBe('allergen');
});

it('Allergen-Änderung nur in einer übersprungenen Zwischenfassung (Endstand gleich): das Rezept bleibt stehen', function () {
    $butter = $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Fassung 2: Butter');
    $butter->delete();
    ($this->publiziere)('Fassung 3: Butter wieder raus');
    expect((bool) ($this->fassung)(3)->fassung_allergen_geaendert)->toBeTrue();   // Milch geht wieder weg, auch das ist eine Änderung des Bildes

    // Rezept auf Fassung 1: Endstand 1→3 gleich, aber Fassung 2 war allergenrelevant
    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id);
    $e = app(RezeptFolgeService::class)->entscheide($this->broich, ($this->fassung)(1));
    expect($e['aktion'])->toBe('halten')->and($e['grund'])->toBe('allergen');
});

it('Unter-Team ohne Abo-Zugriff (erbt_alles aus): sein Rezept wird NICHT umgestellt, das des Oberteams schon', function () {
    app(InhaltsFreigabeService::class)->setzeErbtAlles($this->broich, $this->kita->id, false, $this->chef);
    $kitaGericht = ($this->eigenes)($this->kita, 'Kita-Gericht');

    ($this->menge)('2500');
    ($this->publiziere)('Mehr Knochen');

    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(2)->id)
        ->and(($this->ref)($kitaGericht))->toBe((int) ($this->fassung)(1)->id)   // Fassung 2 ist für die Kita nicht sichtbar
        ->and(FoodAlchemistAngebotUebernahme::where('rezept_id', $kitaGericht->id)->count())->toBe(0);
});

it('Gesperrtes Team (Mandant beendet) wird übersprungen', function () {
    FoodAlchemistMandant::create(['team_id' => $this->soul->id, 'status' => FoodAlchemistMandant::STATUS_BEENDET]);
    TeamSperre::flush();

    ($this->menge)('2500');
    ($this->publiziere)('Mehr Knochen');

    expect(($this->ref)($this->soulGericht))->toBe((int) ($this->fassung)(1)->id)->and(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(2)->id);
});

it('Rezept im Baum eines EINGEFROREN Angebots folgt nicht (auch ohne Allergen-Änderung), der Snapshot bleibt, das Signal meldet es', function () {
    $this->actingAs($this->koch);
    $angebote = app(AngebotService::class);
    $angebot = $angebote->create($this->broich, ['name' => 'Hochzeit Müller']);
    app(OfferCompositionService::class)->addKapitel($this->broich, $angebot->id, ['title' => 'Menü'])->blocks()
        ->create(['team_id' => $this->broich->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $this->lamm->id, 'position' => 1, 'visible' => true]);
    $angebote->setStatus($this->broich, $angebot->id, 'angebot');
    $andere = ($this->eigenes)($this->broich, 'Anderes Gericht');   // nicht eingefroren: folgt

    ($this->menge)('2500');
    ($this->publiziere)('Mehr Knochen');

    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id)->and(($this->ref)($andere))->toBe((int) ($this->fassung)(2)->id);
    $zeile = app(AngebotEinfrierService::class)->aktive($angebot)->zeilen->first();
    expect($zeile->fassungen[0]['fassung_nr'])->toBe(1);   // Snapshot unverändert
    $s = ($this->aktion)($this->broich);
    expect($s->payload['rezepte'][0]['grund'])->toBe('eingefroren')->and($s->payload['angebote'][0]['angebot_id'])->toBe((int) $angebot->id)->and($s->severity)->toBe(SignalSeverity::Warnung);
});

it('Idempotent: ein zweiter Lauf stellt nichts mehr um und schreibt keine weitere Historie', function () {
    ($this->menge)('2500');
    $r = ($this->publiziere)('Mehr Knochen');
    $zeilen = FoodAlchemistAngebotUebernahme::count();

    $zweiter = app(RezeptFolgeService::class)->folgen($r['neu']);

    expect($zweiter['umgestellt'])->toBe(0)->and(FoodAlchemistAngebotUebernahme::count())->toBe($zeilen);
});

it('Ein Fehler bei einem Rezept stoppt die anderen nicht und rollt die Veröffentlichung nicht zurück (geloggt)', function () {
    $kaputt = $this->lamm->id;
    app()->instance(FassungsUmstellung::class, new class($kaputt) extends FassungsUmstellung {
        public function __construct(private int $kaputt) {}

        public function einRezept(FoodAlchemistRecipe $rezept, int $alt, int $neu): int
        {
            if ((int) $rezept->id === $this->kaputt) {
                throw new \RuntimeException('Rezept kaputt');
            }

            return parent::einRezept($rezept, $alt, $neu);
        }
    });
    Log::spy();

    ($this->menge)('2500');
    $r = ($this->publiziere)('Mehr Knochen');

    expect($r['neu'])->not->toBe([])->and(($this->fassung)(2))->not->toBeNull()
        ->and(($this->ref)($this->soulGericht))->toBe((int) ($this->fassung)(2)->id)      // das andere Rezept folgte trotzdem
        ->and(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id);
    Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Rezept kaputt'))->once();
});

it('Ab der Grenze läuft es als Job je Mandant (ShouldBeUnique) und nicht synchron; der Job stellt um', function () {
    config(['foodalchemist.rezept_folge.sync_grenze' => 1]);   // 2 betroffene Rezepte (Broich, Soulkitchen) > 1
    Bus::fake([RezeptFolgeJob::class]);
    ($this->menge)('2500');

    ($this->publiziere)('Mehr Knochen');

    Bus::assertDispatched(RezeptFolgeJob::class, 2);
    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id);   // noch nicht umgestellt
    (new RezeptFolgeJob($this->broich->id, [$this->jus->id]))->handle(app(RezeptFolgeService::class));
    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(2)->id)->and(($this->ref)($this->soulGericht))->toBe((int) ($this->fassung)(1)->id);   // nur Broichs Auftrag
    expect((new RezeptFolgeJob(5, []))->uniqueId())->toBe('rezept-folge-5');
});

it('Übernehmen am Rezept: bei Allergen-Änderung ohne Bestätigung nichts, Kuratieren reicht nicht, Freigeben mit Haken stellt um, Historie + Signal schließt', function () {
    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Butter zur Bindung');
    $svc = app(RezeptFolgeService::class);
    $v = $svc->uebernahmeVorschau($this->broich, $this->lamm->id, $this->jus->id);
    expect($v['braucht_bestaetigung'])->toBeTrue()->and($v['allergen_diff'])->toHaveKey('allergen_milk')->and($v['nach_nr'])->toBe(2);

    expect(fn () => $svc->rezeptUebernehmen($this->broich, $this->koch, $this->lamm->id, $this->jus->id, true))->toThrow(FaRechtFehltException::class);
    $this->actingAs($this->chef);
    expect(fn () => $svc->rezeptUebernehmen($this->broich, $this->chef, $this->lamm->id, $this->jus->id))->toThrow(\RuntimeException::class, 'Bestätigung');
    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id);

    $svc->rezeptUebernehmen($this->broich, $this->chef, $this->lamm->id, $this->jus->id, true);

    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(2)->id);
    $h = FoodAlchemistAngebotUebernahme::where('rezept_id', $this->lamm->id)->where('art', 'rezept')->sole();
    expect($h->allergen_bestaetigt)->toBeTrue()->and($h->bestaetigt_von)->toBe((int) $this->chef->id)->and($h->bestaetigt_at)->not->toBeNull()->and($h->diff)->toHaveKey('allergen_milk');
    expect(($this->aktion)($this->broich)->status)->toBe(SignalStatus::Erledigt);
    // fremdes Team: das Rezept gehört nicht dazu
    expect(fn () => $svc->uebernahmeVorschau($this->soul, $this->lamm->id, $this->jus->id))->toThrow(\RuntimeException::class, 'nicht zu diesem Team');
});

it('MCP und Signal-Panel: Vorschau, Bestätigungspflicht, Übernahme; das Panel zeigt das Rezept mit „Übernehmen am Rezept“', function () {
    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    ($this->publiziere)('Butter zur Bindung');
    $args = ['recipe_id' => $this->lamm->id, 'familie_id' => $this->jus->id];

    $get = app(RecipeFassungUebernehmenGetTool::class)->execute($args, new ToolContext($this->koch, $this->broich));
    expect($get->success)->toBeTrue()->and($get->data['vorschau']['braucht_bestaetigung'])->toBeTrue();
    expect(app(RecipeFassungUebernehmenPostTool::class)->execute($args + ['bestaetigt' => true], new ToolContext($this->koch, $this->broich))->success)->toBeFalse();
    expect(app(RecipeFassungUebernehmenPostTool::class)->execute($args, new ToolContext($this->chef, $this->broich))->success)->toBeFalse();   // ohne Bestätigung

    $s = ($this->aktion)($this->broich);
    $this->actingAs($this->chef);
    $t = Livewire::test(\Platform\FoodAlchemist\Livewire\Signale\DetailPanel::class, ['signalId' => $s->id])
        ->assertSeeHtml('data-signal-fassung-rezept="'.$this->lamm->id.'"')->call('rezeptUebernahmePruefen', $this->lamm->id, $this->jus->id)
        ->assertSeeHtml('data-signal-rezept-vorschau')->assertSeeHtml('data-signal-rezept-bestaetigung');
    $t->call('rezeptUebernahmeAusfuehren');
    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(1)->id);   // ohne Haken nichts
    $t->set('rezeptBestaetigt', true)->call('rezeptUebernahmeAusfuehren')->assertSet('rezeptUebernahme', null);
    expect(($this->ref)($this->lamm))->toBe((int) ($this->fassung)(2)->id);
});
