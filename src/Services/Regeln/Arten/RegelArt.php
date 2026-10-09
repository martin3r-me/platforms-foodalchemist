<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Models\FoodAlchemistRule;

/**
 * Eine Regel-Art des Motors (Spec 81 Teil B). Neue Regeln sind Zeilen, neue Arten sind Code (bewusst selten).
 *
 * `$kontext` trägt die Felder des Artefakts, auf die Bedingungen schauen (z. B. `zustand`, `warengruppe`, `form`).
 */
interface RegelArt
{
    /** @return list<string> Fehler im Parameter-Schema (leer = gültig) */
    public function validiere(array $params): array;

    /**
     * @param  array<string, mixed>  $kontext
     * @return list<array{grund: string, vorschlag: ?string, treffer: ?string}>
     */
    public function pruefe(FoodAlchemistRule $regel, string $text, array $kontext = []): array;

    /** Korrigierte Fassung oder null, wenn nichts zu korrigieren ist / die Art nicht korrigiert. */
    public function korrigiere(FoodAlchemistRule $regel, string $text, array $kontext = []): ?string;

    /** Kann diese Art mit Wirkung „korrigieren" laufen? */
    public function kannKorrigieren(): bool;
}
