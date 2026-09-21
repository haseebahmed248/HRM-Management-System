import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/components/custom-toast';
import { AlertCircle, CheckCircle2, Info } from 'lucide-react';
import { useState } from 'react';

/**
 * Tanzania (TRA) tax settings admin, mirrors the Zambia component.
 *
 * All values are per month in TSh. When TRA changes rates the Super Admin
 * updates them here and every Tanzania-tagged company picks up the change
 * on the next payroll run. Company users see the page in read-only mode.
 */
interface TanzaniaTaxSettingsProps {
  settings: Record<string, string>;
  canEdit?: boolean;
}

interface FieldProps {
  label: string;
  fieldKey: string;
  hint?: string;
  readOnly?: boolean;
  value: string;
  error?: string;
  onChange: (key: string, value: string) => void;
}

const Field = ({ label, fieldKey, hint, readOnly = false, value, error, onChange }: FieldProps) => (
  <div className="space-y-1">
    <Label className="text-xs font-medium text-gray-600 dark:text-gray-400">{label}</Label>
    <Input
      type="number"
      step="0.01"
      readOnly={readOnly}
      value={value}
      onChange={(e) => !readOnly && onChange(fieldKey, e.target.value)}
      className={`${error ? 'border-red-500 focus-visible:ring-red-500' : ''} ${readOnly ? 'bg-muted cursor-not-allowed' : ''}`}
    />
    {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
    {error && (
      <p className="text-xs text-red-500 flex items-center gap-1">
        <AlertCircle className="h-3 w-3" /> {error}
      </p>
    )}
  </div>
);

export default function TanzaniaTaxSettings({ settings, canEdit = true }: TanzaniaTaxSettingsProps) {
  const { t } = useTranslation();
  const ro = !canEdit;

  const [form, setForm] = useState({
    tanzania_paye_slab_1_min:  settings.tanzania_paye_slab_1_min  ?? '0',
    tanzania_paye_slab_1_max:  settings.tanzania_paye_slab_1_max  ?? '270000',
    tanzania_paye_slab_1_rate: settings.tanzania_paye_slab_1_rate ?? '0',
    tanzania_paye_slab_2_min:  settings.tanzania_paye_slab_2_min  ?? '270001',
    tanzania_paye_slab_2_max:  settings.tanzania_paye_slab_2_max  ?? '520000',
    tanzania_paye_slab_2_rate: settings.tanzania_paye_slab_2_rate ?? '8',
    tanzania_paye_slab_3_min:  settings.tanzania_paye_slab_3_min  ?? '520001',
    tanzania_paye_slab_3_max:  settings.tanzania_paye_slab_3_max  ?? '760000',
    tanzania_paye_slab_3_rate: settings.tanzania_paye_slab_3_rate ?? '20',
    tanzania_paye_slab_4_min:  settings.tanzania_paye_slab_4_min  ?? '760001',
    tanzania_paye_slab_4_max:  settings.tanzania_paye_slab_4_max  ?? '1000000',
    tanzania_paye_slab_4_rate: settings.tanzania_paye_slab_4_rate ?? '25',
    tanzania_paye_slab_5_min:  settings.tanzania_paye_slab_5_min  ?? '1000001',
    tanzania_paye_slab_5_rate: settings.tanzania_paye_slab_5_rate ?? '30',
    tanzania_nssf_employee_rate: settings.tanzania_nssf_employee_rate ?? '10',
    tanzania_nssf_employer_rate: settings.tanzania_nssf_employer_rate ?? '10',
    tanzania_sdl_rate: settings.tanzania_sdl_rate ?? '3.5',
    tanzania_sdl_employee_threshold: settings.tanzania_sdl_employee_threshold ?? '10',
    tanzania_wcf_rate: settings.tanzania_wcf_rate ?? '0.5',
  });

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [saved, setSaved] = useState(false);

  const handleChange = (key: string, value: string) => {
    setSaved(false);
    setForm(prev => ({ ...prev, [key]: value }));
    if (errors[key]) {
      setErrors(prev => { const e = { ...prev }; delete e[key]; return e; });
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (ro) return;
    setIsSubmitting(true);
    setSaved(false);
    router.post(route('settings.tanzania-tax.update'), form, {
      onSuccess: (page: any) => {
        setIsSubmitting(false);
        setSaved(true);
        if (page.props.flash?.success) {
          toast.success(t(page.props.flash.success));
        } else {
          toast.success(t('Tanzania tax settings saved successfully'));
        }
      },
      onError: (errs: any) => {
        setIsSubmitting(false);
        setErrors(errs);
        toast.error(t('Please correct the errors in the form'));
      },
    });
  };

  const slabs = [
    {
      label: t('Slab 1'), badge: '0%',
      badgeColor: 'bg-green-50 text-green-700 ring-green-600/20',
      minKey: 'tanzania_paye_slab_1_min', maxKey: 'tanzania_paye_slab_1_max', rateKey: 'tanzania_paye_slab_1_rate',
      hint: t('Tax-free band — TSh 0 to 270,000'),
    },
    {
      label: t('Slab 2'), badge: '8%',
      badgeColor: 'bg-yellow-50 text-yellow-700 ring-yellow-600/20',
      minKey: 'tanzania_paye_slab_2_min', maxKey: 'tanzania_paye_slab_2_max', rateKey: 'tanzania_paye_slab_2_rate',
      hint: t('Low rate band — TSh 270,001 to 520,000'),
    },
    {
      label: t('Slab 3'), badge: '20%',
      badgeColor: 'bg-orange-50 text-orange-700 ring-orange-600/20',
      minKey: 'tanzania_paye_slab_3_min', maxKey: 'tanzania_paye_slab_3_max', rateKey: 'tanzania_paye_slab_3_rate',
      hint: t('Mid rate band — TSh 520,001 to 760,000'),
    },
    {
      label: t('Slab 4'), badge: '25%',
      badgeColor: 'bg-orange-50 text-orange-700 ring-orange-600/20',
      minKey: 'tanzania_paye_slab_4_min', maxKey: 'tanzania_paye_slab_4_max', rateKey: 'tanzania_paye_slab_4_rate',
      hint: t('Upper mid band — TSh 760,001 to 1,000,000'),
    },
    {
      label: t('Slab 5'), badge: '30%',
      badgeColor: 'bg-red-50 text-red-700 ring-red-600/20',
      minKey: 'tanzania_paye_slab_5_min', maxKey: null, rateKey: 'tanzania_paye_slab_5_rate',
      hint: t('Top rate band — TSh 1,000,001 and above'),
    },
  ];

  return (
    <form onSubmit={handleSubmit} className="space-y-6">

      {/* Header */}
      <div className="flex items-start gap-3 p-4 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800">
        <Info className="h-4 w-4 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0" />
        <div className="text-sm text-blue-800 dark:text-blue-200">
          <p className="font-medium">{t('Tanzania Revenue Authority (TRA) — Official Tax Rates')}</p>
          <p className="text-xs mt-0.5 text-blue-600 dark:text-blue-300">
            {t('All values are per month in TSh. Changes take effect on the next payroll run.')}
          </p>
          {ro && (
            <p className="text-xs mt-1 font-medium text-blue-700 dark:text-blue-200">
              {t('These rates are managed centrally by the Super Admin and apply to all Tanzania companies. Shown here for reference only.')}
            </p>
          )}
        </div>
      </div>

      {/* PAYE Tax Slabs */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <CardTitle className="text-base font-semibold">{t('PAYE Tax Slabs (Monthly TSh)')}</CardTitle>
            <span className="text-xs text-muted-foreground">{t('Progressive income tax — TRA official bands')}</span>
          </div>
        </CardHeader>
        <CardContent className="space-y-0 divide-y divide-border">
          {slabs.map((slab, index) => (
            <div key={slab.rateKey} className="py-4 first:pt-0 last:pb-0">
              <div className="mb-3 flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{slab.label}</span>
                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${slab.badgeColor}`}>
                  {slab.badge}
                </span>
                <span className="text-xs text-muted-foreground">— {slab.hint}</span>
              </div>
              <div className={`grid grid-cols-1 gap-4 ${slab.maxKey ? 'md:grid-cols-3' : 'md:grid-cols-2'}`}>
                <Field
                  label={t('Min (TSh)')}
                  fieldKey={slab.minKey}
                  value={form[slab.minKey as keyof typeof form]}
                  error={errors[slab.minKey]}
                  onChange={handleChange}
                  readOnly={ro || index === 0}
                />
                {slab.maxKey && (
                  <Field
                    label={t('Max (TSh)')}
                    fieldKey={slab.maxKey}
                    value={form[slab.maxKey as keyof typeof form]}
                    error={errors[slab.maxKey]}
                    onChange={handleChange}
                    readOnly={ro}
                  />
                )}
                <Field
                  label={t('Rate (%)')}
                  fieldKey={slab.rateKey}
                  value={form[slab.rateKey as keyof typeof form]}
                  error={errors[slab.rateKey]}
                  onChange={handleChange}
                  readOnly={ro}
                />
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      {/* NSSF */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <CardTitle className="text-base font-semibold">{t('NSSF Settings')}</CardTitle>
            <span className="text-xs text-muted-foreground">{t('National Social Security Fund')}</span>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Field
              label={t('Employee Rate (%)')}
              fieldKey="tanzania_nssf_employee_rate"
              value={form.tanzania_nssf_employee_rate}
              error={errors.tanzania_nssf_employee_rate}
              onChange={handleChange}
              readOnly={ro}
              hint={t('Default: 10% of monthly gross')}
            />
            <Field
              label={t('Employer Rate (%)')}
              fieldKey="tanzania_nssf_employer_rate"
              value={form.tanzania_nssf_employer_rate}
              error={errors.tanzania_nssf_employer_rate}
              onChange={handleChange}
              readOnly={ro}
              hint={t('Default: 10% of monthly gross')}
            />
          </div>
          <div className="mt-4 p-3 rounded-md bg-muted/40 text-xs text-muted-foreground flex items-start gap-2">
            <Info className="h-3.5 w-3.5 mt-0.5 shrink-0" />
            {t('Total NSSF contribution = 20% (10% employee + 10% employer). No salary ceiling applies in Tanzania.')}
          </div>
        </CardContent>
      </Card>

      {/* SDL */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <CardTitle className="text-base font-semibold">{t('SDL Settings')}</CardTitle>
            <span className="text-xs text-muted-foreground">{t('Skills Development Levy')}</span>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Field
              label={t('Employer Rate (%)')}
              fieldKey="tanzania_sdl_rate"
              value={form.tanzania_sdl_rate}
              error={errors.tanzania_sdl_rate}
              onChange={handleChange}
              readOnly={ro}
              hint={t('Default: 3.5% of monthly payroll')}
            />
            <Field
              label={t('Applies from (employees)')}
              fieldKey="tanzania_sdl_employee_threshold"
              value={form.tanzania_sdl_employee_threshold}
              error={errors.tanzania_sdl_employee_threshold}
              onChange={handleChange}
              readOnly={ro}
              hint={t('SDL only applies when the company has this many employees or more')}
            />
          </div>
          <div className="mt-4 p-3 rounded-md bg-muted/40 text-xs text-muted-foreground flex items-start gap-2">
            <Info className="h-3.5 w-3.5 mt-0.5 shrink-0" />
            {t('SDL is employer only. Employees do not contribute. TRA rule: only companies with 10+ employees are liable.')}
          </div>
        </CardContent>
      </Card>

      {/* WCF */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <CardTitle className="text-base font-semibold">{t('WCF Settings')}</CardTitle>
            <span className="text-xs text-muted-foreground">{t('Workers Compensation Fund')}</span>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Field
              label={t('Employer Rate (%)')}
              fieldKey="tanzania_wcf_rate"
              value={form.tanzania_wcf_rate}
              error={errors.tanzania_wcf_rate}
              onChange={handleChange}
              readOnly={ro}
              hint={t('Default: 0.5% of monthly payroll (unified rate)')}
            />
            <div className="flex items-center">
              <div className="p-3 rounded-md bg-muted/40 text-xs text-muted-foreground flex items-start gap-2 w-full">
                <Info className="h-3.5 w-3.5 mt-0.5 shrink-0" />
                {t('WCF is employer only. Workplace injury insurance. Unified rate across sectors.')}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Submit */}
      <div className="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          {saved && (
            <span className="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
              <CheckCircle2 className="h-4 w-4" />
              {t('Settings saved successfully')}
            </span>
          )}
          {Object.keys(errors).length > 0 && (
            <span className="flex items-center gap-1.5 text-sm text-red-500">
              <AlertCircle className="h-4 w-4" />
              {t('Please fix the errors above')}
            </span>
          )}
        </div>
        {!ro && (
          <Button type="submit" disabled={isSubmitting} className="w-full sm:w-auto sm:min-w-40">
            {isSubmitting ? (
              <span className="flex items-center gap-2">
                <span className="h-3.5 w-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                {t('Saving...')}
              </span>
            ) : (
              t('Save Tanzania Tax Settings')
            )}
          </Button>
        )}
      </div>

    </form>
  );
}
