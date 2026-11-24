import type { LaravelPaginator, SelectOption } from '@/types';

export type AnnouncementStatus = 'scheduled' | 'sent' | 'withdrawn';
export type AudienceType =
    | 'all'
    | 'branches'
    | 'departments'
    | 'positions'
    | 'employees';
export type Priority = 'normal' | 'high';

export type AnnouncementStats = {
    total: number;
    delivered: number;
    read: number;
    confirmed: number;
    push_sent: number;
    failed: number;
};

export type AnnouncementRow = {
    id: number;
    title: string;
    excerpt: string;
    priority: Priority;
    requires_confirmation: boolean;
    audience_type: AudienceType;
    audience_label: string;
    audience_count: number;
    status: AnnouncementStatus;
    scheduled_at: string | null;
    sent_at: string | null;
    withdrawn_at: string | null;
    created_at: string | null;
    created_by: string | null;
    stats: AnnouncementStats;
};

export type ReminderSettings = {
    late: { enabled: boolean; grace_minutes: number };
    clock_out: { enabled: boolean; minutes_after: number };
};

export type AnnouncementsIndexProps = {
    announcements: LaravelPaginator<AnnouncementRow>;
    filters: { status: AnnouncementStatus | null; search: string };
    settings: ReminderSettings;
    push: { enabled: boolean };
    punch_receipt: { mandatory: boolean };
    can: { send: boolean };
};

export type AnnouncementCreateProps = {
    options: {
        branches: SelectOption[];
        departments: SelectOption[];
        positions: SelectOption[];
    };
    employeeOptions: SelectOption[];
    estimate?: number;
    timezone: string;
    push: { enabled: boolean };
};

export type PushStatus =
    | 'pending'
    | 'sent'
    | 'no_device'
    | 'disabled'
    | 'failed';

export type RecipientRow = {
    id: number;
    employee: { id: number; name: string; employee_number: string } | null;
    delivered_at: string | null;
    read_at: string | null;
    confirmed_at: string | null;
    push_status: PushStatus;
    error: string | null;
};

export type AnnouncementShowProps = {
    announcement: AnnouncementRow & {
        body: string;
        audience_names: string[];
        withdrawn_by: string | null;
    };
    recipients: LaravelPaginator<RecipientRow>;
    filters: { state: 'read' | 'unread' | 'failed' | 'unconfirmed' | null };
    can: { send: boolean };
};
