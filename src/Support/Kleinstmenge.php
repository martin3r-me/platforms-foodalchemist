<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Services\Regeln\Arten\Zuordnung;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Kleinstmengen im Gericht (Regel `gericht.kleinstmengen`, Dominique 11.10.): Butter, Öl, Salz, Pfeffer, Zitrone und
 * Finish-Kräuter stehen im Gericht direkt als GP-Zeile, ohne eigenes Rüst-Basisrezept. Eine Typ-Vorsilbe der KI
 * („Aroma: Butter") zählt nicht, Merkmale auch nicht („Pfeffer schwarz, frisch gemahlen" — Regel-Param `merkmale` plus
 * reine Merkmale der TokenEngine); ein Halbfabrikat („Zitronenbutter", „Butter: braun") trifft nicht — Basisrezept.
 */
final class Kleinstmenge
{
    public const SCHLUESSEL = 'gericht.kleinstmengen';

    /**
     * @return array{eintrag: string, text: string}|null  Listeneintrag und die Zeile ohne Typ-Vorsilbe; null = keine Kleinstmenge
     */
    public static function erkenne(string $zeile): ?array
    {
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        if ($regel === null || trim($zeile) === '') {
            return null;
        }
        $ohneTyp = RezeptTypVokabular::ohneTyp($zeile);
        $engine = app(TokenEngine::class);
        $merkmale = array_map(static fn ($m) => RegelText::norm((string) $m), (array) ($regel->params['merkmale'] ?? []));
        $woerter = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', RegelText::norm($ohneTyp)) ?: [],
            static fn (string $w) => $w !== '' && ! in_array($w, $merkmale, true) && ! $engine->istReinesMerkmal($w)));
        if ($woerter === []) {
            return null;
        }
        $treffer = app(Zuordnung::class)->finde($regel, implode(' ', $woerter));

        return $treffer !== null ? ['eintrag' => $treffer['ziel_name'], 'text' => $ohneTyp] : null;
    }
}
