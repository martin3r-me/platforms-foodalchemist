{{--
    Spec 03 L7b-2 — „Sub-Rezept-Stub + Flag «ausrezeptieren offen»" für BEIDE
    Generator-Modals (eine Fläche, wie oneshot-toggle/oneshot-ergebnis).

    Warum überhaupt: die Kaskade legt für Halbfabrikat-Lücken bewusst Stubs an
    statt rekursiv weiterzugenerieren (L7-DoD v1, Regelwerk §4 gilt erst für v2).
    Ein Stub ist damit ein *gewollter* Zwischenstand — aber einer mit Bringschuld.
    Bis hierher sagte das Ergebnis nur „3 Stubs neu"; welche drei, stand nirgends.
    Genau die drei sind die Arbeit, die nach dem One-Shot noch offen ist.

    Der dauerhafte Flag ist NICHT hier und auch keine neue Spalte: das Signal
    `rezept_sub_stub_offen` (21·S1b) erkennt den Zustand aus dem Bestand. Diese
    Zeile ist die Sichtbarkeit im Moment der Entstehung — sonst erfährt man von
    seinen eigenen Stubs erst beim nächsten Signal-Lauf.
--}}
@props(['stubs' => []])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if(count($stubs ?? []) > 0)
    <div class="mt-3 space-y-1" data-oneshot-stubs>
        <p class="{{ $label }}">Sub-Rezept-Stubs angelegt — ausrezeptieren offen:</p>
        @foreach($stubs as $stub)
            <p class="text-[11px] text-gray-600">🧩 {{ $stub['name'] }} <span class="text-amber-700">· leer, Zutaten fehlen</span></p>
        @endforeach
        <p class="text-[10px] text-gray-500">Bewusst nicht mit-generiert (v1): jeder Stub wird einzeln ausrezeptiert. Sie stehen bis dahin im Signal «Sub-Rezept-Stub offen».</p>
    </div>
@endif
