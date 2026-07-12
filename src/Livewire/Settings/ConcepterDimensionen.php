<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Umbau-Spec Darreichungen Phase 4b (Review F3–F6): Pflege der Concepter-Facetten —
 * Einsatzmomente, Eventtypen, Saisons (team-eigen, FA-nativ) + Servierformen
 * (WaWi-Master-Spiegel: FA-native Zusätze erlaubt, Import-Einträge nur
 * deaktivierbar). Lösch-Schutz V-06: genutzte Einträge nur deaktivieren.
 */
class ConcepterDimensionen extends Component
{
    /** Whitelist: key => [tabelle, label, hint] */
    public const VOKABULARE = [
        'einsatzmomente' => ['tabelle' => 'foodalchemist_service_moments', 'label' => 'Einsatzmomente', 'hint' => 'mehrfach pro Concept (Frühstück, Lunch, Apéro …)'],
        'eventtypen' => ['tabelle' => 'foodalchemist_event_types', 'label' => 'Eventtypen', 'hint' => 'einfach pro Concept (Konferenz, Gala, Sommerfest …)'],
        'saisons' => ['tabelle' => 'foodalchemist_seasons', 'label' => 'Saisons', 'hint' => 'mehrfach pro Concept'],
        'servierformen' => ['tabelle' => 'foodalchemist_serving_forms', 'label' => 'Servierformen', 'hint' => 'Scharnier Concept ⇄ Gericht-Darreichung — WaWi-Master, Zusätze FA-nativ'],
    ];

    /** @var array<string, string> Add-Form (Name) je Vokabular */
    public array $neu = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        foreach (array_keys(self::VOKABULARE) as $key) {
            $this->neu[$key] = '';
        }
    }

    public function create(string $vokabular): void
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        $name = trim($this->neu[$vokabular] ?? '');
        if ($meta === null || $name === '') {
            $this->fehler = 'Name ist Pflicht.';

            return;
        }
        $teamId = Auth::user()?->currentTeamRelation?->id;
        $nameSpalte = $vokabular === 'servierformen' ? 'label' : 'name';
        if (DB::table($meta['tabelle'])->where($nameSpalte, $name)->whereNull('deleted_at')->exists()) {
            $this->fehler = "«{$name}» existiert schon in {$meta['label']}.";

            return;
        }

        $zeile = [
            'uuid' => (string) Str::uuid7(),
            'team_id' => $teamId,
            $nameSpalte => $name,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($vokabular === 'servierformen') {
            $zeile['code'] = Str::slug($name, '_'); // FA-nativ, ohne legacy_id — Import fasst sie nicht an
        }
        DB::table($meta['tabelle'])->insert($zeile);

        $this->neu[$vokabular] = '';
        $this->fehler = null;
        $this->meldung = "«{$name}» angelegt.";
    }

    /** V-06: nur deaktivieren — inaktive bleiben an bestehenden Concepts sichtbar. */
    public function toggleInactive(string $vokabular, int $id): void
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        if ($meta === null) {
            return;
        }
        $zeile = DB::table($meta['tabelle'])->where('id', $id)->first(['is_inactive', 'team_id']);
        if ($zeile === null) {
            return;
        }
        if (! TeamScope::owns($zeile->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann ändern.';

            return;
        }
        DB::table($meta['tabelle'])->where('id', $id)
            ->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);
        $this->meldung = 'Aktualisiert.';
    }

    /** Hart löschen nur wenn ungenutzt; Servierform-Import-Einträge (legacy_id) nie löschen. */
    public function delete(string $vokabular, int $id): void
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        if ($meta === null) {
            return;
        }
        $zeile = DB::table($meta['tabelle'])->where('id', $id)->first();
        if ($zeile === null) {
            return;
        }
        $name = $zeile->label ?? $zeile->name;
        if (! TeamScope::owns($zeile->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = "«{$name}» ist geerbt/Master — nur das Besitzer-Team kann löschen.";

            return;
        }
        if ($vokabular === 'servierformen' && $zeile->legacy_id !== null) {
            $this->fehler = "«{$name}» kommt aus der WaWi (Master) — nur deaktivieren.";

            return;
        }
        $n = $this->referenzen($vokabular, $id);
        if ($n > 0) {
            $this->fehler = "«{$name}» wird {$n}× genutzt — erst umhängen oder deaktivieren.";

            return;
        }
        DB::table($meta['tabelle'])->where('id', $id)->delete();
        $this->fehler = null;
        $this->meldung = "«{$name}» gelöscht.";
    }

    private function referenzen(string $vokabular, int $id): int
    {
        return match ($vokabular) {
            'einsatzmomente' => DB::table('foodalchemist_concept_service_moments')->where('service_moment_id', $id)->count(),
            'eventtypen' => DB::table('foodalchemist_concepts')->where('event_type_id', $id)->whereNull('deleted_at')->count(),
            'saisons' => DB::table('foodalchemist_concept_seasons')->where('season_id', $id)->count(),
            'servierformen' => DB::table('foodalchemist_concepts')->where('serving_form_id', $id)->whereNull('deleted_at')->count()
                + DB::table('foodalchemist_recipe_presentations')->where('serving_form_id', $id)->whereNull('deleted_at')->count(),
            default => 0,
        };
    }

    public function render()
    {
        $listen = [];
        foreach (self::VOKABULARE as $key => $meta) {
            $listen[$key] = $meta + [
                'zeilen' => TeamScope::applyVisible(
                    DB::table($meta['tabelle'])->whereNull('deleted_at'),
                    'team_id', Auth::user()?->currentTeamRelation
                )->orderBy('sort_order')->orderBy($key === 'servierformen' ? 'label' : 'name')->get(),
            ];
        }

        return view('foodalchemist::livewire.settings.concepter-dimensionen', ['listen' => $listen]);
    }
}
