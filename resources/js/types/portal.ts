export type PortalApp = {
    slug: string;
    name: string;
    initials: string;
    accent: string | null;
    launch_url: string;
    current: boolean;
};

export type PortalCategory = {
    category: string;
    apps: PortalApp[];
};
