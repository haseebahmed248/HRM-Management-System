import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Home } from 'lucide-react';

interface ErrorPageProps {
  status: number;
  title?: string;
  message?: string;
}

const DEFAULTS: Record<number, { titleKey: string; messageKey: string }> = {
  403: { titleKey: 'Forbidden', messageKey: 'You do not have permission to view this page.' },
  404: { titleKey: 'Page Not Found', messageKey: 'The page you were looking for could not be found.' },
  419: { titleKey: 'Page Expired', messageKey: 'Your session has expired. Please refresh and try again.' },
  500: { titleKey: 'Server Error', messageKey: 'Something went wrong on our side. The team has been notified.' },
  503: { titleKey: 'Service Unavailable', messageKey: 'The service is temporarily unavailable. Please try again in a moment.' },
};

export default function ErrorPage({ status, title, message }: ErrorPageProps) {
  const { t } = useTranslation();
  const fallback = DEFAULTS[status] ?? { titleKey: 'Error', messageKey: 'An unexpected error occurred.' };
  const displayTitle = title ?? t(fallback.titleKey);
  const displayMessage = message ?? t(fallback.messageKey);

  return (
    <>
      <Head title={`${status} — ${displayTitle}`} />
      <div className="min-h-screen flex flex-col items-center justify-center bg-background text-foreground px-6 py-12">
        <div className="w-full max-w-md text-center">
          <div className="inline-flex size-16 items-center justify-center rounded-2xl bg-muted/60 mb-6">
            <AlertTriangle className="size-7 text-muted-foreground" strokeWidth={1.75} />
          </div>
          <div className="text-6xl font-bold tracking-tight text-muted-foreground/80 tabular-nums">{status}</div>
          <h1 className="mt-4 text-2xl font-semibold">{displayTitle}</h1>
          <p className="mt-2 text-muted-foreground max-w-sm mx-auto">{displayMessage}</p>
          <Link
            href="/"
            className="mt-8 inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
          >
            <Home className="size-4" />
            {t('Back to dashboard')}
          </Link>
        </div>
      </div>
    </>
  );
}
