<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;

/**
 * R5 (Dominique): Aufschlagsklassen als EIGENE Settings-Seite, jetzt
 * EDITIERBAR (vorher Lese-Tabelle in der VK-Taxonomie) — Rohaufschlag/
 * Bedienung/Profit/MwSt fließen direkt in den MargeService (GT-8).
 * `formula_type` bleibt auf aufschlag|deckungsbeitrag begrenzt (W-1-Gate:
 * deckungsbeitrag wirft im MargeService, bis die Formel entschieden ist).
 */
class Aufschlagsklassen extends Component
{
    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['code' => '', 'label' => '', 'raw_markup_pct' => '', 'service_pct' => '0', 'profit_pct' => '0', 'vat_rate' => '19', 'formula_type' => 'aufschlag', 'note' => ''];

    public ?string $fehler = null;

    private const PROZENT_FELDER = ['raw_markup_pct', 'service_pct', 'profit_pct', 'vat_rate'];

    public function edit(int $id): void
    {
        $ak = FoodAlchemistMarkupClass::find($id);
        if ($ak === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = [
            'label' => $ak->label,
            'raw_markup_pct' => (string) $ak->raw_markup_pct,
            'service_pct' => (string) $ak->service_pct,
            'profit_pct' => (string) $ak->profit_pct,
            'vat_rate' => (string) $ak->vat_rate,
            'formula_type' => $ak->formula_type,
            'note' => $ak->note,
        ];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        $werte = $this->validiert($this->form);
        if ($werte === null) {
            return;
        }
        FoodAlchemistMarkupClass::findOrFail($this->editId)->update($werte);
        $this->cancel();
        $this->dispatch('recipe-gespeichert');                        // Marge-Anzeigen (Cockpit) neu rechnen
    }

    public function create(): void
    {
        $code = strtoupper(trim($this->neu['code']));
        if ($code === '' || trim($this->neu['label']) === '') {
            $this->fehler = 'Code und Bezeichnung sind Pflicht.';

            return;
        }
        if (FoodAlchemistMarkupClass::where('code', $code)->exists()) {
            $this->fehler = "Code «{$code}» ist schon vergeben.";

            return;
        }
        $werte = $this->validiert($this->neu);
        if ($werte === null) {
            return;
        }
        FoodAlchemistMarkupClass::create($werte + [
            'code' => $code,
            'team_id' => Auth::user()?->currentTeamRelation?->id,
        ]);
        $this->reset('neu', 'fehler');
    }

    public function toggleInactive(int $id): void
    {
        $ak = FoodAlchemistMarkupClass::find($id);
        $ak?->update(['is_inactive' => ! $ak->is_inactive]);
    }

    /** Phase 5: hart löschen, wenn unbenutzt (sonst locked → deaktivieren). */
    public function delete(int $id): void
    {
        $ak = FoodAlchemistMarkupClass::find($id);
        if ($ak === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        if ($ak->team_id !== null && $team !== null && (int) $ak->team_id !== (int) $team->id) {
            $this->fehler = 'Geerbte Aufschlagsklasse — nur das Besitzer-Team kann löschen.';

            return;
        }
        $nRec = DB::table('foodalchemist_recipes')->whereNull('deleted_at')->where('markup_class_id', $id)->count();
        $nCls = DB::table('foodalchemist_dish_classes')->whereNull('deleted_at')->where('default_markup_class_id', $id)->count();
        if ($nRec + $nCls > 0) {
            $this->fehler = "Wird von {$nRec} Gericht(en) + {$nCls} Klasse(n) genutzt — erst umhängen oder deaktivieren.";

            return;
        }
        $ak->delete();
        $this->fehler = null;
    }

    /** Prozente kommasicher parsen + formula_type-Whitelist; null = Fehler gesetzt. */
    private function validiert(array $eingabe): ?array
    {
        $werte = ['label' => trim($eingabe['label'] ?? ''), 'note' => ($eingabe['note'] ?? '') ?: null];
        if ($werte['label'] === '') {
            $this->fehler = 'Bezeichnung ist Pflicht.';

            return null;
        }
        foreach (self::PROZENT_FELDER as $feld) {
            $wert = str_replace(',', '.', trim((string) ($eingabe[$feld] ?? '')));
            if (! is_numeric($wert) || (float) $wert < 0) {
                $this->fehler = "«{$feld}» braucht eine Zahl ≥ 0.";

                return null;
            }
            $werte[$feld] = (float) $wert;
        }
        $werte['formula_type'] = in_array($eingabe['formula_type'] ?? '', ['aufschlag', 'deckungsbeitrag'], true)
            ? $eingabe['formula_type'] : 'aufschlag';

        return $werte;
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.aufschlagsklassen', [
            'klassen' => FoodAlchemistMarkupClass::orderBy('code')->get(),
            'zaehler' => DB::table('foodalchemist_recipes')->whereNull('deleted_at')
                ->whereNotNull('markup_class_id')->selectRaw('markup_class_id, COUNT(*) AS n')
                ->groupBy('markup_class_id')->pluck('n', 'markup_class_id'),
        ]);
    }
}
