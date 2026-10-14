<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Verwaltung\Grundeinstellungen;
use Platform\FoodAlchemist\Livewire\Verwaltung\MandantBeenden;
use Platform\FoodAlchemist\Livewire\Verwaltung\MasterTeam as MasterTeamKomponente;
use Platform\FoodAlchemist\Livewire\Verwaltung\StammPaket;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * /verwaltung: Der Host (food-alchemist.de) zeichnet je FA-Komponente eine Karte mit Kopf („Food Alchemist: …“). Die Komponenten
 * zeichnen darin keinen zweiten Rahmen und keine zweite Überschrift (Erklärtext und Tabelle bleiben). Das Master-Team hat in der
 * Liste „Mandant beenden“ keinen Knopf, sondern den Hinweis „Master-Team“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    app()->instance(PlattformProtokoll::class, Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing());
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    $this->actingAs($this->admin);
});

it('Mandant beenden: das Master-Team steht in der Liste ohne Beenden-Knopf, mit Hinweis; Mandanten behalten den Knopf', function () {
    // Liste so, wie sie bei Dominique erschien: das Master-Team ist darin enthalten
    app()->instance(MandantenService::class, new class(app(FaRechte::class), app(PlattformProtokoll::class), $this->master, $this->soul) extends MandantenService {
        public function __construct(FaRechte $rechte, PlattformProtokoll $protokoll, private Team $m, private Team $s)
        {
            parent::__construct($rechte, $protokoll);
        }

        public function uebersicht(): array
        {
            return [['team_id' => (int) $this->m->id, 'name' => 'BHG.DIGITAL', 'status' => 'aktiv'], ['team_id' => (int) $this->s->id, 'name' => 'Soulkitchen', 'status' => 'aktiv']];
        }
    });

    $html = Livewire::test(MandantBeenden::class)->assertSeeHtml('data-mandant-beenden-zeile="'.$this->master->id.'"')->html();

    expect($html)->toContain('data-mandant-master-hinweis')->and($html)->toContain('Master-Team')
        ->and(substr_count($html, 'wire:click="vorschau('))->toBe(1);   // nur Soulkitchen hat den Knopf
    $masterZeile = preg_match('/data-mandant-beenden-zeile="'.$this->master->id.'".*?<\/tr>/s', $html, $m) ? $m[0] : '';
    expect($masterZeile)->not->toContain('wire:click="vorschau(')->and($masterZeile)->toContain('data-mandant-master-hinweis');
    $soulZeile = preg_match('/data-mandant-beenden-zeile="'.$this->soul->id.'".*?<\/tr>/s', $html, $m) ? $m[0] : '';
    expect($soulZeile)->toContain('wire:click="vorschau('.$this->soul->id.')"');
});

it('Die eingebetteten Karten zeichnen keinen inneren Rahmen und keine zweite Überschrift; Erklärtext und Tabelle bleiben', function () {
    $falle = [
        [MandantBeenden::class, 'Reihenfolge: Abos kündigen', 'data-mandant-beenden-tabelle', 'Mandant beenden'],
        [StammPaket::class, 'ohne Preise', 'data-stamm-paket-tabelle', 'Stamm-Paket je Mandant'],
        [MasterTeamKomponente::class, 'Der eine Kurator der Plattform', 'data-master-team-aktuell', 'Master-Team'],
    ];
    foreach ($falle as [$klasse, $erklaerung, $anker, $titel]) {
        $html = Livewire::test($klasse)->html();
        expect($html)->toContain($erklaerung)->toContain($anker)
            ->not->toContain('fa-surface')                          // kein innerer Rahmen
            ->not->toContain('<h3')                                 // keine zweite Überschrift
            ->and(substr_count($html, '>'.$titel.'<'))->toBe(0);    // der Titel steht im Kopf der Host-Karte, nicht doppelt
    }
    if (class_exists(Grundeinstellungen::class)) {
        $html = Livewire::test(Grundeinstellungen::class)->html();
        expect($html)->toContain('Startbestand für reine Team-Arbeit')->not->toContain('fa-surface')->not->toContain('<h3');
    }
});

it('Vorschau-Karten innerhalb der Komponente behalten ihren Rahmen (verschiedener Inhalt, kein Doppel)', function () {
    $html = Livewire::test(MandantBeenden::class)->call('vorschau', $this->soul->id)->html();

    expect($html)->toContain('data-mandant-beenden-vorschau-box')->toContain('fa-surface')->toContain('Beenden: Soulkitchen');
});
