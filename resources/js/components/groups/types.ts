export type ContactKind = 'telegram' | 'whatsapp';

export interface SupervisorContact {
    kind: ContactKind;
    /** «@handle» for Telegram, «05XXXXXXXX» for WhatsApp — machine text, render LTR. */
    handle: string;
    url: string;
}

export interface GroupSupervisor {
    id: number;
    name: string;
    contacts: SupervisorContact[];
}

export interface GroupSection {
    key: string;
    label: string;
    supervisors: GroupSupervisor[];
}

export interface StudentGroup {
    id: number;
    name: string;
    is_general: boolean;
    major: string | null;
    branch: string | null;
    branch_label: string;
    sections: GroupSection[];
    supervisors_count: number;
}

export interface Cohort {
    id: number;
    name: string;
    description: string | null;
    note: string | null;
    requirements: string[];
    is_featured: boolean;
    groups: StudentGroup[];
}

/** Which section a visitor wants to see; «all» is the unfiltered default. */
export type SectionFilter = 'all' | 'men' | 'women';

/**
 * A «للشطرين» roster answers to either filter — that is what it is for — so it
 * survives every setting, and «all» keeps everything.
 */
export function sectionMatchesFilter(sectionKey: string, filter: SectionFilter): boolean {
    return filter === 'all' || sectionKey === 'both' || sectionKey === filter;
}

/** The avatar letter for a supervisor — codepoint-safe, so Arabic names work. */
export function initialOf(name: string): string {
    return [...name.trim()][0] ?? '؟';
}

/** Arabic label for a contact method, used on buttons and links. */
export function contactLabel(kind: ContactKind): string {
    return kind === 'telegram' ? 'تيليجرام' : 'واتساب';
}
