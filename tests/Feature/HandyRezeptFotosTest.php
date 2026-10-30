<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetail;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel as GerichtDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Fotos am Handy (Dominique 30.10.): im Rezept-/Gericht-Detail ein Foto als Hauptbild, zu einem Arbeits- oder
 * Anrichte-Schritt aufnehmen — über dieselben Wege wie am Computer; Vorschritt verkleinert auf foodalchemist.media.max_kante.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $this->user = $this->makeUser($this->rootTeam, 'Koch Handy');
    $this->actingAs($this->user);
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Senfrostbraten HANDY');
    app(RecipeStepService::class)->sync($this->rezept, [['text' => 'Braten anbraten']]);
    app(RecipeStepService::class)->sync($this->rezept, [['text' => 'Auf Teller legen']], FoodAlchemistRecipeStep::EBENE_ANRICHTEN);
    $this->schritt = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->ebene(FoodAlchemistRecipeStep::EBENE_PRODUKTION)->firstOrFail();
    $this->anrichten = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->ebene(FoodAlchemistRecipeStep::EBENE_ANRICHTEN)->firstOrFail();
});

function handyFotoDetail(int $recipeId, string $klasse = RezeptDetail::class)
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test($klasse, ['recipeId' => $recipeId]);
}

/** JPEG mit EXIF-Orientation (6 = 90° im Uhrzeigersinn drehen), wie es Handykameras schreiben. */
function jpegMitOrientierung(int $breite, int $hoehe, int $orientierung): UploadedFile
{
    $gd = imagecreatetruecolor($breite, $hoehe);
    imagefilledrectangle($gd, 0, 0, intdiv($breite, 2), $hoehe, imagecolorallocate($gd, 200, 30, 30));
    ob_start();
    imagejpeg($gd, null, 80);
    $jpeg = (string) ob_get_clean();
    $tiff = "II*\x00" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientierung, 0) . pack('V', 0);
    $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\x00\x00" . $tiff;
    $pfad = tempnam(sys_get_temp_dir(), 'fa-exif-') . '.jpg';
    file_put_contents($pfad, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));

    return new UploadedFile($pfad, 'handy.jpg', 'image/jpeg', null, true);
}

it('Foto am Handy: als Hauptbild, zu einem Arbeitsschritt und zu einem Anrichte-Schritt — dieselben Wege wie am Computer', function () {
    handyFotoDetail($this->rezept->id)->assertSeeHtml('data-fa-handy-foto="hauptbild"')
        ->assertSeeHtml('data-fa-handy-foto="schritt:' . $this->schritt->id . '"')
        ->assertSeeHtml('data-fa-handy-foto="anrichten:' . $this->anrichten->id . '"')
        ->call('handyFotoZielWaehlen', 'hauptbild')->set('handyFoto', UploadedFile::fake()->image('haupt.jpg', 800, 600))
        ->assertSeeHtml('data-fa-handy-foto-speichern')->call('handyFotoSpeichern')->assertSet('handyFotoMeldung', 'Foto gespeichert.');
    expect($this->rezept->fresh()->image_context_file_id)->not->toBeNull();

    handyFotoDetail($this->rezept->id)->call('handyFotoZielWaehlen', 'schritt:' . $this->schritt->id)
        ->set('handyFoto', UploadedFile::fake()->image('schritt.jpg'))->set('handyFotoCaption', 'Kruste')->call('handyFotoSpeichern');
    handyFotoDetail($this->rezept->id)->call('handyFotoZielWaehlen', 'anrichten:' . $this->anrichten->id)
        ->set('handyFoto', UploadedFile::fake()->image('teller.jpg'))->call('handyFotoSpeichern');

    expect($this->schritt->fresh()->photos->pluck('caption')->all())->toBe(['Kruste'])
        ->and($this->anrichten->fresh()->photos)->toHaveCount(1)
        ->and(FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->rezept->id)->count())->toBe(2);
});

it('Gericht-Detail (Verkauf) am Handy: Foto als Hauptbild über denselben Weg', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Rinderfilet HANDY', ['is_sales_recipe' => true]);
    handyFotoDetail($gericht->id, GerichtDetail::class)->call('handyFotoZielWaehlen', 'hauptbild')
        ->set('handyFoto', UploadedFile::fake()->image('g.jpg'))->call('handyFotoSpeichern');
    expect($gericht->fresh()->image_context_file_id)->not->toBeNull();
});

it('fremdes (geerbtes) Rezept: kein Foto-Knopf, Speichern abgelehnt', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind-Koch'));
    handyFotoDetail($this->rezept->id)->assertDontSeeHtml('data-fa-handy-foto=')
        ->set('handyFoto', UploadedFile::fake()->image('x.jpg'))->call('handyFotoSpeichern')
        ->assertSet('handyFotoMeldung', 'Geerbtes Rezept: Fotos nur durchs Besitzer-Team.');
    expect($this->rezept->fresh()->image_context_file_id)->toBeNull();
});

it('gesperrtes Rezept (jemand anderes bearbeitet): Speichern abgelehnt; die eigene kurze Sperre wird danach freigegeben', function () {
    $anderer = $this->makeUser($this->rootTeam, 'Anderer Koch');
    app(BearbeitungssperreService::class)->sperren('recipe', $this->rezept->id, $anderer->id, 'Anderer Koch', $this->rootTeam->id);
    config(['foodalchemist.bearbeitungssperre' => true]);

    handyFotoDetail($this->rezept->id)->set('handyFoto', UploadedFile::fake()->image('x.jpg'))->call('handyFotoSpeichern')
        ->assertSet('handyFotoMeldung', 'Wird gerade von Anderer Koch bearbeitet. Bitte später noch einmal.');
    expect($this->rezept->fresh()->image_context_file_id)->toBeNull();

    app(BearbeitungssperreService::class)->freigeben('recipe', $this->rezept->id, $anderer->id);
    handyFotoDetail($this->rezept->id)->set('handyFoto', UploadedFile::fake()->image('x.jpg'))->call('handyFotoSpeichern');
    expect($this->rezept->fresh()->image_context_file_id)->not->toBeNull()
        ->and(app(BearbeitungssperreService::class)->fremd('recipe', $this->rezept->id, $anderer->id))->toBeNull();
});

it('andere Rezept-Methoden bleiben am Handy gesperrt; aus in der Config: Foto-Aufnehmen abgelehnt', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['handyFotoSpeichern', 'handyFotoZielWaehlen', '_startUpload'] as $m) {
        expect(HandyLesemodus::abgewiesen(new RezeptDetail, $m))->toBeFalse($m)
            ->and(HandyLesemodus::abgewiesen(new GerichtDetail, $m))->toBeFalse($m);
    }
    foreach (['duplizieren', 'neuBerechnen', 'loeschen', 'tauschen', 'hochAenderungenZuruecknehmen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new RezeptDetail, $m))->toBeTrue($m);
    }
    foreach (['reject_klasse', 'eignungVerwerfen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new GerichtDetail, $m))->toBeTrue($m);
    }
    config(['foodalchemist.handy.aendern_frei' => []]);
    foreach (['handyFotoSpeichern', '_startUpload'] as $m) {
        expect(HandyLesemodus::abgewiesen(new RezeptDetail, $m))->toBeTrue('aus: ' . $m);
    }
    request()->cookies->set(HandyLesemodus::COOKIE, '0');

    handyFotoDetail($this->rezept->id)->assertDontSeeHtml('data-fa-handy-foto=')
        ->set('handyFoto', UploadedFile::fake()->image('x.jpg'))->call('handyFotoSpeichern');
    expect($this->rezept->fresh()->image_context_file_id)->toBeNull();
});

it('12-MB-Foto wird angenommen, ein Nicht-Bild abgelehnt (Grenze 15 MB wie die Fundstücke)', function () {
    // Die Livewire-Upload-Grenze ist Host-Config (Standard ohne Eintrag: 12 MB). Hier wie im Ziel-Host auf 15 MB gesetzt,
    // damit der Test die FA-Regel (RezeptFotoService::MAX_KB) prüft.
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:15360']]);
    $gd = imagecreatetruecolor(400, 300);
    ob_start();
    imagejpeg($gd);
    $gross = UploadedFile::fake()->createWithContent('gross.jpg', ob_get_clean() . str_repeat("\x00", 12 * 1024 * 1024));   // Bytes nach dem JPEG-Ende ignorieren Decoder
    expect($gross->getSize())->toBeGreaterThan(12 * 1024 * 1024);

    handyFotoDetail($this->rezept->id)->set('handyFoto', $gross)
        ->call('handyFotoSpeichern')->assertHasNoErrors()->assertSet('handyFotoMeldung', 'Foto gespeichert.');

    handyFotoDetail($this->rezept->id)->set('handyFoto', UploadedFile::fake()->create('rezept.pdf', 100, 'application/pdf'))
        ->call('handyFotoSpeichern')->assertHasErrors(['handyFoto']);
});

it('Vorschritt: 4800×3600 → 3840×2880, 3000 px unverändert, EXIF-Drehung angewendet, Nicht-Bild unberührt', function () {
    $svc = app(FoodAlchemistMediaService::class);
    expect(FoodAlchemistMediaService::maxKante())->toBe(3840);

    $gross = $svc->verkleinert(UploadedFile::fake()->image('gross.jpg', 4800, 3600));
    expect(array_slice(getimagesize($gross->getRealPath()), 0, 2))->toBe([3840, 2880]);

    $mittel = UploadedFile::fake()->image('mittel.jpg', 3000, 2000);
    expect($svc->verkleinert($mittel))->toBe($mittel);

    // Hochkant fotografiert: Sensor 4800×3600, EXIF sagt „90° drehen“ → 2880×3840 (stehend)
    $gedreht = $svc->verkleinert(jpegMitOrientierung(4800, 3600, 6));
    expect(array_slice(getimagesize($gedreht->getRealPath()), 0, 2))->toBe([2880, 3840]);

    $pdf = UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
    expect($svc->verkleinert($pdf))->toBe($pdf);

    config(['foodalchemist.media.max_kante' => 2400]);
    expect(array_slice(getimagesize($svc->verkleinert(UploadedFile::fake()->image('g.jpg', 4800, 3600))->getRealPath()), 0, 2))->toBe([2400, 1800]);
});

it('Computer unverändert: der Schritt-Editor speichert über denselben Weg (Pool + Schritt), am Computer kein Foto-Knopf im Detail', function () {
    Livewire::test(StepEditor::class, ['recipeId' => $this->rezept->id])
        ->call('poolOeffnen', $this->schritt->id)
        ->set('fotoUpload', UploadedFile::fake()->image('schritt.jpg'))->set('fotoCaption', 'Brunoise')
        ->call('fotoHochladen')->assertSet('fehler', null);
    $foto = FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->rezept->id)->firstOrFail();
    expect($foto->caption)->toBe('Brunoise')->and($this->schritt->fresh()->photos->pluck('id')->all())->toBe([$foto->id])
        ->and(ContextFile::find($foto->context_file_id))->not->toBeNull();

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->rezept->id])
        ->assertDontSeeHtml('data-fa-handy-foto=');
});
