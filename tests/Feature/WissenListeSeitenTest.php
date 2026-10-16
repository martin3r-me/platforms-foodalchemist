<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Befund io 16.10.: /foodalchemist/wissen lud ohne Suche ALLE sichtbaren Dokumente (~14.250) als
 * Zeile — 15,5 MB HTML, 71.560 DOM-Knoten, ~26 s bis DOM-ready. Jetzt seitenweise (Browser::JE_SEITE).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();

    $zeilen = [];
    for ($i = 1; $i <= 250; $i++) {
        $titel = sprintf('Dossier %03d', $i);
        $zeilen[] = ['uuid' => (string) UuidV7::generate(), 'slug' => "dossier-{$i}", 'title' => $titel,
            'category' => $i <= 200 ? 'domain' : 'pairing', 'content_md' => $titel, 'version' => 1,
            'content_hash' => hash('sha256', $titel), 'char_count' => 10, 'active' => 1, 'team_id' => null,
            'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('foodalchemist_knowledge_documents')->insert($zeilen);
    $this->id = fn (int $i) => (int) DB::table('foodalchemist_knowledge_documents')->where('slug', "dossier-{$i}")->value('id');
    $this->zeilen = fn ($lw) => substr_count($lw->html(), 'data-wissen-doc=');
    $this->gesamt = DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at')->count();   // 250 + Grunddaten

    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('ohne Suche rendert die Liste höchstens eine Seite; der Zähler nennt alle Treffer', function () {
    $lw = Livewire::test(Browser::class);

    $n = number_format($this->gesamt, 0, ',', '.');
    expect($this->gesamt)->toBeGreaterThan(250)
        ->and(($this->zeilen)($lw))->toBe(Browser::JE_SEITE)                   // vorher: alle
        ->and($lw->html())->toContain("{$n} Dokumente")
        ->and($lw->html())->toContain('data-wissen-seiten')
        ->and($lw->html())->toContain("1 bis 100 von {$n}");
});

it('Seitenwechsel zeigt die nächsten Dokumente; ein Filter springt zurück auf Seite 1', function () {
    $lw = Livewire::test(Browser::class)->call('gotoPage', 3, Browser::SEITE);

    expect(($this->zeilen)($lw))->toBe($this->gesamt - 2 * Browser::JE_SEITE)
        ->and($lw->html())->not->toContain('Dossier 001');

    $lw->set('filterCategory', 'pairing');
    expect(($this->zeilen)($lw))->toBe(50)
        ->and($lw->html())->toContain('Dossier 201')
        ->and($lw->html())->toContain('50 Dokumente')
        ->and($lw->html())->not->toContain('data-wissen-seiten');             // eine Seite → keine Blätterung
});

it('ein ausgewähltes Dokument außerhalb der aktuellen Seite bleibt im Detail offen', function () {
    $lw = Livewire::test(Browser::class)->call('select', ($this->id)(240));

    expect($lw->get('selectedId'))->toBe(($this->id)(240))
        ->and($lw->html())->not->toContain('data-wissen-doc="'.($this->id)(240).'"')   // nicht auf Seite 1
        ->and($lw->get('form.title'))->toBe('Dossier 240');
});

it('die Suche bleibt ungeteilt (Limit des Suchdienstes, keine Blätterung)', function () {
    $lw = Livewire::test(Browser::class)->set('search', 'Dossier');

    expect($lw->html())->not->toContain('data-wissen-seiten')
        ->and(($this->zeilen)($lw))->toBeGreaterThan(0)->toBeLessThanOrEqual(100);
});
