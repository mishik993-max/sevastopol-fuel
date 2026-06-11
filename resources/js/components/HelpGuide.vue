<script setup>
import { onMounted, ref } from 'vue';
import { GUIDE_SECTIONS } from '../data/guide';
import { TELEGRAM_CHAT_URL } from '../constants';
import LegalLinks from './LegalLinks.vue';
import { usePwaInstall } from '../composables/usePwaInstall';
import { useShare } from '../composables/useShare';
import { useFaq } from '../composables/useFaq';
import UiIcon from './UiIcon.vue';

const emit = defineEmits(['close', 'start-tour', 'open-legal']);

const { canInstall, promptVisible, showReopenTrigger, reopenPrompt, install } = usePwaInstall();
const { canShare, share } = useShare();
const { items: faqItems, loading: faqLoading, loadFaq } = useFaq();
const shareLoading = ref(false);

onMounted(() => {
    loadFaq();
});

function openInstall() {
    if (showReopenTrigger.value) {
        reopenPrompt();
    } else if (promptVisible.value) {
        return;
    } else {
        install();
    }

    emit('close');
}

async function shareApp() {
    if (shareLoading.value) {
        return;
    }

    shareLoading.value = true;

    try {
        const result = await share();

        if (result.ok && result.method === 'clipboard') {
            window.alert('Ссылка скопирована');
        }
    } catch (error) {
        window.alert(error.message || 'Не удалось поделиться');
    } finally {
        shareLoading.value = false;
    }
}
</script>

<template>
    <div class="modal-overlay modal-overlay--sheet" @click.self="emit('close')">
        <div class="modal modal--sheet guide-modal">
            <div class="modal-report-handle" aria-hidden="true" />
            <div class="modal-report-header">
                <span class="modal-report-icon" aria-hidden="true">
                    <UiIcon name="help-circle" :size="18" color="#E8B84B" />
                </span>
                <div class="modal-report-head-text">
                    <h2>Справочник</h2>
                    <p>Как пользоваться картой топлива Севастополя</p>
                </div>
                <button class="close-btn close-btn--square" type="button" @click="emit('close')">
                    <UiIcon name="x" :size="14" color="#7A7570" />
                </button>
            </div>

            <div class="modal-sheet-body">
                <div class="guide-actions">
                    <button type="button" class="btn btn-secondary btn-block" @click="emit('start-tour')">
                        Пройти обучение снова
                    </button>

                    <button
                        v-if="canInstall"
                        type="button"
                        class="btn btn-accent btn-block"
                        @click="openInstall"
                    >
                        Установить приложение
                    </button>

                    <button
                        v-if="canShare"
                        type="button"
                        class="btn btn-secondary btn-block"
                        :disabled="shareLoading"
                        @click="shareApp"
                    >
                        Поделиться ссылкой
                    </button>

                    <a
                        :href="TELEGRAM_CHAT_URL"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-secondary btn-block community-guide-link"
                    >
                        Чат в Telegram
                    </a>
                </div>

                <section class="guide-section guide-section--community">
                    <h3 class="guide-section-title">Помогите сделать карту точнее</h3>
                    <p class="guide-section-text">
                        Чем чаще водители рекомендуют сайт и отмечают заправки, тем полезнее и актуальнее информация для всех.
                        Расскажите знакомым и загляните в наш
                        <a :href="TELEGRAM_CHAT_URL" target="_blank" rel="noopener noreferrer">Telegram-чат</a>.
                    </p>
                </section>

                <section v-if="faqLoading || faqItems.length" class="guide-section guide-section--faq">
                    <h3 class="guide-section-title">Частые вопросы</h3>
                    <p v-if="faqLoading" class="guide-section-text">Загрузка…</p>
                    <div v-else class="faq-list">
                        <details v-for="item in faqItems" :key="item.id" class="faq-item">
                            <summary class="faq-item-question">{{ item.question }}</summary>
                            <p class="faq-item-answer">{{ item.answer }}</p>
                        </details>
                    </div>
                </section>

                <section v-for="section in GUIDE_SECTIONS" :key="section.id" class="guide-section">
                    <h3 class="guide-section-title">{{ section.title }}</h3>
                    <p class="guide-section-text">{{ section.content }}</p>
                </section>

                <div class="guide-legal-block">
                    <p class="guide-legal-lead">
                        Регистрация не нужна. Ваше местоположение на сервер не отправляется. Туда попадает только то, что вы сами решили отправить.
                    </p>
                    <LegalLinks @open="emit('open-legal', $event)" />
                </div>
            </div>
        </div>
    </div>
</template>
