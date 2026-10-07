{{-- Spec 51: Feldsatz des Behälter-Katalogs, geteilt von Anlegen und Bearbeiten.
     $praefix = wire:model-Wurzel ('edit' oder 'neu.behaelter'), $f = aktuelle Werte.
     fa-pass 2026-10-05: Felder mit Label oben (x-fa::field), drei Gruppen: Bezeichnung · Maße · Einsatz. --}}
@php
    $fid = 'bf-' . \Illuminate\Support\Str::slug($praefix);
    $familienLabel = ['EN600x400' => 'EN 600 × 400', 'Traeger' => 'Träger', 'frei' => 'freie Form'];
    $zweckLabel = ['abfuellen' => 'Abfüllen', 'regenerieren' => 'Regenerieren', 'ausgabe' => 'Ausgabe', 'transport' => 'Transport'];
    $zweckOptionen = collect(\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::ZWECKE)->mapWithKeys(fn ($z) => [$z => $zweckLabel[$z] ?? ucfirst($z)])->all();
@endphp

<div class="flex flex-col gap-4">
    <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,9rem),1fr))]">
        <x-fa::field label="Name" for="{{ $fid }}-name" class="col-span-2">
            <x-fa::input id="{{ $fid }}-name" wire:model="{{ $praefix }}.name" placeholder="z. B. Eimer 10 l" />
        </x-fa::field>
        <x-fa::field label="Gruppe" for="{{ $fid }}-gruppe" optional>
            <x-fa::input id="{{ $fid }}-gruppe" wire:model="{{ $praefix }}.group_name" />
        </x-fa::field>
        <x-fa::field label="Familie" for="{{ $fid }}-familie">
            <x-fa::select id="{{ $fid }}-familie" wire:model="{{ $praefix }}.familie">
                <option value="">bitte wählen</option>
                @foreach(\Platform\FoodAlchemist\Livewire\Settings\Behaelter::FAMILIEN as $fam)
                    <option value="{{ $fam }}">{{ $familienLabel[$fam] ?? $fam }}</option>
                @endforeach
            </x-fa::select>
        </x-fa::field>
        <x-fa::field label="Format" for="{{ $fid }}-format" optional>
            <x-fa::input id="{{ $fid }}-format" wire:model="{{ $praefix }}.format_code" placeholder="1/1" />
        </x-fa::field>
    </div>

    <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,7.5rem),1fr))]">
        <x-fa::field label="Länge in mm" for="{{ $fid }}-l">
            <x-fa::input id="{{ $fid }}-l" numeric wire:model="{{ $praefix }}.laenge_mm" />
        </x-fa::field>
        <x-fa::field label="Breite in mm" for="{{ $fid }}-b">
            <x-fa::input id="{{ $fid }}-b" numeric wire:model="{{ $praefix }}.breite_mm" />
        </x-fa::field>
        <x-fa::field label="Tiefe in mm" for="{{ $fid }}-t">
            <x-fa::input id="{{ $fid }}-t" numeric wire:model="{{ $praefix }}.tiefe_mm" />
        </x-fa::field>
        <x-fa::field label="Volumen in l" for="{{ $fid }}-vol">
            <x-fa::input id="{{ $fid }}-vol" numeric wire:model="{{ $praefix }}.volumen_l"
                title="Nennvolumen laut Hersteller. Geht vor den Maßen, weil GN-Behälter konisch sind." />
        </x-fa::field>
        <x-fa::field label="Nutzfaktor" for="{{ $fid }}-nf">
            <x-fa::input id="{{ $fid }}-nf" numeric wire:model="{{ $praefix }}.nutzfaktor" placeholder="0,85"
                title="Anteil, der real befüllt wird (Rand, Radien, Transport)" />
        </x-fa::field>
        <x-fa::field label="Höchstens kg" for="{{ $fid }}-max">
            <x-fa::input id="{{ $fid }}-max" numeric wire:model="{{ $praefix }}.max_fuellgewicht_kg"
                title="Obergrenze zum Tragen: was ein Mensch noch heben soll" />
        </x-fa::field>
        <x-fa::field label="kg (alter Wert)" for="{{ $fid }}-kg">
            <x-fa::input id="{{ $fid }}-kg" numeric wire:model="{{ $praefix }}.kapazitaet_kg"
                title="Früher von Hand gepflegt. Dient nur noch als letzte Rückfalllösung." />
        </x-fa::field>
    </div>
    <p class="-mt-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Volumen geht vor den Maßen, weil GN-Behälter konisch sind. Der Nutzfaktor ist der Anteil, der real befüllt wird.</p>

    <div class="flex flex-wrap items-start gap-x-8 gap-y-3">
        <div class="flex flex-col gap-1.5">
            <x-fa::choice :name="$praefix . '.eignung'" label="Freigegeben für" :multiple="true" :live="false" :options="$zweckOptionen" />
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Nichts gewählt heißt: nicht gepflegt, keine Einschränkung.</p>
        </div>

        <div class="flex flex-col gap-1.5">
            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer">
                <input type="checkbox" wire:model.live="{{ $praefix }}.ist_traeger" class="rounded accent-[var(--fa-accent)]" />Träger
            </label>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Nimmt Füllbehälter auf und wird selbst nicht befüllt.</p>
            @if(! empty($f['ist_traeger']))
                <div class="flex flex-wrap items-end gap-3">
                    <x-fa::field label="Plätze" for="{{ $fid }}-plaetze" class="w-24">
                        <x-fa::input id="{{ $fid }}-plaetze" numeric wire:model="{{ $praefix }}.traeger_plaetze" />
                    </x-fa::field>
                    <x-fa::field label="Nimmt Format" for="{{ $fid }}-tformat" class="w-36">
                        <x-fa::input id="{{ $fid }}-tformat" wire:model="{{ $praefix }}.traeger_format" placeholder="1/1" />
                    </x-fa::field>
                </div>
            @endif
        </div>
    </div>
</div>
