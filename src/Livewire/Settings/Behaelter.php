<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * R5 (Dominique): Behälter & Geräte als EIGENE Settings-Seite mit Anlegen —
 * die 3 Container-Vokabulare (D-6 §4.6) PLUS das Koch-Equipment der
 * Basisrezepte (D-5 §2.3) an einem Ort. Lösch-Schutz V-06: nur deaktivieren.
 */
class Behaelter extends Component
{
    /** Whitelist: vokabular-key => [tabelle, label, hat kapazitaet_kg] */
    public const VOKABULARE = [
        'behaelter' => ['tabelle' => 'foodalchemist_vocab_containers', 'label' => 'Behälter (GN & Co.)', 'kapazitaet' => true],
        'regen' => ['tabelle' => 'foodalchemist_vocab_regeneration_devices', 'label' => 'Regenerations-Geräte', 'kapazitaet' => false],
        'vehikel' => ['tabelle' => 'foodalchemist_vocab_serving_vehicles', 'label' => 'Servier-Vehikel', 'kapazitaet' => false],
        'equipment' => ['tabelle' => 'foodalchemist_vocab_kitchen_equipment', 'label' => 'Koch-Equipment (Basisrezepte)', 'kapazitaet' => false],
    ];

    /** @var array<string, array{name: string, gruppe: string, kapazitaet_kg: string}> Add-Form je Vokabular */
    public array $neu = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        foreach (array_keys(self::VOKABULARE) as $key) {
            $this->neu[$key] = ['name' => '', 'group_name' => '', 'kapazitaet_kg' => ''];
        }
    }

    public function create(string $vokabular): void
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        $name = trim($this->neu[$vokabular]['name'] ?? '');
        if ($meta === null || $name === '') {
            $this->fehler = 'Name ist Pflicht.';

            return;
        }
        $slug = Str::slug($name, '_');
        // E2: Die DB-Unique ist (team_id, slug). Ein ungescopter Check blockiert Kind-Teams,
        // sobald IRGENDEIN Team den Slug hat — und verrät mit der Meldung dessen Existenz.
        $kollision = TeamScope::applyVisible(
            DB::table($meta['tabelle'])->where('slug', $slug)->whereNull('deleted_at'),
            'team_id', Auth::user()?->currentTeamRelation
        )->exists();
        if ($kollision) {
            $this->fehler = "«{$name}» existiert schon in {$meta['label']} ({$slug}).";

            return;
        }

        $zeile = [
            'uuid' => (string) Str::uuid7(),
            'team_id' => Auth::user()?->currentTeamRelation?->id,
            'slug' => $slug,
            'name' => $name,
            'group_name' => trim($this->neu[$vokabular]['group_name'] ?? '') ?: null,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($meta['kapazitaet']) {
            $kap = str_replace(',', '.', trim($this->neu[$vokabular]['kapazitaet_kg'] ?? ''));
            $zeile['kapazitaet_kg'] = is_numeric($kap) ? (float) $kap : null;
        }
        DB::table($meta['tabelle'])->insert($zeile);

        $this->neu[$vokabular] = ['name' => '', 'group_name' => '', 'kapazitaet_kg' => ''];
        $this->fehler = null;
        $this->meldung = "«{$name}» angelegt.";
    }

    /** V-06: nur deaktivieren — inaktive bleiben an Rezepten sichtbar. */
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
        $this->meldung = 'Aktualisiert — inaktive Einträge bleiben an Rezepten sichtbar (V-06).';
    }

    /** Phase 5: hart löschen, wenn von keinem Rezept genutzt (sonst locked → deaktivieren). */
    public function delete(string $vokabular, int $id): void
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        if ($meta === null) {
            return;
        }
        $zeile = DB::table($meta['tabelle'])->where('id', $id)->first(['id', 'legacy_id', 'team_id', 'name']);
        if ($zeile === null) {
            return;
        }
        if (! TeamScope::owns($zeile->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann löschen.';

            return;
        }
        $n = $this->referenzen($vokabular, $zeile);
        if ($n > 0) {
            $this->fehler = "«{$zeile->name}» ist {$n}× in Verwendung (Rezepte, Darreichungen, Regeneration) — erst umhängen oder deaktivieren.";

            return;
        }
        DB::table($meta['tabelle'])->where('id', $id)->delete();
        $this->fehler = null;
        $this->meldung = "«{$zeile->name}» gelöscht.";
    }

    /**
     * Wo überall dieser Vokabel-Eintrag hängt — Grundlage des Lösch-Schutzes.
     *
     * BEFUND 2026-09-04 (Spec 51): die Vorgänger-Fassung zählte ausschliesslich die
     * `*_legacy_id`-Rohwerte des WaWi-Imports und stieg bei `legacy_id === null` sofort mit 0
     * aus. Damit war JEDE vom Kunden selbst angelegte Zeile hart löschbar, egal wie oft sie
     * genutzt wurde — und die echten Fremdschlüssel (`container_warm_vocab_id` &c.) sah sie
     * ohnehin nie, ebenso wenig Darreichungen und Regenerationszeilen. Weil alle Bestands-FKs
     * `nullOnDelete` sind, hätte ein Löschen den Behälter an Rezepten und Darreichungen STILL
     * entfernt.
     *
     * Die Bestands-FKs bleiben bewusst `nullOnDelete`: es kann Zeilen geben, die auf bereits
     * soft-gelöschte Vokabeln zeigen, und eine nachträgliche restrict-Migration würde daran
     * scheitern. Geschützt wird hier — nur die neue `recipe_containers` ist restrict.
     *
     * Defensiv über Schema-Prüfungen, damit die Zählung Schema-Drift überlebt statt zu werfen.
     */
    private function referenzen(string $vokabular, object $zeile): int
    {
        $treffer = 0;

        foreach ($this->nutzungsorte($vokabular) as [$tabelle, $spalten]) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $vorhanden = array_values(array_filter($spalten, fn (string $c) => Schema::hasColumn($tabelle, $c)));
            if ($vorhanden === []) {
                continue;
            }

            $q = DB::table($tabelle);
            if (Schema::hasColumn($tabelle, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }

            $treffer += $q->where(function ($q) use ($vorhanden, $zeile) {
                foreach ($vorhanden as $c) {
                    $q->orWhere($c, $zeile->id);
                }
            })->count();
        }

        return $treffer + $this->legacyReferenzen($vokabular, $zeile);
    }

    /** Echte Fremdschlüssel je Vokabular: [tabelle, spalten]. */
    private function nutzungsorte(string $vokabular): array
    {
        return match ($vokabular) {
            'behaelter' => [
                ['foodalchemist_recipes', ['container_warm_vocab_id', 'container_cold_vocab_id']],
                ['foodalchemist_recipe_presentations', ['container_warm_vocab_id', 'container_cold_vocab_id']],
                ['foodalchemist_recipe_containers', ['container_vocab_id']],
            ],
            'regen' => [
                ['foodalchemist_recipe_regenerations', ['device_vocab_id']],
                ['foodalchemist_recipe_presentations', ['regeneration_device_vocab_id']],
            ],
            'vehikel' => [
                ['foodalchemist_recipes', ['serving_vehicle_vocab_id']],
                ['foodalchemist_recipe_presentations', ['serving_vehicle_vocab_id']],
                ['foodalchemist_tableware_items', ['vehicle_vocab_id']],
            ],
            'equipment' => [
                ['foodalchemist_recipe_equipment', ['equipment_id']],
            ],
            default => [],
        };
    }

    /**
     * Zusätzlich die Import-Rohwerte: bei WaWi-Zeilen, deren `*_vocab_id` nie nachgezogen wurde,
     * ist die `legacy_id` der einzige Beleg für eine Nutzung.
     */
    private function legacyReferenzen(string $vokabular, object $zeile): int
    {
        if ($zeile->legacy_id === null || ! Schema::hasTable('foodalchemist_recipes')) {
            return 0;
        }

        $spalten = match ($vokabular) {
            'behaelter' => ['container_warm_legacy_id', 'container_cold_legacy_id'],
            'regen' => ['regeneration_device_legacy_id'],
            'vehikel' => ['serving_vehicle_legacy_id'],
            default => [],
        };
        $spalten = array_values(array_filter($spalten, fn (string $c) => Schema::hasColumn('foodalchemist_recipes', $c)));
        if ($spalten === []) {
            return 0;
        }

        return DB::table('foodalchemist_recipes')->whereNull('deleted_at')
            ->where(function ($q) use ($spalten, $zeile) {
                foreach ($spalten as $c) {
                    $q->orWhere($c, $zeile->legacy_id);
                }
            })->count();
    }

    public function render()
    {
        $listen = [];
        foreach (self::VOKABULARE as $key => $meta) {
            $listen[$key] = $meta + [
                'zeilen' => TeamScope::applyVisible(
                    DB::table($meta['tabelle'])->whereNull('deleted_at'),
                    'team_id', Auth::user()?->currentTeamRelation
                )->orderBy('group_name')->orderBy('sort_order')->orderBy('name')->get(),
            ];
        }

        return view('foodalchemist::livewire.settings.behaelter', ['listen' => $listen]);
    }
}
