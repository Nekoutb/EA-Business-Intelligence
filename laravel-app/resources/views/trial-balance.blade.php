@extends('layouts.app')
@section('title', 'Trial Balance')

@section('page-title', 'Trial Balance · Balance Générale')
@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not fetch trial balance:</strong> {{ $error }}
        </div>
    @endif

    @if (!$data || empty($data['rows']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">No accounts with activity</div>
            <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No posted account.move.line records found for the selected period — try a different month or year.</p>
        </div>
    @else
      @php
          $rows         = $data['rows'];
          $totals       = $data['totals'];
          $classTotals  = $data['class_totals'];
          $balanceCheck = $data['balance_check'];
          $ccy          = $data['currency'] ?? 'XAF';

          // Number formatter — exact XAF, thousands separator, negatives
          // never appear because OHADA splits into Dr/Cr columns. A zero
          // shows as "—" so empty cells aren't visually heavy.
          $fmt = function ($v) {
              if ($v === null) return '—';
              $v = (float) $v;
              if (abs($v) < 0.5) return '—';
              return number_format($v, 0);
          };

          // SYSCOHADA class labels
          $classLabels = [
              1 => 'Class 1 — Capital, reserves, debt',
              2 => 'Class 2 — Fixed assets',
              3 => 'Class 3 — Inventory & WIP',
              4 => 'Class 4 — Tiers (counterparties)',
              5 => 'Class 5 — Cash & banks',
              6 => 'Class 6 — Operating expenses',
              7 => 'Class 7 — Operating revenues',
              8 => 'Class 8 — HAO / income tax',
              9 => 'Class 9 — Analytical',
          ];
      @endphp

      <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">

          {{-- Top legend strip --}}
          <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5] flex-wrap">
              <div class="text-[10.5px] text-[#7c7466]">
                  <strong class="text-[#1e3d44]">{{ count($rows) }}</strong> accounts with activity ·
                  Period: <strong class="text-[#1e3d44]">{{ $data['period_start'] }}</strong> to <strong class="text-[#1e3d44]">{{ $data['period_end'] }}</strong> ·
                  Figures in <strong>{{ $ccy }}</strong> (full precision)
              </div>
              <div class="text-[10.5px] flex items-center gap-3 flex-wrap">
                  <span class="inline-flex items-center gap-1.5">
                      Balance check:
                      @if ($periodType !== 'month')
                          <span class="font-semibold {{ $balanceCheck['opening'] ? 'text-sage-700' : 'text-coral-700' }}">
                              Opening {{ $balanceCheck['opening'] ? '✓' : '✗' }}
                          </span>
                      @endif
                      <span class="font-semibold {{ $balanceCheck['movement'] ? 'text-sage-700' : 'text-coral-700' }}">
                          Movement {{ $balanceCheck['movement'] ? '✓' : '✗' }}
                      </span>
                      @if ($periodType !== 'month')
                          <span class="font-semibold {{ $balanceCheck['closing'] ? 'text-sage-700' : 'text-coral-700' }}">
                              Closing {{ $balanceCheck['closing'] ? '✓' : '✗' }}
                          </span>
                      @endif
                  </span>
              </div>
          </div>

          @php $monthOnly = ($periodType === 'month'); @endphp
          <div class="overflow-x-auto">
              <table class="w-full text-[11.5px]" style="min-width: {{ $monthOnly ? '700px' : '1200px' }};">
                  <thead class="bg-white">
                      {{-- Group header row --}}
                      <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                          <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10" colspan="2">
                              <span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">Account</span>
                          </th>
                          @if (!$monthOnly)
                              <th class="py-2 px-2 text-center text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold border-l border-[#e8e4de]" colspan="2">Opening Balance · Solde de début</th>
                          @endif
                          <th class="py-2 px-2 text-center text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold border-l border-[#e8e4de]" colspan="2">Movements · Mouvements</th>
                          @if (!$monthOnly)
                              <th class="py-2 px-2 text-center text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold border-l border-[#e8e4de]" colspan="2">Closing Balance · Solde de fin</th>
                          @endif
                      </tr>
                      {{-- Sub-header row --}}
                      <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                          <th class="text-left py-1.5 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10 text-[10px] font-semibold text-[#5a6a70]" style="width:6rem;">N° Compte</th>
                          <th class="text-left py-1.5 px-2 sticky left-[6rem] bg-[#fcfbf8] z-10 text-[10px] font-semibold text-[#5a6a70]" style="width:18rem;">Intitulé</th>
                          @if (!$monthOnly)
                              <th class="py-1.5 px-2 text-right text-[10px] font-bold text-[#5a6a70] border-l border-[#e8e4de]" style="width:8rem;">Débiteur</th>
                              <th class="py-1.5 px-2 text-right text-[10px] font-bold text-[#5a6a70]" style="width:8rem;">Créditeur</th>
                          @endif
                          <th class="py-1.5 px-2 text-right text-[10px] font-bold text-[#5a6a70] border-l border-[#e8e4de]" style="width:8rem;">Débit</th>
                          <th class="py-1.5 px-2 text-right text-[10px] font-bold text-[#5a6a70]" style="width:8rem;">Crédit</th>
                          @if (!$monthOnly)
                              <th class="py-1.5 px-2 text-right text-[10px] font-bold text-[#5a6a70] border-l border-[#e8e4de]" style="width:8rem;">Débiteur</th>
                              <th class="py-1.5 px-2 text-right text-[10px] font-bold text-[#5a6a70]" style="width:8rem;">Créditeur</th>
                          @endif
                      </tr>
                  </thead>

                  <tbody class="divide-y divide-[#f1ede5]">
                      @foreach ($rows as $row)
                          {{-- In month-only mode, suppress dormant accounts whose movements are both zero --}}
                          @if ($monthOnly && $row['movement_dr'] <= 0.01 && $row['movement_cr'] <= 0.01) @continue @endif
                          <tr class="hover:bg-[#faf8f5] transition-colors">
                              <td class="text-left pl-4 pr-2 py-1 sticky left-0 bg-white z-10 font-mono text-[11px] text-[#1e3d44]" style="width:6rem;">{{ $row['code'] }}</td>
                              <td class="text-left px-2 py-1 sticky bg-white z-10 text-[11px] text-[#2d3748] truncate" style="width:18rem;left:6rem;" title="{{ $row['name'] }}">{{ $row['name'] }}</td>
                              @if (!$monthOnly)
                                  <td class="text-right tabular-nums px-2 py-1 border-l border-[#e8e4de] {{ $row['opening_dr'] > 0 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($row['opening_dr']) }}</td>
                                  <td class="text-right tabular-nums px-2 py-1 {{ $row['opening_cr'] > 0 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($row['opening_cr']) }}</td>
                              @endif
                              <td class="text-right tabular-nums px-2 py-1 border-l border-[#e8e4de] {{ $row['movement_dr'] > 0 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($row['movement_dr']) }}</td>
                              <td class="text-right tabular-nums px-2 py-1 {{ $row['movement_cr'] > 0 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($row['movement_cr']) }}</td>
                              @if (!$monthOnly)
                                  <td class="text-right tabular-nums px-2 py-1 border-l border-[#e8e4de] {{ $row['closing_dr'] > 0 ? 'text-[#2d3748] font-semibold' : 'text-[#a09d95]' }}">{{ $fmt($row['closing_dr']) }}</td>
                                  <td class="text-right tabular-nums px-2 py-1 {{ $row['closing_cr'] > 0 ? 'text-[#2d3748] font-semibold' : 'text-[#a09d95]' }}">{{ $fmt($row['closing_cr']) }}</td>
                              @endif
                          </tr>
                      @endforeach
                  </tbody>

                  <tfoot>
                      <tr class="bg-[#1e3d44] text-white border-t-2 border-[#1e3d44]">
                          <td class="py-2 pl-4 pr-2 sticky left-0 bg-[#1e3d44] z-10 font-bold text-[11px]" colspan="2">TOTAL · {{ count($rows) }} accounts</td>
                          @if (!$monthOnly)
                              <td class="text-right tabular-nums px-2 py-2 border-l border-[#3d5e64] font-bold">{{ $fmt($totals['opening_dr']) }}</td>
                              <td class="text-right tabular-nums px-2 py-2 font-bold">{{ $fmt($totals['opening_cr']) }}</td>
                          @endif
                          <td class="text-right tabular-nums px-2 py-2 border-l border-[#3d5e64] font-bold">{{ $fmt($totals['movement_dr']) }}</td>
                          <td class="text-right tabular-nums px-2 py-2 font-bold">{{ $fmt($totals['movement_cr']) }}</td>
                          @if (!$monthOnly)
                              <td class="text-right tabular-nums px-2 py-2 border-l border-[#3d5e64] font-bold">{{ $fmt($totals['closing_dr']) }}</td>
                              <td class="text-right tabular-nums px-2 py-2 font-bold">{{ $fmt($totals['closing_cr']) }}</td>
                          @endif
                      </tr>
                  </tfoot>
              </table>
          </div>

          <div class="px-4 py-2.5 border-t border-[#e8e4de] bg-[#faf8f5] text-[10.5px] text-[#7c7466] leading-relaxed">
              Opening balance = Σ(débit − crédit) on posted lines BEFORE {{ $data['period_start'] }} ·
              Movements = Σ debit and Σ credit during the selected period ·
              Closing balance = Opening ± Movements, split into Débiteur / Créditeur per OHADA convention.
              The trial balance is in equilibrium when Σ Débit = Σ Crédit on each of the three groups (Opening / Movements / Closing).
          </div>
      </div>

    @endif
@endsection
