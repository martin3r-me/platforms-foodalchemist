<?php

use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 28 §6.1: die Food-Cost-Ampel im Gericht-Editor.
 *
 * Vorher stand die Wareneinsatz-Kachel farblos da, weil `cockpit()` weder Ziel-Quote noch
 * Ampel herausgab — die Leiter lag als PRIVATE Kopie im OneShot-Service. Jetzt gehört sie
 * dem MargeService (der `wareneinsatz_pct` ohnehin rechnet) und ist die eine Wahrheit für
 * Wirtschaftlichkeits-Glied, Signale und Editor.
 *
 * Der Test hält vor allem die Grenzen fest: genau AUF dem Ziel ist grün, genau auf Ziel × 1,5
 * ist noch gelb, und ohne Ziel oder ohne Wareneinsatz wird NICHT geraten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('die Ampel-Leiter liegt im MargeService und trifft die Grenzen', function () {
    $m = app(MargeService::class);

    // Ziel 30 %: bis einschließlich 30 grün, darüber gelb, ab mehr als 45 rot
    expect($m->weAmpel(22.0, 30.0))->toBe('gruen');
    expect($m->weAmpel(30.0, 30.0))->toBe('gruen');      // genau auf Ziel = eingehalten
    expect($m->weAmpel(30.1, 30.0))->toBe('gelb');
    expect($m->weAmpel(45.0, 30.0))->toBe('gelb');       // genau Ziel × 1,5 = noch gelb
    expect($m->weAmpel(45.1, 30.0))->toBe('rot');

    // Nichts geraten: ohne Wareneinsatz oder ohne Vorgabe bleibt es unbekannt
    expect($m->weAmpel(null, 30.0))->toBe('unbekannt');
    expect($m->weAmpel(28.0, 0.0))->toBe('unbekannt');
    expect($m->weAmpel(28.0, -5.0))->toBe('unbekannt');
});

it('cockpit() liefert Ziel-Quote und Ampel — und ohne Team ehrlich nichts', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'HG: Rinderfilet | Jus', [
        'is_sales_recipe' => true,
        'ek_total_eur' => 3.0,
        'sales_net' => 10.0,          // ⇒ Wareneinsatz 30,0 %
    ]);

    $verkauf = app(SalesRecipeService::class);
    $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($this->rootTeam);

    $mitTeam = $verkauf->cockpit($gericht->fresh(), $this->rootTeam);
    expect($mitTeam['ziel_pct'])->toBe($ziel);
    expect($mitTeam['marge']['wareneinsatz_pct'])->toBe(30.0);
    expect($mitTeam['ampel'])->toBe(app(MargeService::class)->weAmpel(30.0, $ziel));

    // Ohne Team gibt es keine Vorgabe — der Service holt sich keins über die Hintertür
    $ohneTeam = $verkauf->cockpit($gericht->fresh());
    expect($ohneTeam['ziel_pct'])->toBeNull();
    expect($ohneTeam['ampel'])->toBe('unbekannt');
});

it('der VK-Editor tönt die Wareneinsatz-Kachel nach der Ampel', function () {
    $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($this->rootTeam);

    // Gericht klar ÜBER Ziel × 1,5 → rot → Kachel-Tone `bad`
    $teuer = $this->makeRecipe($this->rootTeam, 'HG: Hummer | Kaviar', [
        'is_sales_recipe' => true,
        'ek_total_eur' => 9.0,
        'sales_net' => 10.0,          // 90 % Wareneinsatz
    ]);

    $html = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)
        ->call('oeffnen', $teuer->id)
        ->html();

    expect($html)->toContain('data-kpi="wareneinsatz"');
    // Die Kachel trägt jetzt eine Wertung statt Dauer-Neutral …
    $kachel = substr($html, strpos($html, 'data-kpi="wareneinsatz"') - 220, 260);
    expect($kachel)->toContain('kpi-bad');
    // … und nennt die Vorgabe im Tooltip, damit die Farbe erklärbar ist
    expect($html)->toContain('Ziel des Teams: ' . number_format($ziel, 1, ',', '.') . ' %');
});
