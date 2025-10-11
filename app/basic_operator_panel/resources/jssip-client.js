/**
 * JsSIP 客户端封装
 * 提供 SIP 注册、呼叫、多方会议等功能
 */
(function(window) {
    'use strict';

    // JsSIP 客户端类
    function JsSipClient() {
        this.ua = null;
        this.sessions = {}; // 存储所有活动会话 {sessionId: session}
        this.currentSession = null; // 当前主会话
        this.incomingSession = null; // 来电会话
        this.conferenceSessions = []; // 会议会话列表
        
        // 状态标志
        this.isRegistered = false;
        this.isCalling = false;
        this.isCallEstablished = false;
        this.isHeld = false;
        this.isMuted = false;
        this.hasIncomingCall = false;
        this.incomingCallerInfo = null;
        
        // 回调函数
        this.callbacks = {
            onRegistered: null,
            onUnregistered: null,
            onRegistrationFailed: null,
            onIncomingCall: null,
            onCallEstablished: null,
            onCallEnded: null,
            onCallFailed: null,
            onCallProgress: null
        };
    }

    // 设置回调
    JsSipClient.prototype.on = function(event, callback) {
        if (this.callbacks.hasOwnProperty('on' + event.charAt(0).toUpperCase() + event.slice(1))) {
            this.callbacks['on' + event.charAt(0).toUpperCase() + event.slice(1)] = callback;
        }
    };

    // 触发回调
    JsSipClient.prototype.trigger = function(event, data) {
        var callbackName = 'on' + event.charAt(0).toUpperCase() + event.slice(1);
        if (this.callbacks[callbackName] && typeof this.callbacks[callbackName] === 'function') {
            this.callbacks[callbackName](data);
        }
    };

    // 从 URI 解析主机
    JsSipClient.prototype.parseHostFromUri = function(uri) {
        var match = uri.match(/@([^;:>]+)/);
        return match ? match[1] : 'localhost';
    };

    // 注册 SIP
    JsSipClient.prototype.register = function(params) {
        var self = this;
        
        return new Promise(function(resolve, reject) {
            if (self.ua) {
                self.unregister().then(function() {
                    self._doRegister(params, resolve, reject);
                });
            } else {
                self._doRegister(params, resolve, reject);
            }
        });
    };

    // 执行注册
    JsSipClient.prototype._doRegister = function(params, resolve, reject) {
        var self = this;
        console.log('执行注册', params);
        var host = this.parseHostFromUri(params.uri);

        // 检测是否使用 WSS，并显式设置 via_transport
        var isSecure = params.wsServers.toLowerCase().startsWith('wss://');
        var socketConfig = isSecure ? { via_transport: 'wss' } : {};
        
        console.log('WebSocket 配置:', { wsServers: params.wsServers, isSecure: isSecure, socketConfig: socketConfig });

        var configuration = {
            sockets: [new JsSIP.WebSocketInterface(params.wsServers, socketConfig)],
            uri: params.uri,
            authorization_user: params.authUser,
            password: params.password,
            display_name: params.displayName || 'Dispatcher',
            session_timers: false,
            register: true,
            pcConfig: {
                iceServers: [
                    { urls: ['stun:stun.l.google.com:19302'] },
                    { urls: ['stun:stun1.l.google.com:19302'] }
                ]
            }
        };

        console.log('创建 JsSIP UA，配置:', configuration);

        try {
            self.ua = new JsSIP.UA(configuration);
        } catch (error) {
            console.error('创建 UA 失败:', error);
            reject(error);
            return;
        }

        // WebSocket 连接事件
        self.ua.on('connected', function(e) {
            console.log('✅ WebSocket 已连接', e);
        });

        self.ua.on('disconnected', function(e) {
            console.log('❌ WebSocket 已断开', e);
            self.isRegistered = false;
        });

        // 注册事件
        self.ua.on('registered', function(e) {
            console.log('✅ SIP 注册成功', e);
            self.isRegistered = true;
            self.trigger('registered', e);
            resolve();
        });

        self.ua.on('unregistered', function(e) {
            console.log('📤 SIP 已注销', e);
            self.isRegistered = false;
            self.trigger('unregistered', e);
        });

        self.ua.on('registrationFailed', function(e) {
            console.error('❌ SIP 注册失败', e);
            self.isRegistered = false;
            self.trigger('registrationFailed', e);
            reject(new Error('注册失败: ' + (e.cause || e.response?.status_code || '未知错误')));
        });

        // 来电处理
        self.ua.on('newRTCSession', function(e) {
            var session = e.session;
            console.log('📞 收到 RTC 会话', e);

            // 处理来电
            if (session.direction === 'incoming') {
                console.log('📞 来电');
                self.incomingSession = session;
                self.hasIncomingCall = true;

                var remoteIdentity = session.remote_identity;
                var callerUri = remoteIdentity?.uri?.toString() || 'Unknown';
                var callerName = remoteIdentity?.display_name || callerUri;

                self.incomingCallerInfo = {
                    name: callerName,
                    uri: callerUri
                };

                console.log('来电者:', callerName, callerUri);
                self.trigger('incomingCall', self.incomingCallerInfo);

                // 监听来电的状态变化
                self.setupSessionListeners(session);
            } else {
                // 呼出电话
                console.log('📞 呼出会话');
            }
        });

        // 启动 UA
        console.log('启动 JsSIP UA...');
        self.ua.start();

        // 设置超时
        var timeout = setTimeout(function() {
            console.warn('⏰ 注册超时');
            reject(new Error('注册超时'));
        }, 30000);

        // 清理超时
        self.ua.once('registered', function() { clearTimeout(timeout); });
        self.ua.once('registrationFailed', function() { clearTimeout(timeout); });
    };

    // 设置会话监听器
    JsSipClient.prototype.setupSessionListeners = function(session) {
        var self = this;

        // 通话进展
        session.on('progress', function(e) {
            console.log('📞 通话进展', e);
            self.trigger('callProgress', e);
        });

        // 通话接受
        session.on('accepted', function(e) {
            console.log('✅ 通话已接受', e);
        });

        // 通话确认（建立）
        session.on('confirmed', function(e) {
            console.log('✅ 通话已建立', e);
            self.isCallEstablished = true;
            self.isCalling = true;
            self.trigger('callEstablished', { session: session });

            // 绑定媒体流
            self.bindMedia(session);
        });

        // 通话结束
        session.on('ended', function(e) {
            console.log('📞 通话已结束', e);
            self.endCall(session);
            self.trigger('callEnded', { session: session });
        });

        // 通话失败
        session.on('failed', function(e) {
            console.error('❌ 通话失败', e);
            self.endCall(session);
            self.trigger('callFailed', { session: session, error: e });
        });

        // Hold 状态
        session.on('hold', function(e) {
            console.log('📞 通话保持', e);
            if (e.originator === 'local') {
                self.isHeld = true;
            }
        });

        session.on('unhold', function(e) {
            console.log('📞 通话恢复', e);
            if (e.originator === 'local') {
                self.isHeld = false;
            }
        });

        // Mute 状态
        session.on('muted', function(e) {
            console.log('🔇 已静音', e);
        });

        session.on('unmuted', function(e) {
            console.log('🔊 已取消静音', e);
        });
    };

    // 绑定媒体流
    JsSipClient.prototype.bindMedia = function(session) {
        var connection = session.connection;

        if (!connection) {
            console.warn('⚠️ 没有 RTCPeerConnection');
            return;
        }

        // 处理远程音频流
        connection.ontrack = function(event) {
            console.log('📡 收到媒体轨道', event.track.kind, event);

            if (event.track.kind === 'audio') {
                var stream = event.streams.length ? event.streams[0] : new MediaStream([event.track]);
                var audio = new Audio();
                audio.srcObject = stream;
                audio.play().catch(function(err) {
                    console.error('播放音频失败:', err);
                });
                console.log('🔊 远程音频流已播放');
            }
        };
    };

    // 注销
    JsSipClient.prototype.unregister = function() {
        var self = this;
        console.log('开始注销 JsSIP...');

        return new Promise(function(resolve) {
            try {
                // 终止所有会话
                for (var sessionId in self.sessions) {
                    if (self.sessions.hasOwnProperty(sessionId)) {
                        self.sessions[sessionId].terminate();
                    }
                }
                self.sessions = {};

                if (self.currentSession) {
                    self.currentSession.terminate();
                    self.currentSession = null;
                }

                if (self.incomingSession) {
                    self.incomingSession.terminate();
                    self.incomingSession = null;
                }

                if (self.ua) {
                    if (self.ua.isRegistered()) {
                        self.ua.unregister();
                    }
                    self.ua.stop();
                    self.ua = null;
                }

                self.isRegistered = false;
                self.isCalling = false;
                self.isCallEstablished = false;
                self.isHeld = false;
                self.isMuted = false;
                self.hasIncomingCall = false;
                self.incomingCallerInfo = null;

                console.log('✅ JsSIP 注销完成');
                resolve();
            } catch (error) {
                console.error('注销时发生错误:', error);
                resolve();
            }
        });
    };

    // 拨打电话
    JsSipClient.prototype.makeCall = function(target, options) {
        var self = this;
        options = options || {};

        if (!this.ua) {
            return Promise.reject(new Error('UA 未就绪'));
        }

        if (!this.ua.isRegistered()) {
            return Promise.reject(new Error('未注册，无法拨打电话'));
        }

        console.log('📞 开始拨打电话到:', target, '选项:', options);

        var callOptions = {
            mediaConstraints: {
                audio: options.audio !== false,
                video: options.video || false
            },
            rtcOfferConstraints: {
                offerToReceiveAudio: options.audio !== false,
                offerToReceiveVideo: options.video || false
            },
            pcConfig: {
                iceServers: [
                    { urls: 'stun:stun.l.google.com:19302' },
                    { urls: 'stun:stun1.l.google.com:19302' }
                ]
            }
        };

        return new Promise(function(resolve, reject) {
            try {
                var session = self.ua.call(target, callOptions);
                var sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                
                self.sessions[sessionId] = session;
                session._customId = sessionId;
                
                if (!self.currentSession) {
                    self.currentSession = session;
                }
                
                self.isCalling = true;

                console.log('📤 呼叫已发起', session);

                // 设置监听器
                self.setupSessionListeners(session);
                
                resolve({ session: session, sessionId: sessionId });
            } catch (error) {
                console.error('❌ 拨打电话失败:', error);
                self.isCalling = false;
                reject(error);
            }
        });
    };

    // 批量拨打电话（用于组呼/全呼）
    JsSipClient.prototype.makeCallBatch = function(targets, options) {
        var self = this;
        var promises = [];

        targets.forEach(function(target) {
            promises.push(self.makeCall(target, options));
        });

        return Promise.all(promises);
    };

    // 挂断电话
    JsSipClient.prototype.hangup = function(sessionId) {
        var self = this;
        console.log('挂断电话', sessionId);

        return new Promise(function(resolve) {
            try {
                if (sessionId && self.sessions[sessionId]) {
                    self.sessions[sessionId].terminate();
                    delete self.sessions[sessionId];
                } else if (self.currentSession) {
                    self.currentSession.terminate();
                    self.currentSession = null;
                }

                if (self.incomingSession) {
                    self.incomingSession.terminate();
                    self.incomingSession = null;
                }

                self.endCall();
                resolve();
            } catch (error) {
                console.error('挂断时发生错误:', error);
                resolve();
            }
        });
    };

    // 批量挂断（用于结束组呼/会议）
    JsSipClient.prototype.hangupAll = function() {
        var self = this;
        var promises = [];

        for (var sessionId in self.sessions) {
            if (self.sessions.hasOwnProperty(sessionId)) {
                promises.push(self.hangup(sessionId));
            }
        }

        return Promise.all(promises);
    };

    // 清理通话状态
    JsSipClient.prototype.endCall = function(session) {
        if (session && session._customId) {
            delete this.sessions[session._customId];
        }

        // 如果没有活动会话了，重置状态
        if (Object.keys(this.sessions).length === 0) {
            this.isCalling = false;
            this.isCallEstablished = false;
            this.isHeld = false;
            this.isMuted = false;
        }

        this.hasIncomingCall = false;
        this.incomingCallerInfo = null;
        this.currentSession = null;
        this.incomingSession = null;
    };

    // 接听来电
    JsSipClient.prototype.acceptIncomingCall = function(options) {
        var self = this;
        options = options || {};

        if (!this.incomingSession) {
            return Promise.reject(new Error('没有来电可接听'));
        }

        console.log('接听来电', options);

        var callOptions = {
            mediaConstraints: {
                audio: options.audio !== false,
                video: options.video || false
            },
            pcConfig: {
                iceServers: [
                    { urls: 'stun:stun.l.google.com:19302' },
                    { urls: 'stun:stun1.l.google.com:19302' }
                ]
            }
        };

        return new Promise(function(resolve, reject) {
            try {
                self.incomingSession.answer(callOptions);
                
                var sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                self.incomingSession._customId = sessionId;
                self.sessions[sessionId] = self.incomingSession;
                
                self.currentSession = self.incomingSession;
                self.hasIncomingCall = false;
                self.isCalling = true;
                console.log('✅ 已接听来电');
                resolve({ session: self.incomingSession, sessionId: sessionId });
            } catch (error) {
                console.error('接听来电失败:', error);
                self.hasIncomingCall = false;
                self.incomingCallerInfo = null;
                self.incomingSession = null;
                reject(error);
            }
        });
    };

    // 拒绝来电
    JsSipClient.prototype.rejectIncomingCall = function() {
        var self = this;

        if (!this.incomingSession) {
            return Promise.reject(new Error('没有来电可拒绝'));
        }

        console.log('拒绝来电');

        return new Promise(function(resolve, reject) {
            try {
                self.incomingSession.terminate();
                console.log('✅ 已拒绝来电');
                resolve();
            } catch (error) {
                console.error('拒绝来电失败:', error);
                reject(error);
            } finally {
                self.hasIncomingCall = false;
                self.incomingCallerInfo = null;
                self.incomingSession = null;
            }
        });
    };

    // 发送 DTMF
    JsSipClient.prototype.sendDtmf = function(tone, sessionId) {
        var session = sessionId ? this.sessions[sessionId] : this.currentSession;

        if (!session) {
            return Promise.reject(new Error('没有活动会话'));
        }

        if (!/^[0-9A-D#*]$/.test(tone)) {
            return Promise.reject(new Error('无效的 DTMF 音调'));
        }

        console.log('发送 DTMF:', tone);

        return new Promise(function(resolve, reject) {
            try {
                session.sendDTMF(tone);
                resolve();
            } catch (error) {
                console.error('发送 DTMF 失败:', error);
                reject(error);
            }
        });
    };

    // 保持通话
    JsSipClient.prototype.hold = function(sessionId) {
        var self = this;
        var session = sessionId ? this.sessions[sessionId] : this.currentSession;

        if (!session) {
            return Promise.reject(new Error('没有活动会话'));
        }

        console.log('保持通话');

        return new Promise(function(resolve, reject) {
            try {
                session.hold();
                self.isHeld = true;
                resolve();
            } catch (error) {
                console.error('保持通话失败:', error);
                reject(error);
            }
        });
    };

    // 恢复通话
    JsSipClient.prototype.unhold = function(sessionId) {
        var self = this;
        var session = sessionId ? this.sessions[sessionId] : this.currentSession;

        if (!session) {
            return Promise.reject(new Error('没有活动会话'));
        }

        console.log('恢复通话');

        return new Promise(function(resolve, reject) {
            try {
                session.unhold();
                self.isHeld = false;
                resolve();
            } catch (error) {
                console.error('恢复通话失败:', error);
                reject(error);
            }
        });
    };

    // 静音
    JsSipClient.prototype.mute = function(sessionId) {
        var self = this;
        var session = sessionId ? this.sessions[sessionId] : this.currentSession;

        if (!session) {
            return Promise.reject(new Error('没有活动会话'));
        }

        console.log('静音');

        try {
            session.mute({ audio: true, video: false });
            self.isMuted = true;
        } catch (error) {
            console.error('静音失败:', error);
            throw error;
        }
    };

    // 取消静音
    JsSipClient.prototype.unmute = function(sessionId) {
        var self = this;
        var session = sessionId ? this.sessions[sessionId] : this.currentSession;

        if (!session) {
            return Promise.reject(new Error('没有活动会话'));
        }

        console.log('取消静音');

        try {
            session.unmute({ audio: true, video: false });
            self.isMuted = false;
        } catch (error) {
            console.error('取消静音失败:', error);
            throw error;
        }
    };

    // 转接
    JsSipClient.prototype.transfer = function(target, sessionId) {
        var session = sessionId ? this.sessions[sessionId] : this.currentSession;

        if (!session) {
            return Promise.reject(new Error('没有活动会话'));
        }

        console.log('转接到:', target);

        return new Promise(function(resolve, reject) {
            try {
                session.refer(target);
                console.log('✅ 转接请求已发送');
                resolve();
            } catch (error) {
                console.error('转接失败:', error);
                reject(error);
            }
        });
    };

    // 获取所有活动会话
    JsSipClient.prototype.getActiveSessions = function() {
        return Object.keys(this.sessions).map(function(sessionId) {
            return {
                sessionId: sessionId,
                session: this.sessions[sessionId]
            };
        }, this);
    };

    // 静音特定会话（用于会议控制）
    JsSipClient.prototype.muteSession = function(sessionId) {
        if (this.sessions[sessionId]) {
            try {
                this.sessions[sessionId].mute({ audio: true, video: false });
                console.log('会话静音:', sessionId);
            } catch (error) {
                console.error('会话静音失败:', error);
            }
        }
    };

    // 取消静音特定会话
    JsSipClient.prototype.unmuteSession = function(sessionId) {
        if (this.sessions[sessionId]) {
            try {
                this.sessions[sessionId].unmute({ audio: true, video: false });
                console.log('会话取消静音:', sessionId);
            } catch (error) {
                console.error('会话取消静音失败:', error);
            }
        }
    };

    // 诊断连接
    JsSipClient.prototype.diagnoseConnection = function() {
        console.log('=== JsSIP 连接诊断 ===');
        console.log('📋 基本状态:');
        console.log('  - 注册状态:', this.isRegistered ? '✅ 已注册' : '❌ 未注册');
        console.log('  - 通话状态:', this.isCalling ? '📞 通话中' : '⭕ 空闲');
        console.log('  - 通话已建立:', this.isCallEstablished ? '✅ 是' : '❌ 否');
        console.log('  - 来电状态:', this.hasIncomingCall ? '📞 有来电' : '⭕ 无来电');
        console.log('  - 活动会话数:', Object.keys(this.sessions).length);

        if (this.ua) {
            console.log('🌐 UA 信息:');
            console.log('  - UA 状态:', this.ua.isRegistered() ? '已注册' : '未注册');
            console.log('  - UA 已连接:', this.ua.isConnected() ? '是' : '否');
            console.log('  - 配置:', this.ua.configuration);
        } else {
            console.log('🚫 UA: 未初始化');
        }

        console.log('💡 故障排除建议:');
        if (!this.ua) {
            console.log('  - 请先进行 SIP 注册');
        } else if (!this.ua.isConnected()) {
            console.log('  - 检查 WebSocket 服务器地址是否正确');
            console.log('  - 检查网络连接是否正常');
            console.log('  - 检查防火墙设置');
        } else if (!this.isRegistered) {
            console.log('  - 检查 SIP URI 格式是否正确');
            console.log('  - 检查用户名和密码是否正确');
            console.log('  - 检查 SIP 服务器是否允许该用户注册');
        }
        console.log('================');
    };

    // 导出到全局
    window.JsSipClient = JsSipClient;

})(window);

