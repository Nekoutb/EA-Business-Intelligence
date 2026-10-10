@php $R = \App\Services\Clearance\Rules::class; @endphp
@if ($validation)
    <details class="clr-card" {{ ($validation['recon_oop']['ok'] && $validation['recon_advance']['ok']) ? '' : 'open' }}>
        <summary style="cursor:pointer; font-size:13px; font-weight:700;">
            Validation summary —
            {{ $validation['live_files'] }} live files · {{ $validation['settled_expenses'] }} settled disbursements ·
            {{ $validation['posted_invoices'] }} posted invoices in period ·
            @if ($validation['recon_oop']['ok'] && $validation['recon_advance']['ok'])
                <span class="clr-ok">reconciliations OK</span>
            @else
                <span class="clr-bad">reconciliation differences</span>
            @endif
        </summary>
        <div class="clr-tablewrap" style="margin-top:8px;">
            <table>
                <thead><tr><th>Check</th><th class="n">Report</th><th class="n">Ledger</th><th class="n">Difference</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ([['Unbilled disbursements vs out-of-pocket account (R-i)', $validation['recon_oop']],
                               ['Unjustified advances vs staff advance account (R-i)', $validation['recon_advance']]] as [$lbl, $rc])
                        <tr class="{{ $rc['ok'] ? 'green' : 'red' }}">
                            <td>{{ $lbl }}</td><td class="n">{{ $R::xaf($rc['report']) }}</td><td class="n">{{ $R::xaf($rc['ledger']) }}</td>
                            <td class="n">{{ $R::xaf($rc['difference']) }}</td>
                            <td>@if ($rc['ok'])<span class="clr-ok">OK</span>@else<span class="clr-bad">WARNING</span>@endif</td>
                        </tr>
                    @endforeach
                    @foreach ($validation['transit'] as $t)
                        <tr class="{{ $t['recon']['ok'] ? 'green' : 'red' }}">
                            <td>In transit vs holding account — {{ $t['bank'] }} (R-m)</td><td class="n">{{ $R::xaf(-$t['recon']['report']) }}</td>
                            <td class="n">{{ $R::xaf(-$t['recon']['ledger']) }}</td><td class="n">{{ $R::xaf($t['recon']['difference']) }}</td>
                            <td>@if ($t['recon']['ok'])<span class="clr-ok">OK</span>@else<span class="clr-bad">WARNING</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($validation['banks_without_holding'])
            <div class="clr-note">No holding account on: {{ implode(', ', $validation['banks_without_holding']) }} — these banks cannot be reconciled for payments in transit (R-m).</div>
        @endif
    </details>
@endif
