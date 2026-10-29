<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * #1002 (P2a): Ein Job, dessen KI-Aufrufe im externen Modus laufen können und der sich nach einer Antwort selbst neu starten lässt.
 *
 * Der Job ruft seinen Rumpf über {@see KiExternLauf::fuehreAus()}. Im externen Modus läuft der Rumpf in einer Transaktion: wartet er auf
 * eine Antwort, wird zurückgerollt (jede Wiederholung startet vom identischen DB-Stand ⇒ stabiler Fingerabdruck), der Auftrag trägt die
 * Nutzlast, mit der `ki_auftraege.ANTWORT` den Job neu anstößt. Nicht-DB-Nebenwirkungen im Rumpf (Folge-Jobs, Cache, Embedding, Events)
 * gehören in `DB::afterCommit()` bzw. `->afterCommit()`, sonst bleibt der Job Klasse c (Pflichtliste Tabelle D).
 */
interface KiExternWiederholbar
{
    /** Läuft dieser Aufruf extern? Aus ⇒ der Rumpf läuft bytegleich wie bisher (keine Transaktion, kein Sammel-Modus). */
    public function externAktiv(): bool;

    /** Ein laufender Lauf je Ziel, z. B. `enrich-recipe:123`. */
    public function externSchluessel(): string;

    public function externTeamId(): int;

    /** Versuch dieses Laufs, ab 0. */
    public function externVersuch(): int;

    /** @return array<string, mixed> JSON-fähige Konstruktor-Argumente (ohne Versuch/Extern-Flag) */
    public function externNutzlast(): array;

    /** @param array<string, mixed> $nutzlast */
    public static function fromExternNutzlast(array $nutzlast, int $versuch): static;
}
