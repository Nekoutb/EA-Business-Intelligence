<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>I/TVA-IR · Déclaration mensuelle {{ $analysis['period']['mois_fr'] ?? '' }} {{ $analysis['period']['year'] ?? '' }}</title>
    <style>
        /* Print-optimised replica of the I/TVA-IR form. */
        @page { size: A4; margin: 12mm 10mm 12mm 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #f3f4f6; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10.5px; color: #111827; line-height: 1.35; }
        .page {
            background: #fff; max-width: 210mm; margin: 12px auto;
            padding: 14mm 12mm; box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        .toolbar {
            position: sticky; top: 0; z-index: 10;
            background: #111827; color: #fff;
            padding: 10px 16px; display: flex; gap: 12px;
            align-items: center; justify-content: center;
        }
        .toolbar .btn {
            background: #fff; color: #111827; padding: 6px 12px;
            border-radius: 4px; font-size: 12px; font-weight: 600;
            border: 0; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .toolbar .btn:hover { background: #e5e7eb; }
        .toolbar .meta { font-size: 11px; color: #d1d5db; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { box-shadow: none; margin: 0; padding: 0; max-width: none; }
        }

        /* Header: bilingual tri-column */
        .form-head {
            display: grid; grid-template-columns: 1fr auto 1fr; gap: 8px;
            align-items: start; padding-bottom: 8px;
            border-bottom: 1.5px solid #111827; margin-bottom: 10px;
        }
        .form-head .col { text-align: center; font-size: 9.5px; line-height: 1.4; }
        .form-head .col strong { display: block; font-size: 11px; font-weight: 700; }
        .form-head .stamp {
            border: 1.5px solid #111827; padding: 6px 10px; font-weight: 700;
            font-size: 11px; letter-spacing: 0.06em; align-self: center;
            text-align: center; min-width: 70px;
        }
        .form-head .col .sub { display: block; font-size: 9px; }
        .form-head .col .rule { width: 24px; height: 1px; background: #111827; margin: 2px auto; }

        h1.title {
            text-align: center; font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.04em; margin: 8px 0 12px;
        }

        /* Identification block */
        .ident { font-size: 10px; }
        .ident .row { display: flex; gap: 14px; padding: 3px 0; align-items: baseline; }
        .ident .row + .row { border-top: 1px dotted #d1d5db; }
        .ident .label { font-weight: 600; color: #111827; }
        .ident .val { color: #111827; min-width: 90px; }
        .ident .val.fill { flex: 1; border-bottom: 1px dotted #6b7280; padding: 0 4px; }
        .niu-grid { display: grid; grid-template-columns: repeat(14, 1fr); gap: 2px; margin-top: 4px; }
        .niu-grid span {
            border: 1px solid #111827; padding: 4px 0; text-align: center;
            font-family: 'Courier New', monospace; font-weight: 700; font-size: 11px;
        }

        .regime-box { display: inline-flex; gap: 14px; align-items: center; }
        .check { display: inline-flex; align-items: center; gap: 4px; }
        .check .box {
            width: 12px; height: 12px; border: 1px solid #111827;
            display: inline-block; text-align: center; line-height: 11px; font-weight: 700;
        }
        .check.on .box { background: #111827; color: #fff; }

        /* Section block */
        .section { margin-top: 10px; page-break-inside: avoid; }
        .section .head {
            background: #111827; color: #fff; padding: 4px 8px;
            font-size: 10px; font-weight: 700; letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        table {
            width: 100%; border-collapse: collapse; font-size: 9.5px;
        }
        table th, table td {
            border: 0.6px solid #6b7280; padding: 3px 5px; vertical-align: top;
        }
        table thead th {
            background: #f3f4f6; font-weight: 700; text-transform: uppercase;
            font-size: 8.5px; letter-spacing: 0.04em; color: #111827;
        }
        table td.code { font-family: 'Courier New', monospace; font-weight: 700; width: 40px; }
        table td.amount { text-align: right; font-family: 'Courier New', monospace; font-variant-numeric: tabular-nums; }
        table td.center { text-align: center; }
        table tr.total td { background: #f3f4f6; font-weight: 700; }

        /* Recap block */
        .recap th, .recap td { padding: 4px 6px; font-size: 10px; }
        .recap tfoot td { background: #111827; color: #fff; font-weight: 700; }

        /* Footer (declarant signature + admin block) */
        .foot {
            margin-top: 18px; display: grid;
            grid-template-columns: 1fr 1fr; gap: 14px;
        }
        .foot .box {
            border: 1px solid #111827; padding: 8px; min-height: 80px;
            font-size: 9.5px;
        }
        .foot .box .title-row {
            font-weight: 700; text-transform: uppercase; font-size: 9px;
            letter-spacing: 0.04em; margin-bottom: 6px;
            border-bottom: 1px solid #6b7280; padding-bottom: 4px;
        }
        .foot .sign-line { border-bottom: 1px dotted #6b7280; height: 14px; margin: 6px 0; }
        .pay-modes { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 6px; }
    </style>
</head>
<body>

<div class="toolbar">
    <span class="meta">I/TVA-IR · {{ $analysis['period']['mois_fr'] ?? '' }} {{ $analysis['period']['year'] ?? '' }}</span>
    <button class="btn" onclick="window.print()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/></svg>
        Print / Save as PDF
    </button>
    <a class="btn" href="{{ tenant_url('/monthly-tax-return') }}" style="background:transparent;color:#fff;border:1px solid #6b7280;">← Back to dashboard</a>
</div>

<div class="page">

    @if ($error)
        <div style="border:1px solid #b91c1c; background:#fef2f2; color:#7f1d1d; padding:10px; border-radius:4px;">
            <strong>Could not load data:</strong> {{ $error }}
        </div>
    @elseif ($analysis)
        @php
            $cur       = $analysis['currency'] ?? 'XAF';
            $period    = $analysis['period']   ?? [];
            $declarant = $analysis['declarant'] ?? [];
            $sections  = $analysis['sections'] ?? [];
            $recap     = $analysis['recap']    ?? ['rows'=>[], 'total'=>0];
            $fmt = fn ($n) => number_format((float) $n, 0, '.', ' ');
            $niu = preg_replace('/\s+/', '', (string) ($declarant['niu'] ?? ''));
            $niuChars = str_pad($niu, 14)        ;
        @endphp

        {{-- ─────── Bilingual form head ─────── --}}
        <div class="form-head">
            <div class="col">
                <strong>RÉPUBLIQUE DU CAMEROUN</strong>
                <span class="sub">Paix – Travail – Patrie</span>
                <div class="rule"></div>
                <strong>MINISTÈRE DES FINANCES</strong>
                <div class="rule"></div>
                <strong>DIRECTION GÉNÉRALE DES IMPÔTS</strong>
            </div>
            <div class="stamp">I/TVA-IR</div>
            <div class="col">
                <strong>REPUBLIC OF CAMEROON</strong>
                <span class="sub">Peace – Work – Fatherland</span>
                <div class="rule"></div>
                <strong>MINISTRY OF FINANCE</strong>
                <div class="rule"></div>
                <strong>DIRECTORATE GENERAL OF TAXATION</strong>
                <div style="margin-top:4px;font-size:9px;">www.impots.cm · N° vert : 8200</div>
            </div>
        </div>

        <h1 class="title">Déclaration des taxes sur le chiffre d'affaires et des impôts sur le revenu</h1>

        {{-- ─────── Identification block ─────── --}}
        <div class="ident">
            <div class="row">
                <span class="label">D.G.E. / C.R.I. :</span>
                <span class="val fill">{{ $declarant['dge_cri'] ?: '' }}</span>
                <span class="label" style="margin-left:14px;">CDI / CSI / CIME :</span>
                <span class="val fill">{{ $declarant['cdi_csi_cime'] ?: '' }}</span>
            </div>
            <div class="row">
                <span class="label">Régime d'imposition :</span>
                <span class="regime-box">
                    <span class="check {{ ($declarant['regime'] ?? '') === 'reel' ? 'on' : '' }}">
                        <span class="box">{{ ($declarant['regime'] ?? '') === 'reel' ? '✓' : '' }}</span> Réel
                    </span>
                    <span class="check {{ ($declarant['regime'] ?? '') === 'simplifie' ? 'on' : '' }}">
                        <span class="box">{{ ($declarant['regime'] ?? '') === 'simplifie' ? '✓' : '' }}</span> Simplifié
                    </span>
                </span>
                <span class="label" style="margin-left:auto;">Exercice fiscal :</span>
                <span class="val">{{ $declarant['exercice'] ?? '' }}</span>
                <span class="label" style="margin-left:14px;">Mois :</span>
                <span class="val">{{ $declarant['mois_fr'] ?? '' }}</span>
            </div>
            <div class="row" style="display:block;">
                <div class="label" style="margin-bottom:2px;">Numéro Identifiant Unique (NIU) :</div>
                <div class="niu-grid">
                    @for ($i = 0; $i < 14; $i++)
                        <span>{{ $niuChars[$i] !== ' ' ? $niuChars[$i] : '' }}</span>
                    @endfor
                </div>
            </div>
            <div class="row">
                <span class="label">Nom ou Raison sociale :</span>
                <span class="val fill">{{ $declarant['raison_sociale'] ?: '' }}</span>
                <span class="label" style="margin-left:10px;">Sigle :</span>
                <span class="val fill">{{ $declarant['sigle'] ?: '' }}</span>
            </div>
            <div class="row">
                <span class="label">Ville :</span>
                <span class="val fill">{{ $declarant['ville'] ?: '' }}</span>
                <span class="label">Commune :</span>
                <span class="val fill">{{ $declarant['commune'] ?: '' }}</span>
                <span class="label">Quartier :</span>
                <span class="val fill">{{ $declarant['quartier'] ?: '' }}</span>
                <span class="label">Lieu-dit :</span>
                <span class="val fill">{{ $declarant['lieu_dit'] ?: '' }}</span>
            </div>
            <div class="row">
                <span class="label">B.P. :</span>
                <span class="val fill">{{ $declarant['bp'] ?: '' }}</span>
                <span class="label">Tél. fixe :</span>
                <span class="val fill">{{ $declarant['tel_fixe'] ?: '' }}</span>
                <span class="label">Tél. mobile :</span>
                <span class="val fill">{{ $declarant['tel_mobile'] ?: '' }}</span>
            </div>
            <div class="row">
                <span class="label">Adresse électronique :</span>
                <span class="val fill">{{ $declarant['email'] ?: '' }}</span>
                <span class="label">FAX :</span>
                <span class="val fill">{{ $declarant['fax'] ?: '' }}</span>
            </div>
        </div>

        {{-- ─────── 14 sections — column layout matches the form per-section ─────── --}}
        @foreach ($sections as $sec)
            @php
                $sCode   = (string) ($sec['code']  ?? '');
                $sTitle  = (string) ($sec['title'] ?? '');
                $sLayout = (string) ($sec['layout'] ?? 'full');
                $lines   = $sec['lines'] ?? [];
            @endphp
            <div class="section">
                <div class="head">§{{ $sCode }} — {{ $sTitle }}</div>

                @if ($sLayout === 'ca')
                    {{-- Form §2: Code · Libellé · CA · Taux · Montant de la taxe --}}
                    <table>
                        <thead>
                            <tr>
                                <th style="width:36px;">Code</th>
                                <th>Libellé</th>
                                <th style="width:90px;">CA</th>
                                <th style="width:54px;">Taux</th>
                                <th style="width:110px;">Montant de la taxe</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $ln)
                                @php $isTotal = str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL') || (string) ($ln['code'] ?? '') === 'L15'; @endphp
                                <tr class="{{ $isTotal ? 'total' : '' }}">
                                    <td class="code">{{ $ln['code'] }}</td>
                                    <td>{{ $ln['label'] }}</td>
                                    <td class="amount">{{ $ln['base'] > 0 ? $fmt($ln['base']) : '' }}</td>
                                    <td class="center">{{ $ln['rate'] ?: '' }}</td>
                                    <td class="amount">{{ $ln['total'] > 0 ? $fmt($ln['total']) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif ($sLayout === 'tax_due')
                    {{-- Form §6: Code · Libellé · Principal · CAC · Pénalités · Total — no Base/Taux --}}
                    <table>
                        <thead>
                            <tr>
                                <th style="width:36px;">Code</th>
                                <th>Libellé</th>
                                <th style="width:90px;">Principal</th>
                                <th style="width:70px;">CAC</th>
                                <th style="width:78px;">Pénalités</th>
                                <th style="width:90px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $ln)
                                @php
                                    $code = (string) ($ln['code'] ?? '');
                                    $isTotal = $code === 'L39' || str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL') || str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'MONTANT');
                                @endphp
                                <tr class="{{ $isTotal ? 'total' : '' }}">
                                    <td class="code">{{ $code }}</td>
                                    <td>{{ $ln['label'] }}</td>
                                    <td class="amount">{{ $ln['principal'] != 0 ? $fmt($ln['principal']) : '' }}</td>
                                    <td class="amount">{{ $ln['cac'] != 0 ? $fmt($ln['cac']) : '' }}</td>
                                    <td class="amount">{{ $ln['penalites'] != 0 ? $fmt($ln['penalites']) : '' }}</td>
                                    <td class="amount">{{ $ln['total'] != 0 ? $fmt($ln['total']) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif ($sLayout === 'amount')
                    {{-- §3 / §5: Code · Libellé · Montant — single amount column.
                         Lines with a 'unit' (e.g. '%') get the unit suffix. --}}
                    <table>
                        <thead>
                            <tr>
                                <th style="width:36px;">Code</th>
                                <th>Libellé</th>
                                <th style="width:130px;">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $ln)
                                @php
                                    $code = (string) ($ln['code'] ?? '');
                                    $isTotal = in_array($code, ['L22', 'L32', 'L36', 'L39', 'L44', 'L49', 'L62', 'L66', 'L73', 'L77', 'L80'], true)
                                               || str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL');
                                    $unit    = (string) ($ln['unit'] ?? '');
                                    $value   = (float)  ($ln['principal'] ?? 0);
                                    $display = $unit === '%'
                                        ? number_format($value, 2, ',', ' ') . ' %'
                                        : ($value != 0 ? $fmt($value) : '');
                                @endphp
                                <tr class="{{ $isTotal ? 'total' : '' }}">
                                    <td class="code">{{ $code }}</td>
                                    <td>{{ $ln['label'] }}</td>
                                    <td class="amount">{{ $display }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    {{-- Default: Code · Libellé · Base · Taux · Principal · CAC · Pénalités · Total --}}
                    <table>
                        <thead>
                            <tr>
                                <th style="width:36px;">Code</th>
                                <th>Libellé</th>
                                <th style="width:70px;">Base</th>
                                <th style="width:48px;">Taux</th>
                                <th style="width:74px;">Principal</th>
                                <th style="width:54px;">CAC</th>
                                <th style="width:60px;">Pénalités</th>
                                <th style="width:74px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $ln)
                                @php $isTotal = str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL'); @endphp
                                <tr class="{{ $isTotal ? 'total' : '' }}">
                                    <td class="code">{{ $ln['code'] }}</td>
                                    <td>{{ $ln['label'] }}</td>
                                    <td class="amount">{{ $ln['base'] > 0 ? $fmt($ln['base']) : '' }}</td>
                                    <td class="center">{{ $ln['rate'] ?: '' }}</td>
                                    <td class="amount">{{ $ln['principal'] != 0 ? $fmt($ln['principal']) : '' }}</td>
                                    <td class="amount">{{ $ln['cac'] > 0 ? $fmt($ln['cac']) : '' }}</td>
                                    <td class="amount">{{ $ln['penalites'] > 0 ? $fmt($ln['penalites']) : '' }}</td>
                                    <td class="amount">{{ $ln['total'] != 0 ? $fmt($ln['total']) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach

        {{-- ─────── Recap ─────── --}}
        <div class="section">
            <div class="head">TOTAL RÉCAPITULATIF</div>
            <table class="recap">
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th style="width:130px;">Total ({{ $cur }})</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recap['rows'] as $r)
                        <tr>
                            <td>{{ $r['label'] }}</td>
                            <td class="amount">{{ $fmt($r['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>TOTAL À PAYER</td>
                        <td class="amount">{{ $fmt($recap['total']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- ─────── Footer (declarant + admin) ─────── --}}
        <div class="foot">
            <div class="box">
                <div class="title-row">Le déclarant</div>
                <div>À ____________________________ Le ____________________________</div>
                <div style="margin-top:10px;">Cachet et signature :</div>
                <div class="sign-line"></div>
                <div class="sign-line"></div>
            </div>
            <div class="box">
                <div class="title-row">Cadre réservé à l'administration</div>
                <div>N° de quittance : ______________________________________</div>
                <div>Délivrée le : __________________________________________</div>
                <div>Pénalités : ____________________________________________</div>
                <div style="margin-top:6px;">Le Receveur des Impôts :</div>
                <div class="sign-line"></div>
                <div class="title-row" style="margin-top:8px;">Mode de paiement</div>
                <div class="pay-modes">
                    <span class="check"><span class="box"></span> Espèces</span>
                    <span class="check"><span class="box"></span> Virement</span>
                    <span class="check"><span class="box"></span> Chèque n° __________</span>
                </div>
                <div style="margin-top:4px;">Banque : ______________________________________</div>
                <div>Agence : ______________________________________</div>
            </div>
        </div>

    @endif

</div>

</body>
</html>
