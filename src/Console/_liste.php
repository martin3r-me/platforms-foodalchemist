<?php

/**
 * Alle Artisan-Commands des Moduls — EINE Zeile je Command, alphabetisch (98 · 1.2d, Muster wie `src/Tools/_liste.php`).
 *
 * Warum eine eigene Datei: Die Liste stand im ServiceProvider, dem Konflikt-Hotspot des Moduls (348 Änderungen in
 * 6 Monaten). Fast jeder Feature-Branch fügte dort eine Zeile ans Ende und kollidierte mit allen anderen. Alphabetisch
 * einsortiert treffen sich zwei Branches nur noch, wenn sie Nachbar-Commands anlegen.
 *
 * Neuer Command: Zeile alphabetisch einfügen. `CommandListeTest` prüft Sortierung, Dubletten und dass jede konkrete
 * Command-Klasse unter `src/Console` hier steht. Zeitplanung: `src/Console/_zeitplan.php`.
 */

return [
    \Platform\FoodAlchemist\Console\AboHuelleNachfuehrenCommand::class,
    \Platform\FoodAlchemist\Console\AbtropfBackfillCommand::class,   // Abtropfgewicht aus dem Artikeltext (12.11.2026, Standard Trockenlauf)
    \Platform\FoodAlchemist\Console\AllergeneNurPositivCommand::class,   // Spec 85
    \Platform\FoodAlchemist\Console\AnkerWissenCommand::class,
    \Platform\FoodAlchemist\Console\BehaelterKatalogCommand::class,
    \Platform\FoodAlchemist\Console\DarreichungMwstLeerenCommand::class,   // MwSt „aus Preisklasse“ wieder leer (18.11.2026)
    \Platform\FoodAlchemist\Console\DataQualityCommand::class,
    \Platform\FoodAlchemist\Console\DiaetEignungNeuCommand::class,   // Plan 98: Diät-Eignung „ohne Deklaration unbekannt“ (Standard Trockenlauf)
    \Platform\FoodAlchemist\Console\Diagnose\AbgleichEvalCommand::class,   // Abgleich-Dienst: Eval (schreibt nie, zurückgerollte Transaktion)
    // Bereitschafts-Prüfung für den Cutover (lesend) — GO/NO-GO je Pool.
    \Platform\FoodAlchemist\Console\Diagnose\EmbeddingCutoverCheckCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\EmbedEvalCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\FremdSchreibenCommand::class,   // Spec 84 B4: Auswertung fa-fremdschreiben
    \Platform\FoodAlchemist\Console\Diagnose\GeneratorEvalCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\GpAnkerVorauswahlMessenCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\GpHomogenitaetBerichtCommand::class,   // Spec 82 §2 (nur lesend)
    \Platform\FoodAlchemist\Console\Diagnose\GpSensorikReferenzCommand::class,   // 29.10.2026: Live-Abnahme Wahrnehmung (nur lesend)
    // Spec 50 Strang III: Arbeitsliste zu großer Dossiers (ein Thema pro Dossier ≤ Deckel).
    \Platform\FoodAlchemist\Console\Diagnose\KnowledgeOversizedCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\LangsameAbfragenCommand::class,   // Spec 84 E-1: Auswertung fa-langsam
    \Platform\FoodAlchemist\Console\Diagnose\LeadLaInaktiveCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\MatcherEvalCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\MoneyTruthReportCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\NaehrwertePruefenCommand::class,   // Nährwert-Prüfung: Trockenlauf + Meldeliste (nur lesend)
    \Platform\FoodAlchemist\Console\Diagnose\RegelnKonsumentenCommand::class,   // Spec 81: Schutz vor Paket 5
    \Platform\FoodAlchemist\Console\Diagnose\RezeptLueckenVergleichCommand::class,   // 29.10.2026: Messung K1/K4a vorher/nachher (nur lesend)
    \Platform\FoodAlchemist\Console\Diagnose\SpiegelfelderCommand::class,   // 98 · 3.5 · 6c: Abweichungsreport Spiegelfelder (nur lesend)
    \Platform\FoodAlchemist\Console\Diagnose\TrendThemenAehnlichkeitCommand::class,   // Spec 93 B (Messung, schreibt nichts)
    \Platform\FoodAlchemist\Console\Diagnose\VollstaendigkeitReportCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\WaisenCommand::class,   // 98 · 3.5: Waisen-Report der Kernkette (nur lesend)
    // W2-4: §-Deckung — hätte den verlorenen §12 gefunden.
    \Platform\FoodAlchemist\Console\Diagnose\WissenDeckungCommand::class,
    // Spec 52/A1: Grundlinie am tatsächlich versendeten Aufruf — inkl. der drei
    // Folge-Calls, die der Kontext-Inspektor ausblendet.
    \Platform\FoodAlchemist\Console\Diagnose\WissenGrundlinieCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\WissenProfilCommand::class,
    // W1-1: misst Findbarkeit jenseits des Embedding-Fensters (vor/nach).
    \Platform\FoodAlchemist\Console\Diagnose\WissenRecallProbeCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\WissenSpurenCommand::class,
    // Spec 52/A2: welcher Prompt-Key bekommt welches Wissen — Kanon, Routing,
    // Bindung, Budget in einer Tabelle. Ungesteuerte Keys sind ein Befund.
    // Abgrenzung zu `wissen-deckung` (W2-4): das prüft die KORPUS-Richtung
    // („nennt ein Prompt einen §, den kein Dossier hat"), das hier die
    // VERSORGUNGS-Richtung („welches Wissen erreicht diesen Prompt überhaupt").
    \Platform\FoodAlchemist\Console\Diagnose\WissenVersorgungCommand::class,
    \Platform\FoodAlchemist\Console\Diagnose\WissenVerweiseCommand::class,
    \Platform\FoodAlchemist\Console\EmbedCommand::class,
    \Platform\FoodAlchemist\Console\EmbeddingsMigrateStoreCommand::class,
    \Platform\FoodAlchemist\Console\EquipmentStandardSetzenCommand::class,   // Spec 82 §6 (Daten per Befehl, nicht per Migration)
    \Platform\FoodAlchemist\Console\FaelligePreiseCommand::class,   // täglich: vorgemerkte Preise (Gültig ab erreicht) aus Preislisten-/Stammdaten-Läufen
    \Platform\FoodAlchemist\Console\FavoriteGpsCommand::class,
    \Platform\FoodAlchemist\Console\GpAllergenBackfillCommand::class,
    \Platform\FoodAlchemist\Console\GpAromaBackfillCommand::class,   // 27.10.2026: Anker + Geschmacksprofil für Lücken (Geschwister, dann KI)
    \Platform\FoodAlchemist\Console\GpBereinigenCommand::class,
    \Platform\FoodAlchemist\Console\GpFelderBackfillCommand::class,   // Spec 80 Paket 11
    \Platform\FoodAlchemist\Console\GpFelderCommand::class,
    \Platform\FoodAlchemist\Console\GpFormsEstimateCommand::class,
    \Platform\FoodAlchemist\Console\GpKeyMerkmaleCommand::class,   // degenerierte gp_keys nachziehen (20.11.2026)
    \Platform\FoodAlchemist\Console\GpPaketImportCommand::class,   // E2: GP-Paket → io (global, stamm_paket)
    \Platform\FoodAlchemist\Console\GpStammPurgeCommand::class,   // Neu-Import: globalen GP-Stamm hart löschen (Trockenlauf, Riegel, Backup-Nachweis)
    \Platform\FoodAlchemist\Console\GpUmhaengenCommand::class,
    \Platform\FoodAlchemist\Console\GrammaturAutomatischCommand::class,   // Plan 98 B: freie Grammaturen → Summe der Bestandteile (Trockenlauf, Journal, Rückweg, Entscheidungsliste)
    \Platform\FoodAlchemist\Console\GrundeinstellungenNachziehenCommand::class,   // Paket Grundeinstellungen stündlich nachziehen (20.11.2026)
    \Platform\FoodAlchemist\Console\ImportArticlesCommand::class,
    \Platform\FoodAlchemist\Console\KaskadeReaperCommand::class,   // hängende Kaskaden-Läufe heilen, alle 15 min (io 26.11.)
    \Platform\FoodAlchemist\Console\KnowledgeEmbedCommand::class,
    // W2-5: der Rückweg — bis hierher gab es nur Import (Modul = SSOT ohne Backup-Pfad).
    \Platform\FoodAlchemist\Console\KnowledgeExportCommand::class,
    \Platform\FoodAlchemist\Console\KnowledgeImportCommand::class,
    // 2026-09-03: die Routing-Politik hat den Import VERLASSEN. Sie lag dort und wurde
    // bei jedem Lauf mitgeschrieben — auf einer frischen DB hätte das den
    // Monolithen-Pfad wiederhergestellt, den Welle 0 abgebaut hat.
    \Platform\FoodAlchemist\Console\KnowledgePolicySeedCommand::class,
    \Platform\FoodAlchemist\Console\KochmediumVorbelegenCommand::class,   // §18.7 Kochmedium-Vorschlag am GP (17.11.2026)
    \Platform\FoodAlchemist\Console\KonzeptPreiseNachrechnenCommand::class,   // CQ price_incomplete: Bestand nachrechnen (Standard Trockenlauf)
    \Platform\FoodAlchemist\Console\LeadSichtNachlaufCommand::class,
    \Platform\FoodAlchemist\Console\LieferantenGruppenEinrichtenCommand::class,
    \Platform\FoodAlchemist\Console\LieferantenGruppenVorschlagenCommand::class,
    \Platform\FoodAlchemist\Console\MarkenEinspielenCommand::class,   // GP-Regelwerk v3.7.1 §5 Ausnahme 3
    \Platform\FoodAlchemist\Console\MarktdatenAbrufenCommand::class,   // Markt in Zahlen monatlich (07.11.2026)
    \Platform\FoodAlchemist\Console\MasterGpsStammHebenCommand::class,   // Master-GPs in den Stamm (20.11.2026)
    \Platform\FoodAlchemist\Console\NectaAbgleichCommand::class,   // Necta-Abgleich: nächtlich + wöchentlich (PR 2)
    \Platform\FoodAlchemist\Console\NonFoodEinrichtenCommand::class,
    \Platform\FoodAlchemist\Console\NonFoodErkennenCommand::class,
    \Platform\FoodAlchemist\Console\PlanNutzungZaehlenCommand::class,   // Spec 82 §13 D (täglich, Tageswerte → team_billable_usages)
    \Platform\FoodAlchemist\Console\PlanPreiseStartwerteCommand::class,   // Spec 82 §13 D (Platzhalter-Startpreise)
    \Platform\FoodAlchemist\Console\PlattformBasisHebenCommand::class,
    \Platform\FoodAlchemist\Console\PlatzhalterDeklarationVorbelegenCommand::class,   // Platzhalter: Allergene/Zusatzstoffe/Nährwerte von Hand vorbelegen (19.11.2026)
    \Platform\FoodAlchemist\Console\PreislisteImportCommand::class,   // Spec 82 §3 (Abgleich, schreibt keine Preise)
    \Platform\FoodAlchemist\Console\RecipeFindingsCommand::class,
    \Platform\FoodAlchemist\Console\RecomputeCommand::class,
    \Platform\FoodAlchemist\Console\RezeptProfileCommand::class,
    \Platform\FoodAlchemist\Console\SignaleDetektorCommand::class,
    \Platform\FoodAlchemist\Console\SpeiseplanAushangRollierenCommand::class,
    \Platform\FoodAlchemist\Console\SteuerklasseBackfillCommand::class,   // MwSt als Klasse statt Zahl (16.11.2026)
    \Platform\FoodAlchemist\Console\TeamOnboardingCommand::class,
    \Platform\FoodAlchemist\Console\TellerRollenKlassifizierenCommand::class,
    \Platform\FoodAlchemist\Console\TerminologyImportCommand::class,
    \Platform\FoodAlchemist\Console\TrendAutomatikCommand::class,   // Spec 93 C
    \Platform\FoodAlchemist\Console\TrendEingangVertiefenCommand::class,   // Vertiefung: Tageslauf
    \Platform\FoodAlchemist\Console\TrendQuellenAbrufenCommand::class,   // Spec 93 A
    \Platform\FoodAlchemist\Console\TrendsMessenCommand::class,   // Spec 79
    \Platform\FoodAlchemist\Console\TrendsStartbestandCommand::class,   // Spec 79
    \Platform\FoodAlchemist\Console\VokabularCodesCommand::class,   // CQ 2.5b
    \Platform\FoodAlchemist\Console\WaisenCommand::class,   // Folge aus Bild-Löschschutz #901/#907
    \Platform\FoodAlchemist\Console\WechselkursHolenCommand::class,
    \Platform\FoodAlchemist\Console\WgRollenCommand::class,   // CQ 3.7: Rollen-Flags der Warengruppen (Probelauf/--apply)
    \Platform\FoodAlchemist\Console\WissenDeckelCheckCommand::class,
    \Platform\FoodAlchemist\Console\WissenEinordnungSicherungCommand::class,
    \Platform\FoodAlchemist\Console\WissenGlobalHebenCommand::class,
    \Platform\FoodAlchemist\Console\WissenKanonSicherungCommand::class,
    \Platform\FoodAlchemist\Console\WissenSteuerdatenW0Command::class,
    \Platform\FoodAlchemist\Console\WissenSteuerungSicherungCommand::class,
];
