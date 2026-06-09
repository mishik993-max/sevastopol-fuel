import { createApp } from 'vue';
import App from './App.vue';
import '../css/app.css';
import { usePushNotifications } from './composables/usePushNotifications';
import { swRegistrationReady } from './swRegister';

swRegistrationReady
    .then((registration) => {
        if (registration) {
            console.info('Service Worker active:', registration.scope);
        }

        const { syncExistingSubscription } = usePushNotifications();
        syncExistingSubscription();
    })
    .catch((error) => {
        console.error('Service Worker registration failed:', error);
    });

createApp(App).mount('#app');
