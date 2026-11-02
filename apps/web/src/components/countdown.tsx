"use client";

import { useNow } from "@/hooks/use-now";
import { useI18n } from "@/i18n/client";
import { formatDate, formatNumber, interpolate, plural, splitDuration } from "@/i18n/format";
import type { Dictionary } from "@/i18n/dictionaries";
import type { Locale } from "@/i18n/config";

/** "3 days and 4 hours", "2 hours and 5 minutes", "less than a minute". */
export function humanizeDuration(ms: number, t: Dictionary, locale: Locale): string {
  const { days, hours, minutes } = splitDuration(ms);
  const parts: string[] = [];
  if (days > 0) {
    parts.push(plural(t.common.days, days, locale));
    if (hours > 0) parts.push(plural(t.common.hours, hours, locale));
  } else if (hours > 0) {
    parts.push(plural(t.common.hours, hours, locale));
    if (minutes > 0) parts.push(plural(t.common.minutes, minutes, locale));
  } else if (minutes > 0) {
    parts.push(plural(t.common.minutes, minutes, locale));
  } else {
    return t.common.lessThanMinute;
  }
  return parts.join(t.common.and);
}

/**
 * Relative time until `iso` once the browser clock is known; the absolute
 * date before that. `text` is a dictionary string with a {time} slot (a
 * string rather than a function, so Server Components can pass it).
 */
export function TimeUntil({ iso, template, text }: { iso: string; template?: (time: string) => string; text?: string }) {
  const { t, locale } = useI18n();
  const now = useNow();
  const target = Date.parse(iso);
  const time = now === null ? formatDate(iso, locale, "dateTime") : humanizeDuration(target - now, t, locale);
  const shown = template ? template(time) : text ? interpolate(text, { time }) : time;
  return <time dateTime={iso}>{shown}</time>;
}

/** Days / hours / minutes / seconds boxes for the campaign page. */
export function CountdownBoxes({ iso, label }: { iso: string; label: string }) {
  const { t, locale } = useI18n();
  const now = useNow();
  const remaining = now === null ? null : Math.max(0, Date.parse(iso) - now);
  const parts = remaining === null ? null : splitDuration(remaining);
  const units = [
    ["days", parts?.days],
    ["hours", parts?.hours],
    ["minutes", parts?.minutes],
    ["seconds", parts?.seconds],
  ] as const;

  return (
    <div>
      <p className="text-sm text-slate-600">{label}</p>
      <div className="mt-2 grid grid-cols-4 gap-2" role="timer" aria-live="off">
        {units.map(([key, value]) => (
          <div key={key} className="rounded-xl bg-slate-100 px-2 py-2 text-center">
            <span className="block text-xl font-bold tabular-nums text-slate-900">
              {value === undefined ? "–" : formatNumber(value, locale).padStart(2, "0")}
            </span>
            <span className="block text-xs text-slate-600">{t.common.countdown[key]}</span>
          </div>
        ))}
      </div>
      <p className="mt-2 text-xs text-slate-500">
        <time dateTime={iso}>{formatDate(iso, locale, "long")}</time>
      </p>
    </div>
  );
}
