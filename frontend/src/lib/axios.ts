// src/lib/axios.ts
import axios, { type InternalAxiosRequestConfig } from 'axios';

const libAxios = axios.create({
    baseURL: process.env.NEXT_PUBLIC_API_URL || (process.env.NODE_ENV === 'production' ? '/' : 'http://localhost:8000'),
    withCredentials: true,

    // Laravel Sanctum SPA authentication. Axios reads the encrypted cookie,
    // decodes it, and sends X-XSRF-TOKEN on mutating requests.
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',

    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
});

let csrfRequest: Promise<unknown> | null = null;

/** Share concurrent cookie refreshes, but never cache a refresh across sessions. */
export function ensureCsrfCookie(): Promise<unknown> {
    if (!csrfRequest) {
        csrfRequest = libAxios.get('/sanctum/csrf-cookie').finally(() => { csrfRequest = null; });
    }
    return csrfRequest;
}

type CsrfRetryConfig = InternalAxiosRequestConfig & { csrfRetried?: boolean };

libAxios.interceptors.response.use(response => response, async (error: unknown) => {
    if (!axios.isAxiosError(error)) throw error;
    const config: CsrfRetryConfig | undefined = error.config;
    const method = config?.method?.toLowerCase();
    if (error.response?.status !== 419 || !config || config.csrfRetried
        || !config.url?.startsWith('/api/') || !method || !['post', 'put', 'patch', 'delete'].includes(method)) {
        throw error;
    }
    // A 419 is rejected before the controller runs. Retry once after refresh;
    // never retry invalid credentials (401) or authorization failures (403).
    config.csrfRetried = true;
    await ensureCsrfCookie();
    // The old request may retain an explicitly populated header. Let Axios
    // obtain the replacement value from the newly issued browser cookie.
    config.headers.delete('X-XSRF-TOKEN');
    return libAxios.request(config);
});

export default libAxios;