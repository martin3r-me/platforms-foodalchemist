<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor as AngebotEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorAnfrageService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94b · Stufe 7c (Teil 1): Angebots-Blocktyp `position` — Menge × Satz pauschal, EK je Einheit (ek_value, nur intern),
 * in Summen, Editor, Kundenlink und Dokumenten; die Konfigurator-Anfrage legt Leistungen nach Aufwand als Positionen an.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->comp = app(OfferCompositionService::class);
    $this->std = FoodAlchemistVocabEinheit::create(['team_id' => null, 'slug' => 'std94', 'display_de' => 'Std.']);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Gala', 'personen' => 100]);
    $this->kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Service']);
});

it('Position über den echten create()-Weg: ek_value gespeichert, immer pauschal, Betrag = Menge × Satz, EK in der Gesamtsumme', function () {
    $b = $this->comp->addBlock($this->rootTeam, $this->kap->id, ['type' => 'position', 'label' => 'Garderobe', 'quantity' => 14,
        'unit_vocab_id' => $this->std->id, 'price_value' => 45, 'ek_value' => 25, 'price_basis' => 'person']);
    $b = FoodAlchemistOfferBlock::find($b->id);
    expect((float) $b->ek_value)->toBe(25.0)->and($b->price_basis)->toBe('pauschal')->and((float) $b->quantity)->toBe(14.0);

    expect($this->comp->blockPreis($b))->toMatchArray(['vk_pp' => 0.0, 'pauschal' => 630.0, 'ek_pauschal' => 350.0]);
    $summe = $this->comp->komposition($this->rootTeam, $this->angebot->fresh(), null, true)['summe'];
    expect($summe['gesamt_vk'])->toBe(630.0)->and($summe['gesamt_ek'])->toBe(350.0);

    // leere Formularfelder werden NULL, Typ bleibt pauschal
    $b = $this->comp->updateBlock($this->rootTeam, $b->id, ['ek_value' => '', 'price_basis' => 'person']);
    expect($b->ek_value)->toBeNull()->and($b->price_basis)->toBe('pauschal');
});

it('Aufwand-Position zählt nicht in Summe, Preis pro Person und gesamt_ek; eine manuelle Position (pauschal) zählt weiter', function () {
    $this->comp->addBlock($this->rootTeam, $this->kap->id, ['type' => 'position', 'label' => 'Shuttle', 'quantity' => 2, 'price_value' => 150, 'ek_value' => 90]);
    $aufwand = $this->comp->addBlock($this->rootTeam, $this->kap->id, ['type' => 'position', 'label' => 'Garderobe', 'quantity' => 14,
        'unit_vocab_id' => $this->std->id, 'price_value' => 45, 'ek_value' => 25, 'price_basis' => 'aufwand']);
    expect($aufwand->fresh()->istAufwand())->toBeTrue()
        ->and($this->comp->blockPreis($aufwand->fresh()))->toMatchArray(['pauschal' => 0.0, 'ek_pauschal' => 0.0, 'aufwand' => 630.0, 'ek_aufwand' => 350.0]);

    $kalk = app(AngebotService::class)->kalkulation($this->rootTeam, $this->angebot->fresh());
    expect($kalk['gesamt_vk'])->toBe(300.0)->and($kalk['gesamt_ek'])->toBe(180.0)->and($kalk['vk_pro_person'])->toBe(3.0)   // nur der Shuttle
        ->and($kalk['aufwand'])->toBe(['vk' => 630.0, 'ek' => 350.0, 'db' => 280.0]);

    // Kundenlink: Aufwand unter der Summe, mit voraussichtlicher Gesamtsumme; kein EK
    $snap = app(PresentationService::class)->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', ['design' => 'editorial']);
    $pa = $snap['content']['preis_aufschluesselung'];
    expect($pa['netto'])->toEqual(300.0)->and($pa['zzgl'][0])->toMatchArray(['titel' => 'Garderobe', 'menge' => '14', 'einheit' => 'Std.', 'betrag' => 630.0])
        ->and($pa['voraussichtlich_netto'])->toEqual(930.0)->and($snap['content']['total']['zzgl_summe'])->toEqual(630.0)
        ->and(collect($snap['content']['sections'][0]['blocks'])->pluck('label')->all())->toBe(['Shuttle']);
    expect(json_encode($pa['zzgl']))->not->toContain('350')->not->toContain('ek');

    // Angebots-PDF: Shuttle bei den Positionen, Garderobe unter der Summe als zzgl.
    $pdf = $this->get(route('foodalchemist.angebote.dokument', $this->angebot->id))->assertOk()->getContent();
    expect($pdf)->toContain('Weitere Positionen')->toContain('Shuttle')->toContain('Zzgl. nach Aufwand (geschätzt)')
        ->toContain('Garderobe · ca. 14 Std. à 45,00 € ≈ 630,00 €')->toContain('Nicht im Gesamtpreis enthalten');
    expect(strpos($pdf, 'Zzgl. nach Aufwand'))->toBeGreaterThan(strpos($pdf, 'class="total"'));

    // Editor: Kennzeichnung und getrennte Zeile in der Kalkulation
    Livewire::test(AngebotEditor::class)->call('oeffnen', $this->angebot->id)->call('kapitelWaehle', $this->kap->id)
        ->assertSeeHtml('data-angebot-position-aufwand')->assertSeeHtml('data-angebot-aufwand')->assertSeeHtml('data-angebot-voraussichtlich');
});

it('Kundenlink und Dokumente zeigen Menge, Einheit, Satz und Betrag — nie den EK', function () {
    $this->comp->addBlock($this->rootTeam, $this->kap->id, ['type' => 'position', 'label' => 'Garderobe', 'quantity' => 14,
        'unit_vocab_id' => $this->std->id, 'price_value' => 45, 'ek_value' => 25]);

    $snap = app(PresentationService::class)->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', ['design' => 'editorial']);
    $block = collect($snap['content']['sections'][0]['blocks'])->firstWhere('kind', 'position');
    expect($block['label'])->toBe('Garderobe')->and($block['subtitle'])->toBe('14 Std. à 45,00 €')->and($block['price']['pp'])->toBe(630.0);
    expect(json_encode($snap['content']))->not->toContain('ek_value')->not->toContain('350');

    $daten = app(AngebotService::class)->dokumentDaten($this->rootTeam, $this->angebot->fresh());
    expect($daten['positionen'])->toBe([['label' => 'Garderobe', 'menge' => 14.0, 'einheit' => 'Std.', 'satz' => 45.0, 'betrag' => 630.0]]);
    $pdfHtml = $this->get(route('foodalchemist.angebote.dokument', $this->angebot->id))->assertOk()->getContent();   // echter Druckweg
    expect($pdfHtml)->toContain('Weitere Positionen')->toContain('Garderobe · 14 Std. à 45,00 € = 630,00 €')->not->toContain('350,00');
});

it('Editor: Position anlegen und bearbeiten (Menge, Einheit, Satz, EK), Anzeige mit Betrag', function () {
    $t = Livewire::test(AngebotEditor::class)->call('oeffnen', $this->angebot->id)->call('kapitelWaehle', $this->kap->id)
        ->assertSeeHtml('data-angebot-position-neu')
        ->call('blockBasis', 'position');
    $b = FoodAlchemistOfferBlock::where('chapter_id', $this->kap->id)->where('type', 'position')->firstOrFail();
    expect($b->price_basis)->toBe('pauschal')->and((float) $b->quantity)->toBe(1.0);

    $t->call('blockBearbeiten', $b->id)->assertSeeHtml('data-angebot-position-label')
        ->set('blockForm.label', 'Shuttle')->set('blockForm.quantity', '2')->set('blockForm.unit_vocab_id', (string) $this->std->id)
        ->set('blockForm.price_value', '150')->set('blockForm.ek_value', '90')->call('blockSpeichern')
        ->assertSeeHtml('data-angebot-position="' . $b->id . '"')->assertSee('Shuttle');
    $b = $b->fresh();
    expect((float) $b->quantity)->toBe(2.0)->and((float) $b->ek_value)->toBe(90.0)->and($b->unit_vocab_id)->toBe($this->std->id);
});

it('Konfigurator-Anfrage: Leistungen nach Aufwand werden Positionen im Kapitel „Nach Aufwand“, geschätzt bei der Gästezahl der Anfrage', function () {
    $svc = app(KonfiguratorService::class);
    $k = $svc->anlegen($this->rootTeam, ['name' => 'Gala-Konfigurator', 'stunden_dinner' => 7, 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $dinner = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Dinner', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 50, 'ek_per_person_cache' => 15]);
    $kz = $svc->konzeptHinzu($this->rootTeam, $k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $dinner->id, 'kunden_titel' => 'Abend']);
    $svc->paketAnlegen($this->rootTeam, $k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $svc->leistungHinzu($this->rootTeam, $k->id, ['kategorie' => 'personal', 'bezeichnung' => 'Garderobe', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste',
        'schluessel_n' => 50, 'preis_je' => 'stunde', 'stunden_quelle' => 'einsatzzeit', 'haeufigkeit' => 'je_event', 'ek_satz' => 25,
        'vk_modus' => 'fixed', 'vk_satz' => 45, 'vk_begruendung' => 'Test', 'abrechnung' => 'aufwand']);
    app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);

    $a = app(KonfiguratorAnfrageService::class)->anfragen($k->fresh(), ['name' => 'Kevin', 'email' => 'kevin@example.org', 'datenschutz' => '1',
        'konzept' => $kz->id, 'tageszeit' => 'dinner', 'pax' => 120, 'ts' => encrypt(now()->subSeconds(30)->timestamp), 'website' => '']);
    $a = FoodAlchemistAngebot::find($a->id);

    // 120 Gäste: 3 Kräfte (1 je 50) × 7 Std. = 21 Std. à 45 €
    $pos = FoodAlchemistOfferBlock::query()->whereHas('chapter', fn ($q) => $q->where('offer_id', $a->id)->where('title', 'Nach Aufwand'))->get();
    expect($pos)->toHaveCount(1)
        ->and($pos[0]->type)->toBe('position')->and($pos[0]->label)->toBe('Garderobe')
        ->and((float) $pos[0]->quantity)->toBe(21.0)->and((float) $pos[0]->price_value)->toBe(45.0)->and((float) $pos[0]->ek_value)->toBe(25.0)
        ->and($pos[0]->einheitText())->toBe('Std.')
        ->and($a->konfiguration_json['zzgl'][0])->toMatchArray(['bezeichnung' => 'Garderobe', 'menge' => 21.0, 'betrag' => 945.0])
        ->and($a->brief)->toContain('zzgl. nach Aufwand (geschätzt): ca. 21 Std. Garderobe ≈ 945,00 €')
        ->and((float) $a->total_price)->toBe(6000.0)   // der Paketpreis bleibt ohne Aufwand (50 € × 120)
        ->and($pos[0]->price_basis)->toBe('aufwand');
    // Angebot aus der Anfrage: Summe = Paketpreis, der Aufwand steht als zzgl. daneben
    $kalk = app(AngebotService::class)->kalkulation($this->rootTeam, $a);
    expect($kalk['aufwand']['vk'])->toBe(945.0);
});

it('MCP: offer_block.POST legt eine Position mit EK an', function () {
    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.offer_block.POST');
    $r = $tool->execute(['chapter_id' => $this->kap->id, 'type' => 'position', 'label' => 'LKW', 'quantity' => 2, 'price_value' => 180, 'ek_value' => 120],
        new \Platform\Core\Contracts\ToolContext($this->user, $this->rootTeam));
    expect($r->success)->toBeTrue($r->error ?? '');
    $b = FoodAlchemistOfferBlock::where('label', 'LKW')->firstOrFail();
    expect((float) $b->ek_value)->toBe(120.0)->and($b->price_basis)->toBe('pauschal');
});
