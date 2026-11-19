export type User = {
    id: number;
    name: string;
    email: string | null;
    github_username: string;
    avatar?: string;
    level: string;
    level_label: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    is_admin: boolean;
};
