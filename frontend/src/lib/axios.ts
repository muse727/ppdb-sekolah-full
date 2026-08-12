// src/lib/axios.ts
import axios from 'axios';

const libAxios = axios.create({
    baseURL: 'http://localhost:8000',
    withCredentials: true,
    
    // === INI DIA PELURU PERAKNYA (WAJIB BUAT AXIOS VERSI BARU) ===
    withXSRFToken: true, 
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    // ============================================================

    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
});

export default libAxios;