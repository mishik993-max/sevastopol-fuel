<script setup>
import { usePwaInstall } from '../composables/usePwaInstall';
import AppIcon from './AppIcon.vue';
import UiIcon from './UiIcon.vue';

const {
    promptVisible,
    showReopenTrigger,
    showHint,
    hintMode,
    install,
    dismissPrompt,
    reopenPrompt,
    closeHint,
} = usePwaInstall();

async function onInstall() {
    await install();
}
</script>

<template>
    <button
        v-if="showReopenTrigger"
        type="button"
        class="map-fab map-fab--install"
        title="Установить приложение"
        aria-label="Установить приложение"
        @click="reopenPrompt"
    >
        Установить
    </button>

    <div v-if="promptVisible" class="pwa-install-float">
        <button type="button" class="pwa-install-float__close" aria-label="Закрыть" @click="dismissPrompt">
            <UiIcon name="x" :size="14" color="#7A7570" />
        </button>
        <div class="pwa-install-float__row">
            <div class="pwa-install-float__icon" aria-hidden="true">
                <UiIcon name="star" :size="16" color="#0A0807" fill="#0A0807" />
            </div>
            <div class="pwa-install-float__body">
                <strong class="pwa-install-float__title">Установить приложение</strong>
                <p class="pwa-install-float__text">sevazs.ru · PWA</p>
            </div>
        </div>
        <div class="pwa-install-float__actions">
            <button type="button" class="btn btn-accent btn-sm btn-block" @click="onInstall">
                Добавить на экран
            </button>
            <button type="button" class="btn btn-secondary btn-sm" @click="dismissPrompt">
                Позже
            </button>
        </div>
    </div>

    <div v-if="showHint" class="modal-overlay" @click.self="closeHint">
        <div class="modal modal-sm pwa-install-modal">
            <button class="close-btn" type="button" @click="closeHint">
                <UiIcon name="x" :size="16" color="currentColor" />
            </button>
            <div class="pwa-install-modal__head">
                <AppIcon :size="48" />
                <h2>Установить приложение</h2>
            </div>

            <template v-if="hintMode === 'ios'">
                <p class="hint">На iPhone и iPad установка через Safari:</p>
                <ol class="pwa-install-steps">
                    <li>Нажмите <strong>Поделиться</strong> (квадрат со стрелкой внизу).</li>
                    <li>Выберите <strong>«На экран "Домой"»</strong>.</li>
                    <li>Нажмите <strong>«Добавить»</strong>.</li>
                </ol>
                <p class="pwa-install-note">В Chrome на iOS тоже откройте меню «Поделиться» браузера.</p>
            </template>

            <template v-else>
                <p class="hint">В Chrome на Android:</p>
                <ol class="pwa-install-steps">
                    <li>Нажмите меню <strong>⋮</strong> (три точки).</li>
                    <li>Выберите <strong>«Установить приложение»</strong> или <strong>«Добавить на главный экран»</strong>.</li>
                </ol>
            </template>

            <button type="button" class="btn btn-primary btn-block" @click="closeHint">
                Понятно
            </button>
        </div>
    </div>
</template>
