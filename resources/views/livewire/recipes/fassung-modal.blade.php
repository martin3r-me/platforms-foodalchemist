{{-- Spec 82 §12 B / §13 B6 — Rezept veröffentlichen (nur Master): Stand, Abweichung, Allergen-Hinweis, Pflicht-Notiz. --}}
@php $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'; @endphp

<x-foodalchemist::modal name="fassung-modal" :title="$artLabel.' veröffentlichen'" :title-name="$rezept?->name" size="max-w-xl">
    <div class="flex flex-col gap-3" data-fassung-modal>
        @if($fehler)<x-fa::notice tone="crit" data-fassung-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-fassung-meldung>{{ $meldung }}</x-fa::notice>@endif

        @if($rezept === null || $status === null)
            <p class="{{ $leise }}">Veröffentlichen gibt es nur für {{ $artLabel === 'Rezept' ? 'Rezepte' : ($artLabel === 'Paket' ? 'Pakete' : ($artLabel === 'Konzept' ? 'Konzepte' : 'Formate')) }} des Master-Teams.</p>
        @else
            <div data-fassung-stand>
                @if($status['letzte'])
                    <span class="font-medium">Fassung {{ $status['letzte']['nr'] }}</span>
                    <span class="{{ $leise }}">veröffentlicht am {{ $status['letzte']['veroeffentlicht_at'] ?? '—' }}</span>
                    @if($status['letzte']['notiz'])<div class="{{ $leise }}">„{{ $status['letzte']['notiz'] }}“</div>@endif
                @else
                    <span class="font-medium">Noch nie veröffentlicht.</span>
                    <span class="{{ $leise }}">Abonnenten sehen {{ $art === 'recipe' ? 'das Rezept' : 'das '.$artLabel }} erst ab der ersten Fassung.</span>
                @endif
            </div>

            @if($status['letzte'] && ! $status['weicht_ab'])
                <x-fa::notice tone="info" data-fassung-gleich>Der Arbeitsstand entspricht der letzten Fassung. Veröffentlichen würde nichts ändern.</x-fa::notice>
            @elseif($status['letzte'])
                <x-fa::notice tone="warn" data-fassung-weicht-ab>Der Arbeitsstand weicht von Fassung {{ $status['letzte']['nr'] }} ab.{{ $status['neu_im_baum'] !== [] ? ' Neu würden: '.implode(', ', $status['neu_im_baum']).'.' : '' }}</x-fa::notice>
            @endif

            @if($status['allergen_aenderung'])
                <x-fa::notice tone="crit" data-fassung-allergen>Die Allergene oder Zusatzstoffe ändern sich gegenüber Fassung {{ $status['letzte']['nr'] }}. Abonnenten bekommen einen Hinweis und müssen ihre Kennzeichnung prüfen.</x-fa::notice>
            @endif

            @if($status['blockiert'])
                <x-fa::notice tone="warn" data-fassung-blockiert>{{ $status['blockiert'] }}</x-fa::notice>
            @endif
            @foreach($status['hinweise'] as $h)
                <x-fa::notice tone="info" data-fassung-hinweis wire:key="fassung-hinweis-{{ $loop->index }}">{{ $h }}.</x-fa::notice>
            @endforeach

            @if($status['stamm_verstoesse'] !== [])
                <x-fa::notice tone="warn" data-fassung-stamm>Noch nicht veröffentlichbar: {{ count($status['stamm_verstoesse']) === 1 ? 'Dieses GP gehört' : 'Diese GPs gehören' }} dem Master-Team, nicht dem globalen Stamm: {{ implode(', ', array_slice($status['stamm_verstoesse'], 0, 10)) }}{{ count($status['stamm_verstoesse']) > 10 ? ' …' : '' }}. Das ist kein Fehler im Rezept. Veröffentlichen ist nach dem Stamm-Umzug möglich.</x-fa::notice>
            @endif

            @if(! $darf)
                <x-fa::notice tone="info" data-fassung-nur-lesen>Veröffentlichen darf nur die Rolle „Freigeben“.</x-fa::notice>
            @else
                <x-fa::field label="Änderungsnotiz" for="fassung-notiz" hint="Pflicht. Abonnenten lesen sie im Änderungsprotokoll." required>
                    <x-fa::textarea id="fassung-notiz" rows="3" wire:model.live="notiz" placeholder="Was hat sich geändert?" data-fassung-notiz />
                </x-fa::field>
            @endif
        @endif
    </div>

    <x-slot:footer>
        <x-fa::button variant="ghost" wire:click="$dispatch('modal.close', { name: 'fassung-modal' })">Schließen</x-fa::button>
        @if($status && $darf)
            <x-fa::button variant="primary" icon="heroicon-m-paper-airplane" wire:click="veroeffentlichen"
                :disabled="trim($notiz) === '' || $status['stamm_verstoesse'] !== [] || $status['blockiert'] !== null || ($status['letzte'] && ! $status['weicht_ab'])" data-fassung-veroeffentlichen>Veröffentlichen</x-fa::button>
        @endif
    </x-slot:footer>
</x-foodalchemist::modal>
