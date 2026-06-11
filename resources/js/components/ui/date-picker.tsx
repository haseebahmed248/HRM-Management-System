"use client"

import * as React from "react"
import { format } from "date-fns"
import { Calendar as CalendarIcon } from "lucide-react"

import { cn } from "@/lib/utils"
import { Input } from "@/components/ui/input"

interface DatePickerProps {
  selected?: Date | string
  onSelect?: (date: Date | string | undefined) => void
  onChange?: (date: Date | string | undefined) => void
  placeholder?: string
  disabled?: boolean
  /** When true, returns YYYY-MM-DD string instead of Date object (avoids timezone issues) */
  returnString?: boolean
}

export function DatePicker({
  selected,
  onSelect,
  onChange,
  placeholder = "Pick a date",
  disabled = false,
  returnString = true,
}: DatePickerProps) {
  const inputRef = React.useRef<HTMLInputElement>(null);

  // Convert selected to string for the input
  const getDateString = (val: Date | string | undefined): string => {
    if (!val) return '';
    if (typeof val === 'string') return val.slice(0, 10); // Handle ISO strings
    return format(val, 'yyyy-MM-dd');
  };

  const [date, setDate] = React.useState<string>(getDateString(selected));

  const handleDateChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setDate(e.target.value);
    if (e.target.value) {
      // Return string to avoid timezone conversion issues
      const result = returnString ? e.target.value : new Date(e.target.value + 'T00:00:00');
      if (onSelect) onSelect(result);
      if (onChange) onChange(result);
    } else {
      if (onSelect) onSelect(undefined);
      if (onChange) onChange(undefined);
    }
  };

  React.useEffect(() => {
    setDate(getDateString(selected));
  }, [selected]);

  const openPicker = () => {
    if (!disabled && inputRef.current) {
      inputRef.current.showPicker?.();
      inputRef.current.focus();
    }
  };

  return (
    <div className="relative cursor-pointer" onClick={openPicker}>
      <CalendarIcon className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground pointer-events-none" />
      <Input
        ref={inputRef}
        type="date"
        value={date}
        onChange={handleDateChange}
        className="pl-9 w-[240px] cursor-pointer"
        disabled={disabled}
      />
    </div>
  )
}