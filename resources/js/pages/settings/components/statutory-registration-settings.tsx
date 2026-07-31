import { toast } from '@/components/custom-toast';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { BadgeCheck, Building2, Loader2 } from 'lucide-react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

interface StatutoryRegistrationSettingsProps {
    settings: Record<string, string>;
}

const fields = [
    {
        key: 'employer_napsa_number',
        label: 'Employer NAPSA number',
        placeholder: 'Enter employer account number',
    },
    {
        key: 'company_nhima_number',
        label: 'Company NHIMA number',
        placeholder: 'Enter company NHIMA number',
    },
    {
        key: 'employer_tpin',
        label: 'Employer TPIN',
        placeholder: 'Enter employer TPIN',
    },
] as const;

export default function StatutoryRegistrationSettings({ settings }: StatutoryRegistrationSettingsProps) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, recentlySuccessful } = useForm({
        employer_napsa_number: settings.employer_napsa_number ?? '',
        company_nhima_number: settings.company_nhima_number ?? '',
        employer_tpin: settings.employer_tpin ?? '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post(route('settings.statutory-registration.update'), {
            preserveScroll: true,
            onSuccess: () => toast.success(t('Statutory registration details saved')),
            onError: () => toast.error(t('Please correct the highlighted fields')),
        });
    };

    return (
        <Card className="overflow-hidden border-border/80">
            <form onSubmit={handleSubmit}>
                <CardHeader className="border-b bg-muted/25 px-4 py-4 sm:px-6">
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border bg-background text-primary">
                            <Building2 className="h-4 w-4" />
                        </div>
                        <div className="min-w-0">
                            <CardTitle className="text-base">{t('Statutory registration')}</CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('Company identifiers included in statutory portal import files.')}
                            </p>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="space-y-5 px-4 py-5 sm:px-6">
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        {fields.map((field) => (
                            <div key={field.key} className="min-w-0 space-y-2">
                                <Label htmlFor={field.key}>{t(field.label)}</Label>
                                <Input
                                    id={field.key}
                                    value={data[field.key]}
                                    onChange={(event) => setData(field.key, event.target.value)}
                                    placeholder={t(field.placeholder)}
                                    aria-invalid={Boolean(errors[field.key])}
                                    className={errors[field.key] ? 'border-destructive focus-visible:ring-destructive' : ''}
                                />
                                {errors[field.key] && <p className="text-xs text-destructive">{errors[field.key]}</p>}
                            </div>
                        ))}
                    </div>

                    <div className="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-h-5 text-sm text-emerald-700 dark:text-emerald-400">
                            {recentlySuccessful && (
                                <span className="inline-flex items-center gap-1.5">
                                    <BadgeCheck className="h-4 w-4" />
                                    {t('Registration details saved')}
                                </span>
                            )}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full sm:w-auto">
                            {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            {t('Save registration details')}
                        </Button>
                    </div>
                </CardContent>
            </form>
        </Card>
    );
}
