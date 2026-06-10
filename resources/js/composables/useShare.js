import { computed } from 'vue';

const SHARE_TEXT = 'Оперативная карта АЗС Севастополя';

export function useShare() {
    const canShare = computed(() => {
        if (typeof navigator === 'undefined') {
            return false;
        }

        return !!navigator.share || !!navigator.clipboard?.writeText;
    });

    async function share() {
        const url = window.location.href;
        const title = document.title || 'Севастополь Топливо';
        const payload = { title, text: SHARE_TEXT, url };

        if (navigator.share) {
            try {
                await navigator.share(payload);

                return { ok: true, method: 'share' };
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return { ok: false, aborted: true };
                }
            }
        }

        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(url);

            return { ok: true, method: 'clipboard' };
        }

        throw new Error('Не получилось поделиться в этом браузере');
    }

    return { canShare, share };
}
