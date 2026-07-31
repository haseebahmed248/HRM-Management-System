import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ReactNode } from 'react';

interface SettingsSectionProps {
    title: string;
    description?: string;
    children: ReactNode;
    action?: ReactNode;
}

export function SettingsSection({ title, description, children, action }: SettingsSectionProps) {
    return (
        <Card className="mb-6 min-w-0 overflow-hidden">
            <CardHeader className="px-4 pb-3 sm:px-6">
                <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <CardTitle className="text-lg font-medium">{title}</CardTitle>
                        {description && <CardDescription className="mt-1.5 max-w-3xl text-pretty">{description}</CardDescription>}
                    </div>
                    {action && <div className="w-full shrink-0 sm:w-auto [&>*]:w-full sm:[&>*]:w-auto">{action}</div>}
                </div>
            </CardHeader>
            <CardContent className="min-w-0 px-4 sm:px-6">{children}</CardContent>
        </Card>
    );
}
