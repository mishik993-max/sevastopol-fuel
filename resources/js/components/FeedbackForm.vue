<script setup>
import { ref } from 'vue';
import { apiUrl } from '../api';
import UiIcon from './UiIcon.vue';

const emit = defineEmits(['close', 'sent']);

const type = ref('suggestion');
const message = ref('');
const contact = ref('');
const submitting = ref(false);
const error = ref(null);
const success = ref(false);

const TYPES = [
    { value: 'suggestion', label: 'Предложение по улучшению' },
    { value: 'feedback', label: 'Обратная связь / проблема' },
];

async function submit() {
    if (message.value.trim().length < 10) {
        error.value = 'Напишите чуть подробнее (минимум 10 символов)';
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl('/api/feedback'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: type.value,
                message: message.value.trim(),
                contact: contact.value.trim() || null,
            }),
        });
        const json = await res.json();

        if (!res.ok) throw new Error(json.message || 'Не удалось отправить');

        success.value = true;
        emit('sent');
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="modal-overlay modal-overlay--sheet" @click.self="emit('close')">
        <div class="modal modal--sheet">
            <template v-if="success">
                <div class="report-success">
                    <div class="report-success__icon">
                        <UiIcon name="check" :size="32" color="#22C55E" />
                    </div>
                    <h2 class="report-success__title">Спасибо!</h2>
                    <p class="report-success__text">
                        Сообщение получено. Мы читаем все предложения - они помогают улучшать сервис.
                    </p>
                    <button type="button" class="btn btn-secondary btn-block" @click="emit('close')">Закрыть</button>
                </div>
            </template>

            <template v-else>
                <div class="modal-report-handle" aria-hidden="true" />
                <div class="modal-report-header">
                    <span class="modal-report-icon" aria-hidden="true">
                        <UiIcon name="message-square" :size="18" color="#E8B84B" />
                    </span>
                    <div class="modal-report-head-text">
                        <h2>Обратная связь</h2>
                        <p>Идея, замечание или что-то не работает</p>
                    </div>
                    <button class="close-btn close-btn--square" type="button" @click="emit('close')">
                        <UiIcon name="x" :size="14" color="#7A7570" />
                    </button>
                </div>

                <form class="modal-sheet-body" @submit.prevent="submit">
                    <fieldset>
                        <legend class="section-label">Тип сообщения</legend>
                        <div class="radio-grid">
                            <label v-for="t in TYPES" :key="t.value" class="radio-label">
                                <input v-model="type" type="radio" :value="t.value" />
                                {{ t.label }}
                            </label>
                        </div>
                    </fieldset>

                    <label class="field">
                        <span class="section-label">Сообщение</span>
                        <textarea
                            v-model="message"
                            class="field-textarea"
                            rows="4"
                            maxlength="2000"
                            placeholder="Опишите идею или проблему…"
                            required
                        />
                    </label>

                    <label class="field">
                        <span class="section-label">Контакт (необязательно)</span>
                        <input
                            v-model="contact"
                            class="field-input"
                            type="text"
                            maxlength="120"
                            placeholder="Telegram, email - если хотите ответ"
                        />
                    </label>

                    <p v-if="error" class="error">{{ error }}</p>

                    <div class="modal-report-footer modal-report-footer--inline">
                        <button type="submit" class="btn btn-accent btn-block" :disabled="submitting">
                            <UiIcon v-if="!submitting" name="check" :size="16" color="#0A0807" />
                            {{ submitting ? 'Отправка…' : 'Отправить' }}
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</template>
