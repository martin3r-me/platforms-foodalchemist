<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
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
    /**
     * B (2026-09-04): Verpackungs-Einheiten — für sie gibt es KEIN Naturalgewicht am
     * Grundprodukt. Eine Flasche Öl hat beim einen Lieferanten 0,75 l, beim anderen 1 l;
     * das Gewicht hängt am Gebinde des Lieferantenartikels, nicht am Produkt (GP-Regelwerk
     * §7.1 verbietet Verpackungsangaben am GP aus demselben Grund). „portion" ist eine
     * Verkaufs-, keine Einkaufsgrösse. Für diese darf die KI nichts schätzen.
     */
    public const VERPACKUNGS_SLUGS = [
        'beutel', 'dose', 'dosen', 'eimer', 'flasche', 'glas', 'kanister', 'kapsel',
        'karton', 'pck', 'portion', 'schale', 'schalen', 'zaepfle',
    ];

    /**
     * Rückfall, wenn das Vokabular nicht lesbar ist (kein DB-Kontext beim Schema-Aufbau)
     * oder leer antwortet. Bewusst der Alt-Stand: nichts kaputt, nur weniger.
     */
    public const FALLBACK_SLUGS = ['stk', 'scheibe', 'wuerfel', 'streifen', 'blatt', 'ring', 'zehe', 'bund', 'zweig'];

    /**
     * Erlaubte Form-Slugs = alle aktiven ZÄHL-Einheiten des Vokabulars minus Verpackung.
     *
     * Vorher eine handgepflegte Neuner-Liste — und die war von der Wirklichkeit abgekoppelt:
     * das Vokabular führt 35 Zähl-Einheiten, im Einsatz waren u. a. beet/hände/fäden/zweige,
     * für die der Editor kein Gewicht annahm (und die KI darum nichts schätzen konnte),
     * während `blatt` und `ring` in der Liste standen, ohne als Einheit zu existieren.
     * Zwei Listen, die dasselbe meinen, driften auseinander — dieselbe Fehlerklasse, die
     * heute schon Preis- und Gewichts-Kaskade gekostet hat. Darum: EINE Quelle, das Vokabular.
     *
     * @return list<string>
     */
    public static function formSlugs(?Team $team = null): array
    {
        try {
            $slugs = FoodAlchemistVocabEinheit::query()
                ->when($team, fn ($q) => $q->visibleToTeam($team))
                ->where('is_inactive', false)
                ->where('dimension', 'count')
                ->whereNotIn('slug', self::VERPACKUNGS_SLUGS)
                ->orderBy('slug')
                ->pluck('slug')
                ->all();
        } catch (\Throwable) {
            // MCP-Tool-Schemas rufen das beim Registry-Aufbau — auch in Kontexten ohne
            // (migrierte) DB, z. B. `migrate` auf einer frischen Instanz. Dort darf ein
            // Enum-Schema nicht die halbe Anwendung mitreissen.
            return self::FALLBACK_SLUGS;
        }

        return $slugs !== [] ? $slugs : self::FALLBACK_SLUGS;
    }

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
        $erlaubt = self::formSlugs($team);
        if (! in_array($formSlug, $erlaubt, true)) {
            throw new \RuntimeException("Unbekannte Form „{$formSlug}\" — erlaubt: " . implode(', ', $erlaubt) . '.');
        }
        if ($gramm <= 0) {
            throw new \RuntimeException('Gewicht muss > 0 g sein.');
        }
        $source = in_array($source, ['manual', 'ki'], true) ? $source : 'manual';

        // withTrashed + restore: mit SoftDeletes würde ein zuvor entfernter Slug den unique(gp_id,form_slug)
        // blockieren (updateOrCreate sieht die trashed-Zeile nicht → INSERT → Kollision). Re-Add = Reaktivierung.
        $form = FoodAlchemistGpForm::withTrashed()->firstOrNew(['gp_id' => $gp->id, 'form_slug' => $formSlug]);
        $form->fill(['gramm' => round($gramm, 2), 'source' => $source]);
        $form->deleted_at = null;
        $form->save();

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

        $erlaubt = self::formSlugs($team);
        $vorschlag = $this->ki->propose('gp.zaehl_einheiten', [
            'name' => $gp->name,
            'zustand' => $gp->condition,
            'warengruppe' => $gp->commodity_group_code,
            'erlaubte_einheiten' => $erlaubt,                      // B: Katalog = Vokabular, nicht Prompt-Text
        ]);
        $einheiten = (array) ($vorschlag->werte['einheiten'] ?? []);

        $n = 0;
        foreach ($einheiten as $e) {
            if (! is_array($e)) {
                continue;
            }
            $slug = mb_strtolower(trim((string) ($e['unit'] ?? '')));
            $gramm = (float) ($e['gewicht_g'] ?? 0);
            if (! in_array($slug, $erlaubt, true) || $gramm <= 0) {
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
