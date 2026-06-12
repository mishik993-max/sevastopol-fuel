<?php

return [
    /** Минут без активности — сессия считается завершённой, следующее сообщение начнёт новый диалог */
    'idle_minutes' => (int) env('FUEL_ASSISTANT_IDLE_MINUTES', 120),

    /** Макс. сообщений пользователя за сессию */
    'max_turns' => (int) env('FUEL_ASSISTANT_MAX_TURNS', 25),
];
