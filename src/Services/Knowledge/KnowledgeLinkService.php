<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\WissenGesperrtException;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 52 · H6 — Verbindungen zwischen Dossiers setzen, lesen, lösen.
 *
 * Der Zweck ist nicht Navigation, sondern **Nachvollziehbarkeit beim Neuschnitt**: aus einem
 * alten Dossier werden zwei, aus dreien eins. Ohne festgehaltene Herkunft ist danach nicht
 * mehr feststellbar, was wodurch ersetzt wurde — und die Kanon-Zeilen finden ihren neuen
 * Anker nicht.
 */
class KnowledgeLinkService
{
    private const TABLE = 'foodalchemist_knowledge_links';

    private const DOCS = 'foodalchemist_knowledge_documents';

    public function verfuegbar(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * Verbindung setzen (Upsert auf team × von × nach × art).
     *
     * @return array<string, mixed>
     */
    public function set(Team $team, string $vonSlug, string $nachSlug, string $art, ?string $notiz = null): array
    {
        if (! $this->verfuegbar()) {
            throw new RuntimeException('Verbindungs-Tabelle fehlt — Migration nicht gelaufen.');
        }
        if (! Wissensverbindung::gueltig($art)) {
            throw new RuntimeException('Unbekannte Verbindungs-Art «'.$art.'». Erlaubt: '.implode(', ', Wissensverbindung::ALLE).'.');
        }

        $von = $this->docFuerSchreiben($team, $vonSlug);
        $nach = $this->docFuerSchreiben($team, $nachSlug, nurLesen: true);

        if ($von->id === $nach->id) {
            throw new RuntimeException('Ein Dossier kann nicht mit sich selbst verbunden werden.');
        }
        // Nur bei `ersetzt`: eine Nachfolge-Kette, die im Kreis läuft, macht die
        // Nachfolger-Empfehlung unendlich. Bei `siehe_auch` ist Gegenseitigkeit dagegen normal.
        if ($art === Wissensverbindung::ERSETZT && $this->wuerdeKreisen($team, (int) $von->id, (int) $nach->id)) {
            throw new RuntimeException(
                '«'.$vonSlug.'» ersetzt «'.$nachSlug.'» würde eine Nachfolge-Schleife bilden — '
                .$nachSlug.' ersetzt (direkt oder über weitere Schritte) bereits '.$vonSlug.'.'
            );
        }

        $eigen = $this->sichtbar($team)
            ->where('l.von_document_id', $von->id)->where('l.nach_document_id', $nach->id)
            ->where('l.art', $art)->first(['l.id']);

        if ($eigen !== null) {
            DB::table(self::TABLE)->where('id', $eigen->id)->update([
                'notiz' => $notiz, 'active' => 1, 'deleted_at' => null, 'updated_at' => now(),
            ]);
        } else {
            DB::table(self::TABLE)->insert([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => $von->team_id,          // die Kante gehört dem Ausgangs-Dossier
                'von_document_id' => $von->id,
                'nach_document_id' => $nach->id,
                'art' => $art,
                'notiz' => $notiz,
                'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['von' => $vonSlug, 'nach' => $nachSlug, 'art' => $art, 'notiz' => $notiz];
    }

    public function remove(Team $team, string $vonSlug, string $nachSlug, string $art): bool
    {
        if (! $this->verfuegbar()) {
            return false;
        }
        $von = $this->docFuerSchreiben($team, $vonSlug);
        $nach = $this->docFuerSchreiben($team, $nachSlug, nurLesen: true);

        return $this->sichtbar($team)
            ->where('l.von_document_id', $von->id)->where('l.nach_document_id', $nach->id)
            ->where('l.art', $art)
            ->update(['active' => 0, 'deleted_at' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * Alle Verbindungen eines Dossiers, in beide Richtungen.
     *
     * @return array{raus: list<array<string, mixed>>, rein: list<array<string, mixed>>}
     */
    public function fuerDossier(Team $team, string $slug): array
    {
        if (! $this->verfuegbar()) {
            return ['raus' => [], 'rein' => []];
        }
        $doc = $this->doc($team, $slug);
        if ($doc === null) {
            return ['raus' => [], 'rein' => []];
        }

        $zeile = fn ($r, string $richtung) => [
            'art' => (string) $r->art,
            'slug' => (string) $r->partner_slug,
            'titel' => (string) $r->partner_title,
            'partner_aktiv' => (bool) $r->partner_active,
            'notiz' => $r->notiz === null ? null : (string) $r->notiz,
            'richtung' => $richtung,
        ];

        $raus = $this->sichtbar($team)
            ->join(self::DOCS.' as p', 'p.id', '=', 'l.nach_document_id')
            ->where('l.von_document_id', $doc->id)->whereNull('p.deleted_at')
            ->orderBy('l.art')->orderBy('p.slug')
            ->get(['l.art', 'l.notiz', 'p.slug as partner_slug', 'p.title as partner_title', 'p.active as partner_active'])
            ->map(fn ($r) => $zeile($r, 'raus'))->all();

        $rein = $this->sichtbar($team)
            ->join(self::DOCS.' as p', 'p.id', '=', 'l.von_document_id')
            ->where('l.nach_document_id', $doc->id)->whereNull('p.deleted_at')
            ->orderBy('l.art')->orderBy('p.slug')
            ->get(['l.art', 'l.notiz', 'p.slug as partner_slug', 'p.title as partner_title', 'p.active as partner_active'])
            ->map(fn ($r) => $zeile($r, 'rein'))->all();

        return ['raus' => $raus, 'rein' => $rein];
    }

    /**
     * ★ Der Nachfolger eines abgelösten Dossiers — die eine Frage, für die `H6` gebaut ist.
     *
     * Zeigt eine Kanon-Zeile auf ein deaktiviertes Dossier, will der Kurator nicht wissen
     * „etwas ist kaputt", sondern „woran häng ich die Zeile jetzt". Genau das liefert diese
     * Methode: die AKTIVEN Dossiers, die das gegebene per `ersetzt` ablösen.
     *
     * @return list<string>
     */
    public function nachfolgerVon(Team $team, string $slug): array
    {
        if (! $this->verfuegbar()) {
            return [];
        }
        $doc = $this->doc($team, $slug);
        if ($doc === null) {
            return [];
        }

        return $this->sichtbar($team)
            ->join(self::DOCS.' as p', 'p.id', '=', 'l.von_document_id')
            ->where('l.nach_document_id', $doc->id)->where('l.art', Wissensverbindung::ERSETZT)
            ->where('p.active', 1)->whereNull('p.deleted_at')
            ->orderBy('p.slug')->pluck('p.slug')->map(fn ($s) => (string) $s)->all();
    }

    /**
     * Aktive Dossiers, die abgelöst wurden, ohne dass ein Nachfolger benannt ist —
     * beim Neuschnitt die Liste, die man abarbeiten will.
     *
     * @return list<string>
     */
    public function abgeloestOhneNachfolger(Team $team): array
    {
        if (! $this->verfuegbar()) {
            return [];
        }

        $q = DB::table(self::DOCS.' as d')
            ->where('d.active', 0)->whereNull('d.deleted_at')
            ->whereNotExists(function ($sub) {
                $sub->from(self::TABLE.' as l')
                    ->join(self::DOCS.' as p', 'p.id', '=', 'l.von_document_id')
                    ->whereColumn('l.nach_document_id', 'd.id')
                    ->where('l.art', Wissensverbindung::ERSETZT)
                    ->where('l.active', 1)->whereNull('l.deleted_at')
                    ->where('p.active', 1)->whereNull('p.deleted_at')
                    ->selectRaw('1');
            });
        TeamScope::applyVisible($q, 'd.team_id', $team);

        return $q->orderBy('d.slug')->pluck('d.slug')->map(fn ($s) => (string) $s)->all();
    }

    /** Läuft die `ersetzt`-Kette von `$nachId` (über beliebig viele Schritte) auf `$vonId` zurück? */
    private function wuerdeKreisen(Team $team, int $vonId, int $nachId): bool
    {
        $offen = [$nachId];
        $gesehen = [];
        while ($offen !== []) {
            $aktuell = array_pop($offen);
            if (isset($gesehen[$aktuell])) {
                continue;
            }
            $gesehen[$aktuell] = true;

            $weiter = $this->sichtbar($team)
                ->where('l.von_document_id', $aktuell)->where('l.art', Wissensverbindung::ERSETZT)
                ->pluck('l.nach_document_id');
            foreach ($weiter as $id) {
                if ((int) $id === $vonId) {
                    return true;
                }
                $offen[] = (int) $id;
            }
        }

        return false;
    }

    private function sichtbar(Team $team): \Illuminate\Database\Query\Builder
    {
        $q = DB::table(self::TABLE.' as l')
            ->whereNull('l.deleted_at')->where('l.active', 1);
        TeamScope::applyVisible($q, 'l.team_id', $team);

        return $q;
    }

    private function doc(Team $team, string $slug): ?object
    {
        $q = DB::table(self::DOCS)->whereNull('deleted_at')->where('slug', $slug);
        TeamScope::applyVisible($q, 'team_id', $team);

        return $q->first(['id', 'team_id', 'slug', 'active']);
    }

    /**
     * Das Dossier holen — und beim AUSGANGS-Dossier zusätzlich Schreibrecht prüfen.
     *
     * Die Kante gehört dem Ausgangs-Dossier, deshalb entscheidet dessen Eigentum. Das ZIEL
     * darf fremd sein (geerbtes Master-Wissen zu verfeinern ist der Normalfall) — sonst könnte
     * ein Team seine eigenen Dossiers nicht auf den globalen Katalog beziehen.
     */
    private function docFuerSchreiben(Team $team, string $slug, bool $nurLesen = false): object
    {
        $doc = $this->doc($team, $slug);
        if ($doc === null) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }
        if (! $nurLesen && ! TeamScope::mayWrite($doc->team_id, $team)) {
            throw new WissenGesperrtException(
                "\"{$slug}\" ist globales Master-Wissen — Verbindungen daran setzt das Master-Team."
            );
        }

        return $doc;
    }
}
