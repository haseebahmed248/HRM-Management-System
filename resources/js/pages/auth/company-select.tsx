import { useForm } from '@inertiajs/react';
import { Building2, ChevronRight } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { useTranslation } from 'react-i18next';
import AuthLayout from '@/layouts/auth-layout';
import AuthButton from '@/components/auth/auth-button';
import { useBrand } from '@/contexts/BrandContext';
import { THEME_COLORS } from '@/hooks/use-appearance';

interface CompanyOption {
    user_id: number;
    company_name: string;
    role: string;
}

interface CompanySelectProps {
    companies: CompanyOption[];
}

export default function CompanySelect({ companies = [] }: CompanySelectProps) {
    const { t } = useTranslation();
    const { themeColor, customColor } = useBrand();
    const primaryColor = themeColor === 'custom' ? customColor : THEME_COLORS[themeColor as keyof typeof THEME_COLORS];

    const [selected, setSelected] = useState<number | null>(companies.length ? companies[0].user_id : null);

    const { setData, post, processing, errors } = useForm<{ user_id: number | null }>({
        user_id: companies.length ? companies[0].user_id : null,
    });

    const choose = (userId: number) => {
        setSelected(userId);
        setData('user_id', userId);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login.company-select.store'));
    };

    return (
        <AuthLayout
            title={t('Select a company')}
            description={t('Your account is linked to more than one company. Choose which one to work in.')}
        >
            <form className="space-y-5" onSubmit={submit}>
                <div className="space-y-3">
                    {companies.map((company) => {
                        const isActive = selected === company.user_id;
                        return (
                            <button
                                type="button"
                                key={company.user_id}
                                onClick={() => choose(company.user_id)}
                                className="flex w-full items-center gap-3 rounded-lg border p-4 text-left transition-colors"
                                style={{
                                    borderColor: isActive ? primaryColor : undefined,
                                    backgroundColor: isActive ? `${primaryColor}12` : undefined,
                                }}
                            >
                                <span
                                    className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full"
                                    style={{ backgroundColor: `${primaryColor}1f`, color: primaryColor }}
                                >
                                    <Building2 className="h-5 w-5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium text-gray-900">{company.company_name}</span>
                                    <span className="block text-sm text-gray-500">{t('Signed in as')} {company.role}</span>
                                </span>
                                <ChevronRight
                                    className="h-5 w-5 flex-shrink-0"
                                    style={{ color: isActive ? primaryColor : '#9ca3af' }}
                                />
                            </button>
                        );
                    })}
                </div>

                <InputError message={errors.user_id} />

                <AuthButton type="submit" processing={processing} disabled={selected === null}>
                    {t('Continue')}
                </AuthButton>
            </form>
        </AuthLayout>
    );
}
