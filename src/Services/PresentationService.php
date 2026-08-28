<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 43 — Public-Präsentations-Layer (digitales Kundenbuch) für die Ausgabeformen.
 *
 * Zwei Pfade, EINE normalisierte Shape:
 *  - previewData(): LIVE, team-gescopt (interne Vorschau; Editor sieht Edits sofort)
 *  - buildSnapshot()/publish(): eingefroren, aus dem der Public-Link ohne Login rendert
 *
 * Interna-Freiheit ist NICHT der `intern=false`-Flag (der ist code-verifiziert leck:
 * einzel-`ek`, ungegatetes `title_intern`, `gesamt`-EK-Triple), sondern ein
 * ALLOWLIST-Neubau: normalize*() baut die Kundensicht aus bekannten, sicheren Keys neu.
 *
 * Bilder: im Snapshot nur Identifier (context_file_id/path) — NIE eine kurzlebig
 * signierte URL einfrieren. hydrateImages() signiert zur Render-Zeit frisch.
 */
class PresentationService
{
    public const TYPE_FOODBOOK = 'foodbook';
    public const TYPE_SPEISEKARTE = 'speisekarte';
    public const TYPE_SPEISEPLAN = 'speiseplan';

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private PresentationDesignService $designs,
        private FoodAlchemistMediaService $media,
    ) {
    }

    // ── Publish / Withdraw ─────────────────────────────────────────────────

    /**
     * Friert einen absoluten Snapshot ein und schaltet den Public-Link aktiv.
     * Pflicht-Datum: ohne gültig-bis (`expires_at`) kein Link.
     *
     * @param  array<string,mixed>  $settings  price_display|declaration|cta|design|expires_at|tokens
     * @return array{token:string, url:string, published_at:string, expires_at:string, design:string}
     */
    public function publish(Team $team, string $type, int $id, array $settings): array
    {
        $expiresAt = $this->parseExpiry($settings['expires_at'] ?? null);
        if ($expiresAt === null) {
            throw new \RuntimeException('Ein gültig-bis-Datum ist Pflicht (kein Link ohne Ablauf).');
        }

        $entity = $this->resolveEntity($team, $type, $id, forWrite: true);
        $design = $this->cleanDesignSource($settings['design'] ?? $entity->presentation_design ?? 'editorial');

        $token = $this->ensureToken($entity);
        $userId = auth()->id();

        $snapshot = $this->buildSnapshot($team, $entity, $type, $settings + [
            'design' => $design,
            'freigabe' => ['at' => now()->toIso8601String(), 'by' => $userId, 'datum' => now()->format('d.m.Y')],
        ]);

        $entity->forceFill([
            'presentation_enabled' => true,
            'presentation_token' => $token,
            'presentation_design' => $design,
            'presentation_published_at' => now(),
            'presentation_published_by' => $userId,
            'presentation_expires_at' => $expiresAt,
            'presentation_snapshot_json' => $snapshot,
            'presentation_settings_json' => $this->cleanSettings($settings, $type) + ['design' => $design],
        ])->save();

        return [
            'token' => $token,
            'url' => $this->publicUrl($type, $token),
            'published_at' => $entity->presentation_published_at->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'design' => $design,
        ];
    }

    /** Nimmt den Public-Link vom Netz (Snapshot + Token bleiben — Wieder-Freigabe möglich). */
    public function withdraw(Team $team, string $type, int $id): void
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: true);
        $entity->forceFill(['presentation_enabled' => false])->save();
    }

    // ── Public-Auflösung (KEIN Team-Scope) ─────────────────────────────────

    /**
     * Öffentliche Auflösung per Token — bewusst OHNE visibleToTeam. Rendert NUR aus dem
     * eingefrorenen Snapshot; null → Controller abortiert mit 404.
     *
     * @return array<string,mixed>|null
     */
    public function resolveByToken(string $type, string $token): ?array
    {
        $class = $this->modelClass($type);
        /** @var (Model&\Platform\FoodAlchemist\Models\Concerns\HasPresentation)|null $entity */
        $entity = $class::query()->where('presentation_token', $token)->first();
        if ($entity === null || ! $entity->isPresentationLive()) {
            return null;
        }
        $snap = $entity->presentation_snapshot_json;
        if (! is_array($snap) || $snap === []) {
            return null;
        }

        return $this->hydrateImages($snap);
    }

    // ── Interne Live-Vorschau (team-gescopt, nicht persistiert) ────────────

    /**
     * @return array<string,mixed>
     */
    public function previewData(Team $team, string $type, int $id, ?string $designOverride = null): array
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: false);
        $settings = $entity->presentationSettings();
        if ($designOverride !== null && $designOverride !== '') {
            $settings['design'] = $this->cleanDesignSource($designOverride);
        }
        $settings += [
            'design' => $this->cleanDesignSource($entity->presentation_design ?? 'editorial'),
            'freigabe' => ['at' => now()->toIso8601String(), 'by' => auth()->id(), 'datum' => now()->format('d.m.Y')],
        ];

        return $this->hydrateImages($this->buildSnapshot($team, $entity, $type, $settings));
    }

    // ── Snapshot-Aufbau + Sanitizer ────────────────────────────────────────

    /**
     * Baut die vollständige, interna-freie Snapshot-Struktur (Content + aufgelöstes Design).
     *
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    public function buildSnapshot(Team $team, Model $entity, string $type, array $settings): array
    {
        $clean = $this->mergeSettings($settings, $type);
        if (isset($settings['tokens']) && is_array($settings['tokens'])) {
            $clean['tokens'] = $settings['tokens'];
        }
        // Speiseplan: welche Mahlzeit/Woche der Aushang einfriert (zum Snapshot-Zeitpunkt bindend).
        if ($type === self::TYPE_SPEISEPLAN) {
            $clean['mahlzeit'] = (string) ($settings['mahlzeit'] ?? 'mittag');
            $clean['montag'] = $settings['montag'] ?? null;
        }
        $designSource = $this->cleanDesignSource($settings['design'] ?? ($entity->presentation_design ?? 'editorial'));

        $content = match ($type) {
            self::TYPE_FOODBOOK => $this->normalizeFoodbook($team, $entity, $clean),
            self::TYPE_SPEISEKARTE => $this->normalizeSpeisekarte($team, $entity, $clean),
            self::TYPE_SPEISEPLAN => $this->normalizeSpeiseplan($team, $entity, $clean),
            default => throw new \InvalidArgumentException("Präsentations-Typ '{$type}' ist in dieser Phase noch nicht unterstützt."),
        };

        $branding = $this->brandingIdentifiers($entity);
        $tokens = $this->designs->resolveTokens($designSource, $team, $branding, $clean);
        $speiseplanLayout = ($tokens['speiseplan_layout'] ?? 'grid') === 'liste' ? 'liste' : 'grid';
        $resolvedDesign = [
            'source' => $designSource,
            'layout' => $this->adaptLayoutForType($type, $this->designs->resolveLayout($designSource, $team), $speiseplanLayout),
            'tokens' => $tokens,
            'custom_css' => $this->designs->resolveCss($designSource, $team),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'type' => $type,
            'title' => $content['title'],
            'subtitle' => $content['subtitle'],
            'meta' => $content['meta'],
            'freigabe' => is_array($settings['freigabe'] ?? null) ? $settings['freigabe'] : null,
            'settings' => $clean,
            'branding' => $branding,
            'content' => $content['body'],
            'resolved_design' => $resolvedDesign,
        ];
    }

    /**
     * ALLOWLIST-Neubau der Foodbook-Kundensicht. Baut ausschließlich sichere Keys neu —
     * niemals wird die dokumentDaten-Ausgabe wholesale übernommen.
     *
     * @return array{title:string, subtitle:?string, meta:array, body:array}
     */
    private function normalizeFoodbook(Team $team, FoodAlchemistFoodbook $fb, array $settings): array
    {
        // mitKaskade=false → Kaskaden-Leak (EK/Lieferant) entsteht gar nicht erst.
        $dok = app(FoodbookService::class)->dokumentDaten($team, $fb, false, [], false);

        $showPrice = (bool) $settings['price_display'];
        $showDecl = (bool) $settings['declaration'];

        // Bild-Epic: Kapitel-Band-Bilder je Kapitel-ID. Primär = Kapitel-Bild › Concept-Titelbild;
        // dazu die kleine Concept-Galerie (erstes concept_ref-Konzept). $fb->chapters kommt voll
        // aus dokumentDaten; Galerie separat nachladen.
        $fb->loadMissing(['chapters.blocks.concept.images']);
        $chapImg = [];       // primär (Rückwärtskompat: section.image)
        $chapImages = [];    // Liste (section.images): Primärbild + Galerie, gedeckelt
        foreach ($fb->chapters as $c) {
            $primary = ($c->image_context_file_id || $c->image_path)
                ? ['context_file_id' => $c->image_context_file_id, 'path' => $c->image_path]
                : null;
            $gallery = [];
            foreach ($c->blocks as $b) {
                if ($b->type !== 'concept_ref' || $b->concept === null) {
                    continue;
                }
                if ($primary === null && ($b->concept->image_context_file_id || $b->concept->image_path)) {
                    $primary = ['context_file_id' => $b->concept->image_context_file_id, 'path' => $b->concept->image_path];
                }
                foreach ($b->concept->images ?? [] as $gi) {
                    if ($gi->context_file_id || $gi->path) {
                        $gallery[] = ['context_file_id' => $gi->context_file_id, 'path' => $gi->path];
                    }
                }
                if ($primary !== null || $gallery !== []) {
                    break; // erstes Konzept mit Bildmaterial gewinnt
                }
            }
            $liste = [];
            if ($primary !== null) {
                $liste[] = $primary;
            }
            foreach ($gallery as $g) {
                $liste[] = $g;
            }
            $chapImg[(int) $c->id] = $primary;
            $chapImages[(int) $c->id] = array_slice($liste, 0, 6);   // Band zeigt max. 6
        }

        $sections = [];
        foreach ($dok['kapitel'] as $k) {
            $blocks = [];
            foreach ($k['bloecke'] as $b) {
                $perItem = (bool) ($b['einzelpreise'] ?? false);
                $items = [];
                foreach ($b['gerichte'] as $g) {
                    $items[] = [
                        'kind' => (string) ($g['type'] ?? 'gericht'),
                        'label' => (string) ($g['text'] ?? ''),
                        'indent' => (int) ($g['einrueckung'] ?? 0),
                        // NUR VK (Kunde) und nur wenn Preisanzeige an; EK/source/slot_id/recipe_id werden NICHT übernommen.
                        'price' => ($showPrice && ($g['preis'] ?? null) !== null) ? (float) $g['preis'] : null,
                        'codes' => $showDecl ? array_values((array) ($g['codes'] ?? [])) : [],
                    ];
                }
                $blocks[] = [
                    'kind' => (string) ($b['type'] ?? ''),
                    'label' => (string) ($b['label'] ?? ''),
                    'subtitle' => $b['untertitel'] ?? null,
                    'is_header' => (bool) ($b['ist_header'] ?? false),
                    'per_item_price' => $perItem,
                    // Konzept-Summenpreis nur zeigen, wenn Preisanzeige an UND nicht einzel.
                    'price' => ($showPrice && ! $perItem) ? [
                        'pp' => (float) ($b['preis_pp'] ?? 0),
                        'pauschal' => (float) ($b['pauschal'] ?? 0),
                        'unit' => $b['preis_einheit'] ?? null,
                    ] : null,
                    'codes' => $showDecl ? array_values((array) ($b['codes'] ?? [])) : [],
                    'items' => $items,
                ];
            }
            $ankerId = (int) str_replace('k', '', (string) ($k['anker'] ?? ''));
            $sections[] = [
                // consumer_title bereits in dokumentDaten aufgelöst (title = consumer_title ?: title);
                // title_intern wird bewusst NICHT übernommen.
                'title' => (string) ($k['title'] ?? ''),
                'text' => $k['text'] ?? null,
                'depth' => (int) ($k['depth'] ?? 0),
                'anker' => (string) ($k['anker'] ?? ''),
                'image' => $chapImg[$ankerId] ?? null,
                'images' => $chapImages[$ankerId] ?? [],
                'blocks' => $blocks,
            ];
        }

        $g = $dok['gesamt'] ?? [];
        $total = $showPrice ? [
            'vk_pro_person' => isset($g['vk_pro_person']) ? (float) $g['vk_pro_person'] : null,
            'pauschal' => isset($g['pauschal']) ? (float) $g['pauschal'] : null,
            'personen' => $g['personen'] ?? null,
            'gesamt_vk' => isset($g['gesamt_vk']) ? (float) $g['gesamt_vk'] : null,
        ] : null;

        return [
            'title' => (string) ($fb->label ?: $fb->code ?: 'Foodbook'),
            'subtitle' => $fb->description ? (string) $fb->description : null,
            'meta' => [
                'customer' => $dok['customer'] ?? null,
                'kontakt' => $dok['kontakt'] ?? null,
                'jahr' => $fb->jahr ?? null,
                'mwst' => $dok['mwst'] ?? null,
                'stand' => optional($dok['stand'] ?? null)->toIso8601String(),
            ],
            'body' => [
                'layout_kind' => 'linear',
                'sections' => $sections,
                'legend' => $showDecl ? ($dok['legende'] ?? null) : null,
                'total' => $total,
            ],
        ];
    }

    /**
     * Live-Vorschau für den Struktur-Builder: rendert den (noch ungespeicherten) Layout-/
     * Token-Stand gegen ein echtes Foodbook. Content wird regulär sanitisiert, das Design
     * kommt aus dem Builder (nicht aus einer gespeicherten Quelle).
     *
     * @param  list<array{block_type:string, style:array}>  $layout
     * @param  array<string,mixed>  $tokens
     * @return array<string,mixed>
     */
    public function designPreview(Team $team, string $type, int $id, array $layout, array $tokens, ?string $customCss = null): array
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: false);
        // Content + Branding regulär bauen (typ-korrekte Normalisierung), dann das aufgelöste
        // Design durch die LIVE-Builder-Werte ersetzen (Layout/Tokens/CSS aus der Bearbeitung).
        $snapshot = $this->buildSnapshot($team, $entity, $type, ['design' => 'editorial']);
        $spMode = ($tokens['speiseplan_layout'] ?? 'grid') === 'liste' ? 'liste' : 'grid';
        $snapshot['resolved_design'] = [
            'source' => '(builder)',
            'layout' => $this->adaptLayoutForType($type, $this->designs->normalizeLayout($layout), $spMode),
            'tokens' => $tokens,
            'custom_css' => $this->designs->sanitizeCss($customCss),
        ];

        return $this->hydrateImages($snapshot);
    }

    /**
     * ALLOWLIST-Neubau der Speisekarte-Kundensicht (à la carte). Rubrik → Section, Positionen
     * → Zeilen (Menü-Gänge eingerückt). preis_quelle/karte/kaskaden/intern werden NICHT übernommen.
     *
     * @return array{title:string, subtitle:?string, meta:array, body:array}
     */
    private function normalizeSpeisekarte(Team $team, FoodAlchemistSpeisekarte $karte, array $settings): array
    {
        $dok = app(SpeisekarteService::class)->dokumentDaten($team, $karte, false, [], false);

        $showPrice = (bool) $settings['price_display'];
        $showDecl = (bool) $settings['declaration'];
        $brutto = (bool) ($dok['brutto'] ?? true);

        $sections = [];
        foreach ($dok['rubriken'] as $r) {
            $items = [];
            foreach ($r['positionen'] as $p) {
                $typ = (string) ($p['typ'] ?? '');
                if ($typ === 'spacer') {
                    continue;
                }
                $val = $brutto ? ($p['vk_brutto'] ?? null) : ($p['vk_netto'] ?? null);
                $items[] = [
                    'kind' => $typ,
                    'label' => (string) ($p['name'] ?? ''),
                    'subtitle' => $p['consumer_text'] ?? null,
                    'indent' => 0,
                    'price' => ($showPrice && $val !== null) ? (float) $val : null,
                    'codes' => $showDecl ? array_values((array) ($p['codes'] ?? [])) : [],
                ];
                // Menü-Gänge als eingerückte, preislose Unterzeilen.
                foreach ($p['gaenge'] ?? [] as $g) {
                    $items[] = [
                        'kind' => 'gang',
                        'label' => (string) ($g['text'] ?? $g['name'] ?? ''),
                        'subtitle' => null,
                        'indent' => (int) ($g['einrueckung'] ?? 1) ?: 1,
                        'price' => null,
                        'codes' => $showDecl ? array_values((array) ($g['codes'] ?? [])) : [],
                    ];
                }
            }
            $sections[] = [
                'title' => (string) ($r['title'] ?? ''),
                'text' => $r['claim'] ?? null,
                'depth' => (int) ($r['depth'] ?? 0),
                'anker' => 'r' . ($r['id'] ?? ''),
                'blocks' => [[
                    'kind' => 'menu_rubrik', 'label' => '', 'subtitle' => null, 'is_header' => false,
                    'per_item_price' => false, 'price' => null, 'codes' => [], 'items' => $items,
                ]],
            ];
        }

        return [
            'title' => (string) ($karte->name ?: $karte->code ?: 'Speisekarte'),
            'subtitle' => $karte->description ? (string) $karte->description : null,
            'meta' => [
                'customer' => null, 'kontakt' => null, 'jahr' => null,
                'mwst' => ['regulaer' => $dok['mwstSatz'] ?? null],
                'stand' => $dok['erzeugt'] ?? null,
            ],
            'body' => [
                'layout_kind' => 'linear',
                'sections' => $sections,
                'legend' => $showDecl ? ($dok['legende'] ?? null) : null,
                'total' => null,   // à la carte: kein Pro-Person-Total
            ],
        ];
    }

    /**
     * ALLOWLIST-Neubau der Speiseplan-Kundensicht (GV-Aushang, Wochen-Raster). LMIV-Kennzeichnung
     * + Kostformen + DGE-Ø-Nährwerte sind customer-pflichtig; preislos. karte/kaskaden/intern raus.
     *
     * @return array{title:string, subtitle:?string, meta:array, body:array}
     */
    private function normalizeSpeiseplan(Team $team, FoodAlchemistSpeiseplan $plan, array $settings): array
    {
        $mahlzeit = (string) ($settings['mahlzeit'] ?? 'mittag');
        $montag = $settings['montag'] ?? null;
        $dok = app(SpeiseplanService::class)->dokumentDaten($team, $plan, $mahlzeit, $montag, false, false);

        $tage = array_map(fn ($t) => ['key' => $t['ymd'] ?? '', 'label' => $t['label'] ?? ''], $dok['tage'] ?? []);
        $lines = [];
        foreach ($dok['zeilen'] ?? [] as $z) {
            $cells = [];
            foreach ($z['zellen'] ?? [] as $ymd => $eintraege) {
                $cells[$ymd] = array_map(fn ($e) => [
                    'label' => (string) ($e['name'] ?? ''),
                    'codes' => array_values((array) ($e['codes'] ?? [])),
                ], $eintraege);
            }
            $lines[] = ['name' => (string) ($z['linie'] ?? ''), 'color' => $z['color'] ?? null, 'cells' => $cells];
        }

        // Listen-Variante (Speiseplan-Ausgabe „Liste" statt Tabelle): pro Tag eine Sektion,
        // je Linie ein Block mit den Einträgen des Tages. Rendert über chapter_loop.
        $sections = [];
        foreach ($dok['tage'] ?? [] as $t) {
            $ymd = $t['ymd'] ?? '';
            $blocks = [];
            foreach ($dok['zeilen'] ?? [] as $z) {
                $eintraege = $z['zellen'][$ymd] ?? [];
                if ($eintraege === []) {
                    continue;
                }
                $blocks[] = [
                    'kind' => 'speiseplan_line',
                    'label' => (string) ($z['linie'] ?? ''),
                    'is_header' => true,
                    'codes' => [],
                    'items' => array_map(fn ($e) => [
                        'label' => (string) ($e['name'] ?? ''),
                        'codes' => array_values((array) ($e['codes'] ?? [])),
                        'indent' => 0,
                    ], $eintraege),
                ];
            }
            if ($blocks === []) {
                continue;
            }
            $sections[] = [
                'title' => (string) ($t['label'] ?? ''),
                'text' => null, 'depth' => 0, 'anker' => 'tag-' . $ymd,
                'image' => null, 'images' => [], 'blocks' => $blocks,
            ];
        }

        return [
            'title' => (string) ($plan->name ?: 'Speiseplan'),
            'subtitle' => trim((string) (($dok['kwLabel'] ?? '') . ' · ' . ($dok['mahlzeitLabel'] ?? ''))) ?: null,
            'meta' => [
                'customer' => null, 'kontakt' => null, 'jahr' => null, 'mwst' => null,
                'stand' => $dok['erzeugt'] ?? null,
            ],
            'body' => [
                'layout_kind' => 'grid',
                'sections' => $sections,
                'grid' => ['tage' => $tage, 'lines' => $lines],
                'kostformen' => $dok['kostformen'] ?? null,
                'naehrwerte' => $dok['naehrwerte'] ?? null,
                // LMIV ist Pflicht (mergeSettings erzwingt declaration=true bei Speiseplan).
                'legend' => $dok['legende'] ?? null,
                'total' => null,
            ],
        ];
    }

    /**
     * Passt eine (built-in oder eigene) Layout-Definition an den Ausgabetyp an. Für den
     * Speiseplan werden die linearen Content-Blöcke (chapter_loop/dish_list/price_summary)
     * durch einen `grid`-Block ersetzt → „Grid in die 3 Vorlagen" (Cover/Legende/Tokens bleiben).
     *
     * @param  list<array{block_type:string, style:array}>  $layout
     * @return list<array{block_type:string, style:array}>
     */
    private function adaptLayoutForType(string $type, array $layout, string $mode = 'grid'): array
    {
        if ($type !== self::TYPE_SPEISEPLAN) {
            return $layout;
        }
        // Speiseplan-Ausgabe: „grid" = Wochenraster (Default), „liste" = Tag-für-Tag-Sektionen
        // (rendert über chapter_loop). Genau EIN Content-Block; alle linearen/Grid-Blöcke
        // werden auf den gewählten Ausgabe-Block reduziert.
        $contentBlock = $mode === 'liste' ? 'chapter_loop' : 'grid';
        $out = [];
        $gesetzt = false;
        foreach ($layout as $block) {
            $bt = $block['block_type'] ?? '';
            if (in_array($bt, ['chapter_loop', 'dish_list', 'price_summary', 'grid'], true)) {
                if (! $gesetzt) {
                    $out[] = ['block_type' => $contentBlock, 'style' => $block['style'] ?? []];
                    $gesetzt = true;
                }

                continue;
            }
            $out[] = $block;
        }
        if (! $gesetzt) {
            // Kein Content-Block im Design → Ausgabe-Block vor die Legende (oder ans Ende).
            $legendIdx = null;
            foreach ($out as $i => $b) {
                if (($b['block_type'] ?? '') === 'legend') {
                    $legendIdx = $i;
                    break;
                }
            }
            $inject = ['block_type' => $contentBlock, 'style' => []];
            if ($legendIdx !== null) {
                array_splice($out, $legendIdx, 0, [$inject]);
            } else {
                $out[] = $inject;
            }
        }

        return $out;
    }

    // ── Bilder: Identifier → frisch signierte URL ──────────────────────────

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function hydrateImages(array $snapshot): array
    {
        foreach (['logo', 'cover'] as $key) {
            $img = $snapshot['branding'][$key] ?? null;
            if (is_array($img) && (($img['context_file_id'] ?? null) || ($img['path'] ?? null))) {
                $snapshot['branding'][$key]['url'] = $this->media->url(
                    $img['context_file_id'] ?? null,
                    $img['path'] ?? null
                );
            } elseif (is_array($img)) {
                $snapshot['branding'][$key]['url'] = null;
            }
        }

        // Bild-Epic: Kapitel-Band-Bilder (Identifier → frisch signierte URL) — Einzelbild + Liste.
        foreach ($snapshot['content']['sections'] ?? [] as $i => $sec) {
            $img = $sec['image'] ?? null;
            if (is_array($img) && (($img['context_file_id'] ?? null) || ($img['path'] ?? null))) {
                $snapshot['content']['sections'][$i]['image']['url'] = $this->media->url($img['context_file_id'] ?? null, $img['path'] ?? null);
            }
            foreach ($sec['images'] ?? [] as $j => $gi) {
                if (is_array($gi) && (($gi['context_file_id'] ?? null) || ($gi['path'] ?? null))) {
                    $snapshot['content']['sections'][$i]['images'][$j]['url'] = $this->media->url($gi['context_file_id'] ?? null, $gi['path'] ?? null);
                }
            }
        }

        // Freier Bild-Block (Struktur-Builder): Identifier in der Layout-Definition → frische URL.
        foreach ($snapshot['resolved_design']['layout'] ?? [] as $i => $block) {
            if (($block['block_type'] ?? '') !== 'image') {
                continue;
            }
            $st = $block['style'] ?? [];
            if (($st['context_file_id'] ?? null) || ($st['path'] ?? null)) {
                $snapshot['resolved_design']['layout'][$i]['style']['url'] = $this->media->url($st['context_file_id'] ?? null, $st['path'] ?? null);
            }
        }

        return $snapshot;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    /**
     * @return Model&\Platform\FoodAlchemist\Models\Concerns\HasPresentation
     */
    private function resolveEntity(Team $team, string $type, int $id, bool $forWrite): Model
    {
        $class = $this->modelClass($type);
        /** @var Model&\Platform\FoodAlchemist\Models\Concerns\HasPresentation $entity */
        $entity = $class::visibleToTeam($team)->findOrFail($id);
        if ($forWrite && ! $entity->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Ausgabe gehört einem anderen Team und kann nicht veröffentlicht werden.');
        }

        return $entity;
    }

    /** @return class-string<Model> */
    private function modelClass(string $type): string
    {
        return match ($type) {
            self::TYPE_FOODBOOK => FoodAlchemistFoodbook::class,
            self::TYPE_SPEISEKARTE => FoodAlchemistSpeisekarte::class,
            self::TYPE_SPEISEPLAN => FoodAlchemistSpeiseplan::class,
            default => throw new \InvalidArgumentException("Unbekannter Präsentations-Typ '{$type}'."),
        };
    }

    /** Token einmal erzeugen, danach stabil (spiegelt CorePublicFormLink::booted). */
    private function ensureToken(Model $entity): string
    {
        if (! empty($entity->presentation_token)) {
            return (string) $entity->presentation_token;
        }
        $class = $entity::class;
        do {
            $token = bin2hex(random_bytes(16));
        } while ($class::query()->where('presentation_token', $token)->exists());

        return $token;
    }

    /**
     * @return array{color:string, band:?string, footer:?string, logo:array{context_file_id:?int,path:?string}, cover:array{context_file_id:?int,path:?string}}
     */
    private function brandingIdentifiers(Model $e): array
    {
        return [
            // Roh (null wenn nicht gesetzt): resolveTokens übersteuert die Design-Palette NUR bei
            // echt gesetztem Branding — sonst gewänne der Default immer über das eigene Design.
            'color' => $e->brand_color ?: null,
            'band' => $e->band_color ?: null,
            'footer' => $e->footer_text ?: null,
            'logo' => ['context_file_id' => $e->logo_context_file_id ?? null, 'path' => $e->logo_path ?? null],
            'cover' => ['context_file_id' => $e->cover_context_file_id ?? null, 'path' => $e->cover_image_path ?? null],
        ];
    }

    private function publicUrl(string $type, string $token): string
    {
        return url('/p/' . $type . '/' . $token);
    }

    private function cleanDesignSource(?string $source): string
    {
        $source = trim((string) $source);
        if ($source === '') {
            return 'editorial';
        }
        if (str_starts_with($source, 'design:')) {
            return $source;
        }

        return in_array($source, PresentationDesignService::BUILTIN_SLUGS, true) ? $source : 'editorial';
    }

    /**
     * Kundensicht-Settings mit Defaults je Typ. Speiseplan: Preis default AUS, Deklaration Pflicht AN.
     *
     * @param  array<string,mixed>  $settings
     * @return array{price_display:bool, declaration:bool, cta:array{text:?string,link:?string}}
     */
    private function mergeSettings(array $settings, string $type): array
    {
        $priceDefault = $type !== self::TYPE_SPEISEPLAN;      // GV-Aushang preislos
        $declDefault = true;

        $decl = array_key_exists('declaration', $settings) ? (bool) $settings['declaration'] : $declDefault;
        if ($type === self::TYPE_SPEISEPLAN) {
            $decl = true; // LMIV-Pflicht — nicht abschaltbar
        }

        return [
            'price_display' => array_key_exists('price_display', $settings) ? (bool) $settings['price_display'] : $priceDefault,
            'declaration' => $decl,
            'cta' => [
                'text' => isset($settings['cta']['text']) ? (string) $settings['cta']['text'] : null,
                'link' => isset($settings['cta']['link']) ? (string) $settings['cta']['link'] : null,
            ],
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function cleanSettings(array $settings, string $type): array
    {
        $out = $this->mergeSettings($settings, $type);
        if (isset($settings['tokens']) && is_array($settings['tokens'])) {
            $out['tokens'] = $settings['tokens'];
        }
        if ($type === self::TYPE_SPEISEPLAN) {
            $out['mahlzeit'] = (string) ($settings['mahlzeit'] ?? 'mittag');
            $out['montag'] = $settings['montag'] ?? null;
        }

        return $out;
    }

    private function parseExpiry(mixed $value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
