<?php

namespace Platform\FoodAlchemist\Services\GpPaket;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\GpPaketZaehler;
use Symfony\Component\Uid\UuidV7;

/** GP-Paket-Import · Kind-Tabellen (Formen, Texturen, Einheiten, Äquivalente): Plan + Schreiben. */
class GpPaketKinder
{
    // ── Kind-Tabellen ──────────────────────────────────────────────────────────────────────────────

    public function __construct(private readonly GpPaketValidator $v) {}

    /**
     * Fehlende Zeilen werden ergänzt; abweichende bestehende Zeilen nur aktualisiert, wenn der GP „frei“ ist (neu oder unverändert seit dem
     * letzten Lauf) — sonst als abweichend gezählt und nicht überschrieben.
     *
     * @param  array<string,array<string,mixed>>  $P
     */
    public function planeKinder(array &$P, array $paket, array $ctx, GpPaketZaehler $z, array &$listen): void
    {
        foreach ($P as &$g) {
            $g['kinder'] = ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []];
        }
        unset($g);
        $abgelehnt = function (string $datei, string $key, string $grund) use (&$P, $z, &$listen) {
            $wg = $P[$key]['wg'] ?? '--';
            $z->inc($wg, 'kind_abgelehnt');
            $listen['kinder_abgelehnt'][] = ['wg' => $wg, 'datei' => $datei, 'gp_key' => $key, 'grund' => $grund];
        };
        $gesehen = [];
        foreach ($paket['forms'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            if (! isset($P[$k])) {
                continue;                                                    // GP abgelehnt oder ausserhalb des Teillaufs
            }
            $slug = (string) ($r['form_slug'] ?? '');
            if (! isset($ctx['einheiten'][$slug])) {
                $abgelehnt('gp_forms', $k, "form_slug „{$slug}“ gibt es auf io nicht (vocab_units.slug)");

                continue;
            }
            if (! is_numeric($r['gramm'] ?? null) || (float) $r['gramm'] <= 0) {
                $abgelehnt('gp_forms', $k, "gramm für „{$slug}“ ungültig");

                continue;
            }
            if (isset($gesehen['f'][$k][$slug])) {
                $abgelehnt('gp_forms', $k, "form_slug „{$slug}“ doppelt im Paket");

                continue;
            }
            $gesehen['f'][$k][$slug] = true;
            $P[$k]['kinder']['forms'][$slug] = ['gramm' => (float) $r['gramm'], 'source' => $this->kuerze($r['source'] ?? 'manual', 16)];
        }
        foreach ($paket['textures'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            if (! isset($P[$k])) {
                continue;
            }
            $slug = (string) ($r['texture_slug'] ?? '');
            if (! isset($ctx['texturen'][$slug])) {
                $abgelehnt('gp_textures', $k, "texture_slug „{$slug}“ gibt es auf io nicht");

                continue;
            }
            if (isset($P[$k]['kinder']['textures'][$slug])) {
                $abgelehnt('gp_textures', $k, "texture_slug „{$slug}“ doppelt im Paket");

                continue;
            }
            $P[$k]['kinder']['textures'][$slug] = ['intensity' => is_numeric($r['intensity'] ?? null) ? (float) $r['intensity'] : 1.0, 'source' => $this->kuerze($r['source'] ?? null, 16)];
        }
        foreach ($paket['einheiten'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            if (! isset($P[$k])) {
                continue;
            }
            $slug = (string) ($r['unit_slug'] ?? '');
            if (! isset($ctx['einheiten'][$slug])) {
                $abgelehnt('gp_einheiten', $k, "unit_slug „{$slug}“ gibt es auf io nicht (vocab_units.slug)");

                continue;
            }
            if (! is_numeric($r['default_g'] ?? null) || isset($P[$k]['kinder']['einheiten'][$slug])) {
                $abgelehnt('gp_einheiten', $k, "default_g für „{$slug}“ ungültig oder Einheit doppelt");

                continue;
            }
            $P[$k]['kinder']['einheiten'][$slug] = ['default_g' => (float) $r['default_g'], 'is_primary' => (int) (bool) ($r['is_primary'] ?? false),
                'sort_order' => (int) ($r['sort_order'] ?? 0), 'source' => $this->kuerze($r['source'] ?? null, 16)];
        }
        foreach ($paket['aequi'] as $r) {
            $a = (string) ($r['source_gp_key'] ?? '');
            $b = (string) ($r['alt_gp_key'] ?? '');
            if (! isset($P[$a])) {
                continue;
            }
            if (! isset($P[$b]) && ! isset($ctx['bestand'][$b])) {
                $abgelehnt('gp_aequivalente', $a, "Gegen-GP {$b} ist nicht im Lauf und nicht auf io");

                continue;
            }
            $P[$a]['kinder']['aequi'][$b] = ['faktor' => is_numeric($r['umrechnungsfaktor'] ?? null) ? (float) $r['umrechnungsfaktor'] : 1.0,
                'seite' => in_array($r['standard_seite'] ?? 'source', ['source', 'alt'], true) ? $r['standard_seite'] ?? 'source' : 'source',
                'conf' => is_numeric($r['match_confidence'] ?? null) ? (float) $r['match_confidence'] : null, 'notes' => $r['notes'] ?? null];
        }

        // Abgleich gegen io (nur für bestehende GPs)
        $ids = array_values(array_filter(array_map(fn ($g) => $g['id'], $P)));
        $io = ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('foodalchemist_gp_forms')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io['forms'][$r->gp_id][$r->form_slug] = (array) $r;
            }
            foreach (DB::table('foodalchemist_gp_textures')->whereIn('gp_id', $chunk)->get() as $r) {
                $io['textures'][$r->gp_id][$r->texture_vocab_id] = (array) $r;
            }
            foreach (DB::table('foodalchemist_gp_count_unit_defaults')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io['einheiten'][$r->gp_id][$r->unit_vocab_id] = (array) $r;
            }
            foreach (DB::table('foodalchemist_component_equivalents')->whereNull('team_id')->where('source_kind', 'gp')->where('alt_kind', 'gp')
                ->whereIn('source_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io['aequi'][$r->source_id][$r->alt_id] = (array) $r;
            }
        }
        foreach ($P as $key => &$g) {
            $id = $g['id'];
            $frei = $g['aktion'] === 'neu' || ($g['frei'] ?? false);
            $wg = $g['wg'];
            $plan = ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []];
            foreach ($g['kinder']['forms'] as $slug => $w) {
                $ist = $id !== null ? ($io['forms'][$id][$slug] ?? null) : null;
                if ($ist === null) {
                    $plan['forms'][] = ['aktion' => 'neu', 'slug' => $slug] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->v->norm($ist['gramm']) === $this->v->norm($w['gramm']) && ($ist['source'] ?? null) === $w['source']) {
                    $z->inc($wg, 'kind_unveraendert');
                } elseif ($frei) {
                    $plan['forms'][] = ['aktion' => 'aendern', 'slug' => $slug, 'id' => (int) $ist['id'], 'vorher' => ['gramm' => $ist['gramm'], 'source' => $ist['source']]] + $w;
                    $z->inc($wg, 'kind_neu');
                } else {
                    $z->inc($wg, 'kind_abweichend');
                }
            }
            foreach ($g['kinder']['textures'] as $slug => $w) {
                $tid = $ctx['texturen'][$slug];
                $ist = $id !== null ? ($io['textures'][$id][$tid] ?? null) : null;
                if ($ist === null) {
                    $plan['textures'][] = ['aktion' => 'neu', 'slug' => $slug, 'texture_id' => $tid] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->v->norm($ist['intensity']) === $this->v->norm($w['intensity']) && ($ist['source'] ?? null) === $w['source']) {
                    $z->inc($wg, 'kind_unveraendert');
                } elseif ($frei) {
                    $plan['textures'][] = ['aktion' => 'aendern', 'slug' => $slug, 'texture_id' => $tid, 'id' => (int) $ist['id'], 'vorher' => ['intensity' => $ist['intensity'], 'source' => $ist['source']]] + $w;
                    $z->inc($wg, 'kind_neu');
                } else {
                    $z->inc($wg, 'kind_abweichend');
                }
            }
            foreach ($g['kinder']['einheiten'] as $slug => $w) {
                $uid = $ctx['einheiten'][$slug];
                $ist = $id !== null ? ($io['einheiten'][$id][$uid] ?? null) : null;
                if ($ist === null) {
                    $plan['einheiten'][] = ['aktion' => 'neu', 'slug' => $slug, 'unit_id' => $uid] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->v->norm($ist['default_g']) === $this->v->norm($w['default_g']) && (int) $ist['is_primary'] === $w['is_primary'] && (int) $ist['sort_order'] === $w['sort_order']) {
                    $z->inc($wg, 'kind_unveraendert');
                } elseif ($frei) {
                    $plan['einheiten'][] = ['aktion' => 'aendern', 'slug' => $slug, 'unit_id' => $uid, 'id' => (int) $ist['id'],
                        'vorher' => ['default_g' => $ist['default_g'], 'is_primary' => $ist['is_primary'], 'sort_order' => $ist['sort_order'], 'source' => $ist['source']]] + $w;
                    $z->inc($wg, 'kind_neu');
                } else {
                    $z->inc($wg, 'kind_abweichend');
                }
            }
            foreach ($g['kinder']['aequi'] as $altKey => $w) {
                $altId = isset($P[$altKey]) ? $P[$altKey]['id'] : ($ctx['bestand'][$altKey]['id'] ?? null);
                $ist = $id !== null && $altId !== null ? ($io['aequi'][$id][$altId] ?? null) : null;
                if ($ist === null) {
                    $plan['aequi'][] = ['aktion' => 'neu', 'alt_key' => $altKey] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->v->norm($ist['umrechnungsfaktor']) === $this->v->norm($w['faktor'])) {
                    $z->inc($wg, 'kind_unveraendert');
                } else {
                    $z->inc($wg, 'kind_abweichend');                              // Äquivalente: nur ergänzen, nie überschreiben
                }
            }
            $g['kind_plan'] = $plan;
        }
        unset($g);
    }

    private function kuerze(mixed $v, int $max): ?string
    {
        return $v === null || $v === '' ? null : mb_substr((string) $v, 0, $max);
    }

    /** @param array<string,mixed> $g @param array<string,mixed> $proto */
    public function schreibeKinder(array $g, array $P, array $ctx, string $ts, array &$proto): void
    {
        $id = $g['id'];
        foreach ($g['kind_plan']['forms'] ?? [] as $k) {
            if ($k['aktion'] === 'neu') {
                $alt = DB::table('foodalchemist_gp_forms')->where('gp_id', $id)->where('form_slug', $k['slug'])->first();
                if ($alt !== null) {                                              // weich gelöschte Zeile (Unique umfasst sie) beleben
                    DB::table('foodalchemist_gp_forms')->where('id', $alt->id)->update(['gramm' => $k['gramm'], 'source' => $k['source'] ?? 'manual', 'deleted_at' => null, 'updated_at' => $ts]);
                    $proto['kinder_geaendert']['forms'][] = ['id' => (int) $alt->id, 'vorher' => ['gramm' => $alt->gramm, 'source' => $alt->source, 'deleted_at' => $alt->deleted_at]];
                } else {
                    $nid = DB::table('foodalchemist_gp_forms')->insertGetId(['uuid' => (string) UuidV7::generate(), 'gp_id' => $id, 'form_slug' => $k['slug'], 'gramm' => $k['gramm'],
                        'source' => $k['source'] ?? 'manual', 'created_at' => $ts, 'updated_at' => $ts]);
                    $proto['kinder_neu']['forms'][] = (int) $nid;
                }
            } else {
                DB::table('foodalchemist_gp_forms')->where('id', $k['id'])->update(['gramm' => $k['gramm'], 'source' => $k['source'] ?? 'manual', 'updated_at' => $ts]);
                $proto['kinder_geaendert']['forms'][] = ['id' => $k['id'], 'vorher' => $k['vorher']];
            }
        }
        foreach ($g['kind_plan']['textures'] ?? [] as $k) {
            if ($k['aktion'] === 'neu') {
                $nid = DB::table('foodalchemist_gp_textures')->insertGetId(['gp_id' => $id, 'texture_vocab_id' => $k['texture_id'], 'intensity' => $k['intensity'], 'source' => $k['source'], 'created_at' => $ts, 'updated_at' => $ts]);
                $proto['kinder_neu']['textures'][] = (int) $nid;
            } else {
                DB::table('foodalchemist_gp_textures')->where('id', $k['id'])->update(['intensity' => $k['intensity'], 'source' => $k['source'], 'updated_at' => $ts]);
                $proto['kinder_geaendert']['textures'][] = ['id' => $k['id'], 'vorher' => $k['vorher']];
            }
        }
        foreach ($g['kind_plan']['einheiten'] ?? [] as $k) {
            if ($k['aktion'] === 'neu') {
                $alt = DB::table('foodalchemist_gp_count_unit_defaults')->where('gp_id', $id)->where('unit_vocab_id', $k['unit_id'])->first();
                if ($alt !== null) {
                    DB::table('foodalchemist_gp_count_unit_defaults')->where('id', $alt->id)->update(['default_g' => $k['default_g'], 'is_primary' => $k['is_primary'], 'sort_order' => $k['sort_order'], 'source' => $k['source'], 'deleted_at' => null, 'updated_at' => $ts]);
                    $proto['kinder_geaendert']['einheiten'][] = ['id' => (int) $alt->id, 'vorher' => ['default_g' => $alt->default_g, 'is_primary' => $alt->is_primary, 'sort_order' => $alt->sort_order, 'source' => $alt->source, 'deleted_at' => $alt->deleted_at]];
                } else {
                    $nid = DB::table('foodalchemist_gp_count_unit_defaults')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'gp_id' => $id, 'unit_vocab_id' => $k['unit_id'],
                        'default_g' => $k['default_g'], 'is_primary' => $k['is_primary'], 'sort_order' => $k['sort_order'], 'source' => $k['source'], 'created_at' => $ts, 'updated_at' => $ts]);
                    $proto['kinder_neu']['einheiten'][] = (int) $nid;
                }
            } else {
                DB::table('foodalchemist_gp_count_unit_defaults')->where('id', $k['id'])->update(['default_g' => $k['default_g'], 'is_primary' => $k['is_primary'], 'sort_order' => $k['sort_order'], 'source' => $k['source'], 'updated_at' => $ts]);
                $proto['kinder_geaendert']['einheiten'][] = ['id' => $k['id'], 'vorher' => $k['vorher']];
            }
        }
        foreach ($g['kind_plan']['aequi'] ?? [] as $k) {
            $altId = $P[$k['alt_key']]['id'] ?? ($ctx['bestand'][$k['alt_key']]['id'] ?? null);
            if ($altId === null) {
                continue;                                                         // Gegen-GP wurde nicht angelegt
            }
            $nid = DB::table('foodalchemist_component_equivalents')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'source_kind' => 'gp', 'source_id' => $id,
                'alt_kind' => 'gp', 'alt_id' => $altId, 'umrechnungsfaktor' => $k['faktor'], 'standard_seite' => $k['seite'], 'match_confidence' => $k['conf'], 'notes' => $k['notes'],
                'created_at' => $ts, 'updated_at' => $ts]);
            $proto['kinder_neu']['aequi'][] = (int) $nid;
        }
    }
}
