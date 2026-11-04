<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\AngebotStatus;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Mail\KonfiguratorAnfrageMail;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * Spec 94 · Anfrage aus dem öffentlichen Konfigurator-Link → normales Angebot (Status anfrage), Mail an die verantwortliche
 * Person, Signal `konfigurator_anfrage` im Team. Erster schreibender Endpunkt aus einem Präsentations-Link, deshalb:
 * nur der live Snapshot zählt, Auswahl nur aus dessen IDs, Preis neu aus dessen Preistabelle (nie aus dem Formular),
 * feste Feldgrößen, keine URLs/Dateien, Honeypot und Mindest-Ausfüllzeit (stille Ablage), Throttle an der Route.
 */
class KonfiguratorAnfrageService
{
    public const SIGNAL_QUELLE = 'konfigurator';

    private const MINDEST_SEKUNDEN = 3;

    public function __construct(private SignalService $signale)
    {
    }

    /** Live-Konfigurator zum öffentlichen Link (Token oder Link-Name) — bewusst ohne Team-Scope, nur live. */
    public function liveKonfigurator(string $ref): ?FoodAlchemistKonfigurator
    {
        $k = FoodAlchemistKonfigurator::query()->ohneTeamSicht('oeffentlicher-link')->byPresentationRef($ref)->first();

        return $k !== null && $k->isPresentationLive() && is_array($k->presentation_snapshot_json) ? $k : null;
    }

    /**
     * @return FoodAlchemistAngebot|null null = still verworfen (Honeypot, zu schnell)
     *
     * @throws ValidationException
     */
    public function anfragen(FoodAlchemistKonfigurator $k, array $in): ?FoodAlchemistAngebot
    {
        if (trim((string) ($in['website'] ?? '')) !== '' || ! $this->genugZeit($in['ts'] ?? null)) {
            return null;
        }
        $body = $k->presentation_snapshot_json['content'] ?? [];
        $konzeptIds = array_column($body['konzepte'] ?? [], 'id');
        $addonIds = array_column($body['addons'] ?? [], 'id');
        $in['addons'] = array_values(array_filter(array_map('intval', explode(',', (string) ($in['addons'] ?? ''))), fn ($a) => $a > 0));

        $v = Validator::make($in, [
            'name' => ['required', 'string', 'max:120'],
            'firma' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'telefon' => ['nullable', 'string', 'max:60', 'regex:/^[0-9+()\/\s.-]*$/'],
            'datum' => ['nullable', 'date', 'after_or_equal:today'],
            'nachricht' => ['nullable', 'string', 'max:2000'],
            'datenschutz' => ['accepted'],
            'konzept' => ['required', 'integer', 'in:' . implode(',', $konzeptIds ?: [0])],
            'tageszeit' => ['required', 'in:lunch,dinner,day_night'],
            'abend_konzept' => ['nullable', 'integer', 'in:' . implode(',', $konzeptIds ?: [0])],
            'addons' => ['array', 'max:50'],
            'addons.*' => ['integer', 'in:' . implode(',', $addonIds ?: [0])],
            'pax' => ['required', 'integer', 'min:1', 'max:100000'],
        ], [], ['konzept' => 'Konzept', 'pax' => 'Gäste', 'datenschutz' => 'Einwilligung']);
        $d = $v->validate();

        $preis = KonfiguratorSnapshot::preisFuer($body, [
            'konzept' => (int) $d['konzept'], 'tageszeit' => $d['tageszeit'], 'abend_konzept' => $d['abend_konzept'] ?? null,
            'addons' => $d['addons'] ?? [], 'pax' => (int) $d['pax'],
        ]);
        if ($preis === null) {
            throw ValidationException::withMessages(['konzept' => 'Für diese Auswahl und Gästezahl gibt es keinen Preis.']);
        }

        // Spec 94b §4: Leistungen nach Aufwand, geschätzt bei der Gästezahl der Anfrage (Mengenregel, nicht im Preis)
        $aufwand = $this->aufwand($k, $d);
        $titel = fn (int $id, array $liste) => collect($liste)->firstWhere('id', $id)['titel'] ?? ('#' . $id);
        $konzeptTitel = $titel((int) $d['konzept'], $body['konzepte']);
        $tageszeit = ['lunch' => 'Lunch', 'dinner' => 'Dinner', 'day_night' => 'Day into Night'][$d['tageszeit']];
        $abend = $d['tageszeit'] === 'day_night' ? $titel((int) ($d['abend_konzept'] ?? $d['konzept']), $body['konzepte']) : null;
        $addons = array_map(fn ($a) => $titel($a, $body['addons'] ?? []), $d['addons'] ?? []);
        $euro = fn (float $w) => number_format($w, 2, ',', '.') . ' €';

        $text = implode("\n", array_filter([
            'Anfrage aus dem Konfigurator „' . ($k->kunden_titel ?: $k->name) . '“',
            'Konzept: ' . $konzeptTitel . ' · ' . $tageszeit . ($abend !== null ? ' (Abend: ' . $abend . ')' : ''),
            $addons !== [] ? 'Add-ons: ' . implode(', ', $addons) : null,
            'Gäste: ' . $d['pax'] . ' (Paket ' . $preis['paket_name'] . ')',
            'Preis: ' . $euro($preis['pro_person']) . ' pro Person, gesamt ' . $euro($preis['gesamt']) . ' netto (Stand des Links)',
            $aufwand !== [] ? 'zzgl. nach Aufwand (geschätzt): ' . implode('; ', array_map(fn ($e) => 'ca. ' . $this->zahl($e['menge']) . ' ' . $e['einheit'] . ' ' . $e['bezeichnung'] . ' ≈ ' . $euro($e['betrag']), $aufwand)) : null,
            '',
            'Kontakt: ' . $d['name'] . (($d['firma'] ?? '') !== '' ? ', ' . $d['firma'] : ''),
            'E-Mail: ' . $d['email'] . (($d['telefon'] ?? '') !== '' ? ' · Telefon: ' . $d['telefon'] : ''),
            ($d['datum'] ?? null) ? 'Wunschdatum: ' . \Illuminate\Support\Carbon::parse($d['datum'])->format('d.m.Y') : null,
            ($d['nachricht'] ?? '') !== '' ? "\nNachricht:\n" . $d['nachricht'] : null,
        ], fn ($z) => $z !== null));

        $angebot = DB::transaction(fn () => $this->mitPositionen(FoodAlchemistAngebot::create([
            'team_id' => $k->team_id,
            'name' => mb_substr('Anfrage ' . $d['name'] . ' · ' . $konzeptTitel, 0, 190),
            'status' => AngebotStatus::Anfrage->value,
            'occasion' => $k->kunden_titel ?: $k->name,
            'personen' => (int) $d['pax'],
            'event_date' => $d['datum'] ?? null,
            'location' => $k->ort,
            'brief' => $text,
            'total_price' => $preis['gesamt'],
            'konfigurator_id' => $k->id,
            'konfiguration_json' => [
                'konzept' => (int) $d['konzept'], 'tageszeit' => $d['tageszeit'], 'abend_konzept' => $d['abend_konzept'] ?? null,
                'addons' => $d['addons'] ?? [], 'pax' => (int) $d['pax'], 'paket' => $preis['paket_name'],
                'pro_person' => $preis['pro_person'], 'gesamt' => $preis['gesamt'],
                'preisstand' => $k->presentation_published_at?->toIso8601String(),
                'kontakt' => array_intersect_key($d, array_flip(['name', 'firma', 'email', 'telefon'])),
                'zzgl' => array_map(fn ($e) => array_intersect_key($e, array_flip(['bezeichnung', 'menge', 'einheit', 'satz', 'betrag'])), $aufwand),
                // Spec 94b §3: Bedarf für die Event-Planung (Übergabe an OLIVIA), eingefroren, am Angebot nur zu lesen
                'planung' => app(KonfiguratorPlanungService::class)->bedarf($k, $d, (int) $d['pax'], ['datum' => $d['datum'] ?? null, 'ort' => $k->ort]),
            ],
        ]), $aufwand));
        $json = $angebot->konfiguration_json;
        $json['planung']['kopf']['angebot_uuid'] = $angebot->uuid;
        $angebot->update(['konfiguration_json' => $json]);

        $team = Team::find($k->team_id);
        if ($team !== null) {
            $this->signale->erzeuge($team, SignalTyp::KonfiguratorAnfrage, SignalSeverity::Info,
                'Anfrage: ' . $d['name'] . ' · ' . $konzeptTitel . ' · ' . $d['pax'] . ' Gäste', [
                    'dedup_key' => $this->dedupKey($angebot), 'source' => self::SIGNAL_QUELLE,
                    'ref_type' => 'angebot', 'ref_id' => $angebot->id,
                    'description' => $text,
                    'payload' => ['angebot_id' => $angebot->id, 'konfigurator_id' => $k->id, 'gesamt' => $preis['gesamt']],
                ]);
        }
        $this->mail($k, $angebot, $text, (string) $d['email']);

        return $angebot;
    }

    /**
     * Leistungen nach Aufwand der gewählten Auswahl bei genau dieser Gästezahl (Paket der Gästezahl, Mengen nach Regel,
     * Handwerte gelten weiter) — je Leistung summiert über Konzept und gewählte Extras. Leer, wenn es kein Paket gibt.
     *
     * @return list<array{leistung_id:int, bezeichnung:string, einheit:string, satz:float, ek_satz:float, menge:float, betrag:float, ek_betrag:float, einheit_vocab_id:?int}>
     */
    private function aufwand(FoodAlchemistKonfigurator $k, array $d): array
    {
        $pricing = app(\Platform\FoodAlchemist\Services\KonfiguratorPricingService::class);
        $s = $pricing->stand($k);
        $pax = (int) $d['pax'];
        $paket = collect($s['pakete'])->first(fn ($p) => $pax >= (int) $p->von_pax && $pax <= (int) $p->bis_pax);
        if ($paket === null) {
            return [];
        }
        $faelle = [$d['tageszeit'] === 'day_night'
            ? ['typ' => 'day_night', 'k1' => (int) $d['konzept'], 'k2' => (int) ($d['abend_konzept'] ?? $d['konzept'])]
            : ['typ' => 'einzel', 'k' => (int) $d['konzept'], 't' => $d['tageszeit']]];
        foreach ($d['addons'] ?? [] as $a) {
            $faelle[] = ['typ' => 'addon', 'a' => (int) $a];
        }
        $summe = [];
        foreach ($faelle as $fall) {
            foreach ($pricing->rechneFall($k, $paket, $fall, $pax, $s)['einzel'] as $e) {
                $id = $e['leistung_id'];
                $summe[$id] ??= array_merge($e, ['menge' => 0.0, 'betrag' => 0.0, 'ek_betrag' => 0.0,
                    'einheit_vocab_id' => $s['leistungen'][$id]->abrechnung_einheit_vocab_id !== null ? (int) $s['leistungen'][$id]->abrechnung_einheit_vocab_id : null]);
                $summe[$id]['menge'] = round($summe[$id]['menge'] + $e['menge'], 2);
                $summe[$id]['betrag'] = round($summe[$id]['betrag'] + $e['betrag'], 2);
                $summe[$id]['ek_betrag'] = round($summe[$id]['ek_betrag'] + $e['ek_betrag'], 2);
            }
        }

        return array_values(array_filter($summe, fn ($e) => $e['menge'] > 0));
    }

    /** Kapitel „Nach Aufwand“ mit je einer Position (Blocktyp position, Preis-Basis aufwand: zzgl., nicht in der Summe). */
    private function mitPositionen(FoodAlchemistAngebot $angebot, array $aufwand): FoodAlchemistAngebot
    {
        if ($aufwand === []) {
            return $angebot;
        }
        $kapitel = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::create(['team_id' => $angebot->team_id, 'offer_id' => $angebot->id,
            'title' => 'Nach Aufwand', 'consumer_title' => 'Zzgl. nach Aufwand', 'price_mode' => 'auto', 'position' => 1]);
        foreach (array_values($aufwand) as $i => $e) {
            $kapitel->blocks()->create(['team_id' => $angebot->team_id, 'type' => 'position', 'label' => $e['bezeichnung'], 'quantity' => $e['menge'],
                'unit_vocab_id' => $e['einheit_vocab_id'], 'price_value' => $e['satz'], 'ek_value' => $e['ek_satz'], 'price_basis' => 'aufwand',
                'customer_text' => 'Geschätzt, abgerechnet nach tatsächlichem Aufwand.', 'position' => $i + 1,
                'payload_json' => ['einheit_text' => $e['einheit'], 'quelle' => 'konfigurator_aufwand', 'leistung_id' => $e['leistung_id']]]);
        }

        return $angebot;
    }

    private function zahl(float $w): string
    {
        return rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ',');
    }

    /** Signal schließen, sobald das Angebot den Status anfrage verlässt (AngebotService::setStatus). */
    public function angebotWeiter(Team $team, FoodAlchemistAngebot $angebot): void
    {
        if ($angebot->konfigurator_id !== null && $angebot->status !== AngebotStatus::Anfrage) {
            $this->signale->schliesseGemessen($team, SignalTyp::KonfiguratorAnfrage, $this->dedupKey($angebot), self::SIGNAL_QUELLE,
                'Angebot ist nicht mehr im Status Anfrage — automatisch geschlossen');
        }
    }

    private function dedupKey(FoodAlchemistAngebot $angebot): string
    {
        return 'konfigurator-anfrage-' . $angebot->id;
    }

    private function mail(FoodAlchemistKonfigurator $k, FoodAlchemistAngebot $angebot, string $text, string $kundeMail): void
    {
        $empfaenger = $k->verantwortlich_user_id !== null ? User::find($k->verantwortlich_user_id)?->email : null;
        if (! $empfaenger) {
            return;   // ohne Adresse nur das Signal; der Editor zeigt „Anfragen gehen an: niemand“
        }
        try {
            Mail::to($empfaenger)->send(new KonfiguratorAnfrageMail(
                'Neue Anfrage: ' . ($k->kunden_titel ?: $k->name),
                $text . "\n\nAngebot öffnen: " . route('foodalchemist.angebote.index', ['sel' => $angebot->id]),
                $kundeMail,
            ));
        } catch (\Throwable $e) {
            Log::warning('Konfigurator-Anfrage: Mail nicht versendet', ['angebot_id' => $angebot->id, 'fehler' => $e->getMessage()]);
        }
    }

    private function genugZeit(mixed $ts): bool
    {
        try {
            $start = (int) decrypt((string) $ts);
        } catch (\Throwable) {
            return false;
        }

        return now()->timestamp - $start >= self::MINDEST_SEKUNDEN;
    }
}
