{{-- Gericht-Baum-Picker (geteilt: Konzept-Position + Paket schnüren) — VK-Hauptgruppe → Klasse
     → Geschmack → Ernährungsform, wie der VK-Browser. Erwartet: $sucheModel (wire-Model-Name der Suche),
     $pickHauptgruppen, $pickHgCounts, $pickKlassen, $pickKlassenCounts. pickHg/pickKlasse/pickGeschmack/
     pickDiaet erbt der Include aus dem Editor-Scope. fa-pass: nur Tokens + x-fa-Bausteine. --}}
@php
    $baumChip = 'inline-flex items-center gap-1 h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] font-medium transition-colors';
    $baumAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]';
    $baumAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';
    $baumLabel = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
@endphp
<div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-2" data-gericht-baum>
    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="{{ $sucheModel }}" placeholder="Gericht suchen oder unten nach Gruppe blättern …" aria-label="Gericht suchen" />

    {{-- VK-Hauptgruppen --}}
    <div class="flex flex-wrap gap-1">
        @foreach($pickHauptgruppen as $hg)
            <button type="button" wire:key="baum-hg-{{ $hg->id }}" wire:click="pickHgWaehle({{ $hg->id }})" title="{{ $hg->label }}" aria-pressed="{{ $pickHg === $hg->id ? 'true' : 'false' }}"
                    class="{{ $baumChip }} {{ $pickHg === $hg->id ? $baumAn : $baumAus }}">
                <span>{{ $hg->code ?: $hg->label }}</span>
                @if(($pickHgCounts[$hg->id] ?? 0) > 0)<span class="font-normal text-[var(--fa-ink-3)] tabular-nums">{{ $pickHgCounts[$hg->id] }}</span>@endif
            </button>
        @endforeach
    </div>

    {{-- Klassen-Kaskade (nur wenn Hauptgruppe gewählt) --}}
    @if($pickHg !== null && $pickKlassen->isNotEmpty())
        <div class="flex flex-wrap gap-1 pl-2 border-l-2 border-[var(--fa-accent-line)]">
            @foreach($pickKlassen as $kl)
                <button type="button" wire:key="baum-kl-{{ $kl->id }}" wire:click="pickKlasseWaehle({{ $kl->id }})" aria-pressed="{{ $pickKlasse === $kl->id ? 'true' : 'false' }}"
                        class="{{ $baumChip }} {{ $pickKlasse === $kl->id ? $baumAn : $baumAus }}">
                    {{ $kl->label }}
                    @if(($pickKlassenCounts[$kl->id] ?? 0) > 0)<span class="font-normal text-[var(--fa-ink-3)] tabular-nums">{{ $pickKlassenCounts[$kl->id] }}</span>@endif
                </button>
            @endforeach
        </div>
    @endif

    {{-- Geschmack --}}
    <div class="flex flex-wrap items-center gap-1">
        <span class="{{ $baumLabel }} mr-1">Geschmack</span>
        @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $v => $l)
            <button type="button" wire:click="pickGeschmackWaehle('{{ $v }}')" aria-pressed="{{ $pickGeschmack === $v ? 'true' : 'false' }}"
                    class="{{ $baumChip }} {{ $pickGeschmack === $v ? $baumAn : $baumAus }}">{{ $l }}</button>
        @endforeach
    </div>

    {{-- R4.2: Ernährungsform (kanonische diet_form-Achse) — auch Ziel des Klicks auf eine Lücke im Soll-Ist-Abgleich --}}
    <div class="flex flex-wrap items-center gap-1" data-pick-diaet>
        <span class="{{ $baumLabel }} mr-1">Ernährung</span>
        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::DIET_FORMS as $v)
            <button type="button" wire:click="pickDiaetWaehle('{{ $v }}')" aria-pressed="{{ $pickDiaet === $v ? 'true' : 'false' }}"
                    class="{{ $baumChip }} {{ $pickDiaet === $v ? $baumAn : $baumAus }}">{{ $v }}</button>
        @endforeach
    </div>
</div>
