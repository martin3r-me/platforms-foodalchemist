{{--
    Spec 03 L7b-2 — „Unterrezept-Hülle + Flag «ausrezeptieren offen»" für BEIDE
    Generator-Modals (eine Fläche, wie oneshot-toggle/oneshot-ergebnis).

    Warum überhaupt: die Kaskade legt für Halbfabrikat-Lücken bewusst leere Hüllen an
    statt rekursiv weiterzugenerieren (L7-DoD v1, Regelwerk §4 gilt erst für v2).
    Eine Hülle ist damit ein *gewollter* Zwischenstand — aber einer mit Bringschuld.
    Genau diese Rezepte sind die Arbeit, die nach dem One-Shot noch offen ist.

    Der dauerhafte Flag ist NICHT hier und auch keine neue Spalte: das Signal
    `rezept_sub_stub_offen` (21·S1b) erkennt den Zustand aus dem Bestand. Diese
    Zeile ist die Sichtbarkeit im Moment der Entstehung.
    fa-pass (2026-10-05): Tokens + Bausteine, Prop `stubs` und Marker unverändert.
--}}
@props(['stubs' => []])

@if(count($stubs ?? []) > 0)
    <div class="mt-3 flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-warn-soft)] px-3 py-2.5" data-oneshot-stubs>
        <p class="flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
            @svg('heroicon-o-puzzle-piece', 'w-4 h-4 text-[var(--fa-warn)]')
            Neue Unterrezepte angelegt, ausrezeptieren offen
        </p>
        <ul class="flex flex-col gap-0.5">
            @foreach($stubs as $stub)
                <li class="flex flex-wrap items-center gap-x-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                    {{ $stub['name'] }}
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">leer, Zutaten fehlen</span>
                </li>
            @endforeach
        </ul>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Bewusst nicht mit erstellt: jedes Unterrezept wird einzeln ausrezeptiert. Bis dahin stehen sie im Signal «{{ \Platform\FoodAlchemist\Enums\SignalTyp::RezeptSubStubOffen->label() }}».</p>
    </div>
@endif
