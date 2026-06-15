<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * R5 (Dominique): Behälter & Geräte als EIGENE Settings-Seite mit Anlegen —
 * die 3 Container-Vokabulare (D-6 §4.6) PLUS das Koch-Equipment der
 * Basisrezepte (D-5 §2.3) an einem Ort. Lösch-Schutz V-06: nur deaktivieren.
 */
class Behaelter extends Component
{
    /** Whitelist: vokabular-key => [tabelle, label, hat kapazitaet_kg] */
    public const VOKABULARE = [
        'behaelter' => ['tabelle' => 'foodalchemist_vocab_behaelter', 'label' => 'Behälter (GN & Co.)', 'kapazitaet' => true],
        'regen' => ['tabelle' => 'foodalchemist_vocab_regen_geraete', 'label' => 'Regenerations-Geräte', 'kapazitaet' => false],
        'vehikel' => ['tabelle' => 'foodalchemist_vocab_serviervehikel', 'label' => 'Servier-Vehikel', 'kapazitaet' => false],
        'equipment' => ['tabelle' => 'foodalchemist_vocab_kochequipment', 'label' => 'Koch-Equipment (Basisrezepte)', 'kapazitaet' => false],
    ];

    /** @var array<string, array{name: string, gruppe: string, kapazitaet_kg: string}> Add-Form je Vokabular */
    public array $neu = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        foreach (array_keys(self::VOKABULARE) as $key) {
            $this->neu[$key] = ['name' => '', 'gruppe' => '', 'kapazitaet_kg' => ''];
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
        if (DB::table($meta['tabelle'])->where('slug', $slug)->whereNull('deleted_at')->exists()) {
            $this->fehler = "«{$name}» existiert schon in {$meta['label']} ({$slug}).";

            return;
        }

        $zeile = [
            'uuid' => (string) Str::uuid7(),
            'team_id' => Auth::user()?->currentTeamRelation?->id,
            'slug' => $slug,
            'name' => $name,
            'gruppe' => trim($this->neu[$vokabular]['gruppe'] ?? '') ?: null,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($meta['kapazitaet']) {
            $kap = str_replace(',', '.', trim($this->neu[$vokabular]['kapazitaet_kg'] ?? ''));
            $zeile['kapazitaet_kg'] = is_numeric($kap) ? (float) $kap : null;
        }
        DB::table($meta['tabelle'])->insert($zeile);

        $this->neu[$vokabular] = ['name' => '', 'gruppe' => '', 'kapazitaet_kg' => ''];
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
        $zeile = DB::table($meta['tabelle'])->where('id', $id)->first(['is_inactive']);
        if ($zeile !== null) {
            DB::table($meta['tabelle'])->where('id', $id)
                ->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);
            $this->meldung = 'Aktualisiert — inaktive Einträge bleiben an Rezepten sichtbar (V-06).';
        }
    }

    /** Phase 5: hart löschen, wenn von keinem Rezept genutzt (sonst gesperrt → deaktivieren). */
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
        $team = Auth::user()?->currentTeamRelation;
        if ($zeile->team_id !== null && $team !== null && (int) $zeile->team_id !== (int) $team->id) {
            $this->fehler = 'Geerbter Eintrag — nur das Besitzer-Team kann löschen.';

            return;
        }
        $n = $this->referenzen($vokabular, $zeile);
        if ($n > 0) {
            $this->fehler = "«{$zeile->name}» wird von {$n} Rezept(en) genutzt — erst umhängen oder deaktivieren.";

            return;
        }
        DB::table($meta['tabelle'])->where('id', $id)->delete();
        $this->fehler = null;
        $this->meldung = "«{$zeile->name}» gelöscht.";
    }

    /** Rezept-Nutzungen je Vokabular (Behälter/Regen/Vehikel via legacy_id, Equipment via Pivot). */
    private function referenzen(string $vokabular, object $zeile): int
    {
        $recipeRef = function (array $cols) use ($zeile): int {
            if ($zeile->legacy_id === null) {
                return 0;
            }

            return DB::table('foodalchemist_recipes')->whereNull('deleted_at')
                ->where(function ($q) use ($cols, $zeile) {
                    foreach ($cols as $c) {
                        $q->orWhere($c, $zeile->legacy_id);
                    }
                })->count();
        };

        return match ($vokabular) {
            'behaelter' => $recipeRef(['behaelter_warm_legacy_id', 'behaelter_kalt_legacy_id']),
            'regen' => $recipeRef(['regeneration_geraet_legacy_id']),
            'vehikel' => $recipeRef(['servier_vehikel_legacy_id']),
            'equipment' => \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_recipe_equipment')
                ? DB::table('foodalchemist_recipe_equipment')->where('equipment_id', $zeile->id)->count() : 0,
            default => 0,
        };
    }

    public function render()
    {
        $listen = [];
        foreach (self::VOKABULARE as $key => $meta) {
            $listen[$key] = $meta + [
                'zeilen' => DB::table($meta['tabelle'])->whereNull('deleted_at')
                    ->orderBy('gruppe')->orderBy('sort_order')->orderBy('name')->get(),
            ];
        }

        return view('foodalchemist::livewire.settings.behaelter', ['listen' => $listen]);
    }
}
