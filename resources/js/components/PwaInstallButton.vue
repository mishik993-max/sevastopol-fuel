<script setup>
import { onMounted, ref, watch } from 'vue';
import { usePwaInstall } from '../composables/usePwaInstall';

const DISMISS_KEY = 'pwa_install_dismissed';

const { canInstall, showHint, hintMode, install, closeHint } = usePwaInstall();
const visible = ref(false);

function syncVisible() {
    visible.value = canInstall.value && !localStorage.getItem(DISMISS_KEY);
}

onMounted(syncVisible);
watch(canInstall, syncVisible);

function dismiss() {
    localStorage.setItem(DISMISS_KEY, '1');
    visible.value = false;
}

async function onInstall() {
    await install();
}
</script>

<template>
    <div v-if="canInstall && visible" class="pwa-install-float">
        <button type="button" class="pwa-install-float__close" aria-label="Закрыть" @click="dismiss">
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
            <button type="button" class="btn btn-secondary btn-sm" @click="dismiss">
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
