<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\SupplierAgreementService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R9.1 — Lieferanten-Stammblatt: Status + Konditionen + Kontakte + Absprachen +
 * Dokumente aggregiert; Vertragsfrist-Signal; D1-Schreibrecht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SupplierService::class);
    $this->agr = app(SupplierAgreementService::class);
    $this->lief = $this->svc->create($this->rootTeam, ['name' => 'Hanos']);
});

it('aggregiert Stammblatt: Status, Konditionen, Kontakt, Absprache, Dokument, WG-Abdeckung', function () {
    $this->svc->setStatus($this->rootTeam, $this->lief->id, 'zweitquelle');
    $this->svc->updateConditions($this->rootTeam, $this->lief->id, ['rebate_pct' => 3.0, 'payment_term_days' => 30, 'min_order_value' => 250]);
    $this->svc->addContact($this->rootTeam, $this->lief->id, ['name' => 'Frau Meier', 'role' => 'KAM', 'email' => 'kam@hanos.test']);
    $this->agr->create($this->rootTeam, $this->lief->id, ['type' => 'zusage', 'note' => '3 % Bonus ab 500 €', 'follow_up_at' => now()->addDays(5)->toDateString()]);
    $this->agr->addDocument($this->rootTeam, $this->lief->id, ['kind' => 'vertrag', 'term_end' => now()->addDays(200)->toDateString(), 'notice_period_days' => 90]);

    $sb = $this->svc->stammblatt($this->rootTeam, $this->lief->id);

    expect($sb['status'])->toBe('zweitquelle')
        ->and($sb['is_owned'])->toBeTrue()
        ->and($sb['konditionen']['rebate_pct'])->toBe(3.0)
        ->and($sb['konditionen']['payment_term_days'])->toBe(30)
        ->and($sb['kontakte'])->toHaveCount(1)
        ->and($sb['kontakte'][0]['name'])->toBe('Frau Meier')
        ->and($sb['absprachen'])->toHaveCount(1)
        ->and($sb['absprachen'][0]['note'])->toBe('3 % Bonus ab 500 €')
        ->and($sb['dokumente'])->toHaveCount(1)
        ->and($sb['dokumente'][0]['notice_deadline'])->toBe(now()->addDays(200)->subDays(90)->toDateString());
});

it('Vertragsfrist-Signal feuert für ablaufende Kündigungsfrist, nicht für ferne', function () {
    // Deadline in der Vergangenheit (term_end +20, Frist 30 → deadline -10), Vertrag läuft noch → im Fenster.
    $this->agr->addDocument($this->rootTeam, $this->lief->id, ['kind' => 'vertrag', 'term_end' => now()->addDays(20)->toDateString(), 'notice_period_days' => 30]);
    // Ferne Frist: term_end +400, Frist 10 → deadline +390 → außerhalb 30-Tage-Fenster.
    $this->agr->addDocument($this->rootTeam, $this->lief->id, ['kind' => 'rahmenvereinbarung', 'term_end' => now()->addDays(400)->toDateString(), 'notice_period_days' => 10]);

    $faellig = $this->agr->documentsDueForNotice($this->rootTeam, 30);
    expect($faellig)->toHaveCount(1);

    $n = app(SignalDetektorService::class)->vertragsfristFaellig($this->rootTeam, 30);
    expect($n)->toBe(1)
        ->and(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('type', SignalTyp::VertragsfristFaellig->value)->count())->toBe(1);
});

it('Beziehungs-Pflege nur durch das Besitzer-Team (D1)', function () {
    // childA sieht den geerbten Root-Lieferanten, darf ihn aber nicht bearbeiten.
    expect(fn () => $this->svc->setStatus($this->childA, $this->lief->id, 'gesperrt'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});
