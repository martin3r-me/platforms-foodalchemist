<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorKonzept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorLeistung;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaketMenge;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorMengenRechner;
use Platform\FoodAlchemist\Services\Konfigurator\LeistungsRegelPruefung;

/**
 * Spec 94 · Konfiguratoren pflegen: Kopf und Regeln, Konzepte (Haupt/Add-on), Leistungen (frei oder als Kopie aus
 * Vorlagen), Pax-Pakete und Handwerte. Nach jeder Änderung an Leistungen, Konzepten oder Paketen werden die Pakete neu
 * geschnürt (KonfiguratorPricingService::schnueren, idempotent). Alles auf dem Konfigurator des eigenen Teams.
 */
class KonfiguratorService
{
    private const KOPF_FELDER = ['name', 'kunden_titel', 'claim', 'intro', 'crm_company_id', 'ort', 'status', 'verantwortlich_user_id', 'pax_vorgabe',
        'stunden_lunch', 'stunden_dinner', 'stunden_day_night', 'tageszeit_aktiv', 'kombi_rabatt_pct', 'paket_toleranz_pct', 'rundung', 'gueltig_bis'];

    private const KONZEPT_FELDER = ['lunch_concept_id', 'dinner_concept_id', 'abschnitt', 'addon_tageszeit', 'gebunden_an_id',
        'indikativ_lunch', 'indikativ_dinner', 'kunden_titel', 'claim', 'position'];

    public function __construct(
        private KonfiguratorPricingService $pricing,
        private LeistungsRegelPruefung $pruefung,
        private KonfiguratorMengenRechner $rechner,
    ) {
    }

    // ── Kopf ─────────────────────────────────────────────────────────────────────

    public function finde(Team $team, int $id): FoodAlchemistKonfigurator
    {
        return FoodAlchemistKonfigurator::visibleToTeam($team)->findOrFail($id);
    }

    public function anlegen(Team $team, array $in): FoodAlchemistKonfigurator
    {
        $werte = $this->kopfWerte($in + ['name' => 'Neuer Konfigurator'], null, $team);   // übergebener Name gewinnt

        return FoodAlchemistKonfigurator::create($werte + [
            'team_id' => $team->id,
            'verantwortlich_user_id' => $werte['verantwortlich_user_id'] ?? Auth::id(),
            'created_by' => Auth::id(),
        ]);
    }

    public function aendern(Team $team, int $id, array $in): FoodAlchemistKonfigurator
    {
        $k = $this->eigener($team, $id);
        $k->update($this->kopfWerte($in, $k, $team));
        if (array_intersect(['stunden_lunch', 'stunden_dinner', 'stunden_day_night'], array_keys($in)) !== []) {
            $k->touch();
        }

        return $k->fresh();
    }

    public function loeschen(Team $team, int $id): void
    {
        $k = $this->eigener($team, $id);
        DB::transaction(function () use ($k) {
            $paketIds = FoodAlchemistKonfiguratorPaket::where('konfigurator_id', $k->id)->pluck('id');
            FoodAlchemistKonfiguratorPaketMenge::whereIn('paket_id', $paketIds)->where('team_id', $k->team_id)->delete();
            FoodAlchemistKonfiguratorPaket::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->delete();
            FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->delete();
            FoodAlchemistKonfiguratorKonzept::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->delete();
            $k->delete();
        });
    }

    // ── Branding (wie Angebot) ───────────────────────────────────────────────────

    /** Farben und Footer setzen (Hex-Farben, leere Bandfarbe = aus der Primärfarbe abgeleitet). */
    public function branding(Team $team, int $id, array $in): FoodAlchemistKonfigurator
    {
        $k = $this->eigener($team, $id);
        $hex = function (mixed $wert, string $was, bool $leerErlaubt = false): ?string {
            $w = trim((string) $wert);
            if ($w === '' && $leerErlaubt) {
                return null;
            }
            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $w)) {
                throw new \InvalidArgumentException("{$was}: bitte als #RRGGBB angeben.");
            }

            return strtolower($w);
        };
        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $hex($in['brand_color'], 'Primärfarbe');
        }
        if (array_key_exists('band_color', $in)) {
            $daten['band_color'] = $hex($in['band_color'], 'Bandfarbe', true);
        }
        if (array_key_exists('footer_text', $in)) {
            $daten['footer_text'] = mb_substr(trim((string) $in['footer_text']), 0, 250) ?: null;
        }
        if (array_key_exists('presentation_design', $in)) {
            $daten['presentation_design'] = (string) $in['presentation_design'];
        }
        $k->update($daten);

        return $k->fresh();
    }

    /**
     * Branding einmalig übernehmen (Kopie, keine Kopplung): aus einem Foodbook oder Angebot Farben, Footer, Logo und Titelbild;
     * aus einem Betrieb Logo und Design.
     */
    public function brandingUebernehmen(Team $team, int $id, string $quelle, int $quelleId): FoodAlchemistKonfigurator
    {
        $k = $this->eigener($team, $id);
        $klasse = match ($quelle) {
            'foodbook' => \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,
            'angebot' => \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::class,
            'betrieb' => \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::class,
            default => throw new \InvalidArgumentException('Quelle: foodbook, angebot oder betrieb.'),
        };
        $q = $klasse::visibleToTeam($team)->find($quelleId);
        if ($q === null) {
            throw new \InvalidArgumentException('Die Quelle gibt es nicht oder sie ist für dieses Team nicht sichtbar.');
        }
        $felder = $quelle === 'betrieb'
            ? ['logo_path', 'logo_context_file_id', 'presentation_design']
            : ['brand_color', 'band_color', 'footer_text', 'logo_path', 'logo_context_file_id', 'cover_image_path', 'cover_context_file_id'];
        $daten = array_filter($q->only($felder), fn ($w) => $w !== null && $w !== '');
        if ($daten !== []) {
            $k->update($daten);
        }

        return $k->fresh();
    }

    // ── Konzepte ─────────────────────────────────────────────────────────────────

    public function konzeptHinzu(Team $team, int $konfiguratorId, array $in): FoodAlchemistKonfiguratorKonzept
    {
        $k = $this->eigener($team, $konfiguratorId);
        $rolle = in_array($in['rolle'] ?? 'haupt', FoodAlchemistKonfiguratorKonzept::ROLLEN, true) ? $in['rolle'] ?? 'haupt' : 'haupt';
        $werte = $this->konzeptWerte($team, $k, $rolle, $in);
        $konzept = FoodAlchemistKonfiguratorKonzept::create($werte + [
            'team_id' => $k->team_id, 'konfigurator_id' => $k->id, 'rolle' => $rolle,
            'position' => (int) ($in['position'] ?? (FoodAlchemistKonfiguratorKonzept::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->max('position') + 1)),
        ]);
        $this->pricing->schnueren($team, $k);

        return $konzept;
    }

    public function konzeptAendern(Team $team, int $konzeptId, array $in): FoodAlchemistKonfiguratorKonzept
    {
        $konzept = $this->eigenesKind($team, FoodAlchemistKonfiguratorKonzept::class, $konzeptId);
        $k = $this->eigener($team, (int) $konzept->konfigurator_id);
        $konzept->update($this->konzeptWerte($team, $k, $konzept->rolle, $in, $konzept));
        $this->pricing->schnueren($team, $k);

        return $konzept->fresh();
    }

    public function konzeptEntfernen(Team $team, int $konzeptId): void
    {
        $konzept = $this->eigenesKind($team, FoodAlchemistKonfiguratorKonzept::class, $konzeptId);
        $k = $this->eigener($team, (int) $konzept->konfigurator_id);
        DB::transaction(function () use ($konzept, $k) {
            FoodAlchemistKonfiguratorLeistung::where('konfigurator_konzept_id', $konzept->id)->where('team_id', $k->team_id)->delete();
            FoodAlchemistKonfiguratorKonzept::where('gebunden_an_id', $konzept->id)->where('team_id', $k->team_id)->update(['gebunden_an_id' => null]);
            $konzept->delete();
        });
        $this->pricing->schnueren($team, $k);
    }

    // ── Leistungen ───────────────────────────────────────────────────────────────

    /** Leistung frei anlegen (EK und VK von Hand oder per MCP). */
    public function leistungHinzu(Team $team, int $konfiguratorId, array $in): FoodAlchemistKonfiguratorLeistung
    {
        $k = $this->eigener($team, $konfiguratorId);
        $werte = $this->pruefung->pruefe($team, $in);
        $leistung = new FoodAlchemistKonfiguratorLeistung($werte + $this->zuordnung($k, $in) + [
            'team_id' => $k->team_id, 'konfigurator_id' => $k->id, 'position' => (int) ($in['position'] ?? 0),
        ]);
        $this->pruefeLeistung($k, $leistung);
        $leistung->vk_satz = $this->pricing->vkSatz($team, $leistung);
        $leistung->save();
        $this->pricing->schnueren($team, $k);

        return $leistung;
    }

    /**
     * Vorlagen als Kopie übernehmen (Mehrfachauswahl). Bezüge auf andere Vorlagen werden auf die übernommene Leistung
     * umgehängt — aus diesem Aufruf oder einer früheren Übernahme. Fehlt die Bezugs-Leistung, bleibt der Bezug leer (Warnung).
     *
     * @param  list<int>  $vorlageIds
     * @return array{leistungen: list<FoodAlchemistKonfiguratorLeistung>, warnungen: list<string>}
     */
    public function ausVorlagen(Team $team, int $konfiguratorId, array $vorlageIds, ?int $konzeptId = null, ?string $giltFuer = null): array
    {
        $k = $this->eigener($team, $konfiguratorId);
        $vorlagen = FoodAlchemistLeistungVorlage::visibleToTeam($team)->whereIn('id', $vorlageIds)->where('aktiv', true)->get()->keyBy('id');
        $zuordnung = $this->zuordnung($k, ['konfigurator_konzept_id' => $konzeptId, 'gilt_fuer' => $giltFuer]);
        $neu = [];
        $warnungen = [];

        DB::transaction(function () use ($team, $k, $vorlagen, $zuordnung, &$neu) {
            foreach ($vorlagen as $v) {
                $l = new FoodAlchemistKonfiguratorLeistung($v->only(FoodAlchemistLeistungVorlage::REGEL_FELDER) + $zuordnung + [
                    'team_id' => $k->team_id, 'konfigurator_id' => $k->id, 'vorlage_id' => $v->id, 'vorlage_stand_at' => $v->updated_at,
                    'position' => (int) $v->position,
                ]);
                $l->bezug_leistung_id = null;
                $l->vk_satz = $this->pricing->vkSatz($team, $l);
                $l->save();
                $neu[$v->id] = $l;
            }
        });

        foreach ($vorlagen as $v) {
            if ($v->bezug_leistung_id === null) {
                continue;
            }
            $ziel = $neu[$v->bezug_leistung_id] ?? FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)
                ->where('vorlage_id', $v->bezug_leistung_id)->orderBy('id')->first();
            if ($ziel !== null) {
                $neu[$v->id]->update(['bezug_leistung_id' => $ziel->id]);
            } else {
                $warnungen[] = "„{$v->bezeichnung}“ hängt an einer Leistung, die im Konfigurator fehlt — Bezug leer, Menge 0.";
            }
        }
        $this->pricing->schnueren($team, $k);

        return ['leistungen' => array_values($neu), 'warnungen' => $warnungen];
    }

    public function leistungAendern(Team $team, int $leistungId, array $in): FoodAlchemistKonfiguratorLeistung
    {
        $leistung = $this->eigenesKind($team, FoodAlchemistKonfiguratorLeistung::class, $leistungId);
        $k = $this->eigener($team, (int) $leistung->konfigurator_id);
        $leistung->fill($this->pruefung->pruefe($team, $in, $leistung->only(FoodAlchemistLeistungVorlage::REGEL_FELDER)));
        if (array_intersect(['konfigurator_konzept_id', 'gilt_fuer', 'ersetzt_leistung_id'], array_keys($in)) !== []) {
            $leistung->fill($this->zuordnung($k, $in + $leistung->only(['konfigurator_konzept_id', 'gilt_fuer', 'ersetzt_leistung_id'])));
        }
        if (array_key_exists('position', $in)) {
            $leistung->position = (int) $in['position'];
        }
        $this->pruefeLeistung($k, $leistung);
        $leistung->vk_satz = $this->pricing->vkSatz($team, $leistung);
        $leistung->save();
        $this->pricing->schnueren($team, $k);

        return $leistung;
    }

    /** Werte aus der (geänderten) Vorlage neu übernehmen; Bezug und Zuordnung im Konfigurator bleiben. */
    public function vorlageAbgleich(Team $team, int $leistungId): FoodAlchemistKonfiguratorLeistung
    {
        $leistung = $this->eigenesKind($team, FoodAlchemistKonfiguratorLeistung::class, $leistungId);
        $k = $this->eigener($team, (int) $leistung->konfigurator_id);
        $vorlage = $leistung->vorlage_id !== null ? FoodAlchemistLeistungVorlage::visibleToTeam($team)->find($leistung->vorlage_id) : null;
        if ($vorlage === null) {
            throw new \InvalidArgumentException('Die Vorlage gibt es nicht mehr.');
        }
        $bezug = $leistung->bezug_leistung_id;
        $leistung->fill($vorlage->only(FoodAlchemistLeistungVorlage::REGEL_FELDER));
        $leistung->bezug_leistung_id = $vorlage->bezug_leistung_id !== null ? $bezug : null;
        $leistung->vorlage_stand_at = $vorlage->updated_at;
        $leistung->vk_satz = $this->pricing->vkSatz($team, $leistung);
        $leistung->save();
        $this->pricing->schnueren($team, $k);

        return $leistung;
    }

    /** @return list<int> Leistungen, deren Vorlage seit der Übernahme geändert wurde */
    public function vorlageGeaendert(Team $team, int $konfiguratorId): array
    {
        $k = $this->finde($team, $konfiguratorId);
        $leistungen = FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->whereNotNull('vorlage_id')->get();
        $stand = FoodAlchemistLeistungVorlage::visibleToTeam($team)->whereIn('id', $leistungen->pluck('vorlage_id'))->pluck('updated_at', 'id');

        return $leistungen->filter(fn ($l) => isset($stand[$l->vorlage_id]) && ($l->vorlage_stand_at === null || $stand[$l->vorlage_id]->greaterThan($l->vorlage_stand_at)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function leistungEntfernen(Team $team, int $leistungId): void
    {
        $leistung = $this->eigenesKind($team, FoodAlchemistKonfiguratorLeistung::class, $leistungId);
        $k = $this->eigener($team, (int) $leistung->konfigurator_id);
        FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)
            ->where('ersetzt_leistung_id', $leistung->id)->update(['ersetzt_leistung_id' => null]);
        $leistung->delete();
        $this->pricing->schnueren($team, $k);
    }

    // ── Pakete und Mengen ────────────────────────────────────────────────────────

    public function paketAnlegen(Team $team, int $konfiguratorId, array $in): FoodAlchemistKonfiguratorPaket
    {
        $k = $this->eigener($team, $konfiguratorId);
        [$von, $bis] = $this->grenzen($in);
        $this->pruefeUeberschneidung($k, $von, $bis, null);
        $referenz = isset($in['referenz_pax']) && $in['referenz_pax'] !== '' ? (int) $in['referenz_pax'] : null;
        $this->pruefeReferenz($referenz, $von, $bis);
        $paket = FoodAlchemistKonfiguratorPaket::create([
            'team_id' => $k->team_id, 'konfigurator_id' => $k->id, 'bezeichnung' => trim((string) ($in['bezeichnung'] ?? '')) ?: null,
            'von_pax' => $von, 'bis_pax' => $bis,
            'referenz_pax' => $referenz ?? $this->pricing->referenzPax($von, $bis), 'referenz_manuell' => $referenz !== null,
        ]);
        $this->pricing->schnueren($team, $k, (int) $paket->id);

        return $paket->fresh();
    }

    public function paketAendern(Team $team, int $paketId, array $in): FoodAlchemistKonfiguratorPaket
    {
        $paket = $this->eigenesKind($team, FoodAlchemistKonfiguratorPaket::class, $paketId);
        $k = $this->eigener($team, (int) $paket->konfigurator_id);
        [$von, $bis] = $this->grenzen($in + ['von_pax' => $paket->von_pax, 'bis_pax' => $paket->bis_pax]);
        $this->pruefeUeberschneidung($k, $von, $bis, (int) $paket->id);
        $paket->fill(['von_pax' => $von, 'bis_pax' => $bis]);
        if (array_key_exists('bezeichnung', $in)) {
            $paket->bezeichnung = trim((string) $in['bezeichnung']) ?: null;
        }
        if (array_key_exists('referenz_pax', $in)) {
            $referenz = $in['referenz_pax'] !== null && $in['referenz_pax'] !== '' ? (int) $in['referenz_pax'] : null;
            $this->pruefeReferenz($referenz, $von, $bis);
            $paket->referenz_manuell = $referenz !== null;
            $paket->referenz_pax = $referenz ?? $this->pricing->referenzPax($von, $bis);
        } elseif ($paket->referenz_manuell) {
            $this->pruefeReferenz((int) $paket->referenz_pax, $von, $bis);
        }
        $paket->save();
        $this->pricing->schnueren($team, $k, (int) $paket->id);

        return $paket->fresh();
    }

    public function paketEntfernen(Team $team, int $paketId): void
    {
        $paket = $this->eigenesKind($team, FoodAlchemistKonfiguratorPaket::class, $paketId);
        FoodAlchemistKonfiguratorPaketMenge::where('paket_id', $paket->id)->where('team_id', $paket->team_id)->delete();
        $paket->delete();
    }

    /**
     * Vorschlag übernehmen: ersetzt alle Pakete. Handwerte der alten Pakete gehen dabei verloren (die Oberfläche fragt vorher).
     *
     * @param  list<array{von_pax:int, bis_pax:int}>  $pakete
     */
    public function vorschlagUebernehmen(Team $team, int $konfiguratorId, array $pakete): int
    {
        $k = $this->eigener($team, $konfiguratorId);
        $grenzen = array_map(fn ($p) => $this->grenzen($p), $pakete);
        usort($grenzen, fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($grenzen); $i++) {
            if ($grenzen[$i][0] <= $grenzen[$i - 1][1]) {
                throw new \InvalidArgumentException('Die vorgeschlagenen Pakete überschneiden sich.');
            }
        }
        DB::transaction(function () use ($k, $grenzen) {
            $alt = FoodAlchemistKonfiguratorPaket::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->pluck('id');
            FoodAlchemistKonfiguratorPaketMenge::whereIn('paket_id', $alt)->where('team_id', $k->team_id)->delete();
            FoodAlchemistKonfiguratorPaket::whereIn('id', $alt)->where('team_id', $k->team_id)->delete();
            foreach ($grenzen as $i => [$von, $bis]) {
                FoodAlchemistKonfiguratorPaket::create(['team_id' => $k->team_id, 'konfigurator_id' => $k->id, 'von_pax' => $von, 'bis_pax' => $bis,
                    'referenz_pax' => $this->pricing->referenzPax($von, $bis), 'referenz_manuell' => false, 'position' => $i]);
            }
        });
        $this->pricing->schnueren($team, $k);

        return count($grenzen);
    }

    /** Handwert in der Paket-Matrix setzen; `$anzahl` null = zurück auf die Regel. Konzept null = gilt für alle Hauptkonzepte. */
    public function mengeSetzen(Team $team, int $paketId, int $leistungId, ?int $konzeptId, ?float $anzahl, ?string $notiz = null): FoodAlchemistKonfiguratorPaketMenge
    {
        $paket = $this->eigenesKind($team, FoodAlchemistKonfiguratorPaket::class, $paketId);
        $k = $this->eigener($team, (int) $paket->konfigurator_id);
        $leistung = FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->findOrFail($leistungId);
        if ($konzeptId !== null && ! FoodAlchemistKonfiguratorKonzept::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->whereKey($konzeptId)->exists()) {
            throw new \InvalidArgumentException('Das Konzept gehört nicht zu diesem Konfigurator.');
        }
        if ($anzahl !== null && $anzahl < 0) {
            throw new \InvalidArgumentException('Die Anzahl darf nicht negativ sein.');
        }
        $zeile = FoodAlchemistKonfiguratorPaketMenge::firstOrNew(
            ['paket_id' => $paket->id, 'leistung_id' => $leistung->id, 'konfigurator_konzept_id' => $konzeptId, 'team_id' => $k->team_id],
        );
        $zeile->fill(['manuell' => $anzahl !== null, 'anzahl' => $anzahl ?? (float) $zeile->regel_anzahl, 'notiz' => $anzahl !== null ? $notiz : null]);
        $zeile->save();
        $this->pricing->schnueren($team, $k, (int) $paket->id);

        return $zeile->fresh();
    }

    // ── intern ───────────────────────────────────────────────────────────────────

    private function eigener(Team $team, int $id): FoodAlchemistKonfigurator
    {
        $k = $this->finde($team, $id);
        GeerbtNurLesenException::wennNichtEigen($k, $team, 'Konfigurator');

        return $k;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     * @param  class-string<T>  $klasse
     * @return T
     */
    private function eigenesKind(Team $team, string $klasse, int $id): object
    {
        $zeile = $klasse::visibleToTeam($team)->findOrFail($id);
        GeerbtNurLesenException::wennNichtEigen($zeile, $team, 'Konfigurator');

        return $zeile;
    }

    private function kopfWerte(array $in, ?FoodAlchemistKonfigurator $bisher = null, ?Team $team = null): array
    {
        $w = array_intersect_key($in, array_flip(self::KOPF_FELDER));
        foreach (['kunden_titel', 'claim', 'intro', 'ort', 'crm_company_id', 'verantwortlich_user_id', 'gueltig_bis'] as $f) {
            if (array_key_exists($f, $w) && ($w[$f] === '' || $w[$f] === null)) {
                $w[$f] = null;
            }
        }
        // Anfragen gehen per Mail an diese Person: nur Mitglieder des Teams (keine fremden Adressen über die Id)
        if (($w['verantwortlich_user_id'] ?? null) !== null && $team !== null
            && ! $team->users()->where('users.id', (int) $w['verantwortlich_user_id'])->exists()) {
            throw new \InvalidArgumentException('„Anfragen gehen an“: die Person ist kein Mitglied dieses Teams.');
        }
        if (($w['gueltig_bis'] ?? null) !== null) {
            try {
                $w['gueltig_bis'] = \Illuminate\Support\Carbon::parse((string) $w['gueltig_bis'])->format('Y-m-d');
            } catch (\Throwable) {
                throw new \InvalidArgumentException('„Gültig bis“: bitte als Datum YYYY-MM-DD angeben.');
            }
        }
        if (array_key_exists('name', $w) && trim((string) $w['name']) === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        if (isset($w['status']) && ! in_array($w['status'], FoodAlchemistKonfigurator::STATUS, true)) {
            throw new \InvalidArgumentException('Status entwurf, live oder archiv.');
        }
        if (isset($w['rundung']) && ! in_array(round((float) $w['rundung'], 2), FoodAlchemistKonfigurator::RUNDUNGEN, true)) {
            throw new \InvalidArgumentException('Rundung 0,01 · 0,10 · 0,50 oder 1,00.');
        }
        foreach (['stunden_lunch', 'stunden_dinner', 'stunden_day_night', 'kombi_rabatt_pct', 'paket_toleranz_pct'] as $f) {
            if (isset($w[$f]) && (! is_numeric($w[$f]) || (float) $w[$f] < 0)) {
                throw new \InvalidArgumentException("{$f} muss eine Zahl ≥ 0 sein.");
            }
        }
        if (isset($w['kombi_rabatt_pct']) && (float) $w['kombi_rabatt_pct'] >= 100) {
            throw new \InvalidArgumentException('Der Kombi-Rabatt muss unter 100 % liegen.');
        }

        return $w;
    }

    private function konzeptWerte(Team $team, FoodAlchemistKonfigurator $k, string $rolle, array $in, ?FoodAlchemistKonfiguratorKonzept $bisher = null): array
    {
        $w = array_intersect_key($in, array_flip(self::KONZEPT_FELDER));
        if ($rolle === 'addon' && isset($in['concept_id'])) {
            $w['dinner_concept_id'] = $in['concept_id'];   // Add-on: das Concept selbst
        }
        foreach (['lunch_concept_id', 'dinner_concept_id', 'gebunden_an_id'] as $f) {
            if (array_key_exists($f, $w)) {
                $w[$f] = $w[$f] === '' || $w[$f] === null ? null : (int) $w[$f];
            }
        }
        $ziel = array_merge($bisher?->only(self::KONZEPT_FELDER) ?? [], $w);
        foreach (['lunch_concept_id', 'dinner_concept_id'] as $f) {
            if (($ziel[$f] ?? null) !== null && ! FoodAlchemistConcept::visibleToTeam($team)->whereKey($ziel[$f])->exists()) {
                throw new \InvalidArgumentException('Das Concept gibt es nicht oder es ist für dieses Team nicht sichtbar.');
            }
        }
        if ($rolle === 'haupt' && ($ziel['lunch_concept_id'] ?? null) === null && ($ziel['dinner_concept_id'] ?? null) === null) {
            throw new \InvalidArgumentException('Ein Hauptkonzept braucht ein Lunch- oder ein Dinner-Concept.');
        }
        if ($rolle === 'addon') {
            if (($ziel['dinner_concept_id'] ?? null) === null) {
                throw new \InvalidArgumentException('Ein Add-on braucht sein Concept.');
            }
            $w['lunch_concept_id'] = null;
            if (isset($ziel['addon_tageszeit']) && ! in_array($ziel['addon_tageszeit'], FoodAlchemistKonfiguratorKonzept::ADDON_TAGESZEITEN, true)) {
                throw new \InvalidArgumentException('Tageszeit des Add-ons: lunch, dinner oder beide.');
            }
            if (($ziel['gebunden_an_id'] ?? null) !== null && ! FoodAlchemistKonfiguratorKonzept::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)
                ->where('rolle', 'haupt')->whereKey($ziel['gebunden_an_id'])->exists()) {
                throw new \InvalidArgumentException('Ein Add-on kann nur an ein Hauptkonzept desselben Konfigurators gebunden werden.');
            }
        } else {
            $w['abschnitt'] = null;
            $w['addon_tageszeit'] = null;
            $w['gebunden_an_id'] = null;
        }

        return $w;
    }

    /** Konzept-Zuordnung und Ersetzung prüfen; nur Konzepte und Leistungen desselben Konfigurators. */
    private function zuordnung(FoodAlchemistKonfigurator $k, array $in): array
    {
        $konzeptId = isset($in['konfigurator_konzept_id']) && $in['konfigurator_konzept_id'] !== '' ? (int) $in['konfigurator_konzept_id'] : null;
        $konzept = $konzeptId !== null
            ? FoodAlchemistKonfiguratorKonzept::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->find($konzeptId)
            : null;
        if ($konzeptId !== null && $konzept === null) {
            throw new \InvalidArgumentException('Das Konzept gehört nicht zu diesem Konfigurator.');
        }
        $gilt = ($in['gilt_fuer'] ?? null) ?: null;
        if ($gilt !== null && ! in_array($gilt, FoodAlchemistKonfiguratorLeistung::GILT_FUER, true)) {
            throw new \InvalidArgumentException('„Gilt für“: lunch, dinner oder beide.');
        }
        $ersetzt = isset($in['ersetzt_leistung_id']) && $in['ersetzt_leistung_id'] !== '' ? (int) $in['ersetzt_leistung_id'] : null;
        if ($ersetzt !== null) {
            if ($konzept === null || $konzept->rolle !== 'haupt') {
                throw new \InvalidArgumentException('Ersetzen kann nur eine Zusatz-Leistung eines Hauptkonzepts.');
            }
            if (! FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)
                ->whereNull('konfigurator_konzept_id')->whereKey($ersetzt)->exists()) {
                throw new \InvalidArgumentException('Ersetzt werden kann nur eine Leistung der Grundausstattung.');
            }
        }

        return ['konfigurator_konzept_id' => $konzeptId, 'gilt_fuer' => $konzept?->rolle === 'haupt' ? $gilt : null, 'ersetzt_leistung_id' => $ersetzt];
    }

    /** Bezugs-Leistung im selben Konfigurator, kein Kreis. */
    private function pruefeLeistung(FoodAlchemistKonfigurator $k, FoodAlchemistKonfiguratorLeistung $leistung): void
    {
        if ($leistung->bezug_leistung_id === null) {
            return;
        }
        $andere = FoodAlchemistKonfiguratorLeistung::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)->get(['id', 'bezug_leistung_id']);
        if ((int) $leistung->bezug_leistung_id === (int) $leistung->id || ! $andere->contains('id', (int) $leistung->bezug_leistung_id)) {
            throw new \InvalidArgumentException('Die Bezugs-Leistung gehört nicht zu diesem Konfigurator.');
        }
        $bezuege = $andere->pluck('bezug_leistung_id', 'id')->map(fn ($v) => $v !== null ? (int) $v : null)->all();
        $bezuege[$leistung->id ?? 0] = (int) $leistung->bezug_leistung_id;
        if ($this->rechner->findeKreis($bezuege) !== null) {
            throw new \InvalidArgumentException('Kreis in den Bezügen: zwei Leistungen hängen gegenseitig voneinander ab.');
        }
    }

    /** @return array{0:int, 1:int} */
    private function grenzen(array $in): array
    {
        $von = (int) ($in['von_pax'] ?? 0);
        $bis = (int) ($in['bis_pax'] ?? 0);
        if ($von < 1 || $bis < $von) {
            throw new \InvalidArgumentException('Paket: „von“ ab 1 Gast, „bis“ nicht unter „von“.');
        }

        return [$von, $bis];
    }

    private function pruefeUeberschneidung(FoodAlchemistKonfigurator $k, int $von, int $bis, ?int $ohne): void
    {
        $treffer = FoodAlchemistKonfiguratorPaket::where('konfigurator_id', $k->id)->where('team_id', $k->team_id)
            ->when($ohne !== null, fn ($q) => $q->whereKeyNot($ohne))
            ->where('von_pax', '<=', $bis)->where('bis_pax', '>=', $von)->first();
        if ($treffer !== null) {
            throw new \InvalidArgumentException("Überschneidung mit dem Paket {$treffer->anzeigeName()}.");
        }
    }

    private function pruefeReferenz(?int $referenz, int $von, int $bis): void
    {
        if ($referenz !== null && ($referenz < $von || $referenz > $bis)) {
            throw new \InvalidArgumentException('Die Referenz-Pax muss im Paketbereich liegen.');
        }
    }
}
