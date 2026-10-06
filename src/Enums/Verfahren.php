<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 60 · P4: Zubereitung als festes Vokabular (ersetzt die Prozess-Anker).
 *
 * Ein Verfahren wählt die Inspire-Variante eines Ankers („Kürbis" + geroestet → „Kürbis,
 * geröstet", dort gemessen) und verschiebt die Eigenschaften eines Bestandteils (Rösten →
 * Röstaroma, Bitterkeit, Biss). Abgeleitet aus den Inspire-Namen (775 Varianten auf demo,
 * 2026-10-06) und deckungsgleich mit den Zubereitungs-Wörtern der Rezepttexte.
 */
enum Verfahren: string
{
    case Roh = 'roh';
    case Gekocht = 'gekocht';
    case Pochiert = 'pochiert';
    case Gedaempft = 'gedaempft';
    case Blanchiert = 'blanchiert';
    case Geschmort = 'geschmort';
    case Gebraten = 'gebraten';
    case Geroestet = 'geroestet';
    case Gebacken = 'gebacken';
    case Gegrillt = 'gegrillt';
    case Frittiert = 'frittiert';
    case Geraeuchert = 'geraeuchert';
    case Karamellisiert = 'karamellisiert';
    case Getrocknet = 'getrocknet';
    case Eingelegt = 'eingelegt';
    case Fermentiert = 'fermentiert';
    case Gesalzen = 'gesalzen';
    case Konserviert = 'konserviert';

    public function label(): string
    {
        return match ($this) {
            self::Gedaempft => 'gedämpft',
            self::Geroestet => 'geröstet',
            self::Geraeuchert => 'geräuchert',
            default => $this->value,
        };
    }

    /**
     * Verfahren aus einem Zubereitungs-Text („in der Pfanne gebraten", „im Ofen geröstet",
     * „gekocht und in der Pfanne gebraten"). Bei mehreren Schritten gewinnt der letzte
     * aromaprägende (Bräunung vor Garen). Unbekannt → null (nicht raten).
     */
    public static function ausText(string $text): ?self
    {
        $t = mb_strtolower($text);
        $t = strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        // Reihenfolge = Priorität (prägend vor mild).
        $muster = [
            'karamellisiert' => self::Karamellisiert,
            'geraeuchert' => self::Geraeuchert,
            'gegrillt' => self::Gegrillt,
            'geroestet' => self::Geroestet,
            'frittiert' => self::Frittiert,
            'gebraten' => self::Gebraten,
            'gebacken' => self::Gebacken,
            'geschmort' => self::Geschmort,
            'fermentiert' => self::Fermentiert,
            'eingelegt' => self::Eingelegt,
            'gepoekelt' => self::Gesalzen,
            'gesalzen' => self::Gesalzen,
            'getrocknet' => self::Getrocknet,
            'aus der dose' => self::Konserviert,
            'konserviert' => self::Konserviert,
            'pochiert' => self::Pochiert,
            'gedaempft' => self::Gedaempft,
            'blanchiert' => self::Blanchiert,
            'gekocht' => self::Gekocht,
            'roh' => self::Roh,
            'frisch' => self::Roh,
        ];
        foreach ($muster as $wort => $v) {
            if (str_contains($t, $wort)) {
                return $v;
            }
        }

        return null;
    }
}
