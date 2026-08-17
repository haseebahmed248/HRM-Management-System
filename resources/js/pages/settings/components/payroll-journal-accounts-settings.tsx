import { toast } from '@/components/custom-toast';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { Loader2, Save } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface PayrollJournalAccountsSettingsProps {
    accounts: Record<string, string>;
    labels: Record<string, string>;
}

export default function PayrollJournalAccountsSettings({ accounts, labels }: PayrollJournalAccountsSettingsProps) {
    const { t } = useTranslation();
    const { globalSettings } = usePage().props as { globalSettings?: { is_demo?: boolean } };
    const [form, setForm] = useState<Record<string, string>>({ ...accounts });
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setProcessing(true);
        if (!globalSettings?.is_demo) {
            toast.loading(t('Saving account codes...'));
        }

        router.post(
            route('settings.payroll-journal-accounts.update'),
            form as unknown as Record<string, FormDataConvertible>,
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    toast.dismiss();
                    const flash = page.props.flash as { success?: string; error?: string } | undefined;
                    if (flash?.error) {
                        toast.error(flash.error);
                    } else {
                        toast.success(flash?.success ?? t('Account codes updated successfully'));
                    }
                },
                onError: (errors) => {
                    toast.dismiss();
                    toast.error(Object.values(errors)[0] ?? t('Please correct the highlighted fields'));
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SettingsSection
            title={t('Payroll Journal Account Codes')}
            description={t('GL codes for Basic Pay and the statutory lines (PAYE, NAPSA, NHIMA, SDL and their payables/expenses) shown on the Payroll Summary Journal report. Individual earning/deduction components carry their own code on the component form.')}
        >
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {Object.keys(labels).map((key) => (
                        <div key={key} className="space-y-1.5">
                            <Label htmlFor={`journal-account-${key}`}>{t(labels[key])}</Label>
                            <Input
                                id={`journal-account-${key}`}
                                value={form[key] ?? ''}
                                onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}
                                placeholder={t('e.g. 600-110')}
                            />
                        </div>
                    ))}
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={processing}>
                        {processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                        {t('Save Changes')}
                    </Button>
                </div>
            </form>
        </SettingsSection>
    );
}
