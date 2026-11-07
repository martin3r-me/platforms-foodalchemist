<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Support\Curate;
use Platform\FoodAlchemist\Support\Derivat\DerivatArt;
use Platform\FoodAlchemist\Support\Derivat\DerivatAusbeute;

/**
 * Spec 96 · Art und Ausbeute eines Derivats pflegen (GP-Dialog, gps.PUT/POST) und fehlende Ausbeute per KI schätzen
 * (Dominique 06.11.: „KI automatisch“ — Herkunft `ki`, von Hand überschreibbar; manuell gewinnt immer).
 *
 * g_je_stueck wird zusätzlich als Stück-Form („stk“) des Derivats gespiegelt: „Limette (Saft), 1 Stück“ wiegt dann die Ausbeute einer
 * Frucht — Yield, Planungsblatt und Picker rechnen damit wie mit jeder anderen Form. Eine manuelle Stück-Form bleibt unangetastet.
 */
class DerivatAusbeuteService
{
    public function __construct(private AiGatewayService $ki, private GpFormService $formen) {}

    /**
     * @param  array{derivat_art?: ?string, derivat_ausbeute_wert?: float|int|string|null, derivat_ausbeute_basis?: ?string}  $in
     */
    public function setze(Team $team, int $gpId, array $in, string $quelle = 'manual'): FoodAlchemistGp
    {
        $gp = $this->kuratierbaresDerivat($team, $gpId);
        $upd = [];
        if (array_key_exists('derivat_art', $in)) {
            $art = $in['derivat_art'] !== null && $in['derivat_art'] !== '' ? (string) $in['derivat_art'] : null;
            if ($art !== null && ! in_array($art, DerivatArt::ARTEN, true)) {
                throw new \RuntimeException('derivat_art muss nebenprodukt oder zweck sein.');
            }
            $upd['derivat_art'] = $art;
        }
        if (array_key_exists('derivat_ausbeute_wert', $in) || array_key_exists('derivat_ausbeute_basis', $in)) {
            $wert = $in['derivat_ausbeute_wert'] ?? null;
            $wert = $wert === null || $wert === '' ? null : (float) $wert;
            $basis = (string) ($in['derivat_ausbeute_basis'] ?? $gp->derivat_ausbeute_basis ?? DerivatAusbeute::G_JE_STUECK);
            if ($wert !== null) {
                if (! in_array($basis, DerivatAusbeute::BASEN, true)) {
                    throw new \RuntimeException('derivat_ausbeute_basis muss g_je_stueck oder prozent sein.');
                }
                if ($wert <= 0 || ($basis === DerivatAusbeute::PROZENT && $wert > 100)) {
                    throw new \RuntimeException('Die Ausbeute muss größer 0 sein (Prozent höchstens 100).');
                }
            }
            $upd += [
                'derivat_ausbeute_wert' => $wert !== null ? round($wert, 2) : null,
                'derivat_ausbeute_basis' => $wert !== null ? $basis : null,
                'derivat_ausbeute_quelle' => $wert !== null ? (in_array($quelle, DerivatAusbeute::QUELLEN, true) ? $quelle : 'manual') : null,
            ];
        }
        if ($upd === []) {
            return $gp;
        }
        $gp->forceFill($upd)->save();
        if (($upd['derivat_ausbeute_basis'] ?? null) === DerivatAusbeute::G_JE_STUECK) {
            $this->stueckFormSpiegeln($team, $gp, (float) $upd['derivat_ausbeute_wert'], $quelle);
        }

        return $gp->refresh();
    }

    /**
     * KI schätzt die Ausbeute eines Zweck-Derivats. Ein manueller Wert wird nie überschrieben; unsicher (null) → nichts geschrieben,
     * die Reife-Lücke „Ausbeute fehlt“ bleibt.
     *
     * @return array{geschrieben: bool, grund: ?string, wert: ?float, basis: ?string}
     */
    public function schaetzeKi(Team $team, int $gpId): array
    {
        $gp = $this->kuratierbaresDerivat($team, $gpId);
        if ($gp->derivat_ausbeute_quelle === 'manual' && DerivatAusbeute::von($gp) !== null) {
            return ['geschrieben' => false, 'grund' => 'manuell_gepflegt', 'wert' => (float) $gp->derivat_ausbeute_wert, 'basis' => $gp->derivat_ausbeute_basis];
        }
        $mutter = DerivatArt::mutter($gp);
        if ($mutter === null) {
            return ['geschrieben' => false, 'grund' => 'mutter_fehlt', 'wert' => null, 'basis' => null];
        }
        $vorschlag = $this->ki->propose('gp.derivat_ausbeute', [
            'derivat' => $gp->name,
            'derivat_art' => DerivatArt::von($gp, $mutter),
            'mutter' => $mutter->name,
            'mutter_warengruppe' => $mutter->commodity_group_code,
            'mutter_stueckgewicht_g' => $mutter->piece_default_g !== null ? (float) $mutter->piece_default_g : null,
        ]);
        $basis = (string) ($vorschlag->werte['basis'] ?? '');
        $wert = is_numeric($vorschlag->werte['wert'] ?? null) ? (float) $vorschlag->werte['wert'] : null;
        if ($wert === null || $wert <= 0 || ! in_array($basis, DerivatAusbeute::BASEN, true) || ($basis === DerivatAusbeute::PROZENT && $wert > 100)) {
            return ['geschrieben' => false, 'grund' => 'ki_unsicher', 'wert' => null, 'basis' => null];
        }
        $this->setze($team, $gp->id, ['derivat_ausbeute_wert' => $wert, 'derivat_ausbeute_basis' => $basis], 'ki');

        return ['geschrieben' => true, 'grund' => null, 'wert' => round($wert, 2), 'basis' => $basis];
    }

    private function stueckFormSpiegeln(Team $team, FoodAlchemistGp $gp, float $gramm, string $quelle): void
    {
        $manuell = FoodAlchemistGpForm::where('gp_id', $gp->id)->where('form_slug', 'stk')->where('source', 'manual')->exists();
        if ($manuell && $quelle !== 'manual') {
            return;
        }
        $this->formen->setForm($team, (int) $gp->id, 'stk', $gramm, $quelle === 'ki' ? 'ki' : 'manual');
    }

    private function kuratierbaresDerivat(Team $team, int $gpId): FoodAlchemistGp
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht gefunden oder kein Zugriff.');
        }
        if (! $gp->is_derivat) {
            throw new \RuntimeException('Art und Ausbeute gibt es nur an einem Derivat (is_derivat).');
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            throw new \RuntimeException('Derivate pflegt nur das Besitzer-Team des Grundprodukts.');
        }

        return $gp;
    }
}
