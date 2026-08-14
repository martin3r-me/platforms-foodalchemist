# 🧰 Level-up-Backlog der Roadmap-Routine (Planung-Modul)

> Beobachtungen aus den Läufen der Routine `planung-roadmap-umsetzung` — **nichts hiervon wird von
> der Routine selbst umgesetzt** (Scope-Schutz). Entscheidung + Priorisierung: Dominique.
> Status je Eintrag: `neu` · `angenommen` · `verworfen` · `erledigt`.

| # | Datum | Beobachtung | Ort | Status |
|---|---|---|---|---|
| 1 | 2026-08-14 | **Verworfene/neu generierte Steps behalten ihre Kind-Steps.** `regeneriereStep` räumt jetzt nur die `geplant`/`skipped`-Kinder weg — bereits **erzeugte** Kind-Rezepte (`done`/`freigegeben`) eines neu generierten Gerichts bleiben als Steps stehen und gehören danach zu einem Entwurf, den es nicht mehr gibt. Kein Datenverlust, aber irreführende Stufen-Zähler. | `src/Services/PlanningCascadeService.php:701` (`regeneriereStep`) | neu |
| 2 | 2026-08-14 | **Kein Weg, ein geplantes Sub-Rezept abzuwählen.** `verwirfStep` greift nur bei `done`/`failed`; eine `geplant`-Zeile („brauche ich nicht") kann der Mensch nur mitziehen, indem er das ganze Gericht verwirft. Gehört zum Rest-Chunk Teil 2 von Etappe 1. | `src/Services/PlanningCascadeService.php:732` (`verwirfStep`) | neu |
| 3 | 2026-08-14 | **`view:cache`/`view:clear` in der Sandbox brechen an einem Fremd-Fehler** (`platform-crm/src/CrmServiceProvider.php:98`, `Facade::__callStatic("hasTable")` ohne DB). `view:cache` kompiliert trotzdem durch (665 Views, 0 Lint-Fehler), aber **`view:clear` räumt nicht auf** — die Kompilate des gerade eingehängten Worktrees bleiben mit frischer mtime liegen und würden nach dem Symlink-Zurücksetzen der Parallel-Session **fremde Views** servieren. Workaround dieses Laufs: `rm -f storage/framework/views/*.php` nach dem Blade-Lint (Pflichtschritt für künftige Läufe). | `platform/modules/platform-crm` (Fremdmodul → Martin) + Routine-Guardrail | neu |
