<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\Hochrechnung;

/**
 * #1003 Hochrechnen am Basisrezept (Rezept-Detail): „auf ___ kg" oder „nach Zutat", Rechnung über Support\Hochrechnung
 * (dieselbe wie das Dokument). Am Computer sind die hochgerechneten Mengen Eingabefelder (Dominique 30.10.): der Koch
 * korrigiert das Verhältnis im großen Maßstab („bei 50 kg 4 kg Zwiebeln statt 2"), „Ins Rezept übernehmen" rechnet
 * die geänderten Zeilen durch den Faktor auf den Ansatz zurück. Der Ansatz bleibt, nur die Verhältnisse ändern sich.
 * Am Handy reine Ansicht. Die Komponente ruft hochVorbelegen() beim Rezeptwechsel und liefert $recipeId.
 */
trait HatHochrechner
{
    /** Ziel in kg (Eingabe, vorbelegt mit dem Ansatz). */
    public string $hochZielKg = '';

    /** „Nach Zutat": gewählte Zeile und „davon habe ich" in deren Einheit. */
    public ?int $hochZutatId = null;

    public string $hochHabe = '';

    /** @var array<string, string> Eingabefelder der hochgerechneten Mengen: „z{id}" => Zahl in der Anzeige-Einheit */
    public array $hochNeu = [];

    /** Feld = Ansatz, kein Faktor, keine Eingaben (Rezeptwechsel, Zurücksetzen, nach dem Übernehmen). */
    private function hochVorbelegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null
            ? FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId, ['id', 'yield_kg', 'yield_kg_manual'])
            : null;
        $ansatz = $rezept !== null ? Hochrechnung::ansatzKg($rezept) : null;
        $this->hochZielKg = $ansatz !== null ? Hochrechnung::eingabeText($ansatz) : '';
        $this->hochZutatId = null;
        $this->hochHabe = '';
        $this->hochNeu = [];
    }

    private function hochRezept(): ?FoodAlchemistRecipe
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null && $this->recipeId !== null ? app(RecipeService::class)->detail($team, $this->recipeId) : null;
    }

    /** Eingabe in kg beendet „nach Zutat"; neuer Faktor = neue Vorbelegung der Felder. */
    public function updatedHochZielKg(): void
    {
        $this->hochZutatId = null;
        $this->hochHabe = '';
        $this->hochFelderFuellen();
    }

    public function hochNachZutat(int $zutatId): void
    {
        $this->hochVorbelegen();   // Feld zurück auf den Ansatz, bis „davon habe ich" einen Faktor ergibt
        $this->hochZutatId = $zutatId;
    }

    /** „Davon habe ich" → das kg-Feld zeigt die Menge, die es ergibt (sonst stünde dort ein alter Wert). */
    public function updatedHochHabe(): void
    {
        $rezept = $this->hochRezept();
        $ziel = $rezept !== null ? $this->hochrechnung($rezept)['ziel_kg'] : null;
        if ($ziel !== null) {
            $this->hochZielKg = Hochrechnung::eingabeText($ziel);
        }
        $this->hochFelderFuellen();
    }

    public function hochZuruecksetzen(): void
    {
        $this->hochVorbelegen();
    }

    /** „Änderungen zurücknehmen": Felder wieder auf die hochgerechneten Mengen, Faktor bleibt. Schreibt nichts. */
    public function hochAenderungenZuruecknehmen(): void
    {
        $this->hochFelderFuellen();
    }

    /** Eingabefelder = hochgerechnete Mengen. Ein neuer Faktor verwirft Eingaben (sie gehörten zum alten Maßstab). */
    private function hochFelderFuellen(): void
    {
        $this->hochNeu = [];
        $rezept = HandyLesemodus::anfrageVomHandy() ? null : $this->hochRezept();   // am Handy gibt es keine Felder
        $f = $rezept !== null ? $this->hochFaktor($rezept)['faktor'] : null;
        if ($f === null || abs($f - 1) < 0.0005) {
            return;
        }
        foreach ($rezept->ingredients as $z) {
            $this->hochNeu['z' . $z->id] = Hochrechnung::eingabeText(Hochrechnung::anzeige((float) $z->quantity * $f, $z->unit)['wert']);
        }
    }

    /** @return array{faktor: ?float, hinweis: ?string, zutat: mixed, ansatz: ?float} */
    private function hochFaktor(FoodAlchemistRecipe $rezept): array
    {
        $ansatz = Hochrechnung::ansatzKg($rezept);
        $zutat = $this->hochZutatId !== null ? $rezept->ingredients->firstWhere('id', $this->hochZutatId) : null;
        $r = $zutat !== null
            ? Hochrechnung::faktor(Hochrechnung::zahl($this->hochHabe), $zutat->quantity !== null && (float) $zutat->quantity > 0 ? (float) $zutat->quantity : null, Hochrechnung::OHNE_ZUTATMENGE)
            : Hochrechnung::faktor(Hochrechnung::zahl($this->hochZielKg), $ansatz);

        return $r + ['zutat' => $zutat, 'ansatz' => $ansatz];
    }

    /**
     * Faktor, Anzeige-Mengen und geänderte Zeilen. aktiv nur bei einem Faktor ≠ 1 — sonst zeigt die Liste die
     * Rezeptmengen und es gibt keine Felder (bei ×1 ist der Zutaten-Editor der Weg).
     * speichern: nur geänderte Zeilen, Eingabe × je ÷ Faktor in der gespeicherten Einheit, mit der Speicher-Rundung;
     * eine Spanne (quantity_max) verschiebt sich im selben Verhältnis.
     */
    private function hochrechnung(FoodAlchemistRecipe $rezept): array
    {
        $basis = $this->hochFaktor($rezept);
        $f = $basis['faktor'];
        $aktiv = $f !== null && abs($f - 1) >= 0.0005;
        $ansatz = $basis['ansatz'];
        $text = fn (array $a) => Hochrechnung::zahlText($a['wert']) . ' ' . $a['einheit'];

        $mengen = $felder = $speichern = $aenderungen = $namen = $ohneMenge = [];
        foreach ($rezept->ingredients as $z) {
            $name = $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text;
            $menge = (float) $z->quantity;
            if ($aktiv) {
                $a = Hochrechnung::anzeige($menge * $f, $z->unit);
                $max = $z->quantity_max !== null ? $text(Hochrechnung::anzeige((float) $z->quantity_max * $f, $z->unit)) : null;
                $mengen[$z->id] = $text($a) . ($max !== null ? ' – ' . $max : '');
                $felder[$z->id] = ['einheit' => $a['einheit'], 'max' => $max];

                $eingabe = $this->hochNeu['z' . $z->id] ?? null;
                if ($eingabe !== null && abs(Hochrechnung::zahl($eingabe) - $a['wert']) >= 0.0005) {
                    $neu = Hochrechnung::speicherWert(Hochrechnung::zahl($eingabe) * $a['je'] / $f, $z->unit);
                    $speichern[$z->id] = [
                        'quantity' => $neu['wert'],
                        'quantity_max' => $z->quantity_max !== null && $menge > 0
                            ? Hochrechnung::speicherWert((float) $z->quantity_max * $neu['wert'] / $menge, $z->unit)['wert'] : $z->quantity_max,
                    ];
                    $e = Hochrechnung::einheit($z->unit);
                    $aenderungen[$z->id] = Hochrechnung::zahlText($menge) . ' ' . $e . ' → ' . Hochrechnung::zahlText($neu['wert']) . ' ' . $e
                        . ($neu['pruefen'] ? ' (nicht gerundet, bitte prüfen)' : '');
                    $namen[$z->id] = $name;
                    $menge = $neu['wert'];
                }
            }
            if ($menge <= 0) {
                $ohneMenge[] = $name;   // syncIngredients verlangt je Zeile eine Menge > 0 (wie der Zutaten-Editor)
            }
        }

        $zielKg = $aktiv && $ansatz !== null ? round($ansatz * $f, 3) : null;
        $n = count($aenderungen);
        $bestaetigung = $n === 0 ? null
            : $n . ($n === 1 ? ' Zutat' : ' Zutaten') . ' ändern ('
                . ($zielKg !== null ? 'von ' . Hochrechnung::zahlText($zielKg) . ' kg auf den Ansatz ' . Hochrechnung::zahlText($ansatz) . ' kg' : 'durch den Faktor ' . Hochrechnung::faktorText($f))
                . " zurückgerechnet):\n– " . implode("\n– ", array_map(fn ($id) => $namen[$id] . ' ' . $aenderungen[$id], array_keys($aenderungen)))
                . ($rezept->yield_kg_manual !== null ? "\n\nAusbeute ist von Hand eingetragen (" . Hochrechnung::zahlText((float) $rezept->yield_kg_manual) . ' kg) und bleibt so – bitte prüfen, ob sie noch stimmt.' : '');

        return [
            'aktiv' => $aktiv,
            'faktor' => $aktiv ? $f : null,
            'faktor_text' => $aktiv ? Hochrechnung::faktorText($f) : null,
            'hinweis' => $basis['hinweis'],
            'ansatz_kg' => $ansatz,
            'ziel_kg' => $zielKg,
            'zutat' => $basis['zutat'],
            'mengen' => $mengen,
            'felder' => $felder,
            'speichern' => $speichern,
            'aenderungen' => $aenderungen,
            'ohne_menge' => $ohneMenge,
            'bestaetigung' => $bestaetigung,
        ];
    }

    /**
     * „Ins Rezept übernehmen" (nur am Computer): geänderte Zeilen zurückgerechnet speichern, alle anderen Zeilen
     * unverändert (keine neue Rundung). Kein eigener Speicherweg: RecipeService::syncIngredients mit dem vollständigen
     * Bestand wie der Zutaten-Editor (D1-Besitzer-Prüfung, Recompute inkl. Eltern). Ausbeute (yield_kg_manual) bleibt.
     * Am Handy gesperrt (nicht in handyLeseMethoden), mit Bearbeitungssperre nur nach „Bearbeiten" (nicht in sperrFreiExtra).
     */
    public function hochUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $this->hochRezept();
        if ($team === null || $rezept === null || $rezept->is_sales_recipe) {
            return;
        }
        $hoch = $this->hochrechnung($rezept);
        $fehler = match (true) {
            ! $hoch['aktiv'] => 'Kein Faktor — erst hochrechnen.',
            $hoch['speichern'] === [] => 'Keine Menge geändert — nichts zu übernehmen.',
            $hoch['ohne_menge'] !== [] => 'Zeilen ohne Menge (' . implode(', ', $hoch['ohne_menge']) . ') — bitte erst eintragen.',
            default => null,
        };
        if ($fehler !== null) {
            $this->dispatch('fa-saved', message: $fehler, type: 'error');

            return;
        }

        $zeilen = $rezept->ingredients->map(fn ($z) => [
            'id' => $z->id, 'gp_id' => $z->gp_id, 'referenced_recipe_id' => $z->referenced_recipe_id,
            'raw_text' => $z->raw_text, 'display_name' => $z->display_name,
            'quantity' => $hoch['speichern'][$z->id]['quantity'] ?? $z->quantity,   // unveränderte Zeilen wie gespeichert
            'quantity_max' => isset($hoch['speichern'][$z->id]) ? $hoch['speichern'][$z->id]['quantity_max'] : $z->quantity_max,
            'unit_vocab_id' => $z->unit_vocab_id, 'cooking_loss_pct' => $z->cooking_loss_pct,
            'cooking_loss_source' => $z->cooking_loss_source, 'trimming_loss_pct' => $z->trimming_loss_pct,
            'is_optional' => $z->is_optional, 'note' => $z->note, 'role' => $z->role, 'role_source' => $z->role_source,
            'is_value_relevant' => $z->is_value_relevant,
            'auto_ground' => false,   // nur Mengen ändern — offene Zeilen nicht nebenbei neu zuordnen
        ])->all();

        try {
            app(RecipeService::class)->syncIngredients($team, $rezept->id, $zeilen);
        } catch (\RuntimeException $e) {
            $this->dispatch('fa-saved', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->hochVorbelegen();   // zurück auf ×1: die Liste zeigt die gespeicherten Standardmengen
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('kosten-aktualisiert', recipe_id: $rezept->id, ids: app(RecipeRecomputeService::class)->betroffeneRezepte($rezept->id));
        $this->dispatch('fa-saved', message: 'Mengen ins Rezept übernommen');
    }
}
