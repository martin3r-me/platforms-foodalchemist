<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;

/**
 * Stufe 3 P3.1 — Küchen-Rollen mit Kostensatz pflegen (Küchenchef / Koch / Hilfskoch …).
 *
 * Muster 1:1 wie `Posten`: team-eigene Betriebsstammdaten, `feldSetzen`-Inline-Edit,
 * Lösch-Schutz (nur stilllegen), geerbte Rollen sind Vorlagen (nicht editierbar).
 *
 * ⚠️ Rolle ≠ Mensch. Kein Name einer Person, keine Schicht — nur die Rolle und ihr Satz.
 * Die Posten-Besetzung (Anzahl je Rolle) rechnet daraus Kapazität + Kosten (P3.1 Slice 2).
 */
class Rollen extends Component
{
    /** @var array{name: string, satz: string} */
    public array $neu = ['name' => '', 'satz' => ''];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function create(): void
    {
        $this->fehler = null;
        $this->meldung = null;

        $team = Auth::user()?->currentTeamRelation;
        $name = trim($this->neu['name']);
        if ($team === null || $name === '') {
            $this->fehler = 'Name ist Pflicht.';

            return;
        }

        $slug = Str::slug($name, '_');
        if (FoodAlchemistKitchenRole::where('team_id', $team->id)->where('slug', $slug)->exists()) {
            $this->fehler = "«{$name}» gibt es schon.";

            return;
        }

        FoodAlchemistKitchenRole::create([
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => $name,
            'stundensatz_eur' => $this->satz($this->neu['satz']),
            'sort_order' => (int) FoodAlchemistKitchenRole::where('team_id', $team->id)->max('sort_order') + 10,
        ]);

        $this->neu = ['name' => '', 'satz' => ''];
        $this->meldung = "«{$name}» angelegt.";
    }

    public function feldSetzen(int $id, string $feld, string $wert): void
    {
        $this->fehler = null;
        $this->meldung = null;

        $rolle = $this->eigene($id);
        if ($rolle === null) {
            return;
        }

        match ($feld) {
            'name' => trim($wert) !== '' ? $rolle->name = trim($wert) : $this->fehler = 'Name darf nicht leer sein.',
            'satz' => $rolle->stundensatz_eur = $this->satz($wert),
            default => null,
        };

        if ($this->fehler === null) {
            $rolle->save();
            $this->meldung = 'Gespeichert.';
        }
    }

    public function aktivToggle(int $id): void
    {
        $rolle = $this->eigene($id);
        if ($rolle === null) {
            return;
        }
        $rolle->is_inactive = ! $rolle->is_inactive;
        $rolle->save();
        $this->meldung = $rolle->is_inactive ? "«{$rolle->name}» stillgelegt." : "«{$rolle->name}» wieder aktiv.";
    }

    /** Nur Rollen des EIGENEN Teams sind editierbar — geerbte sind Vorlagen. */
    private function eigene(int $id): ?FoodAlchemistKitchenRole
    {
        $team = Auth::user()?->currentTeamRelation;
        $rolle = $team !== null
            ? FoodAlchemistKitchenRole::where('team_id', $team->id)->find($id)
            : null;

        if ($rolle === null) {
            $this->fehler = 'Rolle nicht im Schreibzugriff.';
        }

        return $rolle;
    }

    /** €/Std robust lesen: leer ⇒ null (= flacher Team-Satz greift), sonst ≥ 0, Komma erlaubt. */
    private function satz(string $roh): ?float
    {
        $roh = trim(str_replace(',', '.', $roh));

        return $roh === '' ? null : max(0.0, (float) $roh);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.rollen', [
            'rollen' => $team !== null
                ? FoodAlchemistKitchenRole::visibleToTeam($team)
                    ->orderBy('sort_order')->orderBy('name')->get()
                : collect(),
            'eigenesTeamId' => $team?->id,
        ]);
    }
}
