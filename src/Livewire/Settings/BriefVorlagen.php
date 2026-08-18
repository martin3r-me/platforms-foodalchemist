<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;

/**
 * Settings-Sektion: Schnellstart-Vorlagen (Brief-Templates) verwalten. ANGELEGT werden sie im
 * Planung-Editor („Als Vorlage speichern" = Snapshot aus Brief + Kreativ-Modus + Leitplanken);
 * HIER: eigene umbenennen / aktiv-schalten / löschen + Überblick. Kuratierte Globals sind read-only.
 * Dieselbe Verwaltung auch per MCP (foodalchemist.brief_templates.*).
 */
class BriefVorlagen extends Component
{
    public ?int $editId = null;

    public string $editLabel = '';

    public ?string $fehler = null;

    public function edit(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $tpl = FoodAlchemistBriefTemplate::find($id);
        $darf = $team !== null && $tpl !== null
            && (TeamScope::owns($tpl->team_id, $team) || ($tpl->team_id === null && app(BriefTemplateService::class)->istMaster($team)));
        if (! $darf) {
            $this->fehler = 'Nur eigene Vorlagen bearbeitbar; globale Vorlagen kuratiert das Master-Team.';

            return;
        }
        $this->editId = $id;
        $this->editLabel = (string) $tpl->label;
        $this->fehler = null;
    }

    public function cancel(): void
    {
        $this->reset('editId', 'editLabel', 'fehler');
    }

    public function save(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->editId === null) {
            return;
        }
        try {
            app(BriefTemplateService::class)->umbenennen($team, $this->editId, $this->editLabel);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->cancel();
    }

    public function toggleActive(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            app(BriefTemplateService::class)->toggleActive($team, $id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function loeschen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            app(BriefTemplateService::class)->loeschen($team, $id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if ($this->editId === $id) {
            $this->cancel();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $rows = $team ? app(BriefTemplateService::class)->verwaltung($team) : collect();

        return view('foodalchemist::livewire.settings.brief-vorlagen', [
            'eigene' => $rows->whereNotNull('team_id')->values(),
            'globals' => $rows->whereNull('team_id')->values(),
            'istMaster' => $team !== null && app(BriefTemplateService::class)->istMaster($team),
            'scopeLabel' => ['rezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Concept'],
        ]);
    }
}
