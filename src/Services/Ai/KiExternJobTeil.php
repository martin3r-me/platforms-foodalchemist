<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * #1002 (Paket Kaskade): gemeinsamer Teil der {@see KiExternWiederholbar}-Jobs der Planungs-Kaskade. Der Job trägt `public bool $extern`,
 * `public int $versuch` und `teamId`; `erbeExtern()` im Konstruktor macht einen Job, der aus einem externen MCP-Aufruf oder einem
 * externen Lauf angestoßen wird, selbst extern ({@see KiExternModus::fuerDispatch()}). Ein Job aus der Oberfläche bleibt auf dem Plattformweg.
 */
trait KiExternJobTeil
{
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externTeamId(): int
    {
        return $this->teamId;
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    /** Im Konstruktor aufrufen: wird zur Dispatch-Zeit ausgewertet, nicht beim Deserialisieren im Worker. */
    protected function erbeExtern(): void
    {
        $this->extern = $this->extern || app(KiExternModus::class)->fuerDispatch();
        if ($this->extern && $this->versuch === 0) {   // ein Neustart durch ANTWORT (Versuch > 0) wird sofort angestoßen, nicht an eine fremde Transaktion gehängt
            $this->afterCommit = true;   // aus einem externen Rumpf angestoßen: erst nach dem Commit, ein Rollback darf kein Kind hinterlassen
        }
    }
}
