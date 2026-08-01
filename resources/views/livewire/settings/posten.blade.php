{{-- Spec 30 E3 — Posten (Küchen-Arbeitsplätze) mit optionaler Tageskapazität. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-settings-posten>
    @if($fehler)<x-foodalchemist::alert tone="danger" class="mb-2" data-posten-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif
    @if($meldung)<x-foodalchemist::alert tone="success" class="mb-2" data-posten-meldung>{{ $meldung }}</x-foodalchemist::alert>@endif

    <p class="text-[11px] text-gray-500 mb-3">
        Ein Posten ist ein <strong>Arbeitsplatz</strong>, kein Mensch. Die Kapazität ist
        <strong>netto</strong> — produktiv verplanbare Minuten, Rüsten und Reinigen schon abgezogen.
        Sie ist <strong>freiwillig</strong>: ohne Zahl warnt der Posten nie, du siehst nur die Minutensumme.
        Zwei Kombidämpfer trägst du als einen Posten mit doppelter Kapazität ein.
    </p>

    <table class="{{ $table }}">
        <thead>
            <tr>
                <th class="{{ $th }} w-full">Posten</th>
                <th class="{{ $th }}">Bereich</th>
                <th class="{{ $th }} text-right whitespace-nowrap">Min./Tag</th>
                <th class="{{ $th }} text-right whitespace-nowrap" title="Abweichende Kapazität am Samstag — leer = wie sonst">Sa</th>
                <th class="{{ $th }} text-right whitespace-nowrap" title="Abweichende Kapazität am Sonntag — leer = wie sonst, 0 = geschlossen">So</th>
                <th class="{{ $th }} w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($posten as $p)
                @php($eigen = (int) $p->team_id === (int) $eigenesTeamId)
                <tr class="{{ $tr }} {{ $p->is_inactive ? 'opacity-50' : '' }}" wire:key="posten-{{ $p->id }}" data-posten="{{ $p->id }}">
                    <td class="{{ $td }}">
                        @if($eigen)
                            <input type="text" value="{{ $p->name }}" wire:change="feldSetzen({{ $p->id }}, 'name', $event.target.value)"
                                   class="{{ $input }} !py-0.5" data-posten-name />
                        @else
                            {{ $p->name }} <span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1" title="Vorlage aus dem Eltern-Team — die Auslastung rechnet trotzdem je Betrieb">geerbt</span>
                        @endif
                    </td>
                    <td class="{{ $td }}">
                        @if($eigen)
                            <input type="text" value="{{ $p->group_name }}" wire:change="feldSetzen({{ $p->id }}, 'group_name', $event.target.value)"
                                   class="{{ $input }} !py-0.5 !w-40" placeholder="z. B. Warme Küche" data-posten-gruppe />
                        @else
                            <span class="text-[11px] text-gray-500">{{ $p->group_name }}</span>
                        @endif
                    </td>
                    <td class="{{ $td }} text-right">
                        @if($eigen)
                            <input type="text" inputmode="numeric" value="{{ $p->kapazitaet_min_pro_tag }}"
                                   wire:change="feldSetzen({{ $p->id }}, 'kapazitaet', $event.target.value)"
                                   class="{{ $input }} !py-0.5 !w-20 text-right tabular-nums" placeholder="—"
                                   title="leer = plant nicht mit Kapazität und warnt nie" data-posten-kapazitaet />
                        @else
                            <span class="tabular-nums">{{ $p->kapazitaet_min_pro_tag ?? '—' }}</span>
                        @endif
                    </td>
                    @foreach([6 => 'sa', 7 => 'so'] as $iso => $marker)
                        <td class="{{ $td }} text-right">
                            @if($eigen)
                                <input type="text" inputmode="numeric" value="{{ ($p->kapazitaet_wochentag ?? [])[(string) $iso] ?? '' }}"
                                       wire:change="wochentagSetzen({{ $p->id }}, {{ $iso }}, $event.target.value)"
                                       class="{{ $input }} !py-0.5 !w-14 text-right tabular-nums" placeholder="—"
                                       data-posten-{{ $marker }} />
                            @else
                                <span class="tabular-nums text-[11px] text-gray-500">{{ ($p->kapazitaet_wochentag ?? [])[(string) $iso] ?? '—' }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="{{ $td }} whitespace-nowrap">
                        @if($eigen)
                            <button type="button" wire:click="aktivToggle({{ $p->id }})" class="{{ $btnGhostXs }}"
                                    title="Lösch-Schutz: Posten werden stillgelegt, nicht gelöscht — sonst liefen bestehende Zuteilungen ins Leere"
                                    data-posten-toggle>{{ $p->is_inactive ? 'reaktivieren' : 'stilllegen' }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="{{ $td }} text-[12px] text-gray-500">Noch keine Posten. Unten anlegen — z. B. „Warme Küche", „Kalte Küche", „Patisserie".</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="flex flex-wrap items-end gap-2 mt-3 pt-3 border-t border-black/5" data-posten-neu>
        <label class="block">
            <span class="{{ $label }}">Posten</span>
            <input type="text" wire:model="neu.name" wire:keydown.enter="create" class="{{ $input }} !py-1 w-56" placeholder="Warme Küche" data-neu-name />
        </label>
        <label class="block">
            <span class="{{ $label }}">Bereich (optional)</span>
            <input type="text" wire:model="neu.group_name" class="{{ $input }} !py-1 w-40" placeholder="Küche" data-neu-gruppe />
        </label>
        <label class="block">
            <span class="{{ $label }}">Min./Tag (optional)</span>
            <input type="text" inputmode="numeric" wire:model="neu.kapazitaet" class="{{ $input }} !py-1 w-24 text-right" placeholder="480" data-neu-kapazitaet />
        </label>
        <button type="button" wire:click="create" class="{{ $btnPrimary }}" data-posten-anlegen>Anlegen</button>
    </div>
</div>
