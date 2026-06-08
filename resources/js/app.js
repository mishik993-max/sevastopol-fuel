import { createApp } from 'vue';
import App from './App.vue';
import '../css/app.css';
import { swRegistrationReady } from './swRegister';

swRegistrationReady
    .then((registration) => {
        if (registration) {
            console.info('Service Worker active:', registration.scope);
        }
    })
    .catch((error) => {
        console.error('Service Worker registration failed:', error);
    });

createApp(App).mount('#app');
