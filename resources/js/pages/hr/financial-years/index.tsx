// pages/hr/financial-years/index.tsx
import React, { useState } from 'react';
import { PageTemplate } from '@/components/page-template';
import { usePage, router } from '@inertiajs/react';
import { Plus, Star, Lock, Unlock } from 'lucide-react';
import { hasPermission } from '@/utils/authorization';
import { CrudTable } from '@/components/CrudTable';
import { CrudFormModal } from '@/components/CrudFormModal';
import { CrudDeleteModal } from '@/components/CrudDeleteModal';
import { toast } from '@/components/custom-toast';
import { useTranslation } from 'react-i18next';
import { Pagination } from '@/components/ui/pagination';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';

interface FinancialYear {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: 'active' | 'closed';
  is_current: boolean;
  notes: string | null;
  created_at: string;
}

export default function FinancialYears() {
  const { t } = useTranslation();
  const { auth, financialYears, filters: pageFilters = {}, globalSettings } = usePage().props as any;
  const permissions = auth?.permissions || [];

  // State
  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [selectedStatus, setSelectedStatus] = useState(pageFilters.status || 'all');
  const [showFilters, setShowFilters] = useState(false);
  const [isFormModalOpen, setIsFormModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [currentItem, setCurrentItem] = useState<FinancialYear | null>(null);
  const [formMode, setFormMode] = useState<'create' | 'edit' | 'view'>('create');

  // Check if any filters are active
  const hasActiveFilters = () => {
    return searchTerm !== '' || selectedStatus !== 'all';
  };

  // Count active filters
  const activeFilterCount = () => {
    return (searchTerm ? 1 : 0) + (selectedStatus !== 'all' ? 1 : 0);
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters();
  };

  const applyFilters = () => {
    router.get(route('hr.financial-years.index'), {
      page: 1,
      search: searchTerm || undefined,
      status: selectedStatus !== 'all' ? selectedStatus : undefined,
      per_page: pageFilters.per_page
    }, { preserveState: true, preserveScroll: true });
  };

  const handleSort = (field: string) => {
    const direction = pageFilters.sort_field === field && pageFilters.sort_direction === 'asc' ? 'desc' : 'asc';

    router.get(route('hr.financial-years.index'), {
      sort_field: field,
      sort_direction: direction,
      page: 1,
      search: searchTerm || undefined,
      status: selectedStatus !== 'all' ? selectedStatus : undefined,
      per_page: pageFilters.per_page
    }, { preserveState: true, preserveScroll: true });
  };

  const handleAction = (action: string, item: FinancialYear) => {
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
      case 'set-current':
        handleSetCurrent(item);
        break;
      case 'close':
        handleClose(item);
        break;
      case 'reopen':
        handleReopen(item);
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
        toast.loading(t('Creating financial year...'));
      }

      router.post(route('hr.financial-years.store'), formData, {
        onSuccess: (page: any) => {
          setIsFormModalOpen(false);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (page.props.flash?.success) {
            toast.success(t(page.props.flash.success));
          } else if (page.props.flash?.error) {
            toast.error(t(page.props.flash.error));
          }
        },
        onError: (errors) => {
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (typeof errors === 'string') {
            toast.error(t(errors));
          } else {
            toast.error(t('Failed to create financial year: {{errors}}', { errors: Object.values(errors).join(', ') }));
          }
        }
      });
    } else if (formMode === 'edit') {
      if (!globalSettings?.is_demo) {
        toast.loading(t('Updating financial year...'));
      }

      router.put(route('hr.financial-years.update', currentItem?.id), formData, {
        onSuccess: (page: any) => {
          setIsFormModalOpen(false);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (page.props.flash?.success) {
            toast.success(t(page.props.flash.success));
          } else if (page.props.flash?.error) {
            toast.error(t(page.props.flash.error));
          }
        },
        onError: (errors) => {
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (typeof errors === 'string') {
            toast.error(t(errors));
          } else {
            toast.error(t('Failed to update financial year: {{errors}}', { errors: Object.values(errors).join(', ') }));
          }
        }
      });
    }
  };

  const handleDeleteConfirm = () => {
    if (!globalSettings?.is_demo) {
      toast.loading(t('Deleting financial year...'));
    }

    router.delete(route('hr.financial-years.destroy', currentItem?.id), {
      onSuccess: (page: any) => {
        setIsDeleteModalOpen(false);
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (page.props.flash?.success) {
          toast.success(t(page.props.flash.success));
        } else if (page.props.flash?.error) {
          toast.error(t(page.props.flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(t(errors));
        } else {
          toast.error(t('Failed to delete financial year: {{errors}}', { errors: Object.values(errors).join(', ') }));
        }
      }
    });
  };

  const handleSetCurrent = (item: FinancialYear) => {
    if (!globalSettings?.is_demo) {
      toast.loading(t('Setting as current financial year...'));
    }

    router.post(route('hr.financial-years.set-current', item.id), {}, {
      onSuccess: (page: any) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (page.props.flash?.success) {
          toast.success(t(page.props.flash.success));
        } else if (page.props.flash?.error) {
          toast.error(t(page.props.flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(t(errors));
        } else {
          toast.error(t('Failed to set current financial year: {{errors}}', { errors: Object.values(errors).join(', ') }));
        }
      }
    });
  };

  const handleClose = (item: FinancialYear) => {
    if (!globalSettings?.is_demo) {
      toast.loading(t('Closing financial year...'));
    }

    router.post(route('hr.financial-years.close', item.id), {}, {
      onSuccess: (page: any) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (page.props.flash?.success) {
          toast.success(t(page.props.flash.success));
        } else if (page.props.flash?.error) {
          toast.error(t(page.props.flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(t(errors));
        } else {
          toast.error(t('Failed to close financial year: {{errors}}', { errors: Object.values(errors).join(', ') }));
        }
      }
    });
  };

  const handleReopen = (item: FinancialYear) => {
    if (!globalSettings?.is_demo) {
      toast.loading(t('Reopening financial year...'));
    }

    router.post(route('hr.financial-years.reopen', item.id), {}, {
      onSuccess: (page: any) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (page.props.flash?.success) {
          toast.success(t(page.props.flash.success));
        } else if (page.props.flash?.error) {
          toast.error(t(page.props.flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(t(errors));
        } else {
          toast.error(t('Failed to reopen financial year: {{errors}}', { errors: Object.values(errors).join(', ') }));
        }
      }
    });
  };

  const handleResetFilters = () => {
    setSearchTerm('');
    setSelectedStatus('all');
    setShowFilters(false);

    router.get(route('hr.financial-years.index'), {
      page: 1,
      per_page: pageFilters.per_page
    }, { preserveState: true, preserveScroll: true });
  };

  // Define page actions
  const pageActions: Array<{
    label: string;
    icon: React.ReactNode;
    variant: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
    onClick: () => void;
  }> = [];

  if (hasPermission(permissions, 'manage-payroll-settings')) {
    pageActions.push({
      label: t('Add Financial Year'),
      icon: <Plus className="h-4 w-4 mr-2" />,
      variant: 'default',
      onClick: () => handleAddNew()
    });
  }

  const breadcrumbs = [
    { title: t('Dashboard'), href: route('dashboard') },
    { title: t('HR Management'), href: route('hr.financial-years.index') },
    { title: t('Financial Years') }
  ];

  // Define table columns
  const columns = [
    {
      key: 'name',
      label: t('Name'),
      sortable: true,
      render: (value: string, row: FinancialYear) => (
        <div className="flex items-center gap-2">
          <span>{value}</span>
          {row.is_current && (
            <span className="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
              <Star className="h-3 w-3 mr-1" />
              {t('Current')}
            </span>
          )}
        </div>
      )
    },
    {
      key: 'start_date',
      label: t('Start Date'),
      sortable: true,
      render: (value: string) => window.appSettings?.formatDateTimeSimple(value, false) || new Date(value).toLocaleDateString()
    },
    {
      key: 'end_date',
      label: t('End Date'),
      sortable: true,
      render: (value: string) => window.appSettings?.formatDateTimeSimple(value, false) || new Date(value).toLocaleDateString()
    },
    {
      key: 'status',
      label: t('Status'),
      render: (value: string) => {
        return (
          <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
            value === 'active'
              ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20'
              : 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20'
          }`}>
            {value === 'active' ? (
              <>
                <Unlock className="h-3 w-3 mr-1" />
                {t('Active')}
              </>
            ) : (
              <>
                <Lock className="h-3 w-3 mr-1" />
                {t('Closed')}
              </>
            )}
          </span>
        );
      }
    },
    {
      key: 'created_at',
      label: t('Created At'),
      sortable: true,
      render: (value: string) => window.appSettings?.formatDateTimeSimple(value, false) || new Date(value).toLocaleDateString()
    }
  ];

  // Define table actions - dynamically based on item status
  const getActionsForItem = (item: FinancialYear) => {
    const baseActions = [
      {
        label: t('View'),
        icon: 'Eye',
        action: 'view',
        className: 'text-blue-500',
        requiredPermission: 'manage-payroll-settings'
      },
      {
        label: t('Edit'),
        icon: 'Edit',
        action: 'edit',
        className: 'text-amber-500',
        requiredPermission: 'manage-payroll-settings'
      }
    ];

    // Add Set as Current if not already current and not closed
    if (!item.is_current && item.status === 'active') {
      baseActions.push({
        label: t('Set as Current'),
        icon: 'Star',
        action: 'set-current',
        className: 'text-yellow-500',
        requiredPermission: 'manage-payroll-settings'
      });
    }

    // Add Close or Reopen based on status
    if (item.status === 'active') {
      baseActions.push({
        label: t('Close Year'),
        icon: 'Lock',
        action: 'close',
        className: 'text-gray-500',
        requiredPermission: 'manage-payroll-settings'
      });
    } else {
      baseActions.push({
        label: t('Reopen Year'),
        icon: 'Unlock',
        action: 'reopen',
        className: 'text-green-500',
        requiredPermission: 'manage-payroll-settings'
      });
    }

    // Always add delete at the end
    baseActions.push({
      label: t('Delete'),
      icon: 'Trash2',
      action: 'delete',
      className: 'text-red-500',
      requiredPermission: 'manage-payroll-settings'
    });

    return baseActions;
  };

  // Default actions for the table
  const actions = [
    {
      label: t('View'),
      icon: 'Eye',
      action: 'view',
      className: 'text-blue-500',
      requiredPermission: 'manage-payroll-settings'
    },
    {
      label: t('Edit'),
      icon: 'Edit',
      action: 'edit',
      className: 'text-amber-500',
      requiredPermission: 'manage-payroll-settings'
    },
    {
      label: t('Set as Current'),
      icon: 'Star',
      action: 'set-current',
      className: 'text-yellow-500',
      requiredPermission: 'manage-payroll-settings'
    },
    {
      label: t('Close Year'),
      icon: 'Lock',
      action: 'close',
      className: 'text-gray-500',
      requiredPermission: 'manage-payroll-settings'
    },
    {
      label: t('Reopen Year'),
      icon: 'Unlock',
      action: 'reopen',
      className: 'text-green-500',
      requiredPermission: 'manage-payroll-settings'
    },
    {
      label: t('Delete'),
      icon: 'Trash2',
      action: 'delete',
      className: 'text-red-500',
      requiredPermission: 'manage-payroll-settings'
    }
  ];

  // Status options for filter
  const statusOptions = [
    { value: 'all', label: t('Select Status'), disabled: true },
    { value: 'active', label: t('Active') },
    { value: 'closed', label: t('Closed') }
  ];

  return (
    <PageTemplate
      title={t("Financial Years")}
      description={t("Manage financial years for payroll and reporting")}
      url="/hr/financial-years"
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
            router.get(route('hr.financial-years.index'), {
              page: 1,
              per_page: parseInt(value),
              search: searchTerm || undefined,
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
          data={financialYears?.data || []}
          from={financialYears?.from || 1}
          onAction={handleAction}
          sortField={pageFilters.sort_field}
          sortDirection={pageFilters.sort_direction}
          onSort={handleSort}
          permissions={permissions}
          entityPermissions={{
            view: 'manage-payroll-settings',
            edit: 'manage-payroll-settings',
            delete: 'manage-payroll-settings'
          }}
        />

        {/* Pagination section */}
        <Pagination
          from={financialYears?.from || 0}
          to={financialYears?.to || 0}
          total={financialYears?.total || 0}
          links={financialYears?.links}
          entityName={t("financial years")}
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
            { name: 'name', label: t('Financial Year Name'), type: 'text', required: true, placeholder: t('e.g., FY 2026, 2026-2027') },
            { name: 'start_date', label: t('Start Date'), type: 'date', required: true },
            { name: 'end_date', label: t('End Date'), type: 'date', required: true },
            {
              name: 'status',
              label: t('Status'),
              type: 'select',
              options: [
                { value: 'active', label: t('Active') },
                { value: 'closed', label: t('Closed') }
              ],
              defaultValue: 'active'
            },
            {
              name: 'is_current',
              label: t('Set as Current Financial Year'),
              type: 'checkbox',
              defaultValue: false
            },
            { name: 'notes', label: t('Notes'), type: 'textarea' }
          ],
          modalSize: 'lg'
        }}
        initialData={currentItem}
        title={
          formMode === 'create'
            ? t('Add New Financial Year')
            : formMode === 'edit'
              ? t('Edit Financial Year')
              : t('View Financial Year')
        }
        mode={formMode}
      />

      {/* Delete Modal */}
      <CrudDeleteModal
        isOpen={isDeleteModalOpen}
        onClose={() => setIsDeleteModalOpen(false)}
        onConfirm={handleDeleteConfirm}
        itemName={currentItem?.name || ''}
        entityName="financial year"
      />
    </PageTemplate>
  );
}
