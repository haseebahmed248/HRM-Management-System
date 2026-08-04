// pages/hr/salary-components/index.tsx
import { useState } from 'react';
import { PageTemplate, type PageAction } from '@/components/page-template';
import { usePage, router } from '@inertiajs/react';
import { Plus, Upload } from 'lucide-react';
import { hasPermission } from '@/utils/authorization';
import { CrudTable } from '@/components/CrudTable';
import { CrudFormModal } from '@/components/CrudFormModal';
import { CrudDeleteModal } from '@/components/CrudDeleteModal';
import { ImportModal } from '@/components/ImportModal';
import { toast } from '@/components/custom-toast';
import { useTranslation } from 'react-i18next';
import { Pagination } from '@/components/ui/pagination';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

interface ComponentTypeOption {
  value: string;
  label: string;
  description: string;
  bucket: 'earnings' | 'deductions' | 'employer_contributions';
  taxable_effect: 'increase' | 'reduce' | 'none';
}

interface FlashMessages {
  success?: string;
  error?: string;
}

export default function SalaryComponents() {
  const { t } = useTranslation();
  const { auth, salaryComponents, componentTypes = [], filters: pageFilters = {}, globalSettings } = usePage().props as any;
  const permissions = auth?.permissions || [];
  const typeDefinitions = componentTypes as ComponentTypeOption[];

  const typeDefinition = (value: string) => typeDefinitions.find((type) => type.value === value);

  // State
  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [selectedType, setSelectedType] = useState(pageFilters.type || 'all');
  const [selectedCalculationType, setSelectedCalculationType] = useState(pageFilters.calculation_type || 'all');
  const [selectedStatus, setSelectedStatus] = useState(pageFilters.status || 'all');
  const [showFilters, setShowFilters] = useState(false);
  const [isFormModalOpen, setIsFormModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [currentItem, setCurrentItem] = useState<any>(null);
  const [formMode, setFormMode] = useState<'create' | 'edit' | 'view'>('create');

  // Check if any filters are active
  const hasActiveFilters = () => {
    return searchTerm !== '' || selectedType !== 'all' || selectedCalculationType !== 'all' || selectedStatus !== 'all';
  };

  // Count active filters
  const activeFilterCount = () => {
    return (searchTerm ? 1 : 0) + (selectedType !== 'all' ? 1 : 0) + (selectedCalculationType !== 'all' ? 1 : 0) + (selectedStatus !== 'all' ? 1 : 0);
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters();
  };

  const applyFilters = () => {
    router.get(route('hr.salary-components.index'), {
      page: 1,
      search: searchTerm || undefined,
      type: selectedType !== 'all' ? selectedType : undefined,
      calculation_type: selectedCalculationType !== 'all' ? selectedCalculationType : undefined,
      status: selectedStatus !== 'all' ? selectedStatus : undefined,
      per_page: pageFilters.per_page
    }, { preserveState: true, preserveScroll: true });
  };

  const handleSort = (field: string) => {
    const direction = pageFilters.sort_field === field && pageFilters.sort_direction === 'asc' ? 'desc' : 'asc';

    router.get(route('hr.salary-components.index'), {
      sort_field: field,
      sort_direction: direction,
      page: 1,
      search: searchTerm || undefined,
      type: selectedType !== 'all' ? selectedType : undefined,
      calculation_type: selectedCalculationType !== 'all' ? selectedCalculationType : undefined,
      status: selectedStatus !== 'all' ? selectedStatus : undefined,
      per_page: pageFilters.per_page
    }, { preserveState: true, preserveScroll: true });
  };

  const handleAction = (action: string, item: any) => {
    setCurrentItem(item);

    switch (action) {
      case 'view':
        setFormMode('view');
        setIsFormModalOpen(true);
        break;
      case 'edit':
        setFormMode('edit');
        setIsFormModalOpen(true);
        break;
      case 'delete':
        setIsDeleteModalOpen(true);
        break;
      case 'toggle-status':
        handleToggleStatus(item);
        break;
    }
  };

  const handleAddNew = () => {
    setCurrentItem(null);
    setFormMode('create');
    setIsFormModalOpen(true);
  };

  const handleFormSubmit = (formData: any) => {
    if (formMode === 'create') {
      if (!globalSettings?.is_demo) {
        toast.loading(t('Creating salary component...'));
      }

      router.post(route('hr.salary-components.store'), formData, {
        onSuccess: (page) => {
          setIsFormModalOpen(false);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          const flash = page.props.flash as FlashMessages | undefined;
          if (flash?.success) {
            toast.success(t(flash.success));
          } else if (flash?.error) {
            toast.error(t(flash.error));
          }
        },
        onError: (errors) => {
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (typeof errors === 'string') {
            toast.error(errors);
          } else {
            toast.error(`Failed to create salary component: ${Object.values(errors).join(', ')}`);
          }
        }
      });
    } else if (formMode === 'edit') {
      if (!globalSettings?.is_demo) {
        toast.loading(t('Updating salary component...'));
      }

      router.put(route('hr.salary-components.update', currentItem.id), formData, {
        onSuccess: (page) => {
          setIsFormModalOpen(false);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          const flash = page.props.flash as FlashMessages | undefined;
          if (flash?.success) {
            toast.success(t(flash.success));
          } else if (flash?.error) {
            toast.error(t(flash.error));
          }
        },
        onError: (errors) => {
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (typeof errors === 'string') {
            toast.error(errors);
          } else {
            toast.error(`Failed to update salary component: ${Object.values(errors).join(', ')}`);
          }
        }
      });
    }
  };

  const handleDeleteConfirm = () => {
    if (!globalSettings?.is_demo) {
      toast.loading(t('Deleting salary component...'));
    }

    router.delete(route('hr.salary-components.destroy', currentItem.id), {
      onSuccess: (page) => {
        setIsDeleteModalOpen(false);
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        const flash = page.props.flash as FlashMessages | undefined;
        if (flash?.success) {
          toast.success(t(flash.success));
        } else if (flash?.error) {
          toast.error(t(flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(errors);
        } else {
          toast.error(`Failed to delete salary component: ${Object.values(errors).join(', ')}`);
        }
      }
    });
  };

  const handleToggleStatus = (component: any) => {
    const newStatus = component.status === 'active' ? 'inactive' : 'active';
    if (!globalSettings?.is_demo) {
      toast.loading(`${newStatus === 'active' ? t('Activating') : t('Deactivating')} salary component...`);
    }

    router.put(route('hr.salary-components.toggle-status', component.id), {}, {
      onSuccess: (page) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        const flash = page.props.flash as FlashMessages | undefined;
        if (flash?.success) {
          toast.success(t(flash.success));
        } else if (flash?.error) {
          toast.error(t(flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(errors);
        } else {
          toast.error(`Failed to update salary component status: ${Object.values(errors).join(', ')}`);
        }
      }
    });
  };

  const handleResetFilters = () => {
    setSearchTerm('');
    setSelectedType('all');
    setSelectedCalculationType('all');
    setSelectedStatus('all');
    setShowFilters(false);

    router.get(route('hr.salary-components.index'), {
      page: 1,
      per_page: pageFilters.per_page
    }, { preserveState: true, preserveScroll: true });
  };

  // Define page actions
  const pageActions: PageAction[] = [];

  // track-a/12: bulk import button (shown to users with import permission)
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  if (hasPermission(permissions, 'import-salary-components')) {
    pageActions.push({
      label: t('Import'),
      icon: <Upload className="h-4 w-4 mr-2" />,
      variant: 'outline',
      onClick: () => setIsImportModalOpen(true),
    });
  }

  // Add the "Add New Component" button if user has permission
  if (hasPermission(permissions, 'create-salary-components')) {
    pageActions.push({
      label: t('Add Component'),
      icon: <Plus className="h-4 w-4 mr-2" />,
      variant: 'default',
      onClick: () => handleAddNew()
    });
  }

  const breadcrumbs = [
    { title: t('Dashboard'), href: route('dashboard') },
    { title: t('Payroll Management'), href: route('hr.salary-components.index') },
    { title: t('Salary Components') }
  ];

  // Define table columns
  const columns = [
    {
      key: 'name',
      label: t('Component Name'),
      sortable: true
    },
    {
      key: 'type',
      label: t('Type'),
      render: (value: string) => {
        const definition = typeDefinition(value);
        const bucketStyles: Record<ComponentTypeOption['bucket'], string> = {
          earnings: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
          deductions: 'bg-rose-50 text-rose-700 ring-rose-600/20',
          employer_contributions: 'bg-amber-50 text-amber-800 ring-amber-600/20',
        };

        return (
          <span className={`inline-flex max-w-52 items-center whitespace-normal rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${bucketStyles[definition?.bucket ?? 'earnings']}`}>
            {definition?.label ?? value}
          </span>
        );
      }
    },
    {
      key: 'calculation_type',
      label: t('Calculation'),
      render: (value: string) => {
        // track-a/10: added zambia_pension badge
        const styles: Record<string, string> = {
          fixed: 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20',
          percentage: 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20',
          zambia_paye: 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20',
          zambia_pension: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
          hourly: 'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-600/20',
          daily: 'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-600/20',
          percentage_of_hourly: 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20',
        };
        const labels: Record<string, string> = {
          fixed: t('Fixed'),
          percentage: t('Percentage'),
          zambia_paye: t('Zambia PAYE'),
          zambia_pension: t('Zambia Pension'),
          hourly: t('Hourly Rate'),
          daily: t('Daily Wage'),
          percentage_of_hourly: t('% of Hourly'),
        };
        return (
          <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${styles[value] ?? styles.fixed}`}>
            {labels[value] ?? value}
          </span>
        );
      }
    },
    {
      key: 'amount',
      label: t('Amount/Percentage'),
      render: (value: any, row: any) => {
        const ct = row.calculation_type;
        let display: string;
        if (ct === 'percentage' || ct === 'percentage_of_hourly') {
          display = `${row.percentage_of_basic}%`;
        } else if (ct === 'hourly') {
          display = `${row.default_amount} ${t('hrs')}`;
        } else if (ct === 'daily') {
          display = `${row.default_amount} ${t('days')}`;
        } else {
          display = `ZK ${parseFloat(row.default_amount).toLocaleString('en-ZM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
        return <span className="font-mono">{display}</span>;
      }
    },
    // {
    //   key: 'is_taxable',
    //   label: t('Taxable'),
    //   render: (value: boolean) => (
    //     <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${value
    //       ? 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20'
    //       : 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20'
    //       }`}>
    //       {value ? t('Yes') : t('No')}
    //     </span>
    //   )
    // },
    // {
    //   key: 'is_mandatory',
    //   label: t('Mandatory'),
    //   render: (value: boolean) => (
    //     <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${value
    //       ? 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20'
    //       : 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20'
    //       }`}>
    //       {value ? t('Yes') : t('No')}
    //     </span>
    //   )
    // },
    {
      key: 'status',
      label: t('Status'),
      render: (value: string) => {
        return (
          <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${value === 'active'
            ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20'
            : 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20'
            }`}>
            {value === 'active' ? t('Active') : t('Inactive')}
          </span>
        );
      }
    }
  ];

  // Define table actions
  const actions = [
    {
      label: t('View'),
      icon: 'Eye',
      action: 'view',
      className: 'text-blue-500',
      requiredPermission: 'view-salary-components'
    },
    {
      label: t('Edit'),
      icon: 'Edit',
      action: 'edit',
      className: 'text-amber-500',
      requiredPermission: 'edit-salary-components'
    },
    {
      label: t('Toggle Status'),
      icon: 'Lock',
      action: 'toggle-status',
      className: 'text-amber-500',
      requiredPermission: 'edit-salary-components'
    },
    {
      label: t('Delete'),
      icon: 'Trash2',
      action: 'delete',
      className: 'text-red-500',
      requiredPermission: 'delete-salary-components'
    }
  ];

  // Prepare options for filters
  const typeOptions = [
    { value: 'all', label: t('All Types') , disabled : true},
    ...typeDefinitions.map((type) => ({ value: type.value, label: t(type.label) })),
  ];

  const calculationTypeOptions = [
    { value: 'all', label: t('All Calculations') , disabled : true},
    { value: 'fixed', label: t('Fixed Amount') },
    { value: 'percentage', label: t('Percentage') },
    // track-a/10: zambia_pension marks a deduction component as qualifying
    // for PAYE relief (capped per Zambia Tax Settings).
    { value: 'zambia_pension', label: t('Zambia Pension (PAYE relief)') }
  ];

  const statusOptions = [
    { value: 'all', label: t('All Statuses') , disabled : true },
    { value: 'active', label: t('Active') },
    { value: 'inactive', label: t('Inactive') }
  ];

  return (
    <PageTemplate
      title={t("Salary Components")}
      description={t('Define how each pay component affects cash pay, PAYE, and employer contributions.')}
      url="/hr/salary-components"
      actions={pageActions}
      breadcrumbs={breadcrumbs}
      noPadding
    >
      {/* Search and filters section */}
      <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 p-4">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={handleSearch}
          filters={[
            {
              name: 'type',
              label: t('Type'),
              type: 'select',
              value: selectedType,
              onChange: setSelectedType,
              options: typeOptions
            },
            {
              name: 'calculation_type',
              label: t('Calculation Type'),
              type: 'select',
              value: selectedCalculationType,
              onChange: setSelectedCalculationType,
              options: calculationTypeOptions
            },
            {
              name: 'status',
              label: t('Status'),
              type: 'select',
              value: selectedStatus,
              onChange: setSelectedStatus,
              options: statusOptions
            }
          ]}
          showFilters={showFilters}
          setShowFilters={setShowFilters}
          hasActiveFilters={hasActiveFilters}
          activeFilterCount={activeFilterCount}
          onResetFilters={handleResetFilters}
          onApplyFilters={applyFilters}
          currentPerPage={pageFilters.per_page?.toString() || "10"}
          onPerPageChange={(value) => {
            router.get(route('hr.salary-components.index'), {
              page: 1,
              per_page: parseInt(value),
              search: searchTerm || undefined,
              type: selectedType !== 'all' ? selectedType : undefined,
              calculation_type: selectedCalculationType !== 'all' ? selectedCalculationType : undefined,
              status: selectedStatus !== 'all' ? selectedStatus : undefined
            }, { preserveState: true, preserveScroll: true });
          }}
        />
      </div>

      {/* Content section */}
      <div className="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
        <CrudTable
          columns={columns}
          actions={actions}
          data={salaryComponents?.data || []}
          from={salaryComponents?.from || 1}
          onAction={handleAction}
          sortField={pageFilters.sort_field}
          sortDirection={pageFilters.sort_direction}
          onSort={handleSort}
          permissions={permissions}
          entityPermissions={{
            view: 'view-salary-components',
            edit: 'edit-salary-components',
            delete: 'delete-salary-components'
          }}
        />

        {/* Pagination section */}
        <Pagination
          from={salaryComponents?.from || 0}
          to={salaryComponents?.to || 0}
          total={salaryComponents?.total || 0}
          links={salaryComponents?.links}
          entityName={t("salary components")}
          onPageChange={(url) => router.get(url)}
        />
      </div>

      {/* Form Modal */}
      <CrudFormModal
        isOpen={isFormModalOpen}
        onClose={() => setIsFormModalOpen(false)}
        onSubmit={handleFormSubmit}
        formConfig={{
          fields: [
            { name: 'name', label: t('Component Name'), type: 'text', required: true },
            { name: 'description', label: t('Description'), type: 'textarea' },
            {
              name: 'type',
              label: t('Type'),
              type: 'select',
              required: true,
              options: typeDefinitions.map((type) => ({ value: type.value, label: t(type.label) })),
              defaultValue: 'income',
            },
            {
              name: 'calculation_type',
              label: t('Calculation Type'),
              type: 'select',
              required: true,
              options: [
                { value: 'fixed', label: t('Fixed Amount') },
                { value: 'percentage', label: t('Percentage of Basic') },
                // Rate-based: valued from the employee's derived hourly / daily rate.
                { value: 'hourly', label: t('Hourly Rate (× hours)') },
                { value: 'daily', label: t('Daily Wage (× days)') },
                { value: 'percentage_of_hourly', label: t('Percentage of Hourly Rate') },
                // track-a/10: zambia_pension makes this deduction qualify
                // for PAYE relief (capped per Zambia Tax Settings).
                { value: 'zambia_pension', label: t('Zambia Pension (PAYE relief)') }
              ]
            },
            // Fixed = amount; Hourly = number of hours; Daily = number of days.
            { name: 'default_amount', label: t('Fixed Amount / Hours / Days'), type: 'number' },
            // Percentage of Basic, or Percentage of Hourly Rate depending on type.
            { name: 'percentage_of_basic', label: t('Percentage'), type: 'number' },
            { name: 'is_taxable', label: t('Taxable (subject to PAYE)'), type: 'checkbox', defaultValue: true },
            {
              name: '_processing_rules',
              label: t('Processing Rules'),
              type: 'custom',
              render: (_field, formData, onChange) => {
                const toggles = [
                  ['affect_notional_pay', t('Affect notional pay'), t('Include in PAYE taxable pay without adding cash to gross or net.')],
                  ['affect_payslip', t('Include in payslip data'), t('Store this component in the payroll breakdown.'), true],
                  ['print_on_payslip', t('Print on payslip'), t('Show the stored line on preview, print, and PDF.'), true],
                  ['pro_rata_start_end', t('Pro-rate start and end'), t('Scale for employees joining or leaving during the pay period.')],
                  ['compulsory_deduction', t('Compulsory deduction'), t('Apply the full deduction even when it makes net pay negative.')],
                ] as const;

                return (
                  <div className="space-y-4 rounded-md border border-border bg-muted/15 p-3 sm:p-4">
                    <p className="text-xs leading-5 text-muted-foreground">
                      {t('Control when the component is processed and how it appears in payroll records.')}
                    </p>

                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                      {toggles.map(([name, label, description, defaultValue]) => (
                        <div key={name} className="flex min-h-20 items-start justify-between gap-3 rounded-md border bg-background p-3">
                          <div className="min-w-0">
                            <Label htmlFor={name} className="text-sm font-medium">{label}</Label>
                            <p className="mt-1 text-xs leading-4 text-muted-foreground">{description}</p>
                          </div>
                          <Switch
                            id={name}
                            checked={Boolean(formData[name] ?? defaultValue ?? false)}
                            onCheckedChange={(checked) => onChange(name, checked)}
                            disabled={formMode === 'view'}
                            className="shrink-0"
                          />
                        </div>
                      ))}
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <div className="space-y-2">
                        <Label>{t('Delay or duration')}</Label>
                        <Select
                          value={formData.delay_type ?? 'none'}
                          onValueChange={(value) => onChange('delay_type', value)}
                          disabled={formMode === 'view'}
                        >
                          <SelectTrigger><SelectValue /></SelectTrigger>
                          <SelectContent className="z-[60000]">
                            <SelectItem value="none">{t('No delay')}</SelectItem>
                            <SelectItem value="delay_for">{t('Delay for')}</SelectItem>
                            <SelectItem value="use_for_next">{t('Use for next')}</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      {formData.delay_type && formData.delay_type !== 'none' && (
                        <div className="space-y-2">
                          <Label htmlFor="delay_months">{t('Number of months')}</Label>
                          <Input
                            id="delay_months"
                            type="number"
                            min={1}
                            max={600}
                            value={formData.delay_months ?? ''}
                            onChange={(event) => onChange('delay_months', event.target.value)}
                            disabled={formMode === 'view'}
                          />
                        </div>
                      )}
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <div className="space-y-2">
                        <Label>{t('Clear running totals')}</Label>
                        <Select
                          value={formData.clear_totals ?? 'year_end'}
                          onValueChange={(value) => onChange('clear_totals', value)}
                          disabled={formMode === 'view'}
                        >
                          <SelectTrigger><SelectValue /></SelectTrigger>
                          <SelectContent className="z-[60000]">
                            <SelectItem value="year_end">{t('At year end')}</SelectItem>
                            <SelectItem value="never">{t('Never')}</SelectItem>
                            <SelectItem value="specific_month">{t('In a specific month')}</SelectItem>
                            <SelectItem value="end_of_cycle">{t('At end of cycle')}</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      {formData.clear_totals === 'specific_month' && (
                        <div className="space-y-2">
                          <Label htmlFor="clear_specific_month">{t('Month')}</Label>
                          <Select
                            value={String(formData.clear_specific_month ?? '')}
                            onValueChange={(value) => onChange('clear_specific_month', value)}
                            disabled={formMode === 'view'}
                          >
                            <SelectTrigger id="clear_specific_month"><SelectValue placeholder={t('Select month')} /></SelectTrigger>
                            <SelectContent className="z-[60000]">
                              {Array.from({ length: 12 }, (_, index) => (
                                <SelectItem key={index + 1} value={String(index + 1)}>
                                  {new Date(2026, index, 1).toLocaleString(undefined, { month: 'long' })}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>
                      )}
                    </div>

                    {formData.clear_totals === 'end_of_cycle' && (
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-2">
                          <Label htmlFor="cycle_start_date">{t('Cycle start date')}</Label>
                          <Input
                            id="cycle_start_date"
                            type="date"
                            value={formData.cycle_start_date ?? ''}
                            onChange={(event) => onChange('cycle_start_date', event.target.value)}
                            disabled={formMode === 'view'}
                          />
                        </div>
                        <div className="space-y-2">
                          <Label htmlFor="cycle_length_months">{t('Cycle length (months)')}</Label>
                          <Input
                            id="cycle_length_months"
                            type="number"
                            min={1}
                            max={600}
                            value={formData.cycle_length_months ?? ''}
                            onChange={(event) => onChange('cycle_length_months', event.target.value)}
                            disabled={formMode === 'view'}
                          />
                        </div>
                      </div>
                    )}
                  </div>
                );
              },
            },
            {
              name: 'status',
              label: t('Status'),
              type: 'select',
              options: [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' }
              ],
              defaultValue: 'active'
            }
          ],
          modalSize: '2xl'
        }}
        initialData={currentItem}
        title={
          formMode === 'create'
            ? t('Add New Salary Component')
            : formMode === 'edit'
              ? t('Edit Salary Component')
              : t('View Salary Component')
        }
        mode={formMode}
        description={t('Choose the component type by its payroll effect. Taxable can still be switched off for exempt income or leave pay.')}
      />

      {/* Delete Modal */}
      <CrudDeleteModal
        isOpen={isDeleteModalOpen}
        onClose={() => setIsDeleteModalOpen(false)}
        onConfirm={handleDeleteConfirm}
        itemName={currentItem?.name || ''}
        entityName="salary component"
      />

      {/* track-a/12: bulk-import modal */}
      <ImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        title={t('Import Salary Components from CSV/Excel')}
        importRoute="hr.salary-components.import"
        parseRoute="hr.salary-components.parse"
        sampleRoute="hr.salary-components.download.template"
        importNotes={t('Required: Name, one of the six component Types, and Calculation Type (fixed/percentage/zambia_pension). Legacy earning/deduction imports remain accepted. Duplicate names within the same tenant are skipped.')}
        databaseFields={[
          { key: 'Name', required: true },
          { key: 'Description' },
          { key: 'Type', required: true },
          { key: 'Calculation Type', required: true },
          { key: 'Default Amount' },
          { key: 'Percentage Of Basic' },
          { key: 'Taxable' },
          { key: 'Mandatory' },
          { key: 'Status' },
        ]}
      />
    </PageTemplate>
  );
}
