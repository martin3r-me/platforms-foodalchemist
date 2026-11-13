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
        'cover', 'chapter_loop', 'dish_list', 'price_summary', 'preis_aufschluesselung', 'legend', 'grid',
        'text', 'heading', 'image', 'spacer', 'cta',
        // Spec 94: Konfigurator-Schritte (nur für die Ausgabeform `konfigurator`, je genau einmal)
        'konfig_konzepte', 'konfig_tageszeit', 'konfig_addons', 'konfig_getraenke', 'konfig_zusammenfassung', 'konfig_anfrage',
    ];

    /** Spec 94: Pflicht-Blöcke des Konfigurators in Standard-Reihenfolge. */
    public const KONFIG_BLOCKS = ['konfig_konzepte', 'konfig_tageszeit', 'konfig_addons', 'konfig_getraenke', 'konfig_zusammenfassung', 'konfig_anfrage'];

    /** Spec 94: Blöcke, die im Konfigurator nichts bedeuten (Kapitel-, Raster- und Preisblöcke anderer Ausgaben). */
    private const KONFIG_FREMD = ['chapter_loop', 'dish_list', 'grid', 'price_summary', 'preis_aufschluesselung', 'legend'];

    /**
     * Google-Fonts-Whitelist für tokens.typography (heading/body). Externe CSS-Ressourcen bleiben im Custom-CSS
     * gesperrt (@import), Schriften laden NUR über diese Liste — Familie → Google-Fonts-Achsen + Fallback-Stack.
     */
    public const GOOGLE_FONTS = [
        'Cormorant Garamond' => ['ital,wght@0,400;0,500;0,600;0,700;1,400', 'Georgia, "Times New Roman", serif'],
        'Playfair Display' => ['ital,wght@0,400;0,600;0,700;0,900;1,400', 'Georgia, serif'],
        'Fraunces' => ['opsz,wght@9..144,400;9..144,600;9..144,900', 'Georgia, serif'],
        'Libre Baskerville' => ['ital,wght@0,400;0,700;1,400', 'Georgia, serif'],
        'Lora' => ['ital,wght@0,400;0,600;0,700;1,400', 'Georgia, serif'],
        'EB Garamond' => ['ital,wght@0,400;0,600;0,700;1,400', 'Georgia, serif'],
        'DM Serif Display' => ['ital@0;1', 'Georgia, serif'],
        'Bodoni Moda' => ['ital,opsz,wght@0,6..96,400;0,6..96,700;1,6..96,400', 'Georgia, serif'],
        'Marcellus' => ['', 'Georgia, serif'],
        'Manrope' => ['wght@300;400;500;600;700', 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'],
        'Inter' => ['wght@300;400;500;600;700', 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'],
        'DM Sans' => ['opsz,wght@9..40,400;9..40,500;9..40,700', 'system-ui, sans-serif'],
        'Work Sans' => ['wght@300;400;500;600;700', 'system-ui, sans-serif'],
        'Montserrat' => ['wght@300;400;500;600;700', 'system-ui, sans-serif'],
        'Lato' => ['wght@300;400;700', 'system-ui, sans-serif'],
        'Open Sans' => ['wght@300;400;600;700', 'system-ui, sans-serif'],
        'Raleway' => ['wght@300;400;500;600;700', 'system-ui, sans-serif'],
        'Nunito Sans' => ['wght@300;400;600;700', 'system-ui, sans-serif'],
        'Josefin Sans' => ['wght@300;400;600;700', 'system-ui, sans-serif'],
        'Outfit' => ['wght@300;400;500;600;700', 'system-ui, sans-serif'],
        'Space Grotesk' => ['wght@400;500;700', 'system-ui, sans-serif'],
        'Jost' => ['wght@300;400;500;600;700', 'system-ui, sans-serif'],
    ];

    /**
     * Google-Font aus der Whitelist auflösen (case-insensitive). Liefert [Familie, CSS-Stack, css2-Familien-Parameter] oder null.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    public static function googleFont(?string $wert): ?array
    {
        $wert = trim((string) $wert);
        foreach (self::GOOGLE_FONTS as $familie => [$achsen, $fallback]) {
            if (strcasecmp($familie, $wert) === 0) {
                return [$familie, '"' . $familie . '", ' . $fallback, str_replace(' ', '+', $familie) . ($achsen !== '' ? ':' . $achsen : '')];
            }
        }

        return null;
    }

    public const BUILTIN_SLUGS = ['editorial', 'menu', 'kiosk', 'navigator', 'angebot', 'konfigurator', 'konfigurator_dunkel'];

    // ── MCP-Vokabular (damit die MCP-Tools alle setzbaren Keys dokumentieren) ──

    /** Doku für tokens_json (globale Style-Tokens) — in die MCP-Schemas eingespeist. */
    public static function tokensVocabDoc(): string
    {
        return 'Globale Style-Tokens (Objekt). Keys: '
            . 'palette{primary,accent,bg,surface,text,muted} = Hex-Farben (z.B. accent "#b02530"); '
            . 'typography{heading:display-serif|serif|sans|mono ODER ein Google-Font-Name aus der Whitelist, body:sans|serif|mono ODER Google-Font, scale:0.7–2.0} '
            . '— Google-Fonts-Whitelist: ' . implode(', ', array_keys(self::GOOGLE_FONTS)) . ' (z. B. heading:"Cormorant Garamond", body:"Manrope") '
            . 'ODER eigene Team-Schrift als "team:<Familie>" (hochladen via foodalchemist.presentation_fonts.UPLOAD); '
            . 'spacing:compact|comfortable|roomy; '
            . 'nav:none|anchor|sidebar (Sprungmenü/Sidebar); '
            . 'lightbox:bool (Bilder klickbar vergrößern); '
            . 'band_style:grid|rondell (Kapitel-Bild-Band als Raster oder Karussell); '
            . 'speiseplan_layout:grid|liste (nur Speiseplan: Wochen-Tabelle oder Tag-Liste); '
            . 'auto_advance:bool (Kiosk-Autoplay).';
    }

    /** Doku für layout_json (geordnete Blockliste) — in die MCP-Schemas eingespeist. */
    public static function layoutVocabDoc(): string
    {
        return 'Geordnete Blockliste: [{block_type, style{}}]. block_type ∈ '
            . 'cover|chapter_loop|dish_list|price_summary|legend|grid|text|heading|image|spacer|cta|konfig_konzepte|konfig_tageszeit|konfig_getraenke|konfig_addons|konfig_zusammenfassung|konfig_anfrage. '
            . 'style je Typ: '
            . 'cover{align, show_cover_image:bool, show_logo:bool, cover_fit:cover|contain, cover_height:10-100 (% der Fensterhöhe; Altwerte klein|mittel|gross werden weiter verstanden), cover_height_max_px:int|null, compact:bool}; '
            . 'chapter_loop/dish_list{show_price:bool, show_codes:bool, show_dish_photos:bool, show_chapter_image:bool, dish_columns:1|2, show_kicker:bool}; '
            . 'image{quelle:design|angebot_1|angebot_2|angebot_3 (angebot_N = Präsentationsbild N des Angebots, via bilder.UPLOAD ziel=angebot_bild), context_file_id, path, img_fit:cover|contain|auto, img_height:klein|mittel|gross, img_width:voll|schmal|bleed}; '
            . 'text/heading{text}; spacer{height}; cta{text, link}; price_summary{mode:pro_person}; legend/grid{}; '
            . 'konfig_konzepte{ansicht:raster|liste, show_bild:bool, show_ab_preis:bool}; konfig_tageszeit/konfig_getraenke/konfig_addons/konfig_anfrage{titel}; konfig_zusammenfassung{show_gesamt:bool} '
            . '(Spec 94: nur Ausgabeform konfigurator, je genau einmal; fehlende werden beim Rendern ergänzt). '
            . 'Datengebunden & interna-frei — es gibt keinen EK-/Marge-Block.';
    }

    /** Doku für custom_css (Stufe-2-Leinwand) — in die MCP-Schemas eingespeist. */
    public static function customCssDoc(): string
    {
        return 'JA, freies CSS ist möglich (sandboxed, CSS-only). Wirkt auf die festen Präsentations-Klassen: '
            . self::cssKlassenDoc()
            . ' CSS-Variablen: var(--pt-primary), --pt-accent, --pt-bg, --pt-text, --pt-muted, --pt-line, --pt-heading-font, --pt-body-font, --pt-gap. '
            . 'Kind-Selektoren (>) sind erlaubt. Gestrippt: <, @import, expression(), javascript:, behavior: (kein HTML/JS, keine externen Ressourcen). '
            . 'Eigene Schriften NICHT per @import, sondern über tokens_json.typography (Google-Fonts-Whitelist).';
    }

    /** Alle stylbaren Präsentations-Klassen, gruppiert (MCP-Doku + KI-Prompt). */
    public static function cssKlassenDoc(): string
    {
        return 'Seite: pt-page, pt-wrap, pt-footer · '
            . 'Cover: pt-hero (.has-media/.no-media/.pt-hero--ohne-titel), pt-hero-media, pt-hero-inner, pt-hero-logo, pt-kicker, pt-hero-title, pt-hero-sub, pt-hero-meta · '
            . 'Kapitel: pt-section, pt-depth-N, pt-section-head, pt-section-title, pt-section-text, pt-section-img · '
            . 'Block: pt-block, pt-block--{concept_ref|text|header|position|recipe_ref}, pt-block-header, pt-block-header--{kind}, pt-block-sub · '
            . 'Text-Blöcke: pt-text-block (--angebot = Freitext im Angebot, --konzept = Text-Block aus dem Konzept), pt-text-block-title, pt-text-block-body · '
            . 'Zeilen: pt-line, pt-line--concept (Konzept mit Preis), pt-line--paket (Paket/Station, Label .pt-paket-name), pt-line--dish (Gericht, Label .pt-dish-name), '
            . 'pt-item, pt-indent-0|1|2, pt-line-label, pt-line-dots, pt-line-price, pt-item-sub, pt-subheader (Gang-Überschrift im Konzept), pt-codes, pt-item-foto · '
            . 'Preis: pt-price-summary, pt-price-big, pt-price-label · Sonstiges: pt-legend, pt-cta-btn, pt-grid, pt-cover-img, pt-logo.';
    }

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
                    ['block_type' => 'preis_aufschluesselung', 'style' => []],
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
            'angebot' => [
                'name' => 'Angebot',
                'base_slug' => 'angebot',
                'output_types' => ['angebot'],
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true]],
                    // Kunden-Sicht: Kicker „Leistung" statt „Kapitel" (Freitext-Override, leer = „Kapitel"/„Abschnitt").
                    ['block_type' => 'chapter_loop', 'style' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'show_chapter_image' => true, 'heading_rule' => true, 'kicker_haupt' => 'Leistung', 'kicker_unter' => '']],
                    ['block_type' => 'preis_aufschluesselung', 'style' => ['preis_anzeige' => 'beide']],
                    ['block_type' => 'price_summary', 'style' => ['mode' => 'pro_person', 'preis_anzeige' => 'beide']],
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
            // Spec 94 Stufe 5: dunkles Beispiel im PRO.KOPF-Stil — nur Tokens, im Builder änderbar und duplizierbar.
            'konfigurator_dunkel' => [
                'name' => 'Konfigurator dunkel (PRO.KOPF-Stil)',
                'base_slug' => 'konfigurator',
                'output_types' => ['konfigurator'],
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'left', 'show_cover_image' => true, 'show_logo' => true]],   // Deckblatt auch dunkel, wenn hochgeladen (Dominique 08.11.2026)
                    ['block_type' => 'konfig_konzepte', 'style' => ['ansicht' => 'raster', 'show_bild' => true, 'show_ab_preis' => true]],
                    ['block_type' => 'konfig_tageszeit', 'style' => []],
                    ['block_type' => 'konfig_addons', 'style' => []],
                    ['block_type' => 'konfig_getraenke', 'style' => []],
                    ['block_type' => 'konfig_zusammenfassung', 'style' => ['show_gesamt' => true]],
                    ['block_type' => 'konfig_anfrage', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#f2efe9', 'accent' => '#8fb0d4', 'bg' => '#121212', 'surface' => 'rgba(255,255,255,0.04)', 'text' => '#f2efe9', 'muted' => '#9a948a'],
                    'typography' => ['heading' => 'sans', 'body' => 'sans', 'scale' => 1.0],
                    'spacing' => 'comfortable',
                    'nav' => 'none',
                    'lightbox' => false,
                ],
            ],
            // Spec 94: Kundenlink des Konfigurators — Cover, dann die fünf Konfigurator-Schritte.
            'konfigurator' => [
                'name' => 'Konfigurator',
                'base_slug' => 'konfigurator',
                'output_types' => ['konfigurator'],
                'layout' => [
                    ['block_type' => 'cover', 'style' => ['align' => 'left', 'show_cover_image' => true, 'show_logo' => true]],
                    ['block_type' => 'konfig_konzepte', 'style' => ['ansicht' => 'raster', 'show_bild' => true, 'show_ab_preis' => true]],
                    ['block_type' => 'konfig_tageszeit', 'style' => []],
                    ['block_type' => 'konfig_addons', 'style' => []],
                    ['block_type' => 'konfig_getraenke', 'style' => []],
                    ['block_type' => 'konfig_zusammenfassung', 'style' => ['show_gesamt' => true]],
                    ['block_type' => 'konfig_anfrage', 'style' => []],
                ],
                'tokens' => [
                    'palette' => ['primary' => '#1f2a44', 'accent' => '#b8874a', 'bg' => '#fbfaf8', 'surface' => 'rgba(26,23,18,0.04)', 'text' => '#1a1712', 'muted' => '#8a8178'],
                    'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 1.0],
                    'spacing' => 'comfortable',
                    'nav' => 'none',
                    'lightbox' => false,
                ],
            ],
        ];
    }

    /**
     * Spec 94: Layout für den Konfigurator — fremde Inhaltsblöcke raus, jeder Konfigurator-Block genau einmal; fehlende
     * werden in Standard-Reihenfolge angehängt. So rendert jedes gewählte Design einen vollständigen Konfigurator.
     *
     * @param  list<array{block_type:string, style:array}>  $layout
     * @return list<array{block_type:string, style:array}>
     */
    public static function konfiguratorLayout(array $layout): array
    {
        $out = [];
        $gesehen = [];
        foreach ($layout as $b) {
            $t = $b['block_type'] ?? '';
            if (in_array($t, self::KONFIG_FREMD, true) || (in_array($t, self::KONFIG_BLOCKS, true) && isset($gesehen[$t]))) {
                continue;
            }
            $gesehen[$t] = true;
            if ($t === 'cover') {   // Stufe 5: kompakter Kopf (Titel, Claim, Ort), keine feste Höhe — am Handy kein halber Bildschirm Weißraum
                unset($b['style']['cover_height'], $b['style']['cover_height_max_px']);
            }
            $out[] = $b;
        }
        foreach (self::KONFIG_BLOCKS as $t) {
            if (isset($gesehen[$t])) {
                continue;
            }
            // Spec 94c: ein neu hinzugekommener Block steht vor dem nächsten vorhandenen (Getränke vor den Extras), nicht am Ende
            $danach = array_slice(self::KONFIG_BLOCKS, array_search($t, self::KONFIG_BLOCKS, true) + 1);
            $pos = collect($out)->search(fn ($b) => in_array($b['block_type'] ?? '', $danach, true));
            array_splice($out, $pos === false ? count($out) : $pos, 0, [['block_type' => $t, 'style' => []]]);
        }

        return $out;
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

        // Spec 94: Schrift auf Primärflächen (CTA, Weiter, gewählte Chips) aus den Design-Farben mit dem besten Kontrast —
        // nie fest Weiß. Gilt für jedes Design, auch eigene und dunkle.
        $pal = is_array($tokens['palette'] ?? null) ? $tokens['palette'] : [];
        $tokens['palette']['on_primary'] = self::schriftAuf((string) ($pal['primary'] ?? '#6d28d9'), [(string) ($pal['bg'] ?? ''), (string) ($pal['text'] ?? '')]);

        return $tokens;
    }

    /**
     * Druck auf weißem Papier (Dominique 08.11.2026, Konfigurator „Glorious Bastards“ im dunklen Design): Designfarben, die auf Weiß zu
     * schwach sind, bekommen NUR im Druck eine lesbare Ersatzfarbe — der Bildschirm bleibt unverändert. Schwellen nach WCAG: Text,
     * Primär und Nebentext 4,5, Akzent 3 (wird abgedunkelt statt ersetzt, damit die Markenfarbe erkennbar bleibt). Leer = nichts zu tun.
     *
     * @param  array<string, mixed>  $palette
     * @return array<string, string> CSS-Variable (ohne --pt-) => Wert
     */
    public static function druckErsatzfarben(array $palette): array
    {
        $aus = [];
        foreach (['text' => [4.5, '#1a1712'], 'primary' => [4.5, '#1a1712'], 'muted' => [4.5, '#5f5850'], 'accent' => [3.0, null]] as $rolle => [$min, $ersatz]) {
            $wert = (string) ($palette[$rolle] ?? '');
            $k = $wert !== '' ? self::kontrast($wert, '#ffffff') : null;
            if ($k !== null && $k < $min) {
                $aus[$rolle] = $ersatz ?? 'color-mix(in srgb, ' . $wert . ' 55%, #000)';
            }
        }
        if ($aus !== []) {
            $aus['bg'] = '#ffffff';
            $aus['surface'] = 'rgba(0,0,0,0.035)';
        }

        return $aus;
    }

    /** Kontrastverhältnis nach WCAG (1–21) zweier Farben (#rgb, #rrggbb, rgb()/rgba()); null, wenn eine nicht lesbar ist. */
    public static function kontrast(string $a, string $b): ?float
    {
        $la = self::luminanz($a);
        $lb = self::luminanz($b);
        if ($la === null || $lb === null) {
            return null;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Schriftfarbe für eine Fläche: der Kandidat aus dem Design mit dem höchsten Kontrast; erreicht keiner 4,5,
     * dann Schwarz oder Weiß, je nachdem, was mehr Kontrast bringt.
     *
     * @param  list<string>  $kandidaten
     */
    public static function schriftAuf(string $flaeche, array $kandidaten): string
    {
        $beste = null;
        $besterWert = 0.0;
        foreach (array_filter($kandidaten) as $k) {
            $w = self::kontrast($flaeche, $k);
            if ($w !== null && $w > $besterWert) {
                [$beste, $besterWert] = [$k, $w];
            }
        }
        if ($beste !== null && $besterWert >= 4.5) {
            return $beste;
        }
        $weiss = self::kontrast($flaeche, '#ffffff') ?? 21.0;
        $schwarz = self::kontrast($flaeche, '#111111') ?? 1.0;

        return $weiss >= $schwarz ? '#ffffff' : '#111111';
    }

    private static function luminanz(string $farbe): ?float
    {
        $f = strtolower(trim($farbe));
        if (preg_match('/^#([0-9a-f]{3})$/', $f, $m)) {
            $rgb = array_map(fn ($c) => hexdec($c . $c), str_split($m[1]));
        } elseif (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $f, $m)) {
            $rgb = array_map('hexdec', str_split($m[1], 2));
        } elseif (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $f, $m)) {
            $rgb = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        $lin = array_map(function ($c) {
            $c = $c / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /** Sandboxed Custom-CSS des Designs (Stufe 2 „Leinwand via Code"); built-ins tragen keins. */
    public function resolveCss(?string $designSource, Team $team): ?string
    {
        $design = $this->findBySource($designSource, $team);

        return $design !== null ? $this->sanitizeCss($design->custom_css) : null;
    }

    /**
     * Härtet KI/User-CSS für die eigenständige, chrome-freie Präsentationsseite. CSS-ONLY:
     * verbietet `<` (kein </style>-Breakout → kein HTML/JS), `@import` (externe Ressourcen),
     * `expression(` (IE-Altlast) und `javascript:`. `>` bleibt als Kind-Selektor erlaubt — ohne `<`
     * kann es kein Tag öffnen oder schließen. Schriften kommen über tokens.typography (Whitelist).
     */
    public function sanitizeCss(?string $css): ?string
    {
        $css = trim((string) $css);
        if ($css === '') {
            return null;
        }
        // Kein Tag-Breakout: `<` reicht (ohne `<` kein </style>); `>` bleibt als Kind-Selektor.
        $css = str_replace('<', '', $css);
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
            'pt-page', 'pt-hero', 'pt-hero-media', 'pt-hero-inner', 'pt-hero-logo', 'pt-hero-title', 'pt-hero-sub', 'pt-hero-meta',
            'pt-kicker', 'pt-cover-img', 'pt-logo', 'pt-section', 'pt-section-head', 'pt-section-title', 'pt-section-text',
            'pt-section-img', 'pt-block', 'pt-block-header', 'pt-block-sub', 'pt-line', 'pt-line-label', 'pt-line-price',
            'pt-line-dots', 'pt-codes', 'pt-price-summary', 'pt-price-big', 'pt-price-label', 'pt-legend', 'pt-cta-btn',
            'pt-footer', 'pt-grid',
            'pt-text-block', 'pt-text-block-title', 'pt-text-block-body', 'pt-line--concept', 'pt-line--paket', 'pt-paket-name',
            'pt-line--dish', 'pt-dish-name', 'pt-item-sub', 'pt-subheader',
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
    public const OUTPUT_TYPES = ['foodbook', 'speisekarte', 'speiseplan', 'angebot', 'etikett', 'konfigurator'];   // Spec 70: Etiketten, Spec 94: Konfigurator

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
