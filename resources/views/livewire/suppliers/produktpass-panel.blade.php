{{-- Reiter „Produktpass" am Lieferantenartikel: Datei hochladen, Versionen, gelesene Felder, Übernahme, Allergen-Bestätigung.
     Allergene werden NIE direkt geschrieben — erst die Bestätigung trägt sie ein; bis dahin gilt der bisherige Stand. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $statusText = ['hochgeladen' => 'wartet', 'liest' => 'wird gelesen', 'gelesen' => 'gelesen', 'fehler' => 'Fehler', 'verworfen' => 'verworfen'];
    $statusTon = ['gelesen' => 'ok', 'fehler' => 'crit', 'liest' => 'info', 'hochgeladen' => 'info'];
    $quelleText = ['produktpass' => 'Produktpass', 'websuche' => 'Websuche'];
    $wertText = function ($w, ?string $einheit = null) {
        if (is_float($w)) { $w = rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ','); }
        $t = Str::limit((string) $w, 140);
        return $einheit ? $t.' '.$einheit : $t;
    };
    $aktuellText = fn ($v) => $v === null ? '—' : (is_float($v) ? rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') : Str::limit((string) $v, 80));
    $allergenText = ['enthalten' => 'enthalten', 'spuren' => 'Spuren', 'nicht_enthalten' => 'nicht enthalten', 'unbekannt' => 'unbekannt'];
    $angezeigt = $stand['angezeigt'];
@endphp
<div class="flex flex-col gap-4" data-produktpass-panel @if($laeuft) wire:poll.4s @endif>
    @if($fehler)
        <x-fa::notice tone="crit" data-produktpass-fehler>{{ $fehler }}</x-fa::notice>
    @endif
    @if($hinweis)
        <x-fa::notice tone="ok" data-produktpass-hinweis>{{ $hinweis }}</x-fa::notice>
    @endif

    <x-fa::section title="Produktpass" icon="heroicon-o-document-text"
        description="PDF oder Foto des Produktpasses bzw. Datenblatts. Die KI liest Allergene, Zusatzstoffe, Nährwerte, Zutatenliste, Gebinde, Haltbarkeit, Herkunft und Marke. Was nicht im Dokument steht, bleibt leer.">
        @if($darfEdit)
            <div class="flex flex-wrap items-end gap-3" data-produktpass-upload>
                <input type="file" wire:model="datei" accept=".pdf,image/jpeg,image/png,image/webp" aria-label="Produktpass wählen"
                    class="fa-control h-9 text-[length:var(--fa-text-md)] min-w-0">
                <x-fa::button variant="primary" icon="heroicon-m-arrow-up-tray" wire:click="hochladen" wire:loading.attr="disabled"
                    wire:target="hochladen,datei" data-produktpass-hochladen>Hochladen und lesen</x-fa::button>
                @if($webQuelleDa)
                    <x-fa::button icon="heroicon-m-magnifying-glass" wire:click="recherchieren" wire:loading.attr="disabled" wire:target="recherchieren"
                        title="Ohne Produktpass im Web recherchieren — nur Vorschläge mit Quelle" data-produktpass-recherchieren>Im Web recherchieren</x-fa::button>
                @endif
            </div>
        @else
            <x-fa::notice tone="info" data-produktpass-nur-lesen>Dieser Artikel kommt aus einem übergeordneten Katalog. Den Produktpass ansehen und herunterladen kannst du, hochladen und übernehmen kann nur das Team, dem er gehört.</x-fa::notice>
        @endif

        @if($stand['versionen'] === [])
            <x-fa::empty compact icon="heroicon-o-document-text" title="Noch kein Produktpass" data-produktpass-leer>
                Lade den Produktpass hoch, dann stehen die gelesenen Werte hier zur Prüfung.
            </x-fa::empty>
        @else
            <table class="fa-table" data-produktpass-versionen>
                <thead><tr><th>Version</th><th>Datum</th><th>Quelle</th><th>Datei</th><th>Stand</th><th class="text-right">Aktionen</th></tr></thead>
                <tbody>
                @foreach($stand['versionen'] as $v)
                    <tr wire:key="pp-v-{{ $v['id'] }}" @class(['bg-[var(--fa-accent-soft)]' => ($angezeigt['id'] ?? null) === $v['id']]) data-produktpass-version="{{ $v['id'] }}">
                        <td class="tabular-nums">V{{ $v['version'] }}</td>
                        <td class="tabular-nums">{{ $v['datum'] }}</td>
                        <td>{{ $quelleText[$v['quelle']] ?? $v['quelle'] }}</td>
                        <td class="min-w-0">
                            @if($v['datei'] && ($downloadUrls[$v['id']] ?? null))
                                <a href="{{ $downloadUrls[$v['id']] }}" target="_blank" rel="noopener" class="underline text-[var(--fa-accent)] truncate" data-produktpass-download>{{ Str::limit($v['datei'], 36) }}</a>
                            @else — @endif
                        </td>
                        <td>
                            <x-fa::badge :tone="$statusTon[$v['status']] ?? 'neutral'">{{ $statusText[$v['status']] ?? $v['status'] }}</x-fa::badge>
                            @if($v['status'] === 'fehler' && $v['fehler'])<span class="{{ $leise }} block max-w-xs">{{ Str::limit($v['fehler'], 120) }}</span>@endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <x-fa::button size="sm" variant="ghost" wire:click="anzeigen({{ $v['id'] }})">Anzeigen</x-fa::button>
                            @if($darfEdit && $v['datei'] && in_array($v['status'], ['fehler', 'gelesen'], true))
                                <x-fa::button size="sm" variant="ghost" wire:click="erneutLesen({{ $v['id'] }})">Erneut lesen</x-fa::button>
                            @endif
                            @if($darfEdit)
                                <x-fa::button size="sm" variant="ghost" wire:click="verwerfen({{ $v['id'] }})" wire:confirm="Diese Version verwerfen? Bereits übernommene Werte bleiben stehen.">Verwerfen</x-fa::button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-fa::section>

    @if($angezeigt !== null && $angezeigt['status'] === 'gelesen')
        <x-fa::section title="Gelesene Werte" icon="heroicon-o-clipboard-document-check" meta="V{{ $angezeigt['version'] }} · {{ $quelleText[$angezeigt['quelle']] ?? $angezeigt['quelle'] }}"
            description="Ab {{ rtrim(rtrim(number_format($schwelle * 100, 0), '0'), ',') }} % Sicherheit trägt die KI Nährwerte, Zutatenliste, Zusatzstoffe und Haltbarkeit direkt ein, Gebinde nur in leere Felder. Alles andere ist ein Vorschlag — übernommen wird per Klick.">
            <x-slot:actions>
                @if($darfEdit && $angezeigt['quelle'] === 'produktpass')
                    <x-fa::button size="sm" wire:click="versionUebernehmen" title="Die direkten Felder dieser Version nach den Regeln übernehmen" data-produktpass-version-uebernehmen>Version übernehmen</x-fa::button>
                @endif
            </x-slot:actions>
            @if($stand['felder'] === [])
                <x-fa::empty compact icon="heroicon-o-question-mark-circle" title="Keine Felder erkannt" data-produktpass-felder-leer>
                    Im Dokument ließ sich nichts sicher ablesen. Schärfer fotografieren oder ein Datenblatt als PDF hochladen.
                </x-fa::empty>
            @else
                <table class="fa-table" data-produktpass-felder>
                    <thead><tr><th>Feld</th><th>Gelesen</th><th>Sicherheit</th><th>Bisher</th><th>Stand</th><th></th></tr></thead>
                    <tbody>
                    @foreach($stand['felder'] as $f)
                        <tr wire:key="pp-f-{{ $f['key'] }}" data-produktpass-feld="{{ $f['key'] }}">
                            <td>{{ $f['label'] }}</td>
                            <td class="max-w-sm">
                                {{ $wertText($f['wert'], $f['einheit']) }}
                                @if($f['url'])<a href="{{ $f['url'] }}" target="_blank" rel="noopener nofollow" class="block underline text-[var(--fa-accent)] {{ $leise }} truncate">{{ Str::limit($f['url'], 48) }}</a>@endif
                            </td>
                            <td><x-fa::badge :tone="$f['konfidenz'] >= $schwelle ? 'ok' : 'warn'" class="tabular-nums">{{ number_format($f['konfidenz'] * 100, 0) }} %</x-fa::badge></td>
                            <td class="{{ $leise }}">{{ $aktuellText($f['aktuell']) }}</td>
                            <td>
                                @if($f['status'] === 'uebernommen')
                                    <x-fa::badge tone="ok">übernommen</x-fa::badge>
                                @else
                                    <x-fa::badge tone="neutral">Vorschlag</x-fa::badge>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($darfEdit && $f['status'] !== 'uebernommen' && $f['uebernehmbar'])
                                    <x-fa::button size="sm" wire:click="feldUebernehmen('{{ $f['key'] }}')" data-produktpass-feld-uebernehmen>Übernehmen</x-fa::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(($angezeigt['verworfen_felder'] ?? []) !== [])
                <p class="{{ $leise }}" data-produktpass-verworfen>Nicht verwertbar und ausgelassen: {{ implode(' · ', array_slice($angezeigt['verworfen_felder'], 0, 6)) }}{{ count($angezeigt['verworfen_felder']) > 6 ? ' …' : '' }}</p>
            @endif
        </x-fa::section>

        @if($stand['allergene'] !== [] || $stand['allergene_details'] !== [])
            <x-fa::section title="Allergene aus dem Dokument" icon="heroicon-o-shield-exclamation" data-produktpass-allergene
                description="Ein Vorschlag. Erst deine Bestätigung trägt die Allergene ein — bis dahin gilt der bisherige Stand.">
                @if($angezeigt['allergene_status'] === 'vorschlag')
                    <x-fa::notice tone="warn" data-produktpass-allergene-offen>Noch nicht bestätigt. Der bisherige Allergenstand des Artikels bleibt unverändert.</x-fa::notice>
                @elseif($angezeigt['allergene_status'] === 'bestaetigt')
                    <x-fa::notice tone="ok" data-produktpass-allergene-bestaetigt>Bestätigt und am Artikel eingetragen.</x-fa::notice>
                @elseif($angezeigt['allergene_status'] === 'verworfen')
                    <x-fa::notice tone="info">Vorschlag verworfen, der bisherige Stand gilt.</x-fa::notice>
                @endif
                <table class="fa-table" data-produktpass-allergen-tabelle>
                    <thead><tr><th>Allergen</th><th>Laut Dokument</th><th>Sicherheit</th><th>Bisher am Artikel</th></tr></thead>
                    <tbody>
                    @foreach($stand['allergene'] as $a)
                        <tr wire:key="pp-a-{{ $a['key'] }}" data-produktpass-allergen="{{ $a['key'] }}">
                            <td>{{ $a['label'] }}</td>
                            <td><x-fa::badge :tone="$a['vorschlag'] === 'enthalten' ? 'crit' : ($a['vorschlag'] === 'spuren' ? 'warn' : 'neutral')">{{ $allergenText[$a['vorschlag']] ?? $a['vorschlag'] }}</x-fa::badge>
                                @if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener nofollow" class="block underline text-[var(--fa-accent)] {{ $leise }} truncate">{{ Str::limit($a['url'], 48) }}</a>@endif</td>
                            <td><x-fa::badge :tone="$a['konfidenz'] >= $schwelle ? 'ok' : 'warn'" class="tabular-nums">{{ number_format($a['konfidenz'] * 100, 0) }} %</x-fa::badge></td>
                            <td class="{{ $leise }}">{{ $allergenText[$a['aktuell']] ?? $a['aktuell'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if($stand['allergene_details'] !== [])
                    <p class="{{ $leise }}" data-produktpass-unterarten>Unterarten: {{ collect($stand['allergene_details'])->map(fn ($d, $k) => $k.' ('.($allergenText[$d['wert']] ?? $d['wert']).')')->implode(', ') }}</p>
                @endif
                @if($darfEdit && $angezeigt['allergene_status'] === 'vorschlag')
                    <div class="flex gap-2">
                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="allergeneBestaetigen"
                            wire:confirm="Die Allergene aus dem Dokument am Artikel eintragen? Das ist eine Kennzeichnung — bitte vorher gegen das Dokument prüfen."
                            data-produktpass-allergene-bestaetigen>Allergene bestätigen</x-fa::button>
                        <x-fa::button variant="ghost" wire:click="allergeneVerwerfen" data-produktpass-allergene-verwerfen>Vorschlag verwerfen</x-fa::button>
                    </div>
                @endif
            </x-fa::section>
        @endif
    @endif
</div>
