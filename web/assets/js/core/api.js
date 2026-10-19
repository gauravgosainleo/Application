// Small fetch wrapper for the PHP JSON API (api/index.php?r=module/action).

export class ApiError extends Error {
  constructor(message, status, code, payload) {
    super(message);
    this.status = status;
    this.code = code;
    this.payload = payload || {};
  }
}

export const api = {
  base: 'api/index.php',
  csrf: null,
  onAuthError: null,
  onBanned: null,

  url(route, params) {
    const qs = new URLSearchParams({ r: route });
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== null && v !== '') qs.append(k, v);
      }
    }
    return `${this.base}?${qs.toString()}`;
  },

  async request(route, { method = 'GET', params, body, form } = {}) {
    const headers = { 'X-Requested-With': 'GVI', Accept: 'application/json' };
    if (this.csrf) headers['X-CSRF-Token'] = this.csrf;
    const init = { method, headers, credentials: 'same-origin' };
    if (form) {
      init.body = form;
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(this.url(route, params), init);
    } catch (e) {
      throw new ApiError('Network error — check your internet connection and try again.', 0, 'network');
    }
    let json = null;
    try {
      json = await res.json();
    } catch (e) {
      throw new ApiError(res.status === 413 ? 'The file is too large for the server.' : `Server error (${res.status}). Please try again.`, res.status, 'bad_response');
    }
    if (!json.ok) {
      const err = new ApiError(json.error || 'Something went wrong.', res.status, json.code, json);
      if (res.status === 401 && this.onAuthError) this.onAuthError(err);
      if (json.code === 'banned' && this.onBanned) this.onBanned(err);
      throw err;
    }
    return json.data;
  },

  get(route, params) {
    return this.request(route, { params });
  },

  post(route, body = {}) {
    return this.request(route, { method: 'POST', body });
  },

  upload(route, data = {}) {
    const form = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) {
      for (const [k, v] of Object.entries(data)) {
        if (v === undefined || v === null) continue;
        if (Array.isArray(v)) form.append(k, JSON.stringify(v));
        else form.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v);
      }
    }
    return this.request(route, { method: 'POST', form });
  },
};
