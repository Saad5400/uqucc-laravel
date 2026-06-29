/**
 * UQU Academic Transcript Parser — client-side, ported from the shared
 * reference implementation. Two stages:
 *
 *   1) reconstructLayoutLines(items): turn PDF.js positioned text items into
 *      monospace-style layout lines (the browser has no `pdftotext -layout`,
 *      so we rebuild the column grid from x/y coordinates + gaps).
 *   2) UquTranscriptParser.parseLayoutText(text): English columns are
 *      authoritative, Arabic names recovered via NFKC, and every course's
 *      Points value is validated against credits * grade-weight.
 *
 * The parsing logic intentionally mirrors the reference parser verbatim; only
 * the module wrapper and type annotations were added.
 */

export interface GradeScaleEntry {
    ar: string;
    weight: number | null;
    desc: string;
    pct: string | null;
}

export interface TranscriptStudent {
    student_id: string | null;
    name_en: string | null;
    name_ar: string | null;
    degree: string | null;
    faculty: string | null;
    major: string | null;
    study_type: string | null;
    status: string | null;
    gpa: number | null;
}

export interface TranscriptCourse {
    code: string;
    name_en: string | null;
    name_ar: string | null;
    credit_hours: number;
    pass_hours: number;
    degree: number | null;
    grade: string;
    grade_ar: string | null;
    grade_weight: number | null;
    points: number;
    points_valid: boolean | null;
}

export interface TranscriptSemesterSummary {
    semester_gpa: number | null;
    cumulative_gpa: number | null;
    standing: string | null;
    credit_hours: number | null;
    passed_hours: number | null;
    points: number | null;
}

export interface TranscriptSemester {
    term: string;
    academic_year: string;
    term_hijri: string | null;
    status: string | null;
    faculty: string | null;
    department: string | null;
    major: string | null;
    courses: TranscriptCourse[];
    summary: TranscriptSemesterSummary;
}

export interface TranscriptTotals {
    credit_hours: number;
    passed_hours: number;
    cumulative_gpa: number;
    points: number;
}

export interface TranscriptResult {
    student: TranscriptStudent;
    semesters: TranscriptSemester[];
    totals: TranscriptTotals | null;
    grade_scale: Record<string, { arabic: string; weight_4: number | null; description: string; percent: string | null }>;
    meta?: {
        pages: number;
        extracted_at: string;
        parser: string;
        warnings: string[];
    };
}

// Official UQU grade scale (weight on the 4.0 scale). null = not counted.
export const GRADE_SCALE: Record<string, GradeScaleEntry> = {
    'A+': { ar: 'أ+', weight: 4.0, desc: 'Exceptional', pct: '95-100' },
    A: { ar: 'أ', weight: 3.75, desc: 'Excellent', pct: '90-<95' },
    'B+': { ar: 'ب+', weight: 3.5, desc: 'Superior', pct: '85-<90' },
    B: { ar: 'ب', weight: 3.0, desc: 'Very Good', pct: '80-<85' },
    'C+': { ar: 'ج+', weight: 2.5, desc: 'Above Average', pct: '75-<80' },
    C: { ar: 'ج', weight: 2.0, desc: 'Good', pct: '70-<75' },
    'D+': { ar: 'د+', weight: 1.5, desc: 'High Pass', pct: '65-<70' },
    D: { ar: 'د', weight: 1.0, desc: 'Pass', pct: '60-<65' },
    F: { ar: 'هـ', weight: 0.0, desc: 'Fail', pct: '<60' },
    DN: { ar: 'ح', weight: 0.0, desc: 'Denied', pct: null },
    IP: { ar: 'م', weight: null, desc: 'In-Progress', pct: null },
    IC: { ar: 'ل', weight: null, desc: 'In-Complete', pct: null },
    NP: { ar: 'ند', weight: null, desc: 'Nograde-Pass', pct: null },
    NF: { ar: 'هد', weight: null, desc: 'Nograde-Fail', pct: null },
    W: { ar: 'ع', weight: null, desc: 'Withdrawn', pct: null },
    E: { ar: 'عف', weight: null, desc: 'Exemption', pct: null },
};

// Two-char tokens before their one-char prefixes.
const GRADE_TOKENS = 'A\\+|A|B\\+|B|C\\+|C|D\\+|D|F|DN|IP|IC|NP|NF|W|E';

const RX_ARABIC = /[\u0600-\u06FF\u0750-\u077F\uFB50-\uFDFF\uFE70-\uFEFF]/;
const RX_ARABIC_LETTER = /[\u0621-\u064A\u066E-\u06D3\uFB50-\uFDFF\uFE70-\uFEFF]/;
const RX_BIDI = /[\u061C\u200E\u200F\u202A-\u202E\u2066-\u2069]/g;

function median(arr: number[]): number {
    if (!arr.length) {
        return 0;
    }
    const s = arr.slice().sort((a, b) => a - b);
    return s[Math.floor(s.length / 2)];
}

/**
 * Rebuild layout lines from PDF.js text items.
 * Each item: { str, transform:[a,b,c,d,e,f], width, height }.
 * x = transform[4], y = transform[5] (y grows upward).
 */
export function reconstructLayoutLines(items: any[]): string[] {
    const its: any[] = [];
    for (const it of items) {
        if (!it.str || it.str.length === 0) {
            continue;
        }
        const x = it.transform[4];
        const y = it.transform[5];
        const w = it.width || it.str.length * 4;
        const h = it.height || Math.abs(it.transform[3]) || 8;
        its.push({ s: it.str, x: x, y: y, w: w, h: h, d: it.dir });
    }
    if (!its.length) {
        return [];
    }

    // Char-width unit: median advance per character (used to translate gaps to spaces).
    const cwSamples = its
        .filter((i) => i.s.trim().length)
        .map((i) => i.w / i.s.length)
        .filter((v) => v > 0);
    const cw = median(cwSamples) || 5;
    const tol = Math.max(3, median(its.map((i) => i.h)) * 0.7);

    // Cluster into rows by y (top to bottom).
    its.sort((a, b) => b.y - a.y || a.x - b.x);
    const rows: any[] = [];
    let cur: any = null;
    for (const it of its) {
        if (!cur || Math.abs(it.y - cur.y) > tol) {
            cur = { y: it.y, items: [it] };
            rows.push(cur);
        } else {
            cur.items.push(it);
        }
    }

    // Render each row. Items are sorted by x (visual L->R); contiguous runs of
    // RTL items are reversed so Arabic reads in logical (right-to-left) order.
    return rows.map((r) => {
        r.items.sort((a: any, b: any) => a.x - b.x);
        const seq: any[] = [];
        let i = 0;
        while (i < r.items.length) {
            const rtl = r.items[i].d === 'rtl';
            let j = i;
            while (j < r.items.length && (r.items[j].d === 'rtl') === rtl) {
                j++;
            }
            const run = r.items.slice(i, j);
            if (rtl) {
                run.reverse();
            }
            for (const it of run) {
                seq.push(it);
            }
            i = j;
        }
        let line = '';
        for (let k = 0; k < seq.length; k++) {
            const it = seq[k];
            if (k === 0) {
                const lead = Math.min(200, Math.max(0, Math.round(it.x / cw)));
                line = ' '.repeat(lead) + it.s;
                continue;
            }
            // Physical horizontal whitespace between the two items' x-intervals,
            // independent of reading direction (so reversed RTL runs still get
            // wide gaps between columns but single spaces between name words).
            const p = seq[k - 1];
            const left = p.x <= it.x ? p : it;
            const right = p.x <= it.x ? it : p;
            const gap = right.x - (left.x + left.w);
            const spaces = gap > cw * 0.6 ? Math.max(1, Math.round(gap / cw)) : 1;
            line += ' '.repeat(spaces) + it.s;
        }
        return line;
    });
}

function squash(s: string): string {
    return s.replace(/\s+/g, ' ').trim();
}

export class UquTranscriptParser {
    warnings: string[] = [];

    /** everything left of the first Arabic codepoint (bidi marks stripped) */
    englishPortion(line: string): string {
        line = line.replace(RX_BIDI, '');
        const m = RX_ARABIC.exec(line);
        return (m ? line.slice(0, m.index) : line).replace(/\s+$/, '');
    }

    /** recover Arabic name from the right side */
    arabicName(line: string, stripLabels: string[] | null): string | null {
        let n = line.normalize ? line.normalize('NFKC') : line;
        n = n.replace(RX_BIDI, '');
        const chunks = n.trim().split(/\s{2,}/);
        const nameMode = !!(stripLabels && stripLabels.length > 0);
        let best: string | null = null;
        let bestScore = 0;
        const joined: string[] = [];
        for (let c of chunks) {
            c = c.trim();
            if (!c || !RX_ARABIC_LETTER.test(c)) {
                continue;
            }
            let clean = c.replace(/[^\u0600-\u06FF\s()/-]/g, '');
            clean = squash(clean);
            if (stripLabels) {
                for (const lbl of stripLabels) {
                    clean = clean.split(lbl).join('').trim();
                }
            }
            if (clean === '') {
                continue;
            }
            const score = (clean.match(new RegExp(RX_ARABIC_LETTER.source, 'g')) || []).length;
            if (nameMode) {
                joined.push(clean);
            } else if (score > bestScore) {
                bestScore = score;
                best = clean;
            }
        }
        if (nameMode) {
            best = joined.length ? squash(joined.join(' ')) : null;
        }
        if (best) {
            // PDF text can store an RTL parenthetical with mirrored parens
            // ")١(" — swap back to "(١)" when a ')' precedes its '('.
            const cp = best.indexOf(')');
            const op = best.indexOf('(');
            if (cp !== -1 && (op === -1 || cp < op)) {
                best = best.replace(/[()]/g, (ch) => (ch === '(' ? ')' : '('));
            }
            // move a leading RTL level-number / parenthetical to the end
            const mm = best.match(/^([(\d\u0660-\u0669)/-]+)\s+([\u0600-\u06FF].*)$/);
            if (mm) {
                best = squash(mm[2] + ' ' + mm[1]);
            }
        }
        return best || null;
    }

    hijriTerm(line: string): string | null {
        const n = (line.normalize ? line.normalize('NFKC') : line).replace(RX_BIDI, '');
        const term = n.match(/(الأول|الثاني|الثالث|الصيفي)/);
        if (!term) {
            return null;
        }
        const year = n.match(/([\u0660-\u0669]{4}(?:\/[\u0660-\u0669]{4})?)/);
        return year ? term[1] + ' ' + year[1] : term[1];
    }

    parseCourseRow(en: string, fullLine: string): TranscriptCourse | null {
        const cm = en.match(/^\s*([A-Z]{2,4}\d{3,4}(?:[A-Z](?![a-z]))?)([\s\S]*)$/);
        if (!cm) {
            return null;
        }
        const code = cm[1];
        const rest = cm[2];

        const tail = new RegExp('\\s+(\\d+)\\s+(\\d+)\\s+(?:(\\d{1,3})\\s+)?(' + GRADE_TOKENS + ')\\s+(\\d+\\.\\d+)\\s*$');
        const m = tail.exec(rest);
        if (!m) {
            return null;
        }

        const nameEn = squash(rest.slice(0, m.index));
        const crd = parseInt(m[1], 10);
        const pass = parseInt(m[2], 10);
        const degree = m[3] !== undefined ? parseInt(m[3], 10) : null;
        const grade = m[4];
        const points = parseFloat(m[5]);

        const scale = GRADE_SCALE[grade] || null;
        const weight = scale ? scale.weight : null;

        let pointsValid: boolean | null = null;
        if (weight !== null) {
            const expected = Math.round(weight * crd * 100) / 100;
            pointsValid = Math.abs(expected - points) < 0.01;
            if (!pointsValid) {
                this.warnings.push(`Points mismatch for ${code}: got ${points.toFixed(2)}, expected ${expected.toFixed(2)} (${grade} x ${crd}).`);
            }
        }

        return {
            code: code,
            name_en: nameEn !== '' ? nameEn : null,
            name_ar: this.arabicName(fullLine, []),
            credit_hours: crd,
            pass_hours: pass,
            degree: degree,
            grade: grade,
            grade_ar: scale ? scale.ar : null,
            grade_weight: weight,
            points: points,
            points_valid: pointsValid,
        };
    }

    parseLayoutText(text: string): TranscriptResult {
        const lines = text.split(/\r\n|\r|\n/);
        const student: TranscriptStudent = {
            student_id: null,
            name_en: null,
            name_ar: null,
            degree: null,
            faculty: null,
            major: null,
            study_type: null,
            status: null,
            gpa: null,
        };
        const semesters: TranscriptSemester[] = [];
        let totals: TranscriptTotals | null = null;
        let current: TranscriptSemester | null = null;
        let lastCourse = -1;
        let inCourseTbl = false;

        const flush = (): void => {
            if (current) {
                semesters.push(current);
            }
            current = null;
            lastCourse = -1;
            inCourseTbl = false;
        };

        for (const raw of lines) {
            const line = raw.replace(/\s+$/, '');
            if (line === '') {
                continue;
            }
            const en = this.englishPortion(line);
            let m: RegExpMatchArray | null;

            // ---- Header (first occurrence wins) ----
            if (student.student_id === null && (m = en.match(/Student ID:\s*(\d+)/))) {
                student.student_id = m[1];
            }
            if (student.name_en === null && (m = en.match(/^\s*Name:\s*(.+?)\s*$/)) && m[1] !== '') {
                student.name_en = squash(m[1]);
                student.name_ar = this.arabicName(line, ['الاسم']);
            }
            if (student.gpa === null && (m = en.match(/\bGPA\s*:\s*([\d.]+)/))) {
                student.gpa = parseFloat(m[1]);
            }
            if (student.degree === null && (m = en.match(/Degree\s*:\s*([A-Za-z][A-Za-z ]*?)\s*(?:Study type|\s{2,}|$)/))) {
                student.degree = squash(m[1]);
            }
            if (student.study_type === null && (m = en.match(/Study type\s*:\s*([A-Za-z ]+?)\s*$/))) {
                student.study_type = squash(m[1]);
            }
            if (student.status === null && (m = en.match(/Status:\s*([A-Za-z]+)/))) {
                student.status = m[1];
            }
            if (student.faculty === null && (m = en.match(/^\s*Faculty\s+:\s*([A-Za-z][A-Za-z ]+?)\s*$/))) {
                student.faculty = squash(m[1]);
            }
            if (student.major === null && (m = en.match(/^\s*Major\s+:\s*([A-Za-z][A-Za-z ]+?)\s*$/))) {
                student.major = squash(m[1]);
            }

            // ---- Final totals ----
            if ((m = en.match(/Crd Hrs:\s*(\d+)\s+Passed Hrs:\s*(\d+)\s+Accum GPA:\s*([\d.]+)\s+Points:\s*([\d.]+)/))) {
                totals = {
                    credit_hours: parseInt(m[1], 10),
                    passed_hours: parseInt(m[2], 10),
                    cumulative_gpa: parseFloat(m[3]),
                    points: parseFloat(m[4]),
                };
                continue;
            }

            // ---- Semester header ----
            if ((m = en.match(/(First|Second|Third|Summer)\s+Semester\s+(\d{4})\/(\d{4})/))) {
                flush();
                const sm = en.match(/Status\s*:\s*([A-Za-z]+)/);
                current = {
                    term: m[1] + ' Semester',
                    academic_year: m[2] + '/' + m[3],
                    term_hijri: this.hijriTerm(line),
                    status: sm ? sm[1] : null,
                    faculty: null,
                    department: null,
                    major: null,
                    courses: [],
                    summary: {
                        semester_gpa: null,
                        cumulative_gpa: null,
                        standing: null,
                        credit_hours: null,
                        passed_hours: null,
                        points: null,
                    },
                };
                lastCourse = -1;
                inCourseTbl = false;
                continue;
            }
            if (current === null) {
                continue;
            }

            // ---- Per-semester faculty/dept/major ----
            if ((m = en.match(/^\s*Faculty\s*:\s*([A-Za-z][A-Za-z ]+?)\s*$/))) {
                current.faculty = squash(m[1]);
                continue;
            }
            if ((m = en.match(/^\s*Dept\s*:\s*([A-Za-z][A-Za-z ]+?)\s*$/))) {
                current.department = squash(m[1]);
                continue;
            }
            if ((m = en.match(/Major:\s*([A-Za-z][A-Za-z ]+?)\s*$/))) {
                current.major = squash(m[1]);
                continue;
            }

            // ---- Course table start ----
            if (/Course Code\s+Course Name/.test(en)) {
                inCourseTbl = true;
                lastCourse = -1;
                continue;
            }

            // ---- Summaries ----
            if ((m = en.match(/S\.GPA:\s*([\d.]+)/))) {
                current.summary.semester_gpa = parseFloat(m[1]);
                const sm = en.match(/(\d+)\s+(\d+)\s+sum:\s+([\d.]+)/);
                if (sm) {
                    current.summary.credit_hours = parseInt(sm[1], 10);
                    current.summary.passed_hours = parseInt(sm[2], 10);
                    current.summary.points = parseFloat(sm[3]);
                }
                const st = en.match(/-\s*([A-Za-z][A-Za-z ]*?)\s*(?:\d|$)/);
                if (st) {
                    current.summary.standing = st[1].trim();
                }
                inCourseTbl = false;
                lastCourse = -1;
                continue;
            }
            if ((m = en.match(/Ac\.\s*GPA\s*:\s*([\d.]+)/))) {
                current.summary.cumulative_gpa = parseFloat(m[1]);
                if (current.summary.standing === null) {
                    const st = en.match(/-?\s*([A-Za-z][A-Za-z ]+?)\s*$/);
                    if (st) {
                        current.summary.standing = st[1].trim();
                    }
                }
                continue;
            }

            // ---- Course row ----
            const course = this.parseCourseRow(en, line);
            if (course) {
                current.courses.push(course);
                lastCourse = current.courses.length - 1;
                continue;
            }

            // ---- Wrapped name continuation ----
            if (inCourseTbl && lastCourse >= 0) {
                const contEn = squash(en);
                const contAr = this.arabicName(line, []);
                const structural = /^(Date|Page|Student ID|www\.|Dean of|Dr\.|Crd\.|Pass|Hours)/.test(contEn);
                if (!structural && (contEn !== '' || contAr)) {
                    const c = current.courses[lastCourse];
                    if (contEn !== '') {
                        c.name_en = ((c.name_en || '') + ' ' + contEn).trim();
                    }
                    if (contAr) {
                        c.name_ar = ((c.name_ar || '') + ' ' + contAr).trim();
                    }
                }
            }
        }
        flush();

        const grade_scale: TranscriptResult['grade_scale'] = {};
        for (const k of Object.keys(GRADE_SCALE)) {
            const g = GRADE_SCALE[k];
            grade_scale[k] = { arabic: g.ar, weight_4: g.weight, description: g.desc, percent: g.pct };
        }
        return { student, semesters, totals, grade_scale };
    }
}

/** Browser helper: parse a File/ArrayBuffer using a provided pdfjsLib. */
export async function parsePdf(pdfjsLib: any, source: ArrayBuffer | Uint8Array): Promise<TranscriptResult> {
    const data = source instanceof ArrayBuffer ? new Uint8Array(source) : source;
    const pdf = await pdfjsLib.getDocument({ data: data }).promise;
    let allLines: string[] = [];
    for (let p = 1; p <= pdf.numPages; p++) {
        const page = await pdf.getPage(p);
        const tc = await page.getTextContent();
        allLines = allLines.concat(reconstructLayoutLines(tc.items));
    }
    const parser = new UquTranscriptParser();
    const result = parser.parseLayoutText(allLines.join('\n'));
    result.meta = {
        pages: pdf.numPages,
        extracted_at: new Date().toISOString(),
        parser: 'uqu-transcript-parser-js/1.0',
        warnings: parser.warnings,
    };
    return result;
}
