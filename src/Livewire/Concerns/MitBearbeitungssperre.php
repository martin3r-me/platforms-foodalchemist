<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;

/**
 * Spec 65 · Bearbeitungssperre für Editoren (Livewire).
 *
 * Ein Editor öffnet zum Lesen; „Bearbeiten" sperrt den Datensatz für alle anderen, „Speichern" schreibt und gibt frei,
 * „Abbrechen" gibt frei ohne zu schreiben. Ablauf 15 Min ohne Aktivität (jede erlaubte Schreibaktion verlängert).
 *
 * Durchsetzung auf dem SERVER: ein globaler Livewire-`call`-Haken (FoodAlchemistServiceProvider) weist jede Methode ab,
 * die nicht in sperrFreieMethoden() steht, solange die aufrufende Person die Sperre nicht hält. Die Oberfläche
 * (editor-tabs `gesperrt`, Bearbeiten-Leiste) ist nur der Komfort davor.
 *
 * Nutzer des Traits implementieren sperrZiel(): [typ, id] des Datensatzes oder null (neu/kein Ziel → frei bearbeitbar).
 * Eingebettete Teil-Editoren (Zutaten, Schritte) benutzen dasselbe Ziel wie ihr Editor und halten so keine eigene Sperre.
 */
trait MitBearbeitungssperre
{
    /** Inhaber einer fremden Sperre (Anzeige „wird von … bearbeitet"), sonst null. */
    public ?array $sperreFremd = null;

    /** @return array{0: string, 1: int|string}|null */
    abstract protected function sperrZiel(): ?array;

    /** Methoden, die ohne Sperre laufen dürfen (Lesen, Reiter, Öffnen). Editoren ergänzen über sperrFreiExtra(). */
    public function sperrFreieMethoden(): array
    {
        return array_merge([
            'bearbeitenStarten', 'bearbeitenAbbrechen', 'bearbeitenFertig', 'sperreHerzschlag', 'sperreLoesen', 'sperreBeiSchliessen',
            'oeffnen', 'tabLaden', 'render', 'mount', '$refresh', '__dispatch',
        ], $this->sperrFreiExtra());
    }

    /** Editor-spezifische Lese-Methoden (Suche, Vorschau, Polling …). */
    protected function sperrFreiExtra(): array
    {
        return [];
    }

    /** Darf die aktuelle Person gerade schreiben? Neu/ohne Ziel: ja. */
    public function darfSchreiben(): bool
    {
        $ziel = $this->sperrZiel();
        $uid = Auth::id();
        if ($ziel === null) {
            return true;
        }

        return $uid !== null && app(BearbeitungssperreService::class)->haelt($ziel[0], $ziel[1], (int) $uid);
    }

    /** Vom globalen call-Haken gefragt. Erlaubte Schreibaktion verlängert die Sperre (= Aktivität). */
    public function sperreErlaubt(string $methode): bool
    {
        if (! self::sperreAktiv() || in_array($methode, $this->sperrFreieMethoden(), true)) {
            return true;
        }
        if (! $this->darfSchreiben()) {
            return false;
        }
        $ziel = $this->sperrZiel();
        if ($ziel !== null && Auth::id() !== null) {
            app(BearbeitungssperreService::class)->verlaengern($ziel[0], $ziel[1], (int) Auth::id());
        }

        return true;
    }

    /** Vom call-Haken nach einer Abweisung aufgerufen: klare Rückmeldung statt stillem No-op. */
    public function sperreAbgewiesen(string $methode): void
    {
        $ziel = $this->sperrZiel();
        $fremd = $ziel !== null && Auth::id() !== null
            ? app(BearbeitungssperreService::class)->fremd($ziel[0], $ziel[1], (int) Auth::id()) : null;
        $this->sperreFremd = $fremd;
        $text = $fremd !== null
            ? 'Wird gerade von '.($fremd['name'] ?? 'jemand anderem').' bearbeitet — nur Lesen.'
            : 'Zum Ändern zuerst „Bearbeiten" klicken.';
        $this->dispatch('fa-saved', message: $text, type: 'error');
    }

    public function bearbeitenStarten(): void
    {
        $ziel = $this->sperrZiel();
        $user = Auth::user();
        if ($ziel === null || $user === null) {
            return;
        }
        $r = app(BearbeitungssperreService::class)->sperren($ziel[0], $ziel[1], (int) $user->id, $user->name ?? null, $user->current_team_id ?? null);
        $this->sperreFremd = $r['ok'] ? null : $r['inhaber'];
        if (! $r['ok']) {
            $this->dispatch('fa-saved', message: 'Wird gerade von '.($r['inhaber']['name'] ?? 'jemand anderem').' bearbeitet.', type: 'error');
        }
    }

    /** Nach erfolgreichem Speichern aufrufen: Bearbeitung beenden, Datensatz wieder frei. */
    protected function bearbeitenBeenden(): void
    {
        $ziel = $this->sperrZiel();
        if ($ziel !== null && Auth::id() !== null) {
            app(BearbeitungssperreService::class)->freigeben($ziel[0], $ziel[1], (int) Auth::id());
        }
    }

    /** „Abbrechen": nichts schreiben, Sperre frei, Stand neu laden (Editor überschreibt nachAbbrechen()). */
    public function bearbeitenAbbrechen(): void
    {
        $this->bearbeitenBeenden();
        $this->nachAbbrechen();
    }

    protected function nachAbbrechen(): void {}

    /** Detailspalten speichern jede Aktion sofort — „Fertig" beendet nur die Bearbeitung (Sperre frei). */
    public function bearbeitenFertig(): void
    {
        $this->bearbeitenBeenden();
        $this->sperreFremd = null;
    }

    public function sperreHerzschlag(): void
    {
        $ziel = $this->sperrZiel();
        if ($ziel !== null && Auth::id() !== null) {
            app(BearbeitungssperreService::class)->verlaengern($ziel[0], $ziel[1], (int) Auth::id());
        }
    }

    /** Owner/Admin des aktuellen Teams darf eine fremde Sperre lösen. */
    public function sperreLoesen(): void
    {
        $ziel = $this->sperrZiel();
        $user = Auth::user();
        if ($ziel === null || $user === null || ! $this->istTeamAdmin($user)) {
            return;
        }
        $alt = app(BearbeitungssperreService::class)->loesen($ziel[0], $ziel[1]);
        $this->sperreFremd = null;
        if ($alt !== null) {
            \Illuminate\Support\Facades\Log::info('fa.bearbeitungssperre.geloest', ['ziel' => $ziel, 'von' => $user->id, 'inhaber' => $alt]);
            $this->dispatch('fa-saved', message: 'Sperre von '.($alt['name'] ?? 'unbekannt').' gelöst.', type: 'success');
        }
    }

    /**
     * Modal geschlossen: eigene Sperre freigeben (ungespeicherte Änderungen verfallen — Nachfrage im Client).
     * KEIN #[On] hier: Livewire bindet ein Ereignis an GENAU EINE Methode je Komponente — ein Trait-Listener
     * verdrängt den eigenen modal.closed-Handler des Editors (Befund ZutatenSaveVertragTest). Editoren rufen
     * diese Methode aus ihrem eigenen #[On('modal.closed')]-Handler auf.
     */
    public function sperreBeiSchliessen(?string $name = null): void
    {
        if ($name !== null && $name === $this->sperrModalName()) {
            $this->bearbeitenBeenden();
            $this->sperreFremd = null;
        }
    }

    /** Name des x-foodalchemist::modal dieses Editors (null = kein Modal, z.B. Seiten-Editor). */
    protected function sperrModalName(): ?string
    {
        return null;
    }

    /** Für die View: Zustand der Bearbeiten-Leiste. */
    public function sperrZustand(): array
    {
        $ziel = $this->sperrZiel();
        $uid = Auth::id();
        if (! self::sperreAktiv()) {
            return ['modus' => 'aus', 'fremd' => null, 'admin' => false];   // Schalter aus: Verhalten wie vor Spec 65
        }
        if ($ziel === null) {
            return ['modus' => 'neu', 'fremd' => null, 'admin' => false];
        }
        $svc = app(BearbeitungssperreService::class);
        $fremd = $uid !== null ? $svc->fremd($ziel[0], $ziel[1], (int) $uid) : null;
        $meins = $uid !== null && $svc->haelt($ziel[0], $ziel[1], (int) $uid);

        return [
            'modus' => $meins ? 'bearbeiten' : ($fremd !== null ? 'fremd' : 'lesen'),
            'fremd' => $fremd,
            'admin' => $fremd !== null && Auth::user() !== null && $this->istTeamAdmin(Auth::user()),
        ];
    }

    /** Schalter config('foodalchemist.bearbeitungssperre') — Standard an; Test-Grundlage und Notfall: aus. */
    public static function sperreAktiv(): bool
    {
        return (bool) config('foodalchemist.bearbeitungssperre', true);
    }

    private function istTeamAdmin($user): bool
    {
        $teamId = $user->current_team_id ?? null;

        return $teamId !== null && DB::table('team_user')->where('team_id', $teamId)->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])->exists();
    }
}
