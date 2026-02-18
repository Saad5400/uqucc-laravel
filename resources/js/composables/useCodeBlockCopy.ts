import { onMounted, onUnmounted } from 'vue';
import { toast } from 'vue-sonner';

type CopyState = 'idle' | 'copied' | 'error';
type ButtonState = {
    button: HTMLButtonElement;
    state: CopyState;
    timeout: number | null;
};

const iconSvg = {
    copy: `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>`,
    check: `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0"><path d="M20 6 9 17l-5-5"/></svg>`,
    x: `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="hsl(var(--destructive))" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>`,
};

export function useCodeBlockCopy(containerRef: { value: HTMLElement | null }) {
    const buttons = new Set<ButtonState>();

    function createCopyButton(preElement: HTMLElement): HTMLButtonElement | null {
        // Skip if this pre already has a button
        if (preElement.parentElement?.querySelector('.code-block-copy-btn')) {
            return null;
        }

        // Wrap the pre in a relative container if not already
        let wrapper = preElement.parentElement;
        if (!wrapper?.classList.contains('code-block-wrapper')) {
            wrapper = document.createElement('div');
            wrapper.classList.add('code-block-wrapper', 'relative');
            wrapper.style.margin = '0';
            preElement.parentNode?.insertBefore(wrapper, preElement);
            wrapper.appendChild(preElement);
            // Remove margin from pre element
            preElement.style.margin = '0';
        }

        // Create button
        const button = document.createElement('button');
        button.className = 'code-block-copy-btn absolute right-1 top-1 my-0 p-1.5 rounded-md disabled:opacity-50 bg-background/80 backdrop-blur-sm border border-border/50 z-10';
        button.type = 'button';
        button.innerHTML = iconSvg.copy;
        button.title = 'Copy code';

        button.addEventListener('click', async () => {
            const buttonState = Array.from(buttons).find((b) => b.button === button);
            if (!buttonState) return;

            const codeText = preElement.textContent ?? '';

            try {
                await navigator.clipboard.writeText(codeText);
                setButtonState(buttonState, 'copied');
            } catch {
                setButtonState(buttonState, 'error');
                toast.error('Failed to copy code to clipboard');
            }
        });

        wrapper.appendChild(button);

        // Track button state
        const buttonState: ButtonState = {
            button,
            state: 'idle',
            timeout: null,
        };
        buttons.add(buttonState);

        return button;
    }

    function setButtonState(buttonState: ButtonState, state: CopyState) {
        // Clear existing timeout
        if (buttonState.timeout) {
            clearTimeout(buttonState.timeout);
        }

        buttonState.state = state;

        // Update icon
        switch (state) {
            case 'copied':
                buttonState.button.innerHTML = iconSvg.check;
                buttonState.button.title = 'Copied!';
                break;
            case 'error':
                buttonState.button.innerHTML = iconSvg.x;
                buttonState.button.title = 'Failed to copy';
                break;
            default:
                buttonState.button.innerHTML = iconSvg.copy;
                buttonState.button.title = 'Copy code';
        }

        // Reset to idle after 2 seconds
        if (state !== 'idle') {
            buttonState.timeout = window.setTimeout(() => {
                setButtonState(buttonState, 'idle');
            }, 2000);
        }
    }

    function addCopyButtons(container: HTMLElement) {
        const preElements = container.querySelectorAll('pre');
        preElements.forEach((pre) => {
            if (pre instanceof HTMLElement) {
                createCopyButton(pre);
            }
        });
    }

    function removeCopyButtons() {
        buttons.forEach((buttonState) => {
            if (buttonState.timeout) {
                clearTimeout(buttonState.timeout);
            }
            buttonState.button.remove();
        });
        buttons.clear();
    }

    onMounted(() => {
        if (containerRef.value) {
            addCopyButtons(containerRef.value);

            // Use MutationObserver to detect new pre elements (for dynamic content)
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node instanceof HTMLElement) {
                            // Check if the added node is a pre element
                            if (node.tagName === 'PRE') {
                                createCopyButton(node);
                            }
                            // Check if the added node contains pre elements
                            const pres = node.querySelectorAll('pre');
                            pres.forEach((pre) => {
                                if (pre instanceof HTMLElement) {
                                    createCopyButton(pre);
                                }
                            });
                        }
                    });
                });
            });

            observer.observe(containerRef.value, {
                childList: true,
                subtree: true,
            });

            // Store observer for cleanup
            (containerRef.value as any)._codeBlockObserver = observer;
        }
    });

    onUnmounted(() => {
        // Stop observing
        if (containerRef.value && (containerRef.value as any)._codeBlockObserver) {
            (containerRef.value as any)._codeBlockObserver.disconnect();
        }
        removeCopyButtons();
    });

    return {
        addCopyButtons,
        removeCopyButtons,
    };
}
