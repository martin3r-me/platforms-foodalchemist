<?php

namespace Platform\FoodAlchemist\Services\Marken;

use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\SupplierRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\MandantenService;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 — EINE Schreibstelle für Lieferanten-Rolle, Kurzname und Marken-Kürzel (UI-Block und MCP).
 * Globale Regel-Daten: schreiben nur Plattform-Admin und Admin im Master-Team ({@see MandantenService::darfVerwaltung}); fachliche Fehler als RuntimeException.
 */
class MarkenPflegeService
{
    public function __construct(private readonly MarkeAusArtikel $marken) {}

    public function darf(?User $user): bool
    {
        return app(MandantenService::class)->darfVerwaltung($user);
    }

    public function rolleSetzen(?User $user, FoodAlchemistSupplier $lieferant, string $rolle, ?string $kurzname): FoodAlchemistSupplier
    {
        $this->pruefe($user);
        $r = SupplierRolle::tryFrom(trim($rolle)) ?? throw new \RuntimeException('Rolle muss hersteller, haendler oder offen sein.');
        $kurz = trim((string) $kurzname);
        if ($r === SupplierRolle::Hersteller && $kurz === '') {
            throw new \RuntimeException('Ein Hersteller braucht einen Kurznamen für die Klammer (z. B. Heisters).');
        }
        $lieferant->update(['rolle' => $r, 'kurzname' => $r === SupplierRolle::Hersteller ? mb_substr($kurz, 0, 60) : null]);
        $this->marken->vergessen();

        return $lieferant->refresh();
    }

    /** Kürzel setzen (neu oder Marke/Typ ändern); supplierId null = gilt für alle Lieferanten. */
    public function kuerzelSetzen(?User $user, ?int $supplierId, string $kuerzel, string $marke, string $typ = FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE,
        string $position = FoodAlchemistSupplierMarke::POSITION_ANFANG, int $vorrang = 0): FoodAlchemistSupplierMarke
    {
        $this->pruefe($user);
        $k = self::normKuerzel($kuerzel);
        $m = trim($marke);
        if ($k === '' || $m === '' || preg_match('/^\p{L}+(?:&\p{L}+)*$/u', $k) !== 1) {
            throw new \RuntimeException('Kürzel (nur Buchstaben, innen auch „&“, ohne Punkt) und Marke sind Pflicht.');
        }
        if (in_array($k, MarkeAusArtikel::SPERRWOERTER, true)) {
            throw new \RuntimeException('„' . mb_strtoupper($k) . '“ ist eine Zertifizierung, keine Marke.');
        }
        if (! in_array($typ, [FoodAlchemistSupplierMarke::TYP_EIGENMARKE, FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE], true)) {
            throw new \RuntimeException('Typ muss eigenmarke oder herstellermarke sein.');
        }
        if (! in_array($position, [FoodAlchemistSupplierMarke::POSITION_ANFANG, FoodAlchemistSupplierMarke::POSITION_ENDE], true)) {
            throw new \RuntimeException('Position muss anfang (erste drei Wörter) oder ende (letztes Wort) sein.');
        }
        $zeile = $this->zeile($supplierId, $k);
        $werte = ['marke' => mb_substr($m, 0, 80), 'typ' => $typ, 'position' => $position, 'vorrang' => max(0, min(9, $vorrang))];
        if ($zeile?->trashed()) {
            $zeile->restore();   // UNIQUE (supplier_id, kuerzel) gilt auch für entfernte Zeilen: wiederherstellen statt neu anlegen
        }
        $zeile = $zeile !== null ? tap($zeile)->update($werte) : FoodAlchemistSupplierMarke::create(['supplier_id' => $supplierId, 'kuerzel' => $k] + $werte);
        $this->marken->vergessen();

        return $zeile;
    }

    public function kuerzelEntfernen(?User $user, ?int $supplierId, string $kuerzel): bool
    {
        $this->pruefe($user);
        $zeile = $this->zeile($supplierId, self::normKuerzel($kuerzel));
        $aktiv = $zeile !== null && ! $zeile->trashed();
        if ($aktiv) {
            $zeile->delete();
        }
        $this->marken->vergessen();

        return $aktiv;
    }

    public static function normKuerzel(string $kuerzel): string
    {
        return mb_strtolower(rtrim(trim($kuerzel), '.'));
    }

    private function zeile(?int $supplierId, string $kuerzel): ?FoodAlchemistSupplierMarke
    {
        return FoodAlchemistSupplierMarke::withTrashed()->where('kuerzel', $kuerzel)
            ->when($supplierId === null, fn ($q) => $q->whereNull('supplier_id'), fn ($q) => $q->where('supplier_id', $supplierId))->first();
    }

    private function pruefe(?User $user): void
    {
        if (! $this->darf($user)) {
            throw new \Platform\FoodAlchemist\Exceptions\FaRechtFehltException(\Platform\FoodAlchemist\Enums\FaRolle::Admin, null,
                'Rolle, Kurzname und Marken-Kürzel pflegen (nur Plattform-Admin und Admin im Master-Team)');
        }
    }
}
