export type BookingCopy = Record<string, string>;

export type BookingLocale = 'nl' | 'en';

export type BookingDay = {
    weekday: number;
    enabled: boolean;
    start: string;
    end: string;
};
