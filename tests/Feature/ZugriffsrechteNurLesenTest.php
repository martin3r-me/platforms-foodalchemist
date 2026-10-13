<?php

use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Zugriffsrechte;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 A: Bereiche und Kontingente werden NUR in der Plattform-Verwaltung (/verwaltung) gepflegt. Die FA-Seite
 * Einstellungen → Zugriffsrechte zeigt sie nur lesend; die Team-Dinge (Rollen, „darf Rechnungen freigeben“, Bereiche je Mitglied) bleiben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber Root', 'owner');
    $this->koch = $this->makeUser($this->rootTeam, 'Koch', 'member');
    $this->rechte = app(FaRechte::class);
});

it('Zugriffsrechte zeigt Bereiche und Kontingente nur lesend (gebucht, genutzt), auch dem Plattform-Admin; Link zur Verwaltung nur für ihn', function () {
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->admin, 'lager', false);
    $this->rechte->setzeKontingente($this->rootTeam, $this->admin, ['max_user' => 7]);

    $this->actingAs($this->admin);
    $html = Livewire::test(Zugriffsrechte::class)->assertSeeHtml('data-rechte-bereiche')->assertSeeHtml('data-rechte-verwaltungs-hinweis')->assertSeeHtml('data-rechte-verwaltung-link')
        ->assertSee('Gepflegt in der Plattform-Verwaltung')->assertSee('gebucht: 7')->assertSee('genutzt:')->html();
    expect($html)->toContain('data-team-bereich="lager" data-aktiv="0"')->toContain('data-team-bereich="foodbook" data-aktiv="1"')
        ->not->toContain('data-kontingente-speichern')->not->toMatch('/<input[^>]*data-team-bereich/')->not->toMatch('/<input[^>]*kontingentForm/');

    $this->actingAs($this->inhaber);
    Livewire::test(Zugriffsrechte::class)->assertSeeHtml('data-rechte-verwaltungs-hinweis')->assertDontSeeHtml('data-rechte-verwaltung-link')->assertSee('gebucht: 7');
});

it('Speichern von Bereichen und Kontingenten aus der FA-Seite ist nicht mehr möglich, auch nicht per Hand-Aufruf', function () {
    $this->actingAs($this->admin);
    $vorher = $this->rechte->teamBereiche($this->rootTeam);

    foreach ([['teamBereichSetzen', ['lager', false]], ['kontingenteSpeichern', []]] as [$methode, $args]) {
        expect(fn () => Livewire::test(Zugriffsrechte::class)->call($methode, ...$args))->toThrow(MethodNotFoundException::class);
    }
    expect(method_exists(Zugriffsrechte::class, 'teamBereichSetzen'))->toBeFalse()->and(method_exists(Zugriffsrechte::class, 'kontingenteSpeichern'))->toBeFalse()
        ->and(property_exists(Zugriffsrechte::class, 'kontingentForm'))->toBeFalse();
    expect($this->rechte->teamBereiche($this->rootTeam))->toBe($vorher)->and($this->rechte->kontingente($this->rootTeam)['max_user'])->toBeNull();
});

it('Die eine Stelle bleibt: der Dienst, den die Verwaltung und das MCP nutzen, schaltet Bereiche und setzt Kontingente (nur Plattform-Admin)', function () {
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->admin, 'lager', false);
    $this->rechte->setzeKontingente($this->rootTeam, $this->admin, ['max_standorte' => 2]);
    expect($this->rechte->teamBereiche($this->rootTeam)['lager'])->toBeFalse()->and($this->rechte->kontingente($this->rootTeam)['max_standorte'])->toBe(2);

    expect(fn () => $this->rechte->setzeTeamBereich($this->rootTeam, $this->inhaber, 'lager', true))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class)
        ->and(fn () => $this->rechte->setzeKontingente($this->rootTeam, $this->inhaber, ['max_standorte' => 9]))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
});

it('Die Team-Dinge bleiben auf der FA-Seite: Rollen-Erklärung, Haken „darf Rechnungen freigeben“, Bereiche je Mitglied einschränken', function () {
    $this->actingAs($this->inhaber);

    Livewire::test(Zugriffsrechte::class)->assertSee('Was die Rollen im Food Alchemist dürfen')->assertSeeHtml('data-recht-haken="'.$this->koch->id.'"')
        ->assertSeeHtml('data-recht-bereiche-knopf="'.$this->koch->id.'"')
        ->call('bereicheUmschalten', $this->koch->id)->assertSeeHtml('data-recht-bereiche="'.$this->koch->id.'"')
        ->call('userBereichSetzen', $this->koch->id, 'lager', false)->assertSet('fehler', null);
    expect($this->rechte->userSperren($this->rootTeam, $this->koch->id))->toContain('lager');
});
