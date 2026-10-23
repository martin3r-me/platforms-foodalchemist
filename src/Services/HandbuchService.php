<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistHandbuch;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 86 — Handbuch (Standards zum Nachschlagen). Friert zu einem Ausgabe-Dokument einen
 * kundentauglichen Snapshot ein: je Gericht Zubereitung, Anrichten, Regeneration, Bestandteile,
 * Portion/Geschirr, Allergene. ALLOWLIST-Aufbau — Preise, EK, Lieferant, KI-Lineage und
 * interne Notizen gelangen nie in den Snapshot. Eigener Token/Ablauf/Widerruf, unabhängig vom
 * Kundenlink der Präsentation (Spec 43).
 *
 * Aufbau des Snapshots: `sections` spiegeln die Struktur des Quelldokuments (Kapitel/Rubriken/Tage),
 * jedes Item verweist per `key` auf eine Karte in `gerichte` (Gericht einmal, auch wenn es mehrfach vorkommt).
 */
class HandbuchService
{
    public const TYPE_FOODBOOK = 'foodbook';

    public const TYPE_SPEISEKARTE = 'speisekarte';

    public const TYPE_SPEISEPLAN = 'speiseplan';

    public const TYPE_ANGEBOT = 'angebot';

    public const SCHEMA_VERSION = 1;

    /** Quelltypen des Handbuchs (gleiche vier Ausgabe-Dokumente wie die Präsentation, Spec 43). */
    public const TYPEN = [self::TYPE_FOODBOOK, self::TYPE_SPEISEKARTE, self::TYPE_SPEISEPLAN, self::TYPE_ANGEBOT];

    public function __construct(
        private FoodAlchemistMediaService $media,
        private RegenerationCascadeService $regeneration,
        private HandbuchQuellenService $quellen,
        private HandbuchZutatenService $zutaten,
    ) {
    }

    // ── Publish / Withdraw ─────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $settings  expires_at (Pflicht)
     * @return array{token:string, url:string, published_at:string, expires_at:string, luecken:list<array{gericht:string, fehlt:list<string>}>}
     */
    public function publish(Team $team, string $type, int $id, array $settings): array
    {
        $expiresAt = $this->parseExpiry($settings['expires_at'] ?? null);
        if ($expiresAt === null) {
            throw new \RuntimeException('Ein gültig-bis-Datum ist Pflicht (kein Link ohne Ablauf).');
        }
        $entity = $this->resolveEntity($team, $type, $id, forWrite: true);
        $hb = FoodAlchemistHandbuch::firstOrNew(['quelle_type' => $type, 'quelle_id' => $entity->getKey()]);
        $hb->team_id = $team->id;
        // Freie Kapitel (Hygiene, Ausgabe-Regeln …): ohne Angabe bleiben die zuletzt veröffentlichten stehen.
        $kapitel = array_key_exists('kapitel', $settings)
            ? $this->bereinigeKapitel((array) $settings['kapitel'])
            : (array) ($hb->settings_json['kapitel'] ?? []);
        $snapshot = $this->buildSnapshot($team, $entity, $type, $kapitel);
        $token = $hb->token ?: $this->neuerToken();

        $hb->forceFill([
            'enabled' => true,
            'token' => $token,
            'snapshot_json' => $snapshot,
            'settings_json' => ['expires_at' => $expiresAt->toIso8601String(), 'kapitel' => $kapitel],
            'published_at' => now(),
            'published_by' => auth()->id(),
            'expires_at' => $expiresAt,
        ])->save();

        return [
            'token' => $token,
            'url' => $this->publicUrl($token),
            'published_at' => $hb->published_at->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'luecken' => $snapshot['luecken'],
        ];
    }

    /** Nimmt das Handbuch vom Netz (Snapshot + Token bleiben — Wieder-Freigabe möglich). */
    public function withdraw(Team $team, string $type, int $id): void
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: true);
        FoodAlchemistHandbuch::where('quelle_type', $type)->where('quelle_id', $entity->getKey())
            ->update(['enabled' => false]);
    }

    /**
     * Link-Status für den Editor. Bewusst OHNE Snapshot-Aufbau: läuft bei jedem Render des Editors,
     * die Komposition (Preise/Zeilen) darf dafür nicht ein zweites Mal gerechnet werden.
     *
     * @return array{veroeffentlicht:bool, live:bool, url:?string, expires_at:?string, published_at:?string}
     */
    public function status(Team $team, string $type, int $id): array
    {
        $entity = $this->resolveEntity($team, $type, $id, forWrite: false);
        $hb = FoodAlchemistHandbuch::where('quelle_type', $type)->where('quelle_id', $entity->getKey())->first();

        return [
            'veroeffentlicht' => $hb !== null,
            'live' => $hb?->istLive() ?? false,
            'url' => $hb !== null ? $this->publicUrl((string) $hb->token) : null,
            'expires_at' => $hb?->expires_at?->toIso8601String(),
            'published_at' => $hb?->published_at?->toIso8601String(),
            'kapitel' => (array) ($hb?->settings_json['kapitel'] ?? []),
        ];
    }

    /**
     * Freie Kapitel auf Titel + Klartext reduzieren (kein HTML, begrenzt) — das Handbuch rendert sie escaped.
     *
     * @param  list<array<string,mixed>>  $roh
     * @return list<array{title:string, text:string}>
     */
    public function bereinigeKapitel(array $roh): array
    {
        $out = [];
        foreach (array_slice($roh, 0, 20) as $k) {
            $titel = trim(mb_substr(strip_tags((string) ($k['title'] ?? '')), 0, 120));
            $text = trim(mb_substr(strip_tags((string) ($k['text'] ?? '')), 0, 4000));
            if ($titel !== '' || $text !== '') {
                $out[] = ['title' => $titel !== '' ? $titel : 'Allgemein', 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * Lücken des AKTUELLEN Standes (live gerechnet, nicht persistiert) — nur auf Knopfdruck, weil der
     * Aufbau die ganze Komposition rechnet.
     *
     * @return list<array{gericht:string, fehlt:list<string>}>
     */
    public function luecken(Team $team, string $type, int $id): array
    {
        return $this->buildSnapshot($team, $this->resolveEntity($team, $type, $id, forWrite: false), $type)['luecken'];
    }

    // ── Public-Auflösung (KEIN Team-Scope) ─────────────────────────────────

    /**
     * Öffentliche Auflösung per Token. Rendert NUR aus dem eingefrorenen Snapshot; null → 404.
     *
     * @return array<string,mixed>|null
     */
    public function resolveByToken(string $token): ?array
    {
        $hb = FoodAlchemistHandbuch::query()->where('token', $token)->first();
        if ($hb === null || ! $hb->istLive() || ! is_array($hb->snapshot_json) || $hb->snapshot_json === []) {
            return null;
        }

        return $this->hydrateImages($hb->snapshot_json);
    }

    // ── Snapshot ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function buildSnapshot(Team $team, Model $entity, string $type, array $kapitel = []): array
    {
        $quelle = $this->quellen->lies($team, $type, $entity);
        $this->zutaten->zuruecksetzen();

        // Gerichte einmalig laden: alle vorkommenden Rezept-IDs → Karten.
        $refs = [];
        foreach ($quelle['sections'] as $sec) {
            foreach ($sec['items'] as $it) {
                $refs[$it['key']] = $it['recipe_id'];
            }
        }
        $recipes = $refs === [] ? collect() : FoodAlchemistRecipe::query()
            ->whereIn('id', array_values(array_unique($refs)))
            ->with([
                'steps.photos', 'platingSteps.photos',
                'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
                'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name',
                'standardPresentation.servingForm', 'standardPresentation.vessel:id,name', 'containers.container',
            ])->get()->keyBy('id');

        $gerichte = [];
        $luecken = [];
        $sections = [];
        foreach ($quelle['sections'] as $sec) {
            $items = [];
            foreach ($sec['items'] as $it) {
                $recipe = $recipes->get($it['recipe_id']);
                if ($recipe === null) {
                    continue;
                }
                if (! isset($gerichte[$it['key']])) {
                    $karte = $this->gerichtKarte($team, $recipe, $it['label']);
                    $karte['codes'] = $it['codes'];
                    $gerichte[$it['key']] = $karte;
                    $fehlt = $this->fehlendeAbschnitte($karte);
                    if ($fehlt !== []) {
                        $luecken[] = ['gericht' => $karte['name'], 'fehlt' => $fehlt];
                    }
                }
                $items[] = ['key' => $it['key'], 'label' => $it['label'], 'indent' => $it['indent'] ?? 0];
            }
            if ($items !== []) {
                $sections[] = ['title' => $sec['title'], 'text' => $sec['text'] ?? null, 'items' => $items];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'type' => 'handbuch',
            'quelle' => $type,
            'title' => $quelle['title'],
            'subtitle' => $quelle['subtitle'],
            'freigabe' => ['at' => now()->toIso8601String(), 'by' => auth()->id(), 'datum' => now()->format('d.m.Y')],
            'sections' => $sections,
            'gerichte' => $gerichte,
            'basisrezepte' => $this->zutaten->basisrezepte(),
            'freie_kapitel' => $kapitel,
            'personen' => isset($quelle['personen']) && (int) $quelle['personen'] > 0 ? (int) $quelle['personen'] : null,
            'legende' => $quelle['legende'] ?? null,
            'luecken' => $luecken,
        ];
    }

    /**
     * Allowlist-Karte eines Gerichts. Keine Mengen, Preise, Lieferanten, KI-Felder.
     *
     * @return array<string,mixed>
     */
    public function gerichtKarte(Team $team, FoodAlchemistRecipe $recipe, string $label): array
    {
        $dar = $recipe->standardPresentation;
        $regen = $this->regeneration->fuerRezept($recipe);
        $regenZeilen = [];
        foreach ([...$regen['gesamt'], ...$regen['komponenten']] as $z) {
            if (($z['herkunft'] ?? null) === RegenerationCascadeService::HERKUNFT_FEHLT) {
                continue;
            }
            $kalt = (bool) ($z['ist_kalt'] ?? false);
            if (! $kalt && $z['device'] === null && $z['temp_c'] === null && $z['duration_min'] === null) {
                continue;
            }
            $regenZeilen[] = [
                'komponente' => (string) $z['label'],
                'kalt' => $kalt,
                'geraet' => $z['device'],
                'temp_c' => $z['temp_c'],
                'dauer_min' => $z['duration_min'],
                'kern_c' => $z['core_temp_c'],
                'hinweis' => $z['note'],
            ];
        }

        $bestandteile = [];
        foreach ($recipe->ingredients as $zl) {
            $name = $zl->display_name ?: ($zl->referencedRecipe?->name ?? $zl->gp?->name);
            if ($name === null || $name === '') {
                continue;
            }
            $bestandteile[] = ['name' => (string) $name, 'garnitur' => ($zl->funktion ?? null) === 'garnitur' || ($zl->role ?? null) === 'garnitur'];
        }

        return [
            'name' => $label !== '' ? $label : (string) $recipe->name,
            'foto' => ($recipe->image_context_file_id || $recipe->image_path)
                ? ['context_file_id' => $recipe->image_context_file_id, 'path' => $recipe->image_path] : null,
            'portion' => [
                'gramm' => $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null,
                'form' => $dar?->servingForm?->label,
                'geschirr' => $dar?->vessel?->name,
                'vehikel' => $dar?->serving_vehicle_vocab_id !== null
                    ? \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_serving_vehicles')->where('id', $dar->serving_vehicle_vocab_id)->value('name')
                    : null,
            ],
            'behaelter' => $this->behaelter($recipe),
            'zutaten' => $this->zutaten->fuerGericht($team, $recipe),
            'zubereitung' => $this->schritte($recipe->steps),
            'anrichten' => $this->schritte($recipe->platingSteps),
            'regeneration' => $regenZeilen,
            'bestandteile' => $bestandteile,
            'codes' => [],
        ];
    }

    /** @return list<array{zweck:string, name:string}> Behälter zum Regenerieren und zur Ausgabe (Eigene Zeilen des Gerichts). */
    private function behaelter(FoodAlchemistRecipe $recipe): array
    {
        $labels = ['regenerieren' => 'Regenerieren', 'ausgabe' => 'Ausgabe'];
        $out = [];
        foreach ($recipe->containers as $c) {
            if (isset($labels[$c->zweck]) && $c->container?->name) {
                $out[] = ['zweck' => $labels[$c->zweck], 'name' => (string) $c->container->name];
            }
        }

        return $out;
    }

    /** @return list<array{nr:int, text:string, fotos:list<array{context_file_id:?int, path:?string, caption:?string}>}> */
    private function schritte(iterable $steps): array
    {
        $out = [];
        $nr = 0;
        foreach ($steps as $s) {
            $nr++;
            $fotos = [];
            foreach ($s->photos as $p) {
                $fotos[] = ['context_file_id' => $p->context_file_id ?? null, 'path' => $p->pfad, 'caption' => $p->caption];
            }
            $out[] = ['nr' => $nr, 'text' => (string) $s->text, 'fotos' => $fotos];
        }

        return $out;
    }

    /** @return list<string> */
    private function fehlendeAbschnitte(array $karte): array
    {
        $fehlt = [];
        foreach (['zutaten' => 'Zutaten', 'zubereitung' => 'Zubereitung', 'anrichten' => 'Anrichten', 'regeneration' => 'Regeneration'] as $k => $l) {
            if (($k === 'zutaten' ? $karte[$k]['zeilen'] : $karte[$k]) === []) {
                $fehlt[] = $l;
            }
        }

        return $fehlt;
    }

    // ── Bilder ─────────────────────────────────────────────────────────────

    /** Identifier → frisch signierte URL (Snapshot speichert nur Identifier). */
    public function hydrateImages(array $snapshot): array
    {
        $url = fn (?array $img) => (is_array($img) && (($img['context_file_id'] ?? null) || ($img['path'] ?? null)))
            ? $this->media->url($img['context_file_id'] ?? null, $img['path'] ?? null) : null;
        foreach ($snapshot['gerichte'] ?? [] as $key => $g) {
            $snapshot['gerichte'][$key]['foto_url'] = $url($g['foto'] ?? null);
            foreach (['zubereitung', 'anrichten'] as $abschnitt) {
                foreach ($g[$abschnitt] ?? [] as $i => $s) {
                    foreach ($s['fotos'] ?? [] as $j => $f) {
                        $snapshot['gerichte'][$key][$abschnitt][$i]['fotos'][$j]['url'] = $url($f);
                    }
                }
            }
        }

        return $snapshot;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function resolveEntity(Team $team, string $type, int $id, bool $forWrite): Model
    {
        $class = match ($type) {
            self::TYPE_FOODBOOK => FoodAlchemistFoodbook::class,
            self::TYPE_SPEISEKARTE => FoodAlchemistSpeisekarte::class,
            self::TYPE_SPEISEPLAN => FoodAlchemistSpeiseplan::class,
            self::TYPE_ANGEBOT => FoodAlchemistAngebot::class,
            default => throw new \InvalidArgumentException("Unbekannter Handbuch-Typ '{$type}'."),
        };
        $entity = $class::visibleToTeam($team)->findOrFail($id);
        if ($forWrite && ! $entity->isOwnedBy($team)) {
            throw new \RuntimeException('Diese Ausgabe gehört einem anderen Team und kann nicht veröffentlicht werden.');
        }

        return $entity;
    }

    private function neuerToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (FoodAlchemistHandbuch::withTrashed()->where('token', $token)->exists());

        return $token;
    }

    private function publicUrl(string $token): string
    {
        return route('foodalchemist.presentation.handbuch', ['token' => $token]);
    }

    private function parseExpiry(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
