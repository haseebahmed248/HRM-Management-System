// pages/hr/audit-trail/index.tsx  (item 8 - Audit Trail Report)
import { useState } from 'react';
import { PageTemplate } from '@/components/page-template';
import { usePage, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Pagination } from '@/components/ui/pagination';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';

const CATEGORY_LABEL: Record<string, string> = {
  employee: 'Employee Change',
  employee_deleted: 'Employee Deleted',
  system: 'System Change',
};
const CATEGORY_STYLE: Record<string, string> = {
  employee: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  employee_deleted: 'bg-red-50 text-red-700 ring-red-600/20',
  system: 'bg-purple-50 text-purple-700 ring-purple-600/20',
};

export default function AuditTrail() {
  const { t } = useTranslation();
  const { logs, filters: pageFilters = {} } = usePage().props as any;

  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [category, setCategory] = useState(pageFilters.category || 'all');
  const [showFilters, setShowFilters] = useState(false);

  const hasActiveFilters = () => searchTerm !== '' || category !== 'all';
  const activeFilterCount = () => (searchTerm ? 1 : 0) + (category !== 'all' ? 1 : 0);

  const applyFilters = () => {
    router.get(route('hr.audit-trail.index'), {
      page: 1,
      search: searchTerm || undefined,
      category: category !== 'all' ? category : undefined,
      per_page: pageFilters.per_page,
    }, { preserveState: true, preserveScroll: true });
  };

  const handleSearch = (e: React.FormEvent) => { e.preventDefault(); applyFilters(); };
  const handleResetFilters = () => {
    setSearchTerm(''); setCategory('all'); setShowFilters(false);
    router.get(route('hr.audit-trail.index'), { page: 1 }, { preserveState: true, preserveScroll: true });
  };

  const categoryOptions = [
    { value: 'all', label: t('All Changes') },
    { value: 'employee', label: t('Employee Changes') },
    { value: 'employee_deleted', label: t('Deleted Employees') },
    { value: 'system', label: t('System Changes') },
  ];

  const breadcrumbs = [
    { title: t('Dashboard'), href: route('dashboard') },
    { title: t('Reports'), href: route('hr.zambia-reports.index') },
    { title: t('Audit Trail') },
  ];

  const pageActions = [{
    label: t('Back to Reports'),
    icon: <ArrowLeft className="h-4 w-4 mr-2" />,
    variant: 'outline',
    onClick: () => router.get(route('hr.zambia-reports.index')),
  }];

  const fmt = (v: string) => window.appSettings?.formatDateTimeSimple?.(v, true) || new Date(v).toLocaleString();

  return (
    <PageTemplate title={t('Audit Trail')} url="/hr/audit-trail" actions={pageActions} breadcrumbs={breadcrumbs} noPadding>
      <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 p-4">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={handleSearch}
          filters={[{
            name: 'category', label: t('Change Type'), type: 'select',
            value: category, onChange: setCategory, options: categoryOptions,
          }]}
          showFilters={showFilters}
          setShowFilters={setShowFilters}
          hasActiveFilters={hasActiveFilters}
          activeFilterCount={activeFilterCount}
          onResetFilters={handleResetFilters}
          onApplyFilters={applyFilters}
          currentPerPage={pageFilters.per_page?.toString() || '20'}
          onPerPageChange={(value) => {
            router.get(route('hr.audit-trail.index'), {
              page: 1, per_page: parseInt(value),
              search: searchTerm || undefined,
              category: category !== 'all' ? category : undefined,
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
                <th className="px-4 py-3 font-medium">{t('Type')}</th>
                <th className="px-4 py-3 font-medium">{t('Subject')}</th>
                <th className="px-4 py-3 font-medium">{t('Details')}</th>
                <th className="px-4 py-3 font-medium">{t('Performed By')}</th>
              </tr>
            </thead>
            <tbody>
              {(logs?.data || []).length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-500">{t('No changes recorded yet.')}</td></tr>
              ) : (
                (logs?.data || []).map((l: any) => (
                  <tr key={l.id} className="border-b border-gray-100 dark:border-gray-800 align-top">
                    <td className="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{fmt(l.created_at)}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${CATEGORY_STYLE[l.category] || 'bg-gray-100 text-gray-700 ring-gray-500/20'}`}>
                        {t(CATEGORY_LABEL[l.category] || l.category)}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{l.subject || '—'}</td>
                    <td className="px-4 py-3 text-gray-600 dark:text-gray-400">{l.description || '—'}</td>
                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">{l.performed_by_name || '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          from={logs?.from || 0}
          to={logs?.to || 0}
          total={logs?.total || 0}
          links={logs?.links}
          entityName={t('audit records')}
          onPageChange={(url) => router.get(url)}
        />
      </div>
    </PageTemplate>
  );
}
