import { toast } from '@/components/custom-toast';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { FileText, Loader2, Save } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

type EmployeeFieldKey =
    | 'employee_name'
    | 'nrc'
    | 'designation'
    | 'pay_period'
    | 'tpin'
    | 'napsa_number'
    | 'nhima_number'
    | 'date_of_joining'
    | 'bank_name'
    | 'account_number';

interface EmployeeFieldConfig {
    key: EmployeeFieldKey;
    label: string;
    show: boolean;
}

export interface PayslipTemplateConfig {
    logo: { show: boolean };
    qr: { show: boolean };
    header: {
        title: string;
        show_company_email: boolean;
        show_company_phone: boolean;
    };
    footer: { text: string };
    sections: {
        employee_info: boolean;
        leave: boolean;
        earnings_deductions: boolean;
        employer_contributions: boolean;
    };
    employee_fields: EmployeeFieldConfig[];
}

interface PayslipTemplateSettingsProps {
    config: PayslipTemplateConfig;
}

type SectionKey = keyof PayslipTemplateConfig['sections'];

export default function PayslipTemplateSettings({ config }: PayslipTemplateSettingsProps) {
    const { t } = useTranslation();
    const { globalSettings } = usePage().props as { globalSettings?: { is_demo?: boolean } };
    const [form, setForm] = useState<PayslipTemplateConfig>(config);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const sectionOptions: Array<{ key: SectionKey; label: string }> = [
        { key: 'employee_info', label: t('Employee information') },
        { key: 'leave', label: t('Leave details') },
        { key: 'earnings_deductions', label: t('Earnings and deductions') },
        { key: 'employer_contributions', label: t('Employer contributions') },
    ];

    const updateEmployeeField = (index: number, changes: Partial<EmployeeFieldConfig>) => {
        setForm((current) => ({
            ...current,
            employee_fields: current.employee_fields.map((field, fieldIndex) =>
                fieldIndex === index ? { ...field, ...changes } : field,
            ),
        }));
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        if (!globalSettings?.is_demo) {
            toast.loading(t('Saving payslip template...'));
        }

        router.post(route('settings.payslip-template.update'), form as unknown as Record<string, FormDataConvertible>, {
            preserveScroll: true,
            onSuccess: (page) => {
                toast.dismiss();
                const flash = page.props.flash as { success?: string; error?: string } | undefined;
                const message = flash?.success ?? flash?.error;
                if (flash?.error) {
                    toast.error(message);
                } else {
                    toast.success(message ?? t('Payslip template updated successfully'));
                }
            },
            onError: (validationErrors) => {
                toast.dismiss();
                setErrors(validationErrors);
                toast.error(Object.values(validationErrors)[0] ?? t('Please correct the highlighted fields'));
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <SettingsSection
            title={t('Payslip Template')}
            description={t('Choose the information and labels used in previewed, printed, downloaded, and emailed payslips.')}
            action={
                <Button type="submit" form="payslip-template-form" size="sm" disabled={processing}>
                    {processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                    {t('Save Changes')}
                </Button>
            }
        >
            <form id="payslip-template-form" onSubmit={handleSubmit} className="min-w-0 space-y-7">
                <div className="grid min-w-0 gap-5 border-y py-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.7fr)]">
                    <div className="min-w-0 space-y-2">
                        <Label htmlFor="payslip-header-title">{t('Document title')}</Label>
                        <Input
                            id="payslip-header-title"
                            value={form.header.title}
                            onChange={(event) => setForm((current) => ({
                                ...current,
                                header: { ...current.header, title: event.target.value },
                            }))}
                            aria-invalid={Boolean(errors['header.title'])}
                        />
                        {errors['header.title'] && <p className="text-xs text-destructive">{errors['header.title']}</p>}
                    </div>

                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1">
                        {[
                            { key: 'logo' as const, label: t('Company logo') },
                            { key: 'qr' as const, label: t('ESS portal QR code') },
                        ].map((item) => (
                            <div key={item.key} className="flex min-h-11 items-center justify-between gap-3 rounded-md border px-3 py-2">
                                <span className="text-sm font-medium">{item.label}</span>
                                <Switch
                                    checked={form[item.key].show}
                                    onCheckedChange={(checked) => setForm((current) => ({
                                        ...current,
                                        [item.key]: { show: checked },
                                    }))}
                                    aria-label={item.label}
                                />
                            </div>
                        ))}
                    </div>
                </div>

                <div className="space-y-3">
                    <div className="flex items-center gap-2">
                        <FileText className="h-4 w-4 text-primary" />
                        <h3 className="text-sm font-semibold">{t('Visible sections')}</h3>
                    </div>
                    <div className="grid grid-cols-1 gap-px overflow-hidden rounded-md border bg-border sm:grid-cols-2">
                        {sectionOptions.map((section) => (
                            <div key={section.key} className="flex min-h-12 items-center justify-between gap-3 bg-background px-4 py-3">
                                <Label htmlFor={`payslip-section-${section.key}`} className="cursor-pointer text-sm">
                                    {section.label}
                                </Label>
                                <Switch
                                    id={`payslip-section-${section.key}`}
                                    checked={form.sections[section.key]}
                                    onCheckedChange={(checked) => setForm((current) => ({
                                        ...current,
                                        sections: { ...current.sections, [section.key]: checked },
                                    }))}
                                />
                            </div>
                        ))}
                    </div>
                </div>

                <div className="space-y-3">
                    <div>
                        <h3 className="text-sm font-semibold">{t('Employee information fields')}</h3>
                        <p className="mt-1 text-xs text-muted-foreground">{t('Labels appear exactly as entered on the payslip.')}</p>
                    </div>
                    <div className="overflow-hidden rounded-md border">
                        {form.employee_fields.map((field, index) => (
                            <div
                                key={field.key}
                                className="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-3 border-b px-3 py-3 last:border-b-0 sm:grid-cols-[150px_minmax(0,1fr)_auto] sm:px-4"
                            >
                                <span className="col-span-1 truncate text-xs font-medium uppercase text-muted-foreground sm:text-sm sm:normal-case">
                                    {t(field.key.replaceAll('_', ' '))}
                                </span>
                                <div className="col-span-2 min-w-0 sm:col-span-1">
                                    <Input
                                        value={field.label}
                                        onChange={(event) => updateEmployeeField(index, { label: event.target.value })}
                                        aria-label={t('Label for :field', { field: field.key.replaceAll('_', ' ') })}
                                        aria-invalid={Boolean(errors[`employee_fields.${index}.label`])}
                                    />
                                    {errors[`employee_fields.${index}.label`] && (
                                        <p className="mt-1 text-xs text-destructive">{errors[`employee_fields.${index}.label`]}</p>
                                    )}
                                </div>
                                <div className="col-start-2 row-start-1 flex justify-end sm:col-start-3">
                                    <Switch
                                        checked={field.show}
                                        onCheckedChange={(checked) => updateEmployeeField(index, { show: checked })}
                                        aria-label={t('Show :field', { field: field.label })}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="grid min-w-0 gap-5 border-t pt-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <div className="min-w-0 space-y-2">
                        <Label htmlFor="payslip-footer-text">{t('Footer text')}</Label>
                        <Textarea
                            id="payslip-footer-text"
                            value={form.footer.text}
                            onChange={(event) => setForm((current) => ({
                                ...current,
                                footer: { text: event.target.value },
                            }))}
                            rows={3}
                            aria-invalid={Boolean(errors['footer.text'])}
                        />
                        {errors['footer.text'] && <p className="text-xs text-destructive">{errors['footer.text']}</p>}
                    </div>
                    <div className="space-y-2">
                        {[
                            { key: 'show_company_email' as const, label: t('Company email') },
                            { key: 'show_company_phone' as const, label: t('Company phone') },
                        ].map((item) => (
                            <div key={item.key} className="flex min-h-11 items-center justify-between gap-3 rounded-md border px-3 py-2">
                                <span className="text-sm font-medium">{item.label}</span>
                                <Switch
                                    checked={form.header[item.key]}
                                    onCheckedChange={(checked) => setForm((current) => ({
                                        ...current,
                                        header: { ...current.header, [item.key]: checked },
                                    }))}
                                    aria-label={item.label}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </form>
        </SettingsSection>
    );
}
