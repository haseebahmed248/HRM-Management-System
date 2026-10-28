// pages/hr/employee-loans/index.tsx
import { useMemo, useState } from 'react';
import { PageTemplate } from '@/components/page-template';
import { usePage, router } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { hasPermission } from '@/utils/authorization';
import { CrudTable } from '@/components/CrudTable';
import { CrudDeleteModal } from '@/components/CrudDeleteModal';
import { toast } from '@/components/custom-toast';
import { useTranslation } from 'react-i18next';
import { Pagination } from '@/components/ui/pagination';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type LoanStatus = 'active' | 'paused' | 'completed' | 'cancelled';

interface Repayment {
  id: number;
  amount: number;
  paid_on: string;
  method: 'payroll' | 'manual';
  source: string;
  notes: string | null;
}

interface Loan {
  id: number;
  reference: string;
  employee_id: number;
  employee_name: string;
  employee_code: string;
  loan_type: 'advance' | 'loan';
  type_label: string;
  description: string | null;
  notes: string | null;
  amount: number;
  installments: number;
  installment_amount: number;
  disbursed_on: string;
  start_date: string;
  start_month: string;
  status: 'active' | 'paused' | 'cancelled';
  display_status: LoanStatus;
  repaid: number;
  balance: number;
  installments_left: number;
  has_repayments: boolean;
  repayments: Repayment[];
}

interface EmployeeOption {
  id: number;
  name: string;
  employee_id: string;
}

interface LoanForm {
  employee_id: string;
  loan_type: 'advance' | 'loan';
  amount: string;
  installments: string;
  disbursed_on: string;
  start_month: string;
  description: string;
  notes: string;
}

const today = () => new Date().toISOString().slice(0, 10);

const nextMonth = () => {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() + 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

const emptyForm = (): LoanForm => ({
  employee_id: '',
  loan_type: 'advance',
  amount: '',
  installments: '1',
  disbursed_on: today(),
  start_month: nextMonth(),
  description: '',
  notes: '',
});

const money = (value: number | string) =>
  window.appSettings?.formatCurrency(value) ?? Number(value).toFixed(2);

const formatDate = (value: string | null) =>
  value ? window.appSettings?.formatDateTimeSimple(value, false) || new Date(value).toLocaleDateString() : '';

const formatMonth = (value: string) => {
  if (!value) return '';
  const [y, m] = value.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleString('en-GB', { month: 'short', year: 'numeric' });
};

const statusStyles: Record<LoanStatus, string> = {
  active: 'bg-green-50 text-green-700 ring-green-600/20',
  paused: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  completed: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  cancelled: 'bg-gray-100 text-gray-600 ring-gray-500/20',
};

export default function EmployeeLoans() {
  const { t } = useTranslation();
  const { auth, loans, summary, employees = [], loanTypes = {}, filters: pageFilters = {} } = usePage().props as any;
  const permissions = auth?.permissions || [];
  const canEdit = hasPermission(permissions, 'edit-employee-loans');

  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [selectedStatus, setSelectedStatus] = useState(pageFilters.status || 'all');
  const [selectedType, setSelectedType] = useState(pageFilters.loan_type || 'all');
  const [showFilters, setShowFilters] = useState(false);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Loan | null>(null);
  const [form, setForm] = useState<LoanForm>(emptyForm());
  const [saving, setSaving] = useState(false);

  const [viewId, setViewId] = useState<number | null>(null);
  const [repayment, setRepayment] = useState({ amount: '', paid_on: today(), notes: '' });

  const [deleteTarget, setDeleteTarget] = useState<Loan | null>(null);
  const [exportOpen, setExportOpen] = useState(false);
  const [exportScope, setExportScope] = useState<'outstanding' | 'all'>('outstanding');

  const rows: Loan[] = loans?.data || [];
  // Read the open loan from fresh props so the dialog updates after a repayment.
  const viewLoan = useMemo(() => rows.find((l) => l.id === viewId) || null, [rows, viewId]);

  const flashToast = (page: any) => {
    if (page.props.flash?.success) toast.success(t(page.props.flash.success));
    else if (page.props.flash?.error) toast.error(t(page.props.flash.error));
  };

  const errorToast = (errors: any) => {
    toast.error(typeof errors === 'string' ? t(errors) : Object.values(errors).join(', '));
  };

  const listParams = (overrides: Record<string, any> = {}) => ({
    page: 1,
    search: searchTerm || undefined,
    status: selectedStatus !== 'all' ? selectedStatus : undefined,
    loan_type: selectedType !== 'all' ? selectedType : undefined,
    per_page: pageFilters.per_page,
    sort_field: pageFilters.sort_field,
    sort_direction: pageFilters.sort_direction,
    ...overrides,
  });

  const applyFilters = () => {
    router.get(route('hr.employee-loans.index'), listParams(), { preserveState: true, preserveScroll: true });
  };

  const handleResetFilters = () => {
    setSearchTerm('');
    setSelectedStatus('all');
    setSelectedType('all');
    setShowFilters(false);
    router.get(route('hr.employee-loans.index'), { page: 1, per_page: pageFilters.per_page }, { preserveState: true, preserveScroll: true });
  };

  const handleSort = (field: string) => {
    const direction = pageFilters.sort_field === field && pageFilters.sort_direction === 'asc' ? 'desc' : 'asc';
    router.get(route('hr.employee-loans.index'), listParams({ sort_field: field, sort_direction: direction }), {
      preserveState: true,
      preserveScroll: true,
    });
  };

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm());
    setFormOpen(true);
  };

  const openEdit = (loan: Loan) => {
    setEditing(loan);
    setForm({
      employee_id: String(loan.employee_id),
      loan_type: loan.loan_type,
      amount: String(loan.amount),
      installments: String(loan.installments),
      disbursed_on: loan.disbursed_on,
      start_month: loan.start_month,
      description: loan.description || '',
      notes: loan.notes || '',
    });
    setFormOpen(true);
  };

  const submitForm = () => {
    setSaving(true);
    const options = {
      preserveScroll: true,
      onSuccess: (page: any) => {
        flashToast(page);
        if (!page.props.flash?.error) setFormOpen(false);
      },
      onError: errorToast,
      onFinish: () => setSaving(false),
    };
    if (editing) router.put(route('hr.employee-loans.update', editing.id), { ...form }, options);
    else router.post(route('hr.employee-loans.store'), { ...form }, options);
  };

  const simpleAction = (method: 'put' | 'delete', url: string) => {
    router[method](url, {}, { preserveScroll: true, onSuccess: flashToast, onError: errorToast });
  };

  const openView = (loan: Loan) => {
    setViewId(loan.id);
    setRepayment({ amount: String(loan.balance), paid_on: today(), notes: '' });
  };

  const submitRepayment = () => {
    if (!viewLoan) return;
    router.post(route('hr.employee-loans.repayments.store', viewLoan.id), repayment, {
      preserveScroll: true,
      onSuccess: (page: any) => {
        flashToast(page);
        const fresh = (page.props.loans?.data || []).find((l: Loan) => l.id === viewLoan.id);
        setRepayment({ amount: fresh ? String(fresh.balance) : '', paid_on: today(), notes: '' });
      },
      onError: errorToast,
    });
  };

  const handleAction = (action: string, item: Loan) => {
    switch (action) {
      case 'view':
        openView(item);
        break;
      case 'edit':
        openEdit(item);
        break;
      case 'toggle-pause':
        simpleAction('put', route('hr.employee-loans.toggle-pause', item.id));
        break;
      case 'cancel':
        if (window.confirm(t('Cancel this loan? No further deductions will be made. Recovered amounts stay on record.'))) {
          simpleAction('put', route('hr.employee-loans.cancel', item.id));
        }
        break;
      case 'delete':
        setDeleteTarget(item);
        break;
    }
  };

  // Live preview of the monthly deduction while the form is filled in.
  const amountNum = parseFloat(form.amount) || 0;
  const monthsNum = parseInt(form.installments) || 0;
  const installmentPreview = amountNum > 0 && monthsNum > 0 ? Math.round((amountNum / monthsNum) * 100) / 100 : 0;
  const lastInstallment = installmentPreview > 0 ? Math.round((amountNum - installmentPreview * (monthsNum - 1)) * 100) / 100 : 0;
  const lockedEmployee = !!editing?.has_repayments;

  const pageActions = [];
  pageActions.push({
    label: t('Export Report'),
    icon: <Download className="h-4 w-4 mr-2" />,
    variant: 'outline' as const,
    onClick: () => setExportOpen(true),
  });
  if (hasPermission(permissions, 'create-employee-loans')) {
    pageActions.push({
      label: t('Add Loan / Advance'),
      icon: <Plus className="h-4 w-4 mr-2" />,
      variant: 'default' as const,
      onClick: openCreate,
    });
  }

  const breadcrumbs = [
    { title: t('Dashboard'), href: route('dashboard') },
    { title: t('Payroll Management'), href: route('hr.employee-loans.index') },
    { title: t('Loans & Advances') },
  ];

  const columns = [
    { key: 'reference', label: t('Reference'), sortable: true },
    {
      key: 'employee_name',
      label: t('Employee'),
      render: (_: string, row: Loan) => (
        <div>
          <div className="font-medium">{row.employee_name}</div>
          {row.employee_code && <div className="text-xs text-gray-500">{row.employee_code}</div>}
        </div>
      ),
    },
    { key: 'type_label', label: t('Type'), render: (value: string) => t(value) },
    { key: 'amount', label: t('Amount'), sortable: true, render: (v: number) => <span className="font-mono">{money(v)}</span> },
    {
      key: 'installment_amount',
      label: t('Monthly Deduction'),
      render: (v: number, row: Loan) => (
        <div>
          <span className="font-mono">{money(v)}</span>
          <div className="text-xs text-gray-500">{t('{{n}} months', { n: row.installments })}</div>
        </div>
      ),
    },
    { key: 'repaid', label: t('Recovered'), render: (v: number) => <span className="font-mono text-green-700">{money(v)}</span> },
    { key: 'balance', label: t('Balance'), render: (v: number) => <span className="font-mono font-semibold">{money(v)}</span> },
    { key: 'start_date', label: t('First Deduction'), sortable: true, render: (_: string, row: Loan) => formatMonth(row.start_month) },
    {
      key: 'display_status',
      label: t('Status'),
      render: (value: LoanStatus) => (
        <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${statusStyles[value]}`}>
          {t(value.charAt(0).toUpperCase() + value.slice(1))}
        </span>
      ),
    },
  ];

  const actions = [
    { label: t('View'), icon: 'Eye', action: 'view', className: 'text-blue-500', requiredPermission: 'view-employee-loans' },
    {
      label: t('Edit'),
      icon: 'Edit',
      action: 'edit',
      className: 'text-amber-500',
      requiredPermission: 'edit-employee-loans',
      condition: (row: Loan) => row.display_status !== 'cancelled',
    },
    {
      label: t('Pause Deductions'),
      icon: 'PauseCircle',
      action: 'toggle-pause',
      className: 'text-amber-500',
      requiredPermission: 'edit-employee-loans',
      condition: (row: Loan) => row.display_status === 'active',
    },
    {
      label: t('Resume Deductions'),
      icon: 'PlayCircle',
      action: 'toggle-pause',
      className: 'text-green-600',
      requiredPermission: 'edit-employee-loans',
      condition: (row: Loan) => row.display_status === 'paused',
    },
    {
      label: t('Cancel Loan'),
      icon: 'Ban',
      action: 'cancel',
      className: 'text-red-500',
      requiredPermission: 'edit-employee-loans',
      condition: (row: Loan) => row.display_status === 'active' || row.display_status === 'paused',
    },
    {
      label: t('Delete'),
      icon: 'Trash2',
      action: 'delete',
      className: 'text-red-500',
      requiredPermission: 'delete-employee-loans',
      condition: (row: Loan) => !row.has_repayments,
    },
  ];

  const summaryCards = [
    { label: t('Open Loans'), value: String(summary?.open_count ?? 0), hint: summary?.paused ? t('{{n}} paused', { n: summary.paused }) : '' },
    { label: t('Outstanding Balance'), value: money(summary?.outstanding ?? 0), hint: '' },
    { label: t('Total Disbursed'), value: money(summary?.disbursed ?? 0), hint: '' },
    { label: t('Recovered to Date'), value: money(summary?.recovered ?? 0), hint: '' },
  ];

  return (
    <PageTemplate
      title={t('Loans & Advances')}
      description={t('Salary advances and staff loans, recovered after tax through payroll')}
      url="/hr/employee-loans"
      actions={pageActions}
      breadcrumbs={breadcrumbs}
      noPadding
    >
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        {summaryCards.map((card) => (
          <div key={card.label} className="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
            <div className="text-xs font-medium text-gray-500">{card.label}</div>
            <div className="mt-1 text-xl font-semibold font-mono">{card.value}</div>
            {card.hint && <div className="text-xs text-amber-600 mt-1">{card.hint}</div>}
          </div>
        ))}
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 p-4">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={(e: React.FormEvent) => {
            e.preventDefault();
            applyFilters();
          }}
          filters={[
            {
              name: 'status',
              label: t('Status'),
              type: 'select',
              value: selectedStatus,
              onChange: setSelectedStatus,
              options: [
                { value: 'all', label: t('All Statuses') },
                { value: 'active', label: t('Active') },
                { value: 'paused', label: t('Paused') },
                { value: 'completed', label: t('Completed') },
                { value: 'cancelled', label: t('Cancelled') },
              ],
            },
            {
              name: 'loan_type',
              label: t('Type'),
              type: 'select',
              value: selectedType,
              onChange: setSelectedType,
              options: [
                { value: 'all', label: t('All Types') },
                ...Object.entries(loanTypes).map(([value, label]) => ({ value, label: t(label as string) })),
              ],
            },
          ]}
          showFilters={showFilters}
          setShowFilters={setShowFilters}
          hasActiveFilters={() => searchTerm !== '' || selectedStatus !== 'all' || selectedType !== 'all'}
          activeFilterCount={() => (searchTerm ? 1 : 0) + (selectedStatus !== 'all' ? 1 : 0) + (selectedType !== 'all' ? 1 : 0)}
          onResetFilters={handleResetFilters}
          onApplyFilters={applyFilters}
          currentPerPage={pageFilters.per_page?.toString() || '10'}
          onPerPageChange={(value: string) => {
            router.get(route('hr.employee-loans.index'), listParams({ per_page: parseInt(value) }), {
              preserveState: true,
              preserveScroll: true,
            });
          }}
        />
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
        <CrudTable
          columns={columns}
          actions={actions}
          data={rows}
          from={loans?.from || 1}
          onAction={handleAction}
          sortField={pageFilters.sort_field}
          sortDirection={pageFilters.sort_direction}
          onSort={handleSort}
          permissions={permissions}
          entityPermissions={{
            view: 'view-employee-loans',
            edit: 'edit-employee-loans',
            delete: 'delete-employee-loans',
          }}
        />
        <Pagination
          from={loans?.from || 0}
          to={loans?.to || 0}
          total={loans?.total || 0}
          links={loans?.links}
          entityName={t('loans')}
          onPageChange={(url: string) => router.get(url)}
        />
      </div>

      {/* Create / edit */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editing ? t('Edit {{ref}}', { ref: editing.reference }) : t('New Loan / Advance')}</DialogTitle>
          </DialogHeader>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="sm:col-span-2 space-y-1.5">
              <Label>{t('Employee')}</Label>
              <Select
                value={form.employee_id}
                onValueChange={(v) => setForm({ ...form, employee_id: v })}
                disabled={lockedEmployee}
              >
                <SelectTrigger>
                  <SelectValue placeholder={t('Select employee')} />
                </SelectTrigger>
                <SelectContent className="z-[60000]" searchable>
                  {(employees as EmployeeOption[]).map((e) => (
                    <SelectItem key={e.id} value={String(e.id)}>
                      {e.name}
                      {e.employee_id ? ` (${e.employee_id})` : ''}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {lockedEmployee && <p className="text-xs text-gray-500">{t('Deductions have started, so the employee cannot be changed.')}</p>}
            </div>

            <div className="space-y-1.5">
              <Label>{t('Type')}</Label>
              <Select
                value={form.loan_type}
                onValueChange={(v) => setForm({ ...form, loan_type: v as LoanForm['loan_type'] })}
                disabled={!!editing}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent className="z-[60000]">
                  {Object.entries(loanTypes).map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                      {t(label as string)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label>{t('Amount Given')}</Label>
              <Input type="number" min="1" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </div>

            <div className="space-y-1.5">
              <Label>{t('Recover Over (months)')}</Label>
              <Input
                type="number"
                min="1"
                max="120"
                step="1"
                value={form.installments}
                onChange={(e) => setForm({ ...form, installments: e.target.value })}
              />
            </div>

            <div className="space-y-1.5">
              <Label>{t('First Deduction Month')}</Label>
              <Input type="month" value={form.start_month} onChange={(e) => setForm({ ...form, start_month: e.target.value })} />
            </div>

            <div className="space-y-1.5">
              <Label>{t('Date Given')}</Label>
              <Input type="date" value={form.disbursed_on} onChange={(e) => setForm({ ...form, disbursed_on: e.target.value })} />
            </div>

            <div className="space-y-1.5">
              <Label>{t('Description')}</Label>
              <Input
                value={form.description}
                maxLength={255}
                placeholder={t('e.g. School fees advance')}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
              />
            </div>

            <div className="sm:col-span-2 space-y-1.5">
              <Label>{t('Notes')}</Label>
              <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </div>

            {installmentPreview > 0 && (
              <div className="sm:col-span-2 rounded-md bg-gray-50 dark:bg-gray-800 p-3 text-sm">
                {monthsNum === 1
                  ? t('{{amount}} is deducted in full on the first payroll run.', { amount: money(amountNum) })
                  : t('{{installment}} is deducted each month for {{months}} months, starting {{month}}.', {
                      installment: money(installmentPreview),
                      months: monthsNum,
                      month: formatMonth(form.start_month),
                    })}
                {monthsNum > 1 && lastInstallment !== installmentPreview && (
                  <span className="text-gray-500"> {t('The last deduction is {{last}} to clear the balance exactly.', { last: money(lastInstallment) })}</span>
                )}
                <div className="text-xs text-gray-500 mt-1">
                  {t('Deducted after tax, so PAYE, NAPSA and NHIMA are not affected. If net pay is too low in a month, the remainder carries forward.')}
                </div>
              </div>
            )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              {t('Cancel')}
            </Button>
            <Button onClick={submitForm} disabled={saving || !form.employee_id || !installmentPreview}>
              {saving ? t('Saving...') : editing ? t('Save Changes') : t('Create')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Detail, repayment history and early settlement */}
      <Dialog open={!!viewLoan} onOpenChange={(open) => !open && setViewId(null)}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
          {viewLoan && (
            <>
              <DialogHeader>
                <DialogTitle>
                  {viewLoan.reference} {viewLoan.employee_name}
                </DialogTitle>
              </DialogHeader>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div>
                  <div className="text-xs text-gray-500">{t('Type')}</div>
                  <div className="font-medium">{t(viewLoan.type_label)}</div>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('Amount')}</div>
                  <div className="font-mono">{money(viewLoan.amount)}</div>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('Monthly Deduction')}</div>
                  <div className="font-mono">{money(viewLoan.installment_amount)}</div>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('Status')}</div>
                  <span
                    className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusStyles[viewLoan.display_status]}`}
                  >
                    {t(viewLoan.display_status.charAt(0).toUpperCase() + viewLoan.display_status.slice(1))}
                  </span>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('Date Given')}</div>
                  <div>{formatDate(viewLoan.disbursed_on)}</div>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('First Deduction')}</div>
                  <div>{formatMonth(viewLoan.start_month)}</div>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('Recovered')}</div>
                  <div className="font-mono text-green-700">{money(viewLoan.repaid)}</div>
                </div>
                <div>
                  <div className="text-xs text-gray-500">{t('Balance')}</div>
                  <div className="font-mono font-semibold">{money(viewLoan.balance)}</div>
                </div>
              </div>

              <div className="space-y-1">
                <Progress value={viewLoan.amount > 0 ? Math.min(100, (viewLoan.repaid / viewLoan.amount) * 100) : 0} />
                <div className="text-xs text-gray-500">
                  {viewLoan.balance > 0
                    ? t('About {{n}} more monthly deductions', { n: viewLoan.installments_left })
                    : t('Fully recovered')}
                </div>
              </div>

              {(viewLoan.description || viewLoan.notes) && (
                <div className="text-sm space-y-1">
                  {viewLoan.description && <div>{viewLoan.description}</div>}
                  {viewLoan.notes && <div className="text-gray-500 whitespace-pre-line">{viewLoan.notes}</div>}
                </div>
              )}

              <div>
                <div className="text-sm font-semibold mb-2">{t('Repayments')}</div>
                {viewLoan.repayments.length === 0 ? (
                  <div className="text-sm text-gray-500">{t('No deductions yet. The first one is taken by the {{month}} payroll run.', { month: formatMonth(viewLoan.start_month) })}</div>
                ) : (
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 border-b">
                        <th className="py-1.5">{t('Date')}</th>
                        <th className="py-1.5">{t('Source')}</th>
                        <th className="py-1.5 text-right">{t('Amount')}</th>
                        <th className="py-1.5 w-8"></th>
                      </tr>
                    </thead>
                    <tbody>
                      {viewLoan.repayments.map((r) => (
                        <tr key={r.id} className="border-b last:border-0">
                          <td className="py-1.5">{formatDate(r.paid_on)}</td>
                          <td className="py-1.5">
                            {r.source}
                            {r.notes && <div className="text-xs text-gray-500">{r.notes}</div>}
                          </td>
                          <td className="py-1.5 text-right font-mono">{money(r.amount)}</td>
                          <td className="py-1.5 text-right">
                            {canEdit && r.method === 'manual' && (
                              <button
                                type="button"
                                className="text-xs text-red-500 hover:underline"
                                onClick={() => {
                                  if (window.confirm(t('Remove this repayment?'))) {
                                    simpleAction('delete', route('hr.employee-loans.repayments.destroy', [viewLoan.id, r.id]));
                                  }
                                }}
                              >
                                {t('Remove')}
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>

              {canEdit && viewLoan.balance > 0 && viewLoan.display_status !== 'cancelled' && (
                <div className="rounded-md border p-3 space-y-3">
                  <div className="text-sm font-semibold">{t('Record a repayment or settle early')}</div>
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="space-y-1.5">
                      <Label>{t('Amount')}</Label>
                      <Input
                        type="number"
                        min="0.01"
                        step="0.01"
                        max={viewLoan.balance}
                        value={repayment.amount}
                        onChange={(e) => setRepayment({ ...repayment, amount: e.target.value })}
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label>{t('Date Paid')}</Label>
                      <Input type="date" value={repayment.paid_on} onChange={(e) => setRepayment({ ...repayment, paid_on: e.target.value })} />
                    </div>
                    <div className="space-y-1.5">
                      <Label>{t('Note')}</Label>
                      <Input
                        value={repayment.notes}
                        placeholder={t('e.g. Cash paid at office')}
                        onChange={(e) => setRepayment({ ...repayment, notes: e.target.value })}
                      />
                    </div>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <div className="text-xs text-gray-500">{t('Enter the full balance to settle the loan. Payroll stops deducting once the balance is zero.')}</div>
                    <Button size="sm" onClick={submitRepayment} disabled={!(parseFloat(repayment.amount) > 0)}>
                      {t('Record Repayment')}
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* Export */}
      <Dialog open={exportOpen} onOpenChange={setExportOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t('Loans & Advances Report')}</DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label>{t('Include')}</Label>
              <Select value={exportScope} onValueChange={(v) => setExportScope(v as 'outstanding' | 'all')}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent className="z-[60000]">
                  <SelectItem value="outstanding">{t('Loans with a balance outstanding')}</SelectItem>
                  <SelectItem value="all">{t('All loans, including fully recovered')}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          <DialogFooter className="gap-2">
            {(['csv', 'excel', 'pdf'] as const).map((format) => (
              <Button
                key={format}
                variant={format === 'excel' ? 'default' : 'outline'}
                onClick={() => {
                  window.location.href = route('hr.employee-loans.export', { format, scope: exportScope });
                  setExportOpen(false);
                }}
              >
                {format === 'csv' ? 'CSV' : format === 'excel' ? 'Excel' : 'PDF'}
              </Button>
            ))}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <CrudDeleteModal
        isOpen={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => {
          if (!deleteTarget) return;
          router.delete(route('hr.employee-loans.destroy', deleteTarget.id), {
            preserveScroll: true,
            onSuccess: (page: any) => {
              flashToast(page);
              setDeleteTarget(null);
            },
            onError: errorToast,
          });
        }}
        itemName={deleteTarget?.reference || ''}
        entityName="loan"
      />
    </PageTemplate>
  );
}
