<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

/**
 * Spec 30 E3 — Posten (Küchen-Arbeitsplätze) pflegen.
 *
 * Bewusst NICHT in die `Behaelter::VOKABULARE`-Whitelist gehängt: das ist ein uniformer
 * `DB::table`-Pfad für Vokabulare und verträgt weder den Wochentag-Editor noch die
 * Team-Pflicht. Posten sind Betriebsstammdaten, keine Vokabel — siehe Migrations-Docblock.
 *
 * ⚠️ Wir pflegen ARBEITSPLÄTZE, keine Menschen. Kapazität ist NETTO (Rüsten/Reinigen/Pause
 * abgezogen) und optional: ohne Zahl warnt der Posten nie. Das ist der Anti-Nerv-Schalter —
 * wer keine Kapazität pflegt, merkt vom ganzen Feature nichts außer der Minutensumme.
 *
 * Lösch-Schutz V-06: nur deaktivieren. Zuteilungen an Auftragszeilen sollen nicht ins Leere
 * laufen, nur weil jemand einen Posten aufräumt.
 */
class Posten extends Component
{
    /** @var array{name: string, group_name: string, kapazitaet: string} */
    public array $neu = ['name' => '', 'group_name' => '', 'kapazitaet' => ''];

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
        if (FoodAlchemistProductionStation::where('team_id', $team->id)->where('slug', $slug)->exists()) {
            $this->fehler = "«{$name}» gibt es schon.";

            return;
        }

        FoodAlchemistProductionStation::create([
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => $name,
            'group_name' => trim($this->neu['group_name']) ?: null,
            'kapazitaet_min_pro_tag' => $this->minuten($this->neu['kapazitaet']),
            'sort_order' => (int) FoodAlchemistProductionStation::where('team_id', $team->id)->max('sort_order') + 10,
        ]);

        $this->reset('neu');
        $this->neu = ['name' => '', 'group_name' => '', 'kapazitaet' => ''];
        $this->meldung = "«{$name}» angelegt.";
    }

    public function feldSetzen(int $id, string $feld, string $wert): void
    {
        $this->fehler = null;
        $this->meldung = null;

        $posten = $this->eigener($id);
        if ($posten === null) {
            return;
        }

        match ($feld) {
            'name' => trim($wert) !== '' ? $posten->name = trim($wert) : $this->fehler = 'Name darf nicht leer sein.',
            'group_name' => $posten->group_name = trim($wert) ?: null,
            'kapazitaet' => $posten->kapazitaet_min_pro_tag = $this->minuten($wert),
            'schicht' => $posten->schicht_minuten = $this->minuten($wert),                 // Stufe 3
            'batch_max_kg' => $posten->batch_max_kg = $this->zahl($wert),                    // Stufe 3 (Topf)
            default => null,
        };

        if ($this->fehler === null) {
            $posten->save();
            $this->meldung = 'Gespeichert.';
        }
    }

    /**
     * Wochentag-Abweichung setzen (ISO 1=Mo…7=So). Leerer Wert = Abweichung löschen, der
     * Posten fällt an dem Tag auf seine Normalkapazität zurück. Gepflegt werden in der UI
     * nur Sa/So — das ist im Catering der reale Sonderfall.
     */
    public function wochentagSetzen(int $id, int $iso, string $wert): void
    {
        $posten = $this->eigener($id);
        if ($posten === null) {
            return;
        }

        $tage = $posten->kapazitaet_wochentag ?? [];
        $minuten = $this->minuten($wert);
        if ($minuten === null && trim($wert) === '') {
            unset($tage[(string) $iso]);
        } else {
            $tage[(string) $iso] = $minuten ?? 0;
        }

        $posten->kapazitaet_wochentag = $tage === [] ? null : $tage;
        $posten->save();
        $this->meldung = 'Gespeichert.';
    }

    /** Stufe 3 — Anzahl einer Rolle am Posten setzen (0/leer = Rolle raus). Leitet Kapazität + Kosten ab. */
    public function besetzungSetzen(int $id, int $roleId, string $wert): void
    {
        $posten = $this->eigener($id);
        if ($posten === null) {
            return;
        }

        $besetzung = $posten->besetzung ?? [];
        $anzahl = max(0, (int) trim($wert));
        if ($anzahl === 0) {
            unset($besetzung[(string) $roleId]);
        } else {
            $besetzung[(string) $roleId] = $anzahl;
        }

        $posten->besetzung = $besetzung === [] ? null : $besetzung;
        $posten->save();
        $this->meldung = 'Besetzung gespeichert.';
    }

    public function aktivToggle(int $id): void
    {
        $posten = $this->eigener($id);
        if ($posten === null) {
            return;
        }
        $posten->is_inactive = ! $posten->is_inactive;
        $posten->save();
        $this->meldung = $posten->is_inactive ? "«{$posten->name}» stillgelegt." : "«{$posten->name}» wieder aktiv.";
    }

    /** Nur Posten des EIGENEN Teams sind editierbar (D1) — geerbte sind Vorlagen. */
    private function eigener(int $id): ?FoodAlchemistProductionStation
    {
        $team = Auth::user()?->currentTeamRelation;
        $posten = $team !== null
            ? FoodAlchemistProductionStation::where('team_id', $team->id)->find($id)
            : null;

        if ($posten === null) {
            $this->fehler = 'Posten nicht im Schreibzugriff (D1).';
        }

        return $posten;
    }

    /** Minuten-Eingabe robust lesen: leer ⇒ null (= kein Kapazitätsposten), sonst ≥ 0. */
    private function minuten(string $roh): ?int
    {
        $roh = trim($roh);

        return $roh === '' ? null : max(0, (int) $roh);
    }

    /** Dezimal-Eingabe (Topf-Deckel): leer ⇒ null, Komma erlaubt, ≥ 0. */
    private function zahl(string $roh): ?float
    {
        $roh = trim(str_replace(',', '.', $roh));

        return $roh === '' ? null : max(0.0, (float) $roh);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.posten', [
            'posten' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)
                    ->orderBy('sort_order')->orderBy('name')->get()
                : collect(),
            'rollen' => $team !== null
                ? FoodAlchemistKitchenRole::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get()
                : collect(),
            'eigenesTeamId' => $team?->id,
        ]);
    }
}
