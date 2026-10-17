<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 7: Veröffentlichen eines MASTER-Formats als Fassung (Entwurf D1: Zeilen-Fassung). Spiegelt {@see ConceptFassungService}.
 *
 * - Nur im Master-Team, Rolle Freigeben, Änderungsnotiz Pflicht.
 * - **D8 (Kunden-IP): ein Format mit `origin = kunde` lässt sich nie veröffentlichen/abonnieren** — hart hier, in AboService (Hülle, abonnieren) und beim Befüllen einer abonnierten
 *   Sammlung. Wer ein Kunden-Format als Abo-Inhalt braucht, hat ein Herkunfts-Problem, kein Technik-Problem.
 * - D2: jedes Konzept in den Slots braucht eine veröffentlichte Fassung (Liste bei Abbruch); die Fassung zeigt auf die jüngste Fassung je Konzept.
 * - Ein Format trägt keine Kosten und keinen Preis: nichts davon geht mit (und wird beim Mandanten live aus den Konzept-Fassungen gerechnet, PR 4).
 * - Slot-Wording-Overrides (`payload_json.wording_overrides`, geschlüsselt nach Konzept-SLOT-IDs) werden auf die Slots der Konzept-Fassung umgeschlüsselt (Position für Position),
 *   sonst zeigten sie ins Leere.
 * - Bilder (Galerie/Hero) bleiben Verweise auf die Master-Datei (D5); Kopie erst bei Kündigung/Eigene Kopie.
 */
class FormatFassungService
{
    /** Spalten, die eine Format-Fassung nie vom Arbeitsstand erbt. */
    private const NIE_KOPIEREN = [
        'uuid', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'kopie_von_id', 'kopie_stand_at',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    private const SLOT_NIE = ['id', 'uuid', 'team_id', 'format_id', 'concept_id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        private FaRechte $rechte,
        private AboService $abos,
        private PlattformProtokoll $protokoll,
    ) {}

    /**
     * @return array{fassung_id:int, neu:bool, fassung_nr:int, hinweise:list<string>}
     */
    public function veroeffentlichen(Team $master, int $formatId, string $notiz, ?User $actor): array
    {
        // Spec 84 B3 C: Lesen im Master; das Recht prüft der Rumpf am übergebenen Team + Nutzer, nicht am Kontext
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($master, fn () => $this->veroeffentlichenImTeam($master, $formatId, $notiz, $actor));
    }

    private function veroeffentlichenImTeam(Team $master, int $formatId, string $notiz, ?User $actor): array
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Veröffentlichen gibt es nur im Master-Team (Spec 82 §12 B).');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Format veröffentlichen');
        $notiz = trim($notiz);
        if ($notiz === '') {
            throw new \RuntimeException('Beim Veröffentlichen ist eine Änderungsnotiz Pflicht (Änderungsprotokoll der Abonnenten).');
        }
        $format = FoodAlchemistFormat::where('team_id', $master->id)->findOrFail($formatId);   // Scope: nie eine Fassung
        if ($format->origin === 'kunde') {
            throw new \RuntimeException('Ein Kunden-Format (origin=kunde) ist nie abonnierbar und lässt sich nicht veröffentlichen (Kunden-IP, D8).');
        }
        $slots = $format->slots()->get();
        $ziele = $this->zieleSammeln($slots);
        $hash = $this->inhaltHash($format, $slots, $ziele);
        $vorher = FoodAlchemistFormat::mitFassungen()->where('fassung_von_id', $format->id)->where('ist_fassung', true)
            ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        if ($vorher !== null && $vorher->inhalt_hash === $hash) {
            return ['fassung_id' => (int) $vorher->id, 'neu' => false, 'fassung_nr' => (int) $vorher->fassung_nr, 'hinweise' => []];
        }

        $fassung = DB::transaction(function () use ($format, $slots, $ziele, $hash, $notiz, $actor, $vorher) {
            $nr = (int) FoodAlchemistFormat::mitFassungen()->withTrashed()->where('fassung_von_id', $format->id)->where('ist_fassung', true)->max('fassung_nr') + 1;
            $neu = $this->kopiere($format, $slots, $ziele, $nr, $hash, $notiz, $actor);
            $this->markiereAllergenAenderung($neu, $vorher);

            return $neu;
        });

        $this->abos->neuBerechnenAlle();
        $this->protokoll->schreibe('format_fassung_veroeffentlicht', ['format_id' => $format->id, 'fassung_id' => $fassung->id, 'fassung_nr' => (int) $fassung->fassung_nr, 'notiz' => $notiz], $actor, $fassung);
        try {   // Signale und Folgen: nie ein Grund, die Veröffentlichung scheitern zu lassen
            app(FassungSignalService::class)->nachBausteinVeroeffentlichung('format', [(int) $fassung->id]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Format-Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: '.$e->getMessage(), ['exception' => $e, 'format_id' => $format->id]);
        }

        return ['fassung_id' => (int) $fassung->id, 'neu' => true, 'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => []];
    }

    /**
     * Lese-Status für die Oberfläche „Veröffentlichen…“ — rechnet nichts um und schreibt nichts (Spec 82 §13 B6, PR 8). Ein Kunden-Format ist `blockiert` (D8).
     *
     * @return array{letzte: ?array{id:int, nr:int, veroeffentlicht_at:?string, notiz:?string}, weicht_ab: bool, blockiert: ?string, hinweise: list<string>, allergen_aenderung: bool, allergen_felder: list<string>}
     */
    public function status(FoodAlchemistFormat $format): array
    {
        $letzte = FoodAlchemistFormat::mitFassungen()->where('fassung_von_id', $format->id)->where('ist_fassung', true)->whereNull('deleted_at')
            ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
        $out = ['letzte' => $letzte === null ? null : ['id' => (int) $letzte->id, 'nr' => (int) $letzte->fassung_nr, 'veroeffentlicht_at' => $letzte->veroeffentlicht_at?->format('d.m.Y H:i'),
            'notiz' => $letzte->aenderungsnotiz], 'weicht_ab' => true, 'blockiert' => null, 'hinweise' => [], 'allergen_aenderung' => false, 'allergen_felder' => []];
        if ($format->origin === 'kunde') {
            $out['blockiert'] = 'Ein Kunden-Format (origin=kunde) ist nie abonnierbar und lässt sich nicht veröffentlichen (Kunden-IP, D8).';

            return $out;
        }
        try {
            $slots = $format->slots()->get();
            $ziele = $this->zieleSammeln($slots);
        } catch (\RuntimeException $e) {
            $out['blockiert'] = $e->getMessage();

            return $out;
        }
        $out['weicht_ab'] = $letzte === null || $letzte->inhalt_hash !== $this->inhaltHash($format, $slots, $ziele);
        if ($letzte !== null) {
            $v = app(FassungsVergleich::class);
            $ids = [];
            foreach ($ziele as $c) {
                array_push($ids, ...$v->rezeptIdsDerKette('concept', (int) $c->id));
            }
            $diff = $v->bilderDiff($v->kettenBild('format', (int) $letzte->id), $v->kettenBildAus(array_values(array_unique($ids))));
            $out['allergen_aenderung'] = $diff !== [];
            $out['allergen_felder'] = array_keys($diff);
        }

        return $out;
    }

    /**
     * Je Konzept-Slot die jüngste Konzept-Fassung. Fehlt eine, bricht es mit der Liste ab (D2).
     *
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistFormatSlot>  $slots
     * @return array<int, FoodAlchemistConcept>  Konzept-Arbeitszeile → jüngste Fassung
     */
    private function zieleSammeln($slots): array
    {
        $ziele = [];
        $fehlt = [];
        foreach ($slots as $s) {
            if ($s->concept_id === null || isset($ziele[(int) $s->concept_id])) {
                continue;
            }
            $c = FoodAlchemistConcept::mitFassungen()->find($s->concept_id);
            if ($c === null) {
                continue;   // gelöschtes Konzept fällt weg, wie im Arbeitsstand
            }
            $f = FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $c->id)->where('ist_fassung', true)->whereNull('deleted_at')
                ->orderByDesc('fassung_nr')->orderByDesc('id')->first();
            $f === null ? $fehlt[] = (string) $c->name : $ziele[(int) $s->concept_id] = $f;
        }
        if ($fehlt !== []) {
            $fehlt = array_values(array_unique($fehlt));
            throw new \RuntimeException('Noch nicht veröffentlichbar: Diese Konzepte im Format haben keine veröffentlichte Fassung: '
                .implode(', ', array_slice($fehlt, 0, 10)).(count($fehlt) > 10 ? ' …' : '').'. Zuerst veröffentlichen (Spec 82 Abo-Entwurf D2).');
        }

        return $ziele;
    }

    /** @param array<int, FoodAlchemistConcept> $ziele */
    private function inhaltHash(FoodAlchemistFormat $format, $slots, array $ziele): string
    {
        $kopf = collect($format->getAttributes())->except(array_merge(['id', 'team_id', 'status'], self::NIE_KOPIEREN))
            ->reject(fn ($v, $k) => str_ends_with($k, '_at'))->sortKeys()->all();
        $zeilen = $slots->filter(fn ($s) => $s->concept_id === null || isset($ziele[(int) $s->concept_id]))->map(function (FoodAlchemistFormatSlot $s) use ($ziele) {
            $felder = collect($s->getAttributes())->except(array_merge(self::SLOT_NIE, ['payload_json']))->sortKeys()->all();

            return [$felder, $s->concept_id !== null ? (int) $ziele[(int) $s->concept_id]->id : null, $this->payloadUmgeschluesselt($s, $ziele)];
        })->values()->all();
        $pivots = [
            $format->serviceMoments()->allRelatedIds()->sort()->values()->all(),
            $format->seasons()->allRelatedIds()->sort()->values()->all(),
            $format->targetGroups()->allRelatedIds()->sort()->values()->all(),
        ];

        return hash('sha256', json_encode([$kopf, $zeilen, $pivots], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Wording-Overrides eines Slots (Konzept-Slot-ID → Text) auf die Slots der Konzept-FASSUNG umschlüsseln: der n-te Slot der Arbeitszeile (nach Position, ID) wird der n-te
     * Slot der Fassung. Stimmt die Zahl nicht mehr (unveröffentlichte Änderung am Konzept), entfallen die Overrides, die nicht zuzuordnen sind.
     *
     * @param  array<int, FoodAlchemistConcept>  $ziele
     * @return array<string, mixed>
     */
    private function payloadUmgeschluesselt(FoodAlchemistFormatSlot $s, array $ziele): array
    {
        $payload = (array) ($s->payload_json ?? []);
        if ($s->concept_id === null || ! isset($ziele[(int) $s->concept_id]) || empty($payload['wording_overrides'])) {
            return $payload;
        }
        $alt = DB::table('foodalchemist_concept_slots')->where('concept_id', $s->concept_id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->pluck('id')->values()->all();
        $neu = DB::table('foodalchemist_concept_slots')->where('concept_id', $ziele[(int) $s->concept_id]->id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->pluck('id')->values()->all();
        $umgeschluesselt = [];
        foreach ((array) $payload['wording_overrides'] as $slotId => $text) {
            $i = array_search((int) $slotId, array_map('intval', $alt), true);
            if ($i !== false && isset($neu[$i])) {
                $umgeschluesselt[(string) $neu[$i]] = $text;
            }
        }
        $payload['wording_overrides'] = $umgeschluesselt;

        return $payload;
    }

    /** @param array<int, FoodAlchemistConcept> $ziele */
    private function kopiere(FoodAlchemistFormat $format, $slots, array $ziele, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistFormat
    {
        $fassung = $format->replicate(self::NIE_KOPIEREN);
        $fassung->forceFill([
            'ist_fassung' => true, 'fassung_von_id' => $format->id, 'fassung_nr' => $nr, 'inhalt_hash' => $hash,
            'veroeffentlicht_at' => now(), 'veroeffentlicht_von' => $actor?->id, 'aenderungsnotiz' => $notiz,
        ])->save();
        foreach ($slots as $s) {
            if ($s->concept_id !== null && ! isset($ziele[(int) $s->concept_id])) {
                continue;
            }
            $s->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at'])->forceFill([
                'format_id' => $fassung->id,
                'concept_id' => $s->concept_id !== null ? $ziele[(int) $s->concept_id]->id : null,
                'payload_json' => $this->payloadUmgeschluesselt($s, $ziele) ?: null,
            ])->save();
        }
        $fassung->serviceMoments()->sync($format->serviceMoments()->allRelatedIds()->all());
        $fassung->seasons()->sync($format->seasons()->allRelatedIds()->all());
        $fassung->targetGroups()->sync($format->targetGroups()->allRelatedIds()->all());
        foreach ($format->images()->get() as $bild) {   // Bilder bleiben Verweise auf die Master-Datei (D5)
            $bild->replicate(['uuid'])->forceFill(['format_id' => $fassung->id])->save();
        }

        return $fassung;
    }

    /** Allergen-/Zusatzstoff-Erbe über die Kette: Flag an der Fassung, wenn sich das Bild gegenüber der Vorgängerin ändert. */
    private function markiereAllergenAenderung(FoodAlchemistFormat $fassung, ?FoodAlchemistFormat $vorher): void
    {
        if ($vorher === null) {
            return;
        }
        $v = app(FassungsVergleich::class);
        if ($v->bilderDiff($v->kettenBild('format', (int) $vorher->id), $v->kettenBild('format', (int) $fassung->id)) !== []) {
            $fassung->forceFill(['fassung_allergen_geaendert' => true])->save();
        }
    }
}
