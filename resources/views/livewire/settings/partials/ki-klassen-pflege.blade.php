{{-- Spec 85 (Dominique 19.10.): KI-Klasse je Arbeitsschritt einstellen — nur Admin im Master-Team, gilt für alle Teams.
     „Standard“ = die Klasse aus dem Code; jede Änderung landet im Aktivitätsprotokoll. --}}
@php use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung as WS; use Platform\FoodAlchemist\Support\KiKlassen; @endphp
<div class="flex flex-col divide-y divide-[var(--fa-line)]" data-ki-klassen-pflege>
    @foreach($klassenStandard->sortBy(fn ($kl, $key) => WS::schrittLabel($key)) as $key => $standard)
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-1.5" wire:key="kk-{{ $key }}" data-kk-zeile="{{ $key }}">
            <span class="min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ WS::schrittLabel($key) }}
                <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $key }}</span></span>
            <select class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-2 py-1 text-[length:var(--fa-text-sm)]"
                    aria-label="KI-Klasse für {{ WS::schrittLabel($key) }}" data-kk-select="{{ $key }}"
                    wire:change="klasseSetzen(@js($key), $event.target.value)">
                <option value="" @selected(! isset($klassenEingestellt[$key]))>Standard (Code: {{ KiKlassen::label($standard) }})</option>
                @foreach(KiKlassen::ALLE as $kl)
                    <option value="{{ $kl }}" @selected(($klassenEingestellt[$key] ?? null) === $kl)>{{ KiKlassen::label($kl) }}</option>
                @endforeach
            </select>
        </div>
    @endforeach
</div>
