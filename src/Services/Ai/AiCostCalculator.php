<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * Modellbasierte Kostenschätzung für das Food-Alchemist-Audit-Log.
 *
 * Die Provider-Antwort ist die Wahrheit für das tatsächlich verwendete Modell;
 * der fachliche Tier ist nur Routing-Metadatum und darf nicht als Preisschlüssel
 * dienen. Preise stehen in USD, wie OpenAI sie veröffentlicht.
 */
class AiCostCalculator
{
    /** @param object{feature:string,model:?string,calls:int|string,t_in:int|string,t_cached?:int|string|null,t_out:int|string} $row */
    public function costUsd(object $row): ?float
    {
        $model = trim((string) ($row->model ?? ''));

        $bildpreis = $this->imagePriceUsd($model, (string) $row->feature);
        if ($bildpreis !== null) {
            return (float) $row->calls * $bildpreis;
        }

        $preise = $this->textPricesFor($model);
        if ($preise === null) {
            return null;
        }

        $input = max(0, (int) $row->t_in);
        $cached = min($input, max(0, (int) ($row->t_cached ?? 0)));
        $output = max(0, (int) $row->t_out);

        return (($input - $cached) * (float) $preise['in']
            + $cached * (float) ($preise['cached_in'] ?? $preise['in'])
            + $output * (float) $preise['out']) / 1_000_000;
    }

    /**
     * Preis und Kurs für EINEN Aufruf, so wie sie beim Schreiben des Logs gelten (eingefroren: spätere Preisänderungen betreffen nur neue Aufrufe).
     * Modell ohne Preis → alle Felder null (nie 0): die Auswertung weist diese Aufrufe als „ohne Preis“ aus, statt sie still als gratis zu zählen.
     *
     * @return array{kosten_usd: ?float, preis_in_mio: ?float, preis_cached_mio: ?float, preis_out_mio: ?float, usd_eur: ?float}
     */
    public function snapshotFuerAufruf(string $feature, ?string $model, int $tokensIn, int $tokensCached, int $tokensOut): array
    {
        $model = trim((string) $model);
        $kurs = $this->kurs();
        $leer = ['kosten_usd' => null, 'preis_in_mio' => null, 'preis_cached_mio' => null, 'preis_out_mio' => null, 'usd_eur' => $kurs];

        $bild = $this->imagePriceUsd($model, $feature);
        if ($bild !== null) {
            return ['kosten_usd' => $bild] + $leer;
        }

        $preise = $this->textPricesFor($model);
        if ($preise === null) {
            return $leer;
        }

        $usd = $this->costUsd((object) ['feature' => $feature, 'model' => $model, 'calls' => 1,
            't_in' => $tokensIn, 't_cached' => $tokensCached, 't_out' => $tokensOut]);

        return [
            'kosten_usd' => $usd,
            'preis_in_mio' => (float) $preise['in'],
            'preis_cached_mio' => (float) ($preise['cached_in'] ?? $preise['in']),
            'preis_out_mio' => (float) $preise['out'],
            'usd_eur' => $kurs,
        ];
    }

    /**
     * EUR je 1 USD — EINE Quelle für Snapshot je Aufruf, Anzeige und KI-Budget: neuester EZB-Tageskurs, sonst env
     * (foodalchemist.ai.usd_eur). Ein fehlender Kurs blockiert nie einen KI-Aufruf ({@see WechselkursService}).
     */
    public function kurs(): ?float
    {
        return app(WechselkursService::class)->usdEur();
    }

    public function currency(): string
    {
        return $this->kurs() !== null ? 'EUR' : 'USD';
    }

    public function displayCost(?float $usd): ?float
    {
        if ($usd === null) {
            return null;
        }

        $rate = $this->kurs();

        return $rate !== null ? $usd * $rate : $usd;
    }

    public function symbol(): string
    {
        return $this->currency() === 'EUR' ? '€' : '$';
    }

    /** @return array{in:float,cached_in?:float,out:float}|null */
    private function textPricesFor(string $model): ?array
    {
        if ($model === '') {
            return null;
        }

        foreach (config('foodalchemist.ai.modellkosten_pro_mio_usd', []) as $prefix => $preise) {
            if ($model === $prefix || str_starts_with($model, $prefix.'-')) {
                return is_array($preise) ? $preise : null;
            }
        }

        return null;
    }

    private function imagePriceUsd(string $model, string $feature): ?float
    {
        $featureOverrides = config('foodalchemist.ai.bildkosten_usd.features', []);
        if (array_key_exists($feature, $featureOverrides)) {
            return (float) $featureOverrides[$feature];
        }

        foreach (config('foodalchemist.ai.bildkosten_usd.models', []) as $prefix => $preis) {
            if ($model === $prefix || str_starts_with($model, $prefix.'-')) {
                return (float) $preis;
            }
        }

        return null;
    }
}
