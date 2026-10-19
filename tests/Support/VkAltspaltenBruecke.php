<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;

/**
 * Spec 85 D-1 PR-C · Fixture-Brücke (NUR Test-Harness): die VK-Altspalten am Rezept sind gedroppt, viele Fixtures legen
 * Rezepte aber noch mit `sales_net`/`markup_class_id`/… an. Die Brücke übersetzt sie dorthin, wo die Wahrheit jetzt
 * liegt — eine Standard-Darreichung mit diesem VK (fixiert) und dieser Klasse.
 *
 * Harte Bedingung (Kurator 19.10.): übersetzt wird NUR, wenn das Anlegen/Ändern aus `tests/` kommt. Kommt es aus
 * `src/`, wirft die Brücke — sonst wäre Produktionscode, der noch eine Altspalte schreibt, im Test grün und auf io ein
 * SQL-Fehler. Sperrklinke: nur die Bestandsdateien aus VkAltspaltenBrueckeBasis dürfen sie nutzen, die Liste schrumpft nur
 * (Spec85VkAltspaltenBrueckeTest).
 *
 * Legt ein Fixture danach selbst eine Standard-Darreichung an, macht die Brücken-Darreichung Platz (sonst zwei
 * Standard-Formen bzw. Servierform-Dublette).
 */
final class VkAltspaltenBruecke
{
    public const SPALTEN = ['sales_net', 'sales_gross', 'vat_rate', 'markup_class_id', 'markup_class_legacy_id'];

    public const HERKUNFT = 'test_bruecke';

    /** Servierform der Brücken-Darreichungen (je Team) */
    public const SERVIERFORM = 'test_bruecke';

    /** @var array<int, array<string, mixed>> spl_object_id(Rezept) → abgefangene Altwerte */
    private static array $wartend = [];

    /** @var list<string> Meldungen, wenn der Auto-Preis einer Brücken-Darreichung nicht gerechnet werden konnte (Diagnose) */
    public static array $rechenFehler = [];

    /** @var array<string, int> Testdatei → Übersetzungen in diesem Prozess (Zähler; FA_BRUECKE_LOG=<pfad> schreibt jede mit) */
    public static array $treffer = [];

    /** @var list<string>|null */
    private static ?array $erlaubt = null;

    /** @return list<string> Testdateien, die die Brücke noch nutzen dürfen (Sperrklinke) */
    public static function erlaubteDateien(): array
    {
        return self::$erlaubt ??= require __DIR__ . '/VkAltspaltenBrueckeBasis.php';
    }

    public static function registrieren(): void
    {
        self::$wartend = [];
        self::$treffer = [];
        self::$rechenFehler = [];

        // `$guarded = ['id']` lässt Eloquent beim fill() Schlüssel verwerfen, die keine Tabellenspalte sind — nach dem Drop
        // also genau die Altspalten, bevor `creating` sie sieht (Fixture-VK wäre still weg). Die Spaltenliste des Modells
        // (Model::$guardableColumns) bekommt deshalb nach jeder Migration die Altspalten dazu; nur im Test-Harness.
        self::spaltenlisteErgaenzen();
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsEnded::class, fn () => self::spaltenlisteErgaenzen());
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\NoPendingMigrations::class, fn () => self::spaltenlisteErgaenzen());

        FoodAlchemistRecipe::creating(function (FoodAlchemistRecipe $r) {
            $alt = self::abfangen($r);
            if ($alt !== []) {
                self::$wartend[spl_object_id($r)] = $alt;
            }
        });
        FoodAlchemistRecipe::created(function (FoodAlchemistRecipe $r) {
            $alt = self::$wartend[spl_object_id($r)] ?? [];
            unset(self::$wartend[spl_object_id($r)]);
            if ($alt !== []) {
                self::standardDarreichung($r, $alt);
            }
        });
        FoodAlchemistRecipe::updating(function (FoodAlchemistRecipe $r) {
            $alt = self::abfangen($r);
            if ($alt !== []) {
                self::standardDarreichung($r, $alt);
            }
        });
        FoodAlchemistRecipeDarreichung::creating(function (FoodAlchemistRecipeDarreichung $d) {
            if ($d->created_via === self::HERKUNFT || ! $d->recipe_id) {
                return;
            }
            // Ein Fixture legt selbst eine Form an: Brücken-Darreichung mit derselben Servierform bzw. als Standard weicht.
            FoodAlchemistRecipeDarreichung::withoutGlobalScopes()->withTrashed()
                ->where('recipe_id', $d->recipe_id)->where('created_via', self::HERKUNFT)
                ->where(fn ($q) => $q->where('serving_form_id', $d->serving_form_id)->when($d->is_standard, fn ($w) => $w->orWhere('is_standard', true)))
                ->get()->each->forceDelete();
        });
    }

    private static function spaltenlisteErgaenzen(): void
    {
        $tabelle = (new FoodAlchemistRecipe)->getTable();
        $spalten = \Illuminate\Support\Facades\Schema::hasTable($tabelle) ? \Illuminate\Support\Facades\Schema::getColumnListing($tabelle) : [];
        \Closure::bind(function () use ($spalten) {
            if ($spalten === []) {
                unset(static::$guardableColumns[FoodAlchemistRecipe::class]);   // noch keine Tabelle: Eloquent liest später selbst

                return;
            }
            static::$guardableColumns[FoodAlchemistRecipe::class] = array_values(array_unique([...$spalten, ...VkAltspaltenBruecke::SPALTEN]));
        }, null, \Illuminate\Database\Eloquent\Model::class)();
    }

    /** @return array<string, mixed> die Altwerte, aus dem Modell entfernt */
    private static function abfangen(FoodAlchemistRecipe $r): array
    {
        $attribute = $r->getAttributes();
        $alt = array_intersect_key($attribute, array_flip(self::SPALTEN));
        if ($alt === []) {
            return [];
        }
        [$aufrufer, $testdatei] = self::aufrufer();
        if ($aufrufer !== null && str_starts_with($aufrufer, 'src/')) {
            throw new \LogicException('Altspalte aus Produktionscode: ' . $aufrufer . ' (' . implode(', ', array_keys($alt))
                . ') — VK/Klasse gehören an die Darreichung (Spec 85 D-1).');
        }
        if ($testdatei === null || ! in_array($testdatei, self::erlaubteDateien(), true)) {
            throw new \LogicException('Altspalte in neuer Testdatei: ' . ($aufrufer ?? '?') . ' (' . implode(', ', array_keys($alt))
                . ') — die Fixture-Brücke gilt nur für den Bestand (VkAltspaltenBrueckeBasis); VK/Klasse an der Darreichung anlegen.');
        }
        self::$treffer[$testdatei] = (self::$treffer[$testdatei] ?? 0) + 1;
        if (($log = getenv('FA_BRUECKE_LOG')) !== false && $log !== '') {
            @file_put_contents($log, $aufrufer . "\n", FILE_APPEND | LOCK_EX);
        }
        $roh = $attribute;
        foreach (array_keys($alt) as $spalte) {
            unset($roh[$spalte]);
        }
        $r->setRawAttributes($roh, false);

        return $alt;
    }

    private static function standardDarreichung(FoodAlchemistRecipe $r, array $alt): void
    {
        $standard = FoodAlchemistRecipeDarreichung::withoutGlobalScopes()->where('recipe_id', $r->id)->where('is_standard', true)->first();
        $werte = array_filter([
            'sales_net' => $alt['sales_net'] ?? null,
            'sales_gross' => $alt['sales_gross'] ?? null,
            'markup_class_id' => $alt['markup_class_id'] ?? null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('sales_net', $werte)) {
            $werte['price_mode'] = 'fixed';   // der Fixture-Wert bleibt — wie vorher die Rezept-Spalte
        }
        if ($werte === []) {
            return;
        }
        if ($standard !== null) {
            $standard->forceFill($werte)->saveQuietly();

            return;
        }
        // Eigene Servierform je Team statt „unbestimmt“: die legen Fixtures und DarreichungService::unbestimmtId selbst an (UNIQUE team_id+code),
        // und „unbestimmt“ ist fachlich die Prüfliste — die Brücke soll dort nichts verfälschen.
        $sf = FoodAlchemistServierform::withoutGlobalScopes()->withTrashed()->where('team_id', $r->team_id)->where('code', self::SERVIERFORM)->first();
        if ($sf?->trashed()) {
            $sf->restore();
        }
        $sf ??= FoodAlchemistServierform::withoutGlobalScopes()->create(['team_id' => $r->team_id, 'code' => self::SERVIERFORM, 'label' => 'Test-Brücke']);
        $neu = FoodAlchemistRecipeDarreichung::withoutEvents(fn () => FoodAlchemistRecipeDarreichung::create([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),   // withoutEvents: die uuid-Vergabe des Modells läuft nicht
            'team_id' => $r->team_id, 'recipe_id' => $r->id, 'serving_form_id' => $sf->id, 'is_standard' => true,
            'created_via' => self::HERKUNFT, 'unit_count' => 1, ...$werte,
        ]));
        if (! array_key_exists('sales_net', $werte)) {
            // Grammatur nur für den Auto-Preis vorbelegen (wie ensureStandard: Portion bzw. Ertrag ÷ Einheiten). Bei festem Fixture-VK bleibt
            // sie leer — die Brücken-Darreichung vertritt dann nur den Preis und verschiebt keine Mengenrechnung (Auftragssimulation).
            $neu->forceFill(['quantity_per_unit_g' => $r->sales_quantity_per_unit_g
                ?? ($r->yield_kg !== null && (float) $r->yield_kg > 0 ? round((float) $r->yield_kg * 1000 / max(1, (int) ($r->sales_unit_count ?? 0)), 1) : null)])->saveQuietly();
            // Nur Klasse, kein VK: wie früher ensureStandard den Auto-Preis gleich mitrechnen (sonst stünde die Darreichung ohne VK da).
            // Ein Fehler hier bleibt im Fixture sichtbar (VK fehlt), reißt aber keine fremden Tests.
            try {
                app(\Platform\FoodAlchemist\Services\DarreichungService::class)->recomputePreise($neu, $r);
            } catch (\Throwable $e) {
                self::$rechenFehler[] = $e->getMessage();
            }
        }
    }

    /**
     * [erster Modul-Frame (src/ oder tests/) außerhalb dieser Brücke als „datei:zeile", erste Testdatei im Stapel
     * (ohne tests/Support/ und TestCase)] — Pfade relativ zum Modul.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function aufrufer(): array
    {
        $modul = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $erster = $testdatei = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $f) {
            $datei = $f['file'] ?? '';
            if ($datei === '' || $datei === __FILE__ || ! str_starts_with($datei, $modul)) {
                continue;
            }
            $rel = substr($datei, strlen($modul));
            if (! str_starts_with($rel, 'src/') && ! str_starts_with($rel, 'tests/')) {
                continue;
            }
            $erster ??= $rel . ':' . ($f['line'] ?? 0);
            if (str_starts_with($rel, 'tests/') && ! str_starts_with($rel, 'tests/Support/') && $rel !== 'tests/TestCase.php') {
                $testdatei = $rel;
                break;
            }
        }

        return [$erster, $testdatei];
    }
}
