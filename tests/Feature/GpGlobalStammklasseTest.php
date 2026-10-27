<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kurator 27.10.2026: Ein GP, das nach global umgehängt wird, ist Stamm-Paket — sonst bleibt es auf dem DB-Default `basis` und ist
 * für alle (auch den Master) nur lesbar. io: 57 Marken-Split-GPs (createGp → team_id NULL) waren so gesperrt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('ein teameigenes GP, das global gestellt wird, wird stamm_paket', function () {
    $gp = $this->makeGp($this->rootTeam, 'Sonnenblumen-Hack: TK / (Bio)');
    expect($gp->fresh()->stamm_class)->toBe(StammPaket::BASIS);

    $gp->update(['team_id' => null]);                                      // Muster guard_ab_zuordnen.php: Sichtbarkeit wie das Ziel-GP

    expect(DB::table('foodalchemist_gps')->where('id', $gp->id)->value('stamm_class'))->toBe(StammPaket::PAKET);
});

it('eine beim Umhängen ausdrücklich gesetzte Klasse gilt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Salz: Tafel');

    $gp->update(['team_id' => null, 'stamm_class' => StammPaket::BASIS]);

    expect($gp->fresh()->stamm_class)->toBe(StammPaket::BASIS);
});

it('Anlage mit team_id NULL und andere Updates lassen die Klasse wie sie ist (globaler Basis-GP bleibt gewollt)', function () {
    $basis = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'salz|basis|test', 'name' => 'Salz: Basis']);
    expect($basis->fresh()->stamm_class)->toBe(StammPaket::BASIS);

    $basis->update(['name' => 'Salz: Basis, fein']);                       // team_id unverändert NULL
    expect($basis->fresh()->stamm_class)->toBe(StammPaket::BASIS);

    $team = $this->makeGp($this->rootTeam, 'Pfeffer: schwarz');
    $team->update(['name' => 'Pfeffer: schwarz, ganz']);
    expect($team->fresh()->stamm_class)->toBe(StammPaket::BASIS);
});
