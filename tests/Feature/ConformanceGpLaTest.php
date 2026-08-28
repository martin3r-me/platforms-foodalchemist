<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Tests\Support\ConformanceHealStub;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 4 — der generische Critic deckt jetzt auch GP und LA ab (eigene Adapter,
 * eigene Regelwerke). Beide OHNE Selbstheilung (v1): pruefeUndHeile prüft + persistiert Hinweise,
 * die Heil-Runde wird via unterstuetztHeilung()=false übersprungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

$seedDossier = function ($team, $slug, $title, $content) {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $team->id, 'slug' => $slug, 'title' => $title, 'category' => 'regelwerk',
        'content_md' => $content, 'version' => 1, 'content_hash' => str_repeat('c', 64),
        'char_count' => mb_strlen($content), 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
};

it('GP: prüft gegen das GP-Regelwerk — Wissen im Prompt, Befund normalisiert', function () use ($seedDossier) {
    $seedDossier($this->rootTeam, 'regelwerk-gp-61-singular-pflicht-produktnamen', 'GP §6.1', 'GP_REGELWERK_MARKER §6.1 Produktnamen im Singular.');
    $gp = $this->makeGp($this->rootTeam, 'Tomaten');

    CopilotStub::bind([
        ['paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name', 'begruendung' => 'Plural statt Singular', 'konfidenz' => 0.9],
    ], 'ein Verstoß');

    $erg = app(ConformanceService::class)->pruefe($this->rootTeam, 'gp', $gp->id);

    expect($GLOBALS['l6_user_prompt'] ?? '')->toContain('GP_REGELWERK_MARKER');
    expect($erg['befunde'])->toHaveCount(1);
    expect($erg['befunde'][0]['paragraph'])->toBe('§6.1');
    expect($erg['befunde'][0]['schweregrad'])->toBe('hart');
});

it('GP: pruefeUndHeile überspringt die Heil-Runde (kein Freitext-Revise) → Hinweis direkt persistiert', function () use ($seedDossier) {
    $seedDossier($this->rootTeam, 'regelwerk-gp-61-singular-pflicht-produktnamen', 'GP §6.1', 'GP §6.1 Singular.');
    $gp = $this->makeGp($this->rootTeam, 'Tomaten');

    // NUR EIN conformance-Run gestellt: liefe die Heil-Runde, käme ein zweiter Prüf-Call (→ [])
    // und es würde 0 persistiert. Da GP nicht heilt, bleibt es beim ersten Befund.
    ConformanceHealStub::bind([[['paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name', 'begruendung' => 'Plural', 'konfidenz' => 0.9]]]);

    $erg = app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'gp', $gp->id);

    expect($erg['geheilt'])->toBe(0);
    expect($erg['ablage']['neu'])->toBe(1);
    expect(FoodAlchemistConformanceFinding::where('artifact_type', 'gp')->where('artifact_id', $gp->id)->where('status', 'offen')->count())->toBe(1);
});

it('LA: prüft gegen das LA-Regelwerk', function () use ($seedDossier) {
    $seedDossier($this->rootTeam, 'regelwerk-la-3-4-match-schlussel-hierarchie-necta-quellfelder', 'LA §3-4', 'LA_REGELWERK_MARKER §4 Necta-Quellfelder.');
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Testlieferant']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Tomaten gewürfelt 2500g',
    ]);

    CopilotStub::bind([
        ['paragraph' => '§7', 'schweregrad' => 'weich', 'feld' => 'bezeichnung', 'begruendung' => 'Gebinde im Namen', 'konfidenz' => 0.7],
    ], 'ein Hinweis');

    $erg = app(ConformanceService::class)->pruefe($this->rootTeam, 'la', $la->id);

    expect($GLOBALS['l6_user_prompt'] ?? '')->toContain('LA_REGELWERK_MARKER');
    expect($erg['befunde'])->toHaveCount(1);
    expect($erg['befunde'][0]['schweregrad'])->toBe('weich');
});

it('Unbekannter Artefakt-Typ wirft weiterhin', function () {
    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'foobar', 1))
        ->toThrow(InvalidArgumentException::class);
});
