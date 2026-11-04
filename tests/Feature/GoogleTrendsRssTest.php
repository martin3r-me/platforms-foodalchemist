<?php

use Platform\FoodAlchemist\Services\Trends\FeedLeser;
use Platform\FoodAlchemist\Support\StichwortFilter;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

it('Google-Trends-RSS erhält eigene Links, Starttag, Volumen und Food-Kontext ohne Bilder', function () {
    $xml = '<rss xmlns:ht="https://trends.google.com/trending/rss"><channel>'
        .'<item><title>Matcha</title><link>https://trends.google.com/trending/rss?geo=DE</link><description/>'
        .'<pubDate>Wed, 4 Nov 2026 07:00:00 +0100</pubDate><ht:approx_traffic>2000+</ht:approx_traffic>'
        .'<ht:news_item><ht:news_item_title>Neuer Getränketrend im Restaurant</ht:news_item_title></ht:news_item><ht:picture>https://example.org/image.jpg</ht:picture></item>'
        .'<item><title>Fußball</title><link>https://trends.google.com/trending/rss?geo=DE</link><pubDate>Wed, 4 Nov 2026 07:00:00 +0100</pubDate></item>'
        .'</channel></rss>';
    $items = app(FeedLeser::class)->feed($xml, 'https://trends.google.com/trending/rss?geo=DE');
    expect($items)->toHaveCount(2)
        ->and(TrendUrl::hash($items[0]['url']))->not->toBe(TrendUrl::hash($items[1]['url']))
        ->and($items[0]['teaser'])->toContain('2000+', 'Getränketrend', 'Suchsignal')->not->toContain('image.jpg')
        ->and($items[0]['veroeffentlicht_am'])->not->toBeNull();
    parse_str(parse_url($items[0]['url'], PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['geo' => 'DE', 'q' => 'Matcha', 'date' => '2026-11-04 2026-11-05']);
    expect(StichwortFilter::pruefen($items[0]['titel'].' '.$items[0]['teaser'], ['Getränk'], [])['durch'])->toBeTrue()
        ->and(StichwortFilter::pruefen($items[1]['titel'].' '.$items[1]['teaser'], ['Getränk'], [])['durch'])->toBeFalse();
});

it('Normale RSS-Feeds bleiben unverändert und leere Suchspitzen werden ausgelassen', function () {
    $reader = app(FeedLeser::class);
    $xml = '<rss><channel><item><title>Artikel</title><link>https://example.org/artikel</link><description>Teaser</description></item></channel></rss>';
    expect($reader->feed($xml, 'https://example.org/feed')[0])->toMatchArray(['titel' => 'Artikel', 'url' => 'https://example.org/artikel', 'teaser' => 'Teaser']);
    expect($reader->feed('<rss><channel><item><title/><link>https://trends.google.com/trending/rss</link></item></channel></rss>', 'https://trends.google.com/trending/rss?geo=DE'))->toBe([]);
});
