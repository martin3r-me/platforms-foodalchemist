<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanChip;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;

/**
 * Spec 59 — Speiseplan-Chips pflegen: der zentrale Katalog, gegen den Pläne ihre
 * Wochen-Vorgaben prüfen („mind. 2× Vegan, höchstens 1× Schwein, mind. 1× Suppe“).
 *
 * Ein Chip = ODER-Liste aus Ernährungsformen (vegan … rind) und Hauptgruppen. Die Vorgabe
 * selbst (mind./höchstens, Mahlzeit) steht je Plan im Speiseplan-Editor; hier nur die
 * Standardwerte, die beim Hinzufügen übernommen werden.
 *
 * Lösch-Schutz wie bei Posten (V-06): nur stilllegen — Pläne referenzieren den Chip per id.
 * Geerbte Chips (Eltern-Team) sind lesbar und in Plänen nutzbar, editierbar nur im Eltern-Team (D1).
 * Alle Schreibwege laufen über {@see SpeiseplanVorgabenService} (dieselbe Validierung wie MCP).
 */
class SpeiseplanChips extends Component
{
    /** Spec 65: Bereich settings.speiseplan_chips je Team — Felder schreiben sofort, Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'speiseplan_chips';
    }

    /** @var array{label: string, kriterien: list<string>, default_min: string, default_max: string} */
    public array $neu = ['label' => '', 'kriterien' => [], 'default_min' => '', 'default_max' => ''];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function create(SpeiseplanVorgabenService $svc): void
    {
        $this->fehler = null;
        $this->meldung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team im Zugriff.';

            return;
        }

        try {
            $chip = $svc->chipAnlegen($team, [
                'label' => $this->neu['label'],
                'kriterien' => $this->kriterienAusTokens($this->neu['kriterien']),
                'default_min' => $this->neu['default_min'],
                'default_max' => $this->neu['default_max'],
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $this->neu = ['label' => '', 'kriterien' => [], 'default_min' => '', 'default_max' => ''];
        $this->meldung = "«{$chip->label}» angelegt.";
    }

    public function standardAnlegen(SpeiseplanVorgabenService $svc): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $n = $svc->standardChipsAnlegen($team);
        $this->meldung = $n > 0 ? "{$n} Standard-Chips angelegt." : 'Dein Team hat schon eigene Chips.';
    }

    /** Inline-Feld eines eigenen Chips setzen: label | default_min | default_max | sort_order. */
    public function feldSetzen(int $id, string $feld, string $wert, SpeiseplanVorgabenService $svc): void
    {
        if (! in_array($feld, ['label', 'default_min', 'default_max', 'sort_order'], true)) {
            return;
        }
        $this->schreibe($svc, $id, [$feld => $wert], 'Gespeichert.');
    }

    /** Ein Kriterium (Token `diaet:vegan` / `hauptgruppe:12`) am Chip an- oder abwählen. */
    public function kriteriumUmschalten(int $id, string $token, SpeiseplanVorgabenService $svc): void
    {
        $chip = FoodAlchemistSpeiseplanChip::find($id);
        if ($chip === null) {
            return;
        }
        $tokens = $this->tokensAusKriterien((array) $chip->kriterien);
        $tokens = in_array($token, $tokens, true)
            ? array_values(array_diff($tokens, [$token]))
            : [...$tokens, $token];
        $this->schreibe($svc, $id, ['kriterien' => $this->kriterienAusTokens($tokens)], 'Kriterien gespeichert.');
    }

    public function aktivToggle(int $id, SpeiseplanVorgabenService $svc): void
    {
        $chip = FoodAlchemistSpeiseplanChip::find($id);
        if ($chip === null) {
            return;
        }
        $this->schreibe($svc, $id, ['is_active' => ! $chip->is_active],
            $chip->is_active ? "«{$chip->label}» stillgelegt." : "«{$chip->label}» wieder aktiv.");
    }

    private function schreibe(SpeiseplanVorgabenService $svc, int $id, array $in, string $ok): void
    {
        $this->fehler = null;
        $this->meldung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team im Zugriff.';

            return;
        }
        try {
            $svc->chipAendern($team, $id, $in);
            $this->meldung = $ok;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * UI-Tokens (`diaet:vegan`, `hauptgruppe:12`) → Kriterien-Liste fürs Service.
     *
     * @param  list<string>  $tokens
     * @return list<array<string, int|string>>
     */
    private function kriterienAusTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            [$art, $wert] = array_pad(explode(':', (string) $t, 2), 2, '');
            $out[] = $art === 'hauptgruppe' ? ['art' => 'hauptgruppe', 'id' => (int) $wert] : ['art' => $art, 'key' => $wert];
        }

        return $out;
    }

    /** @return list<string> */
    private function tokensAusKriterien(array $kriterien): array
    {
        return array_values(array_map(
            fn ($k) => ($k['art'] ?? '') === 'hauptgruppe' ? 'hauptgruppe:' . (int) ($k['id'] ?? 0) : 'diaet:' . ($k['key'] ?? ''),
            $kriterien,
        ));
    }

    public function render(SpeiseplanVorgabenService $svc)
    {
        $team = Auth::user()?->currentTeamRelation;
        $chips = $team !== null ? $svc->katalog($team) : collect();
        $hauptgruppen = $team !== null ? app(SalesRecipeService::class)->dishMainGroups($team) : collect();

        return view('foodalchemist::livewire.settings.speiseplan-chips', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'chips' => $chips,
            'chipTokens' => $chips->mapWithKeys(fn ($c) => [$c->id => $this->tokensAusKriterien((array) $c->kriterien)])->all(),
            'diaeten' => FoodAlchemistSpeiseplanChip::DIAETEN,
            'hauptgruppen' => $hauptgruppen,
            // Anzeige-Label je Kriterien-Token (auch für geerbte/inaktive Hauptgruppen ohne Namen: „#id“).
            'tokenLabels' => collect(FoodAlchemistSpeiseplanChip::DIAETEN)->mapWithKeys(fn ($l, $k) => ['diaet:' . $k => $l])
                ->merge($hauptgruppen->mapWithKeys(fn ($hg) => ['hauptgruppe:' . $hg->id => $hg->label]))->all(),
            'eigenesTeamId' => $team?->id,
            'hatEigene' => $team !== null && $chips->contains(fn ($c) => (int) $c->team_id === (int) $team->id),
        ]);
    }
}
