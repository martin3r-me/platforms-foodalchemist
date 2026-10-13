<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamPaket;
use Platform\FoodAlchemist\Support\MasterTeam;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 82 §13 I — Paket „Grundeinstellungen" buchen (nur Plattform-Admin), per KOPIE ins Oberteam.
 *
 * Inhalt = reine Team-Arbeit, auf die kein Master-Inhalt zeigt (Anlage 82a, Block B). Alles, worauf Master-Rezepte/Stamm-GPs zeigen, ist
 * Basis-Katalog (global) und wird NICHT kopiert. Quelle sind die Zeilen des Master-Teams (bzw. globale Zeilen nach dem Stamm-Umzug).
 *
 * Idempotent: `paket_kopien` merkt je Mandant und Tabelle die Zuordnung Quelle → Kopie; ein zweites Buchen kopiert nichts doppelt, holt aber
 * neu hinzugekommene Master-Zeilen nach. Eine gleichnamige Team-Zeile wird NIE überschrieben (sie gilt als vorhanden und wird zugeordnet).
 * Unter-Teams erben über die Team-Kette, es wird nur am Haupt-Team kopiert.
 *
 * Bewusst NICHT im Paket (siehe PR): Fixkosten (Beträge sind kundenspezifisch), Planungsrahmen (hängen an Foodbook/Konzept), Konzept-Sektor-Eignung
 * (je Konzept), Werte der Aufschlagsklassen (Struktur ist Basis). Stundensätze der Rollen werden NICHT mitkopiert (BHG-intern, das Team setzt eigene).
 */
class PaketGrundeinstellungenService
{
    use PlattformAdminPruefung;

    public const PAKET = FoodAlchemistTeamPaket::GRUNDEINSTELLUNGEN;

    /**
     * Reihenfolge = Abhängigkeit. `schluessel` = natürlicher Schlüssel (gleichnamige Team-Zeile gilt als vorhanden),
     * `fk` = Spalte → Tabelle, deren Kopie-ID eingesetzt wird, `leeren` = Spalten, die nicht mitkopiert werden.
     *
     * @var list<array{tabelle:string, label:string, schluessel:list<string>, fk?:array<string,string>, leeren?:list<string>}>
     */
    public const TABELLEN = [
        ['tabelle' => 'concept_categories', 'label' => 'Konzept-Kategorien', 'schluessel' => ['name', 'parent_id'], 'fk' => ['parent_id' => 'concept_categories']],
        ['tabelle' => 'target_groups', 'label' => 'Zielgruppen (Konzept-Merkmale)', 'schluessel' => ['name']],
        ['tabelle' => 'writing_styles', 'label' => 'Schreibstile', 'schluessel' => ['slug'], 'leeren' => ['legacy_id']],
        ['tabelle' => 'kitchen_roles', 'label' => 'Rollen (ohne Stundensätze)', 'schluessel' => ['slug'], 'leeren' => ['stundensatz_eur']],
        ['tabelle' => 'production_stations', 'label' => 'Posten & Kapazität', 'schluessel' => ['slug']],
        ['tabelle' => 'menu_plan_chips', 'label' => 'Speiseplan-Chips', 'schluessel' => ['label']],
        ['tabelle' => 'presentation_designs', 'label' => 'Präsentations-Designs', 'schluessel' => ['name']],
    ];

    private const NIE_KOPIEREN = ['id', 'uuid', 'team_id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /** Für lesende Aufrufer (MCP, Oberfläche): wirft FaRechtFehltException, wenn nicht Plattform-Admin. */
    public function nurPlattformAdminOeffentlich(?User $user, string $wofuer): User
    {
        return $this->nurPlattformAdmin($user, $wofuer);
    }

    /**
     * Alle Mandanten (Kunden-Hauptteams ohne das Master-Team) mit Paketstatus.
     *
     * @return list<array{team_id:int, name:string, gebucht:bool, seit:?string}>
     */
    public function uebersicht(): array
    {
        $paket = FoodAlchemistTeamPaket::where('paket', self::PAKET)->get()->keyBy('team_id');
        $master = MasterTeam::id();

        return Team::whereNull('parent_team_id')->orderBy('name')->get(['id', 'name'])
            ->reject(fn ($t) => $master !== null && (int) $t->id === $master)
            ->map(fn ($t) => ['team_id' => (int) $t->id, 'name' => (string) $t->name, 'gebucht' => $paket->has($t->id),
                'seit' => $paket->get($t->id)?->gebucht_am?->format('d.m.Y')])
            ->values()->all();
    }

    /** @return array{team_id:int, gebucht:bool, seit:?string} */
    public function status(Team $mandant): array
    {
        $p = FoodAlchemistTeamPaket::where('team_id', $mandant->id)->where('paket', self::PAKET)->first();

        return ['team_id' => (int) $mandant->id, 'gebucht' => $p !== null, 'seit' => $p?->gebucht_am?->toDateString()];
    }

    /**
     * Was Buchen bewirken würde — je Tabelle: Quelle, schon zugeordnet, neu zu kopieren.
     *
     * @return array{team_id:int, quelle_team_id:?int, tabellen:list<array{tabelle:string, label:string, quelle:int, bereits:int, neu:int}>, trends_startbestand:bool}
     */
    public function vorschau(Team $mandant): array
    {
        $master = MasterTeam::id();
        $zeilen = [];
        foreach (self::TABELLEN as $spec) {
            $quelle = $this->quellZeilen($spec['tabelle'], $master);
            $bereits = DB::table('foodalchemist_paket_kopien')->where('team_id', $mandant->id)->where('paket', self::PAKET)
                ->where('tabelle', $spec['tabelle'])->whereIn('quell_id', array_column($quelle, 'id'))->count();
            $zeilen[] = ['tabelle' => $spec['tabelle'], 'label' => $spec['label'], 'quelle' => count($quelle), 'bereits' => $bereits, 'neu' => count($quelle) - $bereits];
        }

        return ['team_id' => (int) $mandant->id, 'quelle_team_id' => $master, 'tabellen' => $zeilen, 'trends_startbestand' => Schema::hasTable('foodalchemist_trends')];
    }

    /**
     * Paket buchen (idempotent). Nur Plattform-Admin, nur am Kunden-Hauptteam, nur mit konfiguriertem Master-Team.
     *
     * @return array{team_id:int, gebucht:bool, seit:?string, ergebnis:list<array{tabelle:string, neu:int, vorhanden:int, bereits:int}>, trends:string}
     */
    public function buchen(Team $mandant, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Paket „Grundeinstellungen" buchen');
        $this->pruefeMandant($mandant);
        $master = MasterTeam::id();
        if ($master === null) {
            throw new \RuntimeException('Es ist kein Master-Team festgelegt (master_team.PUT) — ohne Master gibt es keine Vorlage zum Kopieren.');
        }

        $ergebnis = DB::transaction(function () use ($mandant, $master) {
            $ergebnis = [];
            foreach (self::TABELLEN as $spec) {
                $ergebnis[] = $this->kopiere($mandant, $spec, $master);
            }

            return $ergebnis;
        });

        $trends = $this->trendsStartbestand($mandant);
        $war = FoodAlchemistTeamPaket::where('team_id', $mandant->id)->where('paket', self::PAKET)->exists();
        $paket = FoodAlchemistTeamPaket::updateOrCreate(
            ['team_id' => $mandant->id, 'paket' => self::PAKET],
            ['gebucht_von' => $war ? null : $admin->id, 'gebucht_am' => now()]
        );
        $this->protokoll->schreibe($war ? 'paket_grundeinstellungen_nachgezogen' : 'paket_grundeinstellungen_gebucht',
            ['team_id' => $mandant->id, 'team' => $mandant->name, 'ergebnis' => $ergebnis, 'trends' => $trends], $admin, $paket);

        return $this->status($mandant) + ['ergebnis' => $ergebnis, 'trends' => $trends];
    }

    /**
     * @param  array{tabelle:string, schluessel:list<string>, fk?:array<string,string>, leeren?:list<string>}  $spec
     * @return array{tabelle:string, neu:int, vorhanden:int, bereits:int}
     */
    private function kopiere(Team $mandant, array $spec, int $master): array
    {
        $tabelle = 'foodalchemist_'.$spec['tabelle'];
        $quelle = $this->quellZeilen($spec['tabelle'], $master);
        $spalten = array_values(array_diff(Schema::getColumnListing($tabelle), self::NIE_KOPIEREN, $spec['leeren'] ?? []));
        $hatUuid = Schema::hasColumn($tabelle, 'uuid');
        $hatZeit = Schema::hasColumn($tabelle, 'created_at');
        $zahl = ['neu' => 0, 'vorhanden' => 0, 'bereits' => 0];

        // Eltern vor Kindern (parent_id): so lange durchlaufen, bis jede Zeile ihren Vorgänger zugeordnet hat.
        $offen = $quelle;
        for ($runde = 0; $offen !== [] && $runde < 12; $runde++) {
            $rest = [];
            foreach ($offen as $zeile) {
                $werte = \Illuminate\Support\Arr::only($zeile, $spalten);
                $wartet = false;
                foreach ($spec['fk'] ?? [] as $spalte => $fkTabelle) {
                    if (($zeile[$spalte] ?? null) === null) {
                        continue;
                    }
                    $ziel = $this->zuordnung($mandant, $fkTabelle, (int) $zeile[$spalte]);
                    if ($ziel === null) {
                        $wartet = true;
                        break;
                    }
                    $werte[$spalte] = $ziel;
                }
                if ($wartet) {
                    $rest[] = $zeile;

                    continue;
                }
                if ($this->zuordnung($mandant, $spec['tabelle'], (int) $zeile['id']) !== null) {
                    $zahl['bereits']++;

                    continue;
                }

                $vorhanden = $this->vorhandeneTeamZeile($tabelle, $mandant->id, $spec['schluessel'], $werte);
                if ($vorhanden !== null) {
                    $this->merke($mandant, $spec['tabelle'], (int) $zeile['id'], $vorhanden, 'vorhanden');
                    $zahl['vorhanden']++;

                    continue;
                }
                $neu = $werte + ['team_id' => $mandant->id];
                if ($hatUuid) {
                    $neu['uuid'] = (string) UuidV7::generate();
                }
                if ($hatZeit) {
                    $neu['created_at'] = $neu['updated_at'] = now();
                }
                $id = (int) DB::table($tabelle)->insertGetId($neu);
                $this->merke($mandant, $spec['tabelle'], (int) $zeile['id'], $id, 'kopie');
                $zahl['neu']++;
            }
            $offen = $rest;
        }

        return ['tabelle' => $spec['tabelle']] + $zahl;
    }

    /** @return list<array<string,mixed>> Vorlagen-Zeilen: Master-Team bzw. global, nicht gelöscht */
    private function quellZeilen(string $tabelle, ?int $master): array
    {
        $voll = 'foodalchemist_'.$tabelle;
        if (! Schema::hasTable($voll)) {
            return [];
        }
        $q = DB::table($voll)->where(function ($w) use ($master) {
            $w->whereNull('team_id');
            if ($master !== null) {
                $w->orWhere('team_id', $master);
            }
        });
        if (Schema::hasColumn($voll, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return $q->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function zuordnung(Team $mandant, string $tabelle, int $quellId): ?int
    {
        $id = DB::table('foodalchemist_paket_kopien')->where('team_id', $mandant->id)->where('paket', self::PAKET)
            ->where('tabelle', $tabelle)->where('quell_id', $quellId)->value('kopie_id');

        return $id === null ? null : (int) $id;
    }

    private function merke(Team $mandant, string $tabelle, int $quellId, int $kopieId, string $art): void
    {
        DB::table('foodalchemist_paket_kopien')->insert([
            'team_id' => $mandant->id, 'paket' => self::PAKET, 'tabelle' => $tabelle, 'quell_id' => $quellId,
            'kopie_id' => $kopieId, 'art' => $art, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $schluessel @param  array<string,mixed>  $werte */
    private function vorhandeneTeamZeile(string $tabelle, int $teamId, array $schluessel, array $werte): ?int
    {
        $q = DB::table($tabelle)->where('team_id', $teamId);
        if (Schema::hasColumn($tabelle, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        foreach ($schluessel as $spalte) {
            $wert = $werte[$spalte] ?? null;
            $wert === null ? $q->whereNull($spalte) : $q->where($spalte, $wert);
        }
        $id = $q->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Trend-Startbestand ist ein Repo-Seed (Spec 79), keine Master-Zeilen: derselbe idempotente Befehl, hier für den Mandanten. */
    private function trendsStartbestand(Team $mandant): string
    {
        if (! Schema::hasTable('foodalchemist_trends')) {
            return 'übersprungen (Tabelle fehlt)';
        }
        try {
            Artisan::call('foodalchemist:trends-startbestand', ['--team' => $mandant->id]);

            return 'ok';
        } catch (\Throwable $e) {
            return 'Fehler: '.$e->getMessage();
        }
    }

    private function pruefeMandant(Team $mandant): void
    {
        if (MasterTeam::istMaster($mandant)) {
            throw new \RuntimeException('Das Master-Team ist die Vorlage und bucht das Paket nicht.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Das Paket wird am Haupt-Team des Kunden gebucht. Unter-Teams erben es.');
        }
    }
}
