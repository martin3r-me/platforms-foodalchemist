<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentationDesign;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;

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

    public const BUILTIN_SLUGS = ['editorial', 'menu', 'kiosk', 'navigator'];

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
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'show_chapter_image' => true, 'heading_rule' => true]],
                    ['block_type' => 'price_summary', 'style' => ['mode' => 'pro_person']],
                    ['block_type' => 'legend', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#6d28d9', 'accent' => '#b8874a', 'bg' => '#fbfaf8', 'surface' => 'rgba(26,23,18,0.04)', 'text' => '#1a1712', 'muted' => '#8a8178'],
                    'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 1.0],
                    'spacing' => 'roomy',
                    'nav' => 'anchor',
                    'lightbox' => true,
                ],
            ],
            'menu' => [
                'name' => 'Speisekarte',
                'base_slug' => 'menu',
                'output_types' => ['speisekarte'],
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true, 'compact' => true]],
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'show_chapter_image' => true, 'compact' => true]],
                    ['block_type' => 'legend', 'style' => ['compact' => true]],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#1a1712', 'accent' => '#b8874a', 'bg' => '#ffffff', 'surface' => 'rgba(0,0,0,0.03)', 'text' => '#1a1712', 'muted' => '#7a736a'],
                    'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 0.98],
                    'spacing' => 'comfortable',
                    'nav' => 'anchor',
                    'lightbox' => true,
                ],
            ],
            'kiosk' => [
                'name' => 'Kiosk',
                'base_slug' => 'kiosk',
                'output_types' => ['speiseplan', 'speisekarte'],
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true]],
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => false, 'dish_columns' => 1, 'show_chapter_image' => true]],
                    ['block_type' => 'legend', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#e8c07a', 'accent' => '#e8c07a', 'bg' => '#0b0a08', 'surface' => 'rgba(255,255,255,0.05)', 'text' => '#f6f2ea', 'muted' => '#b6ab99'],
                    'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 1.45],
                    'spacing' => 'roomy',
                    'auto_advance' => true,
                    'nav' => 'none',
                    'lightbox' => false,
                ],
            ],
            'navigator' => [
                'name' => 'Navigator',
                'base_slug' => 'navigator',
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true]],
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'show_chapter_image' => true, 'heading_rule' => true]],
                    ['block_type' => 'price_summary', 'style' => ['mode' => 'pro_person']],
                    ['block_type' => 'legend', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#0f766e', 'accent' => '#b8874a', 'bg' => '#faf9f6', 'surface' => 'rgba(15,23,20,0.04)', 'text' => '#141c19', 'muted' => '#71807a'],
                    'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 1.0],
                    'spacing' => 'roomy',
                    'nav' => 'sidebar',
                    'lightbox' => true,
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

    /** Sandboxed Custom-CSS des Designs (Stufe 2 „Leinwand via Code"); built-ins tragen keins. */
    public function resolveCss(?string $designSource, Team $team): ?string
    {
        $design = $this->findBySource($designSource, $team);

        return $design !== null ? $this->sanitizeCss($design->custom_css) : null;
    }

    /**
     * Härtet KI/User-CSS für die eigenständige, chrome-freie Präsentationsseite. CSS-ONLY:
     * verbietet `<`/`>` (kein </style>-Breakout → kein HTML/JS), `@import` (externe Ressourcen),
     * `expression(` (IE-Altlast) und `javascript:`. Rein optischer Layer auf die Blöcke.
     */
    public function sanitizeCss(?string $css): ?string
    {
        $css = trim((string) $css);
        if ($css === '') {
            return null;
        }
        // Kein Tag-Breakout.
        $css = str_replace(['<', '>'], '', $css);
        // Gefährliche/externe Konstrukte raus (case-insensitive).
        $css = preg_replace('/@import\b[^;]*;?/i', '', $css);
        $css = preg_replace('/expression\s*\(/i', '(', $css);
        $css = preg_replace('/javascript\s*:/i', '', $css);
        $css = preg_replace('/\bbehavior\s*:/i', '', $css);
        // Länge deckeln (Missbrauch/DoS-Schutz).
        if (strlen($css) > 60000) {
            $css = substr($css, 0, 60000);
        }

        return trim($css) !== '' ? $css : null;
    }

    /**
     * Bild für einen freien Bild-Block im Struktur-Builder ablegen (design-lokal, team-scoped).
     * Gibt Identifier zurück, die in der Block-`style` gespeichert werden (URL erst zur Render-Zeit).
     *
     * @return array{context_file_id:?int, path:?string}
     */
    public function storeBlockImage(Team $team, UploadedFile $file): array
    {
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.presentation_design', 0, 'foodalchemist/presentation_design',
        );

        return ['context_file_id' => $media['context_file_id'] ?? null, 'path' => $media['path'] ?? null];
    }

    /**
     * Stufe 2 (FA-KI-Self-Service): aus einem Freitext-Wunsch sandboxed CSS erzeugen.
     * Über den Core-Contract (AiGatewayService::propose); Ergebnis wird sanitisiert.
     *
     * @return array{css:string, confidence:?float, call_log_id:?int}
     */
    public function generateCss(Team $team, string $brief): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw new \RuntimeException('Bitte einen Look/Wunsch beschreiben.');
        }
        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'praesentation.design_css',
            ['brief' => $brief, 'ziel_klassen' => $this->cssZielKlassen()],
        );
        $css = $this->sanitizeCss((string) ($proposal->werte['css'] ?? ''));
        if ($css === null) {
            throw new \RuntimeException('Die KI hat kein gültiges CSS geliefert — bitte erneut versuchen.');
        }

        return ['css' => $css, 'confidence' => $proposal->confidence ?? null, 'call_log_id' => $proposal->callLogId ?? null];
    }

    /** Die fixen Präsentations-Selektoren, die die KI stylen darf (in den Prompt-Kontext). */
    private function cssZielKlassen(): array
    {
        return [
            'pt-page', 'pt-hero', 'pt-hero-media', 'pt-hero-inner', 'pt-hero-title', 'pt-hero-sub', 'pt-hero-meta',
            'pt-kicker', 'pt-cover-img', 'pt-logo', 'pt-section', 'pt-section-head', 'pt-section-title', 'pt-section-text',
            'pt-section-img', 'pt-block', 'pt-block-header', 'pt-block-sub', 'pt-line', 'pt-line-label', 'pt-line-price',
            'pt-line-dots', 'pt-codes', 'pt-price-summary', 'pt-price-big', 'pt-price-label', 'pt-legend', 'pt-cta-btn',
            'pt-footer', 'pt-grid',
            'var(--pt-primary)', 'var(--pt-accent)', 'var(--pt-bg)', 'var(--pt-text)', 'var(--pt-muted)',
            'var(--pt-heading-font)', 'var(--pt-body-font)',
        ];
    }

    // ── CRUD (team-gescopt) ────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistPresentationDesign> */
    public function list(Team $team): Collection
    {
        return FoodAlchemistPresentationDesign::visibleToTeam($team)
            ->orderBy('name')
            ->get();
    }

    /** Die drei Ausgabeformen, für die ein Design gelten kann. */
    public const OUTPUT_TYPES = ['foodbook', 'speisekarte', 'speiseplan'];

    /** Nur gültige Formen; leer/keine → null (= gilt für alle Formen). */
    private function sanitizeOutputTypes(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $clean = array_values(array_intersect(self::OUTPUT_TYPES, array_map('strval', $raw)));

        return $clean === [] ? null : $clean;
    }

    /** true, wenn das Design für die Form gilt (leer/null = alle Formen). */
    private function giltFuer(?array $outputTypes, ?string $type): bool
    {
        return $type === null || empty($outputTypes) || in_array($type, $outputTypes, true);
    }

    /**
     * Design-Picker-Optionen einer Ausgabeform: die Built-in-Starter (immer verfügbar)
     * + die team-sichtbaren, auf diese Form passenden DB-Designs.
     *
     * @return list<array{value:string, label:string}>
     */
    public function pickerOptions(Team $team, string $type): array
    {
        $optionen = [];
        foreach ($this->builtins() as $slug => $b) {
            if ($this->giltFuer($b['output_types'] ?? null, $type)) {
                $optionen[] = ['value' => $slug, 'label' => $b['name'] . ' (Vorlage)'];
            }
        }
        foreach ($this->list($team) as $d) {
            if ($this->giltFuer($d->output_types, $type)) {
                $optionen[] = ['value' => 'design:' . $d->id, 'label' => $d->name];
            }
        }

        return $optionen;
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
            'output_types' => $this->sanitizeOutputTypes($data['output_types'] ?? null),
            'layout_json' => $this->sanitizeLayout($data['layout_json'] ?? $this->builtins()[$base]['layout']),
            'tokens_json' => is_array($data['tokens_json'] ?? null) ? $data['tokens_json'] : $this->builtins()[$base]['tokens'],
            'custom_css' => $this->sanitizeCss($data['custom_css'] ?? null),
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
        if (array_key_exists('output_types', $data)) {
            $design->output_types = $this->sanitizeOutputTypes($data['output_types']);
        }
        if (array_key_exists('layout_json', $data) && is_array($data['layout_json'])) {
            $design->layout_json = $this->sanitizeLayout($data['layout_json']);
        }
        if (array_key_exists('tokens_json', $data) && is_array($data['tokens_json'])) {
            $design->tokens_json = $data['tokens_json'];
        }
        if (array_key_exists('custom_css', $data)) {
            $design->custom_css = $this->sanitizeCss($data['custom_css']);
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
