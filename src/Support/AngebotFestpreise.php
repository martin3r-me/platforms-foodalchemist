<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;

/**
 * Eingefrorenes Angebot = feste Preise (Spec 82 §12 B3, Dominique 19.10.). Ein Request-Kontext (scoped), damit die Preiswege des Angebots —
 * OfferCompositionService::blockPreis, ConceptService::preisCockpit, ConceptPreisService — den eingefrorenen VK je Gericht und Form
 * lesen, ohne dass jede Signatur ein neues Argument bekommt. Ohne aktive Einfrierung: null ⇒ live wie bisher.
 *
 * Kein static: Angebots-IDs wiederholen sich zwischen Tests desselben Prozesses; die scoped-Instanz lebt je Request/Job.
 */
final class AngebotFestpreise
{
    /** @var array<int, array<int, array{vk: ?float, ek_summe: ?float, vollstaendig: bool, je_form: array<int, ?float>}>|null> */
    private array $jeAngebot = [];

    /** @var array<int, ?int> chapter_id → offer_id */
    private array $kapitel = [];

    /** @var array<int, array{vk: ?float, ek_summe: ?float, vollstaendig: bool, je_form: array<int, ?float>}>|null aktiver Kontext */
    private ?array $aktiv = null;

    /** @return array<int, array{vk: ?float, ek_summe: ?float, vollstaendig: bool, je_form: array<int, ?float>}>|null */
    public function fuerAngebot(int $angebotId): ?array
    {
        if (! array_key_exists($angebotId, $this->jeAngebot)) {
            $this->jeAngebot[$angebotId] = app(AngebotEinfrierService::class)->festpreiseFuer($angebotId);
        }

        return $this->jeAngebot[$angebotId];
    }

    public function fuerKapitel(?int $chapterId): ?array
    {
        if ($chapterId === null) {
            return null;
        }
        if (! array_key_exists($chapterId, $this->kapitel)) {
            $this->kapitel[$chapterId] = FoodAlchemistOfferChapter::query()->whereKey($chapterId)->value('offer_id');
        }

        return $this->kapitel[$chapterId] !== null ? $this->fuerAngebot((int) $this->kapitel[$chapterId]) : null;
    }

    /** Nach Einfrieren/Verwerfen/Übernehmen: im selben Request neu lesen. */
    public function vergessen(int $angebotId): void
    {
        unset($this->jeAngebot[$angebotId]);
    }

    /** $fn mit den Festpreisen als aktivem Kontext ausführen (Konzept-Preiswege lesen ihn), danach den alten wiederherstellen. */
    public function mit(?array $festpreise, callable $fn): mixed
    {
        $vorher = $this->aktiv;
        $this->aktiv = $festpreise;
        try {
            return $fn();
        } finally {
            $this->aktiv = $vorher;
        }
    }

    /**
     * Eingefrorener VK eines Gerichts im aktiven Kontext: null = nicht eingefroren (live rechnen); sonst ['vk' => ?float] — VK der Form,
     * sonst der Standard-VK; vk NULL heißt „eingefroren ohne VK“ (EK war unvollständig) ⇒ Preis fehlt, kein Rückfall auf live.
     *
     * @return array{vk: ?float, ek_summe: ?float, vollstaendig: bool}|null
     */
    public function gericht(int $recipeId, ?int $presentationId = null, ?array $festpreise = null): ?array
    {
        $map = $festpreise ?? $this->aktiv;
        if ($map === null || ! isset($map[$recipeId])) {
            return null;
        }
        $z = $map[$recipeId];
        $vk = $presentationId !== null && array_key_exists($presentationId, $z['je_form']) ? $z['je_form'][$presentationId] : $z['vk'];

        return ['vk' => $vk, 'ek_summe' => $z['ek_summe'], 'vollstaendig' => $z['vollstaendig']];
    }
}
