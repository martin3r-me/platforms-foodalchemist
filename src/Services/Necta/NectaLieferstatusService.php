<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\PriceCategory;
use Symfony\Component\Uid\UuidV7;

/**
 * Lieferstatus aus dem Necta-Preis-Lebenszyklus (16.10.2026, Belege in {@see PriceCategory}): die jüngste offene Necta-Preiszeile
 * des Artikels im Team der Preise entscheidet:
 *  - status '0' mit Preis → gelistet (lieferstatus NULL)
 *  - status '2' mit Preis → 'auslaufend' seit statusValidFrom (Preis gilt weiter, bleibt aktiv)
 *  - status '2' ohne Preis → 'eingestellt' seit statusValidFrom (nicht mehr bestellbar)
 * Bei 'eingestellt' werden die übrigen offenen Preiszeilen des Artikels/Teams (jeder Status) und der offene Necta-Historienstand
 * auf den Eingestellt-Beginn begrenzt, damit Preis-Linie und „aktiver Preis“ dort enden. Gibt es in der Necta-Historie einen
 * Normalpreis NACH dem Eingestellt-Beginn (io: 1,3 %), wird nichts automatisch entschieden → Prüffall `eingestellt_widerspruch`.
 *
 * Nur Necta-Zeilen (legacy_id gesetzt); ein von Hand gesetzter Lieferstatus (quelle ≠ 'necta') wird nie überschrieben.
 */
class NectaLieferstatusService
{
    public const QUELLE = 'necta';

    /** Von einem Menschen im Prüffall entschieden (PR 3): Necta überschreibt den Status nicht mehr, meldet aber neue Widersprüche. */
    public const QUELLE_PRUEFUNG = 'pruefung';

    /**
     * @return array{lieferstatus: ?string, seit: ?string, begrenzt: int, widerspruch: bool, geaendert: bool}
     */
    public function aktualisieren(int $faArtikelId, ?int $teamId, bool $probe = false, ?int $laufId = null): array
    {
        $raus = ['lieferstatus' => null, 'seit' => null, 'begrenzt' => 0, 'widerspruch' => false, 'geaendert' => false];
        $offen = DB::table('foodalchemist_prices')->where('supplier_item_id', $faArtikelId)->whereNotNull('legacy_id')
            ->where('team_id', $teamId)->whereNull('valid_to')->whereNull('deleted_at')
            ->orderByDesc('status_valid_from')->orderByDesc('id')->get(['id', 'status', 'price', 'status_valid_from', 'legacy_id']);
        $juengste = $offen->first();
        if ($juengste !== null) {
            $kat = PriceCategory::fuer($juengste->price !== null ? (float) $juengste->price : null, $juengste->status === null ? null : (string) $juengste->status);
            $raus['lieferstatus'] = match ($kat) {
                PriceCategory::Auslaufend => 'auslaufend',
                PriceCategory::Eingestellt => 'eingestellt',
                default => null,
            };
            $raus['seit'] = $raus['lieferstatus'] !== null ? $juengste->status_valid_from : null;
        }

        $artikel = DB::table('foodalchemist_supplier_items')->where('id', $faArtikelId)->first(['lieferstatus', 'lieferstatus_seit', 'lieferstatus_quelle']);
        if ($artikel !== null && $artikel->lieferstatus_quelle === self::QUELLE_PRUEFUNG) {
            // im Prüffall entschieden: Status bleibt, aber ein NEUER Widerspruch darf kein toter Winkel werden (Kurator 18.10., F2)
            $this->entschiedenenStatusPruefen($faArtikelId, $teamId, $artikel, $juengste, $raus, $probe, $laufId);

            return $raus;
        }
        if ($artikel === null || ($artikel->lieferstatus_quelle !== null && $artikel->lieferstatus_quelle !== self::QUELLE)) {
            return $raus;   // Handpflege gewinnt
        }
        $raus['geaendert'] = $artikel->lieferstatus !== $raus['lieferstatus'] || self::tag($artikel->lieferstatus_seit) !== self::tag($raus['seit']);

        if ($raus['lieferstatus'] === 'eingestellt' && $raus['seit'] !== null) {
            $raus['widerspruch'] = DB::table('foodalchemist_price_history')->where('supplier_item_id', $faArtikelId)->where('team_id', $teamId)
                ->where('quelle', 'necta_historie')->where('status', '0')->where('status_valid_from', '>', $raus['seit'])->exists();
            if ($raus['widerspruch']) {
                $this->pruefall($juengste, $probe, $laufId);
            } else {
                $raus['begrenzt'] = $this->begrenzen($faArtikelId, $teamId, (int) $juengste->id, (string) $raus['seit'], $probe, $laufId);
            }
        }
        if (! $probe && $raus['geaendert']) {
            DB::table('foodalchemist_supplier_items')->where('id', $faArtikelId)->update([
                'lieferstatus' => $raus['lieferstatus'], 'lieferstatus_seit' => $raus['seit'],
                'lieferstatus_quelle' => $raus['lieferstatus'] === null ? null : self::QUELLE, 'updated_at' => now(),
            ]);
        }

        return $raus;
    }

    /** Offene Preiszeilen (außer der Eingestellt-Zeile) und offene Necta-Historie enden mit dem Eingestellt-Beginn. */
    private function begrenzen(int $faArtikelId, ?int $teamId, int $eingestelltId, string $seit, bool $probe, ?int $laufId): int
    {
        $preise = DB::table('foodalchemist_prices')->where('supplier_item_id', $faArtikelId)->where('team_id', $teamId)
            ->where('id', '!=', $eingestelltId)->whereNull('valid_to')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status_valid_from')->orWhere('status_valid_from', '<', $seit))->get();
        $historie = DB::table('foodalchemist_price_history')->where('supplier_item_id', $faArtikelId)->where('team_id', $teamId)
            ->where('quelle', 'necta_historie')->whereNull('valid_to')->where('status_valid_from', '<', $seit);
        $n = $preise->count() + (clone $historie)->count();
        if ($probe) {
            return $n;
        }
        foreach ($preise as $p) {
            // Stand vor der Begrenzung festhalten, wie der Abgleich vor jedem Update
            DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'price_id' => $p->id, 'legacy_id' => $p->legacy_id,
                'supplier_item_id' => $p->supplier_item_id, 'team_id' => $p->team_id, 'status' => $p->status, 'price' => $p->price,
                'price_partial' => $p->price_partial, 'valid_to' => $p->valid_to, 'status_valid_from' => $p->status_valid_from,
                'is_blocked' => $p->is_blocked, 'change_date' => $p->change_date, 'quelle' => 'necta_eingestellt', 'lauf_id' => $laufId,
                'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('foodalchemist_prices')->where('id', $p->id)->update(['valid_to' => $seit, 'updated_at' => now()]);
        }
        $historie->update(['valid_to' => $seit, 'updated_at' => now()]);

        return $n;
    }

    private function pruefall(object $eingestellt, bool $probe, ?int $laufId): void
    {
        $this->widerspruch((int) $eingestellt->legacy_id, 'Normalpreis in der Necta-Historie nach dem Eingestellt-Beginn',
            'eingestellt seit '.$eingestellt->status_valid_from, $probe, $laufId);
    }

    /** Prüffall eingestellt_widerspruch anlegen bzw. aktualisieren — außer, derselbe Necta-Wert wurde schon mit „aktiv lassen“ entschieden. */
    private function widerspruch(int $preisLegacy, string $faWert, string $nectaWert, bool $probe, ?int $laufId): void
    {
        if ($probe || NectaPruefallSperre::schonVerworfen('eingestellt_widerspruch', 'price', $preisLegacy, 'status', $nectaWert)) {
            return;
        }
        $schluessel = ['art' => 'eingestellt_widerspruch', 'objekt' => 'price', 'legacy_id' => $preisLegacy, 'feld' => 'status', 'status' => 'offen'];
        $werte = ['fa_wert' => $faWert, 'necta_wert' => $nectaWert, 'lauf_id' => $laufId, 'updated_at' => now()];
        $offen = DB::table('foodalchemist_necta_pruefaelle')->where($schluessel)->value('id');
        $offen !== null
            ? DB::table('foodalchemist_necta_pruefaelle')->where('id', $offen)->update($werte)
            : DB::table('foodalchemist_necta_pruefaelle')->insert(['uuid' => (string) UuidV7::generate()] + $schluessel + $werte + ['created_at' => now()]);
    }

    /**
     * Status mit Quelle „pruefung“: bestätigt eingestellt → ein Necta-Normalpreis NACH dem bestätigten Datum ist ein neuer Widerspruch;
     * aktiv gelassen → eine (neue) Necta-Eingestellt-Zeile ist ein neuer Widerspruch. Der Status selbst ändert sich nie automatisch.
     *
     * @param  array<string,mixed>  $raus
     */
    private function entschiedenenStatusPruefen(int $faArtikelId, ?int $teamId, object $artikel, ?object $juengste, array &$raus, bool $probe, ?int $laufId): void
    {
        if ($artikel->lieferstatus === 'eingestellt' && $artikel->lieferstatus_seit !== null) {
            $seit = (string) $artikel->lieferstatus_seit;
            $normal = DB::table('foodalchemist_prices')->where('supplier_item_id', $faArtikelId)->where('team_id', $teamId)->whereNotNull('legacy_id')
                ->whereNull('deleted_at')->where('status', '0')->whereNotNull('price')->where('status_valid_from', '>', $seit)
                ->orderBy('status_valid_from')->first(['legacy_id', 'status_valid_from'])
                ?? DB::table('foodalchemist_price_history')->where('supplier_item_id', $faArtikelId)->where('team_id', $teamId)
                    ->where('quelle', 'necta_historie')->where('status', '0')->where('status_valid_from', '>', $seit)
                    ->orderBy('status_valid_from')->first(['legacy_id', 'status_valid_from']);
            if ($normal !== null) {
                // Schlüssel = die bestätigte Eingestellt-Zeile (steht sicher in prices); der neue Normalpreis steht im Necta-Wert → die Sperre
                // greift nur für genau diesen Normalpreis, ein späterer wird wieder gemeldet
                $eingestelltLegacy = DB::table('foodalchemist_prices')->where('supplier_item_id', $faArtikelId)->where('team_id', $teamId)
                    ->whereNotNull('legacy_id')->whereNull('deleted_at')->where('status_valid_from', $seit)->orderByDesc('id')->value('legacy_id')
                    ?? $normal->legacy_id;
                if ($eingestelltLegacy !== null) {
                    $raus['widerspruch'] = true;
                    $this->widerspruch((int) $eingestelltLegacy, 'als eingestellt bestätigt seit '.$seit, 'Normalpreis ab '.$normal->status_valid_from, $probe, $laufId);
                }
            }

            return;
        }
        if ($artikel->lieferstatus === null && $raus['lieferstatus'] === 'eingestellt' && $juengste !== null) {
            $raus['widerspruch'] = true;
            $this->widerspruch((int) $juengste->legacy_id, 'aktiv gelassen (Prüffall-Entscheidung)', 'eingestellt seit '.$juengste->status_valid_from, $probe, $laufId);
        }
    }

    /** Prüffall-Entscheidung „als eingestellt bestätigen“: Status eingestellt seit dem Eingestellt-Beginn, Quelle pruefung, Preise begrenzt. */
    public function alsEingestelltBestaetigen(object $eingestelltPreis): int
    {
        $seit = (string) $eingestelltPreis->status_valid_from;
        $n = $this->begrenzen((int) $eingestelltPreis->supplier_item_id, $eingestelltPreis->team_id !== null ? (int) $eingestelltPreis->team_id : null,
            (int) $eingestelltPreis->id, $seit, false, null);
        DB::table('foodalchemist_supplier_items')->where('id', $eingestelltPreis->supplier_item_id)->update([
            'lieferstatus' => 'eingestellt', 'lieferstatus_seit' => $seit, 'lieferstatus_quelle' => self::QUELLE_PRUEFUNG, 'updated_at' => now()]);

        return $n;
    }

    /** Prüffall-Entscheidung „aktiv lassen“: kein Lieferstatus, Quelle pruefung (Necta setzt ihn nicht wieder still auf eingestellt). */
    public function aktivLassen(int $faArtikelId): void
    {
        DB::table('foodalchemist_supplier_items')->where('id', $faArtikelId)->update([
            'lieferstatus' => null, 'lieferstatus_seit' => null, 'lieferstatus_quelle' => self::QUELLE_PRUEFUNG, 'updated_at' => now()]);
    }

    private static function tag(?string $zeit): ?string
    {
        return $zeit === null ? null : substr((string) $zeit, 0, 19);
    }
}
