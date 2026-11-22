export type TourPart = 'overview' | 'board' | 'chat';

export type User = {
    id: number;
    name: string;
    email: string | null;
    github_username: string;
    avatar?: string;
    level: string;
    level_label: string;
    /** Parts of the guided tour the user finished or skipped. */
    tours_done: TourPart[];
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    is_admin: boolean;
};
