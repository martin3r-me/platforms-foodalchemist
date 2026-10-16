<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Symfony\Component\Uid\UuidV7;

/**
 * Aktionspreise (Entscheid Dominique 16.10.2026): EIN Platz, die Preis-Zeitachse `foodalchemist_price_history` mit status 'A'.
 * Quellen: Necta-Historie (type A, über den Historien-Import), Datei-Import und Handeingabe (hier). Reine Information: Lead, EK,
 * Preis-Linie und Trend rechnen immer mit dem Normalpreis (PriceService liest nur foodalchemist_prices, PreisVerlaufService lässt
 * 'A' aus Linie und Trend); am Artikel steht „Aktion bis …“, in „Frühere Preise“ die Aktion markiert.
 *
 * Team-Regel wie Teampreise (PriceService::createTeamPrice): die Zeile trägt das schreibende Team; schreiben darf das Besitzer-Team
 * bzw. ein Kunden-Hauptteam an einem sichtbaren Artikel.
 *
 * Idempotenz Datei: Schlüssel sha1('datei|item|team|von|bis') OHNE Preis (Kurator 16.10.): gleicher Schlüssel + gleicher Preis →
 * nichts; anderer Preis → alte Zeile ausblenden („durch Datei ersetzt“), neue schreiben. Kein Unique-Index (siehe Migration
 * 2026_11_07_100000), die Prüfung läuft in einer Transaktion mit Sperre auf den Artikel.
 */
class AktionspreisService
{
    public const STATUS = 'A';

    /** Grund beim Ersetzen durch eine korrigierte Datei. */
    public const GRUND_ERSETZT = 'durch Datei ersetzt';

    public static function dateiSchluessel(int $itemId, int $teamId, string $von, string $bis): string
    {
        return sha1("datei|{$itemId}|{$teamId}|{$von}|{$bis}");
    }

    /**
     * Aktion anlegen. `$von`/`$bis` als 'Y-m-d' (Europe/Berlin, ganztägig): gilt von 00:00 bis einschließlich `$bis`.
     *
     * @return array{status: 'neu'|'unveraendert'|'ersetzt', id: int}
     */
    public function anlegen(Team $team, FoodAlchemistSupplierItem $item, float $preis, string $von, string $bis, string $quelle,
        ?string $schluessel = null, array $details = [], ?int $userId = null): array
    {
        if ($preis <= 0) {
            throw new \RuntimeException('Aktionspreis braucht einen Betrag größer 0.');
        }
        $start = \Illuminate\Support\Carbon::parse($von, 'Europe/Berlin')->startOfDay();
        $ende = \Illuminate\Support\Carbon::parse($bis, 'Europe/Berlin')->addDay()->startOfDay();   // einschließlich „bis“
        if ($ende <= $start) {
            throw new \RuntimeException('Aktion: „bis“ liegt vor „ab“.');
        }
        $this->pruefeSchreibrecht($team, $item);
        $schluessel ??= $quelle === 'manuell' ? 'manuell|'.UuidV7::generate() : self::dateiSchluessel((int) $item->id, (int) $team->id, $von, $bis);

        return DB::transaction(function () use ($team, $item, $preis, $start, $ende, $quelle, $schluessel, $details, $userId) {
            DB::table('foodalchemist_supplier_items')->where('id', $item->id)->lockForUpdate()->first();   // serialisiert parallele Läufe
            $aktiv = DB::table('foodalchemist_price_history')->where('herkunft_schluessel', $schluessel)
                ->where('team_id', $team->id)->where('status', self::STATUS)->whereNull('ausgeblendet_at')->first();
            if ($aktiv !== null && abs((float) $aktiv->price - $preis) < 0.00005) {
                return ['status' => 'unveraendert', 'id' => (int) $aktiv->id];
            }
            if ($aktiv !== null) {
                DB::table('foodalchemist_price_history')->where('id', $aktiv->id)->update(['ausgeblendet_at' => now(), 'ausgeblendet_von' => $userId,
                    'ausgeblendet_grund' => self::GRUND_ERSETZT, 'ausgeblendet_preis' => $aktiv->price, 'updated_at' => now()]);
            }
            $id = (int) DB::table('foodalchemist_price_history')->insertGetId(['uuid' => (string) UuidV7::generate(), 'price_id' => null,
                'herkunft_schluessel' => $schluessel, 'supplier_item_id' => $item->id, 'team_id' => $team->id, 'status' => self::STATUS,
                'price' => round($preis, 4), 'status_valid_from' => $start->utc()->toDateTimeString(), 'valid_to' => $ende->utc()->toDateTimeString(),
                'is_blocked' => false, 'quelle' => $quelle, 'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now()]);

            return ['status' => $aktiv !== null ? 'ersetzt' : 'neu', 'id' => $id];
        });
    }

    /**
     * Die jetzt gültige, für das Team sichtbare Aktion eines Artikels (nicht ausgeblendet), die am frühesten endende zuerst.
     * Sichtbar wie Preise: global (team NULL), Team-Kette des Betrachters oder Besitzer-Team des Artikels.
     */
    public function aktivFuer(?Team $team, FoodAlchemistSupplierItem $item, ?\DateTimeInterface $jetzt = null): ?object
    {
        $jetzt = \Illuminate\Support\Carbon::instance($jetzt ?? now())->utc()->toDateTimeString();
        $teams = $team !== null ? FoodAlchemistSupplierItem::teamAncestryIds($team) : [];
        if ($item->team_id !== null) {
            $teams[] = (int) $item->team_id;
        }

        return DB::table('foodalchemist_price_history')->where('supplier_item_id', $item->id)->where('status', self::STATUS)
            ->where('status_valid_from', '<=', $jetzt)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $jetzt))
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', array_values(array_unique($teams))))
            ->where(fn ($q) => $q->whereNull('ausgeblendet_at')->orWhereNull('ausgeblendet_preis')
                ->orWhereRaw('ABS(price - ausgeblendet_preis) >= 0.00005'))   // wie PreisVerlaufService::istAusgeblendet
            ->orderBy('valid_to')->orderBy('price')->first(['id', 'price', 'status_valid_from', 'valid_to', 'quelle', 'team_id']);
    }

    /**
     * Unterabfrage für Listen (ohne N+1): Ende (valid_to, UTC) der jetzt gültigen, sichtbaren Aktion des äußeren Artikels oder NULL.
     * Gleiche Regeln wie {@see aktivFuer()}.
     */
    public function aktivBisSubquery(string $outerColumn, ?Team $team): \Illuminate\Database\Query\Builder
    {
        $jetzt = now()->utc()->toDateTimeString();
        $teams = $team !== null ? FoodAlchemistSupplierItem::teamAncestryIds($team) : [];

        return DB::table('foodalchemist_price_history AS ah')->select('ah.valid_to')
            ->whereColumn('ah.supplier_item_id', $outerColumn)->where('ah.status', self::STATUS)
            ->where('ah.status_valid_from', '<=', $jetzt)->where(fn ($q) => $q->whereNull('ah.valid_to')->orWhere('ah.valid_to', '>', $jetzt))
            ->where(fn ($q) => $q->whereNull('ah.team_id')->orWhereIn('ah.team_id', $teams === [] ? [0] : $teams)
                ->orWhereColumn('ah.team_id', str_replace('.id', '.team_id', $outerColumn)))
            ->where(fn ($q) => $q->whereNull('ah.ausgeblendet_at')->orWhereNull('ah.ausgeblendet_preis')
                ->orWhereRaw('ABS(ah.price - ah.ausgeblendet_preis) >= 0.00005'))
            ->orderBy('ah.valid_to')->limit(1);
    }

    private function pruefeSchreibrecht(Team $team, FoodAlchemistSupplierItem $item): void
    {
        if ($item->isOwnedBy($team)) {
            return;
        }
        if (! PriceService::darfTeampreisSchreiben($team)) {
            throw new \RuntimeException('Unter-Teams erben die Preise ihres Oberteams — eigene Aktionen je Unter-Team gibt es nicht (Spec 82 §3).');
        }
        if (! FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($item->id)->exists()) {
            throw new \RuntimeException('Artikel ist für dieses Team nicht sichtbar — keine Aktion möglich.');
        }
    }
}
