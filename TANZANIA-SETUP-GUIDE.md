# AfriPay HR — Tanzania Module Setup Guide

This short guide walks the admin through activating the Tanzania payroll
rules for a company. The goal is to get from a fresh install to a first
payroll run that produces PAYE + NSSF + SDL + WCF correctly.

Total setup time: about 15 minutes.

---

## Before you start

You need:

- A company account already created in AfriPay HR (the Super Admin, or the
  company admin, signed in).
- At least a couple of employees added with a basic salary set.
- Working days configured under Settings → Working Days (already required
  for any payroll run).

---

## Step 1. Set the company country to Tanzania

1. Sign in as the company admin.
2. Go to **Settings** in the left sidebar.
3. Scroll to **Tanzania Tax Settings** in the settings navigation.
4. In the **Company Country** panel, pick **Tanzania**.
5. Leave **Auto-apply currency settings for this country** ticked.
6. Click **Save Country Settings**.

What happens on save:

- The company's country code is set to `TZ`.
- The currency settings are switched to TZS with the TSh symbol so
  payslips, dashboards and CSV exports all show the right currency
  automatically.

You do not need to update the currency manually.

---

## Step 2. Review the Tanzania tax rates (Super Admin only)

Below the Company Country panel there is a **Tanzania Tax Settings**
panel. This is where the PAYE bands, NSSF rates, SDL rate and WCF rate
live.

Defaults are pre-seeded from the 2026 TRA reference rates:

| Item | Default |
|------|---------|
| PAYE Slab 1 | 0 to 270,000 → 0% |
| PAYE Slab 2 | 270,001 to 520,000 → 8% |
| PAYE Slab 3 | 520,001 to 760,000 → 20% |
| PAYE Slab 4 | 760,001 to 1,000,000 → 25% |
| PAYE Slab 5 | 1,000,001 and above → 30% |
| NSSF Employee | 10% of monthly gross |
| NSSF Employer | 10% of monthly gross |
| SDL | 3.5% of monthly payroll, employer only |
| SDL applies from | 10 employees or more |
| WCF | 0.5% of monthly payroll, employer only |

When TRA changes rates (annual budget, etc.) the Super Admin edits this
panel once. Every Tanzania company picks up the change on their next
payroll run.

Company admins see this panel in read-only mode for reference.

---

## Step 3. Run payroll as usual

1. Go to **HR** → **Payroll Runs**.
2. Click **New Payroll Run**, set the period, and process.
3. On the run detail page you will see the deduction columns switch to
   **PAYE** and **NSSF** for Tanzania companies (they were PAYE / NAPSA /
   NHIMA for Zambia companies).
4. The employer contributions section shows NSSF Employer, SDL (if the
   company has 10+ employees) and WCF alongside any custom components.

---

## Step 4. Download the statutory CSVs

1. Go to **HR** → **Tanzania Reports** in the sidebar.
2. Pick the payroll run from the dropdown.
3. Click **Download CSV** on any of the five report cards:

- **PAYE Report** — per-employee PAYE and gross pay
- **NSSF Report** — per-employee employee/employer/total NSSF
- **SDL Report** — per-employee SDL breakdown
- **WCF Report** — per-employee WCF breakdown
- **Payroll Summary** — full per-employee breakdown for the run

Each CSV includes a total row at the bottom so the accountant can
reconcile against the payroll totals on the run detail page.

---

## Exemptions

Individual employees can be flagged exempt from statutory deductions via
the employee record. Tanzania uses the same exemption flags as Zambia so
no additional configuration is needed:

- **Exempt from NAPSA** on the employee record → exempts them from NSSF.
- **Exempt from PAYE** → exempts them from PAYE.
- **Exempt from SDL** → exempts them from both SDL and WCF (both are
  employer-side and share this flag in the Tanzania module).

---

## Adding another Tanzania company

Repeat Step 1 for each new company. The Tanzania tax rates in Step 2 are
global, so you set them once and every Tanzania company inherits them.

---

## Support

For issues or questions, contact the support team through your usual
channel.
