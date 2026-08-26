<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\CatalogPricingService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Preisklassen als relative Abweichung vom dynamischen Unternehmens-Basissatz.
 * Legacy-Rohaufschläge bleiben nur als Migrationsquelle in der Datenbank.
 */
class Aufschlagsklassen extends Component
{
    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['code' => '', 'label' => '', 'class_factor_pct' => '100', 'vat_profile_key' => '', 'rounding_decimals' => '', 'rounding_mode' => '', 'note' => ''];

    public ?string $fehler = null;

    /**
     * MVP-039 (P0): eine Klasse ist mutierbar nur fürs Besitzer-Team. Sichtbar (global +
     * Ancestry) genügt zum Lesen/Referenzieren, nie zum Schreiben. `TeamScope::owns()` liefert
     * für globale Zeilen (team_id NULL) korrekt `false` — das schließt das Loch, in dem
     * `delete()` nur Fremd-Teams mit nicht-null team_id blockierte.
     */
    private function eigeneKlasse(int $id): ?FoodAlchemistMarkupClass
    {
        $team = Auth::user()?->currentTeamRelation;
        $ak = TeamScope::applyVisible(FoodAlchemistMarkupClass::query(), 'team_id', $team)->find($id);
        if ($ak === null) {
            $this->fehler = 'Aufschlagsklasse nicht gefunden oder nicht sichtbar.';

            return null;
        }
        if (! TeamScope::owns($ak->team_id, $team)) {
            $this->fehler = 'Geerbte oder globale Aufschlagsklasse — Pflege nur durchs Besitzer-Team (D1).';

            return null;
        }
        $this->fehler = null;

        return $ak;
    }

    public function edit(int $id): void
    {
        // Öffnen nur, wenn speicherbar — sonst zeigt das Formular eine Aktion, die immer scheitert.
        $ak = $this->eigeneKlasse($id);
        if ($ak === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = [
            'label' => $ak->label,
            'class_factor_pct' => (string) ($ak->class_factor_pct ?? 100),
            'vat_profile_key' => (string) ($ak->vat_profile_key ?? ''),
            'rounding_decimals' => $ak->rounding_decimals !== null ? (string) $ak->rounding_decimals : '',
            'rounding_mode' => (string) ($ak->rounding_mode ?? ''),
            'note' => $ak->note,
        ];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        // MVP-039: Owner-Guard auch am Write, nicht nur beim Öffnen — ein manipuliertes editId
        // im Payload kommt hier nicht durch.
        $ak = $this->eigeneKlasse((int) $this->editId);
        if ($ak === null) {
            return;
        }
        $werte = $this->validiert($this->form);
        if ($werte === null) {
            return;
        }
        $ak->update($werte);
        app(PricingCascadeService::class)->recomputeTeam(Auth::user()->currentTeamRelation);
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
        // Unique ist (team_id, code) — die Kollision nur im EIGENEN Team prüfen, sonst blockiert
        // ein fremder/globaler Code das Anlegen (und verrät ihn).
        $teamId = Auth::user()?->currentTeamRelation?->id;
        if (FoodAlchemistMarkupClass::where('team_id', $teamId)->where('code', $code)->exists()) {
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
        app(PricingCascadeService::class)->recomputeTeam(Auth::user()->currentTeamRelation);
        $this->reset('neu', 'fehler');
    }

    public function toggleInactive(int $id): void
    {
        $ak = $this->eigeneKlasse($id);                               // MVP-039
        $ak?->update(['is_inactive' => ! $ak->is_inactive]);
    }

    /** Phase 5: hart löschen, wenn unbenutzt (sonst locked → deaktivieren). */
    public function delete(int $id): void
    {
        // MVP-039: owns()-Guard schließt das team_id-null-Loch (global war vorher löschbar).
        $ak = $this->eigeneKlasse($id);
        if ($ak === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        // MVP-040: Verwendung nur im SICHTBAREN Set zählen (kein Fremd-Team-Leak). `markup_class_id`
        // ist nullOnDelete, deshalb ist die Sperre eine UX-Sicherung, keine FK-Notwendigkeit.
        $nRec = TeamScope::applyVisible(DB::table('foodalchemist_recipes'), 'team_id', $team)
            ->whereNull('deleted_at')->where('markup_class_id', $id)->count();
        $nCls = TeamScope::applyVisible(DB::table('foodalchemist_dish_classes'), 'team_id', $team)
            ->whereNull('deleted_at')->where('default_markup_class_id', $id)->count();
        if ($nRec + $nCls > 0) {
            $this->fehler = "Wird von {$nRec} Gericht(en) + {$nCls} Klasse(n) genutzt — erst umhängen oder deaktivieren.";

            return;
        }
        $ak->delete();
        $this->fehler = null;
    }

    /** Relative Klasse, MwSt-Profil und optionale Rundungsabweichung validieren. */
    private function validiert(array $eingabe): ?array
    {
        $werte = ['label' => trim($eingabe['label'] ?? ''), 'note' => ($eingabe['note'] ?? '') ?: null];
        if ($werte['label'] === '') {
            $this->fehler = 'Bezeichnung ist Pflicht.';

            return null;
        }
        $factor = str_replace(',', '.', trim((string) ($eingabe['class_factor_pct'] ?? '')));
        if (! is_numeric($factor) || (float) $factor <= 0) {
            $this->fehler = 'Der Klassenfaktor muss größer als 0 sein.';

            return null;
        }
        $werte['class_factor_pct'] = (float) $factor;
        $werte['vat_profile_key'] = in_array($eingabe['vat_profile_key'] ?? '', ['regulaer', 'ermaessigt'], true)
            ? $eingabe['vat_profile_key'] : null;
        $decimals = trim((string) ($eingabe['rounding_decimals'] ?? ''));
        $werte['rounding_decimals'] = $decimals === '' ? null : max(0, min(4, (int) $decimals));
        $werte['rounding_mode'] = in_array($eingabe['rounding_mode'] ?? '', ['kaufmaennisch', 'auf', 'ab'], true)
            ? $eingabe['rounding_mode'] : null;

        return $werte;
    }

    public function render()
    {
        // Mandanten-Sichtbarkeit (D1): globaler Seed (team_id NULL) + eigenes Team/Master-Kette.
        $team = Auth::user()?->currentTeamRelation;
        $base = $team !== null ? app(CatalogPricingService::class)->enterpriseBaseRate($team) : null;

        return view('foodalchemist::livewire.settings.aufschlagsklassen', [
            'team' => $team,
            'base' => $base,
            'klassen' => TeamScope::applyVisible(FoodAlchemistMarkupClass::query(), 'team_id', $team)->orderBy('code')->get(),
            // MVP-040: nur sichtbare Gerichte zählen — der Zähler verriet sonst fremde Team-Nutzung.
            'zaehler' => TeamScope::applyVisible(DB::table('foodalchemist_recipes'), 'team_id', $team)
                ->whereNull('deleted_at')->whereNotNull('markup_class_id')
                ->selectRaw('markup_class_id, COUNT(*) AS n')
                ->groupBy('markup_class_id')->pluck('n', 'markup_class_id'),
        ]);
    }
}
