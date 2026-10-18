<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
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
 * (je Konzept), Standorte (mandanteneigen, Plan B F4). Stundensätze der Rollen werden NICHT mitkopiert (BHG-intern, das Team setzt eigene).
 *
 * Plan B (Kurator 18.10.2026): dazu das Vokabular des Masters — Rezept-Hauptgruppen/-Kategorien, Aufschlagsklassen, Speisen-Hauptgruppen/-Klassen,
 * Servierformen, Behälter, Koch-Equipment, Regenerationsgeräte, Anlässe, Einsatzmomente, Saisons (Einheiten und Warengruppen sind globale
 * Plattform-Basis und werden nie kopiert). EINMALIGE Befüllung: die Kopien gehören danach dem Mandanten, ohne Rückverbindung.
 *  - Aufschlagsklassen OHNE BHG-Faktoren: class_factor_pct = 100 (neutral), raw_markup/service/profit = NULL. Der Preis entsteht aus dem Basissatz des
 *    Mandanten (seine Fixkosten, CatalogPricingService::enterpriseBaseRate) × Klassenfaktor — der Mandant staffelt seine Klassen danach selbst.
 *  - Verweise zeigen nie auf Master-Zeilen: Pflicht-Verweise auf die Kopie (oder eine globale Zeile), optionale ohne Gegenstück → NULL (gezählt).
 *  - „Bewusst gelöscht“: die Zuordnung in paket_kopien IST das Gedächtnis. Hat eine Quellzeile einmal eine Zuordnung (Kopie, vorhanden, aus einer
 *    Kündigung), wird sie NIE wieder angefasst — egal ob der Mandant umbenannt, deaktiviert oder (auch hart) gelöscht hat. Erneutes Buchen legt nur
 *    Quellzeilen an, die neu im Master sind. Nichts wird reaktiviert oder überschrieben.
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
        // Plan B: Vokabular des Masters (nur Master-Zeilen — globale sieht der Mandant ohnehin)
        ['tabelle' => 'recipe_main_groups', 'label' => 'Rezept-Hauptgruppen', 'schluessel' => ['code'], 'nur_master' => true],
        ['tabelle' => 'recipe_categories', 'label' => 'Rezept-Kategorien', 'schluessel' => ['main_group_id', 'label'], 'fk' => ['main_group_id' => 'recipe_main_groups'], 'nur_master' => true],
        ['tabelle' => 'markup_classes', 'label' => 'Aufschlagsklassen (ohne BHG-Faktoren)', 'schluessel' => ['code'], 'nur_master' => true,
            'setzen' => ['class_factor_pct' => 100, 'raw_markup_pct' => null, 'service_pct' => null, 'profit_pct' => null]],
        ['tabelle' => 'dish_main_groups', 'label' => 'Speisen-Hauptgruppen', 'schluessel' => ['code'], 'nur_master' => true,
            'fk_optional' => ['default_markup_class_id' => 'markup_classes']],
        ['tabelle' => 'dish_classes', 'label' => 'Speisenklassen', 'schluessel' => ['code'], 'nur_master' => true,
            'fk_optional' => ['dish_main_group_id' => 'dish_main_groups', 'default_markup_class_id' => 'markup_classes']],
        ['tabelle' => 'serving_forms', 'label' => 'Servierformen', 'schluessel' => ['code'], 'nur_master' => true],
        ['tabelle' => 'vocab_containers', 'label' => 'Behälter', 'schluessel' => ['slug'], 'nur_master' => true],
        ['tabelle' => 'vocab_kitchen_equipment', 'label' => 'Koch-Equipment', 'schluessel' => ['slug'], 'nur_master' => true],
        ['tabelle' => 'vocab_regeneration_devices', 'label' => 'Regenerationsgeräte', 'schluessel' => ['slug'], 'nur_master' => true],
        ['tabelle' => 'event_types', 'label' => 'Anlässe', 'schluessel' => ['name'], 'nur_master' => true],
        ['tabelle' => 'service_moments', 'label' => 'Einsatzmomente', 'schluessel' => ['name'], 'nur_master' => true],
        ['tabelle' => 'seasons', 'label' => 'Saisons', 'schluessel' => ['name'], 'nur_master' => true],
    ];

    /**
     * Verweise ohne DB-Fremdschlüssel (für die Rücknahme: „wird verwendet“). Spalte → Tabelle der Paket-Spec.
     *
     * @var array<string, list<array{0:string, 1:string}>>
     */
    private const WEICHE_VERWEISE = [
        'markup_classes' => [['foodalchemist_dish_main_groups', 'default_markup_class_id']],
        'production_stations' => [['foodalchemist_team_settings', 'gerichte_station_id']],
    ];

    private const NIE_KOPIEREN = ['id', 'uuid', 'team_id', 'legacy_id', 'created_at', 'updated_at', 'deleted_at'];   // legacy_id ist teils global unique

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
            $zuordnungen = DB::table('foodalchemist_paket_kopien')->where('team_id', $mandant->id)->where('paket', self::PAKET)
                ->where('tabelle', $spec['tabelle'])->whereIn('quell_id', array_column($quelle, 'id'))->get(['kopie_id', 'art']);
            $bereits = $zuordnungen->count();
            $zeilen[] = ['tabelle' => $spec['tabelle'], 'label' => $spec['label'], 'quelle' => count($quelle), 'bereits' => $bereits, 'neu' => count($quelle) - $bereits,
                'vorhanden' => $zuordnungen->where('art', 'vorhanden')->count(),
                'beim_mandanten_geloescht' => $this->geloeschteKopien('foodalchemist_'.$spec['tabelle'], $zuordnungen->pluck('kopie_id')->all())];
        }

        return ['team_id' => (int) $mandant->id, 'quelle_team_id' => $master, 'tabellen' => $zeilen, 'trends_startbestand' => Schema::hasTable('foodalchemist_trends')];
    }

    /**
     * Paket buchen (idempotent). Nur Plattform-Admin, nur am Kunden-Hauptteam, nur mit konfiguriertem Master-Team.
     *
     * @return array{team_id:int, gebucht:bool, seit:?string, ergebnis:list<array{tabelle:string, neu:int, vorhanden:int, bereits:int}>, pass:array{posten:string, vorgabe:string}, trends:string}
     */
    public function buchen(Team $mandant, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Paket „Grundeinstellungen" buchen');
        $this->pruefeMandant($mandant);
        $master = MasterTeam::id();
        if ($master === null) {
            throw new \RuntimeException('Es ist kein Master-Team festgelegt (master_team.PUT) — ohne Master gibt es keine Vorlage zum Kopieren.');
        }

        $pass = [];
        $standard = null;
        $ergebnis = DB::transaction(function () use ($mandant, $master, &$pass, &$standard) {
            $ergebnis = [];
            foreach (self::TABELLEN as $spec) {
                $ergebnis[] = $this->kopiere($mandant, $spec, $master);
            }
            $pass = $this->passUndVorgabe($mandant);
            $standard = $this->standardAufschlagsklasse($mandant, $master);

            return $ergebnis;
        });

        $trends = $this->trendsStartbestand($mandant);
        $war = FoodAlchemistTeamPaket::where('team_id', $mandant->id)->where('paket', self::PAKET)->exists();
        $paket = FoodAlchemistTeamPaket::updateOrCreate(
            ['team_id' => $mandant->id, 'paket' => self::PAKET],
            ['gebucht_von' => $war ? null : $admin->id, 'gebucht_am' => now()]
        );
        $this->protokoll->schreibe($war ? 'paket_grundeinstellungen_nachgezogen' : 'paket_grundeinstellungen_gebucht',
            ['team_id' => $mandant->id, 'team' => $mandant->name, 'ergebnis' => $ergebnis, 'pass' => $pass, 'standard_aufschlagsklasse' => $standard, 'trends' => $trends], $admin, $paket);

        return $this->status($mandant) + ['ergebnis' => $ergebnis, 'pass' => $pass, 'standard_aufschlagsklasse' => $standard, 'trends' => $trends];
    }

    /**
     * @param  array{tabelle:string, schluessel:list<string>, fk?:array<string,string>, leeren?:list<string>}  $spec
     * @return array{tabelle:string, neu:int, vorhanden:int, bereits:int}
     */
    private function kopiere(Team $mandant, array $spec, int $master): array
    {
        $tabelle = 'foodalchemist_'.$spec['tabelle'];
        $quelle = $this->quellZeilen($spec['tabelle'], $master, (bool) ($spec['nur_master'] ?? false));
        $alleSpalten = Schema::getColumnListing($tabelle);
        $spalten = array_values(array_diff($alleSpalten, self::NIE_KOPIEREN, $spec['leeren'] ?? []));
        $setzen = array_intersect_key($spec['setzen'] ?? [], array_flip($alleSpalten));
        $hatUuid = Schema::hasColumn($tabelle, 'uuid');
        $hatZeit = Schema::hasColumn($tabelle, 'created_at');
        $zahl = ['neu' => 0, 'vorhanden' => 0, 'bereits' => 0, 'verweis_genullt' => 0];

        // Eltern vor Kindern (parent_id): so lange durchlaufen, bis jede Zeile ihren Vorgänger zugeordnet hat.
        $offen = $quelle;
        for ($runde = 0; $offen !== [] && $runde < 12; $runde++) {
            $rest = [];
            foreach ($offen as $zeile) {
                $werte = $setzen + \Illuminate\Support\Arr::only($zeile, $spalten);
                $wartet = false;
                $genullt = 0;
                foreach (($spec['fk'] ?? []) + ($spec['fk_optional'] ?? []) as $spalte => $fkTabelle) {
                    if (($zeile[$spalte] ?? null) === null) {
                        continue;
                    }
                    $ziel = $this->zuordnung($mandant, $fkTabelle, (int) $zeile[$spalte]);
                    if ($ziel === null && $this->istGlobal($fkTabelle, (int) $zeile[$spalte])) {
                        $ziel = (int) $zeile[$spalte];   // globale Zeile: der Mandant sieht sie, kein Master-Verweis
                    }
                    if ($ziel === null && isset($spec['fk_optional'][$spalte])) {
                        $werte[$spalte] = null;          // nie auf eine Master-Zeile zeigen
                        $genullt++;

                        continue;
                    }
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
                $zahl['verweis_genullt'] += $genullt;
            }
            $offen = $rest;
        }

        return ['tabelle' => $spec['tabelle']] + $zahl;
    }

    /** @return list<array<string,mixed>> Vorlagen-Zeilen: Master-Team bzw. global, nicht gelöscht */
    private function quellZeilen(string $tabelle, ?int $master, bool $nurMaster = false): array
    {
        $voll = 'foodalchemist_'.$tabelle;
        if (! Schema::hasTable($voll) || ($nurMaster && $master === null)) {
            return [];
        }
        $q = DB::table($voll)->where(function ($w) use ($master, $nurMaster) {
            if ($nurMaster) {
                $w->where('team_id', $master);   // Plan B: globale Vokabular-Zeilen sieht der Mandant ohnehin — eine Kopie wäre doppelt

                return;
            }
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

    private function istGlobal(string $tabelle, int $id): bool
    {
        return DB::table('foodalchemist_'.$tabelle)->where('id', $id)->whereNull('team_id')->exists();
    }

    /** @param list<int|string> $kopieIds */
    private function geloeschteKopien(string $tabelle, array $kopieIds): int
    {
        if ($kopieIds === [] || ! Schema::hasTable($tabelle)) {
            return 0;
        }
        $da = DB::table($tabelle)->whereIn('id', $kopieIds)
            ->when(Schema::hasColumn($tabelle, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count();

        return count(array_unique($kopieIds)) - $da;
    }

    /**
     * Team-Standard-Aufschlagsklasse: die des Masters, umgehängt auf die Kopie des Mandanten — nur wenn der Mandant noch keine hat (nie überschreiben).
     * Ohne Gegenstück beim Mandanten bleibt das Feld leer (nie die Master-Klasse).
     */
    private function standardAufschlagsklasse(Team $mandant, int $master): string
    {
        if (! Schema::hasColumn('foodalchemist_team_settings', 'default_markup_class_id')) {
            return 'übersprungen';
        }
        $settings = FoodAlchemistTeamSetting::firstOrNew(['team_id' => $mandant->id]);
        if (! empty($settings->default_markup_class_id)) {
            return 'bereits gesetzt';
        }
        $masterKlasse = DB::table('foodalchemist_team_settings')->where('team_id', $master)->value('default_markup_class_id');
        $ziel = $masterKlasse !== null ? $this->zuordnung($mandant, 'markup_classes', (int) $masterKlasse) : null;
        if ($ziel === null) {
            return 'keine (Master ohne Standard oder ohne Gegenstück)';
        }
        $settings->default_markup_class_id = $ziel;
        $settings->save();

        return 'gesetzt';
    }

    /**
     * Paket zurücknehmen (nur Plattform-Admin): löscht NUR Kopien (art = kopie), die der Mandant seit dem Kopieren nicht geändert hat und die nirgends
     * verwendet werden; Kinder vor Eltern. Alles andere bleibt und steht in der Liste. Ohne $bestaetigt nur Vorschau.
     *
     * @return array{bestaetigt:bool, entfernt: array<string,int>, bleibt: list<array{tabelle:string, id:int, grund:string}>}
     */
    public function zuruecknehmen(Team $mandant, ?User $user, bool $bestaetigt): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Paket „Grundeinstellungen" zurücknehmen');
        $this->pruefeMandant($mandant);
        $raus = ['bestaetigt' => $bestaetigt, 'entfernt' => [], 'bleibt' => []];
        $arbeit = function () use ($mandant, $bestaetigt, &$raus) {
            foreach (array_reverse(self::TABELLEN) as $spec) {
                $tabelle = 'foodalchemist_'.$spec['tabelle'];
                if (! Schema::hasTable($tabelle)) {
                    continue;
                }
                $kopien = DB::table('foodalchemist_paket_kopien')->where('team_id', $mandant->id)->where('paket', self::PAKET)
                    ->where('tabelle', $spec['tabelle'])->where('art', 'kopie')->get(['id', 'kopie_id']);
                $n = 0;
                foreach ($kopien as $k) {
                    $zeile = DB::table($tabelle)->where('id', $k->kopie_id)->first();
                    $grund = match (true) {
                        $zeile === null || (($zeile->deleted_at ?? null) !== null) => null,   // schon weg: nur die Zuordnung bleibt als Gedächtnis
                        isset($zeile->updated_at, $zeile->created_at) && (string) $zeile->updated_at !== (string) $zeile->created_at => 'vom Mandanten geändert',
                        ($v = $this->verwendungen($spec['tabelle'], (int) $k->kopie_id)) > 0 => "wird verwendet ({$v}×)",
                        default => 'entfernen',
                    };
                    if ($grund === null) {
                        continue;
                    }
                    if ($grund !== 'entfernen') {
                        $raus['bleibt'][] = ['tabelle' => $spec['tabelle'], 'id' => (int) $k->kopie_id, 'grund' => $grund];

                        continue;
                    }
                    $n++;
                    if ($bestaetigt) {
                        DB::table($tabelle)->where('id', $k->kopie_id)->delete();
                        DB::table('foodalchemist_paket_kopien')->where('id', $k->id)->delete();
                    }
                }
                if ($n > 0) {
                    $raus['entfernt'][$spec['tabelle']] = $n;
                }
            }
        };
        $bestaetigt ? DB::transaction($arbeit) : $arbeit();
        if ($bestaetigt) {
            $this->protokoll->schreibe('paket_grundeinstellungen_zurueckgenommen', ['team_id' => $mandant->id, 'team' => $mandant->name] + $raus, $admin, null);
        }

        return $raus;
    }

    /** Wie oft eine Zeile verwendet wird: alle DB-Fremdschlüssel aus FA-Tabellen auf diese Tabelle + die weichen Verweise. */
    private function verwendungen(string $tabelleKurz, int $id): int
    {
        $ziel = 'foodalchemist_'.$tabelleKurz;
        static $fks = null;
        if ($fks === null) {
            $fks = [];
            foreach (Schema::getTableListing() as $t) {
                $t = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
                if (! str_starts_with($t, 'foodalchemist_') || $t === 'foodalchemist_paket_kopien') {
                    continue;
                }
                foreach (Schema::getForeignKeys($t) as $fk) {
                    if (count($fk['columns']) === 1) {
                        $fks[$fk['foreign_table']][] = [$t, $fk['columns'][0]];
                    }
                }
            }
        }
        $n = 0;
        foreach ([...($fks[$ziel] ?? []), ...(self::WEICHE_VERWEISE[$tabelleKurz] ?? [])] as [$t, $spalte]) {
            $n += DB::table($t)->where($spalte, $id)->count();
        }

        return $n;
    }

    /**
     * Gerichte gehen an den Pass (Spec 83 B2, Erweiterung): das Paket bringt einen Posten "Pass" mit, als SAMMELPOSTEN markiert (ohne eigenes Personal
     * und eigene Kapazität; legt ihn an, wenn das Team keinen mit dem
     * Kürzel `pass` hat; kommt er schon aus den Master-Vorlagen, gilt er als vorhanden) und setzt ihn als Team-Vorgabe "Posten für Gerichte",
     * aber NUR wenn das Team noch keine Vorgabe hat (nie überschreiben).
     *
     * @return array{posten:string, vorgabe:string}
     */
    private function passUndVorgabe(Team $mandant): array
    {
        if (! Schema::hasTable('foodalchemist_production_stations') || ! Schema::hasColumn('foodalchemist_team_settings', 'gerichte_station_id')) {
            return ['posten' => 'übersprungen', 'vorgabe' => 'übersprungen'];
        }
        $station = FoodAlchemistProductionStation::where('team_id', $mandant->id)->where('slug', 'pass')->first();
        $neu = $station === null;
        if ($neu) {
            $station = FoodAlchemistProductionStation::create(['team_id' => $mandant->id, 'slug' => 'pass', 'name' => 'Pass', 'group_name' => 'Ausgabe', 'sort_order' => 900, 'ist_sammelposten' => true]);
        }
        $settings = FoodAlchemistTeamSetting::firstOrNew(['team_id' => $mandant->id]);
        $gesetzt = empty($settings->gerichte_station_id);
        if ($gesetzt) {
            $settings->gerichte_station_id = $station->id;
            $settings->save();
        }

        return ['posten' => $neu ? 'angelegt' : 'vorhanden', 'vorgabe' => $gesetzt ? 'gesetzt' : 'bereits gesetzt'];
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
