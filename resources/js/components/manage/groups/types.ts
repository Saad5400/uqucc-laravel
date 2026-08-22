export interface CohortRow {
    id: number;
    name: string;
    description: string | null;
    note: string | null;
    requirements: string[];
    is_active: boolean;
    is_featured: boolean;
    groups_count: number;
    supervisors_count: number;
    available_supervisors_count: number;
}

export interface SupervisorContact {
    kind: 'telegram' | 'whatsapp';
    handle: string;
    url: string;
}

export interface SupervisorRow {
    id: number;
    name: string;
    /** Bare Telegram handle, no leading «@» — render inside a `dir="ltr"` island. */
    telegram_username: string | null;
    /** Local Saudi form («05XXXXXXXX») for editing; the column stores it international. */
    whatsapp_number: string | null;
    contacts: SupervisorContact[];
    section: string;
    is_available: boolean;
}

export interface GroupRow {
    id: number;
    name: string;
    is_general: boolean;
    major: string | null;
    branch: string | null;
    branch_label: string;
    is_active: boolean;
    supervisors: SupervisorRow[];
}

export interface TaxonomyOption {
    value: string;
    label: string;
}

export interface Taxonomy {
    majors: TaxonomyOption[];
    branches: TaxonomyOption[];
    sections: TaxonomyOption[];
}

/** The empty-string sentinel a `<Select>` needs, since it cannot hold null. */
export const NO_VALUE = '__none__';

export function toSelectValue(value: string | null): string {
    return value ?? NO_VALUE;
}

export function fromSelectValue(value: string): string | null {
    return value === NO_VALUE ? null : value;
}
