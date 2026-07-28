<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * V-014 / Spec 22 H2d — die Herkunft der Preisbasis eines Rezept-EK.
 *
 * Die T3-Kaskade (§3.2) nimmt je Zutat den Preis des **gewählten** Lieferantenartikels
 * (Lead-LA) und mittelt, wenn keiner gewählt ist, über alle aktiven bepreisten LAs des
 * GP. Beide Wege erzeugen ein `ek_total_eur`, das identisch aussieht — der Durchschnitt
 * ist damit eine stille Annahme in einer Zahl, mit der kalkuliert und angeboten wird
 * (zwei Artikel zu 8,90 und 22,00 €/kg ⇒ 15,45 €/kg, in keiner Marge als Schätzung
 * erkennbar). Dieses Enum macht die Annahme sichtbar, statt sie zu vergessen.
 *
 * Das Vokabular lebt hier und nicht im Migrations-Kommentar — das ist die Lehre aus
 * V-020 (20 `status`-Spalten, deren erlaubte Werte nur an der einen Stelle stehen, die
 * kein Code liest).
 */
enum EkPriceBasis: string
{
    /** Jeder bepreiste Beitrag stammt aus einem gewählten Artikel (Lead-LA). */
    case Lead = 'lead';

    /** Jeder bepreiste Beitrag stammt aus einem Lieferanten-Durchschnitt — niemand hat gewählt. */
    case Avg = 'avg';

    /** Beides gemischt: ein Teil des EK ist entschieden, ein Teil geschätzt. */
    case Mixed = 'mixed';

    /**
     * Mindestens ein Beitrag trägt keine nachvollziehbare Basis — heute genau dann, wenn
     * ein Sub-Rezept bepreist ist, seine eigene Basis aber (noch) nicht gerechnet wurde.
     * Bewusst KEIN Synonym für `mixed`: „teils geschätzt" und „unbekannt woher" sind
     * verschiedene Aussagen, und nur die zweite verbietet jede Interpretation.
     */
    case Unknown = 'unknown';

    /**
     * Rezept-Basis aus den Basen der bepreisten Zutaten — schwächstes Glied, dieselbe
     * Haltung wie `RecipeRecomputeService::subKonfidenzRang` („kein false-confident", §7):
     * eine unbekannte Teil-Basis deckelt die Aussage, sie wird nicht weggemittelt.
     *
     * NULL wenn keine Zutat bepreist ist — ohne EK gibt es keine Basis, und ein Default
     * wäre eine Behauptung über eine Zahl, die es nicht gibt.
     *
     * @param  list<self>  $basen  je bepreiste Zutat eine Basis (unbepreiste tragen nichts bei)
     */
    public static function aggregiere(array $basen): ?self
    {
        if ($basen === []) {
            return null;
        }
        if (in_array(self::Unknown, $basen, true)) {
            return self::Unknown;
        }

        $eindeutig = array_unique(array_map(fn (self $b) => $b->value, $basen));

        return count($eindeutig) === 1 ? self::from($eindeutig[array_key_first($eindeutig)]) : self::Mixed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'gewählter Artikel',
            self::Avg => 'Lieferanten-Durchschnitt',
            self::Mixed => 'teils gewählt, teils Durchschnitt',
            self::Unknown => 'Basis unbekannt',
        };
    }

    /** Steht der EK ganz oder teilweise auf einer Schätzung? (Anzeige-/Metrik-Prädikat) */
    public function istVorlaeufig(): bool
    {
        return $this !== self::Lead;
    }
}
