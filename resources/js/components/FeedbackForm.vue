<script setup>
import { ref } from 'vue';
import { apiUrl } from '../api';

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
    <div class="modal-overlay" @click.self="emit('close')">
        <div class="modal">
            <button class="close-btn" type="button" @click="emit('close')">✕</button>

            <template v-if="success">
                <h2>Спасибо!</h2>
                <p class="hint">Сообщение получено. Мы читаем все предложения- они помогают улучшать сервис.</p>
                <button type="button" class="btn btn-primary btn-block" @click="emit('close')">Закрыть</button>
            </template>

            <template v-else>
                <h2>Обратная связь</h2>
                <p class="hint">Идея, замечание или что-то не работает- напишите нам.</p>

                <form @submit.prevent="submit">
                    <fieldset>
                        <legend>Тип сообщения</legend>
                        <div class="radio-grid">
                            <label v-for="t in TYPES" :key="t.value" class="radio-label">
                                <input v-model="type" type="radio" :value="t.value" />
                                {{ t.label }}
                            </label>
                        </div>
                    </fieldset>

                    <label class="field">
                        Сообщение
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
                        Контакт (необязательно)
                        <input
                            v-model="contact"
                            class="field-input"
                            type="text"
                            maxlength="120"
                            placeholder="Telegram, email- если хотите ответ"
                        />
                    </label>

                    <p v-if="error" class="error">{{ error }}</p>

                    <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
                        {{ submitting ? 'Отправка…' : 'Отправить' }}
                    </button>
                </form>
            </template>
        </div>
    </div>
</template>
