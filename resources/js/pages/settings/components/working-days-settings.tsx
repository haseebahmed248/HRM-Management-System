import { toast } from '@/components/custom-toast';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { router, usePage } from '@inertiajs/react';
import { Clock3, Loader2, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

type WorkDaysPattern = 'mon_fri' | 'mon_sat' | 'custom';

interface WorkingDaysSettingsProps {
    settings?: Record<string, unknown>;
}

interface ScheduleState {
    pattern: WorkDaysPattern;
    hoursPerDay: string;
    hoursPerWeek: string;
    daysPerMonth: string;
    workingDays: number[];
}

const PRESETS: Record<Exclude<WorkDaysPattern, 'custom'>, Omit<ScheduleState, 'pattern'>> = {
    mon_fri: {
        hoursPerDay: '8',
        hoursPerWeek: '40',
        daysPerMonth: '22',
        workingDays: [1, 2, 3, 4, 5],
    },
    mon_sat: {
        hoursPerDay: '8',
        hoursPerWeek: '48',
        daysPerMonth: '26',
        workingDays: [1, 2, 3, 4, 5, 6],
    },
};

const parseWorkingDays = (value: unknown): number[] => {
    try {
        const parsed = typeof value === 'string' ? JSON.parse(value) : value;
        if (Array.isArray(parsed)) {
            return parsed.map(Number).filter((day) => Number.isInteger(day) && day >= 0 && day <= 6);
        }
    } catch {
        // Invalid legacy values fall back to the normal Monday-Friday schedule.
    }

    return PRESETS.mon_fri.workingDays;
};

export default function WorkingDaysSettings({ settings = {} }: WorkingDaysSettingsProps) {
    const { t } = useTranslation();
    const { globalSettings } = usePage().props as {
        globalSettings?: { is_demo?: boolean };
    };
    const initialWorkingDays = useMemo(() => parseWorkingDays(settings.working_days), [settings.working_days]);
    const configuredPattern = settings.work_days_pattern;
    const initialPattern: WorkDaysPattern = configuredPattern === 'mon_sat' || configuredPattern === 'custom' ? configuredPattern : 'mon_fri';

    const [schedule, setSchedule] = useState<ScheduleState>({
        pattern: initialPattern,
        hoursPerDay: String(settings.hours_per_day ?? 8),
        hoursPerWeek: String(settings.hours_per_week ?? 40),
        daysPerMonth: String(settings.working_days_per_month ?? 22),
        workingDays: initialWorkingDays,
    });
    const [processing, setProcessing] = useState(false);

    const days = [
        { index: 1, short: t('Mon'), label: t('Monday') },
        { index: 2, short: t('Tue'), label: t('Tuesday') },
        { index: 3, short: t('Wed'), label: t('Wednesday') },
        { index: 4, short: t('Thu'), label: t('Thursday') },
        { index: 5, short: t('Fri'), label: t('Friday') },
        { index: 6, short: t('Sat'), label: t('Saturday') },
        { index: 0, short: t('Sun'), label: t('Sunday') },
    ];

    const selectPattern = (pattern: WorkDaysPattern) => {
        if (pattern === 'custom') {
            setSchedule((current) => ({ ...current, pattern }));
            return;
        }

        setSchedule({ pattern, ...PRESETS[pattern] });
    };

    const toggleWorkingDay = (day: number, checked: boolean) => {
        setSchedule((current) => ({
            ...current,
            pattern: 'custom',
            workingDays: checked
                ? [...current.workingDays, day].sort((a, b) => a - b)
                : current.workingDays.filter((workingDay) => workingDay !== day),
        }));
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();

        if (schedule.workingDays.length === 0) {
            toast.error(t('Select at least one working day.'));
            return;
        }

        if (!globalSettings?.is_demo) {
            toast.loading(t('Saving working days settings...'));
        }
        setProcessing(true);

        router.post(
            route('settings.working-days.update'),
            {
                work_days_pattern: schedule.pattern,
                hours_per_day: Number(schedule.hoursPerDay),
                hours_per_week: Number(schedule.hoursPerWeek),
                working_days_per_month: Number(schedule.daysPerMonth),
                working_days: schedule.workingDays,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!globalSettings?.is_demo) {
                        toast.dismiss();
                    }
                    const successMessage = page.props.flash?.success;
                    const errorMessage = page.props.flash?.error;

                    if (successMessage) {
                        toast.success(successMessage);
                    } else if (errorMessage) {
                        toast.error(errorMessage);
                    } else {
                        toast.success(t('Working days settings saved successfully'));
                    }
                },
                onError: (errors) => {
                    if (!globalSettings?.is_demo) {
                        toast.dismiss();
                    }
                    toast.error(Object.values(errors).join(', ') || t('Failed to save working days settings'));
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SettingsSection
            title={t('Working Days')}
            description={t('Set the normal work schedule used for payroll rates, payslips, and leave pro-rating.')}
            action={
                <Button type="submit" form="working-days-form" size="sm" disabled={processing}>
                    {processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                    {t('Save Changes')}
                </Button>
            }
        >
            <form id="working-days-form" onSubmit={handleSubmit} className="space-y-6">
                <div className="space-y-2">
                    <Label>{t('Schedule Pattern')}</Label>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={schedule.pattern}
                        onValueChange={(value) => value && selectPattern(value as WorkDaysPattern)}
                        className="grid w-full grid-cols-1 sm:grid-cols-3"
                    >
                        <ToggleGroupItem value="mon_fri" className="w-full">
                            {t('Mon-Fri')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="mon_sat" className="w-full">
                            {t('Mon-Sat')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="custom" className="w-full">
                            {t('Custom')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div className="space-y-2">
                        <Label htmlFor="hours-per-day">{t('Hours per Day')}</Label>
                        <Input
                            id="hours-per-day"
                            type="number"
                            min="0.01"
                            max="24"
                            step="0.01"
                            value={schedule.hoursPerDay}
                            onChange={(event) => setSchedule((current) => ({ ...current, hoursPerDay: event.target.value }))}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="hours-per-week">{t('Hours per Week')}</Label>
                        <Input
                            id="hours-per-week"
                            type="number"
                            min="0.01"
                            max="168"
                            step="0.01"
                            value={schedule.hoursPerWeek}
                            onChange={(event) => setSchedule((current) => ({ ...current, hoursPerWeek: event.target.value }))}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="working-days-per-month">{t('Working Days per Month')}</Label>
                        <Input
                            id="working-days-per-month"
                            type="number"
                            min="1"
                            max="31"
                            step="1"
                            value={schedule.daysPerMonth}
                            onChange={(event) => setSchedule((current) => ({ ...current, daysPerMonth: event.target.value }))}
                            required
                        />
                    </div>
                </div>

                <div className="space-y-3 border-t pt-5">
                    <div className="flex items-center gap-2">
                        <Clock3 className="text-muted-foreground h-4 w-4" />
                        <Label>{t('Working Week')}</Label>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                        {days.map((day) => (
                            <div key={day.index} className="flex min-h-14 items-center justify-between gap-2 rounded-md border px-3 py-2">
                                <Label htmlFor={`working-day-${day.index}`} title={day.label} className="cursor-pointer text-sm">
                                    {day.short}
                                </Label>
                                <Switch
                                    id={`working-day-${day.index}`}
                                    aria-label={day.label}
                                    checked={schedule.workingDays.includes(day.index)}
                                    onCheckedChange={(checked) => toggleWorkingDay(day.index, checked)}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </form>
        </SettingsSection>
    );
}
