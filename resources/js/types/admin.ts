export type UsageRow = {
    calls: number;
    input_tokens: number;
    output_tokens: number;
    cost_micros: number;
};

export type FeatureUsage = UsageRow & { key: string; label: string };

export type ModelUsage = UsageRow & { model: string; priced: boolean };

export type DayUsage = {
    date: string;
    calls: number;
    cost_micros: number;
};
