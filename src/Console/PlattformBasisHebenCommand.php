<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Plattform-Basis (Entscheid Dominique 18.10.): Einheiten, Warengruppen und ihre Sub-Kategorien sind globale Plattform-Sprache.
 * Globale GPs und abonnierte Master-Rezepte zeigen auf dieselben Zeilen — eine Kopie je Mandant gäbe „kg“ zweimal mit
 * verschiedenen IDs (Mengen summieren, Einkaufslisten, Produktion, WG-Filter brechen). Dieses Kommando hebt den BESTAND des
 * Master-Teams nach global (team_id NULL). Neue Zeilen legt {@see \Platform\FoodAlchemist\Services\VocabularyService} seitdem global an.
 *
 * - IDs bleiben: alle Verweise (unit_vocab_id …, commodity_group_code, sub_category) gelten unverändert weiter.
 * - Soft-gelöschte Master-Zeilen gehen mit (sonst bliebe Schrott im Master hängen).
 * - Der Unique-Schlüssel (team_id, slug|code|…) greift bei team_id NULL in MySQL NICHT — darum prüft das Kommando Dubletten selbst
 *   und bricht mit Liste ab, bevor irgendetwas geschrieben wird. Mandanten-eigene Zeilen mit gleichem Schlüssel sind nur eine Warnung.
 * - Schreibt per Query-Builder (keine Modell-Ereignisse): kein Aktivitäts-Rauschen, kein Eintrag im fa-fremdschreiben-Log.
 *
 * Ablauf auf io: mysqldump der drei Tabellen → Trockenlauf (Default) → --apply → --verify. Rückweg: --zurueck=<Protokoll>.
 * Hinweis: das Alt-Werkzeug `foodalchemist:import-slice` legt noch team-eigene Einheiten/WG an — nicht auf io benutzen.
 */
class PlattformBasisHebenCommand extends Command
{
    /** Tabelle → fachlicher Schlüssel (Dubletten-Prüfung) + Modell (Sicht-Probe in --verify). */
    public const TABELLEN = [
        'foodalchemist_vocab_units' => ['label' => 'Einheiten', 'schluessel' => ['slug'], 'modell' => FoodAlchemistVocabEinheit::class],
        'foodalchemist_lookup_commodity_groups' => ['label' => 'Warengruppen', 'schluessel' => ['code'], 'modell' => FoodAlchemistLookupWarengruppe::class],
        'foodalchemist_commodity_group_subcategories' => ['label' => 'WG-Sub-Kategorien', 'schluessel' => ['commodity_group_code', 'name'], 'modell' => FoodAlchemistWarengruppeSubkategorie::class],
    ];

    /** Spalten mit Einheiten-ID (nur für --verify: IDs bleiben, es darf kein Verweis ins Leere zeigen). */
    public const EINHEITEN_VERWEISE = [
        'foodalchemist_recipe_ingredients' => 'unit_vocab_id',
        'foodalchemist_gps' => 'preferred_count_unit_id',
        'foodalchemist_recipes' => 'sales_unit_vocab_id',
        'foodalchemist_recipe_presentations' => 'unit_vocab_id',
        'foodalchemist_gp_count_unit_defaults' => 'unit_vocab_id',
        'foodalchemist_baustein_gerichte' => 'unit_vocab_id',
        'foodalchemist_concept_slots' => 'unit_vocab_id',
        'foodalchemist_foodbook_blocks' => 'unit_vocab_id',
        'foodalchemist_offer_blocks' => 'unit_vocab_id',
    ];

    protected $signature = 'foodalchemist:plattform-basis-heben
        {--team= : Quell-Team (Standard: das konfigurierte Master-Team)}
        {--apply : wirklich schreiben (ohne dies nur zählen und prüfen)}
        {--verify : nach dem Umzug prüfen (keine Master-Zeilen, keine Dubletten, keine verwaisten Verweise, Mandant sieht alles)}
        {--zurueck= : Pfad zum Protokoll eines --apply-Laufs — setzt genau diese Zeilen zurück auf das Quell-Team}';

    protected $description = 'Hebt Einheiten, Warengruppen und WG-Sub-Kategorien des Master-Teams auf globale Plattform-Basis (team_id NULL)';

    public function handle(): int
    {
        if ((int) (bool) $this->option('apply') + (int) (bool) $this->option('verify') + (int) ($this->option('zurueck') !== null) > 1) {
            $this->error('--apply, --verify und --zurueck schliessen sich aus.');

            return self::FAILURE;
        }
        if ($this->option('zurueck') !== null) {
            return $this->zurueck((string) $this->option('zurueck'));
        }

        $master = $this->option('team') ?? MasterTeam::id();
        if (! is_numeric($master)) {
            $this->error('Kein Quell-Team: --team=N angeben oder das Master-Team konfigurieren.');

            return self::FAILURE;
        }
        $master = (int) $master;
        if (MasterTeam::id() !== $master) {
            $this->warn("Achtung: Team {$master} ist nicht das konfigurierte Master-Team — globale Zeilen pflegt danach nur das Master-Team.");
        }

        return $this->option('verify') ? $this->verify($master) : $this->heben($master, (bool) $this->option('apply'));
    }

    private function heben(int $master, bool $apply): int
    {
        $befund = $this->befund($master);
        $this->table(
            ['Tabelle', 'Master-Zeilen', 'davon gelöscht', 'bereits global', 'Kollisionen', 'Mandanten-Warnungen'],
            array_map(fn (string $t) => [
                self::TABELLEN[$t]['label'],
                count($befund[$t]['ids']),
                $befund[$t]['geloescht'],
                $befund[$t]['global'],
                count($befund[$t]['kollisionen']),
                count($befund[$t]['warnungen']),
            ], array_keys(self::TABELLEN)),
        );

        foreach ($befund as $t => $b) {
            foreach ($b['warnungen'] as $w) {
                $this->warn('  ' . self::TABELLEN[$t]['label'] . ': ' . $w);
            }
        }
        $kollisionen = array_merge(...array_map(
            fn (string $t) => array_map(fn ($k) => self::TABELLEN[$t]['label'] . ': ' . $k, $befund[$t]['kollisionen']),
            array_keys($befund),
        ));
        if ($kollisionen !== []) {
            $this->error('Dubletten gefunden — nichts geschrieben. Erst bereinigen:');
            foreach ($kollisionen as $k) {
                $this->line('  ' . $k);
            }

            return self::FAILURE;
        }

        $summe = array_sum(array_map(fn ($b) => count($b['ids']), $befund));
        if ($summe === 0) {
            $this->info("Team {$master} trägt keine Einheiten/Warengruppen/Sub-Kategorien mehr — nichts zu tun.");

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->line("Trockenlauf — nichts geschrieben. {$summe} Zeilen würden global. Mit --apply ausführen.");
            $this->line('Vorher sichern: mysqldump <db> ' . implode(' ', array_keys(self::TABELLEN)) . ' > plattform-basis-vorher.sql');

            return self::SUCCESS;
        }

        // Das Protokoll IST der Rückweg — es entsteht VOR dem Schreiben, damit es auch nach einem Abbruch da ist.
        $ids = array_map(fn ($b) => $b['ids'], $befund);
        $protokoll = storage_path('logs/plattform-basis-heben-' . now()->format('Ymd-His') . '.json');
        file_put_contents($protokoll, (string) json_encode([
            'quell_team' => $master,
            'zeitpunkt' => now()->toIso8601String(),
            'ids' => $ids,
            'zurueck' => "php artisan foodalchemist:plattform-basis-heben --zurueck={$protokoll}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        DB::transaction(function () use ($ids, $master) {
            foreach ($ids as $tabelle => $liste) {
                foreach (array_chunk($liste, 500) as $teil) {
                    DB::table($tabelle)->whereIn('id', $teil)->where('team_id', $master)->update(['team_id' => null]);
                }
            }
        });

        $this->info("✓ {$summe} Zeilen global gehoben. Protokoll/Rückweg: {$protokoll}");
        $this->line('Jetzt prüfen: php artisan foodalchemist:plattform-basis-heben --verify');

        return self::SUCCESS;
    }

    /**
     * Je Tabelle: Master-IDs (inkl. gelöschte), Kollisionen (Abbruch) und Mandanten-Warnungen.
     *
     * @return array<string, array{ids: list<int>, geloescht: int, global: int, kollisionen: list<string>, warnungen: list<string>}>
     */
    public function befund(int $master): array
    {
        $out = [];
        foreach (self::TABELLEN as $tabelle => $spec) {
            $felder = $spec['schluessel'];
            $zeilen = DB::table($tabelle)->where(fn ($q) => $q->where('team_id', $master)->orWhereNull('team_id'))
                ->orderBy('id')->get(array_merge(['id', 'team_id', 'deleted_at'], $felder));
            $quelle = $zeilen->where('team_id', $master);
            $global = $zeilen->whereNull('team_id');

            $kollisionen = [];
            $aktiveQuelle = $quelle->whereNull('deleted_at');
            $aktiveGlobal = $global->whereNull('deleted_at');
            $globalNachSchluessel = $aktiveGlobal->groupBy(fn ($z) => self::schluessel($z, $felder));
            foreach ($aktiveQuelle as $z) {
                $k = self::schluessel($z, $felder);
                if ($globalNachSchluessel->has($k)) {
                    $kollisionen[] = sprintf('„%s“ Master-ID %d ⇄ globale ID %s', $k, $z->id, $globalNachSchluessel[$k]->pluck('id')->implode(', '));
                }
            }
            foreach (['Master' => $aktiveQuelle, 'global' => $aktiveGlobal] as $wo => $menge) {
                foreach ($menge->groupBy(fn ($z) => self::schluessel($z, $felder)) as $k => $gruppe) {
                    if ($gruppe->count() > 1) {
                        $kollisionen[] = sprintf('„%s“ mehrfach (%s): IDs %s', $k, $wo, $gruppe->pluck('id')->implode(', '));
                    }
                }
            }

            $basisSchluessel = $aktiveQuelle->concat($aktiveGlobal)->map(fn ($z) => self::schluessel($z, $felder))->unique()->flip();
            $warnungen = [];
            DB::table($tabelle)->whereNotNull('team_id')->where('team_id', '!=', $master)->whereNull('deleted_at')
                ->orderBy('team_id')->orderBy('id')->get(array_merge(['id', 'team_id'], $felder))
                ->each(function ($z) use ($felder, $basisSchluessel, &$warnungen) {
                    $k = self::schluessel($z, $felder);
                    if ($basisSchluessel->has($k)) {
                        $warnungen[] = sprintf('Team %d hat eigene Zeile „%s“ (ID %d) — sieht sie nach dem Umzug doppelt', $z->team_id, $k, $z->id);
                    }
                });

            $out[$tabelle] = [
                'ids' => $quelle->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
                'geloescht' => $quelle->whereNotNull('deleted_at')->count(),
                'global' => $global->count(),
                'kollisionen' => $kollisionen,
                'warnungen' => $warnungen,
            ];
        }

        return $out;
    }

    private function verify(int $master): int
    {
        $fehler = [];
        foreach ($this->befund($master) as $tabelle => $b) {
            $label = self::TABELLEN[$tabelle]['label'];
            if ($b['ids'] !== []) {
                $fehler[] = "{$label}: noch " . count($b['ids']) . " Zeilen im Master-Team {$master}";
            }
            foreach ($b['kollisionen'] as $k) {
                $fehler[] = "{$label}: {$k}";
            }
            foreach ($b['warnungen'] as $w) {
                $this->warn("  {$label}: {$w}");
            }
        }

        foreach (self::EINHEITEN_VERWEISE as $tabelle => $spalte) {
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, $spalte)) {
                continue;
            }
            $verwaist = DB::table($tabelle . ' as v')->whereNotNull('v.' . $spalte)
                ->leftJoin('foodalchemist_vocab_units as u', 'u.id', '=', 'v.' . $spalte)->whereNull('u.id')->count();
            if ($verwaist > 0) {
                $fehler[] = "{$tabelle}.{$spalte}: {$verwaist} Verweise ohne Einheit";
            }
        }

        $masterTeam = Team::find($master);
        $mandant = Team::query()->where('id', '!=', $master)->orderBy('id')->first();
        if ($masterTeam !== null && $mandant !== null) {
            foreach (self::TABELLEN as $tabelle => $spec) {
                $m = $spec['modell'];
                $sichtMaster = $m::visibleToTeam($masterTeam)->count();
                $sichtMandant = $m::visibleToTeam($mandant)->whereNull($tabelle . '.team_id')->count();
                $this->line(sprintf('  %s: Master sieht %d, Mandant %d sieht %d global', $spec['label'], $sichtMaster, $mandant->id, $sichtMandant));
                if ($sichtMandant < $sichtMaster) {
                    $fehler[] = "{$spec['label']}: Mandant {$mandant->id} sieht {$sichtMandant} von {$sichtMaster}";
                }
            }
        }

        if ($fehler !== []) {
            $this->error('Prüfung NICHT bestanden:');
            foreach ($fehler as $f) {
                $this->line('  ' . $f);
            }

            return self::FAILURE;
        }
        $this->info('✓ Prüfung bestanden: keine Master-Zeilen, keine Dubletten, keine verwaisten Einheiten-Verweise, Mandant sieht alles.');

        return self::SUCCESS;
    }

    private function zurueck(string $pfad): int
    {
        $daten = is_file($pfad) ? json_decode((string) file_get_contents($pfad), true) : null;
        if (! is_array($daten) || ! is_numeric($daten['quell_team'] ?? null) || ! is_array($daten['ids'] ?? null)) {
            $this->error("Protokoll nicht lesbar: {$pfad}");

            return self::FAILURE;
        }
        $master = (int) $daten['quell_team'];

        // Abbruch, wenn das Quell-Team inzwischen wieder eine Zeile mit gleichem Schlüssel hat (sonst entstünde dort eine Dublette).
        $konflikte = [];
        foreach ($daten['ids'] as $tabelle => $ids) {
            if (! isset(self::TABELLEN[$tabelle]) || $ids === []) {
                continue;
            }
            $felder = self::TABELLEN[$tabelle]['schluessel'];
            $vorhanden = DB::table($tabelle)->where('team_id', $master)->whereNull('deleted_at')->get($felder)
                ->map(fn ($z) => self::schluessel($z, $felder))->flip();
            DB::table($tabelle)->whereIn('id', $ids)->whereNull('team_id')->whereNull('deleted_at')->get(array_merge(['id'], $felder))
                ->each(function ($z) use ($felder, $vorhanden, $tabelle, &$konflikte) {
                    if ($vorhanden->has($k = self::schluessel($z, $felder))) {
                        $konflikte[] = self::TABELLEN[$tabelle]['label'] . ": „{$k}“ (ID {$z->id}) gibt es im Quell-Team schon wieder";
                    }
                });
        }
        if ($konflikte !== []) {
            $this->error('Rückweg blockiert — nichts geschrieben:');
            foreach ($konflikte as $k) {
                $this->line('  ' . $k);
            }

            return self::FAILURE;
        }

        $n = 0;
        DB::transaction(function () use ($daten, $master, &$n) {
            foreach ($daten['ids'] as $tabelle => $ids) {
                if (! isset(self::TABELLEN[$tabelle])) {
                    continue;
                }
                foreach (array_chunk(array_map('intval', $ids), 500) as $teil) {
                    $n += DB::table($tabelle)->whereIn('id', $teil)->whereNull('team_id')->update(['team_id' => $master]);
                }
            }
        });
        $this->info("✓ {$n} Zeilen zurück an Team {$master}.");

        return self::SUCCESS;
    }

    /** Fachlicher Schlüssel, so verglichen wie die MySQL-Sortierung (Gross/klein egal, Rand-Leerzeichen egal). */
    private static function schluessel(object $zeile, array $felder): string
    {
        return implode(' / ', array_map(fn ($f) => mb_strtolower(trim((string) $zeile->{$f})), $felder));
    }
}
