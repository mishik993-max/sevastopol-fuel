<script setup>
import { ref } from 'vue';
import { GUIDE_SECTIONS } from '../data/guide';
import LegalLinks from './LegalLinks.vue';
import { usePwaInstall } from '../composables/usePwaInstall';
import { useShare } from '../composables/useShare';

const emit = defineEmits(['close', 'start-tour', 'open-legal']);

const { canInstall, promptVisible, showReopenTrigger, reopenPrompt, install } = usePwaInstall();
const { canShare, share } = useShare();
const shareLoading = ref(false);

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
    <div class="modal-overlay" @click.self="emit('close')">
        <div class="modal guide-modal">
            <button class="close-btn" type="button" @click="emit('close')">✕</button>
            <h2>Справочник</h2>
            <p class="hint">Как пользоваться картой топлива Севастополя</p>

            <button type="button" class="btn btn-secondary btn-block guide-tour-btn" @click="emit('start-tour')">
                Пройти обучение снова
            </button>

            <button
                v-if="canInstall"
                type="button"
                class="btn btn-primary btn-block guide-tour-btn"
                @click="openInstall"
            >
                Установить приложение
            </button>

            <button
                v-if="canShare"
                type="button"
                class="btn btn-secondary btn-block guide-tour-btn"
                :disabled="shareLoading"
                @click="shareApp"
            >
                Поделиться ссылкой
            </button>

            <section v-for="section in GUIDE_SECTIONS" :key="section.id" class="guide-section">
                <h3 class="guide-section-title">{{ section.title }}</h3>
                <p class="guide-section-text">{{ section.content }}</p>
            </section>

            <div class="guide-legal-block">
                <p class="guide-legal-lead">
                    Без регистрации. GPS не уходит на сервер. На сервер — только ваши добровольные отчёты и обратная связь.
                </p>
                <LegalLinks @open="emit('open-legal', $event)" />
            </div>

            <p class="guide-footer">
                Админка модерации: <a href="/admin" class="guide-link">/admin</a>
            </p>
        </div>
    </div>
</template>
