import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { useState } from 'react';
import { Save } from 'lucide-react';
import { SettingsSection } from '@/components/settings-section';
import { Card, CardContent } from '@/components/ui/card';
import { useTranslation } from 'react-i18next';
import { router, usePage } from '@inertiajs/react';
import { toast } from '@/components/custom-toast';

interface EmployeeIdSettingsProps {
  settings?: Record<string, any>;
}

export default function EmployeeIdSettings({ settings = {} }: EmployeeIdSettingsProps) {
  const { t } = useTranslation();
  const { globalSettings } = usePage().props as any;

  const [prefix, setPrefix] = useState<string>(settings.employee_id_prefix ?? 'EMP');
  const [padding, setPadding] = useState<string>(String(settings.employee_id_padding ?? 6));

  const paddingNum = Math.max(1, Math.min(12, parseInt(padding || '6', 10) || 6));
  const nextNumber = '1'.padStart(paddingNum, '0');
  const preview = `${prefix}${nextNumber}`;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!globalSettings?.is_demo) {
      toast.loading(t('Saving employee ID settings...'));
    }

    router.post(route('settings.employee-id.update'), {
      employee_id_prefix: prefix,
      employee_id_padding: paddingNum,
    }, {
      preserveScroll: true,
      onSuccess: (page) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        const successMessage = page.props.flash?.success;
        const errorMessage = page.props.flash?.error;
        if (successMessage) {
          toast.success(successMessage);
        } else if (errorMessage) {
          toast.error(errorMessage);
        } else {
          toast.success(t('Employee ID settings saved successfully'));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        const errorMessage = errors.error || Object.values(errors).join(', ') || t('Failed to save employee ID settings');
        toast.error(errorMessage);
      }
    });
  };

  return (
    <SettingsSection
      title={t('Employee ID Format')}
      description={t('Set the prefix and number length used when auto-generating employee IDs for this company. You can still type a custom ID on the employee form.')}
      action={
        <Button type="submit" form="employee-id-form" size="sm">
          <Save className="h-4 w-4 mr-2" />
          {t('Save Changes')}
        </Button>
      }
    >
      <Card>
        <CardContent className="pt-6">
          <form id="employee-id-form" onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label htmlFor="employee_id_prefix">{t('Prefix')}</Label>
                <Input
                  id="employee_id_prefix"
                  value={prefix}
                  maxLength={10}
                  onChange={(e) => setPrefix(e.target.value)}
                  placeholder="EMP"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="employee_id_padding">{t('Number Length')}</Label>
                <Input
                  id="employee_id_padding"
                  type="number"
                  min={1}
                  max={12}
                  value={padding}
                  onChange={(e) => setPadding(e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('Preview')}</Label>
                <div className="flex h-9 items-center rounded-md border bg-muted px-3 font-mono text-sm">
                  {preview}
                </div>
              </div>
            </div>
          </form>
        </CardContent>
      </Card>
    </SettingsSection>
  );
}
