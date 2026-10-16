<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\AktionspreisService;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Services\PreisVerlaufService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Tools\ArtikelAktionspreiseGetTool;
use Platform\FoodAlchemist\Tools\ArtikelAktionspreisePostTool;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Aktionspreise (Entscheid Dominique 16.10.2026): EIN Platz = Preis-Zeitachse foodalchemist_price_history mit status 'A';
 * reine Information (Einkaufspreis, Lead, Linie bleiben beim Normalpreis), am Artikel „Aktion bis …“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    Carbon::setTestNow('2026-11-10 12:00:00');
    $this->svc = app(AktionspreisService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => 'Zanderfilet', 'article_number' => '70012', 'qty' => 1, 'unit_code' => 'kg']);
    app(PriceService::class)->createFor($this->rootTeam, $this->la, 24.90);
    $this->datei = function (array $zeilen): string {
        $pfad = sys_get_temp_dir().'/fa_aktion_'.uniqid('', true).'.csv';
        file_put_contents($pfad, implode("\n", $zeilen)."\n");

        return $pfad;
    };
    $this->aktionen = fn () => DB::table('foodalchemist_price_history')->where('supplier_item_id', $this->la->id)->where('status', 'A')->orderBy('id')->get();
});

afterEach(function () {
    Carbon::setTestNow();
    foreach (glob(sys_get_temp_dir().'/fa_aktion_*') ?: [] as $p) {
        @unlink($p);
    }
});

it('Handeingabe: status A in der Preis-Zeitachse, ganztägig Berlin; aktiv nur im Zeitraum; EK bleibt beim Normalpreis', function () {
    $r = $this->svc->anlegen($this->rootTeam, $this->la, 19.90, '2026-11-01', '2026-11-30', 'manuell');

    $a = ($this->aktionen)()->sole();
    expect($r['status'])->toBe('neu')->and($a->quelle)->toBe('manuell')->and((int) $a->team_id)->toBe($this->rootTeam->id)
        ->and($a->status_valid_from)->toBe('2026-10-31 23:00:00')->and($a->valid_to)->toBe('2026-11-30 23:00:00')
        ->and($a->price_id)->toBeNull()->and($a->herkunft_schluessel)->toStartWith('manuell|')
        ->and((float) $this->svc->aktivFuer($this->rootTeam, $this->la)->price)->toBe(19.9)
        ->and($this->svc->aktivFuer($this->rootTeam, $this->la, Carbon::parse('2026-12-01 12:00:00')))->toBeNull()
        ->and((float) app(PriceService::class)->activeFor($this->la->id, $this->rootTeam)->price)->toBe(24.9);   // Kalkulation: Normalpreis
});

it('Datei: Aktion mit ab/bis wird geschrieben, zweiter Lauf nichts, geänderter Preis ersetzt (alte ausgeblendet), ohne bis nicht übernommen', function () {
    $import = app(FileArticleImportService::class);
    $kopf = 'Artikel-Nr;Bezeichnung;Preis;Preis-Status;Aktion ab;Aktion bis';

    $b1 = $import->importiere($this->rootTeam, $this->supplier->id, ($this->datei)([$kopf, '70012;Zanderfilet;19,90;Aktion;01.11.2026;30.11.2026']), apply: true);
    $b2 = $import->importiere($this->rootTeam, $this->supplier->id, ($this->datei)([$kopf, '70012;Zanderfilet;19,90;Aktion;01.11.2026;30.11.2026']), apply: true);
    $b3 = $import->importiere($this->rootTeam, $this->supplier->id, ($this->datei)([$kopf, '70012;Zanderfilet;18,50;Aktion;01.11.2026;30.11.2026']), apply: true);
    $b4 = $import->importiere($this->rootTeam, $this->supplier->id,
        ($this->datei)(['Artikel-Nr;Bezeichnung;Preis;Preis-Status', '70012;Zanderfilet;17,00;Aktion']), apply: true);

    $alle = ($this->aktionen)();
    expect($b1['preise']['aktion_neu'])->toBe(1)->and($b2['preise']['aktion_unveraendert'])->toBe(1)->and($b3['preise']['aktion_ersetzt'])->toBe(1)
        ->and($b4['preise']['aktion_nicht_uebernommen'])->toBe(1)
        ->and($alle)->toHaveCount(2)
        ->and($alle[0]->ausgeblendet_grund)->toBe('durch Datei ersetzt')->and((float) $alle[0]->ausgeblendet_preis)->toBe(19.9)
        ->and($alle[1]->ausgeblendet_at)->toBeNull()->and($alle[1]->quelle)->toBe('datei')
        ->and($alle[0]->herkunft_schluessel)->toBe($alle[1]->herkunft_schluessel)                              // Schlüssel ohne Preis
        ->and((float) $this->svc->aktivFuer($this->rootTeam, $this->la)->price)->toBe(18.5)
        ->and((float) app(PriceService::class)->activeFor($this->la->id, $this->rootTeam)->price)->toBe(24.9);   // EK unberührt
});

it('Sichtbarkeit und Rechte wie Teampreise: Unter-Team schreibt nicht, Geschwister sieht fremde Aktion nicht; Ausblenden beendet sie', function () {
    $this->svc->anlegen($this->rootTeam, $this->la, 19.90, '2026-11-01', '2026-11-30', 'manuell');

    expect(fn () => $this->svc->anlegen($this->childA, $this->la, 15.00, '2026-11-01', '2026-11-30', 'manuell'))
        ->toThrow(RuntimeException::class, 'Unter-Teams')
        ->and($this->svc->aktivFuer($this->childA, $this->la))->not->toBeNull();                         // erbt vom Oberteam

    $id = (int) $this->svc->aktivFuer($this->rootTeam, $this->la)->id;
    app(PreisVerlaufService::class)->ausblenden($this->rootTeam, $this->la, 'historie', $id, 'Erfassungsfehler', $this->user->id);
    expect($this->svc->aktivFuer($this->rootTeam, $this->la))->toBeNull();
});

it('MCP: POST legt an (bis Pflicht), GET liefert aktive Aktion und Liste mit Hinweis', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $ohne = app(ArtikelAktionspreisePostTool::class)->execute(['id' => $this->la->id, 'preis' => 19.9], $ctx);
    $post = app(ArtikelAktionspreisePostTool::class)->execute(['id' => $this->la->id, 'preis' => 19.9, 'ab' => '2026-11-01', 'bis' => '2026-11-30'], $ctx);
    $get = app(ArtikelAktionspreiseGetTool::class)->execute(['id' => $this->la->id], $ctx);

    expect($ohne->success)->toBeFalse()->and($post->data['status'])->toBe('neu')
        ->and($get->data['aktiv']['preis'])->toBe(19.9)->and($get->data['aktionen'])->toHaveCount(1)
        ->and($get->data['hinweis'])->toContain('Normalpreis')
        ->and(app(ArtikelAktionspreiseGetTool::class)->getMetadata()['read_only'])->toBeTrue();
});

it('Oberfläche: Artikel-Modal legt eine Aktion an und zeigt „Aktion … bis …“; Artikelliste trägt aktion_bis', function () {
    Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)->call('bearbeitenStarten')
        ->set('aktionNeu', ['price' => '19,90', 'ab' => '2026-11-01', 'bis' => '2026-11-30'])->call('aktionAnlegen')
        ->assertSet('fehler', null)->assertSeeHtml('data-aktion-hinweis')->assertSee('30.11.2026');

    $zeile = collect(app(\Platform\FoodAlchemist\Services\SupplierItemService::class)
        ->paginateForSupplier($this->rootTeam, $this->supplier->id, [], 25)->items())->firstWhere('id', $this->la->id);
    expect($zeile->aktion_bis)->toBe('2026-11-30 23:00:00');
});
