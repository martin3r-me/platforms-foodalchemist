<?php

use Platform\FoodAlchemist\Enums\SignalTyp;

/**
 * 22·H1 / V-003 — die Darstellungs-Tabellen der Enums sind vollständig.
 *
 * `SignalTyp` führt jeden Typ an drei Stellen derselben Datei (Case + `label()`-match +
 * `icon()`-match). Ein vergessener match-Arm ist heute erst zur LAUFZEIT sichtbar, als
 * `UnhandledMatchError` in der Signal-Inbox — also genau dort, wo die Zahl gebraucht wird.
 * Spec 21 hat ~20 Typen hinzugefügt (38 Cases), das sind 38 × 3 Pflege-Stellen.
 *
 * Diese Tests holen den Fehler in die Testzeit: ein match ohne default WIRFT hier, und der
 * Fehlschlag nennt den Case.
 */
it('hat für jeden SignalTyp ein Label und ein Heroicon', function () {
    expect(SignalTyp::cases())->not->toBeEmpty();

    foreach (SignalTyp::cases() as $typ) {
        // Ein fehlender match-Arm wirft hier UnhandledMatchError → Test rot statt Laufzeit-Crash.
        $label = $typ->label();
        $icon = $typ->icon();

        expect(trim($label))->not->toBe('', "SignalTyp „{$typ->value}\" hat kein Label");
        expect($icon)->toStartWith('heroicon-', "SignalTyp „{$typ->value}\" hat kein Heroicon");
    }
});

it('vergibt SignalTyp-Labels eindeutig', function () {
    $labels = array_map(fn (SignalTyp $t) => $t->label(), SignalTyp::cases());

    $doppelt = array_keys(array_filter(array_count_values($labels), fn (int $n) => $n > 1));

    expect($doppelt)->toBe([], 'Zwei Signal-Typen mit demselben Label sind in der Inbox nicht unterscheidbar: '
        . implode(' · ', $doppelt));
});

/**
 * Dieselbe Fehlerklasse für alle übrigen Modul-Enums, ohne sie einzeln aufzuzählen: jede
 * argumentlose öffentliche Methode, die einen String liefert (label/icon/farbe/…), wird für
 * jeden Case einmal gerufen. Ein match ohne default fällt damit beim nächsten neuen Case auf.
 */
it('ruft für jeden Case jedes Modul-Enums die argumentlosen Darstellungs-Methoden ohne Fehler', function () {
    $dateien = glob(\dirname(__DIR__, 2) . '/src/Enums/*.php') ?: [];
    expect($dateien)->not->toBeEmpty();

    $geprueft = 0;

    foreach ($dateien as $datei) {
        $klasse = 'Platform\\FoodAlchemist\\Enums\\' . basename($datei, '.php');
        if (! enum_exists($klasse)) {
            continue;
        }

        $rc = new ReflectionEnum($klasse);
        $methoden = array_filter(
            $rc->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! $m->isStatic()
                && $m->getNumberOfParameters() === 0
                && $m->getDeclaringClass()->getName() === $klasse
        );

        foreach ($klasse::cases() as $case) {
            foreach ($methoden as $m) {
                // Wirft der match-Ausdruck (fehlender Arm), ist der Test rot und nennt Case + Methode.
                try {
                    $m->invoke($case);
                } catch (\UnhandledMatchError $e) {
                    throw new \UnhandledMatchError(
                        "{$klasse}::{$case->name} hat keinen Arm in {$m->getName()}(): " . $e->getMessage()
                    );
                }
                $geprueft++;
            }
        }
    }

    expect($geprueft)->toBeGreaterThan(0);
});
