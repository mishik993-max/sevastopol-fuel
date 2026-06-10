<script setup>
import { ref } from 'vue';
import { GUIDE_SECTIONS } from '../data/guide';
import LegalLinks from './LegalLinks.vue';
import { usePwaInstall } from '../composables/usePwaInstall';
import { useShare } from '../composables/useShare';
import UiIcon from './UiIcon.vue';

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
                </div>

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
