<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser as WissenBrowser;
use Platform\FoodAlchemist\Livewire\Trendradar\Index as TrendradarIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Teil 8 · Wissen und Trendradar am Handy. Wissen: nur aktive Dossiers in der Team-Sicht, lesbar (Tabellen im Rahmen,
 * [[Verweise]] als Text), ohne KI. Trendradar: Trends gruppiert, Pinnwand, „Fundstück ablegen“ als bewusste Ausnahme vom
 * Lesemodus über den vorhandenen Weg, Vorbelegung per URL (Teilen), alles andere gesperrt.
 */
function amHandyTeil8(string $klasse, array $query = [])
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->withQueryParams($query)->test($klasse);
}

describe('Wissen', function () {
    beforeEach(function () {
        $this->seedTeamHierarchy();
        config(['foodalchemist.knowledge_master_regel' => true]);
        $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
        config(['foodalchemist.master_team_id' => $this->master->id]);
        MasterTeam::flush();
        $this->kunde = Team::create(['name' => 'Broich.Catering', 'user_id' => 1, 'personal_team' => false]);
        $this->doc = function (?int $teamId, string $slug, string $md, bool $aktiv = true): int {
            DB::table('foodalchemist_knowledge_documents')->insert([
                'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'title' => 'Titel ' . $slug,
                'category' => 'zutat', 'content_md' => $md, 'version' => 1, 'content_hash' => hash('sha256', $slug . $md),
                'char_count' => mb_strlen($md), 'active' => $aktiv, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id');
        };
        $this->eigen = ($this->doc)($this->kunde->id, 'zutat.kunde_tomate',
            "---\ntyp: zutat\n---\n# Titel zutat.kunde_tomate\nTomaten mögen [[07_WISSEN/Saeure|Säure]] und [[Basilikum]].\n\n| Sorte | Säure | Süße | Verwendung |\n|---|---|---|---|\n| San Marzano | mittel | hoch | Sauce |\n");
        $this->inaktiv = ($this->doc)($this->kunde->id, 'zutat.kunde_alt', "Alt", false);
        $this->masterDoc = ($this->doc)($this->master->id, 'zutat.master_geheim', "Master-Wissen");
        $this->global = ($this->doc)(null, 'zutat.global_geheim', "Globales Wissen");
        $this->actingAs($this->makeUser($this->kunde, 'Kunde'));
    });

    it('Liste am Handy: nur eigene aktive Dossiers (kein Master-/globales Wissen, nichts Inaktives), keine Pflege', function () {
        amHandyTeil8(WissenBrowser::class)
            ->assertSeeHtml('data-fa-handy-wissen')
            ->assertSeeHtml('data-fa-handy-karte="' . $this->eigen . '"')
            ->assertDontSeeHtml('data-fa-handy-karte="' . $this->inaktiv . '"')
            ->assertDontSee('Titel zutat.master_geheim')->assertDontSee('Titel zutat.global_geheim')
            ->assertDontSeeHtml('data-wissen-liste')->assertDontSeeHtml('data-wissen-inhalt');
    });

    it('Dossier am Handy: Markdown wie am Computer, Tabelle im Scroll-Rahmen, [[Verweise]] als Text, Zurück zur Liste', function () {
        $c = amHandyTeil8(WissenBrowser::class)->call('select', $this->eigen)
            ->assertSeeHtml('data-fa-handy-dossier="' . $this->eigen . '"')
            ->assertSeeHtml('<div class="overflow-x-auto max-w-full" data-fa-tabelle-rahmen><table>')
            ->assertSee('Säure')->assertSee('Basilikum')->assertDontSee('[[')
            ->assertDontSee('typ: zutat');                                     // YAML-Kopf nicht im Text
        expect(substr_count($c->html(), 'Titel zutat.kunde_tomate'))->toBe(1);   // doppelte H1 entfällt wie am Computer
        $c->call('handyZurueck')->assertSet('selectedId', null)->assertSeeHtml('data-fa-handy-karte="' . $this->eigen . '"');
    });

    it('Inaktives oder fremdes Dossier per Link: nicht verfügbar statt Inhalt', function () {
        amHandyTeil8(WissenBrowser::class, ['doc' => $this->inaktiv])->assertSee('Dossier nicht verfügbar')->assertDontSee('Alt');
        amHandyTeil8(WissenBrowser::class)->call('select', $this->masterDoc)->assertDontSee('Master-Wissen');
    });

    it('Suche am Handy ohne KI: auch mit Semantik-Schalter kein Embedding-Aufruf', function () {
        $this->mock(KnowledgeEmbeddingService::class)->shouldNotReceive('searchEligibleDocIds');
        amHandyTeil8(WissenBrowser::class)->set('semantic', true)->set('search', 'Tomaten')
            ->assertSeeHtml('data-fa-handy-karte="' . $this->eigen . '"');
    });

    it('Sperre am Handy: Öffnen und Blättern laufen, Pflege nicht; am Computer unverändert', function () {
        request()->cookies->set(HandyLesemodus::COOKIE, '1');
        foreach (['select', 'handyZurueck', 'gotoPage', 'nextPage'] as $m) {
            expect(HandyLesemodus::abgewiesen(new WissenBrowser, $m))->toBeFalse($m);
        }
        foreach (['neu', 'save', 'toggleActive', 'delete', 'addAlias', 'removeAlias', 'kanonAdd', 'kanonRemove', 'previewKnowledge'] as $m) {
            expect(HandyLesemodus::abgewiesen(new WissenBrowser, $m))->toBeTrue($m);
        }
        request()->cookies->set(HandyLesemodus::COOKIE, '0');

        amHandyTeil8(WissenBrowser::class)->call('select', $this->eigen)->call('toggleActive', $this->eigen)->call('delete', $this->eigen);
        expect((bool) DB::table('foodalchemist_knowledge_documents')->where('id', $this->eigen)->value('active'))->toBeTrue()
            ->and(DB::table('foodalchemist_knowledge_documents')->where('id', $this->eigen)->value('deleted_at'))->toBeNull();

        Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(WissenBrowser::class)
            ->assertSeeHtml('data-wissen-liste')->assertDontSeeHtml('data-fa-handy-wissen');
    });
});

describe('Trendradar', function () {
    beforeEach(function () {
        $this->seedTeamHierarchy();
        Storage::fake('public');
        Storage::fake('local');
        $this->svc = app(TrendService::class);
        $this->a = $this->makeUser($this->childA, 'Koch A', 'member');
        $this->actingAs($this->a);
        $this->trendA = $this->svc->anlegen($this->childA, ['name' => 'Loaded Mash NUR-A', 'definition' => 'Kartoffelpüree als Hauptgericht', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food'], $this->a->id);
        $this->trendB = $this->svc->anlegen($this->childA, ['name' => 'Alkoholfreie Pairings NUR-A', 'typ' => 'trend', 'ebene' => 'konsum', 'kategorie' => 'getraenke'], $this->a->id);
        $this->fundA = $this->svc->fundstueckAblegen($this->childA, ['titel' => 'Lolli-Fund NUR-A', 'url' => 'https://www.instagram.com/p/lolli'], null, $this->a->id);
    });

    it('Trends am Handy: Karten nach Ebene, umschaltbar nach Kategorie, Detail lesbar; kein Radar, keine Pflege', function () {
        amHandyTeil8(TrendradarIndex::class)
            ->assertSeeHtml('data-fa-handy-trends')
            ->assertSeeHtml('data-fa-handy-trend-gruppe="mode"')->assertSeeHtml('data-fa-handy-trend-gruppe="konsum"')
            ->assertSee('Loaded Mash NUR-A')->assertDontSeeHtml('data-trend-punkt')
            ->set('handyGruppe', 'kategorie')
            ->assertSeeHtml('data-fa-handy-trend-gruppe="food"')->assertSeeHtml('data-fa-handy-trend-gruppe="getraenke"')
            ->call('select', $this->trendA->id)
            ->assertSeeHtml('data-fa-handy-trend="' . $this->trendA->id . '"')->assertSee('Kartoffelpüree als Hauptgericht')
            ->assertDontSee('Jetzt messen')->assertDontSeeHtml('data-trend-beleg-form')->assertDontSee('Einordnen')
            ->call('deselect')->assertSeeHtml('data-fa-handy-karte="' . $this->trendA->id . '"');
    });

    it('Pinnwand am Handy: Fundstück-Karten und Ablegen mit Bild + Link + Notiz über den vorhandenen Weg', function () {
        $c = amHandyTeil8(TrendradarIndex::class)->set('handyReiter', 'pinnwand')
            ->assertSeeHtml('data-fa-handy-inspiration="' . $this->fundA->inspiration_id . '"')->assertSee('Lolli-Fund NUR-A')
            ->call('$set', 'handyAblegenOffen', true)
            ->assertSeeHtml('accept="image/*"')->assertDontSeeHtml('capture=')   // Kamera ODER Galerie
            ->set('fundDatei', UploadedFile::fake()->image('handyfoto.jpg', 1200, 900))
            ->set('fund.url', 'https://www.instagram.com/p/mash')
            ->set('fund.notiz', 'Auf der Messe gesehen')
            ->call('fundstueckAblegen')
            ->assertSet('fehler', null)->assertSeeHtml('data-fa-handy-ablegen-ok');

        $beleg = FoodAlchemistTrendBeleg::where('url', 'https://www.instagram.com/p/mash')->first();
        expect($beleg)->not->toBeNull()
            ->and($beleg->team_id)->toBe($this->childA->id)->and((bool) $beleg->fundstueck)->toBeTrue()
            ->and($beleg->notiz)->toBe('Auf der Messe gesehen')
            ->and($beleg->context_file_id)->not->toBeNull()->and($beleg->datei_mime)->toBe('image/jpeg')
            ->and($beleg->inspiration_id)->not->toBeNull();
    });

    it('Ablegen am Handy: abgelehnter Dateityp → Meldung, nichts gespeichert', function () {
        $vorher = [FoodAlchemistTrendBeleg::count(), FoodAlchemistTrendInspiration::count()];
        amHandyTeil8(TrendradarIndex::class)->set('handyReiter', 'pinnwand')->call('$set', 'handyAblegenOffen', true)
            ->set('fundDatei', UploadedFile::fake()->create('trojaner.exe', 10, 'application/x-msdownload'))
            ->set('fund.titel', 'Bitte nicht')
            ->call('fundstueckAblegen')
            ->assertSet('fehler', fn ($f) => is_string($f) && str_contains($f, 'Bild'));
        expect([FoodAlchemistTrendBeleg::count(), FoodAlchemistTrendInspiration::count()])->toBe($vorher);
    });

    it('Vorbelegung per URL (Teilen): Pinnwand + Formular offen, nur http(s)-Link, Text bereinigt, nichts gespeichert', function () {
        $vorher = FoodAlchemistTrendBeleg::count();
        amHandyTeil8(TrendradarIndex::class, ['ablegen' => 1, 'link' => 'https://www.instagram.com/p/geteilt', 'text' => "<b>Toll</b>\x07 gesehen", 'titel' => str_repeat('x', 300)])
            ->assertSet('handyReiter', 'pinnwand')->assertSet('handyAblegenOffen', true)
            ->assertSet('fund.url', 'https://www.instagram.com/p/geteilt')
            ->assertSet('fund.notiz', 'Toll  gesehen')
            ->assertSet('fund.titel', str_repeat('x', 255))
            ->assertSeeHtml('data-fa-handy-ablegen');
        amHandyTeil8(TrendradarIndex::class, ['ablegen' => 1, 'link' => 'javascript:alert(1)'])->assertSet('fund.url', '');
        expect(FoodAlchemistTrendBeleg::count())->toBe($vorher);

        Livewire::withCookie(HandyLesemodus::COOKIE, '0')->withQueryParams(['ablegen' => 1, 'link' => 'https://x.test/a'])->test(TrendradarIndex::class)
            ->assertSet('fund.url', '');   // am Computer keine Vorbelegung (dort das Modal)
    });

    it('Sperre am Handy: nur Lesen und Ablegen; Löschen, Einordnen, Status, Messen, Belege, Zuordnen bleiben gesperrt', function () {
        request()->cookies->set(HandyLesemodus::COOKIE, '1');
        foreach (['select', 'deselect', 'resetFilter', 'fundstueckAblegen', '_startUpload', '_finishUpload'] as $m) {
            expect(HandyLesemodus::abgewiesen(new TrendradarIndex, $m))->toBeFalse($m);
        }
        foreach (['erfassen', 'belegHinzufuegen', 'belegEntfernen', 'einordnenStarten', 'einordnungSpeichern', 'statusSetzen', 'loeschen', 'messen',
            'inspirationZuordnen', 'inspirationLoesen', 'inspirationLoeschen', 'zusammenfuehren', 'trendAusInspiration', 'inPlanungOeffnen', 'fundstueckOeffnen'] as $m) {
            expect(HandyLesemodus::abgewiesen(new TrendradarIndex, $m))->toBeTrue($m);
        }
        request()->cookies->set(HandyLesemodus::COOKIE, '0');

        amHandyTeil8(TrendradarIndex::class)->call('select', $this->trendA->id)
            ->call('statusSetzen', 'verworfen')->call('loeschen')->call('inspirationLoeschen', $this->fundA->inspiration_id);
        expect($this->trendA->fresh()->status)->not->toBe('verworfen')
            ->and(FoodAlchemistTrendInspiration::find($this->fundA->inspiration_id))->not->toBeNull();
    });

    it('Team-Sicht: ein fremder Kunde sieht am Handy weder Trends noch Fundstücke von Team A', function () {
        $fremd = Team::create(['name' => 'Fremder Kunde', 'user_id' => 1, 'personal_team' => false]);
        $this->actingAs($this->makeUser($fremd, 'Koch Fremd', 'member'));
        amHandyTeil8(TrendradarIndex::class)
            ->assertDontSee('Loaded Mash NUR-A')
            ->call('select', $this->trendA->id)->assertSee('Trend nicht verfügbar')->assertDontSee('Kartoffelpüree')
            ->call('deselect')->set('handyReiter', 'pinnwand')->set('pinnAnsicht', 'alle')->assertDontSee('Lolli-Fund NUR-A');
    });

    it('Geschwister-Standort: Pinnwand der Kundenfamilie wie am Computer (fundstueckFamilie), Trends von A nicht', function () {
        $this->actingAs($this->makeUser($this->childB, 'Koch B', 'member'));
        amHandyTeil8(TrendradarIndex::class)
            ->assertDontSee('Loaded Mash NUR-A')
            ->set('handyReiter', 'pinnwand')->set('pinnAnsicht', 'alle')->assertSee('Lolli-Fund NUR-A');
        expect(app(TrendService::class)->inspirationen($this->childB, 'alle')->pluck('id')->all())->toContain($this->fundA->inspiration_id);   // Desktop-Regel
    });

    it('Am Computer unverändert: Seite mit Radar-Reitern und Ablege-Dialog', function () {
        Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(TrendradarIndex::class)
            ->assertSeeHtml('data-tab-inspiration')->assertDontSeeHtml('data-fa-handy-trends');
    });
});
