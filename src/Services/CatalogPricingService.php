<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/** Mengenunabhängige Preisfindung für eine Darreichung. Produktionszeiten sind hier verboten. */
class CatalogPricingService
{
    public const VERSION = 'catalog-v2.2';

    public function __construct(
        private TeamSettingsService $settings,
        private FixkostenService $fixkosten,
    ) {
    }

    /** @return array{factor:?float,source:?string,components:array,warnings:list<string>,complete:bool} */
    public function enterpriseBaseRate(Team $team): array
    {
        $bases = $this->settings->bezugsbasen($team);
        $schema = array_values(array_filter(
            $this->fixkosten->aufgeloestesSchema($team),
            fn (array $block) => (bool) ($block['active'] ?? false),
        ));
        $sum = fn (string $type): float => array_sum(array_map(
            fn (array $block) => ($block['type'] ?? null) === $type ? (float) ($block['value'] ?? 0) / 100 : 0.0,
            $schema,
        ));

        $mek = (float) $bases['mek'];
        $fek = (float) $bases['fek'];
        $hk = (float) $bases['hk'];
        $warnings = [];

        if ($mek > 0 && $fek > 0 && $hk > 0) {
            $fekRatio = $fek / $mek;
            $directRatio = max(0.0, ($hk - $mek - $fek) / $mek);
            $mgkRatio = $sum('pct_mek');
            $fgkRatio = $fekRatio * $sum('pct_fek');
            $normHk = 1 + $fekRatio + $directRatio + $mgkRatio + $fgkRatio;
            $normHk2 = $normHk * (1 + $sum('pct_hk'));
            $factor = $normHk2 * (1 + $this->settings->margePct($team) / 100);

            return [
                'factor' => round($factor, 6),
                'source' => 'kostenstruktur',
                'components' => compact('fekRatio', 'directRatio', 'mgkRatio', 'fgkRatio', 'normHk', 'normHk2')
                    + ['profit_markup_pct' => $this->settings->margePct($team), 'bases' => $bases],
                'warnings' => [],
                'complete' => true,
            ];
        }

        $target = $this->settings->zielWareneinsatzPct($team);
        if ($target > 0) {
            $warnings[] = 'Monatsbasen unvollständig: Basissatz aus Ziel-Wareneinsatzquote abgeleitet.';

            return [
                'factor' => round(100 / $target, 6),
                'source' => 'ziel_we_fallback',
                'components' => ['target_food_cost_pct' => $target, 'bases' => $bases],
                'warnings' => $warnings,
                'complete' => true,
            ];
        }

        return [
            'factor' => null,
            'source' => null,
            'components' => ['bases' => $bases],
            'warnings' => ['Weder belastbare Monatsbasen noch eine gültige Ziel-Wareneinsatzquote vorhanden.'],
            'complete' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function catalogPrice(Team $team, FoodAlchemistRecipeDarreichung $presentation): array
    {
        $presentation->loadMissing('markupClass', 'recipe.markupClass');
        $base = $this->enterpriseBaseRate($team);
        $class = $presentation->markupClass;
        $classSource = $class !== null ? 'darreichung' : null;
        if ($class === null && $presentation->recipe?->markupClass !== null) {
            $class = $presentation->recipe->markupClass;
            $classSource = 'gericht';
        }
        if ($class === null && ($defaultId = $this->settings->defaultMarkupClassId($team)) !== null) {
            $class = FoodAlchemistMarkupClass::visibleToTeam($team)
                ->whereKey($defaultId)->where('is_inactive', false)->first();
            $classSource = $class !== null ? 'team_standard' : null;
        }
        $classFactor = $class !== null ? (float) ($class->class_factor_pct ?? 100) : 100.0;
        $mek = $presentation->ek_portion !== null ? (float) $presentation->ek_portion : null;
        $warnings = $base['warnings'];
        if ($class === null) {
            $warnings[] = 'Keine Preisklasse und kein Team-Standard gesetzt: neutraler Klassenfaktor 100 % verwendet.';
        }
        if ($mek === null || $mek <= 0) {
            $warnings[] = 'Darreichungs-MEK fehlt; es wird kein Nullpreis erzeugt.';
        }

        $unrounded = $mek !== null && $mek > 0 && $base['factor'] !== null
            ? $mek * $base['factor'] * $classFactor / 100
            : null;
        $rounding = $this->settings->rundung($team);
        $decimals = $class?->rounding_decimals !== null
            ? (int) $class->rounding_decimals
            : (int) ($rounding['nachkommastellen'] ?? 2);
        $mode = $class?->rounding_mode ?: ($rounding['mode'] ?? 'kaufmaennisch');
        $calculated = $unrounded !== null ? $this->roundPrice($unrounded, $decimals, $mode) : null;

        $expired = $presentation->price_override_expires_at !== null
            && $presentation->price_override_expires_at->isPast();
        $fixed = in_array($presentation->price_mode, ['fixed', 'manuell'], true) && ! $expired;
        $effective = $fixed ? ($presentation->sales_net !== null ? (float) $presentation->sales_net : null) : $calculated;

        $vatDefaults = $this->settings->mwst($team);
        $vatKey = $presentation->vat_profile_key ?: ($class?->vat_profile_key ?: $vatDefaults['default_satz']);
        if (! in_array($vatKey, ['regulaer', 'ermaessigt'], true)) {
            $vatKey = $vatDefaults['default_satz'];
        }
        $vatRate = (float) ($vatDefaults[$vatKey] ?? 0);

        return [
            'mek' => $mek,
            'base_factor' => $base['factor'],
            'base_source' => $base['source'],
            'base_components' => $base['components'],
            'class_id' => $class?->id,
            'class_source' => $classSource ?? 'neutral',
            'class_factor_pct' => $classFactor,
            'calculated_sales_net_unrounded' => $unrounded !== null ? round($unrounded, 6) : null,
            'calculated_sales_net' => $calculated,
            'sales_net' => $effective,
            'price_mode' => $fixed ? 'fixed' : 'auto',
            'override_expired' => $expired,
            'vat_profile_key' => $vatKey,
            'vat_rate' => $vatRate,
            'sales_gross' => $effective !== null ? round($effective * (1 + $vatRate / 100), 2) : null,
            'rounding' => ['decimals' => $decimals, 'mode' => $mode],
            'calculation_version' => self::VERSION,
            'warnings' => $warnings,
            'complete' => $calculated !== null,
        ];
    }

    private function roundPrice(float $value, int $decimals, string $mode): float
    {
        $factor = 10 ** max(0, min(4, $decimals));

        return match ($mode) {
            'auf' => ceil($value * $factor - 1e-9) / $factor,
            'ab' => floor($value * $factor + 1e-9) / $factor,
            default => round($value, $decimals, PHP_ROUND_HALF_UP),
        };
    }
}
