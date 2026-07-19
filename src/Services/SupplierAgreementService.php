<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierAgreement;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierDocument;

/**
 * R9.1 (E2/E3/E7) — Absprachen-Log + Dokumenten-Ablage je Lieferant + die
 * Fristen-Abfragen, die das Vertragsfrist-Signal speisen. Datensätze gehören dem
 * anlegenden Team (D1), auch wenn der Lieferant aus der Kette geerbt ist — FA führt
 * die warenwirtschaftliche Beziehung team-eigen.
 */
class SupplierAgreementService
{
    /** Absprache/Zusage anlegen (Supplier muss sichtbar sein). */
    public function create(Team $team, int $supplierId, array $input, ?int $authorId = null): FoodAlchemistSupplierAgreement
    {
        $this->assertSupplierVisible($team, $supplierId);
        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            throw new \RuntimeException('Absprache-Text (note) ist Pflicht.');
        }

        return FoodAlchemistSupplierAgreement::create([
            'team_id' => $team->id,
            'supplier_id' => $supplierId,
            'type' => (string) ($input['type'] ?? 'absprache'),
            'note' => $note,
            'valid_from' => $input['valid_from'] ?? null,
            'valid_to' => $input['valid_to'] ?? null,
            'follow_up_at' => $input['follow_up_at'] ?? null,
            'author_id' => $authorId,
        ]);
    }

    /** @return Collection<int, FoodAlchemistSupplierAgreement> */
    public function forSupplier(Team $team, int $supplierId): Collection
    {
        return FoodAlchemistSupplierAgreement::visibleToTeam($team)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('valid_from')->orderByDesc('id')->get();
    }

    /**
     * Fällige Wiedervorlagen (follow_up_at <= heute + withinDays) — team-sichtbar.
     *
     * @return Collection<int, FoodAlchemistSupplierAgreement>
     */
    public function dueForFollowUp(Team $team, int $withinDays = 0): Collection
    {
        $grenze = now()->addDays(max(0, $withinDays))->toDateString();

        return FoodAlchemistSupplierAgreement::visibleToTeam($team)
            ->whereNotNull('follow_up_at')->whereDate('follow_up_at', '<=', $grenze)
            ->orderBy('follow_up_at')->get();
    }

    /** Dokument-Metadaten anlegen (Supplier muss sichtbar sein). */
    public function addDocument(Team $team, int $supplierId, array $input): FoodAlchemistSupplierDocument
    {
        $this->assertSupplierVisible($team, $supplierId);

        return FoodAlchemistSupplierDocument::create([
            'team_id' => $team->id,
            'supplier_id' => $supplierId,
            'kind' => (string) ($input['kind'] ?? 'vertrag'),
            'title' => $input['title'] ?? null,
            'file_ref' => $input['file_ref'] ?? null,
            'term_start' => $input['term_start'] ?? null,
            'term_end' => $input['term_end'] ?? null,
            'notice_period_days' => isset($input['notice_period_days']) ? (int) $input['notice_period_days'] : null,
        ]);
    }

    /** @return Collection<int, FoodAlchemistSupplierDocument> */
    public function documentsFor(Team $team, int $supplierId): Collection
    {
        return FoodAlchemistSupplierDocument::visibleToTeam($team)
            ->where('supplier_id', $supplierId)->orderByDesc('term_end')->orderByDesc('id')->get();
    }

    /**
     * E7 — Dokumente, deren Kündigungs-Deadline (term_end − notice_period_days) im
     * Fenster [heute, heute + lookaheadDays] liegt und deren Laufzeit noch nicht endete.
     * Speist SignalTyp::VertragsfristFaellig.
     *
     * @return Collection<int, FoodAlchemistSupplierDocument>
     */
    public function documentsDueForNotice(Team $team, int $lookaheadDays = 30): Collection
    {
        $heute = now()->startOfDay();
        $fenster = now()->addDays(max(0, $lookaheadDays))->endOfDay();

        return FoodAlchemistSupplierDocument::visibleToTeam($team)
            ->whereNotNull('term_end')->whereNotNull('notice_period_days')
            ->get()
            ->filter(function (FoodAlchemistSupplierDocument $d) use ($heute, $fenster) {
                $deadline = $d->noticeDeadline();

                return $deadline !== null
                    && $deadline->lte($fenster)
                    && $d->term_end->gte($heute);   // Laufzeit läuft noch → Kündigung noch relevant
            })
            ->values();
    }

    private function assertSupplierVisible(Team $team, int $supplierId): void
    {
        if (! FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->exists()) {
            throw new \RuntimeException('Lieferant nicht im Zugriff (D1).');
        }
    }
}
