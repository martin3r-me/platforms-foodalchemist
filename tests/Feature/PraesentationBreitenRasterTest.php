<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Präsentation 2026-11-13: ein gemeinsames Breiten-Raster (Seitenbreite, Lesebreite, Text links/zentriert),
 * Überschriften-Hierarchie, Titelbild-Abdunkelung + freie Titelzeile, Gerichtsliste-Schalter.
 */
function breitenSnap(array $tokens = [], array $cover = [], array $kapitel = []): array
{
    $items = [
        ['kind' => 'text', 'title' => 'NOSE TO TAIL', 'label' => 'Vom ganzen Tier gedacht.'],
        ['kind' => 'header', 'label' => 'Nose to Tail'],
        ['kind' => 'gericht', 'label' => 'Graved Karotte | Dillcreme'],
    ];

    return [
        'type' => 'angebot', 'title' => 'Green & Tasty', 'meta' => [], 'settings' => [],
        'branding' => ['cover' => ['url' => 'https://example.test/cover.jpg']],
        'content' => ['sections' => [['title' => 'Die Speisen', 'depth' => 0, 'images' => [],
            'blocks' => [['kind' => 'concept', 'label' => 'Green & Tasty Konzept', 'items' => $items]]]]],
        'resolved_design' => [
            'layout' => [
                ['block_type' => 'cover', 'style' => $cover],
                ['block_type' => 'chapter_loop', 'style' => ['show_price' => false] + $kapitel],
                ['block_type' => 'text', 'style' => ['text' => 'Freier Text']],
            ],
            'tokens' => array_replace_recursive(['typography' => ['heading' => 'display-serif', 'body' => 'sans']], $tokens),
        ],
    ];
}

function breitenHtml(array $snap): string
{
    return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
}

it('Standard: normale Seitenbreite, Text links, gemeinsames Raster, bisheriger Titelbild-Verlauf', function () {
    $html = breitenHtml(breitenSnap());
    expect($html)
        ->toContain('--pt-wide: 1180px;')->toContain('--pt-lese: 68ch;')->toContain('--pt-h-faktor: 1;')
        ->toContain('pt-text-links')
        ->toContain('.pt-measure, .pt-wide { max-width: var(--pt-wide);')
        ->toContain('Kulinarisches Angebot')
        ->not->toContain('--pt-hero-dunkel:')
        ->toContain('Green &amp; Tasty Konzept')                  // Konzeptname standardmäßig sichtbar
        ->not->toContain(' pt-ohne-linien"');
});

it('Seitenbreite, Lesebreite, Text zentriert und Überschriften wirken', function () {
    $html = breitenHtml(breitenSnap([
        'page_width' => 'breit', 'text_width' => 'schmal', 'text_align' => 'zentriert',
        'typography' => ['heading_size' => 'zurueckhaltend', 'heading_weight' => 'leicht', 'heading_tracking' => 'eng'],
    ]));
    expect($html)
        ->toContain('--pt-wide: 1440px;')->toContain('--pt-lese: 58ch;')->toContain('pt-text-zentriert')
        ->toContain('--pt-h-faktor: 0.78;')->toContain('font-weight: 400;')->toContain('letter-spacing: -0.035em;');
});

it('Titelbild: Abdunkelung als Zahl (gedeckelt) und freie Zeile über dem Titel', function () {
    $html = breitenHtml(breitenSnap([], ['overlay' => 95, 'kicker_text' => 'Sommerfest · 450 Gäste']));
    expect($html)->toContain('--pt-hero-dunkel: 0.6;')->toContain('Sommerfest · 450 Gäste')->not->toContain('Kulinarisches Angebot</div>');

    // Freitext statt Zahl wird ignoriert (kein CSS-Freitext im öffentlichen Renderer).
    expect(breitenHtml(breitenSnap([], ['overlay' => 'red;background:url(x)'])))->not->toContain('--pt-hero-dunkel:');
});

it('Gerichtsliste: Trennlinien und Konzeptname abschaltbar, doppelte Stationsüberschrift nur einmal', function () {
    $html = breitenHtml(breitenSnap([], [], ['show_rules' => false, 'show_block_header' => false]));
    expect($html)->toContain(' pt-ohne-linien"')->not->toContain('Green &amp; Tasty Konzept')
        ->toContain('NOSE TO TAIL')
        ->not->toContain('<h4 class="pt-subheader pt-indent-0">Nose to Tail');
});
