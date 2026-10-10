<?php

namespace App\Services\Clearance\Reports;

use App\Services\Clearance\Rules;

/** R1 — Executive cockpit: KPIs vs previous period, two charts, the largest lever. */
class R1Cockpit extends Report
{
    protected function body(): array
    {
        $p = $this->p;
        $files = array_filter($p->files(), [Rules::class, 'isCountedFile']);
        $count = fn (string $field, string $f, string $t) => count(array_filter($files, fn ($x) => Rules::inPeriod($x[$field] ?? null, $f, $t)));
        $fees  = fn (string $f, string $t) => Rules::fees($p->linesInWindow($f, $t), $p->invoicesById(), $p->countedInvoiceIds())['fees'];

        $unbilledNow = self::sum($p->unbilled(), 'amount');
        $adv = $p->openAdvances();
        $hmap = $p->advanceHolders();
        $holders = count(array_unique(array_filter(array_map(fn ($e) => ($hmap[(int) $e['id']]['key'] ?? 'none') === 'none' ? null : $hmap[(int) $e['id']]['key'], $adv))));

        $late = function (string $f, string $t) use ($p) {
            $done = array_filter($p->turnaround(), fn ($r) => !empty($r['is_done']) && Rules::inPeriod($r['completed'] ?? null, $f, $t));
            return Rules::pct(count(array_filter($done, fn ($r) => !empty($r['is_late']))), count($done));
        };
        $lateNow = $late($p->from, $p->to);

        $kpis = [
            $this->kpi('Files opened', $count('date_opened', $p->from, $p->to), 'int', $count('date_opened', $p->prevFrom, $p->prevTo)),
            $this->kpi('Files closed', $count('date_closed', $p->from, $p->to), 'int', $count('date_closed', $p->prevFrom, $p->prevTo)),
            $this->kpi('Fees billed HT', $fees($p->from, $p->to), 'xaf', $fees($p->prevFrom, $p->prevTo)),
            $this->kpi('Unbilled disbursements', $unbilledNow, 'xaf', $this->unbilledAt($p->prevTo), null, count($p->unbilled()) . ' lines'),
            $this->kpi('Unjustified advances', self::sum($adv, 'amount'), 'xaf', $this->advancesAt($p->prevTo), null, $holders . ' holder' . ($holders === 1 ? '' : 's')),
            $this->kpi('Turnaround steps late', $lateNow, 'pct', $late($p->prevFrom, $p->prevTo),
                $lateNow === null ? null : ($lateNow > 40 ? 'red' : ($lateNow > 10 ? 'amber' : 'green'))),
        ];

        // Fees billed per month, six months ending with the period's month.
        $labels = $values = [];
        $end = new \DateTimeImmutable(substr($p->to, 0, 7) . '-01');
        for ($i = 5; $i >= 0; $i--) {
            $m = $end->modify("-{$i} months");
            $labels[] = $m->format('m/Y');
            $values[] = $fees($m->format('Y-m-01'), $m->format('Y-m-t'));
        }
        $stateLabels = $p->d->selectionLabels('logistics.file', 'state');
        $byState = [];
        foreach (Rules::LIVE_STATES as $s) {
            $byState[$stateLabels[$s] ?? $s] = count(array_filter($files, fn ($f) => ($f['state'] ?? '') === $s));
        }

        return [
            'decide' => $this->lever(),
            'kpis' => $kpis,
            'charts' => [
                ['type' => 'line', 'title' => 'Fees billed HT per month', 'labels' => $labels, 'values' => $values, 'fmt' => 'xaf'],
                ['type' => 'bar', 'title' => 'Live files by state', 'labels' => array_keys($byState), 'values' => array_values($byState), 'fmt' => 'int'],
            ],
        ];
    }

    /** Unbilled at a past date: settled by then, and not yet billed then. */
    private function unbilledAt(string $date): float
    {
        $inv = $this->p->invoicesById();
        $t = 0.0;
        foreach ($this->p->expenses() as $e) {
            $settled = Rules::localDate($e['date_settled'] ?? null);
            if (!$settled || $settled > $date || ($e['state'] ?? '') === 'cancel') continue;
            $bid = $this->id($e['billed_invoice_id'] ?? false);
            $billedOn = $bid ? Rules::localDate($inv[$bid]['invoice_date'] ?? null) : null;
            if (!empty($e['is_billed']) && $billedOn !== null && $billedOn <= $date) continue;
            $t += (float) $e['amount'];
        }
        return $t;
    }

    /** Unjustified advances at a past date: paid by then, not justified by then. */
    private function advancesAt(string $date): float
    {
        $t = 0.0;
        foreach ($this->p->expenses() as $e) {
            if (($e['payment_mode'] ?? '') !== 'advance' || ($e['state'] ?? '') === 'cancel') continue;
            $settled = Rules::localDate($e['date_settled'] ?? null);
            if (!$settled || $settled > $date) continue;
            $just = Rules::localDate($e['date_justified'] ?? null);
            if ($just !== null && $just <= $date) continue;
            $t += (float) $e['amount'];
        }
        return $t;
    }

    /** One sentence naming the largest money lever. */
    private function lever(): string
    {
        $p = $this->p;
        $m = fn (float $x) => abs($x) >= 1e6 ? number_format($x / 1e6, 1, '.', ' ') . ' M' : Rules::xaf($x) . ' XAF';
        $c = [];

        $ok = array_filter($p->files(), fn ($f) => ($f['state'] ?? '') === 'ops_closed');
        if ($ok) {
            $amt = self::sum($ok, 'oop_total');
            $c[] = [$amt, count($ok) . ' file' . (count($ok) > 1 ? 's are' : ' is') . ' OK for Billing and hold ' . $m($amt) . ' of disbursed cash.'];
        }
        $old = array_filter($p->unbilled(), fn ($e) => (Rules::ageDays($e['date_settled'] ?? null, $p->asOf) ?? 0) > 60);
        if ($old) {
            $c[] = [self::sum($old, 'amount'), count($old) . ' disbursement' . (count($old) > 1 ? 's' : '') . ' paid more than 60 days ago are still unbilled: ' . $m(self::sum($old, 'amount')) . '.'];
        }
        $stale = array_filter($p->openAdvances(), fn ($e) => (Rules::ageDays($e['date_settled'] ?? null, $p->asOf) ?? 0) > 14);
        if ($stale) {
            $c[] = [self::sum($stale, 'amount'), count($stale) . ' staff advance' . (count($stale) > 1 ? 's' : '') . ' older than 14 days remain unjustified: ' . $m(self::sum($stale, 'amount')) . '.'];
        }
        if (!$c) return 'No open money lever: nothing is waiting to be billed or justified.';
        usort($c, fn ($a, $b) => $b[0] <=> $a[0]);
        return $c[0][1];
    }
}
