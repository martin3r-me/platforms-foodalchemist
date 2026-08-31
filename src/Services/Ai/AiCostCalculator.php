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

    public function currency(): string
    {
        return config('foodalchemist.ai.usd_eur') ? 'EUR' : 'USD';
    }

    public function displayCost(?float $usd): ?float
    {
        if ($usd === null) {
            return null;
        }

        $rate = config('foodalchemist.ai.usd_eur');

        return $rate ? $usd * (float) $rate : $usd;
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
