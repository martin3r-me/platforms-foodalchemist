<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Support\DossierText;

/** Gemeinsamer Renderer für Budgetreservierung und tatsächlichen Kanon-Block. */
final class KnowledgeCanonText
{
    public static function requiredChars(Collection $rows): int
    {
        return mb_strlen(self::block($rows->where('mode', 'pflicht')->map(fn ($doc) => self::document($doc))->all()));
    }

    public static function document(object $doc): string
    {
        return "## KANON: {$doc->slug}\n\n".DossierText::ohneVorspann((string) $doc->content_md);
    }

    /** @param list<string> $blocks */
    public static function block(array $blocks): string
    {
        return $blocks === [] ? '' : "# VERBINDLICHES REGELWERK (gilt für jede Antwort dieses Auftrags)\n\n"
            .implode("\n\n---\n\n", $blocks);
    }
}
