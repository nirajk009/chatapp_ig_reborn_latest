const NChat = (() => {
    const SURGE_API_ORIGIN = 'https://aplus-new.goldengatetechnolabs.com';

    function appBaseUrl() {
        if (window.location.protocol === 'file:') {
            return SURGE_API_ORIGIN;
        }

        if (/\.surge\.sh$/i.test(window.location.hostname || '')) {
            return SURGE_API_ORIGIN;
        }

        const path = window.location.pathname || '/';
        const directory = path.replace(/\/[^/]*$/, '');
        const basePath = directory.endsWith('/html')
            ? directory.slice(0, -5)
            : directory;

        return `${window.location.origin}${basePath || ''}`;
    }

    const API_BASE = `${appBaseUrl()}/api`;
    const REALTIME = {
        key: '16fd173f2dd3ebb99caa',
        cluster: 'ap2',
        scriptUrl: 'https://js.pusher.com/8.4.0/pusher.min.js',
        conversationPrefix: 'presence-conversation.',
        adminFeedChannel: 'private-admin.feed',
        adminPresenceChannel: 'presence-admin.global',
        typingClientEvent: 'client-typing',
        typingEvent: 'typing.updated',
        readEvent: 'message.read',
    };

    function uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : ((r & 0x3) | 0x8)).toString(16);
        });
    }

    async function request(method, path, body = null, headers = {}) {
        const finalHeaders = {
            Accept: 'application/json',
            ...headers,
        };

        if (body !== null) {
            finalHeaders['Content-Type'] = 'application/json';
        }

        const response = await fetch(`${API_BASE}${path}`, {
            method,
            headers: finalHeaders,
            body: body !== null ? JSON.stringify(body) : undefined,
        });

        const contentType = response.headers.get('content-type') || '';
        const data = contentType.includes('application/json')
            ? await response.json()
            : {};

        if (!response.ok) {
            throw { status: response.status, ...data };
        }

        return data;
    }

    const Realtime = {
        _loader: null,
        _clients: {
            visitor: null,
            admin: null,
        },
        _subscriptions: {},

        async ensureScript() {
            if (window.Pusher) {
                return window.Pusher;
            }

            if (this._loader) {
                return this._loader;
            }

            this._loader = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = REALTIME.scriptUrl;
                script.async = true;
                script.dataset.nchatPusher = 'true';
                script.onload = () => resolve(window.Pusher);
                script.onerror = () => reject(new Error('Failed to load Pusher'));
                document.head.appendChild(script);
            }).then(Pusher => {
                Pusher.logToConsole = false;
                return Pusher;
            });

            return this._loader;
        },

        async createClient(role, endpoint, headersProvider) {
            if (this._clients[role]) {
                return this._clients[role];
            }

            const Pusher = await this.ensureScript();
            const client = new Pusher(REALTIME.key, {
                cluster: REALTIME.cluster,
                forceTLS: true,
                enabledTransports: ['ws', 'wss'],
                channelAuthorization: {
                    endpoint,
                    transport: 'ajax',
                    headersProvider,
                },
            });

            this._clients[role] = client;
            return client;
        },

        async visitorClient() {
            return this.createClient(
                'visitor',
                `${API_BASE}/visitor/realtime/auth`,
                () => Visitor.headers()
            );
        },

        async adminClient() {
            return this.createClient(
                'admin',
                `${API_BASE}/admin/realtime/auth`,
                () => AdminAPI.headers()
            );
        },

        async waitForSocketId(client) {
            if (client.connection && client.connection.socket_id) {
                return client.connection.socket_id;
            }

            return new Promise(resolve => {
                const finish = socketId => {
                    clearTimeout(timer);
                    client.connection.unbind('connected', handleConnected);
                    client.connection.unbind('error', handleError);
                    resolve(socketId || null);
                };

                const handleConnected = () => finish(client.connection ? client.connection.socket_id : null);
                const handleError = () => finish(null);
                const timer = setTimeout(
                    () => finish(client.connection ? client.connection.socket_id : null),
                    3000
                );

                client.connection.bind('connected', handleConnected);
                client.connection.bind('error', handleError);
            });
        },

        async getSocketId(role) {
            const client = role === 'admin'
                ? await this.adminClient()
                : await this.visitorClient();

            return this.waitForSocketId(client);
        },

        presenceSnapshot(channel) {
            const rawMembers = channel && channel.members && channel.members.members
                ? channel.members.members
                : {};
            const members = [];

            if (channel && channel.members && typeof channel.members.each === 'function') {
                channel.members.each(member => {
                    members.push({
                        id: String(member.id),
                        info: member.info || {},
                    });
                });
            } else {
                Object.entries(rawMembers).forEach(([id, info]) => {
                    members.push({
                        id: String(id),
                        info: info || {},
                    });
                });
            }

            return {
                channel: channel ? channel.name : null,
                total: channel && channel.members ? channel.members.count : members.length,
                members,
            };
        },

        bindPresenceHandlers(channel, onPresenceChange) {
            if (!onPresenceChange) {
                return;
            }

            const emit = () => onPresenceChange(this.presenceSnapshot(channel));

            channel.bind('pusher:subscription_succeeded', emit);
            channel.bind('pusher:member_added', emit);
            channel.bind('pusher:member_removed', emit);

            setTimeout(() => {
                if (channel && channel.subscribed) {
                    emit();
                }
            }, 0);
        },

        async subscribe(slot, role, channelName, handlers = {}) {
            const client = role === 'admin'
                ? await this.adminClient()
                : await this.visitorClient();

            const existing = this._subscriptions[slot];
            if (existing && existing.name !== channelName) {
                this.unsubscribe(slot);
            }

            let channel = client.channel(channelName);
            if (!channel) {
                channel = client.subscribe(channelName);
            }

            channel.unbind_all();

            if (handlers.onMessage) {
                channel.bind('message.created', handlers.onMessage);
            }

            if (handlers.onTyping) {
                channel.bind(REALTIME.typingClientEvent, handlers.onTyping);
                channel.bind(REALTIME.typingEvent, handlers.onTyping);
            }

            if (handlers.onRead) {
                channel.bind(REALTIME.readEvent, handlers.onRead);
            }

            this.bindPresenceHandlers(channel, handlers.onPresenceChange);

            this._subscriptions[slot] = {
                role,
                name: channelName,
                channel,
            };

            return channel;
        },

        unsubscribe(slot) {
            const entry = this._subscriptions[slot];
            if (!entry) {
                return;
            }

            const client = this._clients[entry.role];
            entry.channel.unbind_all();

            if (client) {
                client.unsubscribe(entry.name);
            }

            delete this._subscriptions[slot];
        },

        trigger(slot, eventName, payload) {
            const entry = this._subscriptions[slot];
            if (!entry || !entry.channel || typeof entry.channel.trigger !== 'function') {
                return false;
            }

            try {
                entry.channel.trigger(eventName, payload);
                return true;
            } catch (error) {
                console.warn('Realtime trigger failed', error);
                return false;
            }
        },

        disconnect(role) {
            Object.keys(this._subscriptions).forEach(slot => {
                if (this._subscriptions[slot].role === role) {
                    this.unsubscribe(slot);
                }
            });

            const client = this._clients[role];
            if (client) {
                client.disconnect();
            }

            this._clients[role] = null;
        },
    };

    function trackLatestIds(target, message) {
        if (!message || typeof message.id === 'undefined') {
            return;
        }

        const id = Number(message.id);
        if (!Number.isFinite(id)) {
            return;
        }

        target._lastId = Math.max(target._lastId, id);
        target._conversationLastIds[message.conversation_id] = Math.max(
            target._conversationLastIds[message.conversation_id] || 0,
            id
        );
    }

    function formatLocalTime(timestamp, fallback = '') {
        if (!timestamp) {
            return fallback;
        }

        const date = new Date(timestamp);
        if (Number.isNaN(date.getTime())) {
            return fallback;
        }

        if (Date.now() - date.getTime() < 60 * 1000) {
            return 'Just now';
        }

        return date.toLocaleTimeString(undefined, {
            hour: 'numeric',
            minute: '2-digit',
        });
    }

    function formatStatusTime(timestamp, prefix, fallback = prefix) {
        if (!timestamp) {
            return fallback;
        }

        const time = formatLocalTime(timestamp, '');
        if (!time) {
            return fallback;
        }

        return time === 'Just now'
            ? `${prefix} just now`
            : `${prefix} ${time}`;
    }

    function normalizeMessage(message) {
        if (!message) {
            return message;
        }

        return {
            ...message,
            time: formatLocalTime(message.created_at, message.time || ''),
            read_status: message.is_read
                ? formatStatusTime(message.read_at || message.created_at, 'Seen', 'Seen')
                : formatStatusTime(message.created_at, 'Sent', 'Sent'),
        };
    }

    function normalizeMessages(messages) {
        return Array.isArray(messages)
            ? messages.map(normalizeMessage)
            : [];
    }

    function normalizeLastMessage(lastMessage) {
        if (!lastMessage) {
            return lastMessage;
        }

        return {
            ...lastMessage,
            time: formatLocalTime(lastMessage.created_at, lastMessage.time || ''),
        };
    }

    function normalizeConversations(items) {
        return Array.isArray(items)
            ? items.map(item => ({
                ...item,
                last_message: normalizeLastMessage(item.last_message),
            }))
            : [];
    }

    function normalizeRealtimeEvent(event) {
        if (!event) {
            return event;
        }

        if (typeof event.last_read_message_id !== 'undefined') {
            return event;
        }

        if (event.message) {
            return {
                ...event,
                message: normalizeMessage(event.message),
            };
        }

        return normalizeMessage(event);
    }

    function typingPayload(role, info, conversationId, typing) {
        const fallbackName = role === 'admin' ? 'Admin' : 'Visitor';

        return {
            conversation_id: conversationId,
            typing: !!typing,
            sender_role: role,
            sender_id: info && typeof info.id !== 'undefined' ? info.id : null,
            sender_name: info && (info.name || info.username)
                ? (info.name || info.username)
                : fallbackName,
        };
    }

    const Visitor = {
        _token: null,
        _lastId: 0,
        _conversationLastIds: {},

        getToken() {
            if (this._token) return this._token;
            this._token = localStorage.getItem('nchat_visitor_token');
            return this._token;
        },

        setToken(token) {
            this._token = token;
            localStorage.setItem('nchat_visitor_token', token);
        },

        clearToken() {
            this._token = null;
            this._lastId = 0;
            this._conversationLastIds = {};
            localStorage.removeItem('nchat_visitor_token');
            localStorage.removeItem('nchat_visitor_info');
        },

        isLoggedIn() {
            const info = localStorage.getItem('nchat_visitor_info');
            if (!info) return false;

            try {
                return JSON.parse(info).is_logged_in === true;
            } catch {
                return false;
            }
        },

        getInfo() {
            try {
                return JSON.parse(localStorage.getItem('nchat_visitor_info'));
            } catch {
                return null;
            }
        },

        setInfo(info) {
            localStorage.setItem('nchat_visitor_info', JSON.stringify(info));
        },

        headers() {
            const token = this.getToken();
            return token ? { 'X-Visitor-Token': token } : {};
        },

        async init() {
            const data = await request('POST', '/visitor/init', null, this.headers());
            if (data.visitor && data.visitor.token) {
                this.setToken(data.visitor.token);
                this.setInfo(data.visitor);
            }
            return data;
        },

        async signup(name, email, password, username) {
            const data = await request(
                'POST',
                '/visitor/signup',
                { name, email, password, username },
                this.headers()
            );

            if (data.visitor && data.visitor.token) {
                this.setToken(data.visitor.token);
                this.setInfo(data.visitor);
            }

            return data;
        },

        async login(email, password) {
            const data = await request('POST', '/visitor/login', { email, password });

            if (data.is_admin && data.token) {
                AdminAPI.setToken(data.token);
                if (data.admin) {
                    AdminAPI.setInfo(data.admin);
                }
            } else if (data.visitor && data.visitor.token) {
                this.setToken(data.visitor.token);
                this.setInfo(data.visitor);
            }

            return data;
        },

        async getMessages() {
            const data = await request('GET', '/visitor/messages', null, this.headers());
            data.messages = normalizeMessages(data.messages);
            if (data.messages.length > 0) {
                data.messages.forEach(message => trackLatestIds(this, message));
            }
            return data;
        },

        async sendMessage(body, conversationId = null, clientId = null) {
            const actualClientId = clientId || uuid();
            const payload = {
                body,
                client_id: actualClientId,
            };

            if (conversationId) {
                payload.conversation_id = conversationId;
            }

            const socketId = await Realtime.getSocketId('visitor').catch(() => null);
            if (socketId) {
                payload.socket_id = socketId;
            }

            const data = await request('POST', '/visitor/messages', payload, this.headers());
            if (data.message) {
                data.message = normalizeMessage(data.message);
                trackLatestIds(this, data.message);
            }

            return { ...data, client_id: actualClientId };
        },

        async saveInfo(name, email) {
            return request('POST', '/visitor/save-info', { name, email }, this.headers());
        },

        async getContacts() {
            const data = await request('GET', '/visitor/contacts', null, this.headers());
            data.contacts = normalizeConversations(data.contacts);
            return data;
        },

        async searchUsers(q) {
            return request('GET', `/visitor/search-users?q=${encodeURIComponent(q)}`, null, this.headers());
        },

        async startChat(username) {
            return request('POST', '/visitor/start-chat', { username }, this.headers());
        },

        async getConversationMessages(conversationId) {
            const data = await request(
                'GET',
                `/visitor/conversations/${conversationId}/messages`,
                null,
                this.headers()
            );

            data.messages = normalizeMessages(data.messages);
            if (data.messages.length > 0) {
                data.messages.forEach(message => trackLatestIds(this, message));
            }

            return data;
        },

        async markConversationRead(conversationId) {
            return request(
                'POST',
                `/visitor/conversations/${conversationId}/read`,
                null,
                this.headers()
            );
        },

        async subscribeToConversation(conversationId, handlers = {}, slot = 'visitorConversation') {
            return Realtime.subscribe(
                slot,
                'visitor',
                `${REALTIME.conversationPrefix}${conversationId}`,
                {
                    onMessage: event => {
                        const normalizedEvent = normalizeRealtimeEvent(event);
                        const message = normalizedEvent && normalizedEvent.message
                            ? normalizedEvent.message
                            : normalizedEvent;
                        trackLatestIds(this, message);
                        if (handlers.onMessage) {
                            handlers.onMessage(normalizedEvent);
                        }
                    },
                    onTyping: handlers.onTyping,
                    onRead: handlers.onRead,
                    onPresenceChange: handlers.onPresenceChange,
                }
            );
        },

        unsubscribeConversation(slot = 'visitorConversation') {
            Realtime.unsubscribe(slot);
        },

        async subscribeToAdminPresence(onPresenceChange, slot = 'visitorAdminPresence') {
            return Realtime.subscribe(slot, 'visitor', REALTIME.adminPresenceChannel, {
                onPresenceChange,
            });
        },

        async sendTyping(conversationId, typing, slot = 'visitorConversation') {
            Realtime.trigger(
                slot,
                REALTIME.typingClientEvent,
                typingPayload('visitor', this.getInfo(), conversationId, typing)
            );

            const payload = {
                typing: !!typing,
            };

            const socketId = await Realtime.getSocketId('visitor').catch(() => null);
            if (socketId) {
                payload.socket_id = socketId;
            }

            try {
                return await request(
                    'POST',
                    `/visitor/conversations/${conversationId}/typing`,
                    payload,
                    this.headers()
                );
            } catch (error) {
                console.warn('Visitor typing update failed', error);
                return null;
            }
        },

        unsubscribeAdminPresence(slot = 'visitorAdminPresence') {
            Realtime.unsubscribe(slot);
        },

        disconnectRealtime() {
            Realtime.disconnect('visitor');
        },
    };

    const AdminAPI = {
        _token: null,
        _info: null,
        _lastId: 0,
        _conversationLastIds: {},
        _conversationVisitorMap: {},

        getToken() {
            if (this._token) return this._token;
            this._token = localStorage.getItem('nchat_admin_token');
            return this._token;
        },

        setToken(token) {
            this._token = token;
            localStorage.setItem('nchat_admin_token', token);
        },

        getInfo() {
            if (this._info) return this._info;

            try {
                this._info = JSON.parse(localStorage.getItem('nchat_admin_info'));
            } catch {
                this._info = null;
            }

            return this._info;
        },

        setInfo(info) {
            this._info = info;
            localStorage.setItem('nchat_admin_info', JSON.stringify(info));
        },

        clearToken() {
            this._token = null;
            this._info = null;
            this._lastId = 0;
            this._conversationLastIds = {};
            this._conversationVisitorMap = {};
            localStorage.removeItem('nchat_admin_token');
            localStorage.removeItem('nchat_admin_info');
        },

        isLoggedIn() {
            return !!this.getToken();
        },

        headers() {
            const token = this.getToken();
            return token ? { Authorization: `Bearer ${token}` } : {};
        },

        async login(email, password) {
            const data = await request('POST', '/admin/login', { email, password });
            if (data.token) {
                this.setToken(data.token);
            }
            if (data.admin) {
                this.setInfo(data.admin);
            }
            return data;
        },

        async getConversations() {
            const data = await request('GET', '/admin/conversations', null, this.headers());
            data.conversations = normalizeConversations(data.conversations);
            return data;
        },

        async getMessages(visitorId) {
            const data = await request(
                'GET',
                `/admin/conversations/${visitorId}/messages`,
                null,
                this.headers()
            );

            if (data.conversation_id) {
                this._conversationVisitorMap[data.conversation_id] = visitorId;
            }

            data.messages = normalizeMessages(data.messages);
            if (data.messages.length > 0) {
                data.messages.forEach(message => trackLatestIds(this, message));
            }

            return data;
        },

        async getVisitorProfile(visitorId) {
            return request(
                'GET',
                `/admin/visitors/${visitorId}/profile`,
                null,
                this.headers()
            );
        },

        async sendMessage(visitorId, body, clientId = null) {
            const actualClientId = clientId || uuid();
            const payload = {
                body,
                client_id: actualClientId,
            };

            const socketId = await Realtime.getSocketId('admin').catch(() => null);
            if (socketId) {
                payload.socket_id = socketId;
            }

            const data = await request(
                'POST',
                `/admin/conversations/${visitorId}/messages`,
                payload,
                this.headers()
            );

            if (data.message) {
                data.message = normalizeMessage(data.message);
                trackLatestIds(this, data.message);
            }

            return { ...data, client_id: actualClientId };
        },

        async markConversationRead(visitorId) {
            return request(
                'POST',
                `/admin/conversations/${visitorId}/read`,
                null,
                this.headers()
            );
        },

        async subscribeToFeed(onMessage, slot = 'adminFeed') {
            return Realtime.subscribe(slot, 'admin', REALTIME.adminFeedChannel, {
                onMessage: event => {
                    const normalizedEvent = normalizeRealtimeEvent(event);
                    const message = normalizedEvent && normalizedEvent.message
                        ? normalizedEvent.message
                        : normalizedEvent;
                    trackLatestIds(this, message);
                    if (onMessage) {
                        onMessage(normalizedEvent);
                    }
                },
            });
        },

        unsubscribeFeed(slot = 'adminFeed') {
            Realtime.unsubscribe(slot);
        },

        async subscribeToConversation(conversationId, handlers = {}, slot = 'adminConversation') {
            return Realtime.subscribe(
                slot,
                'admin',
                `${REALTIME.conversationPrefix}${conversationId}`,
                {
                    onMessage: event => {
                        const normalizedEvent = normalizeRealtimeEvent(event);
                        const message = normalizedEvent && normalizedEvent.message
                            ? normalizedEvent.message
                            : normalizedEvent;
                        trackLatestIds(this, message);
                        if (handlers.onMessage) {
                            handlers.onMessage(normalizedEvent);
                        }
                    },
                    onTyping: handlers.onTyping,
                    onRead: handlers.onRead,
                    onPresenceChange: handlers.onPresenceChange,
                }
            );
        },

        unsubscribeConversation(slot = 'adminConversation') {
            Realtime.unsubscribe(slot);
        },

        async subscribeToPresence(onPresenceChange, slot = 'adminPresence') {
            return Realtime.subscribe(slot, 'admin', REALTIME.adminPresenceChannel, {
                onPresenceChange,
            });
        },

        async sendTyping(conversationId, typing, slot = 'adminConversation') {
            Realtime.trigger(
                slot,
                REALTIME.typingClientEvent,
                typingPayload('admin', this.getInfo(), conversationId, typing)
            );

            const payload = {
                typing: !!typing,
            };

            const socketId = await Realtime.getSocketId('admin').catch(() => null);
            if (socketId) {
                payload.socket_id = socketId;
            }

            try {
                const visitorId = this._conversationVisitorMap[conversationId];
                if (!visitorId) {
                    return null;
                }

                return await request(
                    'POST',
                    `/admin/conversations/${visitorId}/typing`,
                    payload,
                    this.headers()
                );
            } catch (error) {
                console.warn('Admin typing update failed', error);
                return null;
            }
        },

        unsubscribePresence(slot = 'adminPresence') {
            Realtime.unsubscribe(slot);
        },

        disconnectRealtime() {
            Realtime.disconnect('admin');
        },
    };

    return {
        Visitor,
        Admin: AdminAPI,
        API_BASE,
        REALTIME,
        formatLocalTime,
        formatStatusTime,
    };
})();
