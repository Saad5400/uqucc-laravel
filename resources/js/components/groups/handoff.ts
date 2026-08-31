/**
 * What happens the moment a student leaves for WhatsApp or Telegram.
 *
 * WhatsApp needs nothing: `wa.me?text=` puts the draft in the input box on
 * every client. Telegram only honours the same parameter on some of its
 * clients, so the message also goes on the clipboard and a toast says so — a
 * student who lands in an empty chat can paste instead of typing it out.
 *
 * Shared by the hero card and the roster: both hand off to the same apps, and
 * the two would otherwise drift.
 */
import { toast } from 'vue-sonner';
import type { SupervisorContact } from './types';

/**
 * Called from the anchor's click, never instead of it — nothing here prevents
 * the default, so the app opens whether or not the clipboard is available.
 */
export function handOffTo(contact: SupervisorContact, message: string): void {
    if (contact.kind !== 'telegram') {
        return;
    }

    navigator.clipboard
        ?.writeText(message)
        .then(() => toast.success('نسخنا الرسالة — إن لم تظهر في المحادثة، الصقها قبل الإرسال'))
        .catch(() => undefined);
}
