<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KapitelLogoService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Support\KapitelBildDaten;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Präsentation 2026-11-13 — Kapitelbild-Darstellung (Fotoband | Ganz zeigen | automatisch nach Transparenz) und
 * optionales Logo je Kapitel für Foodbook, Angebot und Speisekarte, in Oberfläche und per MCP.
 */
function kblPng(bool $alpha): string
{
    $bild = imagecreatetruecolor(8, 8);
    if ($alpha) {
        imagesavealpha($bild, true);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 0, 0, 0, 127));
    }
    ob_start();
    imagepng($bild);

    return (string) ob_get_clean();
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Logo');
    $this->actingAs($this->user);
    Storage::fake('public');
    $this->pres = app(PresentationService::class);

    // Kapitel mit Inhalt (wie PresentationChapterGalleryTest), damit es sicher im Snapshot steht.
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
    $dish = $this->makeRecipe($this->rootTeam, 'Lachs', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs', 'position' => 1]);
    $fb = $this->makeFoodbook($this->rootTeam, 'Katalog', ['personen' => 6]);
    $this->kap = $this->makeChapter($fb, ['title' => 'Partner', 'consumer_title' => 'Partner', 'position' => 1]);
    $this->makeFoodbookBlock($this->kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);
    $this->fb = $fb;
    $this->snap = fn () => $this->pres->hydrateImages($this->pres->buildSnapshot($this->rootTeam, $this->fb->refresh(), 'foodbook', ['expires_at' => now()->addDays(30)->toDateString()]));
    $this->html = function (array $snap, array $stil = []) {
        $snap['resolved_design']['layout'] = [['block_type' => 'chapter_loop', 'style' => ['show_price' => false] + $stil]];

        return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
    };
});

it('erkennt Alpha-Kanal in PNG und WebP, Fotos ohne Alpha nicht', function () {
    expect(KapitelBildDaten::kopfHatAlpha(kblPng(true)))->toBeTrue()
        ->and(KapitelBildDaten::kopfHatAlpha(kblPng(false)))->toBeFalse()
        ->and(KapitelBildDaten::kopfHatAlpha('kein bild'))->toBeFalse();

    if (function_exists('imagewebp')) {
        $bild = imagecreatetruecolor(8, 8);
        imagesavealpha($bild, true);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 0, 0, 0, 127));
        ob_start();
        imagewebp($bild);
        expect(KapitelBildDaten::kopfHatAlpha((string) ob_get_clean()))->toBeTrue();
    }
});

it('automatisch: transparentes Kapitelbild wird ganz gezeigt, Foto bleibt Fotoband; Design kann beides erzwingen', function () {
    Storage::disk('public')->put('foodalchemist/chapter/logo.png', kblPng(true));
    $this->kap->update(['image_path' => 'foodalchemist/chapter/logo.png']);

    $snap = ($this->snap)();
    expect($snap['content']['sections'][0]['image']['transparent'] ?? false)->toBeTrue();
    expect(($this->html)($snap))->toContain('pt-section-img--ganz');
    expect(($this->html)($snap, ['chapter_image_fit' => 'fotoband']))->not->toContain('pt-section-img--ganz"');

    Storage::disk('public')->put('foodalchemist/chapter/foto.png', kblPng(false));
    $this->kap->update(['image_path' => 'foodalchemist/chapter/foto.png']);
    $foto = ($this->snap)();
    expect($foto['content']['sections'][0]['image']['transparent'] ?? false)->toBeFalse();
    expect(($this->html)($foto))->not->toMatch('/class="pt-section-img[^"]*pt-section-img--ganz/');
    expect(($this->html)($foto, ['chapter_image_fit' => 'ganz']))->toMatch('/class="pt-section-img[^"]*pt-section-img--ganz/');
});

it('Kapitel-Logo: setzen, im Snapshot und unten im Kapitel gerendert, oben wählbar, abschaltbar, entfernen', function () {
    $svc = app(KapitelLogoService::class);
    $svc->setzen($this->rootTeam, 'foodbook', $this->kap->id, UploadedFile::fake()->image('partner.png', 200, 80));
    expect($this->kap->refresh()->logo_context_file_id ?? $this->kap->logo_path)->not->toBeNull();

    $snap = ($this->snap)();
    expect($snap['content']['sections'][0]['logo']['url'] ?? null)->not->toBeNull();
    expect(($this->html)($snap))->toContain('pt-section-logo pt-section-logo--unten');
    expect(($this->html)($snap, ['chapter_logo_position' => 'oben']))->toContain('pt-section-logo pt-section-logo--oben');
    expect(($this->html)($snap, ['show_chapter_logo' => false]))->not->toContain('<img class="pt-section-logo');

    $svc->entfernen($this->rootTeam, 'foodbook', $this->kap->id);
    expect(($this->snap)()['content']['sections'][0]['logo'] ?? null)->toBeNull();
});

it('Entfernen löscht eine geteilte Logo-Datei erst, wenn kein Kapitel sie mehr nutzt', function () {
    Storage::disk('public')->put('foodalchemist/chapter/geteilt.png', kblPng(true));
    $zweit = $this->makeChapter($this->fb, ['title' => 'Kopie', 'position' => 2]);
    $this->kap->update(['logo_path' => 'foodalchemist/chapter/geteilt.png']);
    $zweit->update(['logo_path' => 'foodalchemist/chapter/geteilt.png']);

    app(KapitelLogoService::class)->entfernen($this->rootTeam, 'foodbook', $this->kap->id);
    expect(Storage::disk('public')->exists('foodalchemist/chapter/geteilt.png'))->toBeTrue();

    app(KapitelLogoService::class)->entfernen($this->rootTeam, 'foodbook', $zweit->id);
    expect(Storage::disk('public')->exists('foodalchemist/chapter/geteilt.png'))->toBeFalse();
});

it('Speisekarte: Logo je Rubrik landet im Snapshot', function () {
    $karten = app(SpeisekarteService::class);
    $karte = $karten->create($this->rootTeam, ['name' => 'Abendkarte']);
    $rubrik = $karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Fisch']);
    Storage::disk('public')->put('foodalchemist/menu_card_section/fisch.png', kblPng(true));
    $rubrik->update(['logo_path' => 'foodalchemist/menu_card_section/fisch.png']);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $karte->refresh(), 'speisekarte', ['expires_at' => now()->addDays(30)->toDateString()]);
    $sek = collect($snap['content']['sections'])->firstWhere('anker', 'r' . $rubrik->id);
    expect($sek['logo']['path'] ?? null)->toBe('foodalchemist/menu_card_section/fisch.png');
});

it('MCP: bilder.UPLOAD ziel=kapitel_logo setzt und entfernt; falsche Art → VALIDATION_ERROR', function () {
    $tool = app(ToolRegistry::class)->get('foodalchemist.bilder.UPLOAD');
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $falsch = $tool->execute(['ziel' => 'kapitel_logo', 'id' => $this->kap->id, 'kapitel_art' => 'konzept',
        'datei' => ['base64' => base64_encode(kblPng(true)), 'name' => 'l.png']], $ctx);
    expect($falsch->success)->toBeFalse()->and($falsch->errorCode)->toBe('VALIDATION_ERROR');

    $ok = $tool->execute(['ziel' => 'kapitel_logo', 'id' => $this->kap->id, 'kapitel_art' => 'foodbook',
        'datei' => ['base64' => base64_encode(kblPng(true)), 'name' => 'l.png']], $ctx);
    expect($ok->success)->toBeTrue();
    $k = $this->kap->refresh();
    expect($k->logo_context_file_id ?: $k->logo_path)->not->toBeNull();

    $weg = $tool->execute(['ziel' => 'kapitel_logo', 'id' => $this->kap->id, 'kapitel_art' => 'foodbook', 'entfernen' => true], $ctx);
    expect($weg->success)->toBeTrue();
    $k = $this->kap->refresh();
    expect($k->logo_context_file_id)->toBeNull()->and($k->logo_path)->toBeNull();

    $text = $tool->getDescription() . json_encode($tool->getSchema(), JSON_UNESCAPED_UNICODE);
    expect($text)->toContain('kapitel_logo')->toContain('kapitel_art')->toContain('entfernen');
});

it('MCP: presentation_designs nimmt Kapitelbild-Darstellung und Logo-Schalter an und prüft sie', function () {
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $id = (int) $registry->get('foodalchemist.presentation_designs.POST')->execute(['name' => 'Logo-Design', 'base_slug' => 'editorial'], $ctx)->data['id'];

    $stil = ['chapter_image_fit' => 'ganz', 'show_chapter_logo' => true, 'chapter_logo_position' => 'oben'];
    $put = $registry->get('foodalchemist.presentation_designs.PUT')->execute(['id' => $id, 'layout_json' => [['block_type' => 'chapter_loop', 'style' => $stil]]], $ctx);
    expect($put->success)->toBeTrue();
    $get = $registry->get('foodalchemist.presentation_designs.GET')->execute(['id' => $id], $ctx);
    expect($get->data['layout_json'][0]['style'])->toBe($stil);

    $bad = $registry->get('foodalchemist.presentation_designs.PUT')->execute(['id' => $id, 'layout_json' => [['block_type' => 'chapter_loop',
        'style' => ['chapter_image_fit' => 'riesig', 'chapter_logo_position' => 'links', 'show_chapter_logo' => 'ja']]]], $ctx);
    expect($bad->success)->toBeFalse()->and($bad->errorCode)->toBe('VALIDATION_ERROR')
        ->and($bad->error)->toContain('chapter_image_fit')->toContain('chapter_logo_position')->toContain('show_chapter_logo');

    $doku = $registry->get('foodalchemist.presentation_designs.PUT')->getDescription() . json_encode($registry->get('foodalchemist.presentation_designs.PUT')->getSchema(), JSON_UNESCAPED_UNICODE);
    expect($doku)->toContain('chapter_image_fit')->toContain('chapter_logo_position')->toContain('show_chapter_logo');
});
