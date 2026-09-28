export type ApiToken = {
    id: number;
    name: string;
    abilities: string[];
    app: string | null;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

export type ApiAbilityOption = {
    value: string;
    description: string;
};
