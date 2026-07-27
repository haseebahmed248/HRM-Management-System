// pages/hr/financial-years/audit-log.tsx
import { useState } from 'react';
import { PageTemplate } from '@/components/page-template';
import { usePage, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Pagination } from '@/components/ui/pagination';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';

const ACTION_STYLES: Record<string, string> = {
  created: 'bg-green-50 text-green-700 ring-green-600/20',
  updated: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  closed: 'bg-red-50 text-red-700 ring-red-600/20',
  reopened: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  set_current: 'bg-purple-50 text-purple-700 ring-purple-600/20',
  deleted: 'bg-gray-100 text-gray-700 ring-gray-500/20',
};

export default function FinancialYearAuditLog() {
  const { t } = useTranslation();
  const { audits, filters: pageFilters = {} } = usePage().props as any;

  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [selectedAction, setSelectedAction] = useState(pageFilters.action || 'all');
  const [showFilters, setShowFilters] = useState(false);

  const hasActiveFilters = () => searchTerm !== '' || selectedAction !== 'all';
  const activeFilterCount = () => (searchTerm ? 1 : 0) + (selectedAction !== 'all' ? 1 : 0);

  const applyFilters = () => {
    router.get(route('hr.financial-years.audit-log'), {
      page: 1,
      search: searchTerm || undefined,
      action: selectedAction !== 'all' ? selectedAction : undefined,
      per_page: pageFilters.per_page,
    }, { preserveState: true, preserveScroll: true });
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters();
  };

  const handleResetFilters = () => {
    setSearchTerm('');
    setSelectedAction('all');
    setShowFilters(false);
    router.get(route('hr.financial-years.audit-log'), { page: 1 }, { preserveState: true, preserveScroll: true });
  };

  const actionOptions = [
    { value: 'all', label: t('All Actions') },
    { value: 'created', label: t('Created') },
    { value: 'updated', label: t('Updated') },
    { value: 'closed', label: t('Closed') },
    { value: 'reopened', label: t('Reopened') },
    { value: 'set_current', label: t('Set Current') },
    { value: 'deleted', label: t('Deleted') },
  ];

  const breadcrumbs = [
    { title: t('Dashboard'), href: route('dashboard') },
    { title: t('Financial Years'), href: route('hr.financial-years.index') },
    { title: t('Audit Trail') },
  ];

  const pageActions = [{
    label: t('Back to Financial Years'),
    icon: <ArrowLeft className="h-4 w-4 mr-2" />,
    variant: 'outline',
    onClick: () => router.get(route('hr.financial-years.index')),
  }];

  const fmt = (v: string) => window.appSettings?.formatDateTimeSimple?.(v, true) || new Date(v).toLocaleString();

  return (
    <PageTemplate title={t('Period Change Audit Trail')} url="/hr/financial-years/audit-log" actions={pageActions} breadcrumbs={breadcrumbs} noPadding>
      <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 p-4">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={handleSearch}
          filters={[{
            name: 'action',
            label: t('Action'),
            type: 'select',
            value: selectedAction,
            onChange: setSelectedAction,
            options: actionOptions,
          }]}
          showFilters={showFilters}
          setShowFilters={setShowFilters}
          hasActiveFilters={hasActiveFilters}
          activeFilterCount={activeFilterCount}
          onResetFilters={handleResetFilters}
          onApplyFilters={applyFilters}
          currentPerPage={pageFilters.per_page?.toString() || '15'}
          onPerPageChange={(value) => {
            router.get(route('hr.financial-years.audit-log'), {
              page: 1,
              per_page: parseInt(value),
              search: searchTerm || undefined,
              action: selectedAction !== 'all' ? selectedAction : undefined,
            }, { preserveState: true, preserveScroll: true });
          }}
        />
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-gray-700 text-left text-gray-500 dark:text-gray-400">
                <th className="px-4 py-3 font-medium">{t('Date & Time')}</th>
                <th className="px-4 py-3 font-medium">{t('Financial Period')}</th>
                <th className="px-4 py-3 font-medium">{t('Action')}</th>
                <th className="px-4 py-3 font-medium">{t('Performed By')}</th>
                <th className="px-4 py-3 font-medium">{t('Details')}</th>
              </tr>
            </thead>
            <tbody>
              {(audits?.data || []).length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-500">{t('No period changes recorded yet.')}</td></tr>
              ) : (
                (audits?.data || []).map((a: any) => (
                  <tr key={a.id} className="border-b border-gray-100 dark:border-gray-800">
                    <td className="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{fmt(a.created_at)}</td>
                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{a.financial_year_name}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${ACTION_STYLES[a.action] || 'bg-gray-100 text-gray-700 ring-gray-500/20'}`}>
                        {t(a.action.replace('_', ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()))}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{a.performed_by_name || '—'}</td>
                    <td className="px-4 py-3 text-gray-600 dark:text-gray-400">{a.description || '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          from={audits?.from || 0}
          to={audits?.to || 0}
          total={audits?.total || 0}
          links={audits?.links}
          entityName={t('audit records')}
          onPageChange={(url) => router.get(url)}
        />
      </div>
    </PageTemplate>
  );
}
