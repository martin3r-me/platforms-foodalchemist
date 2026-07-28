<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Riegel gegen eine Relations-Methode, die es nur auf dem Papier gibt.
 *
 * Anlass (2026-07-28, Lauf zu 22·H3b): `FoodAlchemistBulkRun::proposals()` deklarierte
 * `: HasMany` **ohne Import** (⇒ auflösbar nur als `Platform\FoodAlchemist\Models\HasMany`,
 * das nicht existiert) und zeigte auf `FoodAlchemistBulkProposal`, ein Model, das
 * absichtlich noch nicht gebaut ist. Ein Aufruf wäre ein Fatal Error gewesen — bemerkt hat
 * es nichts, weil die Methode keinen einzigen Aufrufer hat. Genau diese Kombination
 * (deklariert, unbenutzt, kaputt) fängt keine Suite, die nur echte Pfade fährt.
 *
 * Zwei Invarianten je Model-Klasse des Moduls, rein statisch + ein Aufruf:
 *  1. Ein Rückgabetyp, der wie eine Eloquent-Relation **heißt**, muss auch eine sein —
 *     ein fehlender `use` fällt sonst erst beim ersten Aufruf auf.
 *  2. Die Methode muss aufrufbar sein und eine `Relation` liefern — damit auch das
 *     Ziel-Model (das zweite halbe Versprechen) tatsächlich existiert.
 *
 * Absichtlich ohne DB-Zugriff: eine Relation zu *bauen* fragt nichts ab.
 */
$relationsKurznamen = [
    'HasOne', 'HasMany', 'HasOneThrough', 'HasManyThrough',
    'BelongsTo', 'BelongsToMany',
    'MorphOne', 'MorphMany', 'MorphTo', 'MorphToMany', 'MorphedByMany',
];

it('löst jede deklarierte Relations-Methode der Modul-Models auf', function () use ($relationsKurznamen) {
    $verzeichnis = dirname(__DIR__, 2) . '/src/Models';
    $klassen = [];
    foreach (glob($verzeichnis . '/*.php') ?: [] as $pfad) {
        $klasse = 'Platform\\FoodAlchemist\\Models\\' . basename($pfad, '.php');
        if (class_exists($klasse) && is_subclass_of($klasse, Model::class)) {
            $klassen[] = $klasse;
        }
    }
    expect($klassen)->not->toBeEmpty();

    $geprueft = 0;
    foreach ($klassen as $klasse) {
        $rk = new ReflectionClass($klasse);
        foreach ($rk->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $klasse || $m->isStatic() || $m->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $typ = $m->getReturnType();
            if (! $typ instanceof ReflectionNamedType || $typ->isBuiltin()) {
                continue;
            }
            $name = $typ->getName();
            $kurz = str_contains($name, '\\') ? substr($name, strrpos($name, '\\') + 1) : $name;
            if (! in_array($kurz, $relationsKurznamen, true)) {
                continue;
            }

            // (1) statisch: heißt wie eine Relation ⇒ muss eine sein (fehlender `use`!)
            expect(class_exists($name))->toBeTrue(
                "{$klasse}::{$m->getName()}() deklariert `{$name}` — diese Klasse existiert nicht "
                . '(fehlender `use Illuminate\\Database\\Eloquent\\Relations\\…`?).'
            );
            expect(is_a($name, Relation::class, true))->toBeTrue(
                "{$klasse}::{$m->getName()}() gibt `{$name}` zurück, das keine Eloquent-Relation ist."
            );

            // (2) dynamisch: aufrufbar, und das Ziel-Model existiert
            $relation = (new $klasse)->{$m->getName()}();
            expect($relation)->toBeInstanceOf(Relation::class);
            $geprueft++;
        }
    }

    // Ein Riegel, der nichts findet, ist keiner.
    expect($geprueft)->toBeGreaterThan(20);
});
