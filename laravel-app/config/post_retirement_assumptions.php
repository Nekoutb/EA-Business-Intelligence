<?php

/*
|------------------------------------------------------------------
| Post-Retirement Benefits — Default actuarial assumptions
|------------------------------------------------------------------
|
| These are the platform-level defaults seeded into every tenant
| when the module is enabled. The tenant admin can override any
| of them via the /post-retirement-benefits/assumptions page.
|
| All defaults are calibrated to Cameroon market conditions and
| match the Mercer template for Globeleq Cameroon (FYE 2022) so
| an auditor used to Mercer reports finds familiar numbers.
|
*/

return [

    /*
    | Discount rate — set to Cameroon government bond yield matching
    | the duration of the plan liabilities (~13 years for a typical
    | mid-career workforce). Updated annually based on BEAC published
    | yields. SYSCOHADA does not mandate a specific reference but
    | aligns with IAS 19's "high-quality corporate bond" requirement;
    | in Cameroon where corporate bond markets are thin, the
    | government bond yield is the accepted proxy.
    */
    'discount_rate' => 0.0675,           // 6.75% — Cameroon 13y gov bond yield ~year-end 2022

    /*
    | Salary increase rate — long-term annual escalation. Should
    | reflect tenant's compensation policy + expected inflation +
    | promotion-driven progression. Mercer used 3% for Globeleq.
    */
    'salary_increase_rate' => 0.03,      // 3.0% p.a.

    /*
    | Inflation expectation — BEAC long-term target is around 2%
    | for the CEMAC zone (anchored to the EUR peg).
    */
    'inflation_rate' => 0.02,            // 2.0% p.a.

    /*
    | Expected return on plan assets — relevant only for funded
    | plans. When the plan is unfunded (most Cameroon employers),
    | this is set to 0 in the valuation. Tenant overrides this if
    | the plan is funded with a specific asset mix.
    */
    'expected_return_on_assets' => 0.00, // 0% — unfunded by default

    /*
    | Turnover rates per age band — annual probability of voluntary
    | termination. Mercer's Globeleq assumptions are used as the
    | starting point (involuntary turnover only). Tenants can
    | override per their actual experience study.
    */
    'turnover_bands' => [
        ['from_age' => 0,  'to_age' => 25, 'rate' => 0.010],  // 1.0%
        ['from_age' => 25, 'to_age' => 35, 'rate' => 0.020],  // 2.0%
        ['from_age' => 35, 'to_age' => 45, 'rate' => 0.010],  // 1.0%
        ['from_age' => 45, 'to_age' => 50, 'rate' => 0.000],  // 0.0%
        ['from_age' => 50, 'to_age' => 60, 'rate' => 0.000],  // 0.0%
    ],

    /*
    | Mortality table — CIMA F (female) / CIMA H (male) are the
    | regional African insurance industry tables published by the
    | Conférence Interafricaine des Marchés d'Assurances. They are
    | the standard reference for life-insurance and pension work
    | in CEMAC/CIPRES countries. For mixed populations or when
    | gender data is unavailable we use CIMA F (slightly more
    | conservative — longer life expectancy).
    |
    | The table itself (qx by age) is embedded in
    | App\Services\PostRetirementBenefits\MortalityTables for
    | simplicity. CIMA F and CIMA H are loaded from generated
    | arrays so the calculator has no runtime dependency on an
    | external actuarial library.
    */
    'mortality_table' => 'cima_f',       // default — switch per gender if known

    /*
    | Retirement age — Cameroon legal retirement age is 60 (with
    | minimum 20 years of contributions for the CNPS pension).
    | Enforced as a hard rule by the user; not per-employee
    | configurable. CNPS-eligible employees are assumed to retire
    | exactly at 60.
    */
    'retirement_age' => 60,

    /*
    | Funded status — defaults to unfunded for new tenants. Toggle
    | to true if the tenant has assets in a trust earmarked for
    | the benefit obligation. When funded, the valuation tracks
    | plan assets separately and the expected return assumption
    | becomes relevant.
    */
    'funded' => false,

    /*
    | Net periodic benefit cost period — annual only (as per the
    | user's requirement). All valuations measure at fiscal year end.
    */
    'valuation_frequency' => 'annual',
];
