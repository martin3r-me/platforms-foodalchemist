<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\Abos\Index as AbosIndex;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel as KonzeptPanel;
use Platform\FoodAlchemist\Livewire\Formate\DetailPanel as FormatPanel;
use Platform\FoodAlchemist\Livewire\Pakete\Index as PaketeIndex;
use Platform\FoodAlchemist\Livewire\Recipes\FassungModal;
use Platform\FoodAlchemist\Livewire\Signale\DetailPanel as SignalPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatFassungService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\FassungMenue;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo Konzepte/Pakete/Formate, PR 8 (Oberfläche): „Veröffentlichen …“ je Art mit EINER Bedingung (Support\FassungMenue, erweitert), der Dialog (FassungModal)
 * für Paket/Konzept/Format, der Abos-Bereich mit allen Arten und Zurückziehen je Art, „bleibt über anderes Abo“, und die Übernehmen-Liste im Signal-Panel.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->actingAs($this->kurator);

    $mehl = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ob|mehl', 'name' => 'Mehl']);
    $this->nuss = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ob|nuss', 'name' => 'Haselnuss', 'allergen_tree_nuts' => 'enthalten']);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);
    $this->gericht = $this->makeRecipe($this->master, 'Spätzle', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Mehl', $mehl, '300');
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);

    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Beilagen-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);
    $cs = app(ConceptService::class);
    $this->konzept = $cs->create($this->master, ['name' => 'Schwaben-Menü']);
    $s = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s->id, ['package_id' => $this->paket->id]);
    $this->formatSvc = app(FormatService::class);
    $this->format = $this->formatSvc->create($this->master, ['name' => 'Wirtshaus-Format', 'origin' => 'eigen']);
    $this->formatSvc->slotConceptEinfuegen($this->master, $this->format->id, $this->konzept->id);

    $this->fRezept = app(FassungService::class);
    $this->fPaket = app(PaketFassungService::class);
    $this->fKonzept = app(ConceptFassungService::class);
    $this->fFormat = app(FormatFassungService::class);
    $this->inhalte = app(InhaltsFreigabeService::class);
    $this->abos = app(AboService::class);
    $this->baue = function (string $bis) {   // veröffentlicht die Kette von unten bis zur gewünschten Stufe
        $stufen = ['recipe', 'paket', 'concept', 'format'];
        foreach (array_slice($stufen, 0, array_search($bis, $stufen) + 1) as $st) {
            match ($st) {
                'recipe' => $this->fRezept->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator),
                'paket' => $this->fPaket->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator),
                'concept' => $this->fKonzept->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator),
                'format' => $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator),
            };
        }
    };
});

it('FassungMenue: EINE Bedingung für alle Arten — Master + eigene Arbeitszeile; nie Fassung, nie Mandant, nie Kunden-Format', function () {
    ($this->baue)('format');
    $paketF = FoodAlchemistPaket::mitFassungen()->where('ist_fassung', true)->first();
    $konzeptF = FoodAlchemistConcept::mitFassungen()->where('ist_fassung', true)->first();
    $formatF = FoodAlchemistFormat::mitFassungen()->where('ist_fassung', true)->first();
    $kunde = $this->formatSvc->create($this->master, ['name' => 'Kunden-Format', 'origin' => 'kunde', 'customer' => 'Broich']);

    expect(FassungMenue::sichtbar($this->master, $this->paket->id, 'paket'))->toBeTrue()
        ->and(FassungMenue::sichtbar($this->master, $this->konzept, 'concept'))->toBeTrue()
        ->and(FassungMenue::sichtbar($this->master, $this->format->id, 'format'))->toBeTrue()
        ->and(FassungMenue::sichtbar($this->master, $this->gericht))->toBeTrue()                    // Bestand: Rezept ohne Art
        ->and(FassungMenue::sichtbar($this->master, $paketF, 'paket'))->toBeFalse()
        ->and(FassungMenue::sichtbar($this->master, $konzeptF, 'concept'))->toBeFalse()
        ->and(FassungMenue::sichtbar($this->master, $formatF, 'format'))->toBeFalse()
        ->and(FassungMenue::sichtbar($this->broich, $this->paket->id, 'paket'))->toBeFalse()        // aktives Team ist kein Master
        ->and(FassungMenue::sichtbar($this->master, $kunde->id, 'format'))->toBeFalse()             // D8
        ->and(FassungMenue::sichtbar($this->master, $this->paket->id, 'unbekannt'))->toBeFalse()
        ->and(FassungMenue::sichtbar(null, $this->paket->id, 'paket'))->toBeFalse();
});

it('Menüpunkt „Veröffentlichen …“ steht im Paket-, Konzept- und Format-Detail des Masters, beim Mandanten nicht', function () {
    $this->actingAs($this->kurator);
    Livewire::test(PaketeIndex::class)->call('waehle', $this->paket->id)->assertSeeHtml('data-fassung-menue')->assertSee('Veröffentlichen …')->assertSeeHtml('fassung-modal.oeffnen');
    Livewire::test(KonzeptPanel::class)->call('zeige', 'concepts', $this->konzept->id)->assertSeeHtml('data-fassung-menue')->assertSee('Veröffentlichen …');
    Livewire::test(FormatPanel::class)->set('selectedId', $this->format->id)->assertSeeHtml('data-fassung-menue')->assertSee('Veröffentlichen …');

    // ein Kunden-Format hat keinen Menüpunkt (D8)
    $kunde = $this->formatSvc->create($this->master, ['name' => 'Kunden-Format', 'origin' => 'kunde', 'customer' => 'Broich']);
    Livewire::test(FormatPanel::class)->set('selectedId', $kunde->id)->assertDontSeeHtml('data-fassung-menue');

    // Mandant: eigenes Paket, eigenes Konzept — kein Menüpunkt
    $this->actingAs($this->broichChef);
    $eigenesPaket = app(PaketService::class)->create($this->broich, ['name' => 'Broich-Paket']);
    $eigenesKonzept = app(ConceptService::class)->create($this->broich, ['name' => 'Broich-Menü']);
    Livewire::test(PaketeIndex::class)->call('waehle', $eigenesPaket->id)->assertDontSeeHtml('data-fassung-menue');
    Livewire::test(KonzeptPanel::class)->call('zeige', 'concepts', $eigenesKonzept->id)->assertDontSeeHtml('data-fassung-menue');
});

it('Dialog „Veröffentlichen“ je Art: Stand, Blockade mit Liste (D2), Notiz-Pflicht, Veröffentlichen, danach „keine Änderung“', function () {
    $this->actingAs($this->kurator);
    // Konzept ohne Fassungen darunter → blockiert, mit Liste
    $lw = Livewire::test(FassungModal::class)->call('oeffnen', null, 'concept', $this->konzept->id)
        ->assertSee('Konzept veröffentlichen')->assertSee('Noch nie veröffentlicht')->assertSeeHtml('data-fassung-blockiert')->assertSee('Beilagen-Duo');
    expect(FoodAlchemistConcept::mitFassungen()->where('ist_fassung', true)->count())->toBe(0);

    ($this->baue)('paket');
    $lw->call('oeffnen', null, 'concept', $this->konzept->id)->assertDontSeeHtml('data-fassung-blockiert')
        ->call('veroeffentlichen')->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Änderungsnotiz'))       // Notiz ist Pflicht
        ->set('notiz', 'Menü erstmals')->call('veroeffentlichen')->assertSeeHtml('data-fassung-meldung')->assertSee('Fassung 1 veröffentlicht');
    expect(FoodAlchemistConcept::mitFassungen()->where('ist_fassung', true)->where('aenderungsnotiz', 'Menü erstmals')->count())->toBe(1);

    $lw->call('oeffnen', null, 'concept', $this->konzept->id)->assertSee('Fassung 1')->assertSeeHtml('data-fassung-gleich');     // unverändert → nichts zu veröffentlichen

    // Paket und Format über denselben Dialog
    Livewire::test(FassungModal::class)->call('oeffnen', null, 'paket', $this->paket->id)->assertSee('Paket veröffentlichen')->assertSee('Fassung 1');
    Livewire::test(FassungModal::class)->call('oeffnen', null, 'format', $this->format->id)->assertSee('Format veröffentlichen')
        ->set('notiz', 'Format erstmals')->call('veroeffentlichen')->assertSee('Fassung 1 veröffentlicht');
    expect(FoodAlchemistFormat::mitFassungen()->where('ist_fassung', true)->count())->toBe(1);

    // Mandant: der Dialog kennt das Objekt nicht (kein Master) — und das Rezept-Verhalten bleibt wie bisher
    $this->actingAs($this->broichChef);
    Livewire::test(FassungModal::class)->call('oeffnen', null, 'paket', $this->paket->id)->assertSee('nur für Pakete des Master-Teams');
    $this->actingAs($this->kurator);
    Livewire::test(FassungModal::class)->call('oeffnen', $this->gericht->id)->assertSee('Rezept veröffentlichen')->assertSee('Fassung 1');
});

it('Status der Dienste: Abweichung und Allergen-Hinweis VOR dem Veröffentlichen (Paket, Konzept, Format)', function () {
    ($this->baue)('format');
    $v = fn () => [app(PaketFassungService::class)->status(FoodAlchemistPaket::find($this->paket->id)),
        app(ConceptFassungService::class)->status(FoodAlchemistConcept::find($this->konzept->id)), app(FormatFassungService::class)->status(FoodAlchemistFormat::find($this->format->id))];
    foreach ($v() as $st) {
        expect($st['letzte']['nr'])->toBe(1)->and($st['weicht_ab'])->toBeFalse()->and($st['blockiert'])->toBeNull()->and($st['allergen_aenderung'])->toBeFalse();
    }
    // Haselnuss ins Gericht → neue Rezept-Fassung; Paket/Konzept/Format sind noch nicht neu veröffentlicht → Allergen-Hinweis schon jetzt sichtbar
    $this->makeIngredient($this->gericht, 'Haselnuss', $this->nuss, '50', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $this->fRezept->veroeffentlichen($this->master, $this->gericht->id, 'Mit Nuss', $this->kurator);
    [$p, $k, $f] = $v();
    expect($p['weicht_ab'])->toBeTrue()->and($p['allergen_aenderung'])->toBeTrue()->and($p['allergen_felder'])->toContain('allergen_tree_nuts')
        ->and($k['weicht_ab'])->toBeFalse()   // der Konzept-Inhalt (Verweis auf Paket-Fassung 1) ist unverändert, solange das Paket nicht neu veröffentlicht ist
        ->and($f['blockiert'])->toBeNull();
    $this->paket->update(['name' => 'Beilagen-Duo Nuss']);
    $this->fPaket->veroeffentlichen($this->master, $this->paket->id, 'Nuss', $this->kurator);
    expect(app(ConceptFassungService::class)->status(FoodAlchemistConcept::find($this->konzept->id))['allergen_aenderung'])->toBeTrue();

    // Kunden-Format: blockiert (D8)
    $kunde = $this->formatSvc->create($this->master, ['name' => 'Kunden-Format', 'origin' => 'kunde', 'customer' => 'Broich']);
    expect(app(FormatFassungService::class)->status($kunde)['blockiert'])->toContain('Kunden-IP');
});

it('Abos-Bereich (Master): alle Arten mit Art-Etikett, Zurückziehen je Art mit Vorschau und „bleibt über anderes Abo“', function () {
    ($this->baue)('format');
    $s1 = $this->inhalte->sammlungAnlegen($this->master, 'Menüs', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $s1, 'concept', [$this->konzept->id], $this->kurator);
    $s3 = $this->inhalte->sammlungAnlegen($this->master, 'Formate', null, $this->kurator);   // das Format steht in einer eigenen, nicht abonnierten Sammlung (sonst hielte es das Konzept in der Hülle)
    $this->inhalte->sammlungHinzu($this->master, $s3, 'format', [$this->format->id], $this->kurator);
    $s2 = $this->inhalte->sammlungAnlegen($this->master, 'Pakete', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $s2, 'paket', [$this->paket->id], $this->kurator);
    $this->abos->abonnieren($this->master, $this->broich, $s1, $this->kurator);
    $this->abos->abonnieren($this->master, $this->broich, $s2, $this->kurator);

    $lw = Livewire::test(AbosIndex::class)
        ->assertSeeHtml('data-abos-objekt="concept-'.$this->konzept->id.'"')->assertSeeHtml('data-abos-objekt="format-'.$this->format->id.'"')
        ->assertSeeHtml('data-abos-objekt="paket-'.$this->paket->id.'"')->assertSee('Konzept')->assertSee('Format')->assertSee('Paket')
        ->assertSeeHtml('data-abos-zurueckziehen="concept-'.$this->konzept->id.'"')->assertSeeHtml('data-abos-zurueckziehen="paket-'.$this->paket->id.'"');
    // Zurückziehen eines Konzepts: das Paket bleibt über die zweite Sammlung (Abo) sichtbar → eigene Liste „bleibt“, nicht kopiert
    $cs = app(ConceptService::class);
    $eigen = $cs->create($this->broich, ['name' => 'Broich-Menü']);
    $z = $cs->addSlot($this->broich, $eigen->id, ['role' => 'Beilage']);
    $paketF = FoodAlchemistPaket::mitFassungen()->where('ist_fassung', true)->value('id');
    DB::table('foodalchemist_concept_slots')->where('id', $z->id)->update(['type' => 'paket', 'package_id' => $paketF]);
    $konzeptF = FoodAlchemistConcept::mitFassungen()->where('ist_fassung', true)->value('id');
    $fb = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'X', 'position' => 0]);
    \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $konzeptF]);

    $lw->call('zurueckziehenVorschau', $s1, $this->konzept->id, 'concept')
        ->assertSee('Zurückziehen (Konzept): Schwaben-Menü')->assertSeeHtml('data-abos-vorschau-genutzt')->assertSeeHtml('data-abos-vorschau-bleibt')->assertSee('über Pakete');
    $lw->set('bestaetigt', true)->call('ausfuehren')->assertSeeHtml('data-abos-meldung')->assertSee('Konzept aus der Sammlung zurückgezogen');
    expect(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $s1)->where('typ', 'concept')->count())->toBe(0)
        ->and(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $s3)->where('typ', 'format')->count())->toBe(1)
        ->and((int) DB::table('foodalchemist_concept_slots')->where('id', $z->id)->value('package_id'))->toBe((int) $paketF);   // das Paket blieb auf der Fassung

    // Mandant: „Meine Abos“ zeigt alle Arten
    $this->actingAs($this->broichChef);
    Livewire::test(AbosIndex::class)->assertSeeHtml('data-abos-mein-objekt="paket-'.$paketF.'"')->assertDontSeeHtml('data-abos-zurueckziehen');
});

it('Signal-Panel: gehaltene Verweise (payload.bausteine) mit „Übernehmen …“, Vorschau mit Allergen-Diff, Bestätigung Pflicht, danach schließt sich das Signal', function () {
    ($this->baue)('concept');
    $s = $this->inhalte->sammlungAnlegen($this->master, 'Menüs', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $s, 'concept', [$this->konzept->id], $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $s, 'paket', [$this->paket->id], $this->kurator);   // das Paket selbst im Abo, damit der Mandant die neueste Fassung sieht
    $this->abos->abonnieren($this->master, $this->broich, $s, $this->kurator);
    $paketF1 = FoodAlchemistPaket::mitFassungen()->where('ist_fassung', true)->value('id');
    $cs = app(ConceptService::class);
    $eigen = $cs->create($this->broich, ['name' => 'Broich-Menü']);
    $z = $cs->addSlot($this->broich, $eigen->id, ['role' => 'Beilage']);
    DB::table('foodalchemist_concept_slots')->where('id', $z->id)->update(['type' => 'paket', 'package_id' => $paketF1]);

    // echte Allergen-Sperre: Haselnuss → Rezept-Fassung 2 → Paket-Fassung 2
    $this->makeIngredient($this->gericht, 'Haselnuss', $this->nuss, '50', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $this->fRezept->veroeffentlichen($this->master, $this->gericht->id, 'Mit Nuss', $this->kurator);
    $this->paket->update(['name' => 'Beilagen-Duo Nuss']);
    $this->fPaket->veroeffentlichen($this->master, $this->paket->id, 'Nuss', $this->kurator);
    expect((int) DB::table('foodalchemist_concept_slots')->where('id', $z->id)->value('package_id'))->toBe((int) $paketF1);   // gehalten

    $signal = FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)
        ->where('dedup_key', 'fassung-neu:paket:'.$this->paket->id)->where('status', SignalStatus::Offen->value)->sole();
    $this->actingAs($this->broichChef);
    $lw = Livewire::test(SignalPanel::class, ['signalId' => $signal->id])
        ->assertSee('Betroffene Stellen')->assertSeeHtml('data-signal-fassung-baustein="konzept_paket-'.$z->id.'"')->assertSee('Broich-Menü')->assertSee('Allergen-Änderung');
    $lw->call('bausteinUebernahmePruefen', 'paket', 'konzept_paket', $z->id)->assertSeeHtml('data-signal-baustein-vorschau')->assertSee('Fassung #1 → #2')->assertSee('Allergen tree_nuts')
        ->assertSeeHtml('data-signal-baustein-bestaetigung')
        ->call('bausteinUebernahmeAusfuehren')->assertSet('fehler', fn ($f) => str_contains((string) $f, 'bestätige'));     // ohne Haken passiert nichts
    expect((int) DB::table('foodalchemist_concept_slots')->where('id', $z->id)->value('package_id'))->toBe((int) $paketF1);

    $lw->set('bausteinBestaetigt', true)->call('bausteinUebernahmeAusfuehren')->assertSet('meldung', 'Neuere Fassung übernommen.')->assertSet('bausteinUebernahme', null);
    $paketF2 = FoodAlchemistPaket::mitFassungen()->where('ist_fassung', true)->orderByDesc('fassung_nr')->value('id');
    expect((int) DB::table('foodalchemist_concept_slots')->where('id', $z->id)->value('package_id'))->toBe((int) $paketF2)
        ->and(FoodAlchemistSignal::where('team_id', $this->broich->id)->where('dedup_key', 'fassung-neu:paket:'.$this->paket->id)->where('status', SignalStatus::Offen->value)->count())->toBe(0);
});
