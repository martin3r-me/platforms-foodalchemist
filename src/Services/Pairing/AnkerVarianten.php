<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Verfahren;

/**
 * Spec 60 · P4: Varianten der Inspire-Anker ableiten (deterministisch, wiederholbar).
 *
 * Inspire führt Zubereitungen als eigene, gemessene Anker: „Kürbis, im Ofen gebacken",
 * „Erdnuss, geröstet". Je Anker wird festgehalten
 *   grundname       Teil vor dem ersten Komma (bei Grundformen der ganze Name)
 *   verfahren       Zubereitung aus dem Teil nach dem Komma ({@see Verfahren::ausText}), sonst NULL
 *   grund_anchor_id der Anker, der genau den Grundnamen trägt — falls Inspire ihn führt
 *
 * Gemessen auf demo (2026-10-06): 775 Varianten, 460 mit eigenem Grund-Anker. Wo Inspire
 * keine Grundform hat („Speck, gebraten" ohne „Speck"), verbindet der Grundname die Varianten.
 */
final class AnkerVarianten
{
    private const T = 'foodalchemist_vocab_pairing_anchors';

    /** @return array{anker: int, varianten: int, mit_grund_anker: int, mit_verfahren: int, geaendert: int} */
    public function ableiten(bool $schreiben = true): array
    {
        $anker = DB::table(self::T)->whereNull('deleted_at')
            ->get(['id', 'display_de', 'grundname', 'verfahren', 'grund_anchor_id']);

        $nachName = [];
        foreach ($anker as $a) {
            $nachName[$this->schluessel((string) $a->display_de)][] = (int) $a->id;
        }

        $stat = ['anker' => $anker->count(), 'varianten' => 0, 'mit_grund_anker' => 0, 'mit_verfahren' => 0, 'geaendert' => 0];
        foreach ($anker as $a) {
            $name = trim((string) $a->display_de);
            $grundname = $name;
            $verfahren = null;
            $grundId = null;
            if (str_contains($name, ',')) {
                [$grundname, $rest] = array_map('trim', explode(',', $name, 2));
                $verfahren = Verfahren::ausText($rest)?->value;
                $kandidaten = $nachName[$this->schluessel($grundname)] ?? [];
                // Nur eindeutig: genau ein Anker trägt den Grundnamen.
                $grundId = count($kandidaten) === 1 && $kandidaten[0] !== (int) $a->id ? $kandidaten[0] : null;
                $stat['varianten']++;
                $stat['mit_grund_anker'] += $grundId !== null ? 1 : 0;
                $stat['mit_verfahren'] += $verfahren !== null ? 1 : 0;
            }

            $neu = ['grundname' => mb_substr($grundname, 0, 150), 'verfahren' => $verfahren, 'grund_anchor_id' => $grundId];
            if ($a->grundname !== $neu['grundname'] || $a->verfahren !== $neu['verfahren']
                || ($a->grund_anchor_id !== null ? (int) $a->grund_anchor_id : null) !== $neu['grund_anchor_id']) {
                $stat['geaendert']++;
                if ($schreiben) {
                    DB::table(self::T)->where('id', $a->id)->update($neu);
                }
            }
        }

        return $stat;
    }

    private function schluessel(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
