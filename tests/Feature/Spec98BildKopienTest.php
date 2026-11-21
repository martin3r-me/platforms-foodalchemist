<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bild-Löschschutz (CQ PR 2): Foodbook- und Speisekarten-Duplikate bekommen eigene Bilddateien (kopiereBild) statt Verweisen
 * auf die Dateien der Quelle — Kopf, Kapitelbild, Kapitel-Galerie und Rubrik-Logo. Damit gehört die Kopie ganz dem
 * kopierenden Team, auch bei geerbten Foodbooks, und Löschen an der Kopie berührt die Quelle nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Kopie'));
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $this->fbs = app(FoodbookService::class);
    $this->bild = fn (string $n) => UploadedFile::fake()->image($n, 40, 30);
});

it('Foodbook-Kopie: Logo, Titelbild, Kapitelbild und Galerie sind eigene Dateien des Ziel-Teams', function () {
    $quelle = $this->makeFoodbook($this->rootTeam, 'Katalog');
    $k = $this->makeChapter($quelle, ['title' => 'Vorspeisen']);
    $this->fbs->storeLogo($this->rootTeam, $quelle->id, ($this->bild)('logo.png'));
    $this->fbs->storeCover($this->rootTeam, $quelle->id, ($this->bild)('cover.png'));
    $this->fbs->setKapitelImage($this->rootTeam, $k->id, ($this->bild)('hero.png'));
    $this->fbs->addKapitelGalleryImage($this->rootTeam, $k->id, ($this->bild)('g1.png'));
    $this->fbs->addKapitelGalleryImage($this->rootTeam, $k->id, ($this->bild)('g2.png'));
    $quelle->refresh();
    $k->refresh();

    $kopie = $this->fbs->dupliziere($this->rootTeam, $quelle->id);
    $kk = FoodAlchemistFoodbookKapitel::where('foodbook_id', $kopie->id)->firstOrFail();

    foreach ([[$kopie, $quelle, 'logo_context_file_id', 'logo_path'], [$kopie, $quelle, 'cover_context_file_id', 'cover_image_path'],
        [$kk, $k, 'image_context_file_id', 'image_path']] as [$neu, $alt, $cf, $pfad]) {
        expect($neu->{$cf})->not->toBeNull()->and($neu->{$cf})->not->toBe($alt->{$cf})
            ->and($neu->{$pfad})->not->toBe($alt->{$pfad})
            ->and(Storage::disk('public')->exists($neu->{$pfad}))->toBeTrue();
    }
    $galerie = FoodAlchemistFoodbookChapterImage::where('chapter_id', $kk->id)->orderBy('sort_order')->get();
    $quellGalerie = FoodAlchemistFoodbookChapterImage::where('chapter_id', $k->id)->pluck('path')->all();
    expect($galerie)->toHaveCount(2)
        ->and(array_intersect($galerie->pluck('path')->all(), $quellGalerie))->toBe([])
        ->and($galerie->every(fn ($g) => Storage::disk('public')->exists($g->path)))->toBeTrue();
});

it('geerbtes Foodbook kopieren, dann an der Kopie Bilder löschen: die Dateien des Masters bleiben', function () {
    $master = $this->makeFoodbook($this->rootTeam, 'Master-Katalog');
    $k = $this->makeChapter($master, ['title' => 'Hauptgang']);
    $this->fbs->storeLogo($this->rootTeam, $master->id, ($this->bild)('logo.png'));
    $this->fbs->setKapitelImage($this->rootTeam, $k->id, ($this->bild)('hero.png'));
    $this->fbs->addKapitelGalleryImage($this->rootTeam, $k->id, ($this->bild)('g1.png'));
    $master->refresh();
    $k->refresh();
    $mGalerie = FoodAlchemistFoodbookChapterImage::where('chapter_id', $k->id)->firstOrFail();

    $kopie = $this->fbs->dupliziere($this->childA, $master->id);
    expect((int) $kopie->team_id)->toBe((int) $this->childA->id)
        ->and((int) ContextFile::find($kopie->logo_context_file_id)->team_id)->toBe((int) $this->childA->id);

    $kk = FoodAlchemistFoodbookKapitel::where('foodbook_id', $kopie->id)->firstOrFail();
    $kGalerie = FoodAlchemistFoodbookChapterImage::where('chapter_id', $kk->id)->firstOrFail();
    $this->fbs->clearLogo($this->childA, $kopie->id);
    $this->fbs->clearKapitelImage($this->childA, $kk->id);
    $this->fbs->removeKapitelGalleryImage($this->childA, $kGalerie->id);

    // Kopie-Dateien sind weg, Master-Dateien und -Zeilen unberührt.
    expect(Storage::disk('public')->exists($kopie->logo_path))->toBeFalse()
        ->and(Storage::disk('public')->exists($kGalerie->path))->toBeFalse()
        ->and(Storage::disk('public')->exists($master->logo_path))->toBeTrue()
        ->and(ContextFile::find($master->logo_context_file_id))->not->toBeNull()
        ->and(Storage::disk('public')->exists($k->image_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($mGalerie->path))->toBeTrue()
        ->and($master->fresh()->logo_path)->toBe($master->logo_path);
});

it('Quelldatei fehlt: die Kopie bekommt kein Bild statt eines toten Verweises', function () {
    $quelle = $this->makeFoodbook($this->rootTeam, 'Alt', ['logo_path' => 'foodalchemist/branding/weg/logo.webp']);

    $kopie = $this->fbs->dupliziere($this->rootTeam, $quelle->id);

    expect($kopie->logo_path)->toBeNull()->and($kopie->logo_context_file_id)->toBeNull();
});

it('Speisekarten-Kopie: Logo, Titelbild und Rubrik-Logo sind eigene Dateien; Entfernen an der Kopie lässt die Quelle stehen', function () {
    $karten = app(SpeisekarteService::class);
    $quelle = $karten->create($this->rootTeam, ['name' => 'Abendkarte', 'karten_typ' => 'alacarte']);
    $rubrik = $karten->addRubrik($this->rootTeam, $quelle->id, ['title' => 'Hauptgänge']);
    $karten->storeLogo($this->rootTeam, $quelle->id, ($this->bild)('logo.png'));
    $karten->storeCover($this->rootTeam, $quelle->id, ($this->bild)('cover.png'));
    Storage::disk('public')->put('foodalchemist/menu_card_section/r/logo.webp', 'bild');
    $rubrik->forceFill(['logo_path' => 'foodalchemist/menu_card_section/r/logo.webp'])->save();
    $quelle->refresh();

    $kopie = $karten->dupliziere($this->rootTeam, $quelle->id);
    $kr = FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $kopie->id)->firstOrFail();

    expect($kopie->logo_path)->not->toBe($quelle->logo_path)->and($kopie->logo_context_file_id)->not->toBeNull()
        ->and($kopie->cover_image_path)->not->toBe($quelle->cover_image_path)
        ->and($kr->logo_path)->not->toBeNull()->and($kr->logo_path)->not->toBe('foodalchemist/menu_card_section/r/logo.webp')
        ->and(Storage::disk('public')->exists($kr->logo_path))->toBeTrue();

    $karten->clearLogo($this->rootTeam, $kopie->id);
    expect(Storage::disk('public')->exists($kopie->logo_path))->toBeFalse()
        ->and(Storage::disk('public')->exists($quelle->logo_path))->toBeTrue();
});
