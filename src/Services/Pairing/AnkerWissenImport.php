<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\Kantenart;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Spec 60 · P4: strukturiertes Anker-Wissen aus der Dossier-Auslese übernehmen.
 *
 * Eingang ist je Anker ein Profil im Format der Pilot-Auslese (12_DATA/pairing_pilot_2026-10-06):
 * braucht[], liefert{geschmack, textur, beleg}, vertraegt[], zerstoert[], komponenten[], quellen[].
 *
 * Regeln:
 *  - Der Anker muss über ID UND Slug passen, sonst wird das Profil abgelehnt (wie DossierAnker).
 *  - Alles kommt als `entwurf`. Ein erneuter Import ersetzt nur Entwürfe; geprüfte oder
 *    verworfene Zeilen bleiben unangetastet (die Entscheidung eines Menschen gewinnt).
 *  - „Verträgt"/„Zerstört" werden NUR exakt auf Anker aufgelöst (dieselbe Regel wie die
 *    Rezept-Analyse). Was nicht eindeutig ist, landet in `anchor_wissen_offen` (Prüfliste).
 *  - Zerstört mit art technik/zustand ist ein Verarbeitungsfehler, kein Konflikt zwischen
 *    Zutaten → `anchor_wissen_offen` mit art=verarbeitung (Hinweis für die Rezeptprüfung).
 *  - Herkunft: knowledge_document_id (Kombinations-Dossier, sonst erstes Quell-Dossier) und
 *    quelle_hash über die Inhalte aller Quell-Dossiers → Veraltet-Erkennung.
 */
final class AnkerWissenImport
{
    public function __construct(private readonly PairingService $pairing) {}

    /**
     * @param  array<string, mixed>  $p
     * @return array{status: string, anker_id?: int, bedarfe?: int, eigenschaften?: int, komponenten?: int, kombinationen?: int, konflikte?: int, offen?: int}
     */
    public function importiere(array $p): array
    {
        $ankerId = (int) ($p['anker_id'] ?? 0);
        $slug = (string) ($p['anker_slug'] ?? '');
        if ($ankerId === 0 || DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $ankerId)->value('slug') !== $slug) {
            return ['status' => 'abgelehnt_anker'];
        }

        [$docId, $hash] = $this->herkunft((array) ($p['quellen'] ?? []));
        $basis = ['knowledge_document_id' => $docId, 'quelle_hash' => $hash, 'status' => WissensStatus::Entwurf->value,
            'created_at' => now(), 'updated_at' => now()];
        $stat = ['status' => 'ok', 'anker_id' => $ankerId, 'bedarfe' => 0, 'eigenschaften' => 0, 'komponenten' => 0,
            'kombinationen' => 0, 'konflikte' => 0, 'offen' => 0];

        DB::transaction(function () use ($p, $ankerId, $basis, &$stat) {
            $entwurf = WissensStatus::Entwurf->value;
            foreach (['foodalchemist_anchor_bedarfe', 'foodalchemist_anchor_eigenschaften', 'foodalchemist_anchor_komponenten', 'foodalchemist_anchor_wissen_offen'] as $t) {
                DB::table($t)->where('anchor_id', $ankerId)->where('status', $entwurf)
                    ->when($t === 'foodalchemist_anchor_eigenschaften', fn ($q) => $q->where('quelle', 'dossier'))->delete();
            }
            DB::table('foodalchemist_anchor_beziehungen')->where('anchor_a_id', $ankerId)->where('grundlage', 'dossier')
                ->where('status', $entwurf)->delete();

            $geprueft = fn (string $t, array $wo) => DB::table($t)->where('anchor_id', $ankerId)->where($wo)->exists();

            foreach ((array) ($p['braucht'] ?? []) as $b) {
                $achse = Achse::tryFrom((string) ($b['achse'] ?? ''));
                if ($achse === null || $geprueft('foodalchemist_anchor_bedarfe', ['achse' => $achse->value])) {
                    continue;
                }
                DB::table('foodalchemist_anchor_bedarfe')->insert($basis + [
                    'anchor_id' => $ankerId, 'achse' => $achse->value,
                    'staerke' => ($b['staerke'] ?? '') === 'muss' ? 'muss' : 'soll',
                    'beleg' => $this->kurz($b['beleg'] ?? null),
                ]);
                $stat['bedarfe']++;
            }

            $liefert = (array) ($p['liefert'] ?? []);
            $werte = [];
            foreach ((array) ($liefert['geschmack'] ?? []) as $k => $v) {
                $werte[$k] = max(0, min(3, (int) $v));
            }
            foreach ((array) ($liefert['textur'] ?? []) as $k) {
                $werte[(string) $k] ??= 2;
            }
            foreach ($werte as $k => $stufe) {
                $achse = Achse::tryFrom((string) $k);
                if ($achse === null || $geprueft('foodalchemist_anchor_eigenschaften', ['achse' => $achse->value, 'quelle' => 'dossier'])) {
                    continue;
                }
                DB::table('foodalchemist_anchor_eigenschaften')->insert($basis + [
                    'anchor_id' => $ankerId, 'achse' => $achse->value, 'stufe' => $stufe, 'quelle' => 'dossier',
                    'beleg' => $this->kurz($liefert['beleg'] ?? null),
                ]);
                $stat['eigenschaften']++;
            }

            foreach ((array) ($p['komponenten'] ?? []) as $k) {
                $name = trim((string) ($k['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $achsen = array_values(array_filter(array_map(fn ($a) => Achse::tryFrom((string) $a)?->value, (array) ($k['liefert'] ?? []))));
                DB::table('foodalchemist_anchor_komponenten')->insert($basis + [
                    'anchor_id' => $ankerId, 'name' => mb_substr($name, 0, 200),
                    'technik' => $this->kurz($k['technik'] ?? null), 'liefert' => json_encode($achsen),
                ]);
                $stat['komponenten']++;
            }

            foreach ((array) ($p['vertraegt'] ?? []) as $v) {
                $name = trim((string) ($v['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $ziel = $this->pairing->ankerIdExakt($name);
                if ($ziel !== null && $ziel !== $ankerId) {
                    $this->beziehung($ankerId, $ziel, Kantenart::Kombination, $basis, $name);
                    $stat['kombinationen']++;
                } else {
                    $this->offen($ankerId, 'kombination', $name, $v['kontext'] ?? null, null, $basis);
                    $stat['offen']++;
                }
            }

            foreach ((array) ($p['zerstoert'] ?? []) as $z) {
                $name = trim((string) ($z['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (($z['art'] ?? 'zutat') !== 'zutat') {
                    $this->offen($ankerId, 'verarbeitung', $name, null, $z['grund'] ?? null, $basis);
                    $stat['offen']++;

                    continue;
                }
                $ziel = $this->pairing->ankerIdExakt($name);
                if ($ziel !== null && $ziel !== $ankerId) {
                    $this->beziehung($ankerId, $ziel, Kantenart::Konflikt, $basis, $this->kurz($z['grund'] ?? $name));
                    $stat['konflikte']++;
                } else {
                    $this->offen($ankerId, 'konflikt', $name, null, $z['grund'] ?? null, $basis);
                    $stat['offen']++;
                }
            }
        });

        return $stat;
    }

    /** @param  array<string, mixed>  $basis */
    private function beziehung(int $a, int $b, Kantenart $art, array $basis, ?string $beleg): void
    {
        $schluessel = ['anchor_a_id' => $a, 'anchor_b_id' => $b, 'art' => $art->value, 'achse' => ''];
        // Eine geprüfte/verworfene Beziehung bleibt, wie sie ist.
        if (DB::table('foodalchemist_anchor_beziehungen')->where($schluessel)->exists()) {
            return;
        }
        DB::table('foodalchemist_anchor_beziehungen')->insert($schluessel + [
            'rang' => 0, 'grundlage' => 'dossier', 'status' => $basis['status'],
            'knowledge_document_id' => $basis['knowledge_document_id'], 'quelle_hash' => $basis['quelle_hash'],
            'beleg' => $this->kurz($beleg), 'created_at' => $basis['created_at'], 'updated_at' => $basis['updated_at'],
        ]);
    }

    /** @param  array<string, mixed>  $basis */
    private function offen(int $anker, string $art, string $name, ?string $kontext, ?string $grund, array $basis): void
    {
        DB::table('foodalchemist_anchor_wissen_offen')->insert($basis + [
            'anchor_id' => $anker, 'art' => $art, 'name' => mb_substr($name, 0, 200),
            'kontext' => $kontext !== null ? mb_substr($kontext, 0, 60) : null,
            'grund' => $grund !== null ? mb_substr($grund, 0, 300) : null,
        ]);
    }

    /**
     * Kombinations-Dossier bevorzugt, sonst das erste vorhandene; Hash über alle vorhandenen.
     *
     * @param  list<string>  $slugs
     * @return array{0: ?int, 1: ?string}
     */
    private function herkunft(array $slugs): array
    {
        if ($slugs === []) {
            return [null, null];
        }
        $docs = DB::table('foodalchemist_knowledge_documents')->whereIn('slug', $slugs)->whereNull('deleted_at')
            ->orderBy('slug')->get(['id', 'slug', 'content_md']);
        if ($docs->isEmpty()) {
            return [null, null];
        }
        $leit = $docs->first(fn ($d) => str_contains($d->slug, 'kombination')) ?? $docs->first();

        return [(int) $leit->id, hash('sha256', $docs->pluck('content_md')->implode("\n\u{1F}\n"))];
    }

    private function kurz(?string $s): ?string
    {
        $s = $s !== null ? trim($s) : null;

        return $s === null || $s === '' ? null : mb_substr($s, 0, 400);
    }
}
