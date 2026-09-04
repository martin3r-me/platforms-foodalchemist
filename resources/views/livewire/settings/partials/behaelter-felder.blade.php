{{-- Spec 51: Feldsatz des Behälter-Katalogs, geteilt von Anlegen und Bearbeiten.
     $praefix = wire:model-Wurzel ('edit' oder 'neu.behaelter'), $f = aktuelle Werte. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-1.5">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" wire:model="{{ $praefix }}.name" placeholder="Name (z. B. Eimer 10 l)" class="{{ $input }} !py-1 w-48" />
        <input type="text" wire:model="{{ $praefix }}.group_name" placeholder="Gruppe" class="{{ $input }} !py-1 w-28" />
        <select wire:model="{{ $praefix }}.familie" class="{{ $input }} !py-1 w-32">
            <option value="">Familie …</option>
            @foreach(\Platform\FoodAlchemist\Livewire\Settings\Behaelter::FAMILIEN as $fam)
                <option value="{{ $fam }}">{{ $fam }}</option>
            @endforeach
        </select>
        <input type="text" wire:model="{{ $praefix }}.format_code" placeholder="Format (1/1)" class="{{ $input }} !py-1 w-24" />
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <span class="text-[11px] text-gray-500 w-28">Maße L×B×T mm</span>
        <input type="text" wire:model="{{ $praefix }}.laenge_mm" placeholder="L" class="{{ $input }} !py-1 w-16 text-right" />
        <input type="text" wire:model="{{ $praefix }}.breite_mm" placeholder="B" class="{{ $input }} !py-1 w-16 text-right" />
        <input type="text" wire:model="{{ $praefix }}.tiefe_mm" placeholder="T" class="{{ $input }} !py-1 w-16 text-right" />
        <input type="text" wire:model="{{ $praefix }}.volumen_l" placeholder="Liter" class="{{ $input }} !py-1 w-20 text-right"
               title="Nennvolumen laut Hersteller — schlägt die Maße, weil GN-Behälter konisch sind" />
        <input type="text" wire:model="{{ $praefix }}.nutzfaktor" placeholder="0,85" class="{{ $input }} !py-1 w-16 text-right"
               title="Anteil, der real befüllt wird (Rand, Radien, Transport)" />
        <input type="text" wire:model="{{ $praefix }}.max_fuellgewicht_kg" placeholder="max kg" class="{{ $input }} !py-1 w-20 text-right"
               title="Handhabungs-Deckel: was ein Mensch noch tragen soll" />
        <input type="text" wire:model="{{ $praefix }}.kapazitaet_kg" placeholder="kg (alt)" class="{{ $input }} !py-1 w-20 text-right"
               title="Alte Handpflege — dient nur noch als letzter Fallback" />
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <span class="text-[11px] text-gray-500 w-28">Freigegeben für</span>
        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::ZWECKE as $zweck)
            <label class="text-[11px] text-gray-600 dark:text-gray-300 inline-flex items-center gap-1">
                <input type="checkbox" wire:model="{{ $praefix }}.eignung" value="{{ $zweck }}" class="rounded" />{{ $zweck }}
            </label>
        @endforeach
        <span class="text-[11px] text-gray-400">nichts angehakt = nicht gepflegt, keine Einschränkung</span>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <label class="text-[11px] text-gray-600 dark:text-gray-300 inline-flex items-center gap-1 w-28">
            <input type="checkbox" wire:model.live="{{ $praefix }}.ist_traeger" class="rounded" />Träger
        </label>
        @if(! empty($f['ist_traeger']))
            <input type="text" wire:model="{{ $praefix }}.traeger_plaetze" placeholder="Plätze" class="{{ $input }} !py-1 w-20 text-right" />
            <input type="text" wire:model="{{ $praefix }}.traeger_format" placeholder="nimmt Format (1/1)" class="{{ $input }} !py-1 w-36" />
            <span class="text-[11px] text-gray-400">nimmt Füllbehälter auf, wird selbst nicht befüllt</span>
        @endif
    </div>
</div>
