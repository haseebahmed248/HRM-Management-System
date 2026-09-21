import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/components/custom-toast';
import { Globe, Info } from 'lucide-react';
import { useState } from 'react';

/**
 * Company country picker. Sets which payroll calculator runs and (when
 * "apply currency" is on) stamps the matching currency settings so the
 * payslip and CSV exports render in the right currency out of the box.
 *
 * Kept intentionally simple: 2 radio options + one submit button. If the
 * client's admin selects Tanzania, TSh / TZS is applied automatically.
 */
interface CompanyCountrySettingsProps {
  currentCountry: string;
}

export default function CompanyCountrySettings({ currentCountry }: CompanyCountrySettingsProps) {
  const { t } = useTranslation();
  const [country, setCountry]   = useState(currentCountry || 'ZM');
  const [applyCurrency, setAC]  = useState(true);
  const [isSubmitting, setSub]  = useState(false);

  const options = [
    {
      code: 'ZM',
      label: t('Zambia'),
      hint: t('PAYE / NAPSA / NHIMA / SDL. Currency: ZMW (K).'),
    },
    {
      code: 'TZ',
      label: t('Tanzania'),
      hint: t('PAYE / NSSF / SDL / WCF. Currency: TZS (TSh).'),
    },
  ];

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSub(true);
    router.post(
      route('settings.company-country.update'),
      { country_code: country, apply_currency: applyCurrency },
      {
        onSuccess: (page: any) => {
          setSub(false);
          if (page.props.flash?.success) {
            toast.success(t(page.props.flash.success));
          } else {
            toast.success(t('Company country updated'));
          }
        },
        onError: () => {
          setSub(false);
          toast.error(t('Failed to update company country'));
        },
      },
    );
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              <Globe className="h-4 w-4" />
              {t('Company Country')}
            </CardTitle>
            <span className="text-xs text-muted-foreground">
              {t('Drives which statutory rules and currency the payroll uses')}
            </span>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {options.map((opt) => (
              <label
                key={opt.code}
                className={`cursor-pointer rounded-lg border p-4 transition-all ${
                  country === opt.code
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/40'
                }`}
              >
                <div className="flex items-start gap-3">
                  <input
                    type="radio"
                    name="country_code"
                    value={opt.code}
                    checked={country === opt.code}
                    onChange={() => setCountry(opt.code)}
                    className="mt-1"
                  />
                  <div className="flex-1">
                    <div className="text-sm font-medium">{opt.label}</div>
                    <div className="text-xs text-muted-foreground mt-1">{opt.hint}</div>
                  </div>
                </div>
              </label>
            ))}
          </div>

          <div className="flex items-start gap-2 rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
            <Info className="h-3.5 w-3.5 mt-0.5 shrink-0" />
            <div>
              {t('Auto-applying the currency also updates the currency symbol, code, position, and decimals for this company. Uncheck to keep the current currency settings.')}
            </div>
          </div>

          <div className="flex items-center gap-2">
            <input
              id="apply_currency"
              type="checkbox"
              checked={applyCurrency}
              onChange={(e) => setAC(e.target.checked)}
            />
            <Label htmlFor="apply_currency" className="text-sm">
              {t('Auto-apply currency settings for this country')}
            </Label>
          </div>
        </CardContent>
      </Card>

      <div className="flex items-center justify-end">
        <Button type="submit" disabled={isSubmitting} className="min-w-40">
          {isSubmitting ? t('Saving...') : t('Save Country Settings')}
        </Button>
      </div>
    </form>
  );
}
