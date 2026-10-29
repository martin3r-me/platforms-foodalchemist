<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * #1002: Schalter „KI-Antworten kommen von außen (MCP-Client) statt vom Plattform-Provider".
 * Nur innerhalb einer expliziten Grenze aktiv (within, finally räumt ab) — wie KnowledgeRunContext.
 *
 * Sammel-Modus (MCP-Tool-Hülle): neue Aufträge werden nicht sofort geschrieben, sondern vorgemerkt. Die Hülle rollt die
 * Tool-Transaktion zurück und legt sie danach außerhalb der Transaktion an — sonst rollten sie mit zurück. Gemeldet
 * wird jeder Auftrag, auf den der Lauf wartet (neu oder schon offen), auch wenn ein Tool die Ausnahme verschluckt.
 */
final class KiExternModus
{
    private bool $aktiv = false;

    private bool $sammeln = false;

    /** @var array<string, mixed>|null Wiederholungs-Nutzlast des laufenden Job-Laufs (P2a), geht in jeden neu angelegten Auftrag */
    private ?array $wiederholung = null;

    /** @var array<string, array<string, mixed>> uuid → Zeile (nur neue, noch nicht geschriebene Aufträge) */
    private array $vorgemerkt = [];

    /** @var array<string, string> uuid → Feature (alle Aufträge, auf die der Lauf wartet) */
    private array $wartet = [];

    public function aktiv(): bool
    {
        return $this->aktiv;
    }

    public function within(callable $action): mixed
    {
        $vorher = $this->aktiv;
        $this->aktiv = true;
        try {
            return $action();
        } finally {
            $this->aktiv = $vorher;
        }
    }

    /** Extern + Sammel-Modus; leert Merker vorher und nachher nicht — die Hülle liest sie nach dem Lauf mit {@see entnimm()}. */
    public function withinSammelnd(callable $action, ?array $wiederholung = null): mixed
    {
        $vorherSammeln = $this->sammeln;
        $vorherWdh = $this->wiederholung;
        $this->sammeln = true;
        $this->wiederholung = $wiederholung;
        $this->vorgemerkt = [];
        $this->wartet = [];
        try {
            return $this->within($action);
        } finally {
            $this->sammeln = $vorherSammeln;
            $this->wiederholung = $vorherWdh;
        }
    }

    /** @return array<string, mixed>|null */
    public function wiederholung(): ?array
    {
        return $this->wiederholung;
    }

    public function sammelt(): bool
    {
        return $this->sammeln;
    }

    /** @param array<string, mixed> $zeile Auftragszeile inkl. `uuid` */
    public function merkeVor(array $zeile): void
    {
        $this->vorgemerkt[(string) $zeile['uuid']] = $zeile;
        $this->wartet[(string) $zeile['uuid']] = (string) $zeile['feature'];
    }

    public function vorgemerktUuid(string $feature, string $hash): ?string
    {
        foreach ($this->vorgemerkt as $uuid => $z) {
            if ($z['feature'] === $feature && $z['messages_hash'] === $hash) {
                return $uuid;
            }
        }

        return null;
    }

    public function meldeWartend(string $uuid, string $feature): void
    {
        $this->wartet[$uuid] = $feature;
    }

    /**
     * Liest und leert die Merker.
     *
     * @return array{vorgemerkt: list<array<string, mixed>>, wartet: array<string, string>}
     */
    public function entnimm(): array
    {
        $r = ['vorgemerkt' => array_values($this->vorgemerkt), 'wartet' => $this->wartet];
        $this->vorgemerkt = [];
        $this->wartet = [];

        return $r;
    }
}
