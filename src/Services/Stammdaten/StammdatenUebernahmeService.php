<?php

namespace Platform\FoodAlchemist\Services\Stammdaten;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\SupplierItemService;

/**
 * Stammdaten-Import, Stufe 1b (Kurator 29.10.2026): Entscheidung je Prüflisten-Zeile und Übernahme in den Bestand.
 *
 * Regeln:
 *  - Nichts wird ohne ausdrückliche Entscheidung geschrieben: nur Zeilen mit `entscheidung = uebernehmen`.
 *  - Es gibt keinen zweiten Schreibweg: geschrieben wird über den Artikel-Import ({@see FileArticleImportService::verarbeiteZeile} mit apply) und
 *    {@see SupplierItemService::update} (Haltbarkeit); danach läuft EINMAL die Post-Import-Kette (Rezepte neu rechnen, Signale).
 *  - ALLERGENE nur nach Bestätigung (`allergene_bestaetigt`): ohne Bestätigung werden die Allergen-Spalten der Zeile nicht geschrieben, der Rest schon.
 *    Eine Rückstufung (enthalten → nicht/spuren) ist ein Konflikt und braucht die Bestätigung, sonst wird die ganze Zeile nicht übernommen.
 *  - Harte Nährwert-Befunde (Prüfer: schwere hart) werden NIE geschrieben (die Nährwerte der Zeile bleiben draußen), der Rest der Zeile geht durch.
 *  - Fehler, geerbter Artikel (D1) und mehrdeutige Treffer sind nicht übernehmbar.
 *  - Guard gegen Veralterung: vor dem Schreiben wird die Zeile gegen den aktuellen Bestand neu geprüft; hat sich der Bestand seit der Prüfung geändert,
 *    wird die Zeile nicht geschrieben (neu hochladen).
 *  - Ein Preis mit „Gültig ab“ in der Zukunft wird NICHT geschrieben (das Preismodell kennt keine Vorausdatierung) — Hinweis an der Zeile.
 *  - Rechte: {@see PreislistenImportService::darfImportieren} (Haupt-Team + Kuratieren), der Lauf muss dem Team gehören.
 */
class StammdatenUebernahmeService
{
    public function __construct(
        private readonly PreislistenImportService $liste,
        private readonly StammdatenImportService $pruefung,
        private readonly FileArticleImportService $artikel,
        private readonly SupplierItemService $items,
    ) {}

    /**
     * Entscheidungen festhalten (noch nichts schreiben). „verwerfen“ schließt die Zeile sofort ab.
     *
     * @param  list<array{id: int, entscheidung: string, allergene_bestaetigt?: bool}>  $entscheidungen
     * @return int  Anzahl geänderter Zeilen
     */
    public function entscheide(Team $team, ?User $user, Lauf $lauf, array $entscheidungen): int
    {
        $this->pruefeRecht($team, $user, $lauf);
        $n = 0;
        foreach ($entscheidungen as $e) {
            $z = Zeile::where('run_id', $lauf->id)->where('team_id', $team->id)->find((int) ($e['id'] ?? 0));
            $wert = (string) ($e['entscheidung'] ?? '');
            if ($z === null || $z->status !== Zeile::STATUS_GEPRUEFT
                || ! in_array($wert, [Zeile::ENTSCHEIDUNG_OFFEN, Zeile::ENTSCHEIDUNG_UEBERNEHMEN, Zeile::ENTSCHEIDUNG_VERWERFEN], true)) {
                continue;
            }
            $z->update([
                'entscheidung' => $wert,
                'allergene_bestaetigt' => (bool) ($e['allergene_bestaetigt'] ?? $z->allergene_bestaetigt),
                'status' => $wert === Zeile::ENTSCHEIDUNG_VERWERFEN ? Zeile::STATUS_VERWORFEN : Zeile::STATUS_GEPRUEFT,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Alle neuen/ändernden Zeilen ohne Allergen-Änderung und ohne harten Befund zum Übernehmen vormerken (Sammelaktion „sicher“).
     */
    public function merkeUnkritischeVor(Team $team, ?User $user, Lauf $lauf): int
    {
        $this->pruefeRecht($team, $user, $lauf);
        $n = 0;
        Zeile::where('run_id', $lauf->id)->where('team_id', $team->id)->where('status', Zeile::STATUS_GEPRUEFT)
            ->whereIn('aktion', [Zeile::AKTION_NEU, Zeile::AKTION_AENDERT])->where('entscheidung', Zeile::ENTSCHEIDUNG_OFFEN)->orderBy('id')
            ->each(function (Zeile $z) use (&$n) {
                if (! empty($z->diff['allergene'])) {
                    return;
                }
                $z->update(['entscheidung' => Zeile::ENTSCHEIDUNG_UEBERNEHMEN]);
                $n++;
            });

        return $n;
    }

    /**
     * Vorgemerkte Zeilen (optional nur `$zeilenIds`) in den Bestand übernehmen.
     *
     * @param  ?list<int>  $zeilenIds
     * @return array{angewendet: int, uebersprungen: list<array{id: int, zeile: int, grund: string}>, faellige_preise: int, kette: array<string, int>, lauf_status: string}
     */
    public function uebernehmen(Team $team, ?User $user, Lauf $lauf, ?array $zeilenIds = null): array
    {
        $this->pruefeRecht($team, $user, $lauf);

        return $this->verarbeite($team, $lauf, $zeilenIds, false);
    }

    /**
     * Täglicher Systemlauf (Kommando `foodalchemist:faellige-preise`): schreibt nur vorgemerkte Preise, deren „Gültig ab“ erreicht ist — keine neue
     * Entscheidung, kein Nutzer (die Zeilen wurden beim Übernehmen bestätigt).
     *
     * @return array{angewendet: int, uebersprungen: list<array{id: int, zeile: int, grund: string}>, faellige_preise: int, kette: array<string, int>, lauf_status: string}
     */
    public function faelligePreise(Team $team, Lauf $lauf): array
    {
        if ((int) $lauf->team_id !== (int) $team->id || $lauf->art !== Lauf::ART_STAMMDATEN) {
            throw new \RuntimeException('Dieser Lauf gehört nicht zu diesem Team oder ist kein Stammdaten-Lauf.');
        }

        return $this->verarbeite($team, $lauf, null, true);
    }

    /** @param ?list<int> $zeilenIds */
    private function verarbeite(Team $team, Lauf $lauf, ?array $zeilenIds, bool $nurFaellige): array
    {
        $bilanz = ['angewendet' => 0, 'uebersprungen' => [], 'faellige_preise' => 0];
        $bewegt = [];

        if ($zeilenIds === null) {   // vorgemerkte Preise, deren „Gültig ab“ erreicht ist
            Zeile::where('run_id', $lauf->id)->where('team_id', $team->id)->where('status', Zeile::STATUS_ZUKUENFTIG)
                ->whereDate('valid_from', '<=', now()->toDateString())->orderBy('id')
                ->each(function (Zeile $z) use ($team, &$bilanz, &$bewegt) {
                    if ($this->schreibeFaelligenPreis($team, $z, $bewegt)) {
                        $bilanz['faellige_preise']++;
                    }
                });
        }

        if (! $nurFaellige) {
            Zeile::where('run_id', $lauf->id)->where('team_id', $team->id)->where('status', Zeile::STATUS_GEPRUEFT)
                ->where('entscheidung', Zeile::ENTSCHEIDUNG_UEBERNEHMEN)->when($zeilenIds !== null, fn ($q) => $q->whereIn('id', $zeilenIds))
                ->orderBy('id')->each(function (Zeile $z) use ($team, &$bilanz, &$bewegt) {
                    $grund = $this->wende($team, $z, $bewegt);
                    if ($grund === null) {
                        $bilanz['angewendet']++;
                    } else {
                        $bilanz['uebersprungen'][] = ['id' => (int) $z->id, 'zeile' => (int) $z->row_nr, 'grund' => $grund];
                    }
                });
        }

        $counts = (array) $lauf->counts;
        foreach ([Zeile::STATUS_ANGEWENDET, Zeile::STATUS_VERWORFEN, Zeile::STATUS_ZUKUENFTIG] as $s) {
            $counts[$s] = Zeile::where('run_id', $lauf->id)->where('status', $s)->count();
        }
        $offen = Zeile::where('run_id', $lauf->id)->where('status', Zeile::STATUS_GEPRUEFT)->whereIn('aktion', [Zeile::AKTION_NEU, Zeile::AKTION_AENDERT])
            ->where('entscheidung', Zeile::ENTSCHEIDUNG_OFFEN)->exists() || ($counts[Zeile::STATUS_ZUKUENFTIG] ?? 0) > 0;
        $lauf->update(['counts' => $counts, 'status' => $offen ? 'staged' : 'applied']);

        $kette = $bewegt !== [] ? $this->artikel->postImportKette($team, $bewegt, true) : ['bewegt' => 0, 'gps' => 0, 'rezepte' => 0, 'neu_berechnet' => 0, 'signale' => 0];

        return $bilanz + ['kette' => $kette, 'lauf_status' => (string) $lauf->status];
    }

    // ---- intern -----------------------------------------------------------

    /**
     * Vorgemerkten Preis schreiben, sobald „Gültig ab“ erreicht ist: nur Artikelschlüssel + Preis (der Rest der Zeile ging schon durch), über denselben
     * Schreibweg. Ein unveränderter Preis erzeugt keine neue Zeile; ein neuer bekommt „Gültig ab“ als Stand-Beginn (wie bei der Preisliste).
     *
     * @param  array<int, true>  $bewegt
     */
    private function schreibeFaelligenPreis(Team $team, Zeile $z, array &$bewegt): bool
    {
        $daten = (array) $z->daten;
        $werte = array_intersect_key($daten, array_flip(['artikelnummer', 'eangebinde', 'eanbestelleinheit', 'preis', 'preisstatus']));
        $befund = $this->artikel->verarbeiteZeile($team, (int) $z->supplier_id, ['nr' => (int) $z->row_nr, 'werte' => $werte], true, $bewegt);
        if (in_array($befund['preis']['status'] ?? '', ['fehler'], true) || in_array($befund['status'] ?? '', ['fehler', 'uebersprungen'], true)) {
            return false;
        }
        if (in_array($befund['preis']['status'] ?? '', ['neu', 'geaendert'], true) && $z->supplier_item_id !== null
            && ($aktiv = app(\Platform\FoodAlchemist\Services\PriceService::class)->activeFor((int) $z->supplier_item_id, $team)) !== null) {
            $aktiv->update(['status_valid_from' => $z->valid_from]);
        }
        $hinweise = array_values(array_filter((array) $z->hinweise, fn ($h) => ($h['code'] ?? '') !== 'preis_vorgemerkt'));
        $z->update(['status' => Zeile::STATUS_ANGEWENDET, 'hinweise' => $hinweise]);

        return true;
    }

    private function pruefeRecht(Team $team, ?User $user, Lauf $lauf): void
    {
        if (! $this->liste->darfImportieren($team, $user)) {
            throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Stammdaten übernehmen (nur das Haupt-Team des Kunden)');
        }
        if ((int) $lauf->team_id !== (int) $team->id || $lauf->art !== Lauf::ART_STAMMDATEN) {
            throw new \RuntimeException('Dieser Lauf gehört nicht zu diesem Team oder ist kein Stammdaten-Lauf.');
        }
    }

    /**
     * Eine Zeile schreiben. Null = übernommen, sonst der Grund, warum sie stehen bleibt (steht an der Zeile).
     *
     * @param  array<int, true>  $bewegt
     */
    private function wende(Team $team, Zeile $z, array &$bewegt): ?string
    {
        // Der Grund steht als Hinweis an der Zeile; `reason` bleibt dem Prüf-Ergebnis (geerbt/mehrdeutig) vorbehalten und wird hier nie überschrieben.
        $stehenBleiben = function (string $grund) use ($z): string {
            $hinweise = array_values(array_filter((array) $z->hinweise, fn ($h) => ($h['code'] ?? '') !== 'uebernahme_gestoppt'));
            $hinweise[] = ['code' => 'uebernahme_gestoppt', 'stufe' => 'warnung', 'text' => $grund];
            $z->update(['hinweise' => $hinweise]);

            return $grund;
        };
        if (in_array($z->aktion, [Zeile::AKTION_FEHLER], true)) {
            return $stehenBleiben('Zeile mit Fehler — nicht übernehmbar.');
        }
        if ($z->aktion === Zeile::AKTION_KONFLIKT && ($z->reason !== null && $z->reason !== '')) {
            return $stehenBleiben('Konflikt (geerbter oder mehrdeutiger Artikel) — nicht übernehmbar: ' . $z->reason);
        }
        $codes = collect((array) $z->hinweise)->pluck('code')->all();
        if (in_array('allergen_rueckstufung', $codes, true) && ! $z->allergene_bestaetigt) {
            return $stehenBleiben('Allergen-Rückstufung braucht die ausdrückliche Bestätigung der Allergene.');
        }

        // Veralterungs-Guard: Bestand seit der Prüfung geändert?
        $jetzt = $this->pruefung->pruefeGespeichert($team, $z);
        if (($jetzt['aktion'] ?? null) === Zeile::AKTION_FEHLER || (int) ($jetzt['supplier_item_id'] ?? 0) !== (int) ($z->supplier_item_id ?? 0)
            || json_encode($jetzt['diff'] ?? null) !== json_encode($z->diff)) {
            return $stehenBleiben('Der Bestand hat sich seit der Prüfung geändert — bitte Datei neu prüfen.');
        }

        $daten = (array) $z->daten;
        $werte = array_filter($daten, fn ($k) => ! str_starts_with((string) $k, 'x:'), ARRAY_FILTER_USE_KEY);
        $eigene = array_filter($daten, fn ($k) => str_starts_with((string) $k, 'x:'), ARRAY_FILTER_USE_KEY);
        $hinweise = (array) $z->hinweise;

        if (! $z->allergene_bestaetigt) {
            $werte = array_filter($werte, fn ($k) => ! str_starts_with((string) $k, 'al:'), ARRAY_FILTER_USE_KEY);
        }
        if (collect($hinweise)->contains(fn ($h) => ($h['bereich'] ?? null) === 'naehrwert' && ($h['stufe'] ?? null) === 'konflikt')) {
            $werte = array_filter($werte, fn ($k) => ! str_starts_with((string) $k, 'nw:'), ARRAY_FILTER_USE_KEY);
        }
        $zukunft = $z->valid_from !== null && $z->valid_from->copy()->startOfDay()->isFuture() && ($werte['preis'] ?? '') !== '';
        if ($zukunft) {   // Preis vormerken: der Rest der Zeile geht jetzt durch, der Preis beim ersten Übernehmen ab dem Datum
            unset($werte['preis'], $werte['preisstatus'], $werte['aktion_ab'], $werte['aktion_bis']);
            $hinweise = array_values(array_filter($hinweise, fn ($h) => ($h['code'] ?? '') !== 'gueltig_ab_zukunft'));
            $hinweise[] = ['code' => 'preis_vorgemerkt', 'stufe' => 'info', 'text' => 'Preis gilt erst ab ' . $z->valid_from->format('d.m.Y') . ' — vorgemerkt, wird beim nächsten Übernehmen ab diesem Tag geschrieben.'];
        }

        DB::beginTransaction();
        try {
            $befund = $this->artikel->verarbeiteZeile($team, (int) $z->supplier_id, ['nr' => (int) $z->row_nr, 'werte' => $werte], true, $bewegt);
            if (in_array($befund['status'] ?? '', ['fehler', 'uebersprungen'], true)) {
                DB::rollBack();   // nichts halb geschrieben

                return $stehenBleiben((string) ($befund['grund'] ?? 'Übernahme fehlgeschlagen.'));
            }

            $artno = ($werte['artikelnummer'] ?? '') !== '' ? $werte['artikelnummer'] : null;
            $eans = array_values(array_filter([$werte['eangebinde'] ?? '', $werte['eanbestelleinheit'] ?? '']));
            $art = null;
            $item = $this->artikel->finde($team, (int) $z->supplier_id, $artno, $eans, $art);
            $haltbar = [];
            foreach (['x:haltbar_gekuehlt' => 'shelf_life_chilled_days', 'x:haltbar_tk' => 'shelf_life_frozen_days', 'x:haltbar_trocken' => 'shelf_life_dry_days'] as $k => $spalte) {
                if (($eigene[$k] ?? '') !== '' && preg_match('/^\d{1,5}$/', trim($eigene[$k]))) {
                    $haltbar[$spalte] = (int) $eigene[$k];
                }
            }
            if ($item !== null && $haltbar !== []) {
                $this->items->update($team, $item, $haltbar);
            }
            if ($item !== null) {
                $bewegt[(int) $item->id] = true;
            }
            $z->update(['status' => $zukunft ? Zeile::STATUS_ZUKUENFTIG : Zeile::STATUS_ANGEWENDET, 'supplier_item_id' => $item?->id,
                'hinweise' => array_values(array_filter($hinweise, fn ($h) => ($h['code'] ?? '') !== 'uebernahme_gestoppt'))]);
            DB::commit();

            return null;
        } catch (\Throwable $e) {
            DB::rollBack();

            return $stehenBleiben('Übernahme fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
