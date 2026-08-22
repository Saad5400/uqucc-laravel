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

/** The «I have not declared a programme yet» choice, which resolves to the general group. */
export const GENERAL_MAJOR = '__general__';

/** The avatar letter for a supervisor — codepoint-safe, so Arabic names work. */
export function initialOf(name: string): string {
    return [...name.trim()][0] ?? '؟';
}

/** Arabic label for a contact method, used on buttons and links. */
export function contactLabel(kind: ContactKind): string {
    return kind === 'telegram' ? 'تيليجرام' : 'واتساب';
}

/**
 * The section a student of `sectionKey` should be shown: their own if the group
 * splits by section, otherwise the mixed «للشطرين» roster the general lists use.
 */
export function sectionFor(group: StudentGroup, sectionKey: string): GroupSection | null {
    return group.sections.find((section) => section.key === sectionKey) ?? group.sections.find((section) => section.key === 'both') ?? null;
}
