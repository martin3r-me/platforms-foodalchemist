<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumLagebild as Lagebild;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang as Artikel;
use Platform\FoodAlchemist\Support\ObservatoriumSicht;

/**
 * Observatorium zentral · wöchentliches Lagebild (Dominique 07.11.2026). Der Master (BHG.DIGITAL) schreibt es per MCP,
 * alle Mandanten lesen es im Observatorium. Ruft selbst keine KI — der Text kommt vom Claude-Läufer.
 *
 * Riegel je Aussage und Impuls: (1) mindestens ein Beleg, alle Belege sind Eingangs-Einträge des Masters (oder `markt: true`
 * für „Markt in Zahlen“); (2) jede Zahl steht in den belegten Einträgen bzw. den Marktdaten ({@see TrendVertiefungService::zahlenBelegt});
 * (3) Aussagen prognostizieren und empfehlen nicht ({@see MarktEinordnungService::VERBOTEN}) — Empfehlungen gehören in die Impulse.
 * Was durchfällt, wird verworfen und gezählt; bleibt keine Aussage übrig, wird nichts gespeichert.
 */
class LagebildService
{
    public const MAX_AUSSAGEN = 8;

    public const MAX_IMPULSE = 5;

    public const MAX_TEXT = 400;

    /** Zusätzlich zu {@see MarktEinordnungService::VERBOTEN}: Trend-Prognosen („wird sich durchsetzen“, „werden wachsen“). */
    public const VERBOTEN_ZUSATZ = [
        '/\b(wird|werden|würde|würden)\b[^.]{0,40}\b(durchsetzen|wachsen|boomen|zunehmen|abnehmen|verschwinden|kommen|bleiben)\b/iu',
    ];

    public function __construct(private readonly FaRechte $rechte) {}

    /** Neuestes Lagebild aus Sicht des lesenden Teams (gelesen beim Master). */
    public function aktuelles(Team $team): ?array
    {
        return $this->liste($team, 1)->first();
    }

    /** @return Collection<int, array> neueste zuerst, mit aufgelösten Belegen (Titel, Herausgeber, URL). */
    public function liste(Team $team, int $limit = 6): Collection
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Lagebild lesen');
        $inhalt = ObservatoriumSicht::inhaltsTeam($team);
        $bilder = Lagebild::visibleToTeam($inhalt)->orderByDesc('zeitraum_bis')->orderByDesc('id')->limit(max(1, min($limit, 52)))->get();
        $ids = $bilder->flatMap(fn (Lagebild $l) => collect([...($l->aussagen ?? []), ...($l->impulse ?? [])])->flatMap(fn ($a) => $a['belege'] ?? []))->unique()->values();
        $artikel = $ids->isEmpty() ? collect() : Artikel::visibleToTeam($inhalt)->whereIn('id', $ids)->with('quelle:id,name')->get(['id', 'titel', 'url', 'herausgeber', 'quelle_id'])->keyBy('id');

        return $bilder->map(fn (Lagebild $l) => $this->darstellen($l, $artikel));
    }

    /**
     * Lagebild schreiben/ersetzen (ein Lagebild je Zeitraum-Ende). Nur im Master-Team mit Rolle Kuratieren.
     *
     * @param  array{zeitraum_von:string, zeitraum_bis:string, titel:string, kurzfazit?:?string, aussagen:list<array{text:string, belege?:list<int>, markt?:bool}>, impulse?:list<array{text:string, belege?:list<int>, markt?:bool}>, anbieter?:?string, modell?:?string}  $d
     * @return array{lagebild_id:int, aussagen:int, impulse:int, verworfen:int, gruende:list<string>}
     */
    public function schreiben(Team $team, array $d, ?int $userId = null): array
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Lagebild schreiben');
        if (! ObservatoriumSicht::kuratiertHier($team)) {
            throw new \RuntimeException('Das Lagebild schreibt nur das Master-Team (BHG.DIGITAL kuratiert das Observatorium zentral).');
        }
        $titel = trim((string) ($d['titel'] ?? ''));
        if ($titel === '' || mb_strlen($titel) > 200) {
            throw new \RuntimeException('titel fehlt oder ist länger als 200 Zeichen.');
        }
        try {
            $von = Carbon::parse((string) ($d['zeitraum_von'] ?? ''))->startOfDay();
            $bis = Carbon::parse((string) ($d['zeitraum_bis'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw new \RuntimeException('zeitraum_von und zeitraum_bis als Datum (JJJJ-MM-TT) angeben.');
        }
        if ($von->gt($bis) || $von->diffInDays($bis) > 31) {
            throw new \RuntimeException('Zeitraum ungültig: von ≤ bis und höchstens 31 Tage.');
        }
        $aussagenRoh = array_slice(array_values((array) ($d['aussagen'] ?? [])), 0, self::MAX_AUSSAGEN);
        $impulseRoh = array_slice(array_values((array) ($d['impulse'] ?? [])), 0, self::MAX_IMPULSE);
        $alleIds = collect([...$aussagenRoh, ...$impulseRoh])->flatMap(fn ($a) => (array) ($a['belege'] ?? []))->map(fn ($i) => (int) $i)->filter()->unique()->values();
        $artikel = $alleIds->isEmpty() ? collect() : Artikel::visibleToTeam($team)->whereIn('id', $alleIds)->get()->keyBy('id');
        $markt = null;
        $gruende = [];
        $pruefen = function (array $roh, bool $istAussage, int $nr) use ($artikel, $team, &$markt, &$gruende): ?array {
            $art = $istAussage ? 'Aussage' : 'Impuls';
            $text = trim((string) ($roh['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
                $gruende[] = "{$art} {$nr}: Text fehlt oder ist länger als ".self::MAX_TEXT.' Zeichen.';

                return null;
            }
            $belege = collect((array) ($roh['belege'] ?? []))->map(fn ($i) => (int) $i)->filter()->unique()->values()->all();
            $mitMarkt = (bool) ($roh['markt'] ?? false);
            $fremd = array_values(array_diff($belege, $artikel->keys()->all()));
            if ($fremd !== []) {
                $gruende[] = "{$art} {$nr}: Belege nicht im Observatorium: ".implode(', ', $fremd).'.';

                return null;
            }
            if ($belege === [] && ! $mitMarkt) {
                $gruende[] = "{$art} {$nr}: ohne Beleg.";

                return null;
            }
            if ($istAussage) {
                foreach ([...MarktEinordnungService::VERBOTEN, ...self::VERBOTEN_ZUSATZ] as $muster) {
                    if (preg_match($muster, $text)) {
                        $gruende[] = "Aussage {$nr}: Prognose oder Empfehlung — gehört in die Impulse.";

                        return null;
                    }
                }
            }
            $quelle = collect($belege)->map(fn ($id) => self::belegText($artikel[$id]))->implode("\n");
            if ($mitMarkt) {
                $markt ??= json_encode(MarktEinordnungService::kontext(app(MarktdatenService::class)->lesenFuerTeam($team)), JSON_UNESCAPED_UNICODE);
                $quelle .= "\n".$markt;
            }
            if (! TrendVertiefungService::zahlenBelegt($text, $quelle)) {
                $gruende[] = "{$art} {$nr}: Zahl steht in keinem Beleg.";

                return null;
            }

            return ['text' => $text, 'belege' => $belege, 'markt' => $mitMarkt];
        };
        $aussagen = [];
        foreach ($aussagenRoh as $i => $a) {
            if (($ok = $pruefen((array) $a, true, $i + 1)) !== null) {
                $aussagen[] = $ok;
            }
        }
        $impulse = [];
        foreach ($impulseRoh as $i => $a) {
            if (($ok = $pruefen((array) $a, false, $i + 1)) !== null) {
                $impulse[] = $ok;
            }
        }
        if ($aussagen === []) {
            throw new \RuntimeException('Keine Aussage hat die Prüfung bestanden: '.implode(' ', $gruende));
        }
        $verworfen = count($aussagenRoh) + count($impulseRoh) - count($aussagen) - count($impulse);
        $kurz = trim((string) ($d['kurzfazit'] ?? ''));
        if ($kurz !== '' && ! TrendVertiefungService::zahlenBelegt($kurz, collect($aussagen)->pluck('text')->implode("\n"))) {
            $gruende[] = 'Kurzfazit: Zahl steht in keiner geprüften Aussage — Kurzfazit weggelassen.';
            $kurz = '';
        }
        $l = Lagebild::where('team_id', $team->id)->whereDate('zeitraum_bis', $bis->toDateString())->first() ?? new Lagebild(['team_id' => $team->id]);
        $l->fill(['zeitraum_von' => $von->toDateString(), 'zeitraum_bis' => $bis->toDateString(), 'titel' => $titel,
            'kurzfazit' => $kurz === '' ? null : mb_substr($kurz, 0, 600), 'aussagen' => $aussagen, 'impulse' => $impulse, 'verworfen' => $verworfen,
            'anbieter' => isset($d['anbieter']) ? mb_substr((string) $d['anbieter'], 0, 60) : null,
            'modell' => isset($d['modell']) ? mb_substr((string) $d['modell'], 0, 80) : null, 'erstellt_von' => $userId])->save();

        return ['lagebild_id' => (int) $l->id, 'aussagen' => count($aussagen), 'impulse' => count($impulse), 'verworfen' => $verworfen, 'gruende' => $gruende];
    }

    /** Text eines Eingangs-Eintrags, gegen den Zahlen geprüft werden (Titel, Teaser, Zusammenfassung, Einordnung, Vertiefung). */
    public static function belegText(Artikel $a): string
    {
        return implode("\n", array_filter([$a->titel, $a->teaser, $a->zusammenfassung,
            $a->observatorium ? json_encode($a->observatorium, JSON_UNESCAPED_UNICODE) : null,
            $a->vertiefung ? json_encode($a->vertiefung, JSON_UNESCAPED_UNICODE) : null]));
    }

    private function darstellen(Lagebild $l, Collection $artikel): array
    {
        $beleg = fn (int $id) => ($a = $artikel->get($id)) ? ['id' => $id, 'titel' => $a->titel, 'url' => $a->url, 'herausgeber' => $a->herausgeber ?: $a->quelle?->name] : null;
        $teil = fn (?array $liste) => collect($liste ?? [])->map(fn ($a) => ['text' => $a['text'], 'markt' => (bool) ($a['markt'] ?? false),
            'belege' => collect($a['belege'] ?? [])->map($beleg)->filter()->values()->all()])->all();

        return ['id' => (int) $l->id, 'titel' => $l->titel, 'kurzfazit' => $l->kurzfazit,
            'zeitraum_von' => $l->zeitraum_von?->toDateString(), 'zeitraum_bis' => $l->zeitraum_bis?->toDateString(),
            'aussagen' => $teil($l->aussagen), 'impulse' => $teil($l->impulse), 'verworfen' => (int) $l->verworfen,
            'anbieter' => $l->anbieter, 'modell' => $l->modell, 'aktualisiert_am' => $l->updated_at?->toIso8601String()];
    }
}
