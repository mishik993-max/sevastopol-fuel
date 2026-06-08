<script setup>
import { usePwaInstall } from '../composables/usePwaInstall';

const { canInstall, showHint, hintMode, install, closeHint } = usePwaInstall();
</script>

<template>
    <template v-if="canInstall">
        <button
            type="button"
            class="topbar-install-btn"
            title="Установить приложение"
            @click="install"
        >
            <span class="topbar-install-btn__icon" aria-hidden="true">⬇</span>
            <span class="topbar-install-btn__label">Установить</span>
        </button>

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
