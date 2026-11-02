<?php

use Platform\FoodAlchemist\Services\Trends\FeedLeser;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 93 Folge · WordPress-Fußzeile im Feed-Teaser („The post X appeared first on Y.“, deutsch „Der Beitrag X erschien
 * zuerst auf Y.“): weg, wenn sie der letzte Satz ist — sonst landet sie in Anzeige und Erschließen-Prompt. Anlass: erster
 * Abruf von Rolling Pin auf io (02.11.2026). Feed erfunden, keine echte Seite.
 */
function spec93FusszeileFeed(array $beschreibungen): array
{
    $items = '';
    foreach ($beschreibungen as $i => $b) {
        $items .= '<item><title>Artikel '.$i.' TEST-93</title><link>https://fachblatt.beispiel.test/artikel/'.$i.'/</link>'
            .'<description><![CDATA['.$b.']]></description></item>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Beispiel</title>'.$items.'</channel></rss>';

    return array_column(app(FeedLeser::class)->feed($xml, 'https://fachblatt.beispiel.test/'), 'teaser');
}

it('entfernt die WordPress-Fußzeile am Teaser-Ende, englisch und deutsch, auch mit Links und Seitennamen mit Punkt', function () {
    $teaser = spec93FusszeileFeed([
        '<p>Haubis zeigt neue Brote.</p><p>The post <a href="https://x.test/a">Haubis auf der GAST</a> appeared first on <a href="https://x.test">Rolling Pin</a>.</p>',
        'Bowls aus der Region. Der Beitrag <a href="https://x.test/b">Bowls im Catering</a> erschien zuerst auf foodservice.de.',
        'Kombucha vom Fass. The post Kombucha appeared first on Beispiel Magazin',
    ]);

    expect($teaser)->toBe(['Haubis zeigt neue Brote.', 'Bowls aus der Region.', 'Kombucha vom Fass.']);
});

it('lässt die Floskel mitten im Text stehen und macht einen Teaser nur aus Fußzeile zu null', function () {
    $teaser = spec93FusszeileFeed([
        'Zitat: The post Bowls appeared first on Rolling Pin. Danach geht der Artikel weiter.',
        'The post <a href="https://x.test/c">Nur Titel</a> appeared first on <a href="https://x.test">Rolling Pin</a>.',
        'Ein ganz normaler Teaser ohne Anhang.',
    ]);

    expect($teaser)->toBe(['Zitat: The post Bowls appeared first on Rolling Pin. Danach geht der Artikel weiter.', null, 'Ein ganz normaler Teaser ohne Anhang.']);
});
