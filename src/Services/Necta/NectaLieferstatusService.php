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
        if ($probe) {
            return;
        }
        $schluessel = ['art' => 'eingestellt_widerspruch', 'objekt' => 'price', 'legacy_id' => (int) $eingestellt->legacy_id, 'feld' => 'status', 'status' => 'offen'];
        $werte = ['fa_wert' => 'Normalpreis in der Necta-Historie nach dem Eingestellt-Beginn',
            'necta_wert' => 'eingestellt seit '.$eingestellt->status_valid_from, 'lauf_id' => $laufId, 'updated_at' => now()];
        $offen = DB::table('foodalchemist_necta_pruefaelle')->where($schluessel)->value('id');
        $offen !== null
            ? DB::table('foodalchemist_necta_pruefaelle')->where('id', $offen)->update($werte)
            : DB::table('foodalchemist_necta_pruefaelle')->insert(['uuid' => (string) UuidV7::generate()] + $schluessel + $werte + ['created_at' => now()]);
    }

    private static function tag(?string $zeit): ?string
    {
        return $zeit === null ? null : substr((string) $zeit, 0, 19);
    }
}
