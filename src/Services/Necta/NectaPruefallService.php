<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\NectaPruefallException;
use Platform\FoodAlchemist\Exceptions\NectaPruefallUebersprungen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassAbweichungService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\ProduktpassSchutz;

/**
 * Necta-Prüffälle entscheiden (PR 3, Kurator 18.10.): Liste mit Filtern, Vorschau mit Prüfschlüssel, Einzel- und Sammel-Entscheidung.
 * Nur das Master-Team (es pflegt den globalen Katalog, aus dem Necta kommt).
 *
 * - produktpass_abweichung → {@see ProduktpassAbweichungService} (ein Weg, Pass-Vorrang bleibt dort).
 * - allergen_aenderung / deklaration_aenderung / manuell_gepflegt: „Necta übernehmen“ schreibt den Necta-Wert und setzt den Necta-Stand
 *   des Felds; nicht bei Produktpass-Marker (Weg über den Pass) und nicht, wenn der FA-Wert inzwischen ein anderer ist (veraltet:
 *   übersprungen und gemeldet). „FA behalten“ → verworfen; derselbe Necta-Wert wird nicht wieder gemeldet ({@see NectaPruefallSperre}).
 * - eingestellt_widerspruch: „als eingestellt bestätigen“ / „aktiv lassen“ — Status mit Quelle pruefung ({@see NectaLieferstatusService}).
 * - übrige Arten (in_necta_entfernt …): nur „FA behalten“ (zur Kenntnis).
 * Nach Allergen-/Deklarations-Übernahmen werden die betroffenen Rezepte nachgerechnet ({@see NectaRezeptNachrechnen}).
 *
 * Rechte: Einzelentscheidung ab Kuratieren; Sammel-Entscheidung „Necta übernehmen“ an Allergenen/Deklaration (Lockerungen) nur Admin.
 */
class NectaPruefallService
{
    public const AKTIONEN = ['necta_uebernehmen', 'fa_behalten', 'eingestellt_bestaetigen', 'aktiv_lassen'];

    public const UEBERNEHMBAR = ['allergen_aenderung', 'deklaration_aenderung', 'manuell_gepflegt'];

    private const ARTIKEL_OBJEKTE = ['supplier_item', 'item_allergen', 'item_declaration', 'item_nutritional'];

    private const TABELLEN = ['item_allergen' => 'foodalchemist_item_allergens', 'item_declaration' => 'foodalchemist_item_declarations',
        'item_nutritional' => 'foodalchemist_item_nutritionals'];

    public function __construct(private FaRechte $rechte, private NectaLieferstatusService $lieferstatus)
    {
    }

    // ── Lesen ────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{art?:?string, lieferant?:?int, feld?:?string, status?:?string, suche?:?string, normalpreis_tage?:?int}  $filter
     * @return array{faelle: list<array<string,mixed>>, gesamt: int, seite: int, pro_seite: int}
     */
    public function liste(Team $team, array $filter = [], int $seite = 1, int $proSeite = 50): array
    {
        $this->nurMaster($team);
        $seite = max(1, $seite);
        $q = $this->abfrage($filter);
        $gesamt = (clone $q)->count();
        $zeilen = $q->orderBy('pf.id')->forPage($seite, $proSeite)->get();

        return ['faelle' => $this->anreichern($zeilen), 'gesamt' => $gesamt, 'seite' => $seite, 'pro_seite' => $proSeite];
    }

    /**
     * Zähler für die Filterleiste: offene Fälle je Art und je Lieferant (unter den übrigen Filtern).
     *
     * @return array{je_art: array<string,int>, je_lieferant: list<array{id:int, name:string, n:int}>}
     */
    public function zaehler(Team $team, array $filter = []): array
    {
        $this->nurMaster($team);
        $ohneArt = $filter;
        unset($ohneArt['art']);
        $ohneLieferant = $filter;
        unset($ohneLieferant['lieferant']);

        return [
            'je_art' => $this->abfrage($ohneArt)->groupBy('pf.art')->selectRaw('pf.art, COUNT(*) AS n')->pluck('n', 'art')->map(fn ($n) => (int) $n)->all(),
            'je_lieferant' => $this->abfrage($ohneLieferant)->join('foodalchemist_suppliers as su', 'su.id', '=', DB::raw('COALESCE(sid.supplier_id, sip.supplier_id)'))
                ->groupBy('su.id', 'su.name')->selectRaw('su.id, su.name, COUNT(*) AS n')->orderByDesc('n')->limit(200)->get()
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'n' => (int) $r->n])->all(),
        ];
    }

    /**
     * Was eine Entscheidung träfe — Grundlage des Bestätigungs-Dialogs. Der Schlüssel bindet die Ausführung an GENAU diese Fälle.
     *
     * @param  list<int>|null  $ids  null = alle offenen Fälle unter $filter
     * @return array{anzahl:int, schluessel:string, je_art:array<string,int>, je_lieferant:array<string,int>, allergene:list<array{feld:string, label:string, von:?string, nach:?string, n:int, artikel:list<string>}>, admin_noetig:bool}
     */
    public function vorschau(Team $team, ?array $ids, array $filter, string $aktion): array
    {
        $this->nurMaster($team);
        $this->aktionPruefen($aktion);
        $zeilen = $this->offeneFaelle($ids, $filter);
        $allergene = [];
        foreach ($zeilen as $z) {
            if (! in_array($z->objekt, ['item_allergen', 'item_declaration'], true)) {
                continue;
            }
            $k = $z->feld.'|'.$z->fa_wert.'|'.$z->necta_wert;
            $allergene[$k] ??= ['feld' => (string) $z->feld, 'label' => ProduktpassAbweichungService::label($z->objekt, (string) $z->feld),
                'von' => $z->fa_wert, 'nach' => $z->necta_wert, 'n' => 0, 'artikel' => []];
            $allergene[$k]['n']++;
            if (count($allergene[$k]['artikel']) < 20) {
                $allergene[$k]['artikel'][] = (string) ($z->artikel ?? $z->legacy_id);
            }
        }
        usort($allergene, fn ($a, $b) => $b['n'] <=> $a['n']);

        return [
            'anzahl' => $zeilen->count(),
            'schluessel' => self::schluessel($aktion, $zeilen->pluck('id')->all()),
            'je_art' => $zeilen->countBy('art')->all(),
            'je_lieferant' => $zeilen->countBy(fn ($z) => (string) ($z->lieferant ?? '—'))->all(),
            'allergene' => array_values($allergene),
            'admin_noetig' => $this->adminNoetig($zeilen, $aktion),
        ];
    }

    // ── Entscheiden ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<int>|null  $ids  null = alle offenen Fälle unter $filter (dann ist der Schlüssel Pflicht)
     * @return array{erledigt:int, uebersprungen: array<int,string>, rezepte_nachgerechnet:int}
     */
    public function entscheiden(Team $team, ?array $ids, array $filter, string $aktion, ?int $userId, ?string $schluessel = null): array
    {
        $this->nurMaster($team);
        $this->aktionPruefen($aktion);
        $zeilen = $this->offeneFaelle($ids, $filter);
        if ($zeilen->isEmpty()) {
            throw new NectaPruefallException('LEER', 'Keine offenen Prüffälle für diese Auswahl.');
        }
        if (($zeilen->count() > 1 || $ids === null) && $schluessel !== self::schluessel($aktion, $zeilen->pluck('id')->all())) {
            throw new NectaPruefallException('LISTE_GEAENDERT', 'Die Auswahl hat sich seit der Vorschau geändert — bitte neu prüfen und bestätigen.');
        }
        $rolle = $this->adminNoetig($zeilen, $aktion) ? FaRolle::Admin : FaRolle::Kuratieren;
        $this->rechte->pruefeId($userId, $team, $rolle, $rolle === FaRolle::Admin
            ? 'Necta-Werte an Allergenen/Deklarationen gesammelt übernehmen' : 'Necta-Prüffälle entscheiden');

        $raus = ['erledigt' => 0, 'uebersprungen' => [], 'rezepte_nachgerechnet' => 0];
        $kennzeichnung = [];
        foreach ($zeilen as $fall) {
            try {
                $artikelId = $this->entscheideEinen($team, $fall, $aktion, $userId, $rolle);
                if ($artikelId !== null && in_array($fall->objekt, NectaAbgleichService::KENNZEICHNUNG, true) && $aktion === 'necta_uebernehmen') {
                    $kennzeichnung[$artikelId] = true;
                }
                $raus['erledigt']++;
            } catch (NectaPruefallUebersprungen $e) {
                $raus['uebersprungen'][(int) $fall->id] = $e->getMessage();
            }
        }
        if ($kennzeichnung !== []) {
            $r = app(NectaRezeptNachrechnen::class)->nachrechnen(array_keys($kennzeichnung), 'Necta-Prüffall-Entscheidung',
                'necta_rezepte_nachrechnen_fehler:pruefall:'.now()->format('YmdHis'));
            $raus['rezepte_nachgerechnet'] = $r['nachgerechnet'];
        }

        return $raus;
    }

    /** @return int|null FA-Artikel-ID, wenn ein Artikelwert geschrieben wurde */
    private function entscheideEinen(Team $team, object $fall, string $aktion, ?int $userId, FaRolle $rolle): ?int
    {
        if ($fall->art === ProduktpassAbweichungService::ART) {
            $svc = app(ProduktpassAbweichungService::class);
            if (! in_array($aktion, ['necta_uebernehmen', 'fa_behalten'], true)) {
                throw new NectaPruefallUebersprungen('Aktion passt nicht zu einer Produktpass-Abweichung.');
            }
            try {
                $aktion === 'necta_uebernehmen'
                    ? $svc->uebernehmen($team, (int) $fall->id, $userId, $rolle)
                    : $svc->behalten($team, (int) $fall->id, $userId, $rolle);
            } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException $e) {
                throw $e;
            } catch (\Illuminate\Database\QueryException $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                throw new NectaPruefallUebersprungen($e->getMessage(), 0, $e);   // fachliche Ablehnung des Pass-Wegs: nur dieser Fall
            }

            return $fall->artikel_id !== null ? (int) $fall->artikel_id : null;
        }
        if ($fall->art === 'eingestellt_widerspruch') {
            return $this->eingestellt($fall, $aktion, $userId);
        }
        if ($aktion === 'fa_behalten') {
            $this->status($fall, 'verworfen', $userId);

            return null;
        }
        if ($aktion !== 'necta_uebernehmen' || ! in_array($fall->art, self::UEBERNEHMBAR, true)) {
            throw new NectaPruefallUebersprungen('Für diese Prüffall-Art geht nur „FA behalten“.');
        }

        return $this->nectaUebernehmen($fall, $userId);
    }

    private function nectaUebernehmen(object $fall, ?int $userId): int
    {
        if ($fall->artikel_id === null) {
            throw new NectaPruefallUebersprungen('Der Artikel zu diesem Prüffall existiert nicht mehr.');
        }
        $artikelId = (int) $fall->artikel_id;
        $feld = (string) $fall->feld;
        $unterart = str_starts_with($feld, 'details.') ? substr($feld, 8) : null;
        $spalte = $unterart !== null ? 'details' : $feld;
        $tabelle = $fall->objekt === 'supplier_item' ? 'foodalchemist_supplier_items' : (self::TABELLEN[$fall->objekt] ?? null);
        if ($tabelle === null) {
            throw new NectaPruefallUebersprungen('Unbekanntes Objekt.');
        }
        $zeile = $fall->objekt === 'supplier_item'
            ? DB::table($tabelle)->where('id', $artikelId)->first()
            : DB::table($tabelle)->where('supplier_item_id', $artikelId)->whereNull('deleted_at')->first();
        if ($zeile === null) {
            throw new NectaPruefallUebersprungen('Die FA-Zeile fehlt — der nächste Vollabgleich legt sie an.');
        }
        $details = $unterart !== null ? self::jsonArray($zeile->details ?? null) : null;
        $aktuell = $unterart !== null ? ($details[$unterart] ?? null) : ($zeile->{$spalte} ?? null);
        if (NectaAbgleichService::norm($aktuell) !== NectaAbgleichService::norm($fall->fa_wert)) {
            throw new NectaPruefallUebersprungen('Veraltet: der FA-Wert ist inzwischen ein anderer — der nächste Vollabgleich prüft neu.');
        }
        if (ProduktpassSchutz::schuetzt($fall->objekt, $zeile, $spalte)) {
            throw new NectaPruefallUebersprungen('Das Feld stammt aus dem Produktpass — Entscheidung im Reiter Produktpass.');
        }

        if ($unterart !== null) {
            if ($fall->necta_wert === null) {
                unset($details[$unterart]);   // NULL = unbekannt: Schlüssel fällt weg, wie im Abgleich
            } else {
                $details[$unterart] = $fall->necta_wert;
            }
            ksort($details);
            $wert = $details === [] ? null : json_encode($details);
        } else {
            $wert = $fall->necta_wert;
        }
        DB::transaction(function () use ($tabelle, $zeile, $spalte, $wert, $fall, $unterart, $userId) {
            DB::table($tabelle)->where('id', $zeile->id)->update([$spalte => $wert, 'updated_at' => now()]);
            $this->standSetzen($fall, $spalte, $unterart);
            $this->status($fall, 'uebernommen', $userId);
        });

        return $artikelId;
    }

    /** Necta-Stand des Felds auf den übernommenen Necta-Wert — der nächste Lauf sieht das Feld dann als „nicht von Hand“. */
    private function standSetzen(object $fall, string $spalte, ?string $unterart): void
    {
        $zeile = DB::table('foodalchemist_necta_stand')->where('objekt', $fall->objekt)->where('legacy_id', $fall->legacy_id)->first();
        $werte = $zeile !== null ? (array) json_decode((string) $zeile->werte, true) : [];
        if ($unterart !== null) {
            $d = self::jsonArray($werte['details'] ?? null);
            if ($fall->necta_wert === null) {
                unset($d[$unterart]);
            } else {
                $d[$unterart] = $fall->necta_wert;
            }
            $werte['details'] = $d === [] ? null : $d;
        } else {
            $werte[$spalte] = $fall->necta_wert;
        }
        DB::table('foodalchemist_necta_stand')->updateOrInsert(['objekt' => $fall->objekt, 'legacy_id' => $fall->legacy_id],
            ['werte' => json_encode($werte), 'updated_at' => now(), 'created_at' => $zeile->created_at ?? now()]);
    }

    private function eingestellt(object $fall, string $aktion, ?int $userId): ?int
    {
        if (! in_array($aktion, ['eingestellt_bestaetigen', 'aktiv_lassen'], true)) {
            throw new NectaPruefallUebersprungen('Für einen Eingestellt-Widerspruch: „als eingestellt bestätigen“ oder „aktiv lassen“.');
        }
        $preis = DB::table('foodalchemist_prices')->where('legacy_id', $fall->legacy_id)->first();
        if ($preis === null) {
            throw new NectaPruefallUebersprungen('Die Preiszeile zu diesem Prüffall existiert nicht mehr.');
        }
        $artikel = DB::table('foodalchemist_supplier_items')->where('id', $preis->supplier_item_id)->first(['id', 'lieferstatus', 'lieferstatus_quelle']);
        $bestaetigtEingestellt = $artikel !== null && $artikel->lieferstatus_quelle === NectaLieferstatusService::QUELLE_PRUEFUNG
            && $artikel->lieferstatus === 'eingestellt';
        DB::transaction(function () use ($fall, $aktion, $userId, $preis, $bestaetigtEingestellt) {
            if ($bestaetigtEingestellt) {
                // Fall „neuer Normalpreis nach bestätigtem Eingestellt-Datum“: bestätigen = Status bleibt (dieser Normalpreis fragt nicht wieder)
                if ($aktion === 'aktiv_lassen') {
                    $this->lieferstatus->aktivLassen((int) $preis->supplier_item_id);
                }
                $this->status($fall, $aktion === 'aktiv_lassen' ? 'uebernommen' : 'verworfen', $userId);

                return;
            }
            if ($aktion === 'eingestellt_bestaetigen') {
                $this->lieferstatus->alsEingestelltBestaetigen($preis);
                $this->status($fall, 'uebernommen', $userId);

                return;
            }
            $this->lieferstatus->aktivLassen((int) $preis->supplier_item_id);
            $this->status($fall, 'verworfen', $userId);   // dieselbe Eingestellt-Angabe fragt nicht wieder
        });

        return null;
    }

    private function status(object $fall, string $status, ?int $userId): void
    {
        DB::table('foodalchemist_necta_pruefaelle')->where('id', $fall->id)->where('status', 'offen')
            ->update(['status' => $status, 'erledigt_von' => $userId, 'erledigt_at' => now(), 'updated_at' => now()]);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function nurMaster(Team $team): void
    {
        if (! MasterTeam::istMaster($team)) {
            throw new NectaPruefallException('NUR_MASTER', 'Necta-Prüffälle entscheidet das Master-Team.');
        }
    }

    private function aktionPruefen(string $aktion): void
    {
        if (! in_array($aktion, self::AKTIONEN, true)) {
            throw new NectaPruefallException('AKTION_UNGUELTIG', 'Unbekannte Aktion: '.$aktion);
        }
    }

    /** Sammel-Übernahme an Allergenen/Deklaration (Lockerungen) ist sicherheitsrelevant → Admin (Entscheid Kurator F1). */
    private function adminNoetig(\Illuminate\Support\Collection $zeilen, string $aktion): bool
    {
        return $aktion === 'necta_uebernehmen' && $zeilen->count() > 1
            && $zeilen->contains(fn ($z) => in_array($z->objekt, NectaAbgleichService::KENNZEICHNUNG, true));
    }

    /** @param list<int|string> $ids */
    public static function schluessel(string $aktion, array $ids): string
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return sha1($aktion.'|'.implode(',', $ids));
    }

    private function offeneFaelle(?array $ids, array $filter): \Illuminate\Support\Collection
    {
        $q = $this->abfrage(['status' => 'offen'] + $filter);
        if ($ids !== null) {
            $q->whereIn('pf.id', array_map('intval', $ids));
        }

        return $q->orderBy('pf.id')->get();
    }

    /** Grundabfrage: Prüffall + Artikel (über Necta-ID; bei Preisen über die Preiszeile) + Lieferant. */
    private function abfrage(array $filter): Builder
    {
        $q = DB::table('foodalchemist_necta_pruefaelle as pf')
            ->leftJoin('foodalchemist_supplier_items as sid', function ($j) {
                $j->on('sid.legacy_id', '=', 'pf.legacy_id')->whereIn('pf.objekt', self::ARTIKEL_OBJEKTE);
            })
            ->leftJoin('foodalchemist_prices as pr', function ($j) {
                $j->on('pr.legacy_id', '=', 'pf.legacy_id')->where('pf.objekt', '=', 'price');
            })
            ->leftJoin('foodalchemist_supplier_items as sip', 'sip.id', '=', 'pr.supplier_item_id')
            ->leftJoin('foodalchemist_suppliers as sl', 'sl.id', '=', DB::raw('COALESCE(sid.supplier_id, sip.supplier_id)'))
            ->select(['pf.*', DB::raw('COALESCE(sid.id, sip.id) AS artikel_id'), DB::raw('COALESCE(sid.designation, sip.designation) AS artikel'),
                'sl.id as lieferant_id', 'sl.name as lieferant']);
        $status = $filter['status'] ?? 'offen';
        if ($status !== null && $status !== '' && $status !== 'alle') {
            $q->where('pf.status', $status);
        }
        foreach (['art' => 'pf.art', 'feld' => 'pf.feld'] as $k => $spalte) {
            if (($filter[$k] ?? null) !== null && $filter[$k] !== '') {
                $q->where($spalte, $filter[$k]);
            }
        }
        if (! empty($filter['lieferant'])) {
            $q->where('sl.id', (int) $filter['lieferant']);
        }
        if (($s = trim((string) ($filter['suche'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('sid.designation', 'like', "%{$s}%")->orWhere('sip.designation', 'like', "%{$s}%"));
        }
        if (! empty($filter['normalpreis_tage'])) {
            // F5: nur Fälle, deren Artikel einen Necta-Normalpreis jünger als N Tage hat (Entscheidung bleibt beim Menschen)
            $grenze = now()->subDays((int) $filter['normalpreis_tage'])->toDateTimeString();
            $q->where(fn ($w) => $w->whereExists(fn ($e) => $e->from('foodalchemist_prices as np')
                ->whereColumn('np.supplier_item_id', DB::raw('COALESCE(sid.id, sip.id)'))->whereNotNull('np.legacy_id')->whereNull('np.deleted_at')
                ->where('np.status', '0')->whereNotNull('np.price')->where('np.status_valid_from', '>=', $grenze))
                ->orWhereExists(fn ($e) => $e->from('foodalchemist_price_history as nh')
                    ->whereColumn('nh.supplier_item_id', DB::raw('COALESCE(sid.id, sip.id)'))->where('nh.quelle', 'necta_historie')
                    ->where('nh.status', '0')->where('nh.status_valid_from', '>=', $grenze)));
        }

        return $q;
    }

    /** @return list<array<string,mixed>> */
    private function anreichern(\Illuminate\Support\Collection $zeilen): array
    {
        $artikelIds = $zeilen->pluck('artikel_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $jetzt = [];
        foreach (self::TABELLEN as $objekt => $tabelle) {
            $jetzt[$objekt] = $artikelIds === [] ? collect() : DB::table($tabelle)->whereIn('supplier_item_id', $artikelIds)->whereNull('deleted_at')->get()->keyBy('supplier_item_id');
        }
        $jetzt['supplier_item'] = $artikelIds === [] ? collect() : DB::table('foodalchemist_supplier_items')->whereIn('id', $artikelIds)->get()->keyBy('id');
        $normalpreis = $this->letzterNormalpreis($artikelIds);
        $marker = $artikelIds === [] ? collect() : DB::table('foodalchemist_produktpass_felder')->whereIn('supplier_item_id', $artikelIds)
            ->get(['supplier_item_id', 'objekt', 'feld'])->map(fn ($m) => $m->supplier_item_id.'|'.$m->objekt.'|'.$m->feld)->flip();

        return $zeilen->map(function ($z) use ($jetzt, $normalpreis, $marker) {
            $feld = (string) $z->feld;
            $aid = $z->artikel_id !== null ? (int) $z->artikel_id : null;
            $faJetzt = null;
            if ($aid !== null && isset($jetzt[$z->objekt])) {
                $zeile = $jetzt[$z->objekt]->get($aid);
                $faJetzt = $zeile === null ? null : (str_starts_with($feld, 'details.')
                    ? (self::jsonArray($zeile->details ?? null)[substr($feld, 8)] ?? null) : ($zeile->{$feld} ?? null));
            }

            return [
                'id' => (int) $z->id, 'art' => $z->art, 'objekt' => $z->objekt, 'feld' => $feld,
                'feld_label' => $feld !== '' && $z->objekt !== 'price' ? ProduktpassAbweichungService::label($z->objekt, $feld) : $feld,
                'fa_damals' => $z->fa_wert, 'fa_jetzt' => $faJetzt === null ? null : (string) $faJetzt, 'necta' => $z->necta_wert,
                'veraltet' => isset($jetzt[$z->objekt]) && $aid !== null && NectaAbgleichService::norm($faJetzt) !== NectaAbgleichService::norm($z->fa_wert)
                    && in_array($z->art, self::UEBERNEHMBAR, true),
                'status' => $z->status, 'artikel_id' => $aid, 'artikel' => $z->artikel, 'legacy_id' => (int) $z->legacy_id,
                'lieferant_id' => $z->lieferant_id !== null ? (int) $z->lieferant_id : null, 'lieferant' => $z->lieferant,
                'produktpass' => $aid !== null && $marker->has($aid.'|'.$z->objekt.'|'.$feld),
                'kritisch' => $z->objekt === 'item_allergen',
                'letzter_normalpreis' => $aid !== null ? ($normalpreis[$aid] ?? null) : null,
                'erledigt_von' => $z->erledigt_von !== null ? (int) $z->erledigt_von : null, 'erledigt_at' => $z->erledigt_at,
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $artikelIds
     * @return array<int, string>
     */
    private function letzterNormalpreis(array $artikelIds): array
    {
        if ($artikelIds === []) {
            return [];
        }
        $a = DB::table('foodalchemist_prices')->whereIn('supplier_item_id', $artikelIds)->whereNotNull('legacy_id')->whereNull('deleted_at')
            ->where('status', '0')->whereNotNull('price')->groupBy('supplier_item_id')->selectRaw('supplier_item_id, MAX(status_valid_from) AS d')->pluck('d', 'supplier_item_id');
        $b = DB::table('foodalchemist_price_history')->whereIn('supplier_item_id', $artikelIds)->where('quelle', 'necta_historie')->where('status', '0')
            ->groupBy('supplier_item_id')->selectRaw('supplier_item_id, MAX(status_valid_from) AS d')->pluck('d', 'supplier_item_id');
        $out = [];
        foreach ($artikelIds as $id) {
            $d = max((string) ($a[$id] ?? ''), (string) ($b[$id] ?? ''));
            if ($d !== '') {
                $out[$id] = $d;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function jsonArray(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }

        return is_string($v) && $v !== '' ? (array) json_decode($v, true) : [];
    }
}

