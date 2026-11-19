export type SprintStatus = 'active' | 'paused' | 'completed';

// Shared prop: the user's current sprint (null when there is none).
export type SprintSummary = {
    id: number;
    status: SprintStatus;
    scenario_title: string;
    current_day: number;
    duration_days: number;
    deadline_at: string;
    is_overdue: boolean;
};

export type TicketStatus = 'todo' | 'in_progress' | 'in_review' | 'done';
export type TicketType = 'bug' | 'feature' | 'review' | 'hotfix';

export type StatusOption = {
    value: TicketStatus;
    label: string;
};

export type Colleague = {
    key: string;
    name: string;
    role: string;
};

export type BoardTicket = {
    id: number;
    key: string;
    title: string;
    type: TicketType;
    status: TicketStatus;
    day: number;
    description: string;
    acceptance_criteria: string[];
    reporter: Colleague;
    pull_request: { number: number; url: string } | null;
    suggested_branch: string;
};

export type ScenarioOffer = {
    id: number;
    title: string;
    summary: string | null;
    level: string;
    duration_days: number;
};

export type SprintOverview = {
    ticket_counts: (StatusOption & { count: number })[];
    todays_tickets: { key: string; title: string; status: TicketStatus }[];
    latest_messages: { colleague: string; body: string; created_at: string }[];
};

export type ChatColleague = Colleague & {
    unread: number;
    last_message: string | null;
};

export type ChatMessage = {
    id: number;
    body: string;
    from_user: boolean;
    created_at: string;
    ticket_key: string | null;
};
