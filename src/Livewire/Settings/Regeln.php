<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;
use Platform\FoodAlchemist\Services\Regeln\RegelProbelauf;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelUngueltig;
use RuntimeException;

/**
 * Spec 81 Teil G — Einstellungen › Regeln: mechanische Regelwerk-Regeln pflegen. Formular je Art (kein JSON),
 * Beispiele, Pflicht-Probelauf vor dem Speichern einer aktiven Regel, Versionen mit Zurückrollen.
 *
 * Speichern wirkt nur auf KÜNFTIGE Anlagen und Prüfungen; den Bestand fasst diese Seite nicht an.
 * Felder, die das Formular nicht zeigt (Bedingungen, Kontext, Wortmengen-Optionen), bleiben unverändert.
 */
class Regeln extends Component
{
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'regeln';
    }

    /** Ansehen und Probelauf sind lesend — auch ohne „Bearbeiten" und ohne Admin-Recht. */
    protected function sperrFreiExtra(): array
    {
        return ['oeffne', 'schliesse', 'pruefeProbelauf'];
    }

    public string $regelwerk = '';

    public string $art = '';

    public string $suche = '';

    public ?int $offenId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, mixed>|null */
    public ?array $probelauf = null;

    /** Fingerabdruck des Formulars, für das der Probelauf gilt. */
    public ?string $probelaufFuer = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public const REGELWERKE = ['gp' => 'Grundprodukte', 'basisrezept' => 'Basisrezepte', 'la' => 'Lieferantenartikel',
        'vk' => 'Verkaufsgerichte', 'matching' => 'Matching'];

    public const ARTEN = ['vokabular' => 'Vokabular', 'ersetzung' => 'Ersetzung', 'pflichtangabe' => 'Pflichtangabe',
        'verbot' => 'Verbot', 'zuordnung' => 'Zuordnung', 'schwelle' => 'Schwelle'];

    public const WIRKUNGEN = ['korrigieren' => 'Beim Anlegen korrigieren', 'blockieren' => 'Blockieren (harter Befund)', 'warnen' => 'Warnen (Hinweis)'];

    public function oeffne(int $id): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->find($id);
        if ($r === null) {
            return;
        }
        $this->offenId = $id;
        $this->form = $this->formAus($r);
        $this->reset('probelauf', 'probelaufFuer', 'fehler', 'meldung');
    }

    public function schliesse(): void
    {
        $this->reset('offenId', 'form', 'probelauf', 'probelaufFuer', 'fehler');
    }

    public function zeileDazu(): void
    {
        $this->form['zeilen'][] = array_fill_keys(array_keys($this->leereZeile()), '');
    }

    public function zeileWeg(int $i): void
    {
        unset($this->form['zeilen'][$i]);
        $this->form['zeilen'] = array_values($this->form['zeilen'] ?? []);
    }

    /** Probelauf: geänderte Regel gegen den Bestand, Vergleich mit der gespeicherten Fassung. */
    public function pruefeProbelauf(): void
    {
        $this->fehler = null;
        $alt = $this->regel();
        if ($alt === null) {
            return;
        }
        $neu = $this->entwurf($alt);
        $this->probelauf = app(RegelProbelauf::class)->vergleiche($alt, $neu);
        $this->probelaufFuer = $this->fingerabdruck();
    }

    public function speichern(): void
    {
        $this->reset('fehler', 'meldung');
        $alt = $this->regel();
        if ($alt === null) {
            return;
        }
        if ($alt->aktiv && $this->probelaufFuer !== $this->fingerabdruck()) {
            $this->pruefeProbelauf();
            $this->fehler = 'Diese Regel ist aktiv. Bitte zuerst den Probelauf ansehen, dann erneut speichern.';

            return;
        }
        try {
            $neu = $this->entwurf($alt);
            app(RegelService::class)->speichere($neu->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params', 'beispiele', 'notiz', 'dossier_slug']),
                $this->team(), Auth::id());
            $this->oeffne($alt->id);
            $this->meldung = 'Gespeichert. Gilt ab der nächsten Anlage und Prüfung; der Bestand bleibt unverändert.';
        } catch (RegelUngueltig $e) {
            $this->fehler = implode(' · ', $e->fehler);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function setzeAktiv(int $id, bool $aktiv): void
    {
        try {
            app(RegelService::class)->setzeAktiv($id, $aktiv, $this->team(), Auth::id());
            if ($this->offenId === $id) {
                $this->oeffne($id);
            }
            $this->meldung = $aktiv ? 'Regel ist aktiv und greift ab sofort.' : 'Regel ist aus. Der Code prüft diesen Punkt nicht mehr.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function zurueckAuf(int $version): void
    {
        try {
            app(RegelService::class)->zurueckAuf((int) $this->offenId, $version, $this->team(), Auth::id());
            $this->oeffne((int) $this->offenId);
            $this->meldung = "Fassung {$version} wiederhergestellt (als neue Version).";
        } catch (RegelUngueltig $e) {
            $this->fehler = implode(' · ', $e->fehler);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $alle = FoodAlchemistRule::query()->whereNull('team_id')->orderBy('regelwerk')->orderBy('paragraph')->orderBy('titel')->get();
        $liste = $alle->filter(function (FoodAlchemistRule $r) {
            $q = mb_strtolower(trim($this->suche));

            return ($this->regelwerk === '' || $r->regelwerk === $this->regelwerk)
                && ($this->art === '' || $r->art === $this->art)
                && ($q === '' || str_contains(mb_strtolower($r->titel . ' ' . $r->paragraph . ' ' . $r->schluessel), $q));
        });
        $offen = $this->regel();

        return view('foodalchemist::livewire.settings.regeln', [
            'sperr' => $this->sperrZustand(),
            'zaehler' => $alle->countBy('regelwerk'),
            'gruppen' => $liste->groupBy('regelwerk'),
            'gesamt' => $alle->count(),
            'aktivGesamt' => $alle->where('aktiv', true)->count(),
            'offen' => $offen,
            'versionen' => $offen ? FoodAlchemistRuleVersion::where('rule_id', $offen->id)->orderByDesc('version')->limit(10)->get() : collect(),
        ]);
    }

    private function regel(): ?FoodAlchemistRule
    {
        return $this->offenId ? FoodAlchemistRule::query()->whereNull('team_id')->find($this->offenId) : null;
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }

    private function fingerabdruck(): string
    {
        return sha1(json_encode($this->form));
    }

    /** @return array<string, string> Spalten des Zeilen-Editors je Art */
    private function leereZeile(): array
    {
        return match ($this->form['art'] ?? '') {
            'vokabular' => ['wert' => '', 'aliase' => '', 'gruppe' => ''],
            'ersetzung' => ['von' => '', 'nach' => ''],
            'zuordnung' => ['begriff' => '', 'aliase' => '', 'ziel_name' => ''],
            default => [],
        };
    }

    /** Regel → Formular (Listen als Text, eine Zeile je Wert bzw. Zeilen-Editor). */
    private function formAus(FoodAlchemistRule $r): array
    {
        $p = (array) $r->params;
        $liste = static fn ($v) => implode("\n", array_map('strval', (array) ($v ?? [])));
        $komma = static fn ($v) => implode(', ', array_map('strval', (array) ($v ?? [])));
        $bsp = static fn ($seite) => implode("\n", array_values(array_filter(array_map(
            static fn ($b) => is_array($b) ? null : (string) $b, (array) (($r->beispiele ?? [])[$seite] ?? [])))));

        $form = ['art' => $r->art, 'titel' => $r->titel, 'wirkung' => $r->wirkung, 'notiz' => (string) $r->notiz,
            'richtig' => $bsp('richtig'), 'falsch' => $bsp('falsch'), 'zeilen' => []];

        return array_merge($form, match ($r->art) {
            'vokabular' => ['zeilen' => array_map(static fn ($w) => ['wert' => (string) ($w['wert'] ?? ''), 'aliase' => $komma($w['aliase'] ?? []),
                'gruppe' => (string) ($w['gruppe'] ?? '')], (array) ($p['werte'] ?? [])), 'muster' => $liste($p['muster'] ?? [])],
            'ersetzung' => ['zeilen' => array_map(static fn ($x) => ['von' => (string) $x['von'], 'nach' => (string) $x['nach']], (array) ($p['paare'] ?? [])),
                'ausnahmen' => $komma($p['ausnahmen'] ?? [])],
            'verbot', 'pflichtangabe' => ['tokens' => $liste($p['tokens'] ?? []), 'muster' => $liste($p['muster'] ?? []),
                'ausnahmen' => $komma($p['ausnahmen'] ?? []), 'grund' => (string) ($p['grund'] ?? $p['hinweis'] ?? '')],
            'zuordnung' => ['zeilen' => array_map(static fn ($e) => ['begriff' => (string) $e['begriff'], 'aliase' => $komma($e['aliase'] ?? []),
                'ziel_name' => (string) $e['ziel_name']], (array) ($p['eintraege'] ?? []))],
            'schwelle' => ['vergleich' => (string) ($p['vergleich'] ?? '>='), 'wert' => (string) ($p['wert'] ?? ''),
                'min' => (string) ($p['min'] ?? ''), 'max' => (string) ($p['max'] ?? ''), 'einheit' => (string) ($p['einheit'] ?? '')],
            default => [],
        });
    }

    /** Formular → ungespeicherte Regel. Nicht gezeigte Parameter der alten Fassung bleiben erhalten. */
    private function entwurf(FoodAlchemistRule $alt): FoodAlchemistRule
    {
        $f = $this->form;
        $p = (array) $alt->params;
        $zeilen = static fn (string $t) => array_values(array_filter(array_map('trim', preg_split('/\R/u', $t) ?: []), static fn ($x) => $x !== ''));
        $komma = static fn (string $t) => array_values(array_filter(array_map('trim', explode(',', $t)), static fn ($x) => $x !== ''));
        $zeilenFeld = array_values(array_filter((array) ($f['zeilen'] ?? []), static fn ($z) => implode('', array_map('trim', (array) $z)) !== ''));

        switch ($alt->art) {
            case 'vokabular':
                $p['werte'] = array_map(static fn ($z) => array_filter(['wert' => trim((string) $z['wert']), 'aliase' => $komma((string) ($z['aliase'] ?? '')),
                    'gruppe' => trim((string) ($z['gruppe'] ?? ''))], static fn ($v) => $v !== '' && $v !== []), $zeilenFeld);
                $p['muster'] = $zeilen((string) ($f['muster'] ?? ''));
                break;
            case 'ersetzung':
                $p['paare'] = array_map(static fn ($z) => ['von' => trim((string) $z['von']), 'nach' => trim((string) $z['nach'])], $zeilenFeld);
                $p['ausnahmen'] = $komma((string) ($f['ausnahmen'] ?? ''));
                break;
            case 'verbot':
            case 'pflichtangabe':
                $p['tokens'] = $zeilen((string) ($f['tokens'] ?? ''));
                $p['muster'] = $zeilen((string) ($f['muster'] ?? ''));
                $p['ausnahmen'] = $komma((string) ($f['ausnahmen'] ?? ''));
                $p[$alt->art === 'verbot' ? 'grund' : 'hinweis'] = trim((string) ($f['grund'] ?? ''));
                break;
            case 'zuordnung':
                $altEintraege = [];
                foreach ((array) ($p['eintraege'] ?? []) as $e) {
                    $altEintraege[mb_strtolower($e['begriff'] . '|' . $e['ziel_name'])] = $e;
                }
                $p['eintraege'] = array_map(static function ($z) use ($komma, $altEintraege) {
                    $basis = $altEintraege[mb_strtolower(trim((string) $z['begriff']) . '|' . trim((string) $z['ziel_name']))] ?? ['ziel_typ' => 'gp'];

                    return ['begriff' => trim((string) $z['begriff']), 'aliase' => $komma((string) ($z['aliase'] ?? '')),
                        'ziel_name' => trim((string) $z['ziel_name'])] + $basis;
                }, $zeilenFeld);
                break;
            case 'schwelle':
                $p['vergleich'] = (string) ($f['vergleich'] ?? '>=');
                foreach (['wert', 'min', 'max'] as $k) {
                    $v = str_replace(',', '.', trim((string) ($f[$k] ?? '')));
                    if ($v === '') {
                        unset($p[$k]);
                    } else {
                        $p[$k] = is_numeric($v) ? $v + 0 : $v;
                    }
                }
                $p['einheit'] = trim((string) ($f['einheit'] ?? ''));
                break;
        }
        foreach (['tokens', 'muster', 'ausnahmen'] as $leer) {
            if (($p[$leer] ?? null) === []) {
                unset($p[$leer]);
            }
        }

        $komplex = static fn ($seite) => array_values(array_filter((array) (($alt->beispiele ?? [])[$seite] ?? []), 'is_array'));
        $neu = $alt->replicate();
        $neu->id = $alt->id;
        $neu->exists = true;
        $neu->fill([
            'titel' => trim((string) ($f['titel'] ?? $alt->titel)),
            'wirkung' => (string) ($f['wirkung'] ?? $alt->wirkung),
            'notiz' => trim((string) ($f['notiz'] ?? '')) ?: null,
            'params' => $p,
            'beispiele' => ['richtig' => [...$zeilen((string) ($f['richtig'] ?? '')), ...$komplex('richtig')],
                'falsch' => [...$zeilen((string) ($f['falsch'] ?? '')), ...$komplex('falsch')]],
        ]);

        return $neu;
    }
}
