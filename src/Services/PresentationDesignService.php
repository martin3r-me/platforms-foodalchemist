<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentationDesign;

/**
 * Spec 43 — Präsentations-Designs: wiederverwendbare, blockbasierte Layout-Definitionen
 * (Ausgabe des visuellen Struktur-Builders) + die 3 Built-in-Starter (editorial/menu/kiosk).
 *
 * Ein Design = geordnete Blockliste (`layout_json`) + globale Style-Tokens (`tokens_json`).
 * Die Blöcke sind DATENGEBUNDEN: die Palette kennt nur allowlist-sichere Kundendaten
 * (kein „EK-Block") — die Interna-Freiheit ist damit strukturell, nicht bloß gefiltert.
 *
 * Auflösung (resolveLayout/resolveTokens) ist die EINE Stelle, an der aus einer
 * `presentation_design`-Quelle (Slug oder `design:{id}`) ein konkretes Layout + Tokens
 * werden — genutzt von previewData (live) UND buildSnapshot (eingefroren).
 */
class PresentationDesignService
{
    /** Erlaubte Block-Typen (Whitelist — Renderer wählt Partial per match, kein user-Pfad). */
    public const BLOCK_TYPES = [
        'cover', 'chapter_loop', 'dish_list', 'price_summary', 'legend', 'grid',
        'text', 'heading', 'image', 'spacer', 'cta',
    ];

    public const BUILTIN_SLUGS = ['editorial', 'menu', 'kiosk'];

    /**
     * Die 3 Built-in-Starter als reine Layout-Definitionen (Code-Seeds, keine DB-Zeilen).
     *
     * @return array<string, array{name:string, base_slug:string, layout:list<array>, tokens:array}>
     */
    public function builtins(): array
    {
        return [
            'editorial' => [
                'name' => 'Editorial',
                'base_slug' => 'editorial',
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true]],
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'heading_rule' => true]],
                    ['block_type' => 'price_summary', 'style' => ['mode' => 'pro_person']],
                    ['block_type' => 'legend', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#6d28d9', 'accent' => '#6d28d9', 'bg' => '#ffffff', 'text' => '#111827', 'muted' => '#6b7280'],
                    'typography' => ['heading' => 'serif', 'body' => 'sans', 'scale' => 1.0],
                    'spacing' => 'comfortable',
                ],
            ],
            'menu' => [
                'name' => 'Speisekarte',
                'base_slug' => 'menu',
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => false, 'show_logo' => true, 'compact' => true]],
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'compact' => true]],
                    ['block_type' => 'legend', 'style' => ['compact' => true]],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#111827', 'accent' => '#6d28d9', 'bg' => '#ffffff', 'text' => '#111827', 'muted' => '#6b7280'],
                    'typography' => ['heading' => 'sans', 'body' => 'sans', 'scale' => 0.95],
                    'spacing' => 'compact',
                ],
            ],
            'kiosk' => [
                'name' => 'Kiosk',
                'base_slug' => 'kiosk',
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true]],
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => false, 'dish_columns' => 1]],
                    ['block_type' => 'legend', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#6d28d9', 'accent' => '#f59e0b', 'bg' => '#0b1020', 'text' => '#f8fafc', 'muted' => '#94a3b8'],
                    'typography' => ['heading' => 'sans', 'body' => 'sans', 'scale' => 1.4],
                    'spacing' => 'roomy',
                    'auto_advance' => true,
                ],
            ],
        ];
    }

    /**
     * Löst eine Design-Quelle in die konkrete Layout-Blockliste auf.
     * Slug (editorial/menu/kiosk) → Built-in; `design:{id}` → eigenes/globales Design.
     * Fällt bei Unbekanntem auf editorial zurück (nie leerer Render).
     *
     * @return list<array{block_type:string, style:array}>
     */
    public function resolveLayout(?string $designSource, Team $team): array
    {
        $design = $this->findBySource($designSource, $team);
        if ($design !== null && is_array($design->layout_json) && $design->layout_json !== []) {
            return $this->sanitizeLayout($design->layout_json);
        }

        $slug = $this->baseSlug($designSource);
        $builtins = $this->builtins();

        return $this->sanitizeLayout(($builtins[$slug] ?? $builtins['editorial'])['layout']);
    }

    /**
     * Löst die Style-Tokens auf: Built-in/Design-Tokens ← Branding-Override ← Settings-Override.
     *
     * @param  array{color?:?string, band?:?string}  $branding
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    public function resolveTokens(?string $designSource, Team $team, array $branding = [], array $settings = []): array
    {
        $slug = $this->baseSlug($designSource);
        $builtins = $this->builtins();
        $tokens = ($builtins[$slug] ?? $builtins['editorial'])['tokens'];

        $design = $this->findBySource($designSource, $team);
        if ($design !== null && is_array($design->tokens_json)) {
            $tokens = $this->deepMerge($tokens, $design->tokens_json);
        }

        // Bewusst KEIN Branding-Farb-Override: das Design besitzt die Palette (die im Builder
        // gewählte Farbe gewinnt). `brand_color` hat einen DB-Default (#6d28d9) — würde es die
        // Palette übersteuern, wäre jede Design-Farbe wirkungslos, solange der Default steht.
        // Branding liefert Logo/Cover/Footer (siehe brandingIdentifiers), nicht die Farben.

        // Settings-Overrides (z.B. explizite Token-Overrides pro Output).
        if (isset($settings['tokens']) && is_array($settings['tokens'])) {
            $tokens = $this->deepMerge($tokens, $settings['tokens']);
        }

        return $tokens;
    }

    // ── CRUD (team-gescopt) ────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistPresentationDesign> */
    public function list(Team $team): Collection
    {
        return FoodAlchemistPresentationDesign::visibleToTeam($team)
            ->orderBy('name')
            ->get();
    }

    public function find(Team $team, int $id): ?FoodAlchemistPresentationDesign
    {
        return FoodAlchemistPresentationDesign::visibleToTeam($team)->find($id);
    }

    /**
     * @param  array{name:string, base_slug?:?string, layout_json?:array, tokens_json?:array}  $data
     */
    public function create(Team $team, array $data): FoodAlchemistPresentationDesign
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Design-Name fehlt.');
        }
        $base = $this->baseSlug($data['base_slug'] ?? 'editorial');

        return FoodAlchemistPresentationDesign::create([
            'team_id' => $team->id,
            'name' => $name,
            'base_slug' => $base,
            'layout_json' => $this->sanitizeLayout($data['layout_json'] ?? $this->builtins()[$base]['layout']),
            'tokens_json' => is_array($data['tokens_json'] ?? null) ? $data['tokens_json'] : $this->builtins()[$base]['tokens'],
            'is_default' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Team $team, int $id, array $data): FoodAlchemistPresentationDesign
    {
        $design = FoodAlchemistPresentationDesign::visibleToTeam($team)->findOrFail($id);
        $this->guard($design, $team);

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $design->name = trim((string) $data['name']);
        }
        if (array_key_exists('base_slug', $data)) {
            $design->base_slug = $this->baseSlug($data['base_slug']);
        }
        if (array_key_exists('layout_json', $data) && is_array($data['layout_json'])) {
            $design->layout_json = $this->sanitizeLayout($data['layout_json']);
        }
        if (array_key_exists('tokens_json', $data) && is_array($data['tokens_json'])) {
            $design->tokens_json = $data['tokens_json'];
        }
        $design->save();

        return $design;
    }

    public function delete(Team $team, int $id): void
    {
        $design = FoodAlchemistPresentationDesign::visibleToTeam($team)->findOrFail($id);
        $this->guard($design, $team);
        $design->delete();
    }

    /** Dupliziert ein Built-in ODER ein sichtbares Design als eigenen, editierbaren Startpunkt. */
    public function duplicate(Team $team, string $source, ?string $name = null): FoodAlchemistPresentationDesign
    {
        $existing = $this->findBySource($source, $team);
        if ($existing !== null) {
            return $this->create($team, [
                'name' => $name ?: ($existing->name . ' (Kopie)'),
                'base_slug' => $existing->base_slug,
                'layout_json' => $existing->layout_json,
                'tokens_json' => $existing->tokens_json,
            ]);
        }

        $slug = $this->baseSlug($source);
        $b = $this->builtins()[$slug] ?? $this->builtins()['editorial'];

        return $this->create($team, [
            'name' => $name ?: $b['name'],
            'base_slug' => $b['base_slug'],
            'layout_json' => $b['layout'],
            'tokens_json' => $b['tokens'],
        ]);
    }

    /** Öffentlicher Härter für Roh-Layouts (Struktur-Builder-Vorschau). */
    public function normalizeLayout(array $layout): array
    {
        return $this->sanitizeLayout($layout);
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function guard(FoodAlchemistPresentationDesign $design, Team $team): void
    {
        if (! $design->isOwnedBy($team)) {
            throw new \RuntimeException('Design gehört einem anderen Team (nur lesbar).');
        }
    }

    /** `design:{id}` → Design-Row (visibleToTeam); Slug/leer → null. */
    private function findBySource(?string $source, Team $team): ?FoodAlchemistPresentationDesign
    {
        $source = (string) $source;
        if (! str_starts_with($source, 'design:')) {
            return null;
        }
        $id = (int) substr($source, strlen('design:'));

        return $id > 0 ? FoodAlchemistPresentationDesign::visibleToTeam($team)->find($id) : null;
    }

    /** Ausgangs-Slug einer Quelle (design:{id} → dessen base_slug via Aufrufer; sonst der Slug selbst). */
    private function baseSlug(?string $source): string
    {
        $source = trim((string) $source);
        if ($source === '' || str_starts_with($source, 'design:')) {
            return 'editorial';
        }

        return in_array($source, self::BUILTIN_SLUGS, true) ? $source : 'editorial';
    }

    /**
     * Härtet eine Layout-Liste: nur bekannte Block-Typen, Style als Array.
     *
     * @param  array<int,mixed>  $layout
     * @return list<array{block_type:string, style:array}>
     */
    private function sanitizeLayout(array $layout): array
    {
        $out = [];
        foreach ($layout as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['block_type'] ?? '');
            if (! in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            $out[] = [
                'block_type' => $type,
                'style' => is_array($block['style'] ?? null) ? $block['style'] : [],
            ];
        }

        return $out !== [] ? $out : $this->builtins()['editorial']['layout'];
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = $this->deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
