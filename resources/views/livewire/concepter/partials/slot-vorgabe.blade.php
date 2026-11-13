{{-- Concepter, Aufbau: leere Position mit Gericht-Vorgabe (Trait ManagesSlotVorgabe). Erwartet $slot und $klein.
     Die Vorgabe sagt der Erstellung, welches Gericht hierher gehört; ohne sie erfindet die KI frei.
     Schreibt beim Verlassen des Felds. Die Notiz erscheint erst, wenn ein Titel steht. --}}
<span class="{{ $klein }} text-[var(--fa-ink-2)]">Leere Position. Links ein Gericht wählen, unten befüllen oder hier vorgeben, welches Gericht entstehen soll.</span>
<x-fa::input size="sm" value="{{ $slot->dish_brief }}" wire:change="vorgabeSpeichern({{ $slot->id }}, 'titel', $event.target.value)"
    wire:key="slot-vorgabe-{{ $slot->id }}" placeholder="Gericht-Vorgabe, zum Beispiel Graved Karotte | Dillcreme | Brot-Crumble" aria-label="Gericht-Vorgabe" data-slot-vorgabe />
@if(trim((string) $slot->dish_brief) !== '')
    <x-fa::input size="sm" value="{{ $slot->dish_brief_note }}" wire:change="vorgabeSpeichern({{ $slot->id }}, 'notiz', $event.target.value)"
        wire:key="slot-vorgabe-notiz-{{ $slot->id }}" placeholder="Notiz zur Vorgabe: Zubereitung, Produkte, was nicht hinein soll" aria-label="Notiz zur Gericht-Vorgabe" data-slot-vorgabe-notiz />
    <span class="{{ $klein }} text-[var(--fa-ink-3)]">Beim Erstellen entsteht genau dieses Gericht. Dein Wortlaut bleibt der Name für Gäste.</span>
@endif
