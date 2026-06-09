<script setup>
import { computed } from 'vue';

const props = defineProps({
    size: { type: Number, default: 88 },
});

const uid = `app-icon-${Math.random().toString(36).slice(2, 9)}`;
const px = computed(() => `${props.size}px`);
</script>

<template>
    <svg
        :width="px"
        :height="px"
        viewBox="0 0 512 512"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-hidden="true"
    >
        <defs>
            <radialGradient :id="`${uid}-bg`" cx="50%" cy="35%" r="65%">
                <stop offset="0%" stop-color="#1A1410" />
                <stop offset="100%" stop-color="#0A0807" />
            </radialGradient>
            <radialGradient :id="`${uid}-drop`" cx="42%" cy="30%" r="70%">
                <stop offset="0%" stop-color="#F5D06A" />
                <stop offset="60%" stop-color="#E8B84B" />
                <stop offset="100%" stop-color="#B8870A" />
            </radialGradient>
            <radialGradient :id="`${uid}-glow`" cx="40%" cy="30%" r="55%">
                <stop offset="0%" stop-color="rgba(255,245,200,0.4)" />
                <stop offset="100%" stop-color="rgba(255,245,200,0)" />
            </radialGradient>
            <clipPath :id="`${uid}-clip`">
                <rect width="512" height="512" rx="114" ry="114" />
            </clipPath>
        </defs>
        <g :clip-path="`url(#${uid}-clip)`">
            <rect width="512" height="512" :fill="`url(#${uid}-bg)`" />
            <path
                d="M256 118 C256 118 192 195 192 248 C192 284 221 312 256 312 C291 312 320 284 320 248 C320 195 256 118 256 118 Z"
                :fill="`url(#${uid}-drop)`"
            />
            <path
                d="M256 118 C256 118 192 195 192 248 C192 284 221 312 256 312 C291 312 320 284 320 248 C320 195 256 118 256 118 Z"
                :fill="`url(#${uid}-glow)`"
            />
            <ellipse cx="235" cy="195" rx="14" ry="22" fill="rgba(255,252,240,0.22)" transform="rotate(-18 235 195)" />
            <circle cx="256" cy="248" r="7" fill="#E8B84B" opacity="0.9" />
        </g>
        <rect width="512" height="512" rx="114" ry="114" fill="none" stroke="rgba(232,184,75,0.2)" stroke-width="2" />
    </svg>
</template>
