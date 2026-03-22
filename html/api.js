/**
 * n-chat API Service
 * Shared API client for visitor and admin frontends.
 * Configurable base URL for easy deployment changes.
 */

const NChat = (() => {
    // ── Config ──
    const API_BASE = 'http://localhost:8000/api';
    const POLL_INTERVAL = 500; // 500ms polling

    // ── Helpers ──
    async function request(method, path, body = null, headers = {}) {
        const opts = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...headers,
            },
        };
        if (body) opts.body = JSON.stringify(body);

        const res = await fetch(`${API_BASE}${path}`, opts);
        const data = await res.json();

        if (!res.ok) {
            throw { status: res.status, ...data };
        }
        return data;
    }

    // ── Visitor API ──
    const Visitor = {
        _token: null,
        _pollTimer: null,
        _lastId: 0,

        getToken() {
            if (this._token) return this._token;
            this._token = localStorage.getItem('nchat_visitor_token');
            return this._token;
        },

        setToken(token) {
            this._token = token;
            localStorage.setItem('nchat_visitor_token', token);
        },

        headers() {
            const t = this.getToken();
            return t ? { 'X-Visitor-Token': t } : {};
        },

        async init() {
            const data = await request('POST', '/visitor/init', null, this.headers());
            if (data.visitor && data.visitor.token) {
                this.setToken(data.visitor.token);
            }
            return data;
        },

        async getMessages() {
            const data = await request('GET', '/visitor/messages', null, this.headers());
            if (data.messages && data.messages.length > 0) {
                this._lastId = data.messages[data.messages.length - 1].id;
            }
            return data;
        },

        async sendMessage(body) {
            const data = await request('POST', '/visitor/messages', { body }, this.headers());
            if (data.message) {
                this._lastId = Math.max(this._lastId, data.message.id);
            }
            return data;
        },

        async poll() {
            return await request('GET', `/visitor/poll?since_id=${this._lastId}`, null, this.headers());
        },

        async saveInfo(name, email) {
            return await request('POST', '/visitor/save-info', { name, email }, this.headers());
        },

        startPolling(onNewMessages) {
            this.stopPolling();
            this._pollTimer = setInterval(async () => {
                try {
                    const data = await this.poll();
                    if (data.messages && data.messages.length > 0) {
                        this._lastId = data.messages[data.messages.length - 1].id;
                        onNewMessages(data.messages, data.admin_online);
                    }
                } catch (e) {
                    // silently retry
                }
            }, POLL_INTERVAL);
        },

        stopPolling() {
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
        },
    };

    // ── Admin API ──
    const AdminAPI = {
        _token: null,
        _pollTimer: null,
        _lastId: 0,
        _convPollTimer: null,
        _convLastId: 0,

        getToken() {
            if (this._token) return this._token;
            this._token = localStorage.getItem('nchat_admin_token');
            return this._token;
        },

        setToken(token) {
            this._token = token;
            localStorage.setItem('nchat_admin_token', token);
        },

        clearToken() {
            this._token = null;
            localStorage.removeItem('nchat_admin_token');
        },

        isLoggedIn() {
            return !!this.getToken();
        },

        headers() {
            const t = this.getToken();
            return t ? { 'Authorization': `Bearer ${t}` } : {};
        },

        async login(email, password) {
            const data = await request('POST', '/admin/login', { email, password });
            if (data.token) {
                this.setToken(data.token);
            }
            return data;
        },

        async getConversations() {
            return await request('GET', '/admin/conversations', null, this.headers());
        },

        async getMessages(visitorId) {
            const data = await request('GET', `/admin/conversations/${visitorId}/messages`, null, this.headers());
            if (data.messages && data.messages.length > 0) {
                this._convLastId = data.messages[data.messages.length - 1].id;
            }
            return data;
        },

        async sendMessage(visitorId, body) {
            const data = await request('POST', `/admin/conversations/${visitorId}/messages`, { body }, this.headers());
            if (data.message) {
                this._convLastId = Math.max(this._convLastId, data.message.id);
            }
            return data;
        },

        async poll() {
            return await request('GET', `/admin/poll?since_id=${this._lastId}`, null, this.headers());
        },

        async pollConversation(visitorId) {
            return await request('GET', `/admin/conversations/${visitorId}/poll?since_id=${this._convLastId}`, null, this.headers());
        },

        // Poll globally for new messages (for conversation list)
        startGlobalPolling(onNewData) {
            this.stopGlobalPolling();
            this._pollTimer = setInterval(async () => {
                try {
                    const data = await this.poll();
                    if (data.messages && data.messages.length > 0) {
                        this._lastId = data.messages[data.messages.length - 1].id;
                    }
                    onNewData(data);
                } catch (e) {
                    if (e.status === 401) {
                        this.clearToken();
                        window.location.href = 'admin-login.html';
                    }
                }
            }, POLL_INTERVAL);
        },

        stopGlobalPolling() {
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
        },

        // Poll specific conversation for new messages
        startConversationPolling(visitorId, onNewMessages) {
            this.stopConversationPolling();
            this._convPollTimer = setInterval(async () => {
                try {
                    const data = await this.pollConversation(visitorId);
                    if (data.messages && data.messages.length > 0) {
                        this._convLastId = data.messages[data.messages.length - 1].id;
                        onNewMessages(data.messages, data.visitor_online);
                    }
                } catch (e) {
                    // silently retry
                }
            }, POLL_INTERVAL);
        },

        stopConversationPolling() {
            if (this._convPollTimer) {
                clearInterval(this._convPollTimer);
                this._convPollTimer = null;
            }
        },
    };

    return { Visitor, Admin: AdminAPI, API_BASE, POLL_INTERVAL };
})();
