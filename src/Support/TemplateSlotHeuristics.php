<?php

namespace Platform\FoodAlchemist\Support;

/**
 * D-5: Slot-Typ-Heuristik für die Template-Instanziierung (Port der Tauri-App
 * `TemplateInstantiateModal` — `isBodyPlaceholder`/`isLiquidPlaceholder`/`slotSeed`/
 * `slotPrefersSub`). Reine, seiteneffektfreie Klassifikation eines Platzhalter-
 * Namens → Seed-Suchtext + Pool-Präferenz. Keine DB, kein Team — nur Strings.
 *
 * Geschmacks-Modell: Ein Platzhalter ist entweder GESCHMACKSTRÄGER (bekommt die
 * Variante als Seed) oder TRÄGER (bekommt einen generischen Default). Body-Slots
 * tragen Geschmack; eine Flüssigkeit trägt ihn NUR, wenn das Template keinen
 * eigenen Body-Slot hat (Gelier-/Schäum-Familie: „Gelee: Himbeere" → Flüssigkeit =
 * Himbeerpüree, nicht Fond).
 *
 * Abweichung vom Tauri-Original (bewusst, „sauber neu" 2026-06-15): der frühere
 * Default-Zweig seedete JEDEN unklassifizierten Platzhalter mit der Variante und
 * flutete so Träger geschmacklich (z. B. „Stärke (neutral)" → „Brombeere"). Hier
 * gilt: unklassifizierte Slots bleiben LEER (→ Nutzer bindet manuell) — ES SEI
 * DENN, das Template hat überhaupt keinen Geschmacksträger; dann wird der
 * unklassifizierte Slot zum Träger (Variante landet immer irgendwo).
 */
final class TemplateSlotHeuristics
{
    /** Geschmackstragender Body-Slot (Aromat/Fruchtmark/Hauptprodukt/Püree/Mark). */
    public static function istBody(string $name): bool
    {
        $n = mb_strtolower($name);

        foreach (['aromat', 'fruchtmark', 'gemüsemark', 'gemuesemark', 'hauptprodukt', 'püree', 'pueree', 'mark'] as $marker) {
            if (str_contains($n, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** Flüssigkeits-/Fond-Slot. */
    public static function istFluessigkeit(string $name): bool
    {
        $n = mb_strtolower($name);

        foreach (['flüssig', 'fluessig', 'fond', 'brühe', 'bruehe'] as $marker) {
            if (str_contains($n, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Soll dieser Slot bevorzugt gegen Sub-Rezepte matchen? Ja für Body-Slots und
     * die Flüssigkeit-als-Body (prepared liquids wie Essenz/Suppe/Fond/Coulis sollen
     * Vorrang vor dem Roh-GP haben). Sonst GP-First.
     */
    public static function bevorzugtSub(string $name, bool $hatDedizErtenBody): bool
    {
        if (self::istBody($name)) {
            return true;
        }

        return self::istFluessigkeit($name) && ! $hatDedizErtenBody;
    }

    /**
     * Seed-Suchtext für einen Slot. Leerer String = kein Seed (Slot bleibt ungebunden).
     *
     * @param  bool  $hatDedizErtenBody  Hat das Template einen eigenen Body-Slot?
     * @param  bool  $hatGeschmackstraeger  Trägt IRGENDEIN Slot des Templates den Geschmack?
     */
    public static function seed(string $name, string $variant, bool $hatDedizErtenBody, bool $hatGeschmackstraeger): string
    {
        $variant = trim($variant);
        $n = mb_strtolower($name);

        if (self::istBody($n)) {
            // Mark/Püree-Body trägt eine VERARBEITETE Form — Seed mit „Püree" anreichern,
            // damit der Matcher (Decompounding: brombeere+pueree) ein Püree/Mark trifft
            // statt rohe ganze Ware ("Brombeere" → "Brombeerpüree", nicht "Brombeeren:
            // frisch, ganz"). Aromat/Hauptprodukt bleiben roh.
            if ($variant !== '' && (str_contains($n, 'mark') || str_contains($n, 'püree') || str_contains($n, 'pueree'))) {
                return $variant . ' Püree';
            }

            return $variant;
        }
        if (self::istFluessigkeit($n)) {
            return $hatDedizErtenBody ? 'Fond' : $variant;  // Flüssigkeit-als-Body trägt die Variante
        }
        if (str_contains($n, 'fett') || str_contains($n, 'öl') || str_contains($n, 'oel')) {
            return 'Butter';
        }
        if (str_contains($n, 'säure') || str_contains($n, 'saeure')) {
            return 'Zitronensaft';
        }
        if (str_contains($n, 'süß') || str_contains($n, 'suess') || str_contains($n, 'süss') || str_contains($n, 'zucker')) {
            return 'Zucker';
        }

        // Unklassifiziert: NICHT mit der Variante fluten, solange ein echter
        // Geschmacksträger existiert. Sonst wird dieser Slot zum Träger.
        return $hatGeschmackstraeger ? '' : $variant;
    }

    /**
     * Trägt mindestens ein Slot dieser Liste den Geschmack (Body, oder Flüssigkeit
     * wenn kein Body da ist)? Steuert den „sauber neu"-Default oben.
     *
     * @param  list<string>  $platzhalterNamen
     */
    public static function hatGeschmackstraeger(array $platzhalterNamen): bool
    {
        $hatBody = false;
        $hatFluessigkeit = false;
        foreach ($platzhalterNamen as $name) {
            if (self::istBody($name)) {
                $hatBody = true;
            }
            if (self::istFluessigkeit($name)) {
                $hatFluessigkeit = true;
            }
        }

        return $hatBody || $hatFluessigkeit;
    }

    /** @param  list<string>  $platzhalterNamen */
    public static function hatDedizErtenBody(array $platzhalterNamen): bool
    {
        foreach ($platzhalterNamen as $name) {
            if (self::istBody($name)) {
                return true;
            }
        }

        return false;
    }
}
