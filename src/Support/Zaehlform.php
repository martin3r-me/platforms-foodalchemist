<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;

/**
 * Spec 95c (Pilot-Neuabgleich 03.11., Kimchi „6 Knoblauchzehen“ = 6 ganze Knollen): eine Zählform im Zutatwort („Knoblauchzehe“) ist die
 * EINHEIT der Zeile, nicht die Ware. Der Abgleich sucht die Ware („Knoblauch“), die Zeile bekommt die Form als Einheit, wenn das GP ein
 * Gewicht dafür kennt (GP-Form, Zähl-Default oder Vokabular). Nur echte Zählformen am Wortende — „Beinscheibe“ ist ein Produkt.
 */
final class Zaehlform
{
    /** Wortende → Einheiten-Slug. */
    private const ENDEN = ['zehen' => 'zehe', 'zehe' => 'zehe'];

    /** „Knoblauchzehen“ → „Knoblauch“ (Zutatwort ohne Zählform); unverändert, wenn keine da ist. */
    public static function ohneForm(string $text): string
    {
        return (string) preg_replace_callback('/(\p{L}{4,}?)(' . implode('|', array_keys(self::ENDEN)) . ')(?![\p{L}])/iu',
            fn ($m) => $m[1], $text);
    }

    /** Einheiten-Slug der Zählform im Text (zehe) oder null. */
    public static function formAusText(string $text): ?string
    {
        foreach (self::ENDEN as $ende => $slug) {
            if (preg_match('/\p{L}{4,}' . $ende . '(?![\p{L}])/iu', $text) === 1 || preg_match('/(?<![\p{L}])' . $ende . '(?![\p{L}])/iu', $text) === 1) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Zeile mit GP und Einheit Stück, deren Text eine Zählform nennt → Einheit auf die Form, wenn ihr Gewicht bekannt ist; sonst unverändert.
     *
     * @param  array{gp_id?: ?int, unit_vocab_id?: int}  $zeile
     */
    public static function einheitAnpassen(Team $team, array $zeile, string $text): array
    {
        $form = self::formAusText($text);
        $gpId = (int) ($zeile['gp_id'] ?? 0);
        if ($form === null || $gpId === 0 || FoodAlchemistVocabEinheit::find((int) ($zeile['unit_vocab_id'] ?? 0))?->slug !== 'stk') {
            return $zeile;
        }
        $einheit = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $form)->where('is_inactive', false)->first();
        if ($einheit === null) {
            return $zeile;
        }
        $gewicht = $einheit->default_in_g !== null
            || DB::table('foodalchemist_gp_forms')->where('gp_id', $gpId)->where('form_slug', $form)->whereNull('deleted_at')->exists()
            || DB::table('foodalchemist_gp_count_unit_defaults')->where('gp_id', $gpId)->where('unit_vocab_id', $einheit->id)->whereNull('deleted_at')->exists();

        return $gewicht ? ['unit_vocab_id' => (int) $einheit->id] + $zeile : $zeile;
    }
}
