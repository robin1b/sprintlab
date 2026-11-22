const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function plural(count: number, unit: string): string {
    return `${count} ${unit}${count === 1 ? '' : 's'}`;
}

// "3d 4h", "4h 12m" or "12m": compact, for the top bar.
export function compactDuration(ms: number): string {
    const total = Math.max(0, ms);
    const days = Math.floor(total / DAY);
    const hours = Math.floor((total % DAY) / HOUR);
    const minutes = Math.floor((total % HOUR) / MINUTE);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

// "3 days", "2 hours" or "12 minutes": the largest unit only, for sentences.
export function wordyDuration(ms: number): string {
    const total = Math.max(0, ms);

    if (total >= DAY) {
        return plural(Math.floor(total / DAY), 'day');
    }

    if (total >= HOUR) {
        return plural(Math.floor(total / HOUR), 'hour');
    }

    return plural(Math.max(1, Math.floor(total / MINUTE)), 'minute');
}

export function deadlineInWords(deadlineAt: string, now: number): string {
    const left = new Date(deadlineAt).getTime() - now;

    return left >= 0
        ? `due in ${wordyDuration(left)}`
        : `overdue by ${wordyDuration(-left)}`;
}

const timeFormat = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});
const dateFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

// "14:05", or "12 Nov 14:05" when the message is not from today.
export function formatMessageTime(iso: string, now: Date = new Date()): string {
    const date = new Date(iso);
    const time = timeFormat.format(date);

    return date.toDateString() === now.toDateString()
        ? time
        : `${dateFormat.format(date)} ${time}`;
}
