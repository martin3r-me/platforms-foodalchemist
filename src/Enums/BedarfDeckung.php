<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 60 · P4: WIE ein Bedarf gedeckt wird (Dominique 15.10.).
 *
 * „Erdnuss braucht Röstung" meint die eigene Zubereitung, nicht einen Partner mit Röstaroma.
 * Ohne diese Unterscheidung schlug das Netz Erdnusspulver und Kaffee als Röstaroma-Partner vor.
 *
 *   partner      von außen: ein anderer Bestandteil liefert die Achse (Kontrast, Spannung, Vorschlag)
 *   zubereitung  die Zutat selbst (rösten, anbraten): nur Formwechsel, kein Partner, kein Kontrast
 *   beides       je nach Gericht (Pastinake: geröstet ja, als Püree braucht sie einen Partner)
 */
enum BedarfDeckung: string
{
    case Partner = 'partner';
    case Zubereitung = 'zubereitung';
    case Beides = 'beides';

    /** Darf ein anderer Bestandteil den Bedarf decken? */
    public function partnerMoeglich(): bool
    {
        return $this !== self::Zubereitung;
    }

    public function label(): string
    {
        return match ($this) {
            self::Partner => 'Partner',
            self::Zubereitung => 'über die Zubereitung',
            self::Beides => 'Partner oder Zubereitung',
        };
    }

    /** Zwei Bedarfe auf derselben Achse (mehrere Kern-Anker) → gemeinsame Deckung. */
    public static function vereine(?string $a, ?string $b): string
    {
        $a = self::tryFrom((string) $a) ?? self::Partner;
        $b = self::tryFrom((string) $b) ?? self::Partner;

        return ($a === $b ? $a : self::Beides)->value;
    }
}
