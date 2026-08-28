<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * #9 (Dominique 2026-08-28): Naturaleinheit-Formen eines GP (Stück/Scheibe/Würfel … mit Gramm).
 * Basis dafür, dass der Rezept-Einheiten-Dropdown NUR die hinterlegten Formen zeigt und die KI je
 * Form ein Gewicht schätzt. Katalog-Aktion → Curate-Gate (Besitzer-Team, D1). Die Form „stk" wird
 * mit dem Legacy-Feld `piece_default_g` gespiegelt (eine Wahrheit fürs bestehende Stück-Rechnen).
 */
class GpFormService
{
    /** Erlaubte Form-Slugs (Zähl-/Stück-Einheiten; die ersten fünf existieren als Rezept-Einheiten). */
    public const FORM_SLUGS = ['stk', 'scheibe', 'wuerfel', 'streifen', 'blatt', 'ring', 'zehe', 'bund', 'zweig'];

    public function __construct(private AiGatewayService $ki) {}

    /** @return \Illuminate\Support\Collection<int, FoodAlchemistGpForm> */
    public function list(int $gpId): \Illuminate\Support\Collection
    {
        return FoodAlchemistGpForm::where('gp_id', $gpId)->orderBy('form_slug')->get();
    }

    /** Form setzen/aktualisieren (upsert auf gp_id+form_slug). Katalog-Gate. */
    public function setForm(Team $team, int $gpId, string $formSlug, float $gramm, string $source = 'manual'): FoodAlchemistGpForm
    {
        $gp = $this->kuratierbaresGp($team, $gpId);
        $formSlug = mb_strtolower(trim($formSlug));
        if (! in_array($formSlug, self::FORM_SLUGS, true)) {
            throw new \RuntimeException("Unbekannte Form „{$formSlug}\" — erlaubt: " . implode(', ', self::FORM_SLUGS) . '.');
        }
        if ($gramm <= 0) {
            throw new \RuntimeException('Gewicht muss > 0 g sein.');
        }
        $source = in_array($source, ['manual', 'ki'], true) ? $source : 'manual';

        $form = FoodAlchemistGpForm::updateOrCreate(
            ['gp_id' => $gp->id, 'form_slug' => $formSlug],
            ['gramm' => round($gramm, 2), 'source' => $source],
        );

        // „stk" ist gleichzeitig das Legacy-Stückgewicht — spiegeln, damit Picker/Pricing konsistent bleiben.
        if ($formSlug === 'stk') {
            $gp->forceFill(['piece_default_g' => round($gramm, 2), 'piece_default_g_source' => $source])->save();
        }

        return $form;
    }

    /** Form entfernen. Bei „stk" auch das Legacy-Feld leeren. */
    public function removeForm(Team $team, int $gpId, string $formSlug): void
    {
        $gp = $this->kuratierbaresGp($team, $gpId);
        $formSlug = mb_strtolower(trim($formSlug));
        FoodAlchemistGpForm::where('gp_id', $gp->id)->where('form_slug', $formSlug)->delete();
        if ($formSlug === 'stk') {
            $gp->forceFill(['piece_default_g' => null, 'piece_default_g_source' => null])->save();
        }
    }

    /**
     * KI schätzt die anwendbaren Formen + Gramm (gp.zaehl_einheiten) und persistiert sie als source=ki.
     * Override-First (GL-07): manuell gepflegte Formen werden NICHT überschrieben. Gibt die Zahl der
     * geschriebenen Formen zurück.
     */
    public function estimateKi(Team $team, int $gpId): int
    {
        $gp = $this->kuratierbaresGp($team, $gpId);
        $manuell = FoodAlchemistGpForm::where('gp_id', $gp->id)->where('source', 'manual')
            ->pluck('form_slug')->all();

        $vorschlag = $this->ki->propose('gp.zaehl_einheiten', [
            'name' => $gp->name,
            'zustand' => $gp->condition,
            'warengruppe' => $gp->commodity_group_code,
        ]);
        $einheiten = (array) ($vorschlag->werte['einheiten'] ?? []);

        $n = 0;
        foreach ($einheiten as $e) {
            if (! is_array($e)) {
                continue;
            }
            $slug = mb_strtolower(trim((string) ($e['unit'] ?? '')));
            $gramm = (float) ($e['gewicht_g'] ?? 0);
            if (! in_array($slug, self::FORM_SLUGS, true) || $gramm <= 0) {
                continue;
            }
            if (in_array($slug, $manuell, true)) {
                continue;   // Override-First: manuelle Form bleibt unangetastet
            }
            $this->setForm($team, $gp->id, $slug, $gramm, 'ki');
            $n++;
        }

        return $n;
    }

    private function kuratierbaresGp(Team $team, int $gpId): FoodAlchemistGp
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            throw new \RuntimeException('GP nicht gefunden oder kein Zugriff.');
        }
        if (! Curate::canCurate(\Illuminate\Support\Facades\Auth::user(), $gp)) {
            throw new \RuntimeException('Formen pflegen ist Katalog-Aktion — nur fürs Besitzer-Team (D1).');
        }

        return $gp;
    }
}
