<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\TrendEingangVertiefenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Support\SichererAbrufFehler;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Vertiefung im Trend-Eingang: holt die verlinkte Seite bzw. das PDF (SSRF-Schutz), lässt per KI höchstens 6 Kernaussagen
 * mit Zahl, Bezug und Fundstelle extrahieren und behält nur Aussagen, deren Zahlen im abgerufenen Text stehen (Zahlen-Riegel).
 * Gespeichert werden nur Kernaussagen in eigenen Worten, nie der Volltext.
 *
 * Zugang: Bezahlschranke/Login (401/402, Login-Formular mit wenig Text) → „bezahl“ ohne KI-Aufruf; 403/429 → „gesperrt“
 * (Bot-Sperre, nie umgehen); unlesbar/zu wenig Text → „nicht_lesbar“. Erkennt die KI eine bloße Katalog-/Inhaltsangabe eines
 * kostenpflichtigen Reports, wird es ebenfalls „bezahl“ — mit dem, was die öffentliche Seite belegt.
 *
 * Auslöser: Knopf „Vertiefen“ (Kuratieren) bzw. MCP `trend_eingang.VERTIEFEN`, und täglich automatisch höchstens
 * `trends.vertiefung.auto_je_tag` (10) je Team für Marktbewegungen und harte Quellen (Befehl foodalchemist:trend-eingang-vertiefen).
 * KI dual (Spec 90): Job-Weg, `extern` aus dem MCP-Aufruf ({@see KiExternModus::fuerDispatch}).
 */
class TrendVertiefungService
{
    public const STATUS = [
        'laeuft' => 'Vertiefung läuft',
        'fertig' => 'Vertieft',
        'bezahl' => 'Bezahlinhalt – nur Inhaltsangabe',
        'gesperrt' => 'Seite sperrt den Abruf',
        'nicht_lesbar' => 'Seite nicht lesbar',
        'fehler' => 'Vertiefung fehlgeschlagen',
    ];

    public const MAX_AUSSAGEN = 6;

    /** So viel Text geht höchstens in den Prompt (Zeichen). */
    public const PROMPT_TEXT_MAX = 14000;

    /** Unter so viel Haupttext gilt eine Seite als nicht lesbar (oder als Login-Wand, wenn ein Passwortfeld da ist). */
    public const MIN_TEXT = 300;

    public function __construct(private readonly FaRechte $rechte, private readonly SichererAbruf $abruf) {}

    /**
     * Knopf / MCP: die genannten Einträge des eigenen Teams vertiefen (höchstens 20 je Aufruf). Bereits vertiefte nur mit $neu.
     *
     * @param  list<int>  $ids
     * @return list<int> angestoßene IDs
     */
    public function anstossen(Team $team, array $ids, ?int $userId = null, bool $neu = false): array
    {
        if ($userId !== null) {
            $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Trend-Eingang vertiefen');
        } else {
            $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Trend-Eingang vertiefen');
        }
        $q = FoodAlchemistTrendEingang::where('team_id', $team->id)->whereKey(array_slice(array_map('intval', $ids), 0, 20))
            // Ein laufender Auftrag wird nicht doppelt gestartet; hängt er länger als eine Stunde, darf er neu.
            ->where(fn ($w) => $w->whereNull('vertiefung_status')->orWhere('vertiefung_status', '!=', 'laeuft')
                ->orWhere('vertieft_at', '<', now()->subHour()));
        if (! $neu) {
            $q->where(fn ($w) => $w->whereNull('vertiefung_status')->orWhereIn('vertiefung_status', ['fehler', 'nicht_lesbar', 'laeuft']));
        }
        $zeilen = $q->orderBy('id')->get();
        foreach ($zeilen as $e) {
            $this->dispatchen($e, 'hand');
        }

        return $zeilen->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Täglicher Lauf (Befehl, ohne Benutzer): Marktbewegungen und Einträge aus harten Quellen ohne Vertiefung, neueste zuerst,
     * höchstens `auto_je_tag` je Team und Tag (gezählt über vertieft_at heute mit Auslöser „auto“).
     *
     * @return list<int>
     */
    public function autoLauf(Team $team): array
    {
        $max = (int) config('foodalchemist.trends.vertiefung.auto_je_tag', 10);
        $heute = FoodAlchemistTrendEingang::where('team_id', $team->id)->whereDate('vertieft_at', now()->toDateString())->get(['id', 'vertiefung'])
            ->filter(fn ($e) => ($e->vertiefung['ausloeser'] ?? null) === 'auto')->count();
        $frei = max(0, $max - $heute);
        if ($frei === 0) {
            return [];
        }
        $kandidaten = FoodAlchemistTrendEingang::where('team_id', $team->id)->whereNull('vertiefung_status')
            ->where('created_at', '>=', now()->subDays((int) config('foodalchemist.trends.vertiefung.auto_tage', 30)))
            ->with('quelle:id,art')->orderByDesc('id')->limit(300)->get()
            ->filter(fn ($e) => self::autoGeeignet($e))->take($frei);
        foreach ($kandidaten as $e) {
            $this->dispatchen($e, 'auto');
        }

        return $kandidaten->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Marktbewegung (Observatorium) oder harte Quelle (Marktforschung, Verbraucherstudie …) mit echter Ziel-URL. */
    public static function autoGeeignet(FoodAlchemistTrendEingang $e): bool
    {
        if (! preg_match('~^https?://~i', (string) $e->url) || str_contains((string) $e->url, 'trends.google.com')) {
            return false;
        }
        $verwendungen = (array) ($e->observatorium['verwendungen'] ?? []);

        return in_array('markt', $verwendungen, true) || in_array((string) $e->quelle?->art, V::HARTE_QUELLEN, true);
    }

    private function dispatchen(FoodAlchemistTrendEingang $e, string $ausloeser): void
    {
        $e->update(['vertiefung_status' => 'laeuft', 'vertieft_at' => now(),
            'vertiefung' => ['ausloeser' => $ausloeser] + array_intersect_key((array) $e->vertiefung, ['kernaussagen' => 1, 'methodik' => 1, 'zugang' => 1])]);
        $extern = app(KiExternModus::class)->fuerDispatch();
        $id = (int) $e->id;
        $team = (int) $e->team_id;
        DB::afterCommit(fn () => TrendEingangVertiefenJob::dispatch($id, $team, $extern, 0, $ausloeser));
    }

    /**
     * Ziel-URL lesen. Ergebnis: zugang frei|bezahl|gesperrt|nicht_lesbar, art html|pdf, text (Haupttext, gekürzt), grund.
     *
     * @return array{zugang:string, art:?string, text:string, grund:?string}
     */
    public function quelleLesen(string $url): array
    {
        try {
            $antwort = $this->abruf->laden(\Platform\FoodAlchemist\Support\TrendUrl::ohneSignal($url),
                (int) config('foodalchemist.trends.vertiefung.max_bytes', 8 * 1024 * 1024), 25);
        } catch (SichererAbrufFehler $e) {
            return ['zugang' => 'nicht_lesbar', 'art' => null, 'text' => '', 'grund' => $e->getMessage()];
        } catch (\Throwable) {
            return ['zugang' => 'nicht_lesbar', 'art' => null, 'text' => '', 'grund' => 'Seite nicht erreichbar.'];
        }
        $status = $antwort->status();
        if (in_array($status, [401, 402], true)) {
            return ['zugang' => 'bezahl', 'art' => null, 'text' => '', 'grund' => 'Zugang nur mit Anmeldung oder Bezahlung (HTTP '.$status.').'];
        }
        if (in_array($status, [403, 429], true)) {
            return ['zugang' => 'gesperrt', 'art' => null, 'text' => '', 'grund' => 'Die Seite sperrt automatische Abrufe (HTTP '.$status.').'];
        }
        if ($status >= 400) {
            return ['zugang' => 'nicht_lesbar', 'art' => null, 'text' => '', 'grund' => 'Seite antwortet mit HTTP '.$status.'.'];
        }
        $body = $antwort->body();
        $istPdf = str_starts_with($body, '%PDF') || str_contains(strtolower((string) $antwort->header('Content-Type')), 'application/pdf');
        if ($istPdf) {
            try {
                $text = self::normalisieren((new \Smalot\PdfParser\Parser)->parseContent($body)->getText());
            } catch (\Throwable) {
                return ['zugang' => 'nicht_lesbar', 'art' => 'pdf', 'text' => '', 'grund' => 'PDF nicht lesbar.'];
            }
            $login = false;
        } else {
            [$text, $login] = self::haupttext($body);
        }
        if (mb_strlen($text) < self::MIN_TEXT) {
            return $login
                ? ['zugang' => 'bezahl', 'art' => $istPdf ? 'pdf' : 'html', 'text' => $text, 'grund' => 'Inhalt hinter einer Anmeldung.']
                : ['zugang' => 'nicht_lesbar', 'art' => $istPdf ? 'pdf' : 'html', 'text' => $text, 'grund' => 'Zu wenig lesbarer Text.'];
        }

        return ['zugang' => 'frei', 'art' => $istPdf ? 'pdf' : 'html', 'text' => mb_substr($text, 0, 60000), 'grund' => null];
    }

    /**
     * Haupttext einer HTML-Seite: ohne Skripte, Navigation, Kopf/Fuß, Formulare; <main>/<article> bevorzugt; Absätze bleiben Zeilen.
     *
     * @return array{0: string, 1: bool} Text, Login-Formular erkannt
     */
    public static function haupttext(string $html): array
    {
        $dom = new \DOMDocument;
        $alt = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        $xp = new \DOMXPath($dom);
        $login = $xp->query('//input[@type="password"]')->length > 0;
        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'aside', 'form', 'svg', 'iframe', 'template', 'header'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $k) {
                $k->parentNode?->removeChild($k);
            }
        }
        $wurzel = null;
        $laenge = 0;
        foreach ($xp->query('//main | //article | //*[@role="main"]') as $kandidat) {
            $l = mb_strlen(trim($kandidat->textContent));
            if ($l > $laenge) {
                [$wurzel, $laenge] = [$kandidat, $l];
            }
        }
        $wurzel ??= $dom->getElementsByTagName('body')->item(0);
        if ($wurzel === null) {
            return ['', $login];
        }
        $zeilen = [];
        foreach ($xp->query('.//h1 | .//h2 | .//h3 | .//h4 | .//p | .//li | .//td | .//th | .//figcaption', $wurzel) as $block) {
            // verschachtelte Blöcke (p in li) nicht doppelt
            if ($xp->query('ancestor::p | ancestor::li | ancestor::td | ancestor::th', $block)->length > 0) {
                continue;
            }
            $t = trim(preg_replace('/\s+/u', ' ', $block->textContent) ?? '');
            if ($t !== '') {
                $zeilen[] = $t;
            }
        }
        $text = $zeilen === [] ? trim(preg_replace('/\s+/u', ' ', $wurzel->textContent) ?? '') : implode("\n", $zeilen);

        return [self::normalisieren($text), $login];
    }

    private static function normalisieren(string $s): string
    {
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\n\s*\n+/u', "\n", $s) ?? $s);
    }

    /** Alle Zahlen eines Textes, vereinheitlicht (1.234,5 → 1234,5; 70 % → 70). Jahreszahlen zählen mit. */
    public static function zahlen(string $s): array
    {
        preg_match_all('/\d+(?:[.,\x{202F}\x{00A0} ]\d{3})*(?:[.,]\d+)?/u', $s, $m);

        return array_values(array_unique(array_map([self::class, 'zahlNorm'], $m[0])));
    }

    private static function zahlNorm(string $z): string
    {
        $z = preg_replace('/[\x{202F}\x{00A0} ]/u', '', $z) ?? $z;
        // Tausenderpunkt (1.234) entfernen, Dezimal immer mit Komma
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $z) && ! preg_match('/^\d{1,3},\d{3}$/', $z)) {
            return str_replace(['.', ','], '', $z);
        }

        return str_replace('.', ',', $z);
    }

    /** Zahlen-Riegel: jede Zahl der Aussage muss im Quelltext vorkommen. */
    public static function zahlenBelegt(string $aussage, string $quelltext): bool
    {
        $belegt = self::zahlen($quelltext);
        foreach (self::zahlen($aussage) as $z) {
            if (! in_array($z, $belegt, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * KI-Antwort übernehmen. werte = {zugang: frei|nur_inhaltsangabe, kernaussagen: [{aussage, zahl, bezug, fundstelle}], methodik: {…}, hinweis}.
     * Aussagen ohne belegte Zahlen fliegen raus (gezählt in `verworfen`).
     */
    public function uebernehmen(FoodAlchemistTrendEingang $e, array $w, string $quelltext, ?string $art): FoodAlchemistTrendEingang
    {
        $kurz = fn ($v, int $max) => ($t = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')) ?? '')) === '' ? null : mb_substr($t, 0, $max);
        $aussagen = [];
        $verworfen = 0;
        foreach (array_slice(array_filter((array) ($w['kernaussagen'] ?? []), 'is_array'), 0, self::MAX_AUSSAGEN + 4) as $k) {
            $a = ['aussage' => $kurz($k['aussage'] ?? null, 240), 'zahl' => $kurz($k['zahl'] ?? null, 40),
                'bezug' => $kurz($k['bezug'] ?? null, 160), 'fundstelle' => $kurz($k['fundstelle'] ?? null, 120)];
            if ($a['aussage'] === null) {
                continue;
            }
            if (! self::zahlenBelegt(implode(' ', array_filter([$a['aussage'], $a['zahl'], $a['bezug']])), $quelltext)) {
                $verworfen++;

                continue;
            }
            $aussagen[] = $a;
        }
        $aussagen = array_slice($aussagen, 0, self::MAX_AUSSAGEN);
        $methodik = [];
        foreach (['stichprobe', 'zeitraum', 'auftraggeber', 'methode'] as $feld) {
            $v = $kurz($w['methodik'][$feld] ?? null, 160);
            if ($v !== null && self::zahlenBelegt($v, $quelltext)) {
                $methodik[$feld] = $v;
            }
        }
        $nurInhalt = ($w['zugang'] ?? 'frei') === 'nur_inhaltsangabe';
        $status = $nurInhalt ? 'bezahl' : ($aussagen === [] ? 'fehler' : 'fertig');
        $e->update(['vertiefung_status' => $status, 'vertieft_at' => now(), 'vertiefung' => [
            'ausloeser' => $e->vertiefung['ausloeser'] ?? 'hand',
            'zugang' => $nurInhalt ? 'bezahl' : 'frei', 'art' => $art,
            'kernaussagen' => $aussagen, 'methodik' => $methodik ?: null, 'verworfen' => $verworfen,
            'hinweis' => $kurz($w['hinweis'] ?? null, 300)
                ?? ($status === 'fehler' ? 'Keine Kernaussage mit belegten Zahlen gefunden.' : null),
        ]]);

        return $e->refresh();
    }

    public function fehlgeschlagen(FoodAlchemistTrendEingang $e, string $status, string $grund): FoodAlchemistTrendEingang
    {
        $status = isset(self::STATUS[$status]) && $status !== 'laeuft' ? $status : 'fehler';
        $e->update(['vertiefung_status' => $status, 'vertieft_at' => now(), 'vertiefung' => [
            'ausloeser' => $e->vertiefung['ausloeser'] ?? 'hand', 'zugang' => in_array($status, ['bezahl', 'gesperrt', 'nicht_lesbar'], true) ? $status : null,
            'kernaussagen' => [], 'hinweis' => mb_substr($grund, 0, 300),
        ]]);

        return $e->refresh();
    }
}
