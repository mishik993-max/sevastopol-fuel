const STORAGE_KEY = 'push_client_id';

export function getPushClientId() {
    let id = localStorage.getItem(STORAGE_KEY);

    if (!id) {
        id = crypto.randomUUID();
        localStorage.setItem(STORAGE_KEY, id);
    }

    return id;
}
