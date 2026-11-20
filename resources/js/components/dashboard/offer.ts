import type { ScenarioOffer } from '@/types/sprint';

// "junior · sprint 1 · 5 days", or "demo · 2 days" for a demo.
export function offerFacts(offer: ScenarioOffer): string {
    const days = `${offer.duration_days} ${offer.duration_days === 1 ? 'day' : 'days'}`;

    return offer.is_demo
        ? `demo · ${days}`
        : `${offer.level} · sprint ${offer.sequence} · ${days}`;
}

// "Passed · 82", "Not passed · 55", "Played" or null when never played.
export function offerResult(offer: ScenarioOffer): string | null {
    const result = offer.result;

    if (result === null) {
        return null;
    }

    if (result.passed === null) {
        return 'Played';
    }

    const verdict = result.passed ? 'Passed' : 'Not passed';

    return result.overall === null ? verdict : `${verdict} · ${result.overall}`;
}
