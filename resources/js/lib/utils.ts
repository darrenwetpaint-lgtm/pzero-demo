import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

// A fixed locale + fixed timeZone (never the runtime's own defaults), used
// ONLY as the hydration-safe seed value rendered identically by SSR (Node)
// and the client's first paint — see components/LocalDateTime.vue, which
// swaps this for the browser's own local time once mounted (client-only,
// after hydration has already completed, so there's nothing to mismatch).
// Product intent is local-operator-time, not UTC; this fixed formatting
// exists solely to bridge the moment before the client knows its own
// timezone, never as the steady-state displayed value.
const DATE_TIME_FORMATTER_UTC = new Intl.DateTimeFormat('en-ZA', {
    timeZone: 'UTC',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
});

export function formatDateTimeUtc(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return `${DATE_TIME_FORMATTER_UTC.format(new Date(value))} UTC`;
}

// The browser's own locale/timezone (both left undefined so Intl resolves
// them from the runtime) — only ever called client-side, after mount.
export function formatDateTimeLocal(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(new Date(value));
}
