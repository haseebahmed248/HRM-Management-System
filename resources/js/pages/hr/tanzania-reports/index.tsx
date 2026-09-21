import { useState } from 'react';
import { PageTemplate } from '@/components/page-template';
import { usePage } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';

/**
 * Tanzania statutory reports. Lean UI: pick a payroll run, download CSVs.
 *
 * The five reports on this page are the ones committed for the $300
 * scope — PAYE, NSSF, SDL, WCF, plus a full payroll summary. Each is a
 * simple CSV pulled straight from PayrollEntry rows so no report engine
 * or PDF renderer is required.
 */

interface PayrollRunRow {
  id: number;
  title: string;
  pay_period_start: string;
  pay_period_end: string;
  status: string;
}

interface Reports {
  key: 'paye' | 'nssf' | 'sdl' | 'wcf' | 'summary';
  title: string;
  desc: string;
  routeName: string;
}

export default function TanzaniaReportsIndex() {
  const { t } = useTranslation();
  const { payrollRuns = [] } = usePage().props as unknown as { payrollRuns: PayrollRunRow[] };
  const [runId, setRunId] = useState<string>(payrollRuns[0]?.id.toString() ?? '');

  const reports: Reports[] = [
    { key: 'paye',    title: t('PAYE Report'),          desc: t('TRA PAYE tax per employee for the selected period.'),                routeName: 'hr.tanzania-reports.paye' },
    { key: 'nssf',    title: t('NSSF Report'),          desc: t('Employee 10% + employer 10% NSSF contributions per employee.'),      routeName: 'hr.tanzania-reports.nssf' },
    { key: 'sdl',     title: t('SDL Report'),           desc: t('Skills Development Levy (3.5% employer, 10+ employees).'),           routeName: 'hr.tanzania-reports.sdl' },
    { key: 'wcf',     title: t('WCF Report'),           desc: t('Workers Compensation Fund (0.5% employer).'),                        routeName: 'hr.tanzania-reports.wcf' },
    { key: 'summary', title: t('Payroll Summary'),      desc: t('Full per-employee breakdown for this run (basic, gross, PAYE, NSSF, SDL, WCF, net).'), routeName: 'hr.tanzania-reports.payroll-summary' },
  ];

  const download = (routeName: string) => {
    if (!runId) return;
    const url = route(routeName) + '?payroll_run_id=' + runId;
    window.location.href = url;
  };

  return (
    <PageTemplate
      title={t('Tanzania Statutory Reports')}
      description={t('Download PAYE, NSSF, SDL, WCF and payroll summary CSVs for the selected run.')}
      url="/hr/tanzania-reports"
    >
      <div className="mb-6">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              <FileText className="h-4 w-4" />
              {t('Select Payroll Run')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="max-w-md space-y-1">
              <Label className="text-xs text-muted-foreground">{t('Payroll Run')}</Label>
              <select
                value={runId}
                onChange={(e) => setRunId(e.target.value)}
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                {payrollRuns.length === 0 && (
                  <option value="">{t('No completed payroll runs yet')}</option>
                )}
                {payrollRuns.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.title} — {r.pay_period_start} to {r.pay_period_end} ({r.status})
                  </option>
                ))}
              </select>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        {reports.map((r) => (
          <Card key={r.key}>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-semibold">{r.title}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <p className="text-xs text-muted-foreground">{r.desc}</p>
              <Button
                type="button"
                disabled={!runId}
                onClick={() => download(r.routeName)}
                className="w-full"
              >
                <Download className="mr-2 h-4 w-4" />
                {t('Download CSV')}
              </Button>
            </CardContent>
          </Card>
        ))}
      </div>
    </PageTemplate>
  );
}
