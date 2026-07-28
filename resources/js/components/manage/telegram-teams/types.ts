export interface TeamCategory {
    id: number;
    name: string;
}

/** An extra name the team answers to in Telegram («cs» for «علوم الحاسب»). */
export interface TeamAlias {
    id: number;
    name: string;
}

export interface TeamMember {
    id: number;
    telegram_user_id: string;
    name: string;
    username: string | null;
    consented_at: string | null;
    added_by_telegram_id: string;
}

export interface Team {
    id: number;
    name: string;
    category_id: number | null;
    aliases: TeamAlias[];
    members: TeamMember[];
}

/** Sentinel for "no category" — reka-ui selects reserve the empty string. */
export const NO_CATEGORY = 'none';

/**
 * Batch teams are named with digits («43», «44»…) so a plain string sort puts
 * «10» before «9». Collate numerically, in Arabic, ignoring diacritics/case.
 */
const collator = new Intl.Collator('ar', { numeric: true, sensitivity: 'base' });

export function sortTeams(teams: Team[]): Team[] {
    return [...teams].sort((a, b) => collator.compare(a.name, b.name));
}

/** Matches a team by its own name or by any alias — that is how members type it. */
export function teamMatches(team: Team, query: string): boolean {
    const needle = query.trim();

    if (needle === '') {
        return true;
    }

    return team.name.includes(needle) || team.aliases.some((alias) => alias.name.includes(needle));
}

export function memberMatches(member: TeamMember, query: string): boolean {
    const needle = query.trim();

    if (needle === '') {
        return true;
    }

    return member.name.includes(needle) || (member.username ?? '').includes(needle) || member.telegram_user_id.includes(needle);
}
