<?php

namespace Platform\FoodAlchemist\Exceptions;

use RuntimeException;

/**
 * „Dieses Dossier gehört dir nicht" — als TYP, nicht als Satz.
 *
 * Anlass (2026-09-11): der Fehlercode `LOCKED` wurde an zwei Stellen aus dem Meldungstext
 * geraten — `str_contains($msg, 'Master-/Seed-Wissen')` in {@see \Platform\FoodAlchemist\Tools\KnowledgeUpdateTool}
 * und {@see \Platform\FoodAlchemist\Tools\KnowledgeLinksTool}. Beim Umformulieren der Meldung
 * („Master-/Seed-Wissen" → „Master-Wissen", weil es kein Seed mehr ist) wäre `LOCKED` still zu
 * `VALIDATION_ERROR` geworden, und der Test, der genau das prüft, wäre grün geblieben — er
 * sieht nur `success === false`.
 *
 * Dieselbe Familie wie [[feedback_prompt_wortlaut_ist_keine_schnittstelle]]: ein Matcher auf
 * Wortlaut ist keine Schnittstelle. Ein Typ schon.
 */
class WissenGesperrtException extends RuntimeException {}
