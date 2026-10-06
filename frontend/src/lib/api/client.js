import axios from 'axios';
import { clearStoredAuth } from '../authStorage';

const API_BASE_URL = process.env.REACT_APP_API_URL || 'http://localhost:8000/api';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 20000, // 20s - prevent infinite loading when backend is unreachable
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Request interceptor: attach auth token
apiClient.interceptors.request.use(
  (config) => {
    const token = sessionStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);


function getApiErrorMessage(error) {
  if (error.response) {
    const { status, data } = error.response;
    const msg = data?.message ?? data?.error ?? (typeof data === 'string' ? data : null);
    if (msg) return `[${status}] ${msg}`;
    return `Request failed with status ${status}`;
  }
  if (error.code === 'ERR_NETWORK' || error.message === 'Network Error') {
    const base = apiClient.defaults.baseURL ?? 'API';
    return `Cannot reach server (${base}). Check if backend is running and CORS is allowed.`;
  }
  return error.message || 'Request failed';
}

// Response interceptor: handle 401, then reject with clear error
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const message = getApiErrorMessage(error);
    error.apiMessage = message;

    if (error.response?.status === 401) {
      clearStoredAuth();
      window.dispatchEvent(new Event('auth:logout'));
    }

    // Status and message only, and only while developing: response bodies can
    // hold student data (e.g. a student-number conflict card), which must not
    // end up in the browser console (#58).
    if (process.env.NODE_ENV === 'development') {
      console.error('[API Error]', message);
    }
    return Promise.reject(error);
  }
);
