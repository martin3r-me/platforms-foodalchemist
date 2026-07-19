<?php

use Platform\FoodAlchemist\Services\TerminologyService;

/**
 * #507 Weg-2: die deterministische Terminologie-Schicht (S1 Alias + S2 Anti-Marker).
 * Reine Logik, keine DB. Deckt die Golden-Set-Fälle ab, die die E5-Eichung als
 * „von Embeddings nicht trennbar" markiert hat.
 */
beforeEach(function () {
    $this->svc = new TerminologyService();
});

// ── S1: Alias-Expansion ──────────────────────────────────────────────────────

it('expandiert Dialekt/Übersetzung auf die Kanon-Tokens', function () {
    expect($this->svc->aliasPhrasesFor('Paradeiser'))->toContain('tomate');
    expect($this->svc->aliasPhrasesFor('Erdapfel'))->toContain('kartoffel');
    expect($this->svc->aliasPhrasesFor('Beef'))->toContain('rindfleisch');
    expect($this->svc->aliasPhrasesFor('Salmon'))->toContain('lachs');
    expect($this->svc->aliasPhrasesFor('Möhre'))->toContain('karotte');
    // symmetrisch: der Kanon-Begriff zieht die Synonyme mit
    expect($this->svc->aliasPhrasesFor('Sahne'))->toContain('rahm')->toContain('obers');
});

it('lässt Standardnamen ohne Alias unangetastet', function () {
    expect($this->svc->aliasPhrasesFor('Zanderfilet'))->toBe([]);
    expect($this->svc->aliasPhrasesFor('Butter'))->toBe([]);
});

it('triggert NICHT auf Teil-Strings (Token-Grenzen)', function () {
    // „Tamarinde" enthält den Substring „rind" — darf NICHT Beef-Aliase ziehen.
    expect($this->svc->aliasPhrasesFor('Tamarinde'))->not->toContain('rindfleisch');
    expect($this->svc->aliasPhrasesFor('Tamarinde'))->not->toContain('beef');
});

// ── S3: Decompounding (Compound-Query → §1-Syntax) ───────────────────────────

it('zerlegt Compounds in [Modifier, Kopf] inkl. Fugen-Varianten', function () {
    expect($this->svc->decompoundPhrasesFor('Kürbispüree'))->toContain('kürbis püree');
    expect($this->svc->decompoundPhrasesFor('Kalbsjus'))->toContain('kalb jus');       // Fugen-s
    expect($this->svc->decompoundPhrasesFor('Rotweinjus'))->toContain('rotwein jus');
    expect($this->svc->decompoundPhrasesFor('Tomatensugo'))->toContain('tomate sugo'); // Fugen-n
});

it('splittet Nicht-Compounds nicht', function () {
    expect($this->svc->decompoundPhrasesFor('Kartoffel'))->toBe([]);
    expect($this->svc->decompoundPhrasesFor('Aubergine'))->toBe([]);
});

// ── S2: Anti-Marker-Suppression (die 8 Golden-Negativfälle) ──────────────────

it('unterdrückt bekannte Verwechslungs-Fallen', function () {
    expect($this->svc->isAntiMarker('Brie', 'Bries'))->toBeTrue();
    expect($this->svc->isAntiMarker('Bries', 'Brie'))->toBeTrue();
    expect($this->svc->isAntiMarker('Kalbsbries', 'Brie, jung'))->toBeTrue();
    expect($this->svc->isAntiMarker('Triple Sec', 'Cookies Triple Chocolate'))->toBeTrue();
    expect($this->svc->isAntiMarker('Cookies Triple Chocolate', 'Triple Sec'))->toBeTrue();
    expect($this->svc->isAntiMarker('Cointreau', 'Orange'))->toBeTrue();
    expect($this->svc->isAntiMarker('Limoncello', 'Zitrone'))->toBeTrue();
    expect($this->svc->isAntiMarker('Paprikapulver', 'Paprika: frisch'))->toBeTrue();
    expect($this->svc->isAntiMarker('Sojasauce', 'Sauce Hollandaise'))->toBeTrue();
});

it('lässt legitime Treffer durch (kein Über-Blocken)', function () {
    // Der eigentliche Treffer darf NIE unterdrückt werden.
    expect($this->svc->isAntiMarker('Bries', 'Bries'))->toBeFalse();
    expect($this->svc->isAntiMarker('Brie', 'Brie'))->toBeFalse();
    expect($this->svc->isAntiMarker('Cointreau', 'Cointreau'))->toBeFalse();
    expect($this->svc->isAntiMarker('Limoncello', 'Limoncello'))->toBeFalse();
    expect($this->svc->isAntiMarker('Paprikapulver', 'Paprikapulver'))->toBeFalse();
    expect($this->svc->isAntiMarker('Tomate', 'Tomate'))->toBeFalse();
    // unbeteiligte Paare bleiben unberührt
    expect($this->svc->isAntiMarker('Rindfleisch', 'Rinderhackfleisch'))->toBeFalse();
});
