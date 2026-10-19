<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelUngueltig;
use Platform\FoodAlchemist\Support\HausstandardSicht;
use RuntimeException;

/**
 * Spec 85 D-7 PR 2 — Einstellungen › Hausstandards: das Team weicht bei übersteuerbaren Regeln von der globalen ab (Default-GP,
 * Kleinstmengen, Haus-Zuordnung, HACCP-Temperaturen). Wirkt sofort für das Team und seine Unter-Teams ohne eigene Abweichung.
 * Schreiben nur FA-Admin (Mensch); Schwellen nur innerhalb der belegten Untergrenze. Die globalen Regeln pflegt der
 * Plattform-Admin in Einstellungen › Regeln — diese Seite fasst sie nie an.
 */
class Hausstandards extends Component
{
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'hausstandards';
    }

    /** Ansehen und Verlauf sind lesend. */
    protected function sperrFreiExtra(): array
    {
        return ['oeffne', 'schliesse'];
    }

    public ?string $offen = null;

    /** @var array<string, mixed> Formular der offenen Regel: wert/dauer (Schwelle) bzw. zeilen (Zuordnung), notiz */
    public array $form = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function oeffne(string $schluessel): void
    {
        $zeile = collect(HausstandardSicht::zeilen($this->team()))->firstWhere('schluessel', $schluessel);
        if ($zeile === null) {
            return;
        }
        $p = $zeile['params_wirksam'];
        $this->offen = $schluessel;
        $this->fehler = null;
        $this->form = $zeile['art'] === 'schwelle'
            ? ['wert' => (string) ($p['wert'] ?? ''), 'dauer' => isset($p['dauer']) ? (string) $p['dauer'] : null, 'notiz' => '']
            : ['zeilen' => array_values(array_map(fn ($e) => ['begriff' => (string) ($e['begriff'] ?? ''), 'ziel_name' => (string) ($e['ziel_name'] ?? ''),
                'aliase' => implode(', ', (array) ($e['aliase'] ?? []))], (array) ($p['eintraege'] ?? []))), 'notiz' => ''];
    }

    public function schliesse(): void
    {
        $this->offen = null;
        $this->form = [];
        $this->fehler = null;
    }

    public function zeileDazu(): void
    {
        $this->form['zeilen'][] = ['begriff' => '', 'ziel_name' => '', 'aliase' => ''];
    }

    public function zeileWeg(int $i): void
    {
        unset($this->form['zeilen'][$i]);
        $this->form['zeilen'] = array_values($this->form['zeilen']);
    }

    public function speichern(): void
    {
        $this->fehler = null;
        $global = RegelBuch::global((string) $this->offen);
        if ($global === null) {
            return;
        }
        if ($global->art === 'schwelle') {
            $params = ['wert' => is_numeric(str_replace(',', '.', (string) ($this->form['wert'] ?? ''))) ? (float) str_replace(',', '.', $this->form['wert']) : null];
            if (isset($global->params['dauer'])) {
                $params['dauer'] = is_numeric($this->form['dauer'] ?? null) ? (float) $this->form['dauer'] : null;
            }
        } else {
            // Zuordnung: nur Begriff/Ziel/Aliase je Zeile; weitere Felder (Kontext, Ziel-Typ) aus der globalen Zeile gleichen Begriffs
            $globalJe = collect((array) ($global->params['eintraege'] ?? []))->keyBy(fn ($e) => mb_strtolower((string) ($e['begriff'] ?? '')));
            $params = ['eintraege' => array_values(array_filter(array_map(function ($z) use ($globalJe) {
                $begriff = trim((string) ($z['begriff'] ?? ''));
                if ($begriff === '' || trim((string) ($z['ziel_name'] ?? '')) === '') {
                    return null;
                }
                $basis = (array) ($globalJe[mb_strtolower($begriff)] ?? ['ziel_typ' => 'gp']);

                return ['begriff' => $begriff, 'ziel_name' => trim((string) $z['ziel_name']),
                    'aliase' => array_values(array_filter(array_map('trim', explode(',', (string) ($z['aliase'] ?? '')))))] + $basis;
            }, (array) ($this->form['zeilen'] ?? []))))];
        }
        try {
            app(RegelService::class)->speichereTeam($this->team(), (string) $this->offen, $params,
                trim((string) ($this->form['notiz'] ?? '')) ?: null, Auth::user());
            $this->meldung = 'Hausstandard gespeichert — gilt ab sofort für dieses Team.';
            $this->schliesse();
        } catch (RegelUngueltig $e) {
            $this->fehler = implode(' · ', $e->fehler);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function zuruecksetzen(string $schluessel): void
    {
        try {
            app(RegelService::class)->entferneTeam($this->team(), $schluessel, Auth::user());
            $this->meldung = 'Abweichung entfernt — es gilt wieder der Wert des Oberteams bzw. der globale.';
            $this->schliesse();
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = $this->team();
        $eigen = $this->offen !== null
            ? FoodAlchemistRule::ohneTeamSicht('regelpflege')->withTrashed()->where('team_id', $team->id)->where('schluessel', $this->offen)->first() : null;

        return view('foodalchemist::livewire.settings.hausstandards', [
            'sperr' => $this->sperrZustand(),
            'zeilen' => HausstandardSicht::zeilen($team),
            'darf' => RegelService::darfTeam(Auth::user(), $team),
            'versionen' => $eigen ? FoodAlchemistRuleVersion::where('rule_id', $eigen->id)->orderByDesc('version')->limit(10)->get() : collect(),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
