export type EvaluationStatus =
    | 'preparing'
    | 'awaiting_answers'
    | 'writing'
    | 'completed'
    | 'failed';

export type AreaKey =
    | 'delivery'
    | 'code_quality'
    | 'review'
    | 'communication'
    | 'process'
    | 'understanding';

export type EvaluationArea = {
    key: AreaKey;
    label: string;
    weight: number;
};

export type AnswerNote = {
    understanding: number;
    note: string;
};

export type Evaluation = {
    status: EvaluationStatus;
    level: string;
    questions: string[] | null;
    answers: string[] | null;
    answer_notes: AnswerNote[] | null;
    // A null score means the area could not be judged.
    scores: Record<AreaKey, number | null> | null;
    overall: number | null;
    passed: boolean | null;
    communication_evidence: string | null;
    strengths: string | null;
    growth: string | null;
    advice: string | null;
    promoted_to: string | null;
    completed_at: string | null;
};

export type LevelProgressData = {
    level: string;
    passed: number;
    needed: number;
    next: string | null;
};

// What the Overview knows about the evaluation of the last sprint.
export type EvaluationState = {
    status: EvaluationStatus;
    overall: number | null;
    passed: boolean | null;
    url: string;
};
