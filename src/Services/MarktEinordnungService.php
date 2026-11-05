<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\MarktEinordnungJob;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdatenEinordnung as Einordnung;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;

/**
 * KI-Einordnung „Was heißt das für Caterer und Gastronomie“ nach einem Marktdaten-Abruf mit apply. Ein propose()
 * (`markt.einordnung`) im {@see MarktEinordnungJob}; Kontext sind NUR die abgerufenen Kennzahlen und die Regelsätze.
 *
 * Riegel: (1) jede Zahl eines Satzes muss im Kontext stehen ({@see TrendVertiefungService::zahlenBelegt}); (2) keine Prognose
 * oder Empfehlung ({@see VERBOTEN} — bewusst eng: das Passiv-„wird“ der Regelsätze bleibt erlaubt). Fällt die KI aus oder
 * bleibt kein Satz, zeigt die Ansicht die Regelsätze. Je Team und Werte-Stand höchstens eine Einordnung (werte_hash).
 */
class MarktEinordnungService
{
    /** Prognose- und Empfehlungsmuster (Kurator 05.11.); „es wird mehr umgesetzt“ bleibt erlaubt. */
    public const VERBOTEN = [
        '/\b(wird|werden|würde|würden)\b[^.]{0,40}\b(steigen|sinken|fallen|zulegen|nachgeben|anziehen|zurückgehen)\b/iu',
        '/\bvoraussichtlich\b/iu',
        '/\b(in zukunft|künftig|zukünftig|demnächst|in den kommenden)\b/iu',
        '/\bsollte(n|st)?\b/iu',
        '/\b(empfehlen|empfiehlt|empfohlen|empfehlenswert|ratsam)\b/iu',
        '/\b(muss|müssen|musst)\b/iu',
        '/\blohnt\s+sich\b/iu',
    ];

    public const MAX_SAETZE = 5;

    /** Nur diese Reihen gehen in den Kontext (lesbar, keine Ladenpreis-Details). */
    public const KONTEXT_REIHEN = ['grosshandel_nahrungsmittel', 'gaststaettenpreise', 'kantinenpreise', 'erzeugerpreise_nahrungsmittel',
        'gastro_umsatz_nominal', 'gastro_umsatz_real', 'caterer_umsatz_nominal', 'caterer_umsatz_real', 'eventcaterer_umsatz_real'];

    /** Kontext für die KI aus MarktdatenService::lesen. */
    public static function kontext(array $marktdaten): array
    {
        $alle = [];
        foreach ($marktdaten['alle_reihen'] ?? [] as $r) {
            $alle[$r['serie']] = $r;
        }
        $kennzahlen = [];
        foreach (array_merge(self::KONTEXT_REIHEN, array_keys(array_filter($alle, fn ($r) => ($r['gruppe'] ?? null) === 'grosshandel_nahrungsmittel' && ($r['abschnitt'] ?? null) === null))) as $serie) {
            $r = $alle[$serie] ?? null;
            if ($r === null || $r['vorjahr_prozent'] === null) {
                continue;
            }
            $kennzahlen[$serie] = ['name' => $r['lang'] ?? $r['name'], 'monat' => $r['aktuell']['monat'],
                'vorjahr_prozent' => number_format((float) $r['vorjahr_prozent'], 1, ',', ''), 'quelle' => 'Destatis '.$r['tabelle']];
        }

        return ['kennzahlen' => $kennzahlen, 'regelsaetze' => array_column($marktdaten['aussagen'] ?? [], 'text')];
    }

    public static function hash(array $kontext): string
    {
        return hash('sha256', json_encode($kontext, JSON_UNESCAPED_UNICODE));
    }

    public static function standMonat(array $kontext): ?string
    {
        $monate = array_column($kontext['kennzahlen'], 'monat');

        return $monate === [] ? null : max($monate);
    }

    /** Nach dem Abruf (apply): eine Einordnung anstoßen, wenn es Kennzahlen gibt und dieser Stand noch keine hat. */
    public function nachAbruf(Team $team, array $marktdaten): ?int
    {
        $kontext = self::kontext($marktdaten);
        if (count($kontext['kennzahlen']) < 2) {
            return null;
        }
        $hash = self::hash($kontext);
        $e = Einordnung::where('team_id', $team->id)->where('werte_hash', $hash)->first();
        if ($e !== null && $e->status !== 'fehler') {
            return null;   // dieselben Zahlen: kein zweiter KI-Aufruf
        }
        $e ??= new Einordnung(['team_id' => $team->id, 'werte_hash' => $hash]);
        $e->fill(['stand_monat' => self::standMonat($kontext), 'status' => 'laeuft', 'saetze' => null, 'verworfen' => 0, 'hinweis' => null])->save();
        $extern = app(KiExternModus::class)->fuerDispatch();
        $id = (int) $e->id;
        $teamId = (int) $team->id;
        DB::afterCommit(fn () => MarktEinordnungJob::dispatch($id, $teamId, $extern));

        return $id;
    }

    /** Ein Satz besteht, wenn seine Zahlen im Kontext stehen und er weder prognostiziert noch empfiehlt. */
    public static function satzErlaubt(string $satz, array $kontext): bool
    {
        foreach (self::VERBOTEN as $muster) {
            if (preg_match($muster, $satz)) {
                return false;
            }
        }

        return TrendVertiefungService::zahlenBelegt($satz, json_encode($kontext, JSON_UNESCAPED_UNICODE));
    }

    public function uebernehmen(Einordnung $e, array $w, array $kontext): Einordnung
    {
        $saetze = [];
        $verworfen = 0;
        foreach (array_slice(array_filter((array) ($w['saetze'] ?? []), 'is_string'), 0, self::MAX_SAETZE + 3) as $s) {
            $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
            if ($s === '') {
                continue;
            }
            if (mb_strlen($s) > 300 || ! self::satzErlaubt($s, $kontext)) {
                $verworfen++;

                continue;
            }
            $saetze[] = $s;
        }
        $saetze = array_slice($saetze, 0, self::MAX_SAETZE);
        $e->update(['status' => $saetze === [] ? 'fehler' : 'fertig', 'saetze' => $saetze ?: null, 'verworfen' => $verworfen,
            'hinweis' => $saetze === [] ? 'Kein Satz bestand die Prüfung (Zahlen belegt, keine Prognose/Empfehlung).' : null]);

        return $e->refresh();
    }

    public function fehlgeschlagen(Einordnung $e, string $grund): Einordnung
    {
        $e->update(['status' => 'fehler', 'hinweis' => mb_substr($grund, 0, 300)]);

        return $e->refresh();
    }

    /** Neueste fertige Einordnung, eigenes Team vor geerbtem (wie die Marktdaten). */
    public static function aktuelle(Team $team): ?Einordnung
    {
        $alle = Einordnung::visibleToTeam($team)->where('status', 'fertig')->orderByDesc('updated_at')->get();

        return $alle->first(fn ($e) => (int) $e->team_id === (int) $team->id) ?? $alle->first();
    }
}
