<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\TeamProfilService;

/**
 * Spec 82 §6 — Teamprofil pflegen: was die Küche kann (Fakten, keine DNA).
 *
 * Jedes Team pflegt sein EIGENES Profil (Oberteam wie Unter-Team); das Oberteam sieht die Profile seiner Unter-Teams
 * über die Brille und bearbeitet sie nach dem Wechsel ins jeweilige Team. Equipment ist dreiwertig: vorhanden,
 * fehlt (nicht_vorhanden) oder unbekannt (keine Angabe). Standardausstattung gilt ohne Angabe als vorhanden.
 * Gewarnt wird später nur bei „fehlt". Die Einstellungs-Sperre verlangt Inhaber/Admin (Spec 77a), der Dienst selbst
 * Kuratieren — MCP und Oberfläche haben also bewusst nicht dieselbe Schwelle.
 */
class Teamprofil extends Component
{
    /** Spec 65: Bereich settings.teamprofil je Team — Felder schreiben sofort, Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'teamprofil';
    }

    public string $maxPax = '';

    public string $convenience = '';

    public string $notizen = '';

    /** @var array{name: string, max_pax: string, saison_von: string, saison_bis: string} */
    public array $neueFlaeche = ['name' => '', 'max_pax' => '', 'saison_von' => '', 'saison_bis' => ''];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $profil = app(TeamProfilService::class)->lies($team, $team)['profil'];
        $this->maxPax = $profil['max_pax_parallel'] !== null ? (string) $profil['max_pax_parallel'] : '';
        $this->convenience = (string) ($profil['convenience_grad'] ?? '');
        $this->notizen = (string) ($profil['notes'] ?? '');
    }

    public function profilSpeichern(): void
    {
        $this->aktion(function ($svc, $team, $user) {
            $svc->setzeProfil($team, $user, [
                'max_pax_parallel' => trim($this->maxPax) === '' ? null : trim($this->maxPax),
                'convenience_grad' => $this->convenience === '' ? null : $this->convenience,
                'notes' => trim($this->notizen) === '' ? null : trim($this->notizen),
            ]);
            $this->meldung = 'Kennzahlen gespeichert.';
        });
    }

    /** $wert: vorhanden | nicht_vorhanden | unbekannt (leer = unbekannt). */
    public function equipmentSetzen(int $equipmentId, string $wert): void
    {
        $this->aktion(function ($svc, $team, $user) use ($equipmentId, $wert) {
            $svc->setzeEquipment($team, $user, $equipmentId, in_array($wert, ['vorhanden', 'nicht_vorhanden'], true) ? $wert : null);
            $this->meldung = 'Gespeichert.';
        });
    }

    public function flaecheAnlegen(): void
    {
        $this->aktion(function ($svc, $team, $user) {
            $f = $this->neueFlaeche;
            $svc->setzeFlaeche($team, $user, null, (string) $f['name'], $this->zahl($f['max_pax']), $this->zahl($f['saison_von']), $this->zahl($f['saison_bis']));
            $this->neueFlaeche = ['name' => '', 'max_pax' => '', 'saison_von' => '', 'saison_bis' => ''];
            $this->meldung = 'Fläche angelegt.';
        });
    }

    public function flaecheFeld(int $id, string $feld, string $wert): void
    {
        $this->aktion(function ($svc, $team, $user) use ($id, $feld, $wert) {
            $f = collect($svc->lies($team, $team)['flaechen'])->firstWhere('id', $id);
            if ($f === null) {
                return;
            }
            $f[$feld] = in_array($feld, ['max_pax', 'saison_von', 'saison_bis'], true) ? $this->zahl($wert) : trim($wert);
            $svc->setzeFlaeche($team, $user, $id, (string) $f['name'], $f['max_pax'], $f['saison_von'], $f['saison_bis']);
            $this->meldung = 'Gespeichert.';
        });
    }

    public function flaecheLoeschen(int $id): void
    {
        $this->aktion(function ($svc, $team, $user) use ($id) {
            $svc->loescheFlaeche($team, $user, $id);
            $this->meldung = 'Fläche gelöscht.';
        });
    }

    private function zahl(mixed $roh): ?int
    {
        $s = trim((string) $roh);

        return $s === '' || ! ctype_digit($s) ? null : (int) $s;
    }

    /** @param  callable(TeamProfilService, \Platform\Core\Models\Team, \Platform\Core\Models\User): void  $fn */
    private function aktion(callable $fn): void
    {
        $this->fehler = null;
        $this->meldung = null;
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        if ($user === null || $team === null) {
            $this->fehler = 'Kein Team im Zugriff.';

            return;
        }
        try {
            $fn(app(TeamProfilService::class), $team, $user);
        } catch (FaRechtFehltException $e) {
            $this->fehler = $e->getMessage();
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $daten = $team !== null ? app(TeamProfilService::class)->lies($team, $team) : ['flaechen' => [], 'ausstattung' => []];
        $ausstattung = collect($daten['ausstattung']);

        return view('foodalchemist::livewire.settings.teamprofil', [
            'sperr' => $this->sperrZustand(),
            'flaechen' => $daten['flaechen'],
            'gruppen' => $ausstattung->groupBy(fn ($a) => $a['gruppe'] ?: 'Weitere'),
            'unbekannt' => $ausstattung->filter(fn ($a) => $a['wirksam'] === 'unbekannt')->count(),
            'gesamt' => $ausstattung->count(),
            'convenienceWerte' => [
                'from_scratch' => 'Von Grund auf (from scratch)',
                'teil_convenience' => 'Teil-Convenience',
                'voll_convenience' => 'Voll-Convenience',
            ],
        ]);
    }
}
