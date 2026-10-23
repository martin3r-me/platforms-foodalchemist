{{-- Spec 86 — Basisrezept im Ansatz (aufklappbar unter der Zutaten-Zeile). Unter-Basisrezepte stehen als Name, nicht erneut verschachtelt. --}}
<h4>{{ $b['name'] }}@if(!empty($b['ansatz_kg'])) <span class="meta">· Ansatz {{ $fmtMenge($b['ansatz_kg']) }} kg</span>@endif</h4>
@if(!empty($b['zeilen']))
    <table>
        @foreach($b['zeilen'] as $zi)
            <tr>
                <td>{{ $zi['name'] }}@if(!empty($zi['sub']) && isset($basis[$zi['sub']])) <span class="meta">(Basisrezept)</span>@endif</td>
                <td class="r">{{ $zi['menge'] !== null ? $fmtMenge($zi['menge']) . ' ' . $zi['einheit'] : '–' }}</td>
            </tr>
        @endforeach
    </table>
@endif
@foreach($b['schritte'] ?? [] as $i => $t)
    <div class="step"><div class="nr">{{ $i + 1 }}</div><div>{{ $t }}</div></div>
@endforeach
