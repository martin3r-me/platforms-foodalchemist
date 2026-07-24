<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\LeitstelleService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 19 E5.3 — Leitstelle-Rail als eigenes Nested-Livewire (Muster {@see KundeDnaPanel}).
 * Sitzt in der rechten activity-Sidebar des Foodbook-Cockpits und ist KONTEXTSENSITIV:
 *
 * - **Kopf gewählt** ($kapitelId === null): Umschalter Fortschritt (Checkliste +
 *   Kapitel-Matrix + Komplexitäts-Hinweis) · Speisen (heterogener Baum) · Kalkulation
 *   (Portfolio + WE-Ampel je Kapitel). Auto-Default je Cockpit-Tab nur ohne manuellen
 *   Pin — der Pin lebt client-seitig in localStorage (Alpine), diese Komponente rendert
 *   alle drei Panels und lässt Alpine umschalten (kein Livewire-Roundtrip fürs Blättern).
 * - **Kapitel gewählt**: Kapitel-Planung mit Ziele-Editing (M3-Spalten) — Zielgruppen-Chips,
 *   Niveau/Einsatzmoment/Servierform, Mengen-/Preis-/WE-Ziel, pricing_mode — plus
 *   Kapitel-Coverage (Scope Kapitel+Nachfahren), Kapitel-Kalkulation und Ideen-Stand.
 *
 * Re-Mount bei Selektions-Wechsel über den `wire:key` im Eltern-Blade (foodbook.id +
 * kapitel.id|'kopf'). Ziel-Edits dispatchen `leitstelle-kapitel-geaendert` an den Eltern
 * (Index), damit Kapitel-Kopf/Coverage im Hauptbereich mitziehen.
 *
 * Kapitel-Go „Anlegen" (E7.5): der Ideen-Stand-Shortcut öffnet das Anlage-Modal
 * ({@see kapitelAnlegen} → {@see FoodbookService}::kapitelFreigeben). Solange das Kapitel
 * bearbeitbar bleibt (draft + kein Snapshot/Versand) bietet der angelegt-Zustand ein
 * „Anlage zurückziehen" ({@see anlageZuruckziehen} → {@see FoodbookService}::anlageZuruckziehen).
 * Kapitel-Go ist bewusst human-only (kein MCP-Trigger, Spec 19 §MCP-Lockstep).
 */
class LeitstelleRail extends Component
{
    public int $foodbookId;

    public ?int $kapitelId = null;

    /** Kapitel-Planung-Formular (M3-Ziele) — nur im Kapitel-Modus befüllt. */
    public array $ziel = [];

    /** Gestempelte Zielgruppen-IDs des Kapitels (lokaler Spiegel fürs Chip-Toggle). */
    public array $zielgruppenIds = [];

    /** Optionale Anlage-Notiz (release_note) — Freitext im Anlage-Modal. */
    public string $anlageNote = '';

    /** Transientes Ergebnis der letzten Anlage/Undo (für die Inline-Rückmeldung). */
    public ?array $anlageErgebnis = null;

    public function mount(int $foodbookId, ?int $kapitelId = null): void
    {
        $this->foodbookId = $foodbookId;
        $this->kapitelId = $kapitelId;
        if ($kapitelId !== null) {
            $this->ladeKapitel();
        }
    }

    /** M3-Ziel-Felder + gestempelte Zielgruppen ins Formular laden. */
    private function ladeKapitel(): void
    {
        $k = $this->kapitel();
        if ($k === null) {
            return;
        }
        $this->ziel = [
            'niveau' => $k->niveau,
            'serving_form_id' => $k->serving_form_id,
            'service_moment_id' => $k->service_moment_id,
            'pricing_mode' => $k->pricing_mode,
            'target_count' => $k->target_count,
            'price_anchor' => $k->price_anchor,
            'price_min' => $k->price_min,
            'price_max' => $k->price_max,
            'target_food_cost_pct' => $k->target_food_cost_pct,
        ];
        $k->loadMissing('targetGroups:id');
        $this->zielgruppenIds = $k->targetGroups->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    /** Kapitel-Ziele (M3) speichern — leere Strings → null (numerische/FK-Felder). */
    public function zieleSpeichern(FoodbookService $svc): void
    {
        if ($this->kapitelId === null) {
            return;
        }
        $in = [];
        foreach ($this->ziel as $feld => $wert) {
            $in[$feld] = ($wert === '' ? null : $wert);
        }
        $svc->updateKapitel($this->team(), $this->kapitelId, $in);
        $this->dispatch('leitstelle-kapitel-geaendert');
    }

    /** Zielgruppen-Stempel des Kapitels umschalten (PUT-sync auf die volle Liste). */
    public function zielgruppeToggle(int $id, FoodbookService $svc): void
    {
        if ($this->kapitelId === null) {
            return;
        }
        $this->zielgruppenIds = in_array($id, $this->zielgruppenIds, true)
            ? array_values(array_diff($this->zielgruppenIds, [$id]))
            : [...$this->zielgruppenIds, $id];
        $svc->setKapitelZielgruppen($this->team(), $this->kapitelId, $this->zielgruppenIds);
        $this->dispatch('leitstelle-kapitel-geaendert');
    }

    /**
     * Kapitel-Go „Anlegen" (E7.5) — materialisiert die Skizzen dieses Kapitels in Konzepte /
     * recipe_ref-Blöcke / KI-Queue ({@see FoodbookService}::kapitelFreigeben). Idempotent;
     * schließt das Modal und meldet den Eltern-Cockpit die Änderung (Kapitel-Kopf/Coverage).
     */
    public function kapitelAnlegen(FoodbookService $svc): void
    {
        if ($this->kapitelId === null) {
            return;
        }
        $this->anlageErgebnis = $svc->kapitelFreigeben($this->team(), $this->kapitelId, $this->anlageNote ?: null, Auth::id());
        $this->anlageNote = '';
        $this->dispatch('modal.close', name: 'kapitel-anlegen');
        $this->dispatch('leitstelle-kapitel-geaendert');
    }

    /**
     * „Anlage zurückziehen" (E7.5) — nur solange draft + kein Snapshot/Versand. Räumt die von
     * der Anlage erzeugten Objekte wieder weg ({@see FoodbookService}::anlageZuruckziehen);
     * eine harte Grenze im Service wirft, sobald das Kapitel eingefroren ist.
     */
    public function anlageZuruckziehen(FoodbookService $svc): void
    {
        if ($this->kapitelId === null) {
            return;
        }
        $this->anlageErgebnis = $svc->anlageZuruckziehen($this->team(), $this->kapitelId);
        $this->dispatch('modal.close', name: 'kapitel-zurueckziehen');
        $this->dispatch('leitstelle-kapitel-geaendert');
    }

    public function render(LeitstelleService $leit, FoodbookService $svc, IdeenService $ideenSvc)
    {
        $team = $this->team();
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->find($this->foodbookId);
        if ($fb === null) {
            return view('foodalchemist::livewire.foodbooks.leitstelle-rail', ['fb' => null, 'modus' => 'leer']);
        }

        // ── Kapitel-Modus: Kapitel-Planung + Coverage + Kalkulation + Ideen-Stand ──
        if ($this->kapitelId !== null) {
            $k = $this->kapitel();
            $stand = $k !== null ? $leit->kapitelStand($team, $k) : null;
            // Kapitel-Coverage: Befunde dieses Kapitels aus der Foodbook-Coverage (Scope = Kapitel+Nachfahren, E2.2/E4.3).
            $befunde = [];
            $ideenListe = ['gruppen' => [], 'einzel' => collect()];
            $undoMoeglich = false;
            if ($k !== null) {
                $cov = app(CoverageService::class)->coverage($team, 'foodbook', $fb->id);
                $befunde = collect($cov['befunde'] ?? [])->where('chapter_id', $this->kapitelId)->values()->all();
                // Anlage-Modal-Vorschau: alle Entwurf-Skizzen (Pakete + Einzel), NICHT verworfene.
                $ideenListe = $ideenSvc->liste($team, $this->kapitelId, null, false);
                // Undo nur solange bearbeitbar (Spec 19 UX 4): draft + kein Snapshot/Versand.
                $undoMoeglich = $k->released_at !== null && $k->snapshot_at === null && $k->status !== 'sent';
            }

            return view('foodalchemist::livewire.foodbooks.leitstelle-rail', [
                'fb' => $fb,
                'modus' => $k !== null ? 'kapitel' : 'leer',
                'stand' => $stand,
                'befunde' => $befunde,
                'ideenListe' => $ideenListe,
                'undoMoeglich' => $undoMoeglich,
                'servierformen' => FoodAlchemistServierform::where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('label')->get(['id', 'label']),
                'einsatzmomente' => FoodAlchemistEinsatzmoment::visibleToTeam($team)
                    ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
                'zielgruppenVokab' => FoodAlchemistTargetGroup::visibleToTeam($team)
                    ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
                'niveauLabels' => TeamSettingsService::NIVEAU_LABEL,
                'pricingModes' => FoodAlchemistFoodbookKapitel::PRICING_MODES,
            ]);
        }

        // ── Kopf-Modus: Fortschritt / Speisen / Kalkulation (Alpine-Umschalter) ──
        $matrix = $leit->kapitelMatrix($team, $fb);
        $positionenGesamt = array_sum(array_map(fn ($r) => $r['positionen'], $matrix));

        return view('foodalchemist::livewire.foodbooks.leitstelle-rail', [
            'fb' => $fb,
            'modus' => 'kopf',
            'checkliste' => $leit->checkliste($team, $fb),
            'matrix' => $matrix,
            'baum' => $leit->speisenBaum($team, $fb),
            'gesamt' => $svc->gesamt($team, $fb),
            // Portfolio-WE-Ampel des ganzen Foodbooks (E8.2) — Kopf der Kalkulation-Panel.
            'weGesamt' => $svc->foodbookWareneinsatzAmpel($team, $fb),
            'kapitelAnzahl' => count($matrix),
            'positionenGesamt' => $positionenGesamt,
            // Komplexitäts-Hinweis (UX 2): >8 Kapitel oder >12 Positionen insgesamt.
            'komplex' => count($matrix) > 8 || $positionenGesamt > 12,
        ]);
    }

    private function kapitel(): ?FoodAlchemistFoodbookKapitel
    {
        if ($this->kapitelId === null) {
            return null;
        }

        return FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->foodbookId)->find($this->kapitelId);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
