<script setup>
import { usePwaInstall } from '../composables/usePwaInstall';

defineProps({
    mode: {
        type: String,
        required: true,
        validator: (value) => ['topbar', 'float'].includes(value),
    },
});

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
        v-if="mode === 'topbar' && showReopenTrigger"
        type="button"
        class="topbar-icon-btn topbar-icon-btn--install"
        title="Установить приложение"
        aria-label="Установить приложение"
        @click="reopenPrompt"
    >
        ⬇
    </button>

    <template v-if="mode === 'float'">
        <div v-if="promptVisible" class="pwa-install-float">
            <button type="button" class="pwa-install-float__close" aria-label="Закрыть" @click="dismissPrompt">
                ✕
            </button>
            <div class="pwa-install-float__body">
                <strong class="pwa-install-float__title">Установить приложение</strong>
                <p class="pwa-install-float__text">Быстрый доступ с главного экрана телефона</p>
            </div>
            <div class="pwa-install-float__actions">
                <button type="button" class="btn btn-primary btn-sm" @click="onInstall">
                    Установить
                </button>
                <button type="button" class="btn btn-secondary btn-sm" @click="dismissPrompt">
                    Позже
                </button>
            </div>
        </div>

        <div v-if="showHint" class="modal-overlay" @click.self="closeHint">
            <div class="modal modal-sm pwa-install-modal">
                <button class="close-btn" type="button" @click="closeHint">✕</button>
                <h2>Установить приложение</h2>

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
</template>
