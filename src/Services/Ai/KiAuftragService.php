<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\KiAuftragAusstehendException;
use Platform\FoodAlchemist\Support\FaTeam;
use RuntimeException;

/**
 * #1002: Auftrags-Lebenszyklus des externen KI-Wegs. Ablauf (Variante „Wiederholung"):
 *  1. Gateway ruft {@see antwortOderAuftrag()} am Provider-Punkt: gibt es eine beantwortete Antwort zu
 *     (Team, Feature, messages_hash), wird sie einmalig verbraucht — sonst Auftrag anlegen + KiAuftragAusstehendException.
 *  2. MCP-Client holt offene Aufträge ({@see offene()}) und beantwortet sie ({@see beantworte()}).
 *  3. Der Aufrufer wird wiederholt; Prüfung, Call-Log und Nachverarbeitung laufen unverändert im Gateway/Aufrufer.
 * Ohne gültige Auftrags-ID wird nichts geschrieben.
 */
class KiAuftragService
{
    public const TTL_STUNDEN = 24;

    /** @param array<int, array<string, mixed>> $messages @param array<string, mixed> $optionen */
    public function antwortOderAuftrag(string $feature, array $messages, array $optionen, array $audit = []): array
    {
        $teamId = FaTeam::aktuellId();
        if ($teamId === null) {
            throw new RuntimeException('Externer KI-Weg braucht ein Team im Kontext.');
        }
        $hash = self::hash($messages);

        $fertig = DB::table('foodalchemist_ki_auftraege')
            ->where('team_id', $teamId)->where('feature', $feature)->where('messages_hash', $hash)
            ->where('status', 'beantwortet')->orderBy('id')->first();
        if ($fertig !== null) {
            // einmalig verbrauchen: UPDATE mit Statusbedingung, damit zwei parallele Läufe nicht dieselbe Antwort ziehen
            $n = DB::table('foodalchemist_ki_auftraege')->where('id', $fertig->id)->where('status', 'beantwortet')
                ->update(['status' => 'verbraucht', 'verbraucht_at' => now(), 'updated_at' => now()]);
            if ($n === 1) {
                $modell = trim(($fertig->anbieter ? $fertig->anbieter . '/' : '') . (string) $fertig->modell, '/');

                return ['content' => (string) $fertig->antwort, 'model' => $modell !== '' ? $modell : 'extern', 'usage' => [], 'extern' => true];
            }
        }

        $offen = DB::table('foodalchemist_ki_auftraege')
            ->where('team_id', $teamId)->where('feature', $feature)->where('messages_hash', $hash)
            ->where('status', 'offen')->where('laeuft_ab_at', '>', now())->first();
        $modus = app(KiExternModus::class);
        if ($offen !== null) {
            $modus->meldeWartend($offen->uuid, $feature);

            throw new KiAuftragAusstehendException($offen->uuid, $feature);
        }
        if ($modus->sammelt() && ($schonDa = $modus->vorgemerktUuid($feature, $hash)) !== null) {
            throw new KiAuftragAusstehendException($schonDa, $feature);
        }

        $uuid = (string) \Symfony\Component\Uid\UuidV7::generate();
        $zeile = [
            'uuid' => $uuid, 'team_id' => $teamId, 'user_id' => \Illuminate\Support\Facades\Auth::id(),
            'feature' => $feature, 'messages_hash' => $hash,
            'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            'optionen' => json_encode(array_filter($optionen, fn ($v) => is_scalar($v) || is_array($v)), JSON_UNESCAPED_UNICODE),
            'target_table' => $audit['target_table'] ?? null, 'target_id' => $audit['target_id'] ?? null,
            'knowledge_run_id' => $audit['knowledge_run_id'] ?? null,
            'status' => 'offen', 'laeuft_ab_at' => now()->addHours(self::TTL_STUNDEN),
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (($wdh = $modus->wiederholung()) !== null) {              // P2a: Job-Lauf — ANTWORT darf ihn neu anstoßen
            $zeile['wiederholung'] = json_encode($wdh, JSON_UNESCAPED_UNICODE);
            $zeile['lauf_key'] = (string) ($wdh['lauf_key'] ?? '');
        }
        if ($modus->sammelt()) {
            // MCP-Tool-Hülle: erst nach dem Rollback der Tool-Transaktion schreiben (legeAn), sonst rollt der Auftrag mit zurück
            $modus->merkeVor($zeile);
        } else {
            $this->legeAn([$zeile]);
        }

        throw new KiAuftragAusstehendException($uuid, $feature);
    }

    /** Wartet für dieses Ziel schon ein Lauf (offener oder beantworteter, noch nicht verbrauchter Auftrag)? Dann startet kein zweiter. */
    public function laufAktiv(int $teamId, string $laufKey): bool
    {
        return DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('lauf_key', $laufKey)
            ->whereIn('status', ['offen', 'beantwortet'])->where('laeuft_ab_at', '>', now())->exists();
    }

    /**
     * Startet die Job-Läufe neu, deren letzter offener Auftrag mit dieser Antwort-Runde beantwortet ist (P2a). Nur Klassen, die
     * {@see KiExternWiederholbar} umsetzen; je Lauf höchstens einmal je Versuch.
     *
     * @param  list<string>  $uuids soeben beantwortete Aufträge
     * @return list<array{lauf_key: string, job: string, versuch: int}>
     */
    public function stoesseWiederholungAn(int $teamId, array $uuids): array
    {
        $gestartet = [];
        $beantwortet = DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->whereIn('uuid', $uuids)
            ->where('status', 'beantwortet')->whereNotNull('wiederholung')->get();
        $rows = $beantwortet->filter(fn ($r) => (string) $r->lauf_key !== '')->groupBy('lauf_key');
        foreach ($rows as $key => $_) {
            $key = (string) $key;
            if ($key === ''
                || DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('lauf_key', $key)->where('status', 'offen')
                    ->where('laeuft_ab_at', '>', now())->exists()) {
                continue;                                            // der Lauf wartet noch auf weitere Antworten
            }
            $alle = DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('lauf_key', $key)->where('status', 'beantwortet')
                ->whereNotNull('wiederholung')->orderBy('id')->get()
                ->map(fn ($r) => ['zeile' => $r, 'p' => json_decode((string) $r->wiederholung, true) ?: []]);
            $maxV = (int) $alle->max(fn ($a) => (int) ($a['p']['versuch'] ?? 0));
            $letzte = $alle->filter(fn ($a) => (int) ($a['p']['versuch'] ?? 0) === $maxV);
            if ($letzte->contains(fn ($a) => isset($a['p']['gestartet_at']))) {
                continue;                                            // für diesen Versuch schon angestoßen
            }
            $p = $letzte->last()['p'];
            $klasse = $p['klasse'] ?? null;
            if (! is_string($klasse) || ! class_exists($klasse) || ! is_subclass_of($klasse, KiExternWiederholbar::class)) {
                continue;                                            // nie eine fremde Klasse aus der Datenbank instanziieren
            }
            foreach ($letzte as $a) {
                DB::table('foodalchemist_ki_auftraege')->where('id', $a['zeile']->id)
                    ->update(['wiederholung' => json_encode($a['p'] + ['gestartet_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE)]);
            }
            dispatch($klasse::fromExternNutzlast((array) ($p['nutzlast'] ?? []), $maxV + 1));
            $gestartet[] = ['lauf_key' => $key, 'job' => class_basename($klasse), 'versuch' => $maxV + 1];
        }

        // Mitwartende: Läufe mit demselben Prompt, die auf einen fremden Auftrag gewartet haben — jeder genau einmal neu starten
        $schonGestartet = array_column($gestartet, 'lauf_key');
        foreach ($beantwortet as $r) {
            $p = json_decode((string) $r->wiederholung, true);
            $weitere = is_array($p) ? (array) ($p['weitere'] ?? []) : [];
            $geaendert = false;
            foreach ($weitere as $i => $w) {
                $wKey = (string) ($w['lauf_key'] ?? '');
                $klasse = $w['klasse'] ?? null;
                if ($wKey === '' || isset($w['gestartet_at']) || in_array($wKey, $schonGestartet, true)
                    || ! is_string($klasse) || ! class_exists($klasse) || ! is_subclass_of($klasse, KiExternWiederholbar::class)) {
                    continue;
                }
                $weitere[$i]['gestartet_at'] = now()->toIso8601String();
                $geaendert = true;
                $schonGestartet[] = $wKey;
                dispatch($klasse::fromExternNutzlast((array) ($w['nutzlast'] ?? []), (int) ($w['versuch'] ?? 0) + 1));
                $gestartet[] = ['lauf_key' => $wKey, 'job' => class_basename($klasse), 'versuch' => (int) ($w['versuch'] ?? 0) + 1];
            }
            if ($geaendert) {
                $p['weitere'] = $weitere;
                DB::table('foodalchemist_ki_auftraege')->where('id', $r->id)->update(['wiederholung' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
            }
        }

        return $gestartet;
    }

    /**
     * Ein Lauf wartet auf einen Auftrag, der schon da ist (gleicher Prompt, den ein anderer Lauf angelegt hat): ihn bei diesem Auftrag als
     * Mitwartenden festhalten, damit ANTWORT auch ihn neu startet (sonst bliebe sein Step für immer `running`). Je Lauf ein Eintrag; ein
     * neuer Versuch ersetzt den alten. Nach dem Rollback des Laufs aufrufen (die Zeile selbst gehört zu keiner Transaktion des Laufs).
     *
     * @param  list<string>  $uuids Aufträge, auf die der Lauf wartet (ohne die, die er selbst neu anlegt)
     * @param  array<string, mixed>  $lauf Wiederholungs-Nutzlast des wartenden Laufs (klasse, nutzlast, versuch, lauf_key)
     */
    public function registriereWartende(int $teamId, array $uuids, array $lauf): void
    {
        $key = (string) ($lauf['lauf_key'] ?? '');
        if ($key === '' || $uuids === []) {
            return;
        }
        foreach (DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->whereIn('uuid', $uuids)->where('status', 'offen')->get() as $z) {
            if ((string) $z->lauf_key === $key) {
                continue;                                            // der Besitzer des Auftrags wird ohnehin neu gestartet
            }
            $p = json_decode((string) $z->wiederholung, true);
            $p = is_array($p) ? $p : [];
            $weitere = array_values(array_filter((array) ($p['weitere'] ?? []), fn ($w) => ($w['lauf_key'] ?? null) !== $key));
            $weitere[] = ['klasse' => $lauf['klasse'] ?? null, 'nutzlast' => $lauf['nutzlast'] ?? [], 'versuch' => (int) ($lauf['versuch'] ?? 0), 'lauf_key' => $key];
            $p['weitere'] = $weitere;
            DB::table('foodalchemist_ki_auftraege')->where('id', $z->id)->update(['wiederholung' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** @param list<array<string, mixed>> $zeilen vorgemerkte Aufträge — außerhalb jeder Tool-Transaktion aufrufen */
    public function legeAn(array $zeilen): void
    {
        foreach ($zeilen as $zeile) {
            // Der Wissens-Lauf (knowledge_run) entstand in der zurückgerollten Transaktion und existiert nicht mehr — kein hängender Verweis
            if (! empty($zeile['knowledge_run_id']) && ! DB::table('foodalchemist_knowledge_runs')->where('id', $zeile['knowledge_run_id'])->exists()) {
                $zeile['knowledge_run_id'] = null;
            }
            DB::table('foodalchemist_ki_auftraege')->insert($zeile);
        }
    }

    /** @return list<object> offene, nicht abgelaufene Aufträge des Teams (inkl. vollem Prompt) */
    public function offene(int $teamId, int $limit = 50, ?string $feature = null): array
    {
        $this->laufAb($teamId);

        return DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('status', 'offen')
            ->when($feature, fn ($q) => $q->where('feature', $feature))
            ->orderBy('id')->limit(max(1, min(50, $limit)))->get()->all();
    }

    /**
     * Speichert eine externe Antwort. Abgelehnt (RuntimeException): unbekannte/fremde ID, nicht offen, abgelaufen,
     * Antwort kein JSON-Objekt. Die fachliche Prüfung (structural_retry, Guards) macht danach der Wiederholungslauf.
     *
     * @param array<string, mixed>|string $werte
     */
    public function beantworte(int $teamId, string $auftragUuid, array|string $werte, ?string $anbieter, ?string $modell): void
    {
        $zeile = DB::table('foodalchemist_ki_auftraege')->where('uuid', $auftragUuid)->where('team_id', $teamId)->first();
        if ($zeile === null) {
            throw new RuntimeException("Unbekannter KI-Auftrag {$auftragUuid}.");
        }
        if ($zeile->status !== 'offen') {
            throw new RuntimeException("KI-Auftrag {$auftragUuid} ist nicht offen (Status {$zeile->status}).");
        }
        if ($zeile->laeuft_ab_at !== null && now()->gt($zeile->laeuft_ab_at)) {
            DB::table('foodalchemist_ki_auftraege')->where('id', $zeile->id)->update(['status' => 'abgelaufen', 'updated_at' => now()]);
            throw new RuntimeException("KI-Auftrag {$auftragUuid} ist abgelaufen.");
        }
        $roh = is_array($werte) ? json_encode($werte, JSON_UNESCAPED_UNICODE) : (string) $werte;
        $json = json_decode(app(AiGatewayService::class)->stripJsonFence($roh), true);
        if (! is_array($json)) {
            throw new RuntimeException('Antwort ist kein JSON-Objekt/Array — Schema-Verstoß, nichts gespeichert.');
        }
        DB::table('foodalchemist_ki_auftraege')->where('id', $zeile->id)->where('status', 'offen')->update([
            'status' => 'beantwortet', 'antwort' => json_encode($json, JSON_UNESCAPED_UNICODE),
            'anbieter' => $anbieter, 'modell' => $modell, 'beantwortet_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Bilanz des externen Wegs (#1002): wie viele Aufträge entstanden, wie viele Antworten wirklich verbraucht wurden, was liegen blieb.
     * `angelegt`/`beantwortet`/`verbraucht` zählen ab `$seit` (Lauf-Beginn); `offen`, `liegt_beantwortet`, `abgelaufen` sind Bestand.
     * `doppelt` = verschiedene Prompts über dem ersten je (Feature, Ziel) ab `$seit` — wäre der Fingerabdruck instabil (andere Reihenfolge der
     * Treffer, anderer Kontext), entstünden hier mehrere Aufträge für dasselbe Ziel. `wiederholt` = derselbe Prompt mehrfach beauftragt: der
     * Re-Roll der Antwortprüfung (`structural_retry`, bis 3 Versuche) fragt bei unbrauchbarer Antwort neu — erwartet, kein Fehler.
     * `verwaist` = abgelaufen + seit über 1 Stunde beantwortet, nie verbraucht.
     *
     * @return array<string, int>
     */
    public function zaehler(int $teamId, ?\DateTimeInterface $seit = null, ?string $feature = null): array
    {
        $basis = fn () => DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->when($feature, fn ($q) => $q->where('feature', $feature));
        $ab = fn ($q, string $spalte) => $seit !== null ? $q->where($spalte, '>=', $seit) : $q;
        $doppelt = 0;
        $wiederholt = 0;
        $ab($basis()->whereNotNull('target_id'), 'created_at')->select('feature', 'target_table', 'target_id')
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT messages_hash) AS h')
            ->groupBy('feature', 'target_table', 'target_id')->havingRaw('COUNT(*) > 1')->get()
            ->each(function ($z) use (&$doppelt, &$wiederholt) {
                $doppelt += max(0, (int) $z->h - 1);                 // verschiedener Prompt für dasselbe Ziel: Fingerabdruck instabil (oder Kontext hat sich geändert)
                $wiederholt += (int) $z->n - (int) $z->h;           // derselbe Prompt nochmal: Re-Roll der Prüfung (structural_retry), die Antwort war unbrauchbar
            });
        $abgelaufen = (int) $basis()->where('status', 'abgelaufen')->count();
        $alt = (int) $basis()->where('status', 'beantwortet')->where('beantwortet_at', '<', now()->subHour())->count();

        return [
            'angelegt' => (int) $ab($basis(), 'created_at')->count(),
            'beantwortet' => (int) $ab($basis()->whereNotNull('beantwortet_at'), 'beantwortet_at')->count(),
            'verbraucht' => (int) $ab($basis()->where('status', 'verbraucht'), 'verbraucht_at')->count(),
            'offen' => (int) $basis()->where('status', 'offen')->where('laeuft_ab_at', '>', now())->count(),
            'liegt_beantwortet' => (int) $basis()->where('status', 'beantwortet')->count(),
            'abgelaufen' => $abgelaufen,
            'verwaist' => $abgelaufen + $alt,
            'doppelt' => $doppelt,
            'wiederholt' => $wiederholt,
        ];
    }

    public static function hash(array $messages): string
    {
        return hash('sha256', json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function laufAb(int $teamId): void
    {
        DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('status', 'offen')
            ->where('laeuft_ab_at', '<', now())->update(['status' => 'abgelaufen', 'updated_at' => now()]);
    }
}
