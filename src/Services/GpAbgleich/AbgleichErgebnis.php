<?php

namespace Platform\FoodAlchemist\Services\GpAbgleich;

/**
 * Ergebnis des Abgleich-Dienstes {@see GpAbgleichService::entscheide()}: EINE Entscheidung für einen Lieferantenartikel, dieselbe für Modal, Job,
 * MCP und Eval (Entscheid Dominique 25.10.2026: ein gemeinsamer Dienst für alle Einstiege).
 *
 * `aktion`: verknuepfen (mit `gpId`) · neu (GP aus den Feldern anlegen) · unsicher (nur Vorschlag/Frage) · blockiert (Non-Food) ·
 * bereits_verknuepft (Artikel hängt schon an `gpId`).
 * `stufe`: S0 exakte Brücke · S2 kein Kandidat · S6 KI-Entscheider · ohne_ki · nonfood · verknuepft.
 */
final class AbgleichErgebnis
{
    /**
     * @param  list<string>  $abweichungen  Merkmale/Gründe, die gegen das Ziel-GP sprechen
     * @param  list<array<string,mixed>>  $kandidaten  Top-Kandidaten mit Score, Merkmals-Diff und Guard-Status
     * @param  array<string,mixed>  $felder  Felder aus der Klassifikation (gp.suggest bzw. Struktur) — Grundlage für „neu“
     */
    public function __construct(
        public string $aktion,
        public ?int $gpId = null,
        public string $begruendung = '',
        public float $sicherheit = 0.0,
        public array $abweichungen = [],
        public array $kandidaten = [],
        public array $felder = [],
        public string $stufe = '',
        public bool $automatisch = false,
        public bool $darfVerknuepfen = true,
        public ?string $alternative = null,
        public int $kiCalls = 0,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $dauerMs = 0,
    ) {}

    /** Banner im KI-Knopf (PR B): „Verknüpfen mit #X, weil …“ ab Sicherheit 0,7 (Entscheid Dominique 25.10.2026). */
    public function zeigtBanner(): bool
    {
        return $this->aktion === 'verknuepfen' && $this->gpId !== null && $this->sicherheit >= 0.7;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'aktion' => $this->aktion,
            'gp_id' => $this->gpId,
            'begruendung' => $this->begruendung,
            'sicherheit' => round($this->sicherheit, 3),
            'abweichungen' => $this->abweichungen,
            'automatisch' => $this->automatisch,
            'darf_verknuepfen' => $this->darfVerknuepfen,
            'alternative' => $this->alternative,
            'zeigt_banner' => $this->zeigtBanner(),
            'stufe' => $this->stufe,
            'kandidaten' => $this->kandidaten,
            'felder' => $this->felder,
            'ki' => ['calls' => $this->kiCalls, 'tokens_in' => $this->tokensIn, 'tokens_out' => $this->tokensOut],
            'dauer_ms' => $this->dauerMs,
        ];
    }
}
