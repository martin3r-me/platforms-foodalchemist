<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * EZB-Tageskurs USD für die KI-Kosten und das KI-Budget (Dominique 18.10.2026). Die EZB veröffentlicht werktags gegen 16:00 MEZ den
 * Referenzkurs „1 EUR = x USD“; gespeichert wird zusätzlich usd_eur = 1/x. Ein Fehler beim Holen blockiert NIE einen KI-Aufruf: der
 * letzte Kurs bleibt, ohne Tabelle oder Zeile gilt der env-Kurs (foodalchemist.ai.usd_eur). Ist der neueste Kurs älter als
 * VERALTET_TAGE Kalendertage (5: Ostern Karfreitag–Ostermontag ohne Fehlalarm), bekommt der Master ein Signal.
 */
class WechselkursService
{
    public const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public const VERALTET_TAGE = 5;

    private const CACHE_KEY = 'foodalchemist.wechselkurs.usd_eur';

    /**
     * EUR je 1 USD: neuester gespeicherter EZB-Kurs, sonst env. Je Stunde gecacht (läuft je KI-Aufruf).
     */
    public function usdEur(): ?float
    {
        $gespeichert = Cache::remember(self::CACHE_KEY, 3600, function () {
            try {
                $wert = DB::table('foodalchemist_wechselkurse')->where('waehrung', 'USD')->orderByDesc('datum')->value('usd_eur');

                return $wert !== null ? (float) $wert : 0.0;   // 0.0 = „nichts gespeichert“ (null cacht Laravel nicht)
            } catch (\Throwable $e) {
                Log::warning('Wechselkurs lesen fehlgeschlagen, env-Kurs gilt — '.$e->getMessage());

                return 0.0;
            }
        });
        if ($gespeichert > 0) {
            return $gespeichert;
        }
        $env = config('foodalchemist.ai.usd_eur');

        return is_numeric($env) && (float) $env > 0 ? (float) $env : null;
    }

    /** @return array{datum:string, ezb_kurs:float, usd_eur:float}|null neuester gespeicherter Kurs */
    public function neuester(): ?array
    {
        try {
            $z = DB::table('foodalchemist_wechselkurse')->where('waehrung', 'USD')->orderByDesc('datum')->first();
        } catch (\Throwable $e) {
            unset($e);

            return null;
        }

        return $z === null ? null : ['datum' => substr((string) $z->datum, 0, 10), 'ezb_kurs' => (float) $z->ezb_kurs, 'usd_eur' => (float) $z->usd_eur];
    }

    /**
     * Tageskurs holen und speichern (idempotent je EZB-Datum). Wirft nie.
     *
     * @return array{ok:bool, datum:?string, ezb_kurs:?float, usd_eur:?float, neu:bool, fehler:?string}
     */
    public function holen(bool $schreiben = true): array
    {
        $raus = ['ok' => false, 'datum' => null, 'ezb_kurs' => null, 'usd_eur' => null, 'neu' => false, 'fehler' => null];
        try {
            $antwort = Http::timeout(10)->retry(2, 500, throw: false)->get(self::URL);
            if (! $antwort->successful()) {
                throw new \RuntimeException('EZB antwortet mit HTTP '.$antwort->status());
            }
            [$datum, $kurs] = self::parse($antwort->body());
            $raus = ['ok' => true, 'datum' => $datum, 'ezb_kurs' => $kurs, 'usd_eur' => round(1 / $kurs, 8), 'neu' => false, 'fehler' => null];
            if ($schreiben) {
                $raus['neu'] = ! DB::table('foodalchemist_wechselkurse')->where('datum', $datum)->where('waehrung', 'USD')->exists();
                DB::table('foodalchemist_wechselkurse')->updateOrInsert(['datum' => $datum, 'waehrung' => 'USD'],
                    ['ezb_kurs' => $kurs, 'usd_eur' => $raus['usd_eur'], 'quelle' => 'ezb', 'updated_at' => now(), 'created_at' => now()]);
                Cache::forget(self::CACHE_KEY);
            }
        } catch (\Throwable $e) {
            $raus['fehler'] = $e->getMessage();
            Log::warning('EZB-Wechselkurs nicht geholt, der letzte Kurs bleibt — '.$e->getMessage());
        }
        if ($schreiben) {
            $this->signalPruefen();
        }

        return $raus;
    }

    /**
     * EZB-XML lesen: <Cube time="YYYY-MM-DD"><Cube currency="USD" rate="1.1253"/>…
     *
     * @return array{0:string, 1:float} [Datum, USD je EUR]
     */
    public static function parse(string $xml): array
    {
        $alt = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($doc === false) {
                throw new \RuntimeException('EZB-Antwort ist kein gültiges XML.');
            }
            $doc->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
            $tag = $doc->xpath('//e:Cube[@time]')[0] ?? null;
            $usd = $doc->xpath('//e:Cube[@time]/e:Cube[@currency="USD"]')[0] ?? null;
            $datum = $tag !== null ? (string) $tag['time'] : '';
            $kurs = $usd !== null ? (float) (string) $usd['rate'] : 0.0;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $kurs <= 0) {
                throw new \RuntimeException('EZB-Antwort ohne USD-Kurs oder Datum.');
            }

            return [$datum, $kurs];
        } finally {
            libxml_use_internal_errors($alt);
        }
    }

    /** Neuester Kurs älter als VERALTET_TAGE (oder keiner) → Signal an den Master; wieder frisch → Signal gemessen schließen. */
    public function signalPruefen(): void
    {
        $master = MasterTeam::id() !== null ? Team::find(MasterTeam::id()) : null;
        if ($master === null) {
            return;
        }
        $neuester = $this->neuester();
        $alter = $neuester === null ? null : (int) \Illuminate\Support\Carbon::parse($neuester['datum'])->startOfDay()->diffInDays(now()->startOfDay());
        $svc = app(SignalService::class);
        if ($neuester !== null && $alter <= self::VERALTET_TAGE) {
            $svc->schliesseGemessen($master, SignalTyp::WechselkursVeraltet, 'wechselkurs_veraltet', 'wechselkurs', 'EZB-Kurs wieder aktuell');

            return;
        }
        $svc->erzeuge($master, SignalTyp::WechselkursVeraltet, SignalSeverity::Warnung,
            $neuester === null ? 'Kein EZB-Wechselkurs gespeichert — KI-Kosten rechnen mit dem env-Kurs'
                : sprintf('EZB-Wechselkurs seit %d Tagen nicht aktualisiert (Stand %s)', $alter, $neuester['datum']), [
                'dedup_key' => 'wechselkurs_veraltet', 'source' => 'wechselkurs',
                'description' => 'Der tägliche Abruf (foodalchemist:wechselkurs-holen, 16:30) liefert keinen neuen Kurs. KI-Aufrufe laufen weiter mit dem letzten Kurs.',
                'payload' => ['stand' => $neuester['datum'] ?? null, 'alter_tage' => $alter, 'url' => self::URL],
            ]);
    }
}
