# Queue-Worker-Betrieb — Runbook (Planung-Leitstelle)

> **Stand:** 2026-08-16 · **Auftrag:** Roadmap [38 »Planung-Leitstelle«](38_Roadmap_Planung_Leitstelle.md), Etappe 8 (Robustheit) »Worker-Präsenz« — Teil 3 (Doku).
> **Kern in einem Satz:** Die Planung-Leitstelle rechnet **nichts** im Web-Request — sie dispatcht ausnahmslos Queue-Jobs (LLM raus aus dem Request). **Ohne einen laufenden `queue:work`-Prozess produziert die Leitstelle nichts**; der Nutzer sieht nur einen Spinner.
> **Quellen (Code):** `src/Services/WorkerHealthService.php` (Heartbeat + Ampel), `src/FoodAlchemistServiceProvider.php:222-234` (Event-Listener), `src/Livewire/Planung/Index.php` (`render()` → Ampel im Cockpit, `pruefeLauf` → reaktiver Per-Lauf-Watchdog).

---

## 0. Warum der Worker mission-critical ist

Die Zielarchitektur (Whiteboard 2026-08-14, s. Roadmap 38) trennt bewusst:

```
Web-Request  ─►  Planung (Leitstelle)  ─►  dispatch  ─►  Queued Jobs  ─►  LLM-Worker
   (schnell)         (nur Steuerung)      (kein LLM        (queue:work)     (Menü/Gericht/
                                           im Request)                       Basisrezept)
```

- **Jeder Go** (`goKaskade`, `kiKopf`, Skizzen-Batch, die drei Ausgabe-Vollkaskaden) legt einen `foodalchemist_cascade_run` an und wirft die eigentliche Arbeit als Job in die **Default-Queue**.
- Läuft **kein** Worker, bleibt der Job liegen. Es gibt **keinen** synchronen Fallback (bewusst — ein LLM-Call im Web-Request würde den Request sprengen).
- Symptom für den Nutzer: „Go" gedrückt, Cockpit zeigt endlosen Spinner, nie „prüfen"/„fertig".

**Konsequenz:** Der Worker-Daemon ist Teil der Laufzeit, nicht optional. Nach **jedem Deploy** muss er (neu) laufen — Laravel-Worker laden Code beim Start, ein alter Worker fährt alten Code weiter (`queue:restart` erzwingt den sauberen Neustart, s. §3).

---

## 1. Worker starten (Betrieb)

Die Food Alchemist nutzt **plain `queue:work`** — **kein Horizon** (nicht als Abhängigkeit installiert; Stand 2026-08-16).

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

- **Queue:** FA-Jobs liefen bis 2026-09-03 **alle** auf der Default-Queue. Seit der Artefakt-Trennung (§1a) können die vier Fan-out-Sorten je eine eigene Schlange bekommen — **standardmässig aber nicht**: ohne gesetzte Env-Variablen bleibt alles auf der Default-Queue und dieses Kapitel gilt unverändert.
- **Daemon, nicht `queue:listen`:** `queue:work` hält den Prozess offen (schneller, kein Framework-Reboot je Job). Er muss von einem Prozess-Manager überwacht und neu gestartet werden (Supervisor / Forge-Daemon / systemd).
- **Auf `demo`:** der Worker läuft als **Forge-Daemon** auf dem App-Host. Nach einem Deploy (Lock-Pin + `foodalchemist:import-master`) prüfen, dass der Daemon aktiv ist und neu gestartet wurde (§3). *(Deploy selbst ist NICHT Teil der Umsetzungs-Routine — nur Dominique.)*
- **Lokal / Sandbox:** ein Terminal mit `php artisan queue:work` daneben offen halten, sonst hängt jeder Go. In der **Test-Suite** laufen Jobs synchron/gefaked — das Runbook gilt für den echten Betrieb, nicht für Pest.

---

## 1a. Fan-out-Trennung nach ARTEFAKT (seit 2026-09-03)

### Warum nicht nach Job-Klasse

Der erste Entwurf trennte nach Job-Klasse. Dominiques Frage kippte ihn:
*„ein großes foodbook oder speisekarte würde doch auch den worker verdrängen oder nicht?"* —
ja. `GenerateRecipeJob` bedient **denselben** Klassen-Namen für einen Einzel-Klick UND für
90 Speiseplan-Zellen. Eine Trennung nach Klasse hätte den Einzel-Klick mit in die
Fan-out-Schlange geworfen und genau nichts gelöst.

Getrennt wird deshalb nach **Artefakt-Sorte am Dispatch-Ort**, nicht nach Klasse:

| Queue-Key (`config('foodalchemist.queue.*')`) | Env | was darauf läuft | Menge je Klick |
|---|---|---|---|
| `gerichte` | `FOODALCHEMIST_QUEUE_GERICHTE` | Speiseplan-Zellen, Speisekarten-Positionen | bis **90** |
| `rezepte` | `FOODALCHEMIST_QUEUE_REZEPTE` | Kaskaden-Kinder (Sub-Rezepte) | bis **50** |
| `kaskade` | `FOODALCHEMIST_QUEUE_KASKADE` | Slot-Concepts (Foodbook/Angebot/Format) | je Slot 1 |
| `anreichern` | `FOODALCHEMIST_QUEUE_ANREICHERN` | Anreicherung je Sub-Rezept | je Sub 1 |

**Defaults sind leer** (`Support\Warteschlange` gibt dann `null` zurück ⇒ Default-Queue).
Verhalten also unverändert, bis jemand die Variablen setzt. Das ist Absicht: der Code kann
vor der Server-Einrichtung deployen.

### Einrichtung auf Forge — Reihenfolge ist eine Sicherheitseigenschaft

Server `49.13.90.76` → Site `demo.bhgdigital.de` → **Queue**.

**1. Erst die vier Worker anlegen.** Je: **Connection** `database` · **Processes** `1` ·
**Timeout** `600` · **Sleep** `3` · **Tries** `2` · **Max Memory** `256` · **Daemon** ja.
Nur das Feld **Queue** unterscheidet sie (`fa-gerichte`, `fa-rezepte`, `fa-kaskade`,
`fa-anreichern`). Sie laufen ins Leere, solange die Env-Variablen fehlen — der sichere Zustand.

**2. Den bestehenden `Queue Worker` NICHT anfassen** (bleibt `numprocs=2`). Er bedient danach
die kleinen, interaktiven Jobs; zwei Prozesse dafür sind Reserve, kein Verschnitt.

**3. Zuletzt** Site → **Environment** um die vier Zeilen ergänzen (`FOODALCHEMIST_QUEUE_*` auf
die Queue-Namen). Ab dem Speichern greift die Trennung.

> ⚠ **Nie die Env-Variablen vor den Workern setzen.** Jobs auf einer Schlange ohne Worker
> bleiben **lautlos** liegen — die Generierung stünde still, ohne Fehlermeldung. Das ist
> dieselbe Symptomatik wie der ewige Spinner in §0, aber mit anderer Ursache.

> ⚠ **Nicht** mehrere Schlangen an einen Worker (`--queue=fa-gerichte,default`). Laravel leert
> die Liste **in der angegebenen Reihenfolge** — der Fan-out würde die kleinen Jobs dann noch
> härter aushungern als vor der Trennung.

### Speicher — gemessen, nicht geschätzt

Laufende Worker belegen real **202 MB** und **172 MB** (nicht die 256 des Limits). Sechs
Prozesse (1 default×2 + 4 Artefakt) ≈ **1,2 GB** bei **2.469 MB frei** auf 2 Kernen. Passt.

### Die offene Warnung: Provider-Rate, nicht Speicher

Vier parallele Generierungs-Worker heissen ~67.000 Token **gleichzeitig** beim Provider
(4 × ⌀16.800). Ob das die TPM-Grenze des Kontos reisst, ist vom Server aus nicht prüfbar.
Im Modul gibt es **kein** `RateLimited` und keinen 429-Backoff — bei `tries=2` würden Jobs
dann **scheitern statt zu warten**. Bei vier Workern vertretbar; **ab sechs vorher den
Backoff einbauen**.

---

## 2. Die Health-Ampel — was sie bedeutet

Das Cockpit zeigt **vor dem Go** eine proaktive Worker-Ampel (`WorkerHealthService::status()`), zusätzlich zum reaktiven Per-Lauf-Watchdog. Jeder lebende Worker stempelt einen **Herzschlag** in den Cache; die Ampel liest dessen Alter.

| Ampel | Bedeutung | Cockpit-Verhalten |
|---|---|---|
| **`gesund`** | Letzter Herzschlag jünger als **60 s** (`STILL_SEKUNDEN`) → ein Worker lebt. | Keine Warnung (Fläche unverändert). |
| **`still`** | Letzter Herzschlag ist **älter als 60 s** → vermutlich kein `queue:work` mehr aktiv. | Amber-Banner: „Kein Hintergrund-Worker aktiv — ein Go bleibt in der Warteschlange liegen …" |
| **`unbekannt`** | **Nie** ein Herzschlag gesehen (Worker lief noch nie / Cache leer). | Gleiches Banner wie `still` (derselbe „liegt liegen"-Fall). |

### Wie der Herzschlag entsteht

- **Signal-Quelle (real, kein Timer):** Laravel feuert `Illuminate\Queue\Events\Looping` bei **jeder** Worker-Schleifen-Iteration — **auch im Leerlauf** (der Worker schläft `--sleep` Sekunden und loopt weiter). Ein lebender Worker stempelt also weiter, selbst ohne Arbeit; ein fehlender Worker stempelt **nie mehr**. Zusätzlich stempelt `JobProcessing` (Job-Start), damit busy-Phasen frisch bleiben.
- **Verdrahtung:** beide Events sind im `FoodAlchemistServiceProvider::boot()` auf `WorkerHealthService::heartbeat()` gehängt.
- **Gedrosselt:** höchstens **1× je 10 s** (`THROTTLE_SEKUNDEN`, atomarer `Cache::add`-Riegel) — kein Cache-Schreibsturm unter Last.
- **Fail-soft:** ein Cache-Fehler kippt **nie** einen Job (der Herzschlag ist Diagnose, kein Arbeitsschritt).
- **Cache-TTL:** der Stempel bleibt **1 h** (`HEARTBEAT_TTL_SEKUNDEN`) lesbar — ein toter Worker liest deshalb als `still` (altes Datum), nicht als `unbekannt`, bis die TTL abläuft.

### ⚠ Abhängigkeit: der Cache-Store muss geteilt & persistent sein

Der Herzschlag lebt im **Cache** (`fa:worker:heartbeat`). Damit die Ampel stimmt, müssen Web-Prozess und Worker **denselben** Cache-Store sehen:

- **`array`-Cache** (Prozess-lokal) → die Ampel funktioniert **nicht** (Web-Prozess sieht den Worker-Stempel nie) → sie zeigt dauerhaft `unbekannt`/`still`, obwohl ein Worker läuft. In Betrieb daher **redis/memcached/database**, nicht `array`.
- Cache leeren (`cache:clear`) setzt die Ampel kurz auf `unbekannt` zurück, bis der Worker den nächsten Herzschlag stempelt (≤ 10 s bei laufendem Worker).

---

## 3. Nach jedem Deploy

1. Code ist deployt (Lock-Pin / `import-master`).
2. **Worker neu starten**, damit er den neuen Code lädt:
   ```bash
   php artisan queue:restart
   ```
   `queue:restart` signalisiert den laufenden Daemons, nach dem aktuellen Job sauber zu beenden — der Prozess-Manager (Forge-Daemon/Supervisor) startet sie mit neuem Code wieder. **Ohne diesen Schritt fährt der alte Worker den alten Code weiter.**
3. Im Cockpit prüfen: Ampel steht binnen ~10 s auf `gesund`.

---

## 4. Zwei Wächter — proaktiv + reaktiv (ergänzen sich)

| Wächter | Wann er anschlägt | Deckt ab |
|---|---|---|
| **Heartbeat-Ampel** (`WorkerHealthService`, proaktiv) | Kein Herzschlag seit **60 s** → `still`/`unbekannt`, **vor** dem Go sichtbar. | Der Worker fehlt ganz / ist abgestürzt (der häufige, teure Fall: Go ins Leere). |
| **Per-Lauf-Watchdog** (`Planung\Index::pruefeLauf`, reaktiv) | Ein **konkreter Lauf** hängt ~**90 s** ohne Fortschritt. | Der eng getaktete Hänger — z. B. ein einzelner, sehr langer Job, bei dem der Herzschlag naturgemäß dazwischen nicht feuert. |

**Blinder Fleck der Ampel (bewusst):** Ein **einzelner** sehr langer Job (z. B. 60-s-LLM-Entwurf) feuert dazwischen kein `Looping` → der Herzschlag kann während dieses einen Jobs altern. Genau diesen Fall deckt der reaktive 90-s-Watchdog ab; `STILL_SEKUNDEN=60` ist deshalb großzügig über THROTTLE + realistischer Einzel-Job-Dauer gewählt, damit ein in-flight-Job die Ampel nicht fälschlich auf `still` kippt.

---

## 5. Troubleshooting-Kurzref

| Symptom | Wahrscheinliche Ursache | Prüfen / Fix |
|---|---|---|
| Go → endloser Spinner, nie „prüfen"/„fertig" | Kein Worker aktiv | Läuft `queue:work`? (`ps`/Forge-Daemon-Status). Ampel im Cockpit? Worker starten (§1). |
| Ampel `still`/`unbekannt`, aber Worker läuft nachweislich | Cache nicht geteilt/persistent (`array`) oder gerade `cache:clear` | Cache-Store prüfen (redis/database, nicht `array`). Nach `cache:clear` ≤ 10 s warten. |
| Nach Deploy altes Verhalten trotz neuem Code | Worker fährt alten Code | `php artisan queue:restart` (§3). |
| Läufe hängen einzeln bei ~90 s mit Watchdog-Hinweis | Provider-Timeout / hängender Einzel-Job | Job-Logs / `failed_jobs` prüfen; Provider-Erreichbarkeit. |

---

## Changelog
- **2026-08-16** — Erstanlage (Roadmap 38, Et.8 »Worker-Präsenz« Teil 3). Grounded auf `WorkerHealthService` + Provider-Listener; Horizon-Abwesenheit + Cache-Abhängigkeit + Deploy-`queue:restart` dokumentiert.
- **2026-09-03** — §1a Fan-out-Trennung nach Artefakt-Sorte (4 Queues, Defaults leer). §1 korrigiert: „kein dedizierter Queue-Name nötig" stimmte nicht mehr. Trennung nach Job-KLASSE verworfen, weil `GenerateRecipeJob` Einzel-Klick und 90er-Fan-out teilt. Einrichtungs-Reihenfolge (Worker vor Env) als Sicherheitseigenschaft dokumentiert.
