{{--
    Eine klickbare Zeile in einer Browser-Tabelle (GPs, Rezepte, Gerichte, Pakete, Angebote,
    Produktion). Eine Stelle entscheidet, wie „ausgewählt" aussieht — bisher stand das in
    jeder Tabelle einzeln.

    AUSWAHL = Füllung + linker Akzentbalken. Der Balken ist der Grund für den Baustein: mit
    Füllung allein war die Auswahl beim Hovern nicht mehr erkennbar (Hover- und Auswahl-
    Hintergrund konkurrieren). Inaktive Zeilen tragen einen transparenten Balken derselben
    Breite, damit die Auswahl das Layout nicht verschiebt.

    Nutzung — wire:key / wire:click / x-data / data-Marker fließen über $attributes durch:

        x-foodalchemist::table-row  wire:key="gp-{id}"  wire:click="waehle({id})"
            :active="$gpId === $gp->id"  data-gp-zeile="{id}"
          → Slot: die <td>-Zellen

    Nicht klickbare Tabellen (Einstellungen, Preis-Historie) brauchen den Baustein nicht —
    dort ist `$tr` weiterhin richtig.
--}}
@props(['active' => false])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<tr {{ $attributes->merge(['class' => $tr . ' cursor-pointer border-l-2 ' . ($active
        ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 border-violet-500'
        : 'border-transparent')]) }} data-fa-table-row>
    {{ $slot }}
</tr>
