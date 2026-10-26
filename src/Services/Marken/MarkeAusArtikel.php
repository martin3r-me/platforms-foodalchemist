<?php

namespace Platform\FoodAlchemist\Services\Marken;

use Platform\FoodAlchemist\Enums\SupplierRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 (Dominique 26.10.2026): EINE Stelle, die für einen Lieferantenartikel die Marke der GP-Klammer bestimmt.
 * Alle Wege (gp.suggest-Kontext, Abgleich, GP-Dialog, Anlage, MCP) fragen hier — nie selbst.
 *
 * Reihenfolge (fest):
 *  1. Necta-`brand` (selten befüllt, io 26.10.: 1,5 %), wenn es nicht der Händler selbst ist;
 *  2. Kürzel bzw. Markenwort aus der Bezeichnung über `foodalchemist_supplier_marken` — erst die des Lieferanten, dann die globalen.
 *     Matchregel wie `gp_helfer/marken_kuerzel.py::resolve()` (Klaus): nur die ersten drei Wörter, ein Wort zählt nur, wenn es aus
 *     Buchstaben besteht und allein steht oder direkt vor einem Punkt endet („Kn.Bratenjus“ → Knorr, „oKn.m.Schw“ → nichts);
 *  3. Lieferant ist Hersteller → sein Kurzname;
 *  4. sonst offen. Der Name eines Händlers (oder ungeklärten Lieferanten) wird NIE zur Marke; Sperrwörter (MSC = Zertifizierung) nie.
 */
class MarkeAusArtikel
{
    /** Zertifizierungen und ähnliche Präfixe, die nie eine Marke sind (Dominique 26.10.: „MSC ist die Zertifizierung“). */
    public const SPERRWOERTER = ['msc'];

    /** Warengruppen, die nie Rezepturware sind: Wurst (§8.13), Getränke (§5 Ausnahme 1). */
    public const NIE_REZEPTURWARE_WG = ['04', '15'];

    /** @var array<string, array<string, string>>|null  'global' bzw. supplier_id → [kuerzel => marke] */
    private ?array $karte = null;

    public function fuer(FoodAlchemistSupplierItem $la): MarkenErgebnis
    {
        $lieferant = $la->supplier;
        if ($lieferant !== null && ! array_key_exists('rolle', $lieferant->getAttributes())) {
            $lieferant = $la->supplier()->first();   // Relation war mit Spaltenauswahl geladen (z. B. supplier:id,name) — Rolle fehlte
        }

        $brand = trim((string) $la->brand);
        if ($brand !== '' && ! $this->gesperrt($brand) && ! $this->istHaendlerName($brand, $lieferant)) {
            return new MarkenErgebnis($brand, MarkenErgebnis::QUELLE_NECTA, $brand);
        }

        $treffer = $this->kuerzelAus((string) $la->designation, $lieferant?->id);
        if ($treffer !== null) {
            return new MarkenErgebnis($treffer[0], MarkenErgebnis::QUELLE_KUERZEL, $treffer[1]);
        }

        $kurz = trim((string) $lieferant?->kurzname);
        if ($lieferant !== null && $lieferant->rolle === SupplierRolle::Hersteller && $kurz !== '') {
            return new MarkenErgebnis($kurz, MarkenErgebnis::QUELLE_KURZNAME, $lieferant->name);
        }

        return new MarkenErgebnis(null, MarkenErgebnis::QUELLE_OFFEN);
    }

    /**
     * Kürzel bzw. Markenwort in den ersten drei Wörtern der Bezeichnung → [Marke, Rohkürzel] oder null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function kuerzelAus(string $bezeichnung, ?int $supplierId): ?array
    {
        $karte = $this->karte();
        $eigen = $supplierId !== null ? ($karte[(string) $supplierId] ?? []) : [];
        $woerter = array_slice(preg_split('/\s+/u', trim(str_replace('/', ' ', $bezeichnung)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 3);
        foreach ($woerter as $wort) {
            if (preg_match('/^(\p{L}+)(\.|$)/u', $wort, $m) !== 1) {
                continue;   // nur Buchstaben, allein oder direkt vor einem Punkt
            }
            $k = mb_strtolower($m[1]);
            if (in_array($k, self::SPERRWOERTER, true)) {
                continue;
            }
            $marke = $eigen[$k] ?? $karte['global'][$k] ?? null;
            if ($marke !== null) {
                return [$marke, $m[1] . ($m[2] === '.' ? '.' : '')];
            }
        }

        return null;
    }

    /**
     * §5 Ausnahme 3: Rezepturware ohne belegte Marke → `marke_offen`. Nur bei rezepturware = true und leerer Klammer.
     */
    public static function markeOffen(?bool $rezepturware, ?string $zusatz): bool
    {
        return $rezepturware === true && trim((string) $zusatz) === '';
    }

    /** Dreiwertig aus Formular/MCP/KI: null/'' = nicht entschieden, sonst ja/nein ('1', '0', true, false, 'true', 'false'). */
    public static function dreiwertig(mixed $wert): ?bool
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        return filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** Wurst (04) und Getränke (15) sind nie Rezepturware im Sinn von §5 Ausnahme 3; sonst bleibt der Wert, wie er kam. */
    public static function rezepturwareFuerWg(?bool $rezepturware, ?string $warengruppe): ?bool
    {
        return in_array((string) $warengruppe, self::NIE_REZEPTURWARE_WG, true) ? false : $rezepturware;
    }

    /** Teilen-Hinweis (§5 Ausnahme 3): am Rezeptur-GP hängt schon ein Artikel mit anderer Marke. */
    public static function andereMarkeAmGp(FoodAlchemistGp $gp, MarkenErgebnis $artikel): bool
    {
        $amGp = mb_strtolower(trim((string) $gp->zusatz));

        return $gp->rezepturware === true && $amGp !== '' && $artikel->marke !== null && mb_strtolower($artikel->marke) !== $amGp;
    }

    public function gesperrt(string $wert): bool
    {
        $erstes = mb_strtolower(preg_split('/[\s.]+/u', trim($wert))[0] ?? '');

        return in_array($erstes, self::SPERRWOERTER, true);
    }

    /**
     * Händler und ungeklärte Lieferanten: ihr eigener Name ist nie eine Marke. Verglichen werden ganze Wörter, keine Teilstrings — „Chef“ (Nestlé) bei
     * Chefs Culinar bleibt eine Marke, „Chefs“ / „Chefs Culinar“ nicht.
     */
    private function istHaendlerName(string $wert, ?FoodAlchemistSupplier $lieferant): bool
    {
        if ($lieferant === null || $lieferant->rolle === SupplierRolle::Hersteller) {
            return false;
        }
        $woerter = fn (?string $x) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $x)) ?: []));
        $w = $woerter($wert);
        $name = $woerter($lieferant->name);
        $kurz = $woerter($lieferant->kurzname);
        if ($w === [] || $name === []) {
            return false;
        }

        return array_diff($w, $name) === []                                        // alle Wörter der Marke stehen im Lieferantennamen
            || ($kurz !== [] && array_diff($kurz, $w) === [])                         // Kurzname des Händlers steckt in der Marke
            || (mb_strlen($w[0]) >= 4 && $w[0] === $name[0]);                        // gleiches erstes Wort („CHEFS Feinkost“)
    }

    /** @return array<string, array<string, string>> */
    private function karte(): array
    {
        if ($this->karte !== null) {
            return $this->karte;
        }
        $this->karte = ['global' => []];
        foreach (FoodAlchemistSupplierMarke::query()->get(['supplier_id', 'kuerzel', 'marke']) as $z) {
            $schluessel = $z->supplier_id === null ? 'global' : (string) $z->supplier_id;
            $this->karte[$schluessel][mb_strtolower($z->kuerzel)] = $z->marke;
        }

        return $this->karte;
    }

    /** Nach Pflege/Seeder: Karte neu laden (der Dienst ist als Singleton registriert). */
    public function vergessen(): void
    {
        $this->karte = null;
    }
}
