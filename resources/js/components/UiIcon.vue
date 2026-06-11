<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 18 },
    color: { type: String, default: 'currentColor' },
    strokeWidth: { type: Number, default: 2 },
    fill: { type: String, default: 'none' },
});

const px = computed(() => `${props.size}px`);
</script>

<template>
    <svg
        :width="px"
        :height="px"
        viewBox="0 0 24 24"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
    >
        <template v-if="name === 'map-pin'">
            <path
                d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0Z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle
                cx="12"
                cy="10"
                r="3"
                :stroke="color"
                :stroke-width="strokeWidth"
            />
        </template>
        <template v-else-if="name === 'shield'">
            <path
                d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'anchor'">
            <path
                d="M12 22V8M5 12H2a10 10 0 0 0 20 0h-3"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle cx="12" cy="5" r="3" :stroke="color" :stroke-width="strokeWidth" />
        </template>
        <template v-else-if="name === 'message-square'">
            <path
                d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'x'">
            <path
                d="M18 6 6 18M6 6l12 12"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'camera'">
            <path
                d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle cx="12" cy="13" r="3" :stroke="color" :stroke-width="strokeWidth" />
        </template>
        <template v-else-if="name === 'check'">
            <path
                d="M20 6 9 17l-5-5"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'bell'">
            <path
                d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" :stroke="color" :stroke-width="strokeWidth" />
        </template>
        <template v-else-if="name === 'navigation'">
            <polygon
                points="3 11 22 2 13 21 11 13 3 11"
                :stroke="color"
                :stroke-width="strokeWidth"
                :fill="fill"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'thumbs-up'">
            <path
                d="M7 10v12M15 4.697a5 5 0 0 1 1.55 8.838l-6.35 5.934A1 1 0 0 1 8 18.207V10.065a1 1 0 0 1 .456-.822l3.18-2.043A2 2 0 0 0 15 5.847V4.5a.5.5 0 0 1 .5-.5h1.25A2.25 2.25 0 0 1 19 6.25v3.015"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'alert-triangle'">
            <path
                d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path d="M12 9v4M12 17h.01" :stroke="color" :stroke-width="strokeWidth" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'star'">
            <polygon
                points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"
                :stroke="color"
                :stroke-width="strokeWidth"
                :fill="fill"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'help-circle'">
            <circle cx="12" cy="12" r="10" :stroke="color" :stroke-width="strokeWidth" />
            <path
                d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
            />
        </template>
        <template v-else-if="name === 'cookie'">
            <path
                d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path d="M8.5 8.5v.01M16 15.5v.01M12 12v.01M7 16v.01M17 8v.01" :stroke="color" :stroke-width="strokeWidth" />
        </template>
        <template v-else-if="name === 'gauge'">
            <path
                d="m12 14 4-4"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path
                d="M3.34 19a10 10 0 1 1 17.32 0"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'list'">
            <path d="M8 6h13M8 12h13M8 18h13" :stroke="color" :stroke-width="strokeWidth" stroke-linecap="round" />
            <path d="M3 6h.01M3 12h.01M3 18h.01" :stroke="color" :stroke-width="strokeWidth" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'chevron-down'">
            <path
                d="m6 9 6 6 6-6"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'chevron-up'">
            <path
                d="m18 15-6-6-6 6"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'file-text'">
            <path
                d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path
                d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'sun'">
            <circle cx="12" cy="12" r="4" :stroke="color" :stroke-width="strokeWidth" />
            <path
                d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
            />
        </template>
        <template v-else-if="name === 'moon'">
            <path
                d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else-if="name === 'settings'">
            <circle cx="12" cy="12" r="3" :stroke="color" :stroke-width="strokeWidth" />
            <path
                d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
            />
        </template>
        <template v-else-if="name === 'activity'">
            <path
                d="M22 12h-4l-3 9L9 3l-3 9H2"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
    </svg>
</template>
