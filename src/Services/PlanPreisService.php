<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanPreis;

/**
 * Spec 82 §13 D/B5 — Gebühren-Preispflege (Variante B). Nur Plattform-Admin schreibt, append-only mit „gültig ab“,
 * jede Änderung geht ins Plattform-Protokoll.
 *
 * Regeln:
 *  - Ein neuer Preis gilt ab einem Datum, das heute oder später ist und NACH dem „gültig ab“ des bisherigen Preises liegt.
 *    Rückwirkend wird nichts geändert: Tageswerte, die schon in team_billable_usages stehen, tragen ihren Preis-Snapshot.
 *  - Die Vorgängerzeile bekommt gültig_bis = neues gültig_ab minus 1 Tag (die einzige erlaubte Änderung an einer alten Zeile).
 *  - Preisschutz für Bestandskunden (§13 F) ist eine offene Entscheidung von Dominique und nicht Teil dieses Dienstes.
 */
class PlanPreisService
{
    use PlattformAdminPruefung;

    public function __construct(private readonly PlattformProtokoll $protokoll) {}

    /** Für lesende Aufrufer (MCP, Oberfläche). */
    public function nurPlattformAdminOeffentlich(?User $user, string $wofuer): User
    {
        return $this->nurPlattformAdmin($user, $wofuer);
    }

    /** Gültiger Monatspreis eines Bausteins am Stichtag; null, wenn es dafür keinen Preis gibt. */
    public function preisAm(string $baustein, Carbon|string $stichtag): ?FoodAlchemistPlanPreis
    {
        $tag = Carbon::parse($stichtag)->toDateString();

        return FoodAlchemistPlanPreis::where('baustein', $baustein)
            ->whereDate('gueltig_ab', '<=', $tag)
            ->where(fn ($q) => $q->whereNull('gueltig_bis')->orWhereDate('gueltig_bis', '>=', $tag))
            ->orderByDesc('gueltig_ab')->orderByDesc('id')->first();
    }

    /**
     * Preise aller Bausteine am Stichtag.
     *
     * @return list<array{baustein:string, label:string, betrag_monat:?string, gueltig_ab:?string}>
     */
    public function aktuell(Carbon|string|null $stichtag = null): array
    {
        $tag = $stichtag ?? now();

        return collect(FoodAlchemistPlanPreis::BAUSTEINE)->map(function ($def, $baustein) use ($tag) {
            $p = $this->preisAm($baustein, $tag);

            return ['baustein' => $baustein, 'label' => $def[0], 'betrag_monat' => $p !== null ? (string) $p->betrag_monat : null, 'gueltig_ab' => $p?->gueltig_ab?->toDateString()];
        })->values()->all();
    }

    /** Alle Zeilen (neueste zuerst), optional eines Bausteins. @return list<array<string,mixed>> */
    public function historie(?string $baustein = null): array
    {
        return FoodAlchemistPlanPreis::query()->when($baustein, fn ($q) => $q->where('baustein', $baustein))
            ->orderBy('baustein')->orderByDesc('gueltig_ab')->orderByDesc('id')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'baustein' => $p->baustein, 'betrag_monat' => (string) $p->betrag_monat, 'gueltig_ab' => $p->gueltig_ab->toDateString(),
                'gueltig_bis' => $p->gueltig_bis?->toDateString(), 'notiz' => $p->aenderungsnotiz, 'geaendert_von' => $p->geaendert_von])->all();
    }

    /**
     * Legt einen neuen Preis an (Plattform-Admin). `$gueltigAb` null = heute.
     *
     * @return array<string,mixed> die neue Zeile
     */
    public function setze(string $baustein, string|float|int $betragMonat, ?string $gueltigAb, ?string $notiz, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Gebühren-Preis pflegen');
        if (! array_key_exists($baustein, FoodAlchemistPlanPreis::BAUSTEINE)) {
            throw new \RuntimeException('Baustein muss basis, user, unterteam, stamm_paket oder master_abo sein.');
        }
        if (! is_numeric($betragMonat) || (float) $betragMonat < 0) {
            throw new \RuntimeException('Der Betrag je Monat muss eine Zahl ab 0 sein.');
        }
        $betrag = number_format((float) $betragMonat, 2, '.', '');
        try {
            $ab = Carbon::parse($gueltigAb ?? now()->toDateString())->startOfDay();
        } catch (\Throwable) {
            throw new \RuntimeException('„Gültig ab“ ist kein Datum.');
        }
        if ($ab->lt(now()->startOfDay())) {
            throw new \RuntimeException('„Gültig ab“ darf nicht in der Vergangenheit liegen, bereits berechnete Tage ändern sich nicht.');
        }

        $zeile = DB::transaction(function () use ($baustein, $betrag, $ab, $notiz, $admin) {
            $letzte = FoodAlchemistPlanPreis::where('baustein', $baustein)->orderByDesc('gueltig_ab')->orderByDesc('id')->first();
            if ($letzte !== null && $ab->lte($letzte->gueltig_ab)) {
                throw new \RuntimeException(sprintf('Für „%s“ gibt es schon einen Preis ab %s. Der neue Preis muss danach gelten.', $baustein, $letzte->gueltig_ab->format('d.m.Y')));
            }
            if ($letzte !== null && ($letzte->gueltig_bis === null || $letzte->gueltig_bis->gte($ab))) {
                $letzte->gueltig_bis = $ab->copy()->subDay()->toDateString();
                $letzte->save();
            }

            return FoodAlchemistPlanPreis::create(['baustein' => $baustein, 'betrag_monat' => $betrag, 'gueltig_ab' => $ab->toDateString(),
                'aenderungsnotiz' => $notiz !== null && trim($notiz) !== '' ? trim($notiz) : null, 'geaendert_von' => $admin->id]);
        });

        $this->protokoll->schreibe('plan_preis_gesetzt', ['baustein' => $baustein, 'betrag_monat' => $betrag, 'gueltig_ab' => $ab->toDateString()], $admin);

        return ['id' => (int) $zeile->id, 'baustein' => $baustein, 'betrag_monat' => $betrag, 'gueltig_ab' => $ab->toDateString()];
    }

    /**
     * Startwerte (Platzhalter) für Bausteine, die noch keinen Preis haben. Idempotent: ein Baustein mit Preis wird nie angefasst.
     *
     * @return list<string> die angelegten Bausteine
     */
    public function startwerteAnlegen(?User $user = null): array
    {
        $angelegt = [];
        foreach (FoodAlchemistPlanPreis::BAUSTEINE as $baustein => $def) {
            if (FoodAlchemistPlanPreis::where('baustein', $baustein)->exists()) {
                continue;
            }
            FoodAlchemistPlanPreis::create(['baustein' => $baustein, 'betrag_monat' => $def[2], 'gueltig_ab' => now()->subDay()->toDateString(),   // gestern: ein heutiger Korrekturpreis darf direkt folgen
                'aenderungsnotiz' => 'Startwert (Platzhalter, Spec 82 §13 D)', 'geaendert_von' => $user?->id]);
            $angelegt[] = $baustein;
        }
        if ($angelegt !== []) {
            $this->protokoll->schreibe('plan_preis_startwerte', ['bausteine' => $angelegt], $user);
        }

        return $angelegt;
    }
}
