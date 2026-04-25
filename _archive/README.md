# Archive

Frozen reference copies of upstream projects that have been merged into the
**Elite Advisors Management Suite (EAMS)**. Code here is **not** built or
served — it lives on disk for traceability.

## EA Tax Compliance

Forked sandbox originally used to develop the VAT-compliance module against
Cameroon CGI rules. Its unique business logic was ported into EAMS during
Phase B of the multi-tenancy / multi-service rollout:

| Source (legacy)                                            | EAMS destination                                      |
| ---------------------------------------------------------- | ----------------------------------------------------- |
| `src/Services/VATComplianceService.php`                    | `laravel-app/app/Services/Tax/VATComplianceService.php` |
| `src/Services/VATInvoiceComplianceService.php`             | `laravel-app/app/Services/Tax/VATInvoiceComplianceService.php` |
| `src/Controllers/VATComplianceController.php`              | `laravel-app/app/Http/Controllers/Tax/VATComplianceController.php` |
| `src/Controllers/VATInvoiceComplianceController.php`       | `laravel-app/app/Http/Controllers/Tax/VATInvoiceComplianceController.php` |
| `views/vat_compliance_dashboard.php` (52-week calendar)    | `laravel-app/resources/views/tax/tax-calendar.blade.php` |
| `views/vat_invoice_compliance.php`                         | `laravel-app/resources/views/tax/vat-invoice-compliance.blade.php` |

The duplicate Laravel mirror under `EA Tax Compliance/laravel-app/` was a
slightly older copy of the BI app and contained no net-new code. It is kept
here for completeness only.
