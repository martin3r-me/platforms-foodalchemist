# Live-Abnahme Welle 0 (Wissen/Token-Programm)

`welle0_live_abnahme.php` prüft auf **demo** in sieben Schritten, ob Welle 0 wirksam ist.
Sechs Prüfungen sind lesend; Schritt 5 legt zwei Fake-Provider-Calls an und **löscht sie
wieder** aus dem Call-Log, damit die Statistik unverfälscht bleibt. **Kein LLM-Token.**

```bash
scp docs/abnahme/welle0_live_abnahme.php forge@49.13.90.76:/tmp/fa_abnahme.php
ssh forge@49.13.90.76 "cd /home/forge/demo.bhgdigital.de \
  && php artisan tinker --execute=\"require '/tmp/fa_abnahme.php';\" ; rm -f /tmp/fa_abnahme.php"
```

Was geprüft wird:

| # | Prüfung | Warum sie nötig ist |
|---|---|---|
| 1 | Warteschlangen-Defaults leer? | Ein gesetzter Wert ohne Worker lässt Jobs **lautlos** liegen |
| 2 | `cascade_runs.deckel_hinweise` da? | Ohne die Spalte sind die Deckel wieder still |
| 3 | Wohin hängen die zwei Dossiers? | `grounding` an `recipe.eigenschaften` kommt dort nie an |
| 4 | Pflicht-Summe gegen Deckel | Kappung von bindendem Regelwerk ist unsichtbar |
| 5 | Prompt-Zerlegung je Topf | `dropped > 0` heisst: Regelwerk fällt weg |
| 6 | Drift-Signal offen? | Hand-Änderungen an Routings/Bindings scheitern still |
| 7 | Neuer Signal-Typ hat einen Weg | Ein Typ ohne Kategorie/Weg bricht das Cockpit |

## Zwei Fallen, die dieses Skript selbst hatte

1. **`config()` findet Keys mit Punkt IM Namen nicht.** Der Budget-Key heisst wörtlich
   `'recipe.generator'`; `config('…bound_knowledge_budget.recipe.generator')` löst den Punkt
   als Verschachtelung auf und liefert **still den Default 4.200** → falsches »GEKAPPT«.
   Richtig ist, das ganze Array zu holen und in PHP zu indexieren — so macht es auch der
   Produktionscode (`AiGatewayService:366`).
2. **Die Datei braucht `<?php`**, weil sie per `require` in `tinker --execute` läuft. Ohne
   das hält PHP alles für Text — und `php -l` meldet dann fälschlich »keine Fehler«.

## Abhol-Beweis für die Warteschlangen (getrennte Aussage!)

»Jobs werden verteilt« und »Jobs werden abgeholt« sind zwei Dinge. Die `jobs.queue`-Spalte
braucht keinen Worker. Der Abhol-Beweis steht in [[39_Worker_Betrieb_Runbook]] §1a.
