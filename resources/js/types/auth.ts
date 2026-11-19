export type User = {
    id: number;
    name: string;
    email: string | null;
    github_username: string;
    avatar?: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
