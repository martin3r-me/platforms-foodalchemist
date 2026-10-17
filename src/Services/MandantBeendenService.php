<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistMandantBeendenLauf;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §13 C3 — „Mandant beenden“ als Ablauf mit Vorschau. Reihenfolge (Kurator 13.10., ändert die Spec):
 *
 *   Abos kündigen → Stamm-Paket abbestellen → Datenexport → Bereiche aus → Team sperren
 *
 * (Der Export steht VOR „Bereiche aus“ und „sperren“; sein Download hängt an keinem Bereich.) Löschen der Daten gehört
 * NICHT dazu — das ist ein eigener späterer Schritt nach Vertrag/AVV.
 *
 * Nur Plattform-Admin. §13 G: Die Vorschau enthält Verwaltungsdaten und ZÄHLUNGEN (Manifest), nie Inhalte. Dieser Dienst
 * liest keine Rezepte, Preise, Foodbooks, DNA oder Teamprofile — er zählt Zeilen.
 *
 * Diese Stufe (PR 1) liefert Vorschau und Lauf-Gerüst; die Schritte selbst führt PR 2 aus, der Export PR 3.
 */
class MandantBeendenService
{
    use PlattformAdminPruefung;

    public const SCHRITTE = ['abos', 'stamm_paket', 'export', 'bereiche', 'sperren'];

    public const TITEL = [
        'abos' => 'Abos kündigen',
        'stamm_paket' => 'Stamm-Paket abbestellen',
        'export' => 'Datenexport',
        'bereiche' => 'Bereiche ausschalten',
        'sperren' => 'Team sperren',
    ];

    public const OFFEN = 'offen';

    public const ERLEDIGT = 'erledigt';

    public const ENTFAELLT = 'entfaellt';

    /** Zählbare Bestandteile des Exports: Schlüssel => Model-Klasse (Tabelle und Team-Spalte kommen vom Modell). */
    private const MANIFEST = [
        'rezepte' => \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
        'konzepte' => \Platform\FoodAlchemist\Models\FoodAlchemistConcept::class,
        'foodbooks' => \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,
        'angebote' => \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::class,
        'speisekarten' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::class,
        'speiseplaene' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::class,
        'grundprodukte' => \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
        'lieferanten' => \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::class,
        'artikel' => \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::class,
        'preise' => \Platform\FoodAlchemist\Models\FoodAlchemistPrice::class,
        'dna_canvases' => \Platform\FoodAlchemist\Models\FoodAlchemistCanvas::class,
    ];

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly StammPaketService $paket,
        private readonly AboAuskunft $abos,
        private readonly StandortService $standorte,
        private readonly PlattformProtokoll $protokoll,
        private readonly MandantenService $mandanten,
        private readonly ExportAuskunft $export,
    ) {
    }

    /**
     * Was das Beenden bewirken würde — je Schritt Zustand, Wirkung und Details, in der festen Reihenfolge.
     *
     * @return array{
     *   team_id:int, name:string, status:string, modus:string, unter_teams:int, mitglieder:int,
     *   schritte:list<array{schritt:string, titel:string, zustand:string, wirkung:string, details:array<string,mixed>}>,
     *   bestaetigung_noetig:bool, offener_lauf:?array<string,mixed>
     * }
     */
    public function vorschau(Team $mandant, ?User $user): array
    {
        // Spec 84 B3 C: Admin handelt am Mandanten; gelesen wird in dessen Sicht, das Recht prüft der Rumpf
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($mandant, fn () => $this->vorschauImTeam($mandant, $user));
    }

    private function vorschauImTeam(Team $mandant, ?User $user): array
    {
        $this->nurPlattformAdmin($user, 'Mandant beenden: Vorschau');
        $this->pruefeMandant($mandant);

        $zeile = FoodAlchemistMandant::where('team_id', $mandant->id)->first();
        $teamIds = array_merge([(int) $mandant->id], $this->standorte->unterTeamIds($mandant));
        $schritte = [
            $this->schrittAbos($mandant),
            $this->schrittStammPaket($mandant),
            $this->schrittExport($teamIds),
            $this->schrittBereiche($mandant),
            $this->schrittSperren($zeile),
        ];
        $lauf = $this->offenerLauf($mandant);

        return [
            'team_id' => (int) $mandant->id,
            'name' => (string) $mandant->name,
            'status' => $zeile->status ?? FoodAlchemistMandant::STATUS_STANDARD,
            'modus' => $zeile->modus ?? FoodAlchemistMandant::MODUS_STANDARD,
            'unter_teams' => count($teamIds) - 1,
            'mitglieder' => (int) DB::table('team_user')->whereIn('team_id', $teamIds)->distinct('user_id')->count('user_id'),
            'schritte' => $schritte,
            // Bestätigung ist nötig, sobald das Beenden etwas Fremdes berührt (Abos) oder den Zugriff sperrt
            'bestaetigung_noetig' => true,
            'offener_lauf' => $lauf === null ? null : $this->laufDarstellung($lauf),
        ];
    }

    /**
     * Lauf anlegen (noch ohne Schritte auszuführen). Ein Mandant hat höchstens einen offenen Lauf; ein zweiter Aufruf
     * liefert ihn zurück. Ohne Schrittliste gelten alle Schritte.
     *
     * @param  list<string>|null  $schritte
     */
    public function starte(Team $mandant, ?User $user, ?array $schritte = null): FoodAlchemistMandantBeendenLauf
    {
        // Spec 84 B3 C: Admin handelt am Mandanten; versandte Jobs tragen den Mandanten, das Recht prüft der Rumpf
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($mandant, fn () => $this->starteImTeam($mandant, $user, $schritte));
    }

    private function starteImTeam(Team $mandant, ?User $user, ?array $schritte = null): FoodAlchemistMandantBeendenLauf
    {
        $admin = $this->nurPlattformAdmin($user, 'Mandant beenden');
        $this->pruefeMandant($mandant);
        if (($offen = $this->offenerLauf($mandant)) !== null) {
            return $offen;
        }
        $gewaehlt = $schritte === null ? self::SCHRITTE : array_values(array_intersect(self::SCHRITTE, $schritte));   // Reihenfolge ist fest
        if ($gewaehlt === [] || ($schritte !== null && count($gewaehlt) !== count(array_unique($schritte)))) {
            throw new \RuntimeException('Schritte: '.implode(' | ', self::SCHRITTE).'.');
        }
        $lauf = FoodAlchemistMandantBeendenLauf::create([
            'team_id' => $mandant->id, 'status' => FoodAlchemistMandantBeendenLauf::STATUS_OFFEN,
            'schritte' => $gewaehlt, 'fortschritt' => [], 'gestartet_von' => $admin->id,
        ]);
        $this->protokoll->schreibe('mandant_beenden_gestartet', ['team_id' => $mandant->id, 'team' => $mandant->name, 'schritte' => $gewaehlt], $admin, $lauf);

        return $lauf;
    }

    /**
     * Den Lauf AUSFÜHREN: Schritt für Schritt in fester Reihenfolge, jeder Schritt idempotent, der Fortschritt steht im Lauf.
     * Braucht die ausdrückliche Bestätigung nach der Vorschau. Fehler oder ein wartender Schritt (Export fehlt) halten den
     * Lauf an — er bleibt offen und lässt sich mit demselben Aufruf fortsetzen. Der letzte Schritt sperrt das Team (Vollsperre).
     *
     * @param  list<string>|null  $schritte  nur beim Start eines neuen Laufs; ein offener Lauf behält seine Schritte
     * @return array{lauf: array<string,mixed>, abgeschlossen: bool, angehalten_bei: ?string, grund: ?string}
     */
    public function fuehreAus(Team $mandant, ?User $user, bool $bestaetigt, ?array $schritte = null): array
    {
        // Spec 84 B3 C: Admin handelt am Mandanten; gelesen wird in dessen Sicht, das Recht prüft der Rumpf
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($mandant, fn () => $this->fuehreAusImTeam($mandant, $user, $bestaetigt, $schritte));
    }

    private function fuehreAusImTeam(Team $mandant, ?User $user, bool $bestaetigt, ?array $schritte = null): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Mandant beenden');
        $this->pruefeMandant($mandant);
        if (! $bestaetigt) {
            throw new \RuntimeException('Das Beenden braucht die ausdrückliche Bestätigung nach der Vorschau (bestaetigt).');
        }
        $lauf = $this->starte($mandant, $admin, $schritte);
        $fortschritt = $lauf->fortschritt ?? [];
        $angehalten = null;
        $grund = null;

        foreach ($lauf->schritte as $schritt) {
            if (in_array($fortschritt[$schritt]['status'] ?? null, [self::ERLEDIGT, self::ENTFAELLT], true)) {
                continue;
            }
            try {
                $r = $this->fuehreSchrittAus($schritt, $mandant, $admin);
            } catch (\Throwable $e) {
                $fortschritt[$schritt] = ['status' => 'fehler', 'at' => now()->toIso8601String(), 'von' => $admin->id, 'details' => ['meldung' => $e->getMessage()]];
                $lauf->update(['fortschritt' => $fortschritt]);
                $angehalten = $schritt;
                $grund = $e->getMessage();

                break;
            }
            $fortschritt[$schritt] = ['status' => $r['status'], 'at' => now()->toIso8601String(), 'von' => $admin->id, 'details' => $r['details'] ?? []];
            $lauf->update(['fortschritt' => $fortschritt]);
            if ($r['status'] === 'wartet') {
                $angehalten = $schritt;
                $grund = (string) ($r['details']['meldung'] ?? 'Der Schritt wartet.');

                break;
            }
            $this->protokoll->schreibe('mandant_beenden_schritt', ['team_id' => $mandant->id, 'team' => $mandant->name, 'schritt' => $schritt, 'status' => $r['status']], $admin, $lauf);
        }

        $fertig = $angehalten === null;
        if ($fertig) {
            $lauf->update(['status' => FoodAlchemistMandantBeendenLauf::STATUS_ABGESCHLOSSEN, 'abgeschlossen_at' => now()]);
            $this->protokoll->schreibe('mandant_beenden_abgeschlossen', ['team_id' => $mandant->id, 'team' => $mandant->name, 'schritte' => $lauf->schritte], $admin, $lauf);
        }

        return ['lauf' => $this->laufDarstellung($lauf->refresh()), 'abgeschlossen' => $fertig, 'angehalten_bei' => $angehalten, 'grund' => $grund];
    }

    /**
     * Rückgängig: Status „beendet“ zurück auf „aktiv“ — der Zugriff ist wieder offen. Die Bereiche BLEIBEN aus, bis sie neu
     * gebucht werden (Dominique 13.10.), ein evtl. abbestelltes Stamm-Paket ebenso. Die Daten wurden nie gelöscht.
     *
     * @return array<string,mixed> der Mandant (Verwaltungsdaten)
     */
    public function entsperren(Team $mandant, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Mandant entsperren');
        $this->pruefeMandant($mandant);
        $zeile = FoodAlchemistMandant::where('team_id', $mandant->id)->first();
        if (($zeile->status ?? FoodAlchemistMandant::STATUS_STANDARD) !== FoodAlchemistMandant::STATUS_BEENDET) {
            throw new \RuntimeException('Der Mandant ist nicht gesperrt (Status „beendet“).');
        }
        $r = $this->mandanten->setze($mandant, ['status' => FoodAlchemistMandant::STATUS_STANDARD], $admin);
        $this->protokoll->schreibe('mandant_entsperrt', ['team_id' => $mandant->id, 'team' => $mandant->name], $admin, FoodAlchemistMandant::where('team_id', $mandant->id)->first());

        return $r;
    }

    /** @return array{status: string, details?: array<string,mixed>} */
    private function fuehreSchrittAus(string $schritt, Team $mandant, User $admin): array
    {
        return match ($schritt) {
            'abos' => $this->fuehreAbosAus($mandant, $admin),
            'stamm_paket' => $this->fuehreStammPaketAus($mandant, $admin),
            'export' => $this->fuehreExportAus($mandant),
            'bereiche' => $this->fuehreBereicheAus($mandant, $admin),
            'sperren' => $this->fuehreSperrenAus($mandant, $admin),
        };
    }

    private function fuehreAbosAus(Team $mandant, User $admin): array
    {
        if (! $this->abos->verfuegbar()) {
            return ['status' => self::ENTFAELLT, 'details' => ['meldung' => 'Es gibt noch keine Abos im System.']];
        }

        return ['status' => self::ERLEDIGT, 'details' => ['gekuendigt' => count($this->abos->kuendigen($mandant, $admin))]];
    }

    private function fuehreStammPaketAus(Team $mandant, User $admin): array
    {
        if (! $this->paket->status($mandant)['gebucht']) {
            return ['status' => self::ERLEDIGT, 'details' => ['war_gebucht' => false]];
        }
        $this->paket->abbestellen($mandant, $admin, true);   // die Abos wurden im Schritt davor gekündigt; bestätigt ist hier der Gesamtlauf

        return ['status' => self::ERLEDIGT, 'details' => ['war_gebucht' => true]];
    }

    /** Ohne vorliegenden Export hält der Lauf an, statt das Team ohne Daten-Auszug zu sperren. */
    private function fuehreExportAus(Team $mandant): array
    {
        if (! $this->export->vorhanden($mandant)) {
            return ['status' => 'wartet', 'details' => ['meldung' => 'Der Datenexport liegt noch nicht vor. Erst den Export erzeugen (mandant_export.POST) oder den Schritt „export“ bewusst weglassen — vor dem Sperren.']];
        }

        return ['status' => self::ERLEDIGT, 'details' => ['export_vorhanden' => true]];
    }

    private function fuehreBereicheAus(Team $mandant, User $admin): array
    {
        $aus = [];
        foreach (array_keys(array_filter($this->rechte->teamBereiche($mandant))) as $bereich) {
            $this->rechte->setzeTeamBereich($mandant, $admin, $bereich, false);
            $aus[] = $bereich;
        }

        return ['status' => self::ERLEDIGT, 'details' => ['ausgeschaltet' => $aus]];
    }

    private function fuehreSperrenAus(Team $mandant, User $admin): array
    {
        $this->mandanten->setze($mandant, ['status' => FoodAlchemistMandant::STATUS_BEENDET], $admin);

        return ['status' => self::ERLEDIGT, 'details' => ['status' => FoodAlchemistMandant::STATUS_BEENDET]];
    }

    /** Offenen Lauf abbrechen (Verlauf bleibt). */
    public function abbrechen(Team $mandant, ?User $user): ?FoodAlchemistMandantBeendenLauf
    {
        $admin = $this->nurPlattformAdmin($user, 'Mandant beenden: Lauf abbrechen');
        $this->pruefeMandant($mandant);
        $lauf = $this->offenerLauf($mandant);
        if ($lauf === null) {
            return null;
        }
        $lauf->update(['status' => FoodAlchemistMandantBeendenLauf::STATUS_ABGEBROCHEN, 'abgeschlossen_at' => now()]);
        $this->protokoll->schreibe('mandant_beenden_abgebrochen', ['team_id' => $mandant->id, 'team' => $mandant->name], $admin, $lauf);

        return $lauf;
    }

    public function offenerLauf(Team $mandant): ?FoodAlchemistMandantBeendenLauf
    {
        return FoodAlchemistMandantBeendenLauf::where('team_id', $mandant->id)
            ->where('status', FoodAlchemistMandantBeendenLauf::STATUS_OFFEN)->latest('id')->first();
    }

    /** @return array<string,mixed> */
    public function laufDarstellung(FoodAlchemistMandantBeendenLauf $lauf): array
    {
        return [
            'id' => (int) $lauf->id, 'status' => $lauf->status, 'schritte' => $lauf->schritte ?? [],
            'fortschritt' => $lauf->fortschritt ?? [], 'gestartet_von' => $lauf->gestartet_von,
            'abgeschlossen_am' => $lauf->abgeschlossen_at?->toDateString(),
        ];
    }

    private function schritt(string $key, string $zustand, string $wirkung, array $details = []): array
    {
        return ['schritt' => $key, 'titel' => self::TITEL[$key], 'zustand' => $zustand, 'wirkung' => $wirkung, 'details' => $details];
    }

    private function schrittAbos(Team $mandant): array
    {
        if (! $this->abos->verfuegbar()) {
            return $this->schritt('abos', self::ENTFAELLT, 'Es gibt noch keine Abos im System (Spec 82 Schritt 7); dieser Schritt entfällt, bis sie da sind.', ['abos' => 0, 'verfuegbar' => false]);
        }
        $abos = $this->abos->abosDesMandanten($mandant);
        if ($abos === []) {
            return $this->schritt('abos', self::ERLEDIGT, 'Der Mandant hat keine Abos.', ['abos' => 0, 'verfuegbar' => true]);
        }

        return $this->schritt('abos', self::OFFEN, sprintf('%d Abo(s) werden gekündigt; genutzte Rezepte werden als eigene Kopie (tiefe Kopie, §12 D) übernommen, ungenutzte verschwinden.', count($abos)),
            ['abos' => count($abos), 'sammlungen' => array_column($abos, 'sammlung'), 'verfuegbar' => true]);
    }

    private function schrittStammPaket(Team $mandant): array
    {
        $v = $this->paket->vorschauAbbestellen($mandant);
        if (! $v['gebucht']) {
            return $this->schritt('stamm_paket', self::ERLEDIGT, 'Das Stamm-Paket ist nicht gebucht.', ['gebucht' => false, 'abos' => 0]);
        }

        return $this->schritt('stamm_paket', self::OFFEN, $v['abos'] === []
            ? 'Das Stamm-Paket wird abbestellt; Grundprodukte, Lieferanten und Artikel des Pakets stehen dem Mandanten nicht mehr zur Verfügung.'
            : sprintf('Das Stamm-Paket wird abbestellt und macht %d Abo(s) ungültig (die Abos werden vorher gekündigt).', count($v['abos'])),
            ['gebucht' => true, 'abos' => count($v['abos'])]);
    }

    /** Zählung je Bestandteil des Exports (nur eigene Zeilen des Mandanten und seiner Unter-Teams, nie der Stamm). */
    private function schrittExport(array $teamIds): array
    {
        $manifest = [];
        foreach (self::MANIFEST as $key => $klasse) {
            $tabelle = (new $klasse())->getTable();
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'team_id')) {
                continue;
            }
            $manifest[$key] = (int) DB::table($tabelle)->whereIn('team_id', $teamIds)
                ->when(Schema::hasColumn($tabelle, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count();
        }

        return $this->schritt('export', self::OFFEN,
            'Alle eigenen Inhalte werden als Datei für den Inhaber des Mandanten bereitgestellt. Der Plattform-Admin sieht nur diese Zählung, nie die Inhalte; der Download ist nur für Inhaber/Admin des Mandanten-Teams (und den Master bei kuratierten Mandanten) möglich.',
            ['manifest' => $manifest, 'zeilen_gesamt' => array_sum($manifest)]);
    }

    private function schrittBereiche(Team $mandant): array
    {
        $aktiv = array_keys(array_filter($this->rechte->teamBereiche($mandant)));
        if ($aktiv === []) {
            return $this->schritt('bereiche', self::ERLEDIGT, 'Alle Bereiche sind ausgeschaltet.', ['aktiv' => []]);
        }

        return $this->schritt('bereiche', self::OFFEN, sprintf('%d Bereich(e) werden ausgeschaltet (der Export-Download bleibt erreichbar).', count($aktiv)),
            ['aktiv' => $aktiv, 'namen' => array_map(fn ($b) => FaBereiche::KATALOG[$b] ?? $b, $aktiv)]);
    }

    private function schrittSperren(?FoodAlchemistMandant $zeile): array
    {
        $status = $zeile->status ?? FoodAlchemistMandant::STATUS_STANDARD;
        if ($status === 'beendet') {
            return $this->schritt('sperren', self::ERLEDIGT, 'Das Team ist gesperrt (Status „beendet“).', ['status' => $status]);
        }

        return $this->schritt('sperren', self::OFFEN,
            'Das Team und seine Unter-Teams werden voll gesperrt: kein Zugriff mehr auf FA außer dem Export-Download. Rückgängig machbar (Status zurück auf „aktiv“; die Bereiche bleiben aus, bis sie neu gebucht werden). Die Daten werden NICHT gelöscht.',
            ['status' => $status]);
    }

    private function pruefeMandant(Team $mandant): void
    {
        if (MasterTeam::istMaster($mandant)) {
            throw new \RuntimeException('Das Master-Team lässt sich nicht beenden.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Beendet wird der Mandant (das Haupt-Team des Kunden), nicht ein Unter-Team.');
        }
    }
}
