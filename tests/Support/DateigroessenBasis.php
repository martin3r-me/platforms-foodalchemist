<?php

// Spec 85 §4 · Bestand des Größen-Wächters (DateigroessenWaechterTest). Darf nur schrumpfen (Sperrklinke).
// Pfad (relativ zum Modul) => [Zeilen, Grund/Ziel]. Zeilen = Zeilenumbrüche wie `wc -l`.
// Stand 2026-10-19 (main 9e1d3fc6, nach Stufe 4 #514, Sweep #516, C1 PR-1 #515, D-2-Lösch-PRs): 19 Dateien über 1.500 Zeilen.

return [
    'src/Services/OrderService.php' => [3739, 'C2 Bestell-Cluster (Spec 85 §6)'],
    'src/Livewire/Planung/Index.php' => [3427, 'C1 PR-1 … PR-5 (Spec 85 §5)'],
    'src/Services/PlanningCascadeService.php' => [3542, 'nach Stufen schneiden (Spec 85 §9)'],
    'src/Services/SpeiseplanService.php' => [2875, 'Lifecycle / Aushang / Auswertung (Spec 85 §9)'],
    'src/Services/FoodbookService.php' => [2412, 'mit C4 (Spec 85 §8/§9)'],
    'src/Services/PairingService.php' => [2591, 'Lesen / Bewerten / Pflege (Spec 85 §9)'],
    'src/Services/Ai/KnowledgeContextService.php' => [2171, 'Routing / Budget / Prompt-Bau (Spec 85 §9)'],
    'src/Services/DataQualityService.php' => [2040, 'Metriken je Bereich (Spec 85 §9)'],
    'src/Livewire/Angebote/Editor.php' => [1790, 'C4 Komposition (Spec 85 §8)'],
    'resources/views/livewire/produktion/tagesplan.blade.php' => [1646, 'C6 mit Produktion/Tagesplan (Spec 85 §9)'],
    'src/Livewire/Concepter/Editor.php' => [1637, 'C6 Slots / Paket-Positionen (Spec 85 §9)'],
    'src/Livewire/Foodbooks/Index.php' => [1625, 'C4 Komposition (Spec 85 §8); +1 Handy-Weiche #1003 PR6'],
    'resources/views/livewire/concepter/editor.blade.php' => [1605, 'C6 mit Concepter/Editor (Spec 85 §9)'],
    'src/Services/RecipeRecomputeService.php' => [1524, 'Nicht-Kernlogik auslagern (Spec 85 §9)'],
    'src/Services/ConceptService.php' => [1535, 'Nicht-Kernlogik auslagern (Spec 85 §9)'],
    'src/Services/RecipeService.php' => [1526, 'Nicht-Kernlogik auslagern (Spec 85 §9)'],
    'src/Livewire/Verkauf/VkModal.php' => [1532, 'C6 Darreichung / Regeneration (Spec 85 §9)'],
    'src/Services/RecipeGeneratorService.php' => [1528, 'Nicht-Kernlogik auslagern (Spec 85 §9)'],
    'src/Livewire/Produktion/Tagesplan.php' => [1528, 'C6 Feedback / Anleitung (Spec 85 §9)'],
];
