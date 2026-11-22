// Costs are stored in micros: millionths of a US dollar.
export function formatDollars(micros: number): string {
    return `$${(micros / 1_000_000).toFixed(2)}`;
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}
