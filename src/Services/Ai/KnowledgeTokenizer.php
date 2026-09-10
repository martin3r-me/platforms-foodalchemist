<?php

namespace Platform\FoodAlchemist\Services\Ai;

/** Gemeinsames Suchvokabular für Generator, MCP und Wissens-Browser. */
final class KnowledgeTokenizer
{
    private const STOPWORDS = [
        'der' => true, 'die' => true, 'das' => true, 'den' => true, 'dem' => true, 'des' => true,
        'und' => true, 'oder' => true, 'mit' => true, 'ohne' => true, 'fuer' => true, 'auf' => true,
        'von' => true, 'aus' => true, 'ein' => true, 'eine' => true, 'einer' => true, 'eines' => true,
        'einen' => true, 'ist' => true, 'sind' => true, 'wird' => true, 'werden' => true,
        'als' => true, 'auch' => true, 'sehr' => true, 'sowie' => true, 'nach' => true, 'bei' => true,
        'rezept' => true, 'gericht' => true, 'basisrezept' => true, 'komponente' => true,
        'zutaten' => true, 'werte' => true,
        'wie' => true, 'viel' => true, 'viele' => true, 'pro' => true, 'beim' => true,
        'einem' => true, 'zum' => true, 'zur' => true, 'soll' => true, 'sollen' => true,
        'welche' => true, 'welcher' => true, 'welches' => true,
    ];

    /** @return list<string> */
    public function tokenize(string $s): array
    {
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($s));
        $s = (string) preg_replace('/[^[:alnum:]]+/u', ' ', $s);
        $tokens = [];
        foreach (preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (mb_strlen($tok) >= 3 && ! isset(self::STOPWORDS[$tok])) {
                $tokens[$tok] = true;
            }
        }

        return array_map('strval', array_keys($tokens));
    }
}
