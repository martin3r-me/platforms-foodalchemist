<?php

use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stufe 2 „Form vor Zustand“ (Dominique 25.10.2026): `Hauptzutat: Geschmack, Verarbeitung, Zuschnitt, Zustand, Portion, Pflichtangabe
 * / (Zusatz) / (Bio) …`. Golden = Stichprobe (328 Zeilen, alle Warengruppen) aus Klaus' Re-Render-Plan 26.10. (Felder aus der lokalen
 * DB nach dem Markenlauf → erwarteter neuer Name). Ohne TK + Behandlung, ohne Getränke/Sirupe mit Zustand — die prüfen eigene Fälle.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->naming = app(GpNamingService::class);
    $this->golden = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/gp_form_vor_zustand_golden.json'), true);
});

it('Golden: der Renderer baut aus den Feldern genau Klaus’ neuen Namen', function () {
    expect(count($this->golden))->toBeGreaterThanOrEqual(300);
    $abweichend = [];
    foreach ($this->golden as $g) {
        $name = $this->naming->renderGpName($g['felder']);
        if ($name !== $g['name']) {
            $abweichend[] = "{$g['gp_id']}: {$name} ≠ {$g['name']}";
        }
    }

    expect($abweichend)->toBe([]);
});

it('Golden: Rundlauf render(felderAusName(neuer Name)) == neuer Name', function () {
    $abweichend = [];
    foreach ($this->golden as $g) {
        $felder = $this->naming->felderAusName($g['name'], true) + ['commodity_group_code' => $g['felder']['commodity_group_code'] ?? null];
        $name = $this->naming->renderGpName($felder);
        if ($name !== $g['name']) {
            $abweichend[] = "{$g['gp_id']}: {$name} ≠ {$g['name']}";
        }
    }

    expect($abweichend)->toBe([]);
});

it('Verarbeitung und Zuschnitt stehen vor dem Zustand, Portion und Pflichtangabe dahinter', function () {
    $n = $this->naming;

    expect($n->renderGpName(['hauptzutat' => 'Kartoffel', 'condition' => 'frisch', 'processing' => 'gegart', 'form' => 'Würfel 10 mm']))->toBe('Kartoffel: gegart, Würfel 10 mm, frisch')
        ->and($n->renderGpName(['hauptzutat' => 'Tomate', 'condition' => 'frisch', 'form' => 'ganz']))->toBe('Tomate: ganz, frisch')
        ->and($n->renderGpName(['hauptzutat' => 'Käse', 'flavor' => 'Chili', 'condition' => 'frisch', 'form' => 'Scheiben', 'pflichtangabe' => '45 % Fett i. Tr.']))
        ->toBe('Käse: Chili, Scheiben, frisch, 45 % Fett i. Tr.')
        ->and($n->renderGpName(['hauptzutat' => 'Brötchen', 'condition' => 'TK', 'form' => 'Mini', 'portion' => '45 g', 'zusatz' => 'Harry', 'bio' => true, 'vegan' => true]))
        ->toBe('Brötchen: Mini, TK, 45 g pro Stück / (Harry) / (Bio) / (Vegan)')
        ->and($n->renderGpName(['hauptzutat' => 'Butter', 'condition' => 'frisch', 'pflichtangabe' => '82 %']))->toBe('Butter: frisch, 82 %');
});

it('TK + Behandlung: TK steht hinter der Behandlung, das Datenmodell („TK, …“ in processing) bleibt und rundet', function () {
    $n = $this->naming;
    $felder = ['hauptzutat' => 'Räucherlachs', 'condition' => 'geräuchert', 'processing' => 'TK, geschnitten'];

    expect($n->renderGpName($felder))->toBe('Räucherlachs: geschnitten, geräuchert, TK')
        ->and($n->renderGpName(['hauptzutat' => 'Matjesfilet', 'condition' => 'gepökelt', 'processing' => 'TK']))->toBe('Matjesfilet: gepökelt, TK')
        ->and($n->felderAusName('Räucherlachs: geschnitten, geräuchert, TK'))->toMatchArray(['condition' => 'geräuchert', 'processing' => 'TK, geschnitten'])
        ->and($n->felderAusName('Räucherlachs: geräuchert, TK, geschnitten', false, true))->toMatchArray(['condition' => 'geräuchert', 'processing' => 'TK, geschnitten'])   // Bestand alte Reihenfolge
        ->and(GpNamingService::istTk('geräuchert', 'Räucherlachs: geschnitten, geräuchert, TK'))->toBeTrue();
});

it('Trinkfertige Getränke, Öle und Sirupe tragen kein Zustandswort — auch wenn condition gesetzt ist; Kaffee/Tee behalten trocken (§9-Zusatz)', function () {
    $n = $this->naming;

    expect($n->renderGpName(['hauptzutat' => 'Apfelsaft', 'condition' => 'konserviert', 'commodity_group_code' => '15', 'bio' => true]))->toBe('Apfelsaft / (Bio)')
        ->and($n->renderGpName(['hauptzutat' => 'Orangensaft', 'condition' => 'konserviert', 'pflichtangabe' => 'Flasche 1 l', 'commodity_group_code' => '15']))->toBe('Orangensaft: Flasche 1 l')
        ->and($n->renderGpName(['hauptzutat' => 'Kaffee', 'condition' => 'trocken', 'processing' => 'gemahlen', 'commodity_group_code' => '15']))->toBe('Kaffee: gemahlen, trocken')   // Grundstoff behält „trocken“ (26.10.)
        ->and($n->renderGpName(['hauptzutat' => 'Olivenöl', 'condition' => 'konserviert', 'flavor' => 'Knoblauch', 'pflichtangabe' => 'extra vergine', 'commodity_group_code' => '11', 'sub_category' => '11.2 Aromatisierte Öle']))
        ->toBe('Olivenöl: Knoblauch, extra vergine')
        ->and($n->renderGpName(['hauptzutat' => 'Ahornsirup', 'condition' => 'konserviert', 'commodity_group_code' => '10', 'sub_category' => '10.7 Sirupe (für Würzung)']))->toBe('Ahornsirup')
        ->and($n->renderGpName(['hauptzutat' => 'Dattelsirup', 'condition' => 'konserviert', 'commodity_group_code' => '12']))->toBe('Dattelsirup')   // Rückfall über die Hauptzutat
        ->and($n->renderGpName(['hauptzutat' => 'Walnüsse in Sirup', 'condition' => 'konserviert', 'pflichtangabe' => 'schwarz', 'commodity_group_code' => '12']))->toBe('Walnüsse in Sirup: konserviert, schwarz')   // Ware MIT Sirup ist kein Sirup
        ->and($n->renderGpName(['hauptzutat' => 'Apfelessig', 'condition' => 'konserviert', 'commodity_group_code' => '11', 'sub_category' => '11.3 Essig']))->toBe('Apfelessig: konserviert')   // Essig behält den Zustand
        ->and($n->validateGpName('Apfelsaft', ['hauptzutat' => 'Apfelsaft', 'condition' => 'konserviert', 'commodity_group_code' => '15'])['errors'])->toBe([])
        ->and(implode(' ', $n->validateGpName('Apfelsaft', ['hauptzutat' => 'Apfelsaft', 'condition' => 'konserviert', 'commodity_group_code' => '15'])['warnings']))->toContain('kein Zustandswort');
});

it('Parser: nur der erste Doppelpunkt mit Leerzeichen trennt Hauptzutat und Eigenschaften (Gelierzucker 2:1)', function () {
    $f = $this->naming->felderAusName('Gelierzucker: trocken, 2:1', true);

    expect($f['hauptzutat'])->toBe('Gelierzucker')->and($f['condition'])->toBe('trocken')->and($f['pflichtangabe'])->toBe('2:1')
        ->and($this->naming->renderGpName($f))->toBe('Gelierzucker: trocken, 2:1')
        ->and($this->naming->felderAusName('Gelierzucker 2:1')['hauptzutat'])->toBe('Gelierzucker 2:1');
});

it('Parser liest Bestandsnamen in alter Reihenfolge und rendert sie in die neue', function () {
    $n = $this->naming;
    $neu = fn (string $alt) => $n->renderGpName($n->felderAusName($alt, true, true));

    expect($neu('Kartoffel: frisch, gegart, Würfel 10 mm'))->toBe('Kartoffel: gegart, Würfel 10 mm, frisch')
        ->and($neu('Tomate: frisch, ganz'))->toBe('Tomate: ganz, frisch')
        ->and($neu('Apfelmark: konserviert, ungezuckert / (Bio)'))->toBe('Apfelmark: konserviert, ungezuckert / (Bio)')   // „un…“ ist Pflichtangabe
        ->and($neu('Gurke: frisch, Stifte 5 mm, mit Schale'))->toBe('Gurke: Stifte 5 mm, frisch, mit Schale');
});
