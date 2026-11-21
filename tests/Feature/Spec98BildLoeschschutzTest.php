<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Support\BildVerweise;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bild-Löschschutz (CQ PR 1): Kopien und Fassungen teilen Bilddateien mit ihrer Quelle. Entfernen/Ersetzen an einer Zeile löscht
 * die Datei nur, wenn sie keine andere Zeile mehr nutzt (BildVerweise::REGISTER, alle Teams, gelöschte Zeilen zählen mit).
 * Geteilte Dateien entstehen seit PR 2 nicht mehr beim Duplizieren (eigene Kopien), stehen aber als Altbestand (Alt-Kopien) und
 * in Abo-Fassungen weiter in den Daten — die Tests legen solche Zeilen direkt an. Dazu zwei Wächter: jede Bild-Spalte steht im Register oder in den Ausnahmen; niemand löscht Bilder am Schutz vorbei.
 */
function bldDatei(int $teamId, string $pfad): ContextFile
{
    Storage::disk('public')->put($pfad, 'bild');

    return ContextFile::create([
        'token' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $teamId, 'context_type' => 'foodalchemist.foodbook', 'context_id' => 1,
        'disk' => 'public', 'path' => $pfad, 'file_name' => basename($pfad), 'original_name' => basename($pfad), 'mime_type' => 'image/webp',
        'file_size' => 4, 'keep_original' => false,
    ]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Bild'));
    Storage::fake('public');
    $this->fbs = app(FoodbookService::class);
});

it('Kopie: Logo an der Kopie entfernen lässt die Datei der Quelle stehen; erst die letzte Nutzung löscht', function () {
    Storage::disk('public')->put('foodalchemist/branding/1/logo.webp', 'bild');
    $quelle = $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_path' => 'foodalchemist/branding/1/logo.webp']);
    $kopie = $this->makeFoodbook($this->rootTeam, 'Katalog (Alt-Kopie)', ['logo_path' => 'foodalchemist/branding/1/logo.webp']);

    $this->fbs->clearLogo($this->rootTeam, $kopie->id);
    expect($kopie->refresh()->logo_path)->toBeNull()
        ->and(Storage::disk('public')->exists('foodalchemist/branding/1/logo.webp'))->toBeTrue();

    $this->fbs->clearLogo($this->rootTeam, $quelle->id);
    expect(Storage::disk('public')->exists('foodalchemist/branding/1/logo.webp'))->toBeFalse();
});

it('Kopie mit ContextFile: die Kopie trägt nur den Pfad — die Datei bleibt, bis die Quelle sie freigibt', function () {
    $datei = bldDatei($this->rootTeam->id, 'foodalchemist/branding/2/logo.webp');
    $quelle = $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_context_file_id' => $datei->id, 'logo_path' => $datei->path]);
    $kopie = $this->makeFoodbook($this->rootTeam, 'Katalog (Alt-Kopie)', ['logo_path' => $datei->path]);

    $this->fbs->clearLogo($this->rootTeam, $kopie->id);
    expect(Storage::disk('public')->exists($datei->path))->toBeTrue()
        ->and(ContextFile::find($datei->id))->not->toBeNull();

    $this->fbs->clearLogo($this->rootTeam, $quelle->id);
    expect(Storage::disk('public')->exists($datei->path))->toBeFalse()
        ->and(ContextFile::find($datei->id))->toBeNull();
});

it('gelöschte Kopie zählt mit: Wiederherstellen braucht die Datei', function () {
    Storage::disk('public')->put('foodalchemist/branding/3/logo.webp', 'bild');
    $quelle = $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_path' => 'foodalchemist/branding/3/logo.webp']);
    $this->makeFoodbook($this->rootTeam, 'Alt-Kopie', ['logo_path' => 'foodalchemist/branding/3/logo.webp'])->delete();   // soft delete

    $this->fbs->clearLogo($this->rootTeam, $quelle->id);
    expect(Storage::disk('public')->exists('foodalchemist/branding/3/logo.webp'))->toBeTrue();
});

it('veröffentlichter Snapshot hält die Datei, bis der Link neu veröffentlicht wird', function () {
    $datei = bldDatei($this->rootTeam->id, 'foodalchemist/branding/6/logo.webp');
    $fb = $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_context_file_id' => $datei->id, 'logo_path' => $datei->path]);
    // Snapshot wie beim Publish: nur Identifier, tief verschachtelt; Laravel-Cast schreibt escapte Slashes.
    $fb->forceFill(['presentation_snapshot_json' => ['branding' => ['logo' => ['context_file_id' => $datei->id, 'path' => $datei->path]]]])->save();

    $this->fbs->clearLogo($this->rootTeam, $fb->id);
    expect($fb->refresh()->logo_context_file_id)->toBeNull()
        ->and(Storage::disk('public')->exists($datei->path))->toBeTrue()
        ->and(ContextFile::find($datei->id))->not->toBeNull();

    // Republish ohne das Logo → die letzte Nutzung ist weg, das nächste Löschen räumt die Datei ab.
    $fb->forceFill(['presentation_snapshot_json' => ['branding' => ['logo' => null]]])->save();
    app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($datei->id, $datei->path, $this->rootTeam);
    expect(Storage::disk('public')->exists($datei->path))->toBeFalse();
});

it('Snapshot-Vorfilter trifft beide JSON-Schreibweisen (MySQL mit Leerzeichen, Pfad allein) und nicht Nachbar-IDs', function () {
    $media = app(FoodAlchemistMediaService::class);
    $fb = $this->makeFoodbook($this->rootTeam, 'Katalog');
    DB::table('foodalchemist_foodbooks')->where('id', $fb->id)
        ->update(['presentation_snapshot_json' => '{"cover": {"context_file_id": 4711, "path": "foodalchemist/branding/7/cover.webp"}}']);

    expect($media->wirdGenutzt(4711, ''))->toBeTrue()
        ->and($media->wirdGenutzt(null, 'foodalchemist/branding/7/cover.webp'))->toBeTrue()
        ->and($media->wirdGenutzt(471, ''))->toBeFalse()
        ->and($media->wirdGenutzt(47110, ''))->toBeFalse()
        ->and($media->wirdGenutzt(null, 'foodalchemist/branding/8/cover.webp'))->toBeFalse();
});

it('Fassung in einem anderen Team: Master entfernt bzw. ersetzt sein Bild, die Datei der Fassung bleibt', function () {
    Storage::disk('public')->put('foodalchemist/concept/9/titel.webp', 'bild');
    $master = $this->makeConcept($this->rootTeam, 'Menü', ['image_path' => 'foodalchemist/concept/9/titel.webp']);
    $fassung = $this->makeConcept($this->childB, 'Menü (Fassung)', ['image_path' => 'foodalchemist/concept/9/titel.webp']);
    $concepts = app(ConceptService::class);

    $concepts->clearImage($this->rootTeam, $master->id);
    expect($master->refresh()->image_path)->toBeNull()
        ->and(Storage::disk('public')->exists('foodalchemist/concept/9/titel.webp'))->toBeTrue();

    $master->update(['image_path' => 'foodalchemist/concept/9/titel.webp']);
    $neu = $concepts->storeImage($this->rootTeam, $master->id, UploadedFile::fake()->image('neu.png', 20, 20));
    expect($neu)->not->toBe('foodalchemist/concept/9/titel.webp')
        ->and(Storage::disk('public')->exists('foodalchemist/concept/9/titel.webp'))->toBeTrue()
        ->and($fassung->refresh()->image_path)->toBe('foodalchemist/concept/9/titel.webp');
});

it('Ersetzen: neues Logo über ein geteiltes lässt die alte Datei stehen, über ein ungeteiltes löscht es sie', function () {
    Storage::disk('public')->put('foodalchemist/branding/4/logo.webp', 'bild');
    $quelle = $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_path' => 'foodalchemist/branding/4/logo.webp']);
    $kopie = $this->makeFoodbook($this->rootTeam, 'Katalog (Alt-Kopie)', ['logo_path' => 'foodalchemist/branding/4/logo.webp']);

    $this->fbs->storeLogo($this->rootTeam, $kopie->id, UploadedFile::fake()->image('neu.png', 20, 20));
    expect($kopie->refresh()->logo_path)->not->toBe('foodalchemist/branding/4/logo.webp')
        ->and(Storage::disk('public')->exists('foodalchemist/branding/4/logo.webp'))->toBeTrue();

    $this->fbs->storeLogo($this->rootTeam, $quelle->id, UploadedFile::fake()->image('neu2.png', 20, 20));
    expect(Storage::disk('public')->exists('foodalchemist/branding/4/logo.webp'))->toBeFalse();
});

it('fremde, unbenutzte Datei: kein 403, nur ein Log-Eintrag', function () {
    $datei = bldDatei($this->rootTeam->id, 'foodalchemist/branding/5/logo.webp');
    $fb = $this->makeFoodbook($this->childA, 'Kind-Katalog', ['logo_context_file_id' => $datei->id, 'logo_path' => $datei->path]);
    Log::spy();

    $this->fbs->clearLogo($this->childA, $fb->id);

    expect($fb->refresh()->logo_context_file_id)->toBeNull()
        ->and(Storage::disk('public')->exists($datei->path))->toBeTrue()
        ->and(ContextFile::find($datei->id))->not->toBeNull();
    Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'anderen Team')
        && $ctx['context_file_id'] === $datei->id && $ctx['team_id'] === $this->childA->id)->once();
});

it('JSON-Verweise (Design-Bildblock) zählen; eigener Eintrag über $ausser nicht', function () {
    $id = DB::table('foodalchemist_presentation_designs')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->childB->id, 'name' => 'Kopie',
        'layout_json' => json_encode([['block_type' => 'image', 'style' => ['context_file_id' => 77, 'path' => 'foodalchemist/presentation_design/x.webp']]]),
    ]);
    $media = app(FoodAlchemistMediaService::class);

    expect($media->wirdGenutzt(77, ''))->toBeTrue()
        ->and($media->wirdGenutzt(null, 'foodalchemist/presentation_design/x.webp'))->toBeTrue()
        ->and($media->wirdGenutzt(78, 'anders.webp'))->toBeFalse()
        ->and($media->wirdGenutzt(77, '', [['tabelle' => 'foodalchemist_presentation_designs', 'id' => $id, 'spalte' => 'layout_json']]))->toBeFalse();
});

it('$ausser nimmt nur das eigene Spaltenpaar aus: dieselbe Datei im Titelbild derselben Zeile zählt weiter', function () {
    Storage::disk('public')->put('foodalchemist/branding/6/x.webp', 'bild');
    $fb = $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_path' => 'foodalchemist/branding/6/x.webp', 'cover_image_path' => 'foodalchemist/branding/6/x.webp']);

    $this->fbs->clearLogo($this->rootTeam, $fb->id);
    expect(Storage::disk('public')->exists('foodalchemist/branding/6/x.webp'))->toBeTrue();

    $this->fbs->clearCover($this->rootTeam, $fb->id);
    expect(Storage::disk('public')->exists('foodalchemist/branding/6/x.webp'))->toBeFalse()
        ->and(FoodAlchemistFoodbook::find($fb->id)->cover_image_path)->toBeNull();
});

it('Wächter: jede Bild-Spalte der foodalchemist_*-Tabellen steht im Register oder in den Ausnahmen', function () {
    $erfasst = array_keys(BildVerweise::AUSNAHMEN);
    $fehlendImSchema = array_values(array_filter($erfasst, fn ($s) => Schema::hasTable(explode('.', $s)[0])   // Tabelle fehlt im Test-Schema → nicht prüfbar
        && ! Schema::hasColumn(...explode('.', $s, 2))));
    foreach (BildVerweise::REGISTER as $tabelle => $verweise) {
        foreach ($verweise as $verweis) {
            foreach (isset($verweis['json']) ? [$verweis['json']] : $verweis as $spalte) {
                $erfasst[] = "{$tabelle}.{$spalte}";
                if (! Schema::hasColumn($tabelle, $spalte)) {
                    $fehlendImSchema[] = "{$tabelle}.{$spalte}";
                }
            }
        }
    }
    $offen = [];
    foreach (Schema::getTableListing(null, false) as $tabelle) {
        if (! str_starts_with($tabelle, 'foodalchemist_')) {
            continue;
        }
        foreach (Schema::getColumnListing($tabelle) as $spalte) {
            $bildartig = $spalte === 'context_file_id' || str_ends_with($spalte, '_context_file_id') || str_ends_with($spalte, '_path')
                || str_contains($spalte, 'image') || str_ends_with($spalte, 'pfad') || str_starts_with($spalte, 'bild_');
            if ($bildartig && ! in_array("{$tabelle}.{$spalte}", $erfasst, true)) {
                $offen[] = "{$tabelle}.{$spalte}";
            }
        }
    }

    expect($fehlendImSchema)->toBe([], 'Register-/Ausnahme-Spalte gibt es nicht im Schema')
        ->and($offen)->toBe([], 'Neue Bild-Spalte: in BildVerweise::REGISTER (wird kopiert/geteilt) oder AUSNAHMEN (mit Grund) eintragen');
});

it('Wächter: kein Bild wird am Löschschutz vorbei gelöscht (MediaService->delete nur in der Erlaubnisliste)', function () {
    $erlaubt = [
        'src/Services/FoodAlchemistMediaService.php',   // delete() selbst, gerufen aus loescheWennUnbenutzt()
        'src/Services/PresentationFontService.php',     // Schriftdateien je Team, nie geteilt (BildVerweise::AUSNAHMEN)
    ];
    $basis = dirname(__DIR__, 2);
    $treffer = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis.'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (! str_ends_with($f->getFilename(), '.php')) {
            continue;
        }
        $rel = substr($f->getPathname(), strlen($basis) + 1);
        foreach (file($f->getPathname()) as $i => $zeile) {
            if (preg_match('/(MediaService::class\)|->media|\$media)\s*->delete\(/', $zeile) === 1 && ! in_array($rel, $erlaubt, true)) {
                $treffer[] = $rel.':'.($i + 1);
            }
        }
    }

    expect($treffer)->toBe([], 'Bilder nur über FoodAlchemistMediaService::loescheWennUnbenutzt() löschen');
});
