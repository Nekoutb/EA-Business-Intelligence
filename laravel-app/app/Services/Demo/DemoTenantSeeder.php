<?php
declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Demo\DemoAccount;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoInvoice;
use App\Models\Demo\DemoInvoiceLine;
use App\Models\Demo\DemoJournalEntry;
use App\Models\Demo\DemoJournalLine;
use App\Models\Demo\DemoPayment;
use App\Models\Demo\DemoProduct;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a fresh demo tenant with a realistic — but fictional — dataset
 * that exercises every BMS module:
 *
 *   - 10 customers across 3 categories (Hotel & Restaurant, Retail,
 *     Wholesale), spread over different payment terms (15/30/45/60d)
 *     so the AR aging chart actually shows tail risk + a top-5
 *     concentration north of 50%.
 *   - 8 products across 2 categories (Wines, Spirits) with different
 *     margins so the P&L gross-margin commentary has something to chew on.
 *   - ~30 invoices over the trailing 90 days, with realistic Cameroon
 *     XAF amounts (100k-2.5M each), 19.25% VAT, and a deliberate
 *     ~15% slice marked has_po=false so the GL-quality demo finding
 *     ("invoices without a matching PO") fires immediately.
 *   - Payments against ~70% of posted invoices, scattered across the
 *     past 60 days so DSO lands around 40-50d and overdue rate around
 *     20-25% — both amber thresholds, prime demo material.
 *   - A handful of manual journal entries (rent, salaries, depreciation,
 *     bank charges) so the trial balance + P&L view shows non-revenue
 *     activity right out of the gate.
 *   - Full OHADA chart of accounts for the relevant classes (1, 4, 5,
 *     6, 7) so the visitor can post journals to any sensible account
 *     and see them flow through.
 *
 * Idempotent at the tenant level — calling reseed() on a tenant wipes
 * its existing demo_* rows first, then re-runs the full seed.
 *
 * All amounts in XAF. Currency tag is set on the tenant via
 * config(bi.currency) at tenant level — the demo controllers read it
 * from there.
 */
final class DemoTenantSeeder
{
    /**
     * Wipe + reseed a demo tenant. Safe to call repeatedly — the
     * caller (DemoEntryController, demo console "Reset" button) uses
     * this to restore a clean slate.
     */
    public function reseed(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            $this->wipe($tenant);
            $this->seedAccounts($tenant);
            $customers = $this->seedCustomers($tenant);
            $products  = $this->seedProducts($tenant);
            $invoices  = $this->seedInvoices($tenant, $customers, $products);
            $this->seedPayments($tenant, $invoices);
            $this->seedJournalEntries($tenant);
        });
    }

    private function wipe(Tenant $tenant): void
    {
        // Order matters: lines / payments / audit before headers.
        // Cascades cover most of this but explicit clears guarantee
        // we don't leave orphans if FK isn't enforced (SQLite/MySQL
        // differences).
        DemoInvoiceLine::whereIn('invoice_id', DemoInvoice::where('tenant_id', $tenant->id)->pluck('id'))->delete();
        DemoPayment::where('tenant_id', $tenant->id)->delete();
        DemoInvoice::where('tenant_id', $tenant->id)->delete();
        DemoJournalLine::whereIn('entry_id', DemoJournalEntry::where('tenant_id', $tenant->id)->pluck('id'))->delete();
        DemoJournalEntry::where('tenant_id', $tenant->id)->delete();
        DemoCustomer::where('tenant_id', $tenant->id)->delete();
        DemoProduct::where('tenant_id', $tenant->id)->delete();
        DemoAccount::where('tenant_id', $tenant->id)->delete();
    }

    private function seedAccounts(Tenant $tenant): void
    {
        // OHADA-style chart, just the accounts the demo modules touch.
        $rows = [
            // Class 1 — Capital + reserves
            ['code' => '101000', 'name' => 'Capital social',                       'class' => 1, 'type' => 'equity',    'opening_balance' =>  50_000_000],
            ['code' => '120000', 'name' => 'Résultat de l\'exercice',              'class' => 1, 'type' => 'equity',    'opening_balance' =>   0],
            // Class 2 — Fixed assets
            ['code' => '241000', 'name' => 'Matériel et mobilier',                 'class' => 2, 'type' => 'asset',     'opening_balance' =>  18_000_000],
            ['code' => '281000', 'name' => 'Amortissements cumulés',               'class' => 2, 'type' => 'asset',     'opening_balance' =>  -6_500_000],
            // Class 4 — Receivables + payables + VAT
            ['code' => '411000', 'name' => 'Clients',                              'class' => 4, 'type' => 'asset',     'opening_balance' =>   0],
            ['code' => '401000', 'name' => 'Fournisseurs',                         'class' => 4, 'type' => 'liability', 'opening_balance' =>   8_400_000],
            ['code' => '443100', 'name' => 'TVA collectée (sales VAT)',            'class' => 4, 'type' => 'liability', 'opening_balance' =>   0],
            ['code' => '445660', 'name' => 'TVA déductible (input VAT)',           'class' => 4, 'type' => 'asset',     'opening_balance' =>   0],
            ['code' => '447110', 'name' => 'Acompte sur CA retenu à la source',    'class' => 4, 'type' => 'asset',     'opening_balance' =>   0],
            // Class 5 — Treasury
            ['code' => '521000', 'name' => 'Banque principale',                    'class' => 5, 'type' => 'asset',     'opening_balance' =>  12_300_000],
            ['code' => '571000', 'name' => 'Caisse',                               'class' => 5, 'type' => 'asset',     'opening_balance' =>     850_000],
            // Class 6 — Expenses
            ['code' => '601000', 'name' => 'Achats de marchandises (COGS)',        'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            ['code' => '611000', 'name' => 'Sous-traitance générale',              'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            ['code' => '622000', 'name' => 'Location et charges locatives',        'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            ['code' => '631100', 'name' => 'Frais bancaires',                      'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            ['code' => '641000', 'name' => 'Rémunérations directes',               'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            ['code' => '661000', 'name' => 'Charges sociales',                     'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            ['code' => '681000', 'name' => 'Dotations aux amortissements',         'class' => 6, 'type' => 'expense',   'opening_balance' =>   0],
            // Class 7 — Revenue
            ['code' => '701100', 'name' => 'Ventes de marchandises',               'class' => 7, 'type' => 'revenue',   'opening_balance' =>   0],
            ['code' => '706000', 'name' => 'Services vendus',                      'class' => 7, 'type' => 'revenue',   'opening_balance' =>   0],
        ];
        foreach ($rows as $r) {
            DemoAccount::create($r + ['tenant_id' => $tenant->id]);
        }
    }

    /** @return array<int, DemoCustomer> */
    private function seedCustomers(Tenant $tenant): array
    {
        $rows = [
            ['name' => 'Hôtel Krystal Palace',       'category' => 'Hotel & Restaurant', 'payment_terms_days' => 45, 'country' => 'CM'],
            ['name' => 'Hilton Yaoundé',             'category' => 'Hotel & Restaurant', 'payment_terms_days' => 60, 'country' => 'CM'],
            ['name' => 'Restaurant Le Pacha',        'category' => 'Hotel & Restaurant', 'payment_terms_days' => 30, 'country' => 'CM'],
            ['name' => 'Mahima Supermarché',         'category' => 'Retail',             'payment_terms_days' => 30, 'country' => 'CM'],
            ['name' => 'Casino Bonanjo',             'category' => 'Retail',             'payment_terms_days' => 30, 'country' => 'CM'],
            ['name' => 'Score Akwa',                 'category' => 'Retail',             'payment_terms_days' => 15, 'country' => 'CM'],
            ['name' => 'Grossiste Bafoussam SARL',   'category' => 'Wholesale',          'payment_terms_days' => 60, 'country' => 'CM'],
            ['name' => 'Distrib Cameroun',           'category' => 'Wholesale',          'payment_terms_days' => 45, 'country' => 'CM'],
            ['name' => 'Total Energies CM',          'category' => 'Wholesale',          'payment_terms_days' => 30, 'country' => 'CM'],
            ['name' => 'Société Anonyme Bana',       'category' => 'Wholesale',          'payment_terms_days' => 30, 'country' => 'CM'],
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = DemoCustomer::create($r + ['tenant_id' => $tenant->id]);
        }
        return $out;
    }

    /** @return array<int, DemoProduct> */
    private function seedProducts(Tenant $tenant): array
    {
        $rows = [
            ['name' => 'Vin rouge Côtes du Rhône 75cl',   'category' => 'Wines',    'unit_price' =>  8_500, 'default_cogs' =>  5_100],
            ['name' => 'Champagne Moët & Chandon 75cl',   'category' => 'Wines',    'unit_price' => 45_000, 'default_cogs' => 28_000],
            ['name' => 'Whisky Johnnie Walker Black 70cl','category' => 'Spirits',  'unit_price' => 32_000, 'default_cogs' => 19_500],
            ['name' => 'Vodka Smirnoff 1L',               'category' => 'Spirits',  'unit_price' => 18_500, 'default_cogs' => 11_200],
            ['name' => 'Rhum Bologne Vieux 70cl',         'category' => 'Spirits',  'unit_price' => 22_000, 'default_cogs' => 13_400],
            ['name' => 'Vin blanc Sancerre 75cl',         'category' => 'Wines',    'unit_price' => 14_500, 'default_cogs' =>  8_900],
            ['name' => 'Gin Bombay Sapphire 70cl',        'category' => 'Spirits',  'unit_price' => 24_000, 'default_cogs' => 14_500],
            ['name' => 'Vin rosé Provence 75cl',          'category' => 'Wines',    'unit_price' => 11_000, 'default_cogs' =>  6_700],
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = DemoProduct::create($r + ['tenant_id' => $tenant->id]);
        }
        return $out;
    }

    /** @return array<int, DemoInvoice> */
    private function seedInvoices(Tenant $tenant, array $customers, array $products): array
    {
        // 60 invoices over the trailing 90 days — date spread is uniform-ish
        // with a slight bias toward the most recent 30 days so the DSO math
        // produces a believable mid-period figure (~40-50d).
        //
        // Sized so the seeded business looks like a healthy mid-sized
        // wholesaler: ~30-40M XAF/month revenue, gross margin ~39%, and
        // EBITDA in the green band (~10-15% margin) for a default dataset.
        // The visitor can then break things deliberately via the NL console
        // or canned forms to see the BMS catch the consequences.
        $today = now()->startOfDay();
        $invoices = [];
        $invoiceCounter = 1;

        // Deterministic-ish randomness — the same seed every reseed so demos
        // are reproducible. Seeded from the tenant id so different sandboxes
        // don't all show the exact same data layout.
        mt_srand(($tenant->id * 7919) & 0x7fffffff);

        for ($i = 0; $i < 60; $i++) {
            $daysAgo  = (int) (90 - ($i * 1.5) - mt_rand(0, 1));   // monotonic enough to look like a real timeline
            $invDate  = $today->copy()->subDays(max(0, $daysAgo));
            $customer = $customers[$i % count($customers)];
            $product1 = $products[($i * 3) % count($products)];
            $product2 = $products[($i * 5 + 2) % count($products)];

            $qty1 = mt_rand(12, 48);
            $qty2 = mt_rand(6, 24);
            $sub1 = $qty1 * (float) $product1->unit_price;
            $sub2 = $qty2 * (float) $product2->unit_price;
            $cogs1 = $qty1 * (float) $product1->default_cogs;
            $cogs2 = $qty2 * (float) $product2->default_cogs;
            $amountHt = $sub1 + $sub2;
            $vat      = round($amountHt * 0.1925, 2);
            $total    = $amountHt + $vat;

            // ~17% of invoices marked has_po=false so the GL-quality
            // module finds a meaningful "invoices without matching PO"
            // pile to flag.
            $hasPo = ($i % 6 !== 0);

            $invoice = DemoInvoice::create([
                'tenant_id'    => $tenant->id,
                'number'       => sprintf('INV-%04d-%05d', $invDate->year, $invoiceCounter++),
                'customer_id'  => $customer->id,
                'invoice_date' => $invDate,
                'due_date'     => $invDate->copy()->addDays($customer->payment_terms_days),
                'post_date'    => $invDate,
                'status'       => 'posted',
                'amount_ht'    => $amountHt,
                'vat_amount'   => $vat,
                'total'        => $total,
                'has_po'       => $hasPo,
                'po_number'    => $hasPo ? sprintf('PO-%05d', 4000 + $i) : null,
                'notes'        => null,
            ]);

            DemoInvoiceLine::create([
                'invoice_id'  => $invoice->id,
                'product_id'  => $product1->id,
                'description' => $product1->name,
                'qty'         => $qty1,
                'unit_price'  => $product1->unit_price,
                'subtotal'    => $sub1,
                'cogs_amount' => $cogs1,
            ]);
            DemoInvoiceLine::create([
                'invoice_id'  => $invoice->id,
                'product_id'  => $product2->id,
                'description' => $product2->name,
                'qty'         => $qty2,
                'unit_price'  => $product2->unit_price,
                'subtotal'    => $sub2,
                'cogs_amount' => $cogs2,
            ]);

            $invoices[] = $invoice;
        }

        return $invoices;
    }

    private function seedPayments(Tenant $tenant, array $invoices): void
    {
        // Pay ~70% of invoices in full, leave the rest open. The open ones
        // skew slightly toward older dates so the AR aging chart shows
        // ~20-25% overdue + a 60d/90d tail.
        foreach ($invoices as $i => $inv) {
            // Skip every 4th + every 7th to leave ~30% open invoices.
            if ($i % 4 === 0 || $i % 7 === 0) continue;

            // Pay between 5 and (customer's terms + 20) days after the
            // invoice date — most are on-time, some slip.
            $customer = $inv->customer;
            $payDays  = mt_rand(5, ($customer->payment_terms_days ?? 30) + 20);
            $payDate  = $inv->invoice_date->copy()->addDays($payDays);
            if ($payDate->isFuture()) continue;   // nothing booked beyond today

            DemoPayment::create([
                'tenant_id'    => $tenant->id,
                'invoice_id'   => $inv->id,
                'payment_date' => $payDate,
                'amount'       => $inv->total,
                'method'       => $i % 3 === 0 ? 'bank_transfer' : ($i % 3 === 1 ? 'cheque' : 'mobile_money'),
                'reference'    => 'PAY-' . str_pad((string) ($i + 100), 5, '0', STR_PAD_LEFT),
            ]);

            $inv->update(['status' => 'paid']);
        }
    }

    private function seedJournalEntries(Tenant $tenant): void
    {
        $today = now()->startOfDay();

        // Three months of monthly recurring opex — rent, salaries, depreciation.
        // Sized to land EBITDA margin in the amber band (0%-10%) for a default
        // sandbox, so the demo shows a real business with normal challenges
        // rather than a screaming distressed one. Revenue runs ~140-160M XAF
        // over 90 days from the invoice seeder; total opex below comes out
        // to ~25M XAF per month × 3 months = 75M, leaving a small positive
        // EBITDA — typical for a Cameroon-SME wholesaler/retailer.
        for ($m = 0; $m < 3; $m++) {
            $monthStart = $today->copy()->subMonthsNoOverflow($m)->startOfMonth();

            // Rent — debit 622000 2,500,000 / credit 521000
            $rent = DemoJournalEntry::create([
                'tenant_id'   => $tenant->id,
                'entry_date'  => $monthStart->copy()->addDays(2),
                'journal_code'=> 'OD',
                'reference'   => 'RENT-' . $monthStart->format('Ym'),
                'description' => 'Loyer mensuel siège',
                'posted'      => true,
            ]);
            DemoJournalLine::create(['entry_id' => $rent->id, 'account_code' => '622000', 'account_name' => 'Location et charges locatives', 'debit' => 2_500_000, 'credit' => 0]);
            DemoJournalLine::create(['entry_id' => $rent->id, 'account_code' => '521000', 'account_name' => 'Banque principale',              'debit' => 0,         'credit' => 2_500_000]);

            // Payroll — debit 641000 4,800,000 + 661000 960,000 / credit 521000 5,760,000
            $payroll = DemoJournalEntry::create([
                'tenant_id'   => $tenant->id,
                'entry_date'  => $monthStart->copy()->endOfMonth()->startOfDay(),
                'journal_code'=> 'OD',
                'reference'   => 'PAY-' . $monthStart->format('Ym'),
                'description' => 'Paie + charges sociales',
                'posted'      => true,
            ]);
            DemoJournalLine::create(['entry_id' => $payroll->id, 'account_code' => '641000', 'account_name' => 'Rémunérations directes', 'debit' => 4_800_000, 'credit' => 0]);
            DemoJournalLine::create(['entry_id' => $payroll->id, 'account_code' => '661000', 'account_name' => 'Charges sociales',       'debit' =>   960_000, 'credit' => 0]);
            DemoJournalLine::create(['entry_id' => $payroll->id, 'account_code' => '521000', 'account_name' => 'Banque principale',      'debit' => 0,          'credit' => 5_760_000]);

            // Depreciation — debit 681000 450,000 / credit 281000 450,000
            $dep = DemoJournalEntry::create([
                'tenant_id'   => $tenant->id,
                'entry_date'  => $monthStart->copy()->endOfMonth()->startOfDay(),
                'journal_code'=> 'OD',
                'reference'   => 'DEP-' . $monthStart->format('Ym'),
                'description' => 'Dotation aux amortissements',
                'posted'      => true,
            ]);
            DemoJournalLine::create(['entry_id' => $dep->id, 'account_code' => '681000', 'account_name' => 'Dotations aux amortissements', 'debit' => 450_000, 'credit' => 0]);
            DemoJournalLine::create(['entry_id' => $dep->id, 'account_code' => '281000', 'account_name' => 'Amortissements cumulés',       'debit' => 0,       'credit' => 450_000]);

            // Bank charges — small, recurring
            $bank = DemoJournalEntry::create([
                'tenant_id'   => $tenant->id,
                'entry_date'  => $monthStart->copy()->endOfMonth()->startOfDay(),
                'journal_code'=> 'BNK',
                'reference'   => 'BNK-FRAIS-' . $monthStart->format('Ym'),
                'description' => 'Frais bancaires mensuels',
                'posted'      => true,
            ]);
            DemoJournalLine::create(['entry_id' => $bank->id, 'account_code' => '631100', 'account_name' => 'Frais bancaires',    'debit' => 85_000, 'credit' => 0]);
            DemoJournalLine::create(['entry_id' => $bank->id, 'account_code' => '521000', 'account_name' => 'Banque principale', 'debit' => 0,      'credit' => 85_000]);
        }
    }
}
