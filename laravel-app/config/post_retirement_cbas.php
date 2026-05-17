<?php

/*
|------------------------------------------------------------------
| Post-Retirement Benefits — Collective Bargaining Agreement registry
|------------------------------------------------------------------
|
| Each CBA defines the indemnity owed to an employee on three
| termination scenarios:
|
|   - termination  (involuntary, by employer — Indemnité de licenciement)
|   - retirement   (Indemnité de fin de carrière)
|   - death        (Indemnité de décès aux ayants droit)
|
| The benefit formula is universal across CBAs:
|
|   Indemnity = monthly_avg_salary_last_12_months
|             × Σ_band ( band_rate × years_in_band )
|
| Each band is defined as { from: <years>, to: <years>, rate: <pct> }
| where `to: null` means "and above". `years_in_band` is the portion
| of the employee's seniority that falls within that band.
|
| Example: a Commerce CBA employee retiring with 18 years of service
| at a 1,000,000 XAF average monthly salary gets:
|   5y × 40%  +  5y × 45%  +  5y × 60%  +  3y × 65%
|   = 2.00 + 2.25 + 3.00 + 1.95
|   = 9.20 months → 9,200,000 XAF lump sum
|
| Notice period (Article 42) is included for reference but is paid
| as part of severance, not capitalized into the long-term provision.
|
| Adding a new CBA: copy a block and edit. The CbaRegistry service
| will pick it up automatically; tenants can be reassigned via the
| admin UI.
|
*/

return [

    /*
    |--------------------------------------------------------------
    | Commerce sector — Convention Collective Nationale du Commerce
    |--------------------------------------------------------------
    | Articles 42 (préavis), 45 (licenciement), 46 (fin de carrière),
    | 47 (décès). Eligibility threshold for indemnités = 1 year of
    | service (except death from work accident → no threshold).
    */
    'commerce' => [
        'key'                  => 'commerce',
        'name'                 => 'Convention Collective Nationale du Commerce',
        'source_doc'           => 'CBA Commerce — Articles 42, 45, 46, 47',
        'min_service_months'   => 12,
        'notice_period_months' => [
            // Commerce CBA Article 42 — paid but not worked.
            // Categories I-VI = junior/blue-collar; VII-IX = supervisors;
            // X-XII = senior managers. Notice period varies with
            // seniority bracket.
            'blue_collar' => ['under_1y' => 1, '1_to_5y' => 2, '5_to_10y' => 3, 'over_10y' => 3],
            'supervisor'  => ['under_1y' => 1, '1_to_5y' => 2, '5_to_10y' => 3, 'over_10y' => 3],
            'manager'     => ['under_1y' => 1, '1_to_5y' => 3, '5_to_10y' => 4, 'over_10y' => 4],
        ],
        'termination_brackets' => [
            ['from' => 0,  'to' => 5,    'rate' => 0.25],
            ['from' => 5,  'to' => 10,   'rate' => 0.30],
            ['from' => 10, 'to' => 15,   'rate' => 0.40],
            ['from' => 15, 'to' => 20,   'rate' => 0.45],
            ['from' => 20, 'to' => null, 'rate' => 0.50],
        ],
        'retirement_brackets' => [
            ['from' => 0,  'to' => 5,    'rate' => 0.40],
            ['from' => 5,  'to' => 10,   'rate' => 0.45],
            ['from' => 10, 'to' => 15,   'rate' => 0.60],
            ['from' => 15, 'to' => 20,   'rate' => 0.65],
            ['from' => 20, 'to' => null, 'rate' => 0.75],
        ],
        'death_brackets' => [
            // Article 47.2 — same scale as fin de carrière (retirement).
            ['from' => 0,  'to' => 5,    'rate' => 0.40],
            ['from' => 5,  'to' => 10,   'rate' => 0.45],
            ['from' => 10, 'to' => 15,   'rate' => 0.60],
            ['from' => 15, 'to' => 20,   'rate' => 0.65],
            ['from' => 20, 'to' => null, 'rate' => 0.75],
        ],
        'notes' => [
            'Indemnity base = average monthly salary of the 12 months preceding the event.',
            'Excludes reimbursements (frais) and in-kind benefits.',
            'Retirement: 10% penalty if employer fires anticipated without 1-year notice (Art 46.5).',
            'Death: indemnity doubled for work accident or occupational illness (informational; not capitalized).',
        ],
    ],

    /*
    |--------------------------------------------------------------
    | Default fallback — used when a tenant has no CBA assigned.
    | This is the Cameroon Labour Code (Loi 92-007) statutory
    | minimum and is INTENTIONALLY LESS GENEROUS than any sector
    | CBA. Tenants should assign a sector CBA to get accurate
    | indemnities; this is here so the engine never crashes.
    |--------------------------------------------------------------
    */
    'statutory_minimum' => [
        'key'                  => 'statutory_minimum',
        'name'                 => 'Cameroon Code du Travail — Statutory Minimum',
        'source_doc'           => 'Loi N° 92-007 du 14 août 1992 portant Code du Travail',
        'min_service_months'   => 12,
        'notice_period_months' => [
            'blue_collar' => ['under_1y' => 0, '1_to_5y' => 1, '5_to_10y' => 1, 'over_10y' => 1],
            'supervisor'  => ['under_1y' => 0, '1_to_5y' => 1, '5_to_10y' => 2, 'over_10y' => 2],
            'manager'     => ['under_1y' => 0, '1_to_5y' => 2, '5_to_10y' => 3, 'over_10y' => 3],
        ],
        // Statutory rates are not codified at sector-grade detail;
        // we conservatively mirror the Commerce CBA termination
        // scale at -5pp per band as a defensible floor.
        'termination_brackets' => [
            ['from' => 0,  'to' => 5,    'rate' => 0.20],
            ['from' => 5,  'to' => 10,   'rate' => 0.25],
            ['from' => 10, 'to' => 15,   'rate' => 0.35],
            ['from' => 15, 'to' => 20,   'rate' => 0.40],
            ['from' => 20, 'to' => null, 'rate' => 0.45],
        ],
        'retirement_brackets' => [
            ['from' => 0,  'to' => 5,    'rate' => 0.35],
            ['from' => 5,  'to' => 10,   'rate' => 0.40],
            ['from' => 10, 'to' => 15,   'rate' => 0.55],
            ['from' => 15, 'to' => 20,   'rate' => 0.60],
            ['from' => 20, 'to' => null, 'rate' => 0.70],
        ],
        'death_brackets' => [
            ['from' => 0,  'to' => 5,    'rate' => 0.35],
            ['from' => 5,  'to' => 10,   'rate' => 0.40],
            ['from' => 10, 'to' => 15,   'rate' => 0.55],
            ['from' => 15, 'to' => 20,   'rate' => 0.60],
            ['from' => 20, 'to' => null, 'rate' => 0.70],
        ],
        'notes' => [
            'Use only when no sector CBA applies. Tenants should classify employees under a sector CBA for accurate valuation.',
        ],
    ],

    /*
    | Future CBAs (Industry, Banking, Telecoms, Hospitality, etc.)
    | will be added here. Each follows the same shape so the
    | calculation engine never needs to change. The admin UI will
    | expose a form to define custom CBAs at tenant level via the
    | post_retirement_cbas table (overrides this config file).
    */

];
