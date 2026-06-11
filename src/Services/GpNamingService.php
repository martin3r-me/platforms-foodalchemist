<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M3-09: GL-12 — GP-Naming, Slugs & Anlage-Guard.
 *
 * - slugify(): KANONISCH, byte-identisch zur Rust-Variante (commands.rs:2471) —
 *   ä→a/ö→o/ü→u/ß→s (EIN Zeichen!), NICHT Str::slug() (I6: sonst kollidieren
 *   neue gp_keys mit den 7.774 Seed-GPs). Matcher-Slug (ae/oe/ue/ss) bleibt
 *   getrennt in TokenEngine::normalizeSlug (A3 — nie vereinheitlichen während Migration).
 * - renderGpName()/validateGpName(): gp_naming.rs Z. 31/88; §7.1 Verpackungswörter
 *   = Hard-Error (Wort-Boundary: „Dose" blockt, „Dosentomate" nicht), §9-Zustand
 *   mit Eingangs-Normalisierung tiefgekuehlt→TK (A2/A7: EIN Vokabular).
 * - anlageGuard(): gp_key-UNIQUE über approved/tentative + Jaccard ≥ 0.92 gegen
 *   bestehende Namen (create_gp Z. 2509); nur force=true legt trotzdem an (GT-12-10).
 */
class GpNamingService
{
    /** §9 / DB-CHECK — kanonisches Zustand-Vokabular (A7: EIN Validator-Set). */
    public const ZUSTAND_VOCAB = ['frisch', 'TK', 'trocken', 'konserviert'];

    /** §7.1 / I2 — Verpackungswörter (Wort-Boundary-Match). */
    public const VERPACKUNGSWOERTER = [
        'Kiste', 'Karton', 'Beutel', 'Pkt', 'Btl', 'Geb', 'Tasse', 'Dose', 'Glas',
        'Stange', 'Atmospack', 'Vac', 'Bund', 'Gebinde',
    ];

    public function __construct(private TokenEngine $engine)
    {
    }

    /** KANONISCH (gp_key/hauptzutat_slug) — byte-identisch zu commands.rs:2471 (I6). */
    public function slugify(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 's']);   // EIN Zeichen — nicht ae/oe/ue/ss!
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);                     // Unicode-Buchstaben (é, è) bleiben
        $s = preg_replace('/_+/', '_', $s);

        return trim($s, '_');
    }

    /** commands.rs:2496 — IMMER 3 Slots, auch leere (Tomatenpulver-Dubletten-Bug 2026-05-26). */
    public function buildGpKey(string $hauptzutatSlug, ?string $verarbeitung, ?string $form): string
    {
        return $hauptzutatSlug . '|' . $this->slugify($verarbeitung ?? '') . '|' . $this->slugify($form ?? '');
    }

    /** A2/A7: Eingangs-Normalisierung — Langform → kanonisch TK; sonst unverändert. */
    public function normalisiereZustand(?string $zustand): ?string
    {
        if ($zustand === null || trim($zustand) === '') {
            return null;
        }
        $z = trim($zustand);

        return mb_strtolower($z) === 'tiefgekuehlt' ? 'TK' : $z;
    }

    /**
     * gp_naming.rs Z. 31 — §6-Schema (Ist-Slots 1:1, A1):
     * `<Hauptzutat>: <zustand>, <verarbeitung|form>[, Portion <x> pro Stueck][, <pflicht>][ / (Bio)]…`
     */
    public function renderGpName(array $in): string
    {
        $parts = [];
        $zustand = $this->normalisiereZustand($in['zustand'] ?? null);
        if ($zustand !== null) {
            $parts[] = $zustand;
        }
        $mid = ($in['verarbeitung'] ?? null) ?: ($in['form'] ?? null);       // verarbeitung gewinnt (spezifischer)
        if ($mid) {
            $parts[] = $mid;
        }
        if (! empty($in['portion'])) {
            $parts[] = 'Portion ' . $in['portion'] . ' pro Stueck';
        }
        if (! empty($in['pflichtangabe'])) {
            $parts[] = $in['pflichtangabe'];
        }

        $hauptzutat = trim($in['hauptzutat'] ?? '');
        $base = $parts === [] ? $hauptzutat : $hauptzutat . ': ' . implode(', ', $parts);

        $suffixes = [];
        foreach (['bio' => '(Bio)', 'vegan' => '(Vegan)', 'glutenfrei' => '(Glutenfrei)', 'laktosefrei' => '(Laktosefrei)'] as $flag => $suffix) {
            if (! empty($in[$flag])) {
                $suffixes[] = $suffix;                                       // Reihenfolge fix (A1)
            }
        }

        return $suffixes === [] ? $base : $base . ' / ' . implode(' / ', $suffixes);
    }

    /**
     * gp_naming.rs Z. 88 — Hard-Errors blocken Insert, Warnings sind informativ (I4).
     *
     * @return array{errors: string[], warnings: string[]}
     */
    public function validateGpName(string $name, array $in): array
    {
        $errors = [];
        $warnings = [];

        if (trim($name) === '') {
            $errors[] = 'Name ist leer.';
        }
        foreach (self::VERPACKUNGSWOERTER as $wort) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($wort, '/') . '(?![\p{L}\p{N}])/iu', $name)) {
                $errors[] = "§7.1: Verpackungswort »{$wort}« gehört nie in den GP-Namen.";
            }
        }
        $zustand = $this->normalisiereZustand($in['zustand'] ?? null);
        if ($zustand !== null && ! in_array($zustand, self::ZUSTAND_VOCAB, true)) {
            $errors[] = "§9: Zustand »{$zustand}« ist nicht im Pflicht-Vokabular (" . implode('/', self::ZUSTAND_VOCAB) . ').';
        }
        if ($this->normalisiere($name) !== $this->normalisiere($this->renderGpName($in))) {
            $warnings[] = 'Drift: Name weicht vom Render aus den strukturierten Feldern ab (I4).';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Anlage-Guard (create_gp Z. 2509, GT-12-10): gp_key-UNIQUE über aktive GPs
     * (approved/tentative) + Jaccard ≥ 0.92 gegen bestehende Namen. Hard-Stop —
     * nur force=true legt trotzdem an.
     *
     * @return array{blockiert: bool, grund: ?string, vorhandenes_gp: ?FoodAlchemistGp}
     */
    public function anlageGuard(Team $team, string $gpKey, string $name, ?int $ignoriereGpId = null): array
    {
        $kollision = FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('status', ['approved', 'tentative'])
            ->where('gp_key', $gpKey)
            ->when($ignoriereGpId !== null, fn ($q) => $q->where('id', '!=', $ignoriereGpId))
            ->first();
        if ($kollision !== null) {
            return ['blockiert' => true, 'grund' => 'gp_key', 'vorhandenes_gp' => $kollision];
        }

        $query = $this->engine->tokenize($name);
        if ($query !== []) {
            $beste = null;
            FoodAlchemistGp::visibleToTeam($team)
                ->whereIn('status', ['approved', 'tentative'])
                ->when($ignoriereGpId !== null, fn ($q) => $q->where('id', '!=', $ignoriereGpId))
                ->select('id', 'name', 'gp_key', 'status', 'team_id')
                ->orderBy('id')
                ->chunk(2000, function ($gps) use (&$beste, $query) {
                    foreach ($gps as $gp) {
                        $c = $this->engine->tokenize($gp->name);
                        if ($c === []) {
                            continue;
                        }
                        $schnitt = count(array_intersect($query, $c));
                        $jaccard = $schnitt / count(array_unique([...$query, ...$c]));
                        if ($jaccard >= 0.92 && ($beste === null || $jaccard > $beste['j'])) {
                            $beste = ['gp' => $gp, 'j' => $jaccard];
                        }
                    }
                });
            if ($beste !== null) {
                return ['blockiert' => true, 'grund' => 'jaccard', 'vorhandenes_gp' => $beste['gp']];
            }
        }

        return ['blockiert' => false, 'grund' => null, 'vorhandenes_gp' => null];
    }

    /**
     * GP-Anlage (Render-First, I4): rendert + validiert + Guard, dann Insert.
     * Status neuer GPs = tentative (Kuration hebt auf approved).
     *
     * @throws \RuntimeException bei Hard-Error oder Guard-Block ohne force
     */
    public function createGp(Team $team, array $in, bool $force = false): FoodAlchemistGp
    {
        $name = trim($in['name'] ?? '') !== '' ? trim($in['name']) : $this->renderGpName($in);
        $pruefung = $this->validateGpName($name, $in);
        if ($pruefung['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $pruefung['errors']));
        }

        $hauptzutatSlug = $this->slugify($in['hauptzutat'] ?? '');
        if ($hauptzutatSlug === '') {
            throw new \RuntimeException('Hauptzutat ist Pflicht (D-3: hauptzutat_slug ab Editor).');
        }
        $gpKey = $this->buildGpKey($hauptzutatSlug, $in['verarbeitung'] ?? null, $in['form'] ?? null);

        $guard = $this->anlageGuard($team, $gpKey, $name);
        if ($guard['blockiert']) {
            if (! $force) {
                throw new \RuntimeException(
                    "HARD_STOP_EXISTING_GP: »{$guard['vorhandenes_gp']->name}« existiert bereits"
                    . " ({$guard['grund']}) — verwenden oder bewusst trotzdem anlegen (force)."
                );
            }
            // force: DB-UNIQUE (team_id, gp_key) ist absolut — bewusste Duplikate bekommen
            // einen ~n-Suffix (klar als Force-Anlage erkennbar, Dubletten-Guard bleibt scharf)
            $n = 2;
            while (FoodAlchemistGp::where('team_id', $team->id)->where('gp_key', "{$gpKey}~{$n}")->exists()) {
                $n++;
            }
            $gpKey = "{$gpKey}~{$n}";
        }

        return FoodAlchemistGp::create([
            'team_id' => $team->id,                                          // D1: Kind-eigene GPs möglich
            'gp_key' => $gpKey,
            'name' => $name,
            'hauptzutat_slug' => $hauptzutatSlug,
            'status' => 'tentative',
            'zustand' => $this->normalisiereZustand($in['zustand'] ?? null),
            'warengruppe_code' => ($in['warengruppe_code'] ?? '') ?: null,
            'sub_kategorie' => ($in['sub_kategorie'] ?? '') ?: null,
            'is_derivat' => (bool) ($in['is_derivat'] ?? false),
            'derivat_von_gp_id' => $in['derivat_von_gp_id'] ?? null,
            'requires_la' => ! (bool) ($in['is_derivat'] ?? false),          // §11.2: Derivat braucht keinen LA
            'first_seen_at' => now(),
        ]);
    }

    /** GP-Edit über die strukturierten Felder — gleiche Validierung, gp_key wird NICHT umgeschrieben (Seed-Stabilität). */
    public function updateGp(Team $team, FoodAlchemistGp $gp, array $in): FoodAlchemistGp
    {
        $name = trim($in['name'] ?? '') !== '' ? trim($in['name']) : $this->renderGpName($in);
        $pruefung = $this->validateGpName($name, $in);
        if ($pruefung['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $pruefung['errors']));
        }

        $gp->update([
            'name' => $name,
            'zustand' => $this->normalisiereZustand($in['zustand'] ?? null) ?? $gp->zustand,
            'warengruppe_code' => ($in['warengruppe_code'] ?? '') ?: $gp->warengruppe_code,
            'sub_kategorie' => array_key_exists('sub_kategorie', $in) ? (($in['sub_kategorie'] ?? '') ?: null) : $gp->sub_kategorie,
            'is_derivat' => (bool) ($in['is_derivat'] ?? $gp->is_derivat),
            'derivat_von_gp_id' => array_key_exists('derivat_von_gp_id', $in) ? $in['derivat_von_gp_id'] : $gp->derivat_von_gp_id,
        ]);

        return $gp->refresh();
    }

    private function normalisiere(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
