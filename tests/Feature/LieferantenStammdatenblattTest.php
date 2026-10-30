<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController;
use Platform\FoodAlchemist\Livewire\Preislisten\LieferantenStammdatenblatt as Panel;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLinkEreignis as Ereignis;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierDeliveryArea;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierDocument;
use Platform\FoodAlchemist\Services\LieferantenLinkService;
use Platform\FoodAlchemist\Services\LieferantenStammdatenblattService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\LieferantenStammdatenblattEntscheidenTool;
use Platform\FoodAlchemist\Tools\LieferantenStammdatenblattGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Lieferanten-Link, Stufe 2d (Kurator 30.10.2026): Stammdatenblatt als Vorschlag in der Prüfliste, Whitelist, globale Lieferanten nur Master. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    config(['foodalchemist.master_team_id' => $this->team->id]);
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->links = app(LieferantenLinkService::class);
    $this->svc = app(LieferantenStammdatenblattService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos', 'rebate_pct' => 7.5, 'payment_term_days' => 14]);
    $this->link = $this->links->erzeugen($this->team, $this->admin, $this->hanos);
    $this->blatt = [
        'stamm' => ['legal_name' => 'Hanos GmbH', 'order_cutoff_time' => '14:30', 'delivery_days' => '1, 3,5', 'min_order_value' => '150,00', 'order_channels' => ['email', 'portal', 'quatsch'],
            'complaint_path' => 'Per E-Mail an reklamation@hanos.test'],
        'kontakt' => ['disposition' => ['name' => 'Frau Beispiel', 'phone' => '0211 1', 'mobile' => '0170 1', 'email' => 'dispo@hanos.test'], 'vertrieb' => ['name' => '']],
        'gebiet' => [['name' => 'Düsseldorf', 'postal_codes' => '40-42', 'delivery_days' => '2,4', 'delivery_von' => '6:00', 'delivery_bis' => '10:00', 'order_cutoff_time' => '12:00', 'lead_days' => '1']],
        'zertifikat' => ['cert_type' => 'IFS', 'cert_number' => 'IFS-1', 'issuer' => 'TÜV', 'term_end' => '31.12.2027'],
    ];
    $this->pdf = fn () => UploadedFile::fake()->createWithContent('ifs.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
    $this->hoch = fn (array $eingabe, ?UploadedFile $datei = null, ?string $token = null) => app(LieferantenPortalController::class)->stammdatenblatt(
        $token ?? $this->link['token'], Request::create('/l/x/stammdatenblatt', 'POST', $eingabe, [], $datei ? ['zertifikat_datei' => $datei] : [], ['REMOTE_ADDR' => '203.0.113.9']),
        $this->links, $this->svc);
});

it('Einreichen: je Datensatz eine Prüflisten-Zeile mit Vorher/Nachher, der Bestand bleibt unberührt', function () {
    $r = ($this->hoch)($this->blatt, ($this->pdf)());

    $lauf = Lauf::first();
    $zeilen = Zeile::where('run_id', $lauf->id)->orderBy('row_nr')->get();
    expect($r->getSession()->get('portal_meldung'))->toContain('angekommen')
        ->and($lauf->art)->toBe(Lauf::ART_LIEFERANT)->and($lauf->herkunft)->toBe(Lauf::HERKUNFT_LIEFERANTEN_LINK)->and($lauf->link_id)->toBe($this->link['link']->id)
        ->and($zeilen->pluck('daten.typ')->all())->toBe(['stamm', 'kontakt', 'gebiet', 'zertifikat'])
        ->and($zeilen[0]->diff['order_cutoff_time'])->toBe([null, '14:30'])->and($zeilen[0]->daten['werte']['delivery_days'])->toBe('1,3,5')
        ->and($zeilen[0]->daten['werte']['order_channels'])->toBe(['email', 'portal'])   // „quatsch“ fliegt raus
        ->and($zeilen[0]->daten['werte']['min_order_value'])->toEqual(150)
        ->and($zeilen[2]->daten['werte']['delivery_von'])->toBe('06:00')
        ->and($this->hanos->fresh()->legal_name)->toBeNull()->and(FoodAlchemistSupplierContact::count())->toBe(0)
        ->and(FoodAlchemistSupplierDeliveryArea::count())->toBe(0)->and(FoodAlchemistSupplierDocument::count())->toBe(0)
        ->and(Ereignis::where('art', 'upload')->count())->toBe(1);
});

it('Eingabe wird geprüft: falsche E-Mail/Uhrzeit/Tage/Datum/Betrag werden abgelehnt, nichts angelegt; leere Eingabe = nichts geändert', function () {
    $fall = [
        ['stamm' => ['email_order' => 'keine-mail']], ['stamm' => ['order_cutoff_time' => '25:99']], ['stamm' => ['delivery_days' => '9']], ['stamm' => ['min_order_value' => 'viel']],
        ['stamm' => ['homepage' => 'javascript:alert(1)']], ['zertifikat' => ['cert_type' => 'IFS', 'term_end' => '31.02.2027']], ['zertifikat' => ['cert_type' => 'IFS']],
        ['kontakt' => ['vertrieb' => ['phone' => '1']]], ['gebiet' => [['postal_codes' => '40']]],
    ];
    foreach ($fall as $e) {
        expect(($this->hoch)($e)->getSession()->get('portal_fehler'))->not->toBeNull();
    }
    expect(($this->hoch)(['stamm' => ['legal_name' => '']])->getSession()->get('portal_fehler'))->toContain('nichts geändert')->and(Lauf::count())->toBe(0)
        ->and(Ereignis::where('art', 'abgelehnt')->count())->toBe(count($fall) + 1);
});

it('Whitelist: Rückvergütung, Status, Rolle & Co. kommen nie in eine Zeile, Formeln werden neutralisiert', function () {
    ($this->hoch)(['stamm' => ['legal_name' => '=HYPERLINK("http://x")', 'rebate_pct' => '99', 'status' => 'gesperrt', 'rolle' => 'x', 'is_inactive' => '1', 'payment_term_days' => '30']]);

    $z = Zeile::first();
    expect(array_keys($z->daten['werte']))->toBe(['legal_name', 'payment_term_days'])->and($z->daten['werte']['legal_name'])->toStartWith("'=");

    // manipulierte Zeile: beim Übernehmen gilt die Whitelist trotzdem
    $z->forceFill(['daten' => ['typ' => 'stamm', 'werte' => ['legal_name' => 'Neu GmbH', 'rebate_pct' => 99, 'status' => 'gesperrt', 'is_inactive' => true]]])->save();
    $b = $this->svc->entscheide($this->team, $this->admin, [$z->id], 'uebernehmen');
    $h = $this->hanos->fresh();
    expect($b['uebernommen'])->toBe(1)->and($h->legal_name)->toBe('Neu GmbH')->and((float) $h->rebate_pct)->toBe(7.5)->and($h->status->value)->toBe('aktiv')->and($h->is_inactive)->toBeFalse();
});

it('Übernehmen: Stammdaten, Kontakt, Liefergebiet (team_id-gebunden) und Zertifikat mit Datei landen im Bestand, Zeilen und Lauf werden abgeschlossen', function () {
    ($this->hoch)($this->blatt, ($this->pdf)());
    $ids = Zeile::pluck('id')->all();

    $b = $this->svc->entscheide($this->team, $this->admin, $ids, 'uebernehmen');

    $h = $this->hanos->fresh();
    $k = FoodAlchemistSupplierContact::first();
    $g = FoodAlchemistSupplierDeliveryArea::first();
    $d = FoodAlchemistSupplierDocument::first();
    expect($b)->toMatchArray(['uebernommen' => 4, 'verworfen' => 0, 'fehler' => []])
        ->and($h->legal_name)->toBe('Hanos GmbH')->and($h->order_cutoff_time)->toBe('14:30')->and($h->delivery_days)->toBe('1,3,5')->and($h->order_channels)->toBe(['email', 'portal'])
        ->and((float) $h->min_order_value)->toBe(150.0)
        ->and($k->rolle_key)->toBe('disposition')->and($k->name)->toBe('Frau Beispiel')->and($k->mobile)->toBe('0170 1')->and($k->team_id)->toBe($this->team->id)
        ->and($g->name)->toBe('Düsseldorf')->and($g->team_id)->toBe($this->team->id)->and($g->delivery_von)->toBe('06:00')
        ->and($d->kind)->toBe('zertifikat')->and($d->cert_type)->toBe('IFS')->and($d->term_end->toDateString())->toBe('2027-12-31')->and($d->context_file_id)->not->toBeNull()
        ->and(Lauf::first()->status)->toBe('applied')->and(Storage::disk('local')->allFiles('fa-lieferanten-dokumente'))->toBe([])
        ->and(Ereignis::where('art', 'stammdatenblatt_uebernommen')->count())->toBe(4);
    expect($this->svc->offene($this->team))->toHaveCount(0);

    // zweiter Lauf: gleicher Kontakt/Gebiet/Zertifikat ⇒ „ändert“ statt Dublette
    ($this->hoch)(['kontakt' => ['disposition' => ['name' => 'Herr Neu']], 'gebiet' => [['name' => 'düsseldorf', 'lead_days' => '2']], 'zertifikat' => ['cert_type' => 'ifs', 'cert_number' => 'IFS-1', 'term_end' => '31.12.2028']]);
    $this->svc->entscheide($this->team, $this->admin, Zeile::where('entscheidung', 'offen')->pluck('id')->all(), 'uebernehmen');
    expect(FoodAlchemistSupplierContact::count())->toBe(1)->and(FoodAlchemistSupplierContact::first()->name)->toBe('Herr Neu')
        ->and(FoodAlchemistSupplierDeliveryArea::count())->toBe(1)->and(FoodAlchemistSupplierDeliveryArea::first()->lead_days)->toBe(2)
        ->and(FoodAlchemistSupplierDocument::count())->toBe(1)->and(FoodAlchemistSupplierDocument::first()->term_end->toDateString())->toBe('2028-12-31');
});

it('Verwerfen schreibt nichts und löscht die Zertifikat-Datei', function () {
    ($this->hoch)($this->blatt, ($this->pdf)());
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    $b = $this->svc->entscheide($this->team, $this->admin, Zeile::pluck('id')->all(), 'verwerfen');

    expect($b['verworfen'])->toBe(4)->and($this->hanos->fresh()->legal_name)->toBeNull()->and(FoodAlchemistSupplierContact::count())->toBe(0)
        ->and(FoodAlchemistSupplierDocument::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([])->and(Lauf::first()->status)->toBe('verworfen');
});

it('Zertifikat-Datei: nur PDF/JPG/PNG, aktive PDFs abgelehnt, dann kein Lauf', function () {
    $boese = UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n<< /S /JavaScript /JS (x) >>\n");
    expect(($this->hoch)($this->blatt, $boese)->getSession()->get('portal_fehler'))->toContain('aktiven Inhalten')->and(Lauf::count())->toBe(0);
    expect(($this->hoch)($this->blatt, UploadedFile::fake()->createWithContent('x.exe', 'MZ'))->getSession()->get('portal_fehler'))->toContain('Dateiformat');
});

it('GLOBALER Lieferant: Kunden-Team bekommt keinen Link, ENTSCHEIDEN ist für ein Nicht-Master-Team FORBIDDEN, das Master-Team darf', function () {
    $global = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Global-Stamm']);
    $kunde = $this->makeUser($this->childA, 'Kunde', 'owner');
    expect(fn () => $this->links->erzeugen($this->childA, $kunde, $global))->toThrow(FaRechtFehltException::class);

    $gl = $this->links->erzeugen($this->team, $this->admin, $global);   // Root = Master
    ($this->hoch)(['stamm' => ['legal_name' => 'Global GmbH']], null, $gl['token']);
    $z = Zeile::first();
    expect($z->supplier_id)->toBe($global->id);

    config(['foodalchemist.master_team_id' => $this->childA->id]);   // Root ist jetzt NICHT mehr Master
    expect(fn () => $this->svc->entscheide($this->team, $this->admin, [$z->id], 'uebernehmen'))->toThrow(FaRechtFehltException::class)
        ->and($global->fresh()->legal_name)->toBeNull();
    $r = (new LieferantenStammdatenblattEntscheidenTool)->execute(['zeilen_ids' => [$z->id], 'entscheidung' => 'uebernehmen'], new ToolContext($this->admin, $this->team));
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');

    config(['foodalchemist.master_team_id' => $this->team->id]);
    expect($this->svc->entscheide($this->team, $this->admin, [$z->id], 'uebernehmen')['uebernommen'])->toBe(1)->and($global->fresh()->legal_name)->toBe('Global GmbH');
});

it('Rechte und Mandanten: Betrachter FORBIDDEN, fremdes Team sieht und übernimmt nichts; Bereich „formular“ nicht freigegeben ⇒ abgelehnt; Widerruf ⇒ 410', function () {
    ($this->hoch)($this->blatt);
    $leser = $this->makeUser($this->team, 'Leser', 'viewer');
    expect(fn () => $this->svc->entscheide($this->team, $leser, Zeile::pluck('id')->all(), 'uebernehmen'))->toThrow(FaRechtFehltException::class);

    $fremd = \Platform\Core\Models\Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremder = $this->makeUser($fremd, 'Fremd', 'owner');
    expect($this->svc->offene($fremd))->toHaveCount(0)->and($this->svc->entscheide($fremd, $fremder, Zeile::pluck('id')->all(), 'uebernehmen')['uebernommen'])->toBe(0)
        ->and($this->hanos->fresh()->legal_name)->toBeNull();

    $ohne = $this->links->erzeugen($this->team, $this->admin, $this->hanos, ['bereiche' => ['preisliste']]);
    expect(($this->hoch)($this->blatt, null, $ohne['token'])->getSession()->get('portal_fehler'))->toContain('nicht freigegeben');
    $this->links->widerrufen($this->team, $this->admin, $this->link['link']);
    expect(($this->hoch)($this->blatt)->getStatusCode())->toBe(410);
});

it('Portal-Seite: Formular mit Vorbelegung, aber ohne Rückvergütung, Status oder Interna', function () {
    $this->hanos->update(['legal_name' => 'Hanos Vorbelegt', 'status' => 'zweitquelle']);
    $html = app(LieferantenPortalController::class)->show($this->link['token'], Request::create('/l/x', 'GET'), $this->links)->getContent();

    expect($html)->toContain('data-portal-upload="stammdatenblatt"')->toContain('Hanos Vorbelegt')->toContain('name="kontakt[disposition][name]"')
        ->not->toContain('rebate')->not->toContain('Rückvergütung')->not->toContain('7.5')->not->toContain('zweitquelle');
});

it('Panel und MCP: Vorher/Nachher + Badge, Übernehmen per Klick; GET/ENTSCHEIDEN; Betrachter FORBIDDEN', function () {
    ($this->hoch)($this->blatt);
    Livewire::test(Panel::class)->assertSeeHtml('data-lieferanten-stammdatenblatt')->assertSeeHtml('data-lauf-vom-lieferanten')->assertSee('Bestellschluss')->assertSee('14:30')
        ->call('entscheiden', Zeile::where('row_nr', 1)->value('id'), 'uebernehmen')->assertSee('Angaben übernommen');
    expect($this->hanos->fresh()->legal_name)->toBe('Hanos GmbH');

    $ctx = new ToolContext($this->admin, $this->team);
    $liste = (new LieferantenStammdatenblattGetTool)->execute([], $ctx);
    expect($liste->success)->toBeTrue()->and($liste->data['vorschlaege'])->toHaveCount(3)->and($liste->data['vorschlaege'][0]['aenderungen'])->not->toBeEmpty();
    $leser = $this->makeUser($this->team, 'Leser2', 'viewer');
    expect((new LieferantenStammdatenblattGetTool)->execute([], new ToolContext($leser, $this->team))->success)->toBeFalse();
    $r = (new LieferantenStammdatenblattEntscheidenTool)->execute(['zeilen_ids' => array_column($liste->data['vorschlaege'], 'zeile_id'), 'entscheidung' => 'uebernehmen'], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['uebernommen'])->toBe(3);
});
