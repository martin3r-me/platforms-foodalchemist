<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelMotor;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelText;
use Platform\FoodAlchemist\Services\Regeln\RegelUngueltig;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 81 Teil B–D — Regel-Motor: sechs Arten, Speichern mit Prüfung, Versionen, Regelbuch. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->motor = app(RegelMotor::class);
    $this->regel = fn (string $art, array $params, string $wirkung = 'warnen', array $extra = []) => new FoodAlchemistRule([
        'schluessel' => 'test.' . $art, 'regelwerk' => 'gp', 'paragraph' => '§9', 'titel' => 'Test ' . $art,
        'art' => $art, 'ziel' => 'gp.name', 'wirkung' => $wirkung, 'params' => $params,
    ] + $extra);
});

it('Text-Vergleich ist tolerant gegen Umlaut-Umschreibung und Großschreibung', function () {
    expect(RegelText::hatWort('Schalotten: frisch, Wuerfel 5 mm', 'würfel'))->toBeTrue()
        ->and(RegelText::hatWort('Kreuzkümmel: trocken, gemahlen', 'GEMAHLEN'))->toBeTrue()
        ->and(RegelText::hatWort('Rinderhack', 'hack'))->toBeFalse()
        ->and(RegelText::ersetzeWort('Moehre: frisch, Wuerfel 5 mm', 'würfel', 'Würfel'))->toBe('Moehre: frisch, Würfel 5 mm');
});

it('Vokabular: Wert, Alias → kanonisch, Muster mit <mm>, Befund außerhalb', function () {
    $r = ($this->regel)('vokabular', ['werte' => [['wert' => 'frisch'], ['wert' => 'TK', 'aliase' => ['tiefgekühlt']]],
        'muster' => ['Würfel <mm>']], 'korrigieren');

    expect($this->motor->pruefe($r, 'tiefgekuehlt'))->toBe([])
        ->and($this->motor->korrigiere($r, 'tiefgekühlt'))->toBe('TK')
        ->and($this->motor->pruefe($r, 'Wuerfel 10 mm'))->toBe([])
        ->and($this->motor->pruefe($r, 'gefriergetrocknet')[0]['begruendung'])->toContain('nicht im Vokabular')
        ->and($this->motor->pruefe($r, 'gefriergetrocknet')[0]['quelle'])->toBe('code');
});

it('Ersetzung: ganze Wörter, Ausnahmen, Bedingung', function () {
    $r = ($this->regel)('ersetzung', ['paare' => [['von' => 'gekocht', 'nach' => 'gegart']], 'ausnahmen' => ['Schinken']], 'korrigieren');

    expect($this->motor->korrigiere($r, 'Udon-Nudel: trocken, gekocht'))->toBe('Udon-Nudel: trocken, gegart')
        ->and($this->motor->korrigiere($r, 'Schinken: gekocht'))->toBeNull()
        ->and($this->motor->korrigiere($r, 'Ungekochtes'))->toBeNull();
});

it('Verbot: Token, Regex, Bedingung auf Kontext', function () {
    $r = ($this->regel)('verbot', ['tokens' => ['würfel', 'brunoise'], 'bedingung' => ['zustand' => ['frisch']], 'grund' => 'Schnittform gehört in die Küche (§2)']);

    expect($this->motor->pruefe($r, 'Schalotten: frisch, Wuerfel 5 mm', ['zustand' => 'frisch'])[0]['treffer'])->toBe('würfel')
        ->and($this->motor->pruefe($r, 'Karotte: TK, Würfel 10 mm', ['zustand' => 'TK']))->toBe([])
        ->and($this->motor->pruefe($r, 'Karotte: frisch, Würfel', []))->toBe([]);   // Kontext fehlt → schweigt

    $kg = ($this->regel)('verbot', ['muster' => ['/\b\d+(?:[.,]\d+)?\s*kg\b/iu']]);
    expect($this->motor->pruefe($kg, 'Mirabellenpueree: konserviert, Boiron 1 kg'))->not->toBe([]);
});

it('Pflichtangabe: nur unter der Bedingung, nur flaggen', function () {
    $r = ($this->regel)('pflichtangabe', ['bedingung' => ['warengruppe' => ['Kartoffel']],
        'tokens' => ['festkochend', 'vorwiegend festkochend', 'mehligkochend'], 'hinweis' => 'Kochtyp fehlt (§8.12).']);

    expect($this->motor->pruefe($r, 'Kartoffel: frisch, ganz', ['warengruppe' => 'Kartoffel'])[0]['begruendung'])->toBe('Kochtyp fehlt (§8.12).')
        ->and($this->motor->pruefe($r, 'Kartoffel: frisch, festkochend, ganz', ['warengruppe' => 'Kartoffel']))->toBe([])
        ->and($this->motor->pruefe($r, 'Möhre: frisch, ganz', ['warengruppe' => 'Gemüse']))->toBe([])
        ->and($this->motor->korrigiere($r, 'Kartoffel: frisch'))->toBeNull();
});

it('Zuordnung: Kontext-Eintrag schlägt allgemeinen (Olivenöl kalt/heiß)', function () {
    $r = ($this->regel)('zuordnung', ['eintraege' => [
        ['begriff' => 'Olivenöl', 'ziel_typ' => 'gp', 'ziel_name' => 'Olivenöl: nativ extra', 'kontext' => ['anwendung' => ['kalt']]],
        ['begriff' => 'Olivenöl', 'ziel_typ' => 'gp', 'ziel_name' => 'Olivenöl: raffiniert', 'kontext' => ['anwendung' => ['heiß']]],
        ['begriff' => 'Olivenöl', 'aliase' => ['Olivenoel'], 'ziel_typ' => 'gp', 'ziel_name' => 'Olivenöl: nativ extra'],
    ]], 'korrigieren', ['ziel' => 'rezeptzeile']);
    $zuo = $this->motor->art('zuordnung');

    expect($zuo->finde($r, 'olivenoel', ['anwendung' => 'heiß'])['ziel_name'])->toBe('Olivenöl: raffiniert')
        ->and($zuo->finde($r, 'Olivenöl', ['anwendung' => 'kalt'])['ziel_name'])->toBe('Olivenöl: nativ extra')
        ->and($zuo->finde($r, 'Olivenöl')['ziel_name'])->toBe('Olivenöl: nativ extra')
        ->and($zuo->finde($r, 'Rapsöl'))->toBeNull();
});

it('Schwelle: Vergleich und Spanne', function () {
    $min = ($this->regel)('schwelle', ['wert' => 65, 'vergleich' => '>=', 'einheit' => '°C']);
    $satz = ($this->regel)('schwelle', ['min' => 3, 'max' => 5, 'vergleich' => 'zwischen', 'einheit' => 'Sätze']);

    expect($this->motor->pruefe($min, '63'))->not->toBe([])
        ->and($this->motor->pruefe($min, '70'))->toBe([])
        ->and($this->motor->pruefe($satz, '2'))->not->toBe([])
        ->and($this->motor->art('schwelle')->wert($min))->toBe(65.0);
});

it('Validierung: Schema, Wirkung je Art, kaputtes Regex, falsche Beispiele', function () {
    expect($this->motor->validiere(($this->regel)('vokabular', ['werte' => []])))->not->toBe([])
        ->and($this->motor->validiere(($this->regel)('verbot', ['tokens' => ['x']], 'korrigieren'))[0])->toContain('kann nicht korrigieren')
        ->and($this->motor->validiere(($this->regel)('verbot', ['muster' => ['/(/']]))[0])->toContain('Ungültiges Muster');

    $r = ($this->regel)('vokabular', ['werte' => [['wert' => 'frisch']]], 'warnen', ['beispiele' => ['richtig' => ['frisch'], 'falsch' => ['frisch']]]);
    expect($this->motor->validiere($r))->toBe(['Beispiel (falsch) stimmt nicht: »frisch«']);
});

it('Speichern: neu = inaktiv, Version + Fassung, ungültig wird abgelehnt, Zurückrollen', function () {
    $svc = app(RegelService::class);
    $daten = ['schluessel' => 'gp.9.zustand', 'regelwerk' => 'gp', 'paragraph' => '§9', 'titel' => 'Zustand', 'art' => 'vokabular',
        'ziel' => 'gp.zustand', 'wirkung' => 'blockieren', 'params' => ['werte' => [['wert' => 'frisch'], ['wert' => 'TK']]]];

    $r = $svc->speichere($daten);
    expect($r->aktiv)->toBeFalse()->and($r->version)->toBe(1);

    $r = $svc->speichere([...$daten, 'params' => ['werte' => [['wert' => 'frisch'], ['wert' => 'TK'], ['wert' => 'trocken']]]]);
    expect($r->version)->toBe(2)->and(FoodAlchemistRuleVersion::where('rule_id', $r->id)->count())->toBe(2);

    expect(fn () => $svc->speichere([...$daten, 'params' => ['werte' => []]]))->toThrow(RegelUngueltig::class);

    $r = $svc->zurueckAuf($r->id, 1);
    expect($r->version)->toBe(3)->and($this->motor->art('vokabular')->werte($r))->toBe(['frisch', 'TK']);
});

it('Regelbuch: nur aktive globale Regeln, Memo wird beim Speichern geleert', function () {
    $svc = app(RegelService::class);
    $daten = ['schluessel' => 'gp.2.schnittform', 'regelwerk' => 'basisrezept', 'paragraph' => '§2', 'titel' => 'Schnittform',
        'art' => 'verbot', 'ziel' => 'gp.name', 'params' => ['tokens' => ['würfel']]];
    $r = $svc->speichere($daten);

    expect(RegelBuch::falls('gp.2.schnittform'))->toBeNull();

    $svc->setzeAktiv($r->id, true);
    expect(RegelBuch::per('gp.2.schnittform')?->id)->toBe($r->id)
        ->and(collect(RegelBuch::fuerZiel('gp.name'))->pluck('schluessel')->all())->toContain('gp.2.schnittform');
});

it('Globale Regeln schreibt nur das Master-Team', function () {
    expect(fn () => app(RegelService::class)->speichere(['schluessel' => 'x', 'regelwerk' => 'gp', 'titel' => 'x', 'art' => 'verbot',
        'ziel' => 'gp.name', 'params' => ['tokens' => ['x']]], $this->childA))->toThrow(RuntimeException::class, 'Master-Team');
});
