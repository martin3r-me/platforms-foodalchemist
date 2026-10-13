<?php

use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Schritt 6 · GOLDEN-TEST des heutigen KI-Kontext-Blocks (Pflicht vor jedem Umstellen).
 *
 * Friert den Block ein, den `CanvasService::cascadeKontext` heute für Concept und Foodbook liefert:
 * Reihenfolge der Ebenen, Beschriftungen, Zeilenformat, Repeatable-Format, Tonalitäts-Block des
 * Foodbooks. Wer den Kontext umbaut (DnaResolver, KontextResolver, Gesamtbudget), muss diese Tests
 * ENTWEDER unverändert grün lassen ODER jede Abweichung einzeln benennen und hier bewusst anpassen
 * (gewollt: Teamprofil-Block, feldweise Vererbung; alles andere ist ein Fehler).
 *
 * Die Fixtures sind fest verdrahtet; kein Zeitstempel, keine Zufallswerte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(CanvasService::class);
    $this->companyId = 9001;

    $setze = function (string $typ, string $ownerType, int $ownerId, array $felder) {
        $canvas = $this->svc->canvasFor($this->rootTeam, $typ, $ownerType, $ownerId);
        foreach ($felder as $key => $wert) {
            $this->svc->setSkalar($canvas, $key, $wert);
        }

        return $canvas;
    };

    $setze('food_dna', 'team', $this->rootTeam->id, [
        'leitbild' => 'Regionale Küche mit Haltung.',
        'no_gos' => 'Kein Foie gras, keine Superlative.',
        'preis_positionierung' => 'gehoben',
    ]);
    $setze('kunde_dna', 'crm_company', $this->companyId, [
        'marke_positionierung' => 'Familiengeführtes Stadthotel.',
        'kommunikation_ton' => 'Locker, per Du.',
    ]);

    $this->stil = FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'golden-stil', 'name' => 'Golden-Stil',
        'sprach_duktus' => 'Kurze Sätze, sinnliche Verben.', 'is_inactive' => false, 'sort_order' => 0,
    ]);

    $fbSvc = app(FoodbookService::class);
    $this->foodbook = $fbSvc->create($this->rootTeam, ['label' => 'Golden-Gala']);
    $fbSvc->update($this->rootTeam, $this->foodbook->id, ['writing_style_id' => $this->stil->id]);
    $setze('foodbook', 'foodbook', $this->foodbook->id, [
        'leitidee' => 'Ein Abend am Wasser.',
        'spektrum' => 'Sommer, Hochzeit',
    ]);

    $this->concept = $this->makeConcept($this->rootTeam, 'Golden-Menü');
    $conceptCanvas = $setze('concept', 'concept', $this->concept->id, [
        'name_claim' => 'Hafenabend – frisch vom Kutter',
        'leitidee' => 'Fisch, Salz, Zitrus.',
    ]);
    $this->svc->addEntry($conceptCanvas, 'geschmackswelten', 'Nordsee', ['claim' => 'salzig-frisch', 'description' => 'Algen, Muscheln']);
    $this->svc->addEntry($conceptCanvas, 'geschmackswelten', 'Zitrus', ['claim' => null, 'description' => null]);

    $this->angebot = \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Golden-Angebot', 'status' => 'anfrage', 'personen' => 80,
    ]);
    $setze('angebot', 'angebot', $this->angebot->id, ['ziel_business_case' => 'Neukunden gewinnen.', 'budget' => '60 € pro Gast']);
});

const GOLDEN_DNA = "Küchen-/Marken-DNA des Mandanten (verbindlicher Stil-/Geschmacks-Rahmen):\n- Leitbild / Positionierung: Regionale Küche mit Haltung.\n- No-Gos / Tabus: Kein Foie gras, keine Superlative.\n- Preis-Positionierung: gehoben";

it('GOLDEN: nur Team-DNA', function () {
    expect($this->svc->cascadeKontext($this->rootTeam))->toBe(['marken_kontext' => GOLDEN_DNA]);
});

it('GOLDEN: Concept (Team-DNA → Concept)', function () {
    $erwartet = GOLDEN_DNA."\n\n"
        ."Kreatives Foodkonzept (Leitidee, Inszenierung, Geschmackswelten):\n- Name + Claim: Hafenabend – frisch vom Kutter\n- Leitidee: Fisch, Salz, Zitrus.\n- Geschmackswelten: Nordsee (salzig-frisch) – Algen, Muscheln; Zitrus";

    expect($this->svc->cascadeKontext($this->rootTeam, (int) $this->concept->id))->toBe(['marken_kontext' => $erwartet]);
});

it('GOLDEN: Foodbook mit Kunde (Team-DNA → Kunde → Foodbook → Tonalität des Foodbooks)', function () {
    $erwartet = GOLDEN_DNA."\n\n"
        ."Kunde/Marke des Endkunden (stabiler Wer-/Kommunikations-Rahmen dieses Kunden):\n- Marke / Positionierung: Familiengeführtes Stadthotel.\n- Kommunikation / Ton: Locker, per Du.\n\n"
        ."Foodbook-Leitidee (was das Foodbook erfüllen muss):\n- Leitidee / roter Faden: Ein Abend am Wasser.\n- Spektrum (Anlass / Saison): Sommer, Hochzeit\n\n"
        ."Tonalität dieses Foodbooks (führt — überschreibt die Default-Schreibstile):\n- Schreibstil: Golden-Stil — Sprach-Duktus: Kurze Sätze, sinnliche Verben.";

    expect($this->svc->cascadeKontext($this->rootTeam, null, (int) $this->foodbook->id, null, $this->companyId))->toBe(['marken_kontext' => $erwartet]);
});

it('GOLDEN: volle Kette (Team → Kunde → Angebot → Foodbook → Concept → Tonalität)', function () {
    $erwartet = GOLDEN_DNA."\n\n"
        ."Kunde/Marke des Endkunden (stabiler Wer-/Kommunikations-Rahmen dieses Kunden):\n- Marke / Positionierung: Familiengeführtes Stadthotel.\n- Kommunikation / Ton: Locker, per Du.\n\n"
        ."Kundenprojekt / Business Case dieses Angebots:\n- Ziel / Business Case: Neukunden gewinnen.\n- Budget-Rahmen: 60 € pro Gast\n\n"
        ."Foodbook-Leitidee (was das Foodbook erfüllen muss):\n- Leitidee / roter Faden: Ein Abend am Wasser.\n- Spektrum (Anlass / Saison): Sommer, Hochzeit\n\n"
        ."Kreatives Foodkonzept (Leitidee, Inszenierung, Geschmackswelten):\n- Name + Claim: Hafenabend – frisch vom Kutter\n- Leitidee: Fisch, Salz, Zitrus.\n- Geschmackswelten: Nordsee (salzig-frisch) – Algen, Muscheln; Zitrus\n\n"
        ."Tonalität dieses Foodbooks (führt — überschreibt die Default-Schreibstile):\n- Schreibstil: Golden-Stil — Sprach-Duktus: Kurze Sätze, sinnliche Verben.";

    expect($this->svc->cascadeKontext($this->rootTeam, (int) $this->concept->id, (int) $this->foodbook->id, (int) $this->angebot->id, $this->companyId))
        ->toBe(['marken_kontext' => $erwartet]);
});

it('GOLDEN: ohne jede Ebene bleibt der Kontext leer (nichts injizieren)', function () {
    $fremd = \Platform\Core\Models\Team::create(['name' => 'Ohne DNA', 'user_id' => 1, 'personal_team' => false]);

    expect($this->svc->cascadeKontext($fremd))->toBe([]);
});

it('GOLDEN: ein Unter-Team ohne eigene Ebene bekommt die Team-DNA des Oberteams (Vererbung aus Schritt 1)', function () {
    expect($this->svc->cascadeKontext($this->childB))->toBe(['marken_kontext' => GOLDEN_DNA]);
});

// ABWEICHUNG vom früheren Golden (Spec 82 Schritt 6, GEWOLLT): Das 3.000er-Limit je Ebene ist durch das
// Gesamtbudget (12.000 Zeichen, KontextResolver) ersetzt. Eine einzelne lange Ebene wird nicht mehr bei
// 3.000 abgeschnitten, solange das Gesamtbudget reicht. Das Kürzen selbst testet Spec82KontextResolverTest.
it('GOLDEN (Abweichung gewollt): eine lange Ebene bleibt unter dem Gesamtbudget ungekürzt', function () {
    $canvas = $this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id);
    $this->svc->setSkalar($canvas, 'leitbild', str_repeat('A', 3500));

    $kontext = $this->svc->cascadeKontext($this->rootTeam)['marken_kontext'];

    expect($kontext)->toContain(str_repeat('A', 3500))->and($kontext)->not->toContain('gekürzt für KI-Kontext');
});
