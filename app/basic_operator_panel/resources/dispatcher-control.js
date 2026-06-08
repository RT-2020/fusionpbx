/**
 * 调度控制逻辑
 * 实现中继汇接、组呼全呼、多方会议功能
 */
(function(window, $) {
    'use strict';

    // 调度控制器
    function DispatcherControl() {
        this.sipClient = new window.JsSipClient();
        this.config = {
            uri: '',
            wsServers: '',
            authUser: '',
            password: '',
            displayName: '调度员'
        };

        // 中继状态
        this.trunkCalls = []; // 中继呼叫列表 {callId, fromNumber, toNumber, session, status, startTime}
        
        // 组呼状态
        this.groupCallActive = false;
        this.groupCallSessions = [];
        this.groupCallStartTime = null;
        this.callGroups = {}; // 呼叫分组配置
        
        // 会议状态
        this.conferenceActive = false;
        this.conferenceParticipants = []; // {extension, session, muted, joinTime}
        this.conferenceStartTime = null;
        
        // 自动中继模式
        this.autoTrunkMode = false;
        
        // 计时器
        this.timers = {
            groupCall: null,
            conference: null
        };
        this.transferring = {};
        this.holdTimers = {};
        this.holdRemaining = {};
        this.emergencyPending = {};
        this.emergencySessions = {};
        this.emergencyCallMeta = {};
        this.scenePromptTimers = {};
        this.scenePromptActive = {};
        this.presenceTimer = null;
        this.presenceBound = false;
        this.tabUuid = '';
        this.presenceExtension = '';
	        this.entityStatusTimer = null;
	        this.entityStatusSnapshot = { users: [], trunks: [], summary: {}, warnings: [] };
	        this.entityStatusPending = false;
	        this.entityStatusError = '';
	        this.sessionVisualState = {};
        
        // 事件监听器
        this.eventHandlers = {};
        
        this.init();
    }
    
    // 事件系统：注册事件监听器
    DispatcherControl.prototype.on = function(eventName, handler) {
        if (!this.eventHandlers[eventName]) {
            this.eventHandlers[eventName] = [];
        }
        this.eventHandlers[eventName].push(handler);
    };
    
    // 事件系统：触发事件
    DispatcherControl.prototype.emit = function(eventName, data) {
        if (this.eventHandlers[eventName]) {
            this.eventHandlers[eventName].forEach(function(handler) {
                try {
                    handler(data);
                } catch (e) {
                    console.error('事件处理器错误 [' + eventName + ']:', e);
                }
            });
        }
    };

    // 初始化
    DispatcherControl.prototype.init = function() {
        var self = this;

        // 设置事件回调
        this.sipClient.on('registered', function() {
            self.onRegistered();
        });
        
        this.sipClient.on('unregistered', function() {
            console.log('调度员已注销');
            self.onUnregistered();
        });
        
        this.sipClient.on('registrationFailed', function(e) {
            console.error('SIP注册失败:', e);
            self.onRegistrationFailed(e);
        });
        
        // 添加统一的通话事件监听
	        this.sipClient.on('callEnded', function(data) {
	            var sid = (data && data.session && data.session._customId) ? data.session._customId : data.sessionId;
	            self.updateCallStatus('ended', sid, '通话已结束');
            var shouldClearAlarm = !!((self.emergencySessions && self.emergencySessions[sid]) || (self.emergencyCallMeta && self.emergencyCallMeta[sid] && self.emergencyCallMeta[sid].isEmergency));
            if (shouldClearAlarm) {
                try { self.clearEmergencyAlarmForSession(sid, { status: 'completed', reason: 'hangup', acknowledge: false, silent: true, allowRepeat: true, cleanupMetaOnComplete: true }); } catch (e) {}
            }
            if (self.transferring && self.transferring[sid]) {
                self.showToast('转接完成 (Transfer completed)', 'success');
                delete self.transferring[sid];
            }
            try { if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyTone(sid) } catch(e) {}
            try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.releaseEmergencyOwner(sid) }catch(e){}
            try{ if (self.emergencySessions && self.emergencySessions[sid]){ delete self.emergencySessions[sid]; if (window.hideEmergencyStatus) hideEmergencyStatus(); if (window.DispatcherUtils && DispatcherUtils.showEmergencyEndConfirm) DispatcherUtils.showEmergencyEndConfirm('通话线路已结束'); } }catch(e){}
            try{ if (self.emergencyCallMeta && self.emergencyCallMeta[sid]){ delete self.emergencyCallMeta[sid]; } }catch(e){}
	            try { self.cleanupIncomingUI(sid) } catch(e){}
	            try { self.clearSessionVisualState(sid); } catch(e){}
	            try{ if (window.dispatcherLogger && typeof dispatcherLogger.logEvent === 'function'){ dispatcherLogger.logEvent('emergency_call_end', { sessionId: sid }) } }catch(e){}
	            self.onCallEnded(data);
	            try{ self.updateUI() }catch(e){}
	            if (self.renderLinesGrid) self.renderLinesGrid();
	        });

        this.sipClient.on('callFailed', function(data) {
            var errorMessage = '通话失败';
            if (data.error && data.error.message) {
                errorMessage = data.error.message;
            }
            var sid = (data && data.session && data.session._customId) ? data.session._customId : data.sessionId;
            var failureState = self.classifyCallFailure(data, errorMessage);
            var shouldClearAlarm = !!((self.emergencySessions && self.emergencySessions[sid]) || (self.emergencyCallMeta && self.emergencyCallMeta[sid] && self.emergencyCallMeta[sid].isEmergency));
            if (shouldClearAlarm) {
                try { self.clearEmergencyAlarmForSession(sid, { status: failureState.benign ? 'cancelled' : 'failed', reason: failureState.benign ? 'cancelled' : 'failed', acknowledge: false, silent: true, allowRepeat: true, cleanupMetaOnComplete: true }); } catch (e) {}
            }
            if (!failureState.benign) {
                try { self.handleSipError(failureState.code, sid, 'callFailed') } catch(e){}
            }
            self.updateCallStatus(failureState.status, sid, failureState.message);
            if (self.transferring && self.transferring[sid]) {
                self.showToast('转接失败 (Transfer failed): '+failureState.message, failureState.benign ? 'warn' : 'error');
                delete self.transferring[sid];
            }
            try { if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyTone(sid) }catch(e){}
            try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.releaseEmergencyOwner(sid) }catch(e){}
	            try { self.cleanupIncomingUI(sid) } catch(e){}
	            try { self.clearSessionVisualState(sid); } catch(e){}
	            try{ if (self.emergencySessions && self.emergencySessions[sid]){ delete self.emergencySessions[sid]; if (window.hideEmergencyStatus) hideEmergencyStatus(); } }catch(e){}
	            try{ if (self.emergencyCallMeta && self.emergencyCallMeta[sid]){ delete self.emergencyCallMeta[sid]; } }catch(e){}
	            self.onCallFailed($.extend({}, data, { uiFailure: failureState }));
	            if (self.renderLinesGrid) self.renderLinesGrid();
	        });

        this.sipClient.on('callEstablished', function(data) {
            self.updateCallStatus('connected', data.sessionId, '通话已建立');
            if (self.renderLinesGrid) self.renderLinesGrid();
        });

        this.sipClient.on('callEstablished', function(data) {
            var session = data && data.session ? data.session : null;
            var sessionId = (data && data.sessionId) || (session && session._customId) || '';
            try { self.ensureDefaultCallRecording(session, sessionId); } catch (e) {}
            try {
                var sessionMeta = self.sipClient && self.sipClient.getSessionMeta ? self.sipClient.getSessionMeta(session || sessionId) : null;
                if ((sessionMeta && sessionMeta.isEmergency) || (self.emergencyCallMeta && self.emergencyCallMeta[sessionId] && self.emergencyCallMeta[sessionId].isEmergency)) {
                    self.clearEmergencyAlarmForSession(sessionId, { status: 'answered', reason: 'answered', acknowledge: true, silent: true, allowRepeat: true });
                }
            } catch (e) {}
            try { self.onCallEstablished({ session: session, sessionId: sessionId }); } catch (e) {}
        });

        this.sipClient.on('incomingCall', function(data) {
            var incomingInfo = self.normalizeIncomingCallerInfo(data);
            if (self.tryAutoAnswerIncoming(incomingInfo, function() {
                self.onIncomingCall(incomingInfo);
            })) {
                return;
            }
            try {
                if (self.sipClient && self.sipClient.isCalling) {
                    $('#dispatcher-busy-queue').show();
                } else {
                    $('#dispatcher-busy-queue').hide();
                }
            } catch(e) {}
            self.onIncomingCall(incomingInfo);
        });

        this.sipClient.on('callProgress', function(data) {
            self.onCallProgress(data);
        });

        // 加载保存的配置
        this.loadConfig();
        
        // 加载呼叫分组
        this.loadCallGroups();
        this.bindPresenceLifecycle();
        this.startEntityStatusPolling();
    };

    // 加载配置
    DispatcherControl.prototype.loadConfig = function() {
        var self = this;
        
        // 先从本地存储加载
        var saved = localStorage.getItem('dispatcher_sip_config');
        if (saved) {
            try {
                this.config = JSON.parse(saved);
                // 自动填充表单
                this.fillConfigForm();
            } catch (e) {
                console.error('加载配置失败:', e);
            }
        }
        
        // 从服务器加载推荐配置
        $.ajax({
            url: 'dispatcher_api.php?action=get_sip_config',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    // 如果本地没有配置，使用服务器配置
                    if (!saved) {
                        self.config = $.extend({}, response.data, self.config || {});
                        self.fillConfigForm();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.warn('从服务器加载配置失败:', error);
            }
        });
    };

    // 填充配置表单
    DispatcherControl.prototype.fillConfigForm = function() {
        if (this.config.uri) $('#dispatcher-uri').val(this.config.uri);
        if (this.config.wsServers) $('#dispatcher-ws').val(this.config.wsServers);
        if (this.config.authUser) $('#dispatcher-user').val(this.config.authUser);
        if (this.config.displayName) $('#dispatcher-display-name').val(this.config.displayName);
        // 密码不自动填充（安全考虑）
    };

    // 保存配置
    DispatcherControl.prototype.saveConfig = function() {
        localStorage.setItem('dispatcher_sip_config', JSON.stringify(this.config));
    };

    // 加载呼叫分组
    DispatcherControl.prototype.loadCallGroups = function() {
        var self = this;
        
        // 先从本地存储加载
        var saved = localStorage.getItem('dispatcher_call_groups');
        if (saved) {
            try {
                this.callGroups = JSON.parse(saved);
            } catch (e) {
                console.error('加载呼叫分组失败:', e);
                this.callGroups = {
                    'default': {
                        name: '默认组',
                        extensions: []
                    }
                };
            }
        } else {
            this.callGroups = {
                'default': {
                    name: '默认组',
                    extensions: []
                }
            };
        }
        
        // 从服务器加载分组（基于 call_group 字段）
        $.ajax({
            url: 'dispatcher_api.php?action=get_call_groups',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    // 合并服务器分组和本地分组
                    for (var groupId in response.data) {
                        if (response.data.hasOwnProperty(groupId)) {
                            // 服务器分组优先
                            self.callGroups[groupId] = response.data[groupId];
                        }
                    }
                    self.saveCallGroups();
                    
                    // 如果页面已加载，更新显示
                    if (typeof updateGroupsList === 'function') {
                        updateGroupsList();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.warn('从服务器加载分组失败:', error);
            }
        });
    };

    // 保存呼叫分组
    DispatcherControl.prototype.saveCallGroups = function() {
        localStorage.setItem('dispatcher_call_groups', JSON.stringify(this.callGroups));
    };

    // 注册 SIP
    DispatcherControl.prototype.getTabUuid = function() {
        if (this.tabUuid) {
            return this.tabUuid;
        }
        var context = window.OPERATOR_PANEL_CONTEXT || {};
        this.tabUuid = context.tabUuid || '';
        if (!this.tabUuid) {
            this.tabUuid = 'tab_' + Date.now() + '_' + Math.random().toString(36).slice(2, 12);
        }
        return this.tabUuid;
    };

    DispatcherControl.prototype.getPresenceExtension = function() {
        var context = window.OPERATOR_PANEL_CONTEXT || {};
        var extensions = context.sessionExtensions || [];
        var extension = '';
        if (this.config && this.config.authUser) {
            extension = this.config.authUser;
        } else if (context.currentExtension) {
            extension = context.currentExtension;
        } else if (extensions.length) {
            extension = extensions[0];
        }
        extension = (extension || '').toString().trim();
        this.presenceExtension = extension;
        return extension;
    };

    DispatcherControl.prototype.getPresenceExtensions = function() {
        var extension = this.getPresenceExtension();
        return extension ? [extension] : [];
    };

    DispatcherControl.prototype.bindPresenceLifecycle = function() {
        var self = this;
        if (this.presenceBound) {
            return;
        }
        this.presenceBound = true;
        window.addEventListener('beforeunload', function() {
            self.stopEntityStatusPolling();
            self.clearPresence(true);
        });
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible' && self.isRegistered()) {
                self.reportPresence();
            }
        });
    };

    DispatcherControl.prototype.startPresenceHeartbeat = function() {
        var self = this;
        this.stopPresenceHeartbeat(false);
        this.reportPresence();
        this.presenceTimer = window.setInterval(function() {
            self.reportPresence();
        }, 20000);
    };

    DispatcherControl.prototype.stopPresenceHeartbeat = function(clearRemote) {
        if (this.presenceTimer) {
            window.clearInterval(this.presenceTimer);
            this.presenceTimer = null;
        }
        if (clearRemote !== false) {
            this.clearPresence();
        }
    };

    DispatcherControl.prototype.reportPresence = function() {
        var extension = this.getPresenceExtension();
        if (!extension) {
            return null;
        }
        return $.ajax({
            url: 'dispatcher_api.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'report_operator_panel_presence',
                extension: extension,
                extensions: this.getPresenceExtensions(),
                tab_uuid: this.getTabUuid(),
                sip_uri: (this.config && this.config.uri) ? this.config.uri : '',
                user_agent: navigator.userAgent || ''
            }
        });
    };

    DispatcherControl.prototype.clearPresence = function(useBeacon) {
        var extension = this.getPresenceExtension();
        var payload = {
            action: 'clear_operator_panel_presence',
            extension: extension,
            extensions: this.getPresenceExtensions(),
            tab_uuid: this.getTabUuid()
        };
        if (useBeacon && navigator.sendBeacon) {
            try {
                var formData = new FormData();
                Object.keys(payload).forEach(function(key) {
                    formData.append(key, payload[key] || '');
                });
                navigator.sendBeacon('dispatcher_api.php', formData);
                return;
            } catch (e) {}
        }
        return $.ajax({
            url: 'dispatcher_api.php',
            type: 'POST',
            dataType: 'json',
            async: !useBeacon,
            data: payload
        });
    };

    DispatcherControl.prototype.extractExtensionFromUri = function(uri) {
        var value = (uri || '').toString().trim();
        if (!value) {
            return '';
        }
        var sipMatch = value.match(/sip:([^@;>]+)/i);
        if (sipMatch && sipMatch[1]) {
            value = sipMatch[1];
        }
        return value.replace(/[^0-9A-Za-z_*#-]/g, '');
    };

    DispatcherControl.prototype.escapeHtml = function(value) {
        return (value === null || typeof value === 'undefined' ? '' : String(value))
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    DispatcherControl.prototype.getIncomingSceneConfig = function(scene) {
        var buildPhonePrompt = function(tones, volume, finalGap, pulseCount) {
            var events = [];
            var count = pulseCount || 3;
            var pulseDuration = 0.26;
            var finalPulseDuration = 0.34;
            var pulseGap = 0.10;
            var legacyCycleTotal = ((count - 1) * (0.16 + 0.10)) + 0.20 + finalGap;
            var adjustedFinalGap = Math.max(0.90, legacyCycleTotal - (((count - 1) * (pulseDuration + pulseGap)) + finalPulseDuration));
            for (var i = 0; i < count; i++) {
                events.push({
                    tones: tones.slice(0),
                    duration: (i === count - 1) ? finalPulseDuration : pulseDuration,
                    gapAfter: (i === count - 1) ? adjustedFinalGap : pulseGap
                });
            }
            return {
                waveform: 'triangle',
                overlayWaveform: 'sine',
                overlayGain: 0.22,
                masterVolume: volume,
                attack: 0.008,
                release: 0.10,
                detuneCents: [0, 3],
                events: events
            };
        };
        var map = {
            normal_in: {
                title: '来电',
                shortLabel: '普通来电',
                theme: 'normal',
                priority: 30,
                interval: 3200,
                entityLabel: '用户',
                prompt: buildPhonePrompt([440], 0.18, 2.48, 3)
            },
            normal_out: { title: '呼出', shortLabel: '普通呼出', theme: 'normal', priority: 20, interval: 0, tones: [], entityLabel: '用户' },
            emergency_in: { title: '紧急呼叫', shortLabel: '紧急呼叫', theme: 'emergency', priority: 100, interval: 0, tones: [], entityLabel: '用户' },
            emergency_out: { title: '紧急呼出', shortLabel: '紧急呼出', theme: 'emergency', priority: 90, interval: 0, tones: [], entityLabel: '用户' },
            trunk_in_manual: {
                title: '中继来电',
                shortLabel: '人工中继',
                theme: 'trunk',
                priority: 80,
                interval: 3200,
                entityLabel: '中继',
                prompt: buildPhonePrompt([523], 0.17, 2.48, 3)
            },
            trunk_in_auto: {
                title: '自动中继',
                shortLabel: '自动中继',
                theme: 'trunk',
                priority: 85,
                interval: 3200,
                entityLabel: '中继',
                prompt: buildPhonePrompt([587], 0.17, 2.48, 3)
            },
            blind_transfer_in: {
                title: '转接来电',
                shortLabel: '盲转来电',
                theme: 'transfer',
                priority: 70,
                interval: 3000,
                entityLabel: '用户',
                prompt: buildPhonePrompt([659], 0.16, 2.38, 3)
            },
            attended_transfer_in: {
                title: '咨询转接',
                shortLabel: '咨询转接',
                theme: 'transfer',
                priority: 75,
                interval: 3000,
                entityLabel: '用户',
                prompt: buildPhonePrompt([698], 0.16, 2.38, 3)
            },
            consult_leg: {
                title: '咨询支路',
                shortLabel: '咨询支路',
                theme: 'transfer',
                priority: 60,
                interval: 2800,
                entityLabel: '用户',
                prompt: buildPhonePrompt([622], 0.14, 2.18, 2)
            },
            eavesdrop_leg: {
                title: '监听接入',
                shortLabel: '监听支路',
                theme: 'monitor',
                priority: 65,
                interval: 4200,
                entityLabel: '监听',
                prompt: {
                    waveform: 'sine',
                    overlayWaveform: 'triangle',
                    overlayGain: 0.20,
                    masterVolume: 0.05,
                    attack: 0.01,
                    release: 0.12,
                    events: [
                        { tones: [392], duration: 0.14, gapAfter: 0.10 },
                        { tones: [392], duration: 0.20, gapAfter: 3.76 }
                    ]
                }
            },
            barge_leg: {
                title: '插入讲话',
                shortLabel: '强插支路',
                theme: 'monitor',
                priority: 78,
                interval: 2800,
                entityLabel: '监控',
                prompt: {
                    waveform: 'triangle',
                    overlayWaveform: 'sawtooth',
                    overlayGain: 0.16,
                    masterVolume: 0.085,
                    attack: 0.008,
                    release: 0.08,
                    events: [
                        { tones: [415, 622], duration: 0.14, gapAfter: 0.06 },
                        { tones: [415, 622], duration: 0.14, gapAfter: 0.06 },
                        { tones: [659, 784], duration: 0.18, gapAfter: 2.22 }
                    ]
                }
            },
            conference_invite: {
                title: '会议邀请',
                shortLabel: '会议邀请',
                theme: 'conference',
                priority: 55,
                interval: 3000,
                entityLabel: '调度',
                prompt: buildPhonePrompt([784], 0.15, 2.38, 3)
            }
        };
        return map[scene] || map.normal_in;
    };

	    DispatcherControl.prototype.getCallerInfoBySessionId = function(sessionId) {
	        var session = this.sipClient && this.sipClient.sessions ? this.sipClient.sessions[sessionId] : null;
	        if (session && this.sipClient && typeof this.sipClient.buildCallerInfoFromSession === 'function') {
	            return this.normalizeIncomingCallerInfo(this.sipClient.buildCallerInfoFromSession(session));
	        }
	        return this.normalizeIncomingCallerInfo({ sessionId: sessionId });
	    };

	    DispatcherControl.prototype.getSessionVisualState = function(sessionId) {
	        if (!sessionId || !this.sessionVisualState) {
	            return {};
	        }
	        return this.sessionVisualState[sessionId] || {};
	    };

	    DispatcherControl.prototype.setSessionVisualState = function(sessionId, patch) {
	        if (!sessionId) {
	            return;
	        }
	        var current = $.extend({}, this.getSessionVisualState(sessionId));
	        var next = $.extend({}, current, patch || {});
	        var hasMeaningfulState = false;
	        Object.keys(next).forEach(function(key) {
	            if (next[key]) {
	                hasMeaningfulState = true;
	            }
	        });
	        if (hasMeaningfulState) {
	            this.sessionVisualState[sessionId] = next;
	        } else {
	            delete this.sessionVisualState[sessionId];
	        }
	    };

	    DispatcherControl.prototype.clearSessionVisualState = function(sessionId) {
	        if (!sessionId || !this.sessionVisualState) {
	            return;
	        }
	        delete this.sessionVisualState[sessionId];
	    };

	    DispatcherControl.prototype.refreshSessionVisualState = function(sessionId) {
	        try {
	            if (this.renderLinesGrid) {
	                this.renderLinesGrid();
	            }
	        } catch (e) {}
	        try {
	            if (sessionId && $('#incoming-' + sessionId).length) {
	                this.queueIncomingCall(this.getCallerInfoBySessionId(sessionId));
	            }
	        } catch (e) {}
	    };

	    DispatcherControl.prototype.normalizeIncomingCallerInfo = function(callerInfo) {
	        var info = $.extend(true, {
	            sessionId: '',
	            name: '',
            uri: '',
            scene: 'normal_in',
            callType: 'normal',
            entityType: 'user',
            sessionState: 'incoming_ringing',
            displayNumber: '',
	            displayName: '',
	            transferMeta: {},
	            trunkMeta: {},
	            monitorMeta: {},
	            headers: {},
	            isRecording: false,
	            recordingPending: false
	        }, callerInfo || {});
	        if ((!info.scene || info.scene === 'trunk_in_manual') && info.trunkMeta && info.trunkMeta.isTrunk && this.autoTrunkMode) {
	            info.scene = 'trunk_in_auto';
	        }
        if (!info.displayNumber) {
            info.displayNumber = this.extractExtensionFromUri(info.uri || '');
        }
        if (!info.displayName) {
            info.displayName = info.name || info.displayNumber || info.uri || '';
        }
        if (!info.name) {
            info.name = info.displayName;
        }
        if (info.scene === 'emergency_in' || info.scene === 'emergency_out') {
            info.isEmergency = true;
        } else if (typeof info.isEmergency === 'undefined') {
            info.isEmergency = false;
        }
        if (!info.entityType) {
            info.entityType = info.scene.indexOf('trunk_') === 0 ? 'trunk' : 'user';
        }
	        if (!info.callType) {
	            if (info.scene === 'conference_invite') info.callType = 'conference';
	            else if (info.scene === 'eavesdrop_leg') info.callType = 'eavesdrop';
	            else if (info.scene === 'barge_leg') info.callType = 'three-way';
            else if (info.scene.indexOf('trunk_') === 0) info.callType = 'trunk';
            else if (info.scene.indexOf('transfer') !== -1 || info.scene === 'consult_leg') info.callType = 'transfer';
	            else if (info.isEmergency) info.callType = 'emergency';
	            else info.callType = 'normal';
	        }
	        if (info.sessionId) {
	            var visualState = this.getSessionVisualState(info.sessionId);
	            if (visualState.isRecording) {
	                info.isRecording = true;
	            }
	            if (visualState.recordingPending && !info.isRecording) {
	                info.recordingPending = true;
	            }
	        }
	        return info;
	    };

    DispatcherControl.prototype.getSessionUiInfo = function(session, sessionId) {
        var info = { sessionId: sessionId || '', scene: 'normal_in', entityType: 'user', sessionState: 'incoming_ringing', displayName: '', displayNumber: '', uri: '' };
        try {
            if (this.sipClient && typeof this.sipClient.buildCallerInfoFromSession === 'function' && session) {
                info = this.normalizeIncomingCallerInfo(this.sipClient.buildCallerInfoFromSession(session));
            }
        } catch (e) {}
        if (!info.sessionId) {
            info.sessionId = sessionId || (session && session._customId) || '';
        }
        if (!info.uri && session && session.remote_identity && session.remote_identity.uri) {
            info.uri = session.remote_identity.uri.toString();
        }
        if (!info.displayName && session && session.remote_identity) {
            info.displayName = session.remote_identity.display_name || info.uri || '';
        }
        if (!info.displayNumber) {
            info.displayNumber = this.extractExtensionFromUri(info.uri || '');
        }
        return info;
    };

	DispatcherControl.prototype.isSessionTracked = function(sessionId) {
		if (!sessionId) {
			return false;
		}
		if (this.sipClient && this.sipClient.sessions && this.sipClient.sessions[sessionId]) {
			return true;
		}
		if (this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId === sessionId) {
			return true;
		}
		return false;
	};

	DispatcherControl.prototype.shouldAutoRecordSession = function(session, sessionId) {
		var info = this.getSessionUiInfo(session, sessionId);
		var callType = (info.callType || '').toLowerCase();
		if (info.isEmergency || callType === 'emergency') {
			return false;
		}
		return true;
	};

	DispatcherControl.prototype.hasBuiltInRecordingSession = function(session, sessionId) {
		var info = this.getSessionUiInfo(session, sessionId);
		var scene = (info.scene || '').toLowerCase();
		var callType = (info.callType || '').toLowerCase();
		return (
			callType === 'conference' ||
			callType === 'group' ||
			callType === 'eavesdrop' ||
			callType === 'three-way' ||
			scene === 'conference_invite' ||
			scene === 'eavesdrop_leg' ||
			scene === 'barge_leg'
		);
	};

	DispatcherControl.prototype.requestSessionRecording = function(sessionId) {
		try {
			var self = this;
			var user = this.getPresenceExtension ? this.getPresenceExtension() : '';
			var session = (this.sipClient && this.sipClient.sessions && this.sipClient.sessions[sessionId]) || null;
			if (!session && this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId === sessionId) {
				session = this.sipClient.incomingSession;
			}
			var info = this.getSessionUiInfo(session, sessionId);
			var updateRecordingState = function(patch) {
				if (!sessionId || !self.isSessionTracked(sessionId)) {
					return;
				}
				self.setSessionVisualState(sessionId, patch);
				self.refreshSessionVisualState(sessionId);
			};
			if (sessionId) {
				this.setSessionVisualState(sessionId, {
					recordingPending: true,
					isRecording: false
				});
				this.refreshSessionVisualState(sessionId);
			}
			if (!user) {
				updateRecordingState({ recordingPending: false, isRecording: false });
				return;
			}
			this.postDispatcherAction('start_call_recording', {
				extension: user,
				session_id: sessionId || '',
				remote_number: (info && info.displayNumber) || '',
				direction: (session && session.direction) || ''
			}).done(function(response) {
				if (response && response.success) {
					updateRecordingState({
						isRecording: true,
						recordingPending: false
					});
				} else {
					updateRecordingState({
						isRecording: false,
						recordingPending: false
					});
				}
			}).fail(function() {
				updateRecordingState({
					isRecording: false,
					recordingPending: false
				});
			});
		} catch (e) {}
	};

	DispatcherControl.prototype.ensureDefaultCallRecording = function(session, sessionId) {
		var sid = sessionId || (session && session._customId) || '';
		if (!sid || !this.shouldAutoRecordSession(session, sid)) {
			return;
		}
		var visualState = this.getSessionVisualState(sid);
		if (visualState.isRecording || visualState.recordingPending) {
			return;
		}
		if (session && session._defaultRecordingRequested) {
			return;
		}
		if (this.hasBuiltInRecordingSession(session, sid)) {
			if (session) {
				session._defaultRecordingRequested = true;
			}
			this.setSessionVisualState(sid, {
				isRecording: true,
				recordingPending: false
			});
			this.refreshSessionVisualState(sid);
			return;
		}
		if (session) {
			session._defaultRecordingRequested = true;
		}
		this.requestSessionRecording(sid);
	};

    DispatcherControl.prototype.renderIncomingMetaHtml = function(callerInfo) {
        var lines = [];
        var scene = callerInfo.scene || 'normal_in';
        if (scene === 'blind_transfer_in' || scene === 'attended_transfer_in' || scene === 'consult_leg') {
            if (callerInfo.transferMeta && callerInfo.transferMeta.referredBy) {
                lines.push('<p><strong>转接来源:</strong> ' + this.escapeHtml(callerInfo.transferMeta.referredBy) + '</p>');
            }
            if (callerInfo.transferMeta && callerInfo.transferMeta.referTo) {
                lines.push('<p><strong>转接目标:</strong> ' + this.escapeHtml(callerInfo.transferMeta.referTo) + '</p>');
            }
        }
        if (scene === 'trunk_in_manual' || scene === 'trunk_in_auto') {
            lines.push('<p><strong>中继模式:</strong> ' + this.escapeHtml(scene === 'trunk_in_auto' ? '自动' : '人工') + '</p>');
        }
        if (scene === 'eavesdrop_leg' || scene === 'barge_leg') {
            var modeText = scene === 'barge_leg' ? '强插/插入讲话' : '监听';
            lines.push('<p><strong>监控类型:</strong> ' + modeText + '</p>');
            var targetText = '';
            if (callerInfo.monitorMeta && callerInfo.monitorMeta.targetExtension) {
                targetText = callerInfo.monitorMeta.targetExtension;
            }
            if (!targetText) {
                targetText = callerInfo.displayNumber || '';
            }
            if (targetText) {
                lines.push('<p><strong>监控对象:</strong> ' + this.escapeHtml(targetText) + '</p>');
            }
        }
        return lines.join('');
    };

    DispatcherControl.prototype.getIncomingActionsHtml = function(callerInfo, hasActive) {
        var sid = callerInfo.sessionId || '';
        var scene = callerInfo.scene || 'normal_in';
        var actions = [];
        if (scene === 'trunk_in_manual') {
            actions.push('<button onclick="dispatcherControl.acceptTrunkCall(\'' + sid + '\')" class="btn-accept">应答</button>');
            actions.push('<button onclick="dispatcherControl.rejectTrunkCall(\'' + sid + '\')" class="btn-reject">拒绝</button>');
            return actions.join('');
        }
        if (scene === 'trunk_in_auto') {
            actions.push('<input type="text" id="auto-trunk-target-' + sid + '" class="dispatcher-inline-input" placeholder="输入目标号码" />');
            actions.push('<button onclick="dispatcherControl.processAutoTrunk(\'' + sid + '\')" class="btn-accept">转接</button>');
            actions.push('<button onclick="dispatcherControl.rejectTrunkCall(\'' + sid + '\')" class="btn-reject">拒绝</button>');
            return actions.join('');
        }
        actions.push('<button onclick="dispatcherControl.acceptIncomingById(\'' + sid + '\')" class="btn-accept">接听</button>');
        if (callerInfo.isEmergency) {
            actions.push('<button onclick="dispatcherControl.acknowledgeAndClearAlarm(\'' + sid + '\', false)" class="btn-warning btn-alarm-clear">响应并清警</button>');
            actions.push('<button onclick="dispatcherControl.acknowledgeAndClearAlarm(\'' + sid + '\', true)" class="btn-reject btn-alarm-force-clear">强制清警</button>');
        }
        if (callerInfo.isEmergency && hasActive) {
            actions.push('<button onclick="dispatcherControl.preemptAndAccept(\'' + sid + '\')" class="btn-warning">中断并接听</button>');
        }
        actions.push('<button onclick="dispatcherControl.rejectIncomingById(\'' + sid + '\')" class="btn-reject">拒绝</button>');
        return actions.join('');
    };

	    DispatcherControl.prototype.buildIncomingCardHtml = function(callerInfo) {
	        var info = this.normalizeIncomingCallerInfo(callerInfo);
	        var cfg = this.getIncomingSceneConfig(info.scene);
	        var hasActive = (this.sipClient.getActiveSessions && this.sipClient.getActiveSessions().length) > 0;
	        var metaHtml = this.renderIncomingMetaHtml(info);
	        var statusChipsHtml = this.renderSessionStatusChips(info, {
	            containerClass: 'incoming-status-chips',
	            includePendingRecording: true
	        });
	        return '' +
	            '<div class="incoming-call scene-' + cfg.theme + '" data-scene="' + this.escapeHtml(info.scene) + '" data-scene-priority="' + cfg.priority + '" id="incoming-' + this.escapeHtml(info.sessionId) + '">' +
	            '<div class="incoming-scene-header">' +
	            '<span class="incoming-scene-badge scene-' + cfg.theme + '">' + this.escapeHtml(cfg.shortLabel) + '</span>' +
	            '<h3>' + this.escapeHtml(cfg.title) + '</h3>' +
	            '</div>' +
	            statusChipsHtml +
	            '<p><strong>号码:</strong> ' + this.escapeHtml(info.displayNumber || info.uri || '-') + '</p>' +
	            '<p><strong>名称:</strong> ' + this.escapeHtml(info.displayName || info.name || '-') + '</p>' +
	            metaHtml +
	            '<div class="call-actions">' + this.getIncomingActionsHtml(info, hasActive) + '</div>' +
	            '</div>';
	    };

	    DispatcherControl.prototype.getSessionStatusDescriptors = function(callerInfo, options) {
	        var info = this.normalizeIncomingCallerInfo(callerInfo);
	        var opts = options || {};
	        var descriptors = [];
	        if (info.isRecording) {
	            descriptors.push({ className: 'status-recording', label: '录音中' });
	        } else if (opts.includePendingRecording && info.isEmergency) {
	            descriptors.push({ className: 'status-recording-pending', label: '接通后录音' });
	        }
	        if (info.scene === 'eavesdrop_leg') {
	            descriptors.push({ className: 'status-monitor', label: '监听中' });
	        } else if (info.scene === 'barge_leg') {
	            descriptors.push({ className: 'status-barge', label: '强插中' });
	        }
	        return descriptors;
	    };

	    DispatcherControl.prototype.renderSessionStatusChips = function(callerInfo, options) {
	        var opts = options || {};
	        var descriptors = this.getSessionStatusDescriptors(callerInfo, opts);
	        if (!descriptors.length) {
	            return '';
	        }
	        var containerClass = opts.containerClass || 'session-status-chips';
	        return '<div class="' + this.escapeHtml(containerClass) + '">' + descriptors.map(function(item) {
	            return '<span class="session-status-chip ' + this.escapeHtml(item.className || '') + '">' + this.escapeHtml(item.label || '') + '</span>';
	        }.bind(this)).join('') + '</div>';
	    };

    DispatcherControl.prototype.normalizePromptSpec = function(promptSpec) {
        if (!promptSpec) {
            return null;
        }
        if (Array.isArray(promptSpec)) {
            if (!promptSpec.length) {
                return null;
            }
            return {
                waveform: 'triangle',
                overlayWaveform: 'sine',
                overlayGain: 0.28,
                masterVolume: 0.12,
                attack: 0.01,
                release: 0.12,
                events: promptSpec.map(function(tone, index) {
                    return {
                        tones: [tone],
                        duration: 0.15,
                        gapAfter: index === promptSpec.length - 1 ? 0 : 0.08
                    };
                })
            };
        }
        if (promptSpec.events && promptSpec.events.length) {
            return promptSpec;
        }
        if (promptSpec.tones && promptSpec.tones.length) {
            return $.extend(true, {
                events: [{
                    tones: promptSpec.tones.slice(0),
                    duration: 0.20,
                    gapAfter: 0
                }]
            }, promptSpec);
        }
        return null;
    };

    DispatcherControl.prototype.schedulePromptEvent = function(ctx, start, eventSpec, promptSpec) {
        var tones = eventSpec.tones || promptSpec.tones || [];
        if (!tones || !tones.length) {
            return;
        }

        var attack = Math.max(0.005, typeof eventSpec.attack === 'number' ? eventSpec.attack : (promptSpec.attack || 0.01));
        var release = Math.max(0.04, typeof eventSpec.release === 'number' ? eventSpec.release : (promptSpec.release || 0.10));
        var duration = Math.max(0.08, typeof eventSpec.duration === 'number' ? eventSpec.duration : 0.18);
        var end = start + duration;
        var waveform = eventSpec.waveform || promptSpec.waveform || 'triangle';
        var overlayWaveform = eventSpec.overlayWaveform || promptSpec.overlayWaveform || 'sine';
        var overlayGain = typeof eventSpec.overlayGain === 'number' ? eventSpec.overlayGain : (typeof promptSpec.overlayGain === 'number' ? promptSpec.overlayGain : 0);
        var detuneCents = eventSpec.detuneCents || promptSpec.detuneCents || [];
        var peakGain = typeof eventSpec.volume === 'number' ? eventSpec.volume : (typeof promptSpec.masterVolume === 'number' ? promptSpec.masterVolume : 0.12);
        var toneGain = peakGain / Math.max(tones.length, 1);

        for (var i = 0; i < tones.length; i++) {
            var frequency = parseFloat(tones[i]);
            if (!isFinite(frequency) || frequency <= 0) {
                continue;
            }

            var carrier = ctx.createOscillator();
            var carrierGain = ctx.createGain();
            carrier.type = waveform;
            carrier.frequency.setValueAtTime(frequency, start);
            if (detuneCents.length) {
                carrier.detune.setValueAtTime(detuneCents[i % detuneCents.length], start);
            }
            carrierGain.gain.setValueAtTime(0.0001, start);
            carrierGain.gain.exponentialRampToValueAtTime(toneGain, start + attack);
            carrierGain.gain.exponentialRampToValueAtTime(0.0001, Math.max(start + attack + 0.02, end - release));
            carrier.connect(carrierGain);
            carrierGain.connect(ctx.destination);
            carrier.start(start);
            carrier.stop(end + 0.03);

            if (overlayGain > 0) {
                var overlay = ctx.createOscillator();
                var overlayNode = ctx.createGain();
                overlay.type = overlayWaveform;
                overlay.frequency.setValueAtTime(frequency * 2, start);
                overlayNode.gain.setValueAtTime(0.0001, start);
                overlayNode.gain.exponentialRampToValueAtTime(toneGain * overlayGain, start + attack);
                overlayNode.gain.exponentialRampToValueAtTime(0.0001, Math.max(start + attack + 0.02, end - release));
                overlay.connect(overlayNode);
                overlayNode.connect(ctx.destination);
                overlay.start(start);
                overlay.stop(end + 0.03);
            }
        }
    };

    DispatcherControl.prototype.playPromptBurst = function(promptSpec) {
        var spec = this.normalizePromptSpec(promptSpec);
        if (!spec || !spec.events || !spec.events.length) {
            return;
        }
        try {
            if (this.sipClient && typeof this.sipClient.unlockAudioPlayback === 'function') {
                this.sipClient.unlockAudioPlayback();
            }
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) {
                return;
            }
            if (!this.sipClient._audioCtx) {
                this.sipClient._audioCtx = new AudioContext();
            }
            var ctx = this.sipClient._audioCtx;
            if (ctx.state === 'suspended' && ctx.resume) {
                ctx.resume();
            }
            var cursor = 0;
            var now = ctx.currentTime + 0.02;
            for (var i = 0; i < spec.events.length; i++) {
                var eventSpec = spec.events[i] || {};
                var start = now + cursor;
                this.schedulePromptEvent(ctx, start, eventSpec, spec);
                var duration = Math.max(0.08, typeof eventSpec.duration === 'number' ? eventSpec.duration : 0.18);
                var gapAfter = typeof eventSpec.gapAfter === 'number' ? eventSpec.gapAfter : 0.08;
                cursor += duration + gapAfter;
            }
        } catch (e) {}
    };

    DispatcherControl.prototype.startScenePrompt = function(callerInfo) {
        var info = this.normalizeIncomingCallerInfo(callerInfo);
        var sid = info.sessionId || '';
        if (!sid) {
            return;
        }
        this.stopScenePrompt(sid, true);
        if (info.isEmergency) {
            try {
                if (window.emergencyAudio) {
                    emergencyAudio.startAlert(sid, { uri: info.uri, scene: info.scene });
                }
            } catch (e) {}
            this.scenePromptActive[sid] = { scene: info.scene, emergency: true };
            return;
        }
        var cfg = this.getIncomingSceneConfig(info.scene);
        var promptSpec = cfg.prompt || cfg.tones;
        if (!promptSpec || !cfg.interval) {
            return;
        }
        var self = this;
        this.playPromptBurst(promptSpec);
        this.scenePromptTimers[sid] = window.setInterval(function() {
            self.playPromptBurst(promptSpec);
        }, cfg.interval);
        this.scenePromptActive[sid] = { scene: info.scene, emergency: false };
    };

    DispatcherControl.prototype.stopScenePrompt = function(sessionId, keepState) {
        if (!sessionId) {
            return;
        }
        if (this.scenePromptTimers[sessionId]) {
            window.clearInterval(this.scenePromptTimers[sessionId]);
            delete this.scenePromptTimers[sessionId];
        }
        try {
            if (window.emergencyAudio) {
                emergencyAudio.stopAlert(sessionId);
            }
        } catch (e) {}
        if (!keepState) {
            delete this.scenePromptActive[sessionId];
        }
    };

    DispatcherControl.prototype.rememberEmergencyCall = function(sessionId, callerInfo) {
        if (!sessionId) {
            return {};
        }
        var meta = this.emergencyCallMeta[sessionId] || { sessionId: sessionId };
        if (callerInfo) {
            meta.uri = callerInfo.uri || meta.uri || '';
            meta.name = callerInfo.name || meta.name || '';
            meta.isEmergency = !!callerInfo.isEmergency;
            if (callerInfo.emergencyUuid) {
                meta.emergencyUuid = callerInfo.emergencyUuid;
            }
        }
        meta.callerExtension = this.extractExtensionFromUri(meta.uri || '') || this.extractExtensionFromUri((callerInfo && callerInfo.displayNumber) || meta.displayNumber || '');
        this.emergencyCallMeta[sessionId] = meta;
        return meta;
    };

    DispatcherControl.prototype.getEmergencyCallMeta = function(sessionId) {
        if (!sessionId) {
            return {};
        }
        return this.emergencyCallMeta[sessionId] || {};
    };

    DispatcherControl.prototype.requestEmergencyAlarmTrigger = function(sessionId, callerInfo, options) {
        var requestOptions = options || {};
        if (!sessionId) {
            return $.Deferred().resolve({ success: false, skipped: true }).promise();
        }

        var info = this.normalizeIncomingCallerInfo(callerInfo || {});
        var meta = this.rememberEmergencyCall(sessionId, info);
        meta.isEmergency = true;
        meta.displayNumber = info.displayNumber || meta.displayNumber || '';
        meta.callerExtension = meta.callerExtension || this.extractExtensionFromUri(info.displayNumber || '');
        if (meta.alarmTriggerPending && meta.alarmTriggerPromise) {
            return meta.alarmTriggerPromise;
        }
        if (meta.alarmTriggerCompleted) {
            return $.Deferred().resolve({ success: true, skipped: true, emergency_uuid: meta.emergencyUuid || '' }).promise();
        }

        var self = this;
        meta.alarmTriggerPending = true;
        var request = $.ajax({
            url: 'dispatcher_api.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'trigger_emergency_alarm',
                extension: this.getPresenceExtension(),
                tab_uuid: this.getTabUuid(),
                session_id: sessionId,
                call_uuid: sessionId,
                emergency_uuid: meta.emergencyUuid || '',
                caller_extension: meta.callerExtension || '',
                status: 'ringing'
            }
        }).done(function(response) {
            if (response && response.emergency_uuid && !meta.emergencyUuid) {
                meta.emergencyUuid = response.emergency_uuid;
            }
            meta.alarmTriggerCompleted = !!(response && response.success);
            meta.alarmCleared = false;
            if (!response || !response.success) {
                var message = (response && response.error) || 'Emergency alarm trigger failed';
                if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.toast === 'function') {
                    DispatcherUtils.toast(message, 'warn');
                }
                try { console.warn('Emergency alarm trigger failed:', response); } catch (e) {}
            }
        }).fail(function(xhr) {
            meta.alarmTriggerCompleted = false;
            if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.toast === 'function') {
                DispatcherUtils.toast('Emergency alarm trigger request failed', 'warn');
            }
            try { console.warn('Emergency alarm trigger request failed:', xhr); } catch (e) {}
        }).always(function() {
            meta.alarmTriggerPending = false;
            delete meta.alarmTriggerPromise;
            self.emergencyCallMeta[sessionId] = meta;
        });

        meta.alarmTriggerPromise = request;
        this.emergencyCallMeta[sessionId] = meta;
        return request;
    };

    DispatcherControl.prototype.clearEmergencyAlarmForSession = function(sessionId, options) {
        var requestOptions = options || {};
        var meta = this.getEmergencyCallMeta(sessionId);
        if (!sessionId && !(meta && meta.emergencyUuid)) {
            return $.Deferred().resolve({ success: false, skipped: true }).promise();
        }

        meta = $.extend(true, {
            sessionId: sessionId || '',
            emergencyUuid: '',
            callerExtension: '',
            uri: '',
            isEmergency: true
        }, meta || {});
        if (!meta.callerExtension) {
            meta.callerExtension = this.extractExtensionFromUri(meta.uri || '');
        }
        if (meta.alarmClearPending && meta.alarmClearPromise) {
            return meta.alarmClearPromise;
        }
        if (meta.alarmCleared && !requestOptions.forceClear && !requestOptions.status && !requestOptions.allowRepeat) {
            return $.Deferred().resolve({ success: true, skipped: true, emergency_uuid: meta.emergencyUuid || '' }).promise();
        }

        if (meta.alarmTriggerPending && meta.alarmTriggerPromise && !requestOptions.skipTriggerWait) {
            if (meta.alarmClearAfterTriggerPromise) {
                return meta.alarmClearAfterTriggerPromise;
            }
            var self = this;
            var deferred = $.Deferred();
            meta.alarmClearAfterTriggerPromise = deferred.promise();
            this.emergencyCallMeta[sessionId] = meta;
            meta.alarmTriggerPromise.always(function() {
                var latestMeta = self.emergencyCallMeta[sessionId] || meta;
                delete latestMeta.alarmClearAfterTriggerPromise;
                self.emergencyCallMeta[sessionId] = latestMeta;
                self.clearEmergencyAlarmForSession(sessionId, $.extend({}, requestOptions, {
                    skipTriggerWait: true
                })).done(function(response) {
                    deferred.resolve(response);
                }).fail(function(error) {
                    deferred.reject(error);
                });
            });
            return meta.alarmClearAfterTriggerPromise;
        }

        var self = this;
        meta.alarmClearPending = true;
        var request = $.ajax({
            url: 'dispatcher_api.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'clear_emergency_alarm',
                extension: this.getPresenceExtension(),
                tab_uuid: this.getTabUuid(),
                session_id: sessionId,
                call_uuid: sessionId,
                emergency_uuid: meta.emergencyUuid || '',
                caller_extension: meta.callerExtension || '',
                force_clear: requestOptions.forceClear ? 'true' : 'false',
                acknowledge: requestOptions.acknowledge === false ? 'false' : 'true',
                status: requestOptions.status || '',
                reason: requestOptions.reason || (requestOptions.forceClear ? 'force_clear' : 'ui_clear')
            }
        }).done(function(response) {
            if (response && response.emergency_uuid && !meta.emergencyUuid) {
                meta.emergencyUuid = response.emergency_uuid;
            }
            if (response && response.success) {
                meta.alarmCleared = true;
                try {
                    if (window.emergencyAudio) {
                        emergencyAudio.stopAlert(sessionId);
                    }
                } catch (e) {}
            } else if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.alert === 'function') {
                DispatcherUtils.alert((response && response.error) || 'Alarm clear failed', 'error');
            }
        }).fail(function() {
            if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.alert === 'function') {
                DispatcherUtils.alert('Alarm clear request failed', 'error');
            }
        }).always(function() {
            meta.alarmClearPending = false;
            delete meta.alarmClearPromise;
            if (requestOptions.cleanupMetaOnComplete) {
                delete self.emergencyCallMeta[sessionId];
            } else {
                self.emergencyCallMeta[sessionId] = meta;
            }
        });

        meta.alarmClearPromise = request;
        this.emergencyCallMeta[sessionId] = meta;
        return request;
    };

    DispatcherControl.prototype.acknowledgeAndClearAlarm = function(sessionId, forceClear, options) {
        var requestOptions = options || {};
        var meta = this.getEmergencyCallMeta(sessionId);
        return this.clearEmergencyAlarmForSession(sessionId, {
            forceClear: !!forceClear,
            acknowledge: true,
            reason: forceClear ? 'ui_force_clear' : 'operator_ack',
            silent: true
        }).done(function(response) {
            if (response && response.success) {
                if (response.emergency_uuid && !meta.emergencyUuid) {
                    meta.emergencyUuid = response.emergency_uuid;
                }
                try {
                    if (window.emergencyAudio) {
                        emergencyAudio.stopAlert(sessionId);
                    }
                } catch (e) {}
                var card = $('#incoming-' + sessionId);
                if (card.length) {
                    card.attr('data-alarm-cleared', 'true');
                    card.find('.btn-alarm-clear, .btn-alarm-force-clear').prop('disabled', true);
                }
                if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.toast === 'function') {
                    DispatcherUtils.toast(forceClear ? '已发送强制清警' : '已响应并清警', 'success');
                }
            } else if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.alert === 'function') {
                DispatcherUtils.alert((response && response.error) || '清警失败', 'error');
            }
        }).fail(function() {
            if (!requestOptions.silent && window.DispatcherUtils && typeof DispatcherUtils.alert === 'function') {
                DispatcherUtils.alert('清警请求失败', 'error');
            }
        });
    };

    DispatcherControl.prototype.postDispatcherAction = function(action, payload) {
        return $.ajax({
            url: 'dispatcher_api.php',
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action }, payload || {})
        });
    };

    DispatcherControl.prototype.prepareMonitorAutoAnswer = function(mode) {
        var normalizedMode = String(mode || '').toLowerCase();
        if (normalizedMode === 'three-way') {
            normalizedMode = 'barge';
        }
        try {
            if (normalizedMode === 'listen') {
                window.__autoAnswerListenNext = {
                    mode: 'listen',
                    createdAt: Date.now()
                };
            } else if (normalizedMode === 'barge') {
                window.__autoAnswerBargeNext = true;
            }
            if (this.sipClient && this.sipClient.unlockAudioPlayback) {
                this.sipClient.unlockAudioPlayback();
            }
        } catch (e) {}
    };

    DispatcherControl.prototype.clearMonitorAutoAnswer = function(mode) {
        var normalizedMode = String(mode || '').toLowerCase();
        try {
            if (!normalizedMode || normalizedMode === 'listen') {
                if (window.__autoAnswerListenNext) {
                    delete window.__autoAnswerListenNext;
                }
            }
        } catch (e) {
            window.__autoAnswerListenNext = null;
        }
        if (!normalizedMode || normalizedMode === 'barge' || normalizedMode === 'three-way') {
            try { window.__autoAnswerBargeNext = false; } catch (e) {}
        }
    };

    DispatcherControl.prototype.getIncomingAcceptOptions = function(callerInfo) {
        var info = this.normalizeIncomingCallerInfo(callerInfo);
        return {
            audio: info.scene !== 'eavesdrop_leg',
            video: false
        };
    };

    DispatcherControl.prototype.tryAutoAnswerIncoming = function(callerInfo, onFailure) {
        var info = this.normalizeIncomingCallerInfo(callerInfo);
        var autoMode = '';
        var listenNext = null;

        try {
            listenNext = window.__autoAnswerListenNext || null;
        } catch (e) {}

        if (listenNext && info.scene === 'eavesdrop_leg') {
            if (!listenNext.createdAt || (Date.now() - listenNext.createdAt) < 20000) {
                autoMode = 'listen';
            } else {
                this.clearMonitorAutoAnswer('listen');
            }
        }

        if (!autoMode) {
            try {
                if (window.__autoAnswerBargeNext === true && (info.scene === 'barge_leg' || info.scene === 'conference_invite')) {
                    autoMode = 'barge';
                }
            } catch (e) {}
        }

        if (!autoMode) {
            return false;
        }

        this.clearMonitorAutoAnswer(autoMode);

        var self = this;
        this.sipClient.acceptIncomingCall(this.getIncomingAcceptOptions(info))
            .then(function() {
                self.updateUI();
                if (self.renderLinesGrid) self.renderLinesGrid();
            })
            .catch(function(error) {
                console.error('auto answer incoming failed:', error);
                if (typeof onFailure === 'function') {
                    onFailure(error);
                }
            });
        return true;
    };

    DispatcherControl.prototype.getEntityStateConfig = function(state) {
        var map = {
            idle: { label: '空闲', className: 'entity-state-idle', icon: 'fa-circle' },
            ringing: { label: '振铃', className: 'entity-state-ringing', icon: 'fa-bell' },
            busy: { label: '通话中', className: 'entity-state-busy', icon: 'fa-phone' },
            held: { label: '保持', className: 'entity-state-held', icon: 'fa-pause' },
            offline: { label: '离线', className: 'entity-state-offline', icon: 'fa-plug' },
            disabled: { label: '停用', className: 'entity-state-disabled', icon: 'fa-ban' },
            error: { label: '故障', className: 'entity-state-error', icon: 'fa-exclamation-triangle' },
            unknown: { label: '未知', className: 'entity-state-unknown', icon: 'fa-question-circle' }
        };
        return map[state] || map.unknown;
    };

    DispatcherControl.prototype.getEntityStatusWarningText = function(code) {
        var map = {
            channels_unavailable: 'ESL 通话状态不可用，忙闲态可能延迟',
            registrations_unavailable: '注册状态不可用，在线态可能不准确',
            gateway_runtime_unavailable: '网关运行态不可用，中继状态已降级'
        };
        return map[code] || '状态源不可用';
    };

    DispatcherControl.prototype.getEntityStatusItem = function(entityType, entityId) {
        var snapshot = this.entityStatusSnapshot || {};
        var items = entityType === 'trunk' ? snapshot.trunks : snapshot.users;
        if (!Array.isArray(items)) {
            return null;
        }
        var targetId = String(entityId || '');
        for (var i = 0; i < items.length; i++) {
            if (String(items[i].entity_id || '') === targetId) {
                return items[i];
            }
        }
        return null;
    };

    DispatcherControl.prototype.deferEntityStatusRefresh = function(delay) {
        var self = this;
        window.setTimeout(function() {
            self.refreshEntityStatus(true).catch(function() {});
        }, delay || 800);
    };

    DispatcherControl.prototype.refreshEntityStatus = function(force) {
        var self = this;
        if (this.entityStatusPending) {
            return Promise.resolve(this.entityStatusSnapshot);
        }
        this.entityStatusPending = true;
        this.renderEntityStatusPanel();
        return new Promise(function(resolve, reject) {
            self.postDispatcherAction('get_entity_status_snapshot', {
                extension: self.getPresenceExtension() || ''
            }).done(function(response) {
                if (response && response.success) {
                    self.entityStatusSnapshot = response;
                    self.entityStatusError = '';
                    self.renderEntityStatusPanel();
                    resolve(response);
                    return;
                }
                self.entityStatusError = (response && response.error) || '实体状态刷新失败';
                self.renderEntityStatusPanel();
                reject(new Error(self.entityStatusError));
            }).fail(function(xhr) {
                self.entityStatusError = (xhr && xhr.responseJSON && xhr.responseJSON.error) || '实体状态刷新失败';
                self.renderEntityStatusPanel();
                reject(new Error(self.entityStatusError));
            }).always(function() {
                self.entityStatusPending = false;
                self.renderEntityStatusPanel();
            });
        });
    };

    DispatcherControl.prototype.startEntityStatusPolling = function() {
        var self = this;
        this.stopEntityStatusPolling();
        this.refreshEntityStatus(true).catch(function() {});
        this.entityStatusTimer = window.setInterval(function() {
            self.refreshEntityStatus(false).catch(function() {});
        }, 5000);
    };

    DispatcherControl.prototype.stopEntityStatusPolling = function() {
        if (this.entityStatusTimer) {
            window.clearInterval(this.entityStatusTimer);
            this.entityStatusTimer = null;
        }
    };

    DispatcherControl.prototype.buildEntitySummaryHtml = function(snapshot) {
        var summary = snapshot && snapshot.summary ? snapshot.summary : {};
        var userSummary = summary.users || {};
        var trunkSummary = summary.trunks || {};
        var chips = [];
        chips.push('<span class="dispatcher-entity-summary-chip">用户 ' + (userSummary.total || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip">已注册 ' + (userSummary.registered || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip is-warn">振铃 ' + (userSummary.ringing || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip is-busy">忙线 ' + (userSummary.busy || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip is-muted">离线 ' + (userSummary.offline || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip">中继 ' + (trunkSummary.total || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip is-busy">占用 ' + (trunkSummary.busy || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip is-warn">振铃 ' + (trunkSummary.ringing || 0) + '</span>');
        chips.push('<span class="dispatcher-entity-summary-chip is-error">故障 ' + (trunkSummary.error || 0) + '</span>');
        return chips.join('');
    };

    DispatcherControl.prototype.buildEntityMetaHtml = function(entity) {
        var chips = [];
        if (entity.entity_type === 'user') {
            chips.push('<span class="dispatcher-entity-meta-chip ' + (entity.registered ? 'is-ok' : 'is-muted') + '">' + (entity.registered ? '已注册' : '未注册') + '</span>');
            if (entity.number_alias) {
                chips.push('<span class="dispatcher-entity-meta-chip">别名 ' + this.escapeHtml(entity.number_alias) + '</span>');
            }
            if (entity.user_status) {
                chips.push('<span class="dispatcher-entity-meta-chip">用户态 ' + this.escapeHtml(entity.user_status) + '</span>');
            }
            if (entity.call_group) {
                chips.push('<span class="dispatcher-entity-meta-chip">呼组 ' + this.escapeHtml(entity.call_group) + '</span>');
            }
        } else {
            chips.push('<span class="dispatcher-entity-meta-chip ' + (entity.enabled ? 'is-ok' : 'is-muted') + '">' + (entity.enabled ? '已启用' : '已停用') + '</span>');
            if (entity.runtime_state) {
                chips.push('<span class="dispatcher-entity-meta-chip">运行态 ' + this.escapeHtml(entity.runtime_state) + '</span>');
            }
            if (entity.profile) {
                chips.push('<span class="dispatcher-entity-meta-chip">Profile ' + this.escapeHtml(entity.profile) + '</span>');
            }
            if (entity.proxy) {
                chips.push('<span class="dispatcher-entity-meta-chip">目标 ' + this.escapeHtml(entity.proxy) + '</span>');
            }
        }
        return chips.join('');
    };

    DispatcherControl.prototype.buildEntityCallHtml = function(entity) {
        var call = entity.current_call || null;
        if (!call) {
            return '<div class="dispatcher-entity-call is-empty">当前无活动通话</div>';
        }

        var bits = [];
        var directionLabel = call.direction === 'incoming' ? '呼入' : (call.direction === 'outgoing' ? '呼出' : '通话');
        bits.push('<span class="dispatcher-entity-call-chip direction-chip">' + directionLabel + '</span>');

        if (entity.entity_type === 'user') {
            var peerText = '';
            if (call.remote_name && call.remote_number && call.remote_name !== call.remote_number) {
                peerText = call.remote_name + ' ' + call.remote_number;
            } else {
                peerText = call.remote_name || call.remote_number || '';
            }
            if (peerText) {
                bits.push('<span class="dispatcher-entity-route">对端 ' + this.escapeHtml(peerText) + '</span>');
            }
        } else {
            var routeText = call.route_text || '';
            if (!routeText) {
                routeText = [call.source_number || '', call.target_number || ''].join(' -> ').replace(/^ -> | -> $/g, '');
            }
            if (routeText) {
                bits.push('<span class="dispatcher-entity-route">' + this.escapeHtml(routeText) + '</span>');
            }
        }

        if (call.callstate) {
            bits.push('<span class="dispatcher-entity-call-chip">' + this.escapeHtml(call.callstate) + '</span>');
        }
        if (call.duration_label) {
            bits.push('<span class="dispatcher-entity-call-chip">时长 ' + this.escapeHtml(call.duration_label) + '</span>');
        }
        return '<div class="dispatcher-entity-call">' + bits.join('') + '</div>';
    };

    DispatcherControl.prototype.buildEntityActionsHtml = function(entity) {
        var actions = [];
        if (entity.monitorable) {
            actions.push('<button class="btn btn-sm btn-secondary" onclick="dispatcherControl.monitorEntity(\'' + this.escapeHtml(entity.entity_type) + '\', \'' + this.escapeHtml(entity.entity_id) + '\', \'listen\').catch(function(){})">监听</button>');
            actions.push('<button class="btn btn-sm btn-warning" onclick="dispatcherControl.monitorEntity(\'' + this.escapeHtml(entity.entity_type) + '\', \'' + this.escapeHtml(entity.entity_id) + '\', \'barge\').catch(function(){})">强插</button>');
        }
        if (entity.force_releasable || (entity.entity_type === 'user' && entity.active_call_count > 0)) {
            actions.push('<button class="btn btn-sm btn-danger" onclick="dispatcherControl.forceReleaseEntity(\'' + this.escapeHtml(entity.entity_type) + '\', \'' + this.escapeHtml(entity.entity_id) + '\').catch(function(){})">强拆</button>');
        }
        return actions.join('');
    };

    DispatcherControl.prototype.buildEntityCardHtml = function(entity) {
        var stateCfg = this.getEntityStateConfig(entity.entity_state);
        var typeLabel = entity.entity_type === 'trunk' ? '中继' : '用户';
        var titleNumber = entity.entity_type === 'trunk' ? (entity.display_name || entity.gateway || '-') : (entity.extension || entity.display_number || '-');
        var nameText = entity.entity_type === 'trunk'
            ? (entity.display_number || entity.runtime_target || entity.display_name || '-')
            : (entity.display_name || entity.description || entity.display_number || '-');
        var actionsHtml = this.buildEntityActionsHtml(entity);

        return '' +
            '<div class="dispatcher-entity-card state-' + this.escapeHtml(entity.entity_state || 'unknown') + '">' +
                '<div class="dispatcher-entity-card-head">' +
                    '<div class="dispatcher-entity-title">' +
                        '<span class="dispatcher-entity-type-chip">' + typeLabel + '</span>' +
                        '<span class="dispatcher-entity-id">' + this.escapeHtml(titleNumber) + '</span>' +
                    '</div>' +
                    '<span class="dispatcher-entity-state-badge ' + stateCfg.className + '">' +
                        '<i class="fas ' + stateCfg.icon + '"></i>' +
                        this.escapeHtml(stateCfg.label) +
                    '</span>' +
                '</div>' +
                '<div class="dispatcher-entity-name">' + this.escapeHtml(nameText) + '</div>' +
                '<div class="dispatcher-entity-meta">' + this.buildEntityMetaHtml(entity) + '</div>' +
                this.buildEntityCallHtml(entity) +
                (actionsHtml ? '<div class="dispatcher-entity-actions">' + actionsHtml + '</div>' : '') +
            '</div>';
    };

    DispatcherControl.prototype.renderEntityStatusCards = function(items, entityType) {
        if (!Array.isArray(items) || !items.length) {
            return '<div class="dispatcher-entity-empty">' + (entityType === 'trunk' ? '暂无中继配置' : '暂无分机数据') + '</div>';
        }
        return items.map(function(entity) {
            return this.buildEntityCardHtml(entity);
        }.bind(this)).join('');
    };

    DispatcherControl.prototype.renderEntityStatusPanel = function() {
        var panel = $('#dispatcher-entity-status-panel');
        if (!panel.length) {
            return;
        }

        var snapshot = this.entityStatusSnapshot || {};
        var users = Array.isArray(snapshot.users) ? snapshot.users : [];
        var trunks = Array.isArray(snapshot.trunks) ? snapshot.trunks : [];
        var warningHtml = '';
        var warnings = Array.isArray(snapshot.warnings) ? snapshot.warnings : [];

        if (this.entityStatusError) {
            warningHtml += '<div class="dispatcher-entity-warning is-error">' + this.escapeHtml(this.entityStatusError) + '</div>';
        }
        warningHtml += warnings.map(function(item) {
            return '<div class="dispatcher-entity-warning">' + this.escapeHtml(this.getEntityStatusWarningText(item && item.code)) + '</div>';
        }.bind(this)).join('');

        $('#dispatcher-entity-status-summary').html(this.buildEntitySummaryHtml(snapshot));
        $('#dispatcher-user-status-grid').html(this.renderEntityStatusCards(users, 'user'));
        $('#dispatcher-trunk-status-grid').html(this.renderEntityStatusCards(trunks, 'trunk'));
        $('#dispatcher-entity-status-warnings').html(warningHtml);

        var updatedText = snapshot.generated_at ? String(snapshot.generated_at).replace(/^\d{4}-\d{2}-\d{2}\s*/, '') : '';
        if (this.entityStatusPending) {
            updatedText = '刷新中...';
        } else if (updatedText) {
            updatedText = '更新时间 ' + updatedText;
        } else {
            updatedText = '等待首帧状态';
        }
        $('#dispatcher-entity-status-updated').text(updatedText).toggleClass('is-error', !!this.entityStatusError);
        panel.toggleClass('is-loading', !!this.entityStatusPending);
    };

	    DispatcherControl.prototype.monitorEntity = function(entityType, entityId, mode) {
	        var self = this;
	        var entity = this.getEntityStatusItem(entityType, entityId);
	        var currentCall = entity && entity.current_call ? entity.current_call : null;
	        var targetMode = String(mode || 'listen').toLowerCase();
        if (targetMode !== 'listen' && targetMode !== 'barge') {
            return Promise.reject(new Error('无效的监控模式'));
        }
        if (!entity || !currentCall) {
            DispatcherUtils.alert('未找到可监控的活动对象', 'warn');
            return Promise.reject(new Error('entity not found'));
        }
	        if (entity.entity_type === 'user' && entity.extension && entity.extension === this.getPresenceExtension()) {
	            DispatcherUtils.alert('不能对当前调度分机自身发起监控', 'warn');
	            return Promise.reject(new Error('self monitor not allowed'));
	        }
	        this.prepareMonitorAutoAnswer(targetMode);

	        var preflight = Promise.resolve();
	        if (this.sipClient && this.sipClient.hangupByType) {
	            preflight = this.sipClient.hangupByType(targetMode === 'barge' ? 'eavesdrop' : 'three-way').catch(function() {});
	        }

	        return preflight.then(function() {
	            return self.requestMonitorCall({
	                mode: targetMode,
                targetExtension: entity.extension || '',
                targetChannelUuid: currentCall.channel_uuid || '',
                bridgeUuid: currentCall.bridge_uuid || ''
            });
        }).then(function(response) {
            self.showToast(targetMode === 'barge' ? '已发起强插请求' : '已发起监听请求', 'success');
            self.deferEntityStatusRefresh(800);
	            self.deferEntityStatusRefresh(2200);
	            return response;
	        }).catch(function(error) {
	            self.clearMonitorAutoAnswer(targetMode);
	            DispatcherUtils.alert((error && error.message) || '监控请求失败', 'error');
	            throw error;
	        });
	    };

    DispatcherControl.prototype.forceReleaseEntity = function(entityType, entityId) {
        var self = this;
        var entity = this.getEntityStatusItem(entityType, entityId);
        var currentCall = entity && entity.current_call ? entity.current_call : null;
        if (!entity || !currentCall) {
            DispatcherUtils.alert('未找到可强拆的活动对象', 'warn');
            return Promise.reject(new Error('entity not found'));
        }
        return this.forceReleaseCall({
            channelUuids: [currentCall.channel_uuid || '', currentCall.bridge_uuid || ''].filter(Boolean),
            targetExtension: entity.extension || ''
        }).then(function(response) {
            self.showToast('已发送强拆请求', 'success');
            self.deferEntityStatusRefresh(600);
            self.deferEntityStatusRefresh(1800);
            return response;
        }).catch(function(error) {
            DispatcherUtils.alert((error && error.message) || '强拆失败', 'error');
            throw error;
        });
    };

    DispatcherControl.prototype.isMonitorScene = function(scene) {
        return scene === 'eavesdrop_leg' || scene === 'barge_leg';
    };

    DispatcherControl.prototype.requestMonitorCall = function(options) {
        var self = this;
        var opts = options || {};
        var mode = String(opts.mode || 'listen').toLowerCase();
        if (mode === 'three-way') {
            mode = 'barge';
        }
        return new Promise(function(resolve, reject) {
            self.postDispatcherAction('monitor_call', {
                mode: mode,
                operator_extension: opts.operatorExtension || self.getPresenceExtension() || '',
                target_extension: opts.targetExtension || '',
                target_channel_uuid: opts.targetChannelUuid || '',
                bridge_channel_uuid: opts.bridgeUuid || ''
            }).done(function(response) {
                if (response && response.success) {
                    self.deferEntityStatusRefresh(800);
                    self.deferEntityStatusRefresh(2200);
                    resolve(response);
                    return;
                }
                reject(new Error((response && response.error) || '监控请求失败'));
            }).fail(function(xhr) {
                reject(new Error((xhr && xhr.responseJSON && xhr.responseJSON.error) || '监控请求失败'));
            });
        });
    };

	    DispatcherControl.prototype.forceReleaseCall = function(options) {
	        var self = this;
	        var opts = options || {};
	        return new Promise(function(resolve, reject) {
	            self.postDispatcherAction('force_release_call', {
	                channel_uuids: opts.channelUuids || [],
	                target_extension: opts.targetExtension || '',
	                conference_name: opts.conferenceName || ''
	            }).done(function(response) {
	                if (response && response.success) {
	                    self.deferEntityStatusRefresh(600);
	                    self.deferEntityStatusRefresh(1800);
	                    resolve(response);
                    return;
                }
                reject(new Error((response && response.error) || '强拆失败'));
            }).fail(function(xhr) {
                reject(new Error((xhr && xhr.responseJSON && xhr.responseJSON.error) || '强拆失败'));
            });
        });
    };

    DispatcherControl.prototype.switchMonitorMode = function(sessionId, mode) {
        var self = this;
        var info = this.getCallerInfoBySessionId(sessionId);
        var targetMode = String(mode || '').toLowerCase();
        if (targetMode === 'three-way') {
            targetMode = 'barge';
        }
        if (targetMode !== 'listen' && targetMode !== 'barge') {
            return Promise.reject(new Error('无效的监控模式'));
        }
        var currentMode = (info.monitorMeta && info.monitorMeta.mode) || (info.scene === 'barge_leg' ? 'barge' : 'listen');
        if (currentMode === targetMode) {
            this.showToast(targetMode === 'barge' ? '当前已处于强插模式' : '当前已处于监听模式', 'warn');
            return Promise.resolve();
        }
        var targetExtension = (info.monitorMeta && info.monitorMeta.targetExtension) || info.displayNumber || this.extractExtensionFromUri(info.uri || '');
        var targetChannelUuid = (info.monitorMeta && info.monitorMeta.targetUuid) || '';
        var bridgeUuid = (info.monitorMeta && info.monitorMeta.bridgeUuid) || '';
	        if (!targetExtension && !targetChannelUuid) {
	            var err = new Error('缺少监控目标');
	            DispatcherUtils.alert(err.message, 'error');
	            return Promise.reject(err);
	        }
	        this.prepareMonitorAutoAnswer(targetMode);
	        var request = function() {
	            return self.requestMonitorCall({
	                mode: targetMode,
	                targetExtension: targetExtension,
                targetChannelUuid: targetChannelUuid,
                bridgeUuid: bridgeUuid
	            }).then(function(response) {
	                self.showToast(targetMode === 'barge' ? '已发起强插请求' : '已发起监听请求', 'success');
	                return response;
	            }).catch(function(error) {
	                self.clearMonitorAutoAnswer(targetMode);
	                DispatcherUtils.alert((error && error.message) || '监控请求失败', 'error');
	                throw error;
	            });
	        };
        if (sessionId && this.sipClient && this.sipClient.hangupById) {
            return this.sipClient.hangupById(sessionId).catch(function() {}).then(request);
        }
        return request();
    };

    DispatcherControl.prototype.forceReleaseMonitorTarget = function(sessionId) {
        var self = this;
        var info = this.getCallerInfoBySessionId(sessionId);
        var monitorMeta = info.monitorMeta || {};
        var channelUuids = [];
        if (monitorMeta.targetUuid) {
            channelUuids.push(monitorMeta.targetUuid);
        }
	        if (monitorMeta.bridgeUuid) {
	            channelUuids.push(monitorMeta.bridgeUuid);
	        }
	        return this.forceReleaseCall({
	            channelUuids: channelUuids,
	            targetExtension: monitorMeta.targetExtension || info.displayNumber || '',
	            conferenceName: monitorMeta.conference || ''
	        }).then(function(response) {
	            self.showToast('已发送强拆请求', 'success');
	            return response;
	        }).catch(function(error) {
	            DispatcherUtils.alert((error && error.message) || '强拆失败', 'error');
	            throw error;
	        });
	    };

    DispatcherControl.prototype.register = function(config) {
        var self = this;
        
        if (config) {
            this.config = $.extend(this.config, config);

            // 保存SIP配置供后续使用
            this.sipConfig = {
                uri: config.uri,
                wsServers: config.wsServers,
                authUser: config.authUser,
                password: config.password,
                displayName: config.displayName
            };
            
            // 保存到localStorage
            localStorage.setItem('sip_config', JSON.stringify(this.sipConfig));
            
            this.saveConfig();
        }

        // 触发连接中事件
        this.emit('connecting');

        return this.sipClient.register(this.config);
    };

    // 注销 SIP
    DispatcherControl.prototype.unregister = function() {
        var self = this;
        return this.sipClient.unregister().then(function() {
            self.emit('unregistered');
        });
    };

    // 检查是否已注册
    DispatcherControl.prototype.isRegistered = function() {
        return this.sipClient && this.sipClient.ua && this.sipClient.ua.isRegistered();
    };

    // 事件回调
    DispatcherControl.prototype.onRegistered = function() {
        console.log('调度员已注册');
        this.updateUI();
        this.refreshEntityStatus(true).catch(function() {});
        // 触发注册成功事件
        this.startPresenceHeartbeat();
        this.emit('registered');
    };

    DispatcherControl.prototype.onUnregistered = function() {
        console.log('调度员已注销');
        this.stopPresenceHeartbeat();
        this.refreshEntityStatus(true).catch(function() {});
        this.emit('unregistered');
    };

    DispatcherControl.prototype.onRegistrationFailed = function(error) {
        console.error('调度员注册失败', error);
        this.stopPresenceHeartbeat();
        this.refreshEntityStatus(true).catch(function() {});
        this.emit('registrationFailed', error);
    };

    DispatcherControl.prototype.onIncomingCall = function(callerInfo) {
        callerInfo = this.normalizeIncomingCallerInfo(callerInfo);
        console.log('收到来电:', callerInfo);
        
        // === 新增：检查是否是会议自动接听（优先级最高） ===
        // Legacy fallback is intentionally disabled; unified auto-answer runs in tryAutoAnswerIncoming().
        if (false && window.__autoAnswerBargeNext === true) {
            console.log('🎯 检测到会议自动接听标志，立即接听');
            window.__autoAnswerBargeNext = false;
            
            var self = this;
            // 短延迟确保媒体流准备就绪
            setTimeout(function() {
                self.sipClient.acceptIncomingCall({ audio: true, video: false })
                    .then(function() {
                        console.log('✅ 会议来电已自动接听');
                        self.updateUI();
                        if (self.renderLinesGrid) self.renderLinesGrid();
                    })
                    .catch(function(error) {
                        console.error('❌ 会议自动接听失败:', error);
                        // 失败后按普通来电处理
                        self.queueIncomingCall(callerInfo);
                        self.updateUI();
                        self.updateBusyQueueBanner(false);
                    });
            }, 300);
            return; // 直接返回，不执行后续逻辑
        }
        
        // 检测是否为急呼
        var isEmergency = false;
        if (callerInfo && callerInfo.isEmergency) { isEmergency = true; }
        else {
            try {
                var s = this.sipClient && this.sipClient.incomingSession;
                if (s && s._callType === 'emergency') { isEmergency = true; }
            } catch(e) {}
        }
        
        if (isEmergency) {
            try {
                var sid = (callerInfo && callerInfo.sessionId) || (this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId) || ('ea_'+Date.now())
                if (window.emergencyAudio) emergencyAudio.startAlert(sid, { uri: callerInfo && callerInfo.uri })
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager && this.config && this.config.authUser){ DispatcherUtils.ResourceManager.setEmergencyOwner(sid, this.config.authUser) } }catch(e){}
            } catch(e){}
            if (this.autoAnswerEmergency) {
                var self = this;
                setTimeout(function() { self.sipClient.acceptIncomingCall({ audio: true, video: false }); }, 1000);
            }
        }
        
        // 检查是否是中继呼叫（根据号码前缀判断，可配置）
        // 统一使用队列展示，不覆盖已有提示
        this.startScenePrompt(callerInfo);
        this.queueIncomingCall(callerInfo);
        this.updateUI();
        this.updateBusyQueueBanner(false);
    };

    DispatcherControl.prototype.onCallEstablished = function(data) {
        console.log('通话已建立:', data);
        this.updateUI();
        this.updateBusyQueueBanner(false);
    };

    DispatcherControl.prototype.onCallEnded = function(data) {
        console.log('通话已结束:', data);
        
        var callType = 'normal';
        var callData = {
            duration: 0,
            participants: [],
            status: 'completed'
        };
        
        // 判断通话类型并记录
        var wasTrunk = this.trunkCalls.some(function(call) {
            return call.session === data.session;
        });
        
        if (wasTrunk) {
            callType = 'trunk';
        } else if (this.groupCallActive) {
            callType = 'group';
        } else if (this.conferenceActive) {
            callType = 'conference';
        }
        
        // 记录日志
        if (typeof logCallEnd === 'function') {
            logCallEnd(callType, callData);
        }
        
        // 检查并隐藏直接呼叫状态栏 - 修复：确保正确清理所有直接呼叫状态
        if (data.sessionId) {
            var directCallStatus = $('#direct-call-status-' + data.sessionId);
            if (directCallStatus.length > 0) {
                directCallStatus.remove();
                console.log('已清除直接呼叫状态栏:', data.sessionId);
            }
        } else {
            // 如果没有sessionId，尝试查找所有直接呼叫状态栏并清理
            $('.call-status-bar').each(function() {
                var statusId = $(this).attr('id');
                if (statusId && statusId.startsWith('direct-call-status-')) {
                    $(this).remove();
                    console.log('已清除直接呼叫状态栏:', statusId);
                }
            });
        }
        
        // 从中继列表移除
        this.trunkCalls = this.trunkCalls.filter(function(call) {
            return call.session !== data.session;
        });
        
        // 移除结束的组呼会话
        if (this.groupCallSessions) {
            this.groupCallSessions = this.groupCallSessions.filter(function(s) {
                return s.session !== data.session;
            });
            
            // 检查是否所有组呼会话都已结束
            if (this.groupCallActive && this.groupCallSessions.length === 0) {
                console.log('所有组呼会话已结束');
                this.groupCallActive = false;
                $('#group-call-status').hide();
                // 清除计时器
                if (this.timers.groupCall) {
                    clearInterval(this.timers.groupCall);
                    this.timers.groupCall = null;
                }
            }
        }
        
        // 移除会议参与者
        if (this.conferenceParticipants) {
            var index = this.conferenceParticipants.findIndex(function(p) {
                return p.session === data.session;
            });
            
            if (index !== -1) {
                this.conferenceParticipants.splice(index, 1);
                console.log('会议参与者已退出，剩余:', this.conferenceParticipants.length);
                
                // 更新会议状态显示
                if (this.conferenceActive) {
                    $('#conference-status-text').text('会议进行中 - ' + this.conferenceParticipants.length + '人参与');
                    
                    // 如果所有参与者都退出了，结束会议
                    if (this.conferenceParticipants.length === 0) {
                        console.log('所有会议参与者已退出');
                        this.conferenceActive = false;
                        $('#conference-status').hide();
                        // 清除计时器
                        if (this.timers.conference) {
                            clearInterval(this.timers.conference);
                            this.timers.conference = null;
                        }
                    }
                }
            }
        }
        
        // 更新中继计数
        $('#dispatcher-trunk-count').text(this.trunkCalls.length);
        
        this.updateUI();
        this.updateBusyQueueBanner(false);
    };

    DispatcherControl.prototype.onCallFailed = function(data) {
        if (data && data.uiFailure && data.uiFailure.benign) {
            console.warn('来电已结束:', data);
        } else {
            console.error('通话失败:', data);
        }
        this.updateUI();
    };

    DispatcherControl.prototype.onCallProgress = function(data) {
        console.log('通话进度:', data);
        // 可以根据需要处理通话进度事件
    };

    DispatcherControl.prototype.updateBusyQueueBanner = function(forceShow) {
        try {
            var sessions = this.sipClient.getActiveSessions();
            var activeCount = sessions.length;
            var incomingCount = sessions.filter(function(x){
                var s = x.session; return s && s.direction==='incoming' && !(s.isEstablished && s.isEstablished());
            }).length;
            var busy = activeCount > 0;
            var hasQueue = incomingCount >= 1 && activeCount >= 1;
            var el = $('#dispatcher-busy-queue');
            if (!el.length) return;
            var shouldShow = forceShow || (busy && hasQueue);
            if (shouldShow) { el.stop(true, true).fadeIn(300); }
            else { el.stop(true, true).fadeOut(300); }
        } catch(e) {}
    };

    DispatcherControl.prototype.renderLinesGrid = function() {
        var sessions = this.sipClient.getActiveSessions();
        var list = $('#dispatcher-lines-grid');
        if (!list.length) return;
        var items = sessions.slice(0, 6).map(function(entry) {
            var session = entry.session;
            var sessionId = entry.sessionId;
            var info = this.getSessionUiInfo(session, sessionId);
            var cfg = this.getIncomingSceneConfig(info.scene);
            var dir = session && session.direction ? session.direction : (info.direction || '');
            var connected = !!(session && session.isEstablished && session.isEstablished());
            var held = !!this.holdRemaining[sessionId];
            var holdText = held ? ('剩余 ' + this.holdRemaining[sessionId] + 's') : '';
            var cls = connected ? 'status-ok' : 'status-warn';
            var icon = connected ? 'fa-phone' : 'fa-bell';
            var stateText = '振铃中';
            var monitorMeta = info.monitorMeta || {};
            var actionButtons = [];

            if (this.transferring && this.transferring[sessionId]) {
                cls = 'status-warn';
                icon = 'fa-exchange-alt';
                stateText = '转接中';
            } else if (held) {
                cls = 'status-held';
                icon = 'fa-pause';
                stateText = '保持';
            } else if (cfg.theme === 'emergency') {
                cls = 'status-error';
                stateText = connected ? '紧急通话' : '紧急呼入';
            } else if (connected || info.sessionState === 'connected') {
                stateText = '已接通';
            } else if (info.sessionState === 'accepted') {
                stateText = '已应答';
            } else if (dir === 'outgoing') {
                stateText = '呼出中';
            }

            if (dir === 'incoming' && !connected) {
                actionButtons.push('<button class="btn btn-sm btn-success" onclick="dispatcherControl.acceptIncomingById(\'' + sessionId + '\')">接听</button>');
            }

            if (this.isMonitorScene(info.scene)) {
                if (connected && info.scene !== 'eavesdrop_leg') {
                    actionButtons.push('<button class="btn btn-sm btn-secondary" onclick="dispatcherControl.switchMonitorMode(\'' + sessionId + '\', \'listen\')">监听</button>');
                }
                if (connected && info.scene !== 'barge_leg') {
                    actionButtons.push('<button class="btn btn-sm btn-warning" onclick="dispatcherControl.switchMonitorMode(\'' + sessionId + '\', \'barge\')">强插</button>');
                }
                if (connected && (monitorMeta.targetUuid || monitorMeta.bridgeUuid || monitorMeta.targetExtension || info.displayNumber)) {
                    actionButtons.push('<button class="btn btn-sm btn-danger" onclick="dispatcherControl.forceReleaseMonitorTarget(\'' + sessionId + '\')">强拆</button>');
                }
            } else {
                if (connected || held) {
                    actionButtons.push(held
                        ? '<button class="btn btn-sm btn-primary" onclick="dispatcherControl.resumeHeldCall(\'' + sessionId + '\')">恢复</button>'
                        : '<button class="btn btn-sm btn-warning" onclick="dispatcherControl.holdLine(\'' + sessionId + '\')">保持</button>');
                }
                if (connected) {
                    actionButtons.push('<button class="btn btn-sm btn-secondary" onclick="dispatcherControl.transferLine(\'' + sessionId + '\')">转接</button>');
                }
            }

            actionButtons.push('<button class="btn btn-sm btn-danger" onclick="dispatcherControl.hangupLine(\'' + sessionId + '\')">挂断</button>');

	            var entityLabel = cfg.entityLabel || (info.entityType === 'trunk' ? '中继' : (info.entityType === 'dispatcher' ? '调度' : '用户'));
	            var displayName = this.escapeHtml(info.displayName || info.uri || '-');
	            var displayNumber = info.displayNumber ? '<span class="line-number">' + this.escapeHtml(info.displayNumber) + '</span>' : '';
	            var statusChipsHtml = this.renderSessionStatusChips(info, {
	                containerClass: 'line-status-chips'
	            });

	            return '' +
	                '<div class="line-card ' + cls + '" onclick="dispatcherControl.selectLine(\'' + sessionId + '\')">' +
                '<div class="line-status">' +
                '<i class="fas ' + icon + '"></i>' +
                '<span class="line-chip scene-' + cfg.theme + '">' + this.escapeHtml(cfg.shortLabel) + '</span>' +
                '<span class="line-chip line-entity-chip">' + this.escapeHtml(entityLabel) + '</span>' +
	                '<span class="line-direction">' + (dir === 'incoming' ? '呼入' : '呼出') + '</span>' +
	                '</div>' +
	                '<div class="line-peer">' + displayName + displayNumber + '</div>' +
	                '<div class="line-state">' + this.escapeHtml(stateText) + ' <span id="hold-remaining-' + sessionId + '">' + this.escapeHtml(holdText) + '</span></div>' +
	                statusChipsHtml +
	                '<div style="display:flex;gap:6px;flex-wrap:wrap;">' + actionButtons.join('') + '</div>' +
	                '</div>';
	        }.bind(this));
        list.html(items.join(''));
        this.updateBusyQueueBanner(false);
    };

    DispatcherControl.prototype.selectLine = function(sessionId) {
        try {
            var s = this.sipClient.sessions[sessionId];
            if (s) this.sipClient.currentSession = s;
            this.updateBusyQueueBanner(false);
        } catch(e) {}
    };

    DispatcherControl.prototype.holdLine = function(sessionId) {
        var self = this;
        this.sipClient.hold(sessionId).then(function(){
            self.startHoldTimer(sessionId, 180);
            if (self.renderLinesGrid) self.renderLinesGrid();
        }).catch(function(err){ console.error(err); });
    };

    DispatcherControl.prototype.resumeHeldCall = function(sessionId) {
        var self = this;
        this.sipClient.unhold(sessionId).then(function(){
            if (self.holdTimers[sessionId]) { clearInterval(self.holdTimers[sessionId]); delete self.holdTimers[sessionId]; }
            delete self.holdRemaining[sessionId];
            if (self.renderLinesGrid) self.renderLinesGrid();
        }).catch(function(err){ console.error(err); });
    };

    DispatcherControl.prototype.startHoldTimer = function(sessionId, seconds) {
        var self = this;
        var secs = seconds || 180;
        this.holdRemaining[sessionId] = secs;
        if (this.holdTimers[sessionId]) clearInterval(this.holdTimers[sessionId]);
        this.holdTimers[sessionId] = setInterval(function(){
            if (!self.holdRemaining[sessionId]) { clearInterval(self.holdTimers[sessionId]); return; }
            self.holdRemaining[sessionId] = Math.max(0, self.holdRemaining[sessionId]-1);
            var el = document.getElementById('hold-remaining-'+sessionId);
            if (el) el.textContent = '剩余 '+self.holdRemaining[sessionId]+'s';
            if (self.holdRemaining[sessionId] === 0) {
                clearInterval(self.holdTimers[sessionId]);
                delete self.holdTimers[sessionId];
                delete self.holdRemaining[sessionId];
                self.sipClient.hangup(sessionId).then(function(){
                    var toast = $('#dispatcher-lines-toast');
                    if (toast.length) { toast.text('保持超时，已挂断').show(); setTimeout(function(){ toast.hide(); }, 2000); }
                    if (self.renderLinesGrid) self.renderLinesGrid();
                });
            }
        }, 1000);
    };

    DispatcherControl.prototype.transferLine = function(sessionId) {
        var ext = prompt('输入目标分机');
        if (!ext) return;
        var target = 'sip:'+ext+'@'+this.getServerHost();
        var self = this;
        this.transferring[sessionId] = { startedAt: Date.now() };
        if (this.renderLinesGrid) this.renderLinesGrid();
        // 未确认提示与清理定时器
        this.transferring[sessionId].warnTimer = setTimeout(function(){
            if (self.transferring[sessionId]) {
                self.showToast('已发送转接请求，等待确认 (Transfer requested)', 'warn');
            }
        }, 1000);
        this.transferring[sessionId].cleanupTimer = setTimeout(function(){
            if (self.transferring[sessionId]) {
                self.showToast('转接状态未确认，请检查目标分机 (Unconfirmed)', 'warn');
                delete self.transferring[sessionId];
                if (self.renderLinesGrid) self.renderLinesGrid();
            }
        }, 30000);

        this.sipClient.transfer(target, sessionId)
            .then(function(){
                try { self.showToast('已发送转接请求 (Transfer requested)', 'success'); } catch(e) {}
            })
            .catch(function(e){
                try { self.showToast('转接失败 (Transfer failed): '+(e.message||e), 'error'); } catch(_) {}
                if (self.transferring[sessionId]) {
                    clearTimeout(self.transferring[sessionId].warnTimer);
                    clearTimeout(self.transferring[sessionId].cleanupTimer);
                    delete self.transferring[sessionId];
                }
                if (self.renderLinesGrid) self.renderLinesGrid();
            });
    };

    DispatcherControl.prototype.hangupLine = function(sessionId) {
        var self = this;
        var s = this.sipClient.sessions[sessionId];
        var remoteUri = '';
        var dir = '';
        try { remoteUri = (s && s.remote_identity && s.remote_identity.uri && s.remote_identity.uri.toString()) || ''; } catch(e) {}
        try { dir = (s && s.direction) || ''; } catch(e) {}
        this.sipClient.hangup(sessionId)
            .then(function(){
                // 后端强制挂断兜底（防止会话残留）
                if (remoteUri) {
                    $.ajax({ url: 'dispatcher_api.php', type: 'POST', dataType: 'json', data: { action: 'force_hangup', uri: remoteUri, direction: dir } });
                }
                if (self.renderLinesGrid) self.renderLinesGrid();
            })
            .catch(function(){
                if (remoteUri) {
                    $.ajax({ url: 'dispatcher_api.php', type: 'POST', dataType: 'json', data: { action: 'force_hangup', uri: remoteUri, direction: dir } })
                        .always(function(){ if (self.renderLinesGrid) self.renderLinesGrid(); });
                }
            });
    };

    DispatcherControl.prototype.showToast = function(text, type) {
        try { if (window.DispatcherUtils && DispatcherUtils.toast) { DispatcherUtils.toast(text, type) } } catch(e) {}
    };

    DispatcherControl.prototype.hangupAll = function() {
        var self = this;
        this.sipClient.hangupAll().then(function(){ self.updateUI(); if (self.renderLinesGrid) self.renderLinesGrid(); }).catch(function(){});
    };

    // ============ 中继汇接功能 ============

    // 判断是否是中继号码
    DispatcherControl.prototype.isTrunkNumber = function(uri) {
        // 可根据实际情况配置中继号码前缀或范围
        // 例如：0开头的号码视为中继号码
        var number = uri.match(/sip:(\d+)@/);
        if (number && number[1]) {
            return number[1].startsWith('0') || number[1].length > 6;
        }
        return false;
    };

    // 处理中继来电
    DispatcherControl.prototype.handleTrunkIncomingCall = function(callerInfo) {
        var self = this;
        
        if (this.autoTrunkMode) {
            // 自动中继模式：自动提示输入目标号码
            this.showAutoTrunkPrompt(callerInfo);
        } else {
            // 人工中继模式：显示来电，等待调度员操作
            this.showManualTrunkPrompt(callerInfo);
        }
    };

    // 显示人工中继提示
    DispatcherControl.prototype.showManualTrunkPrompt = function(callerInfo) {
        var self = this;
        
        var html = '<div class="trunk-incoming-call">' +
            '<h3>🔔 中继来电</h3>' +
            '<p><strong>来电号码:</strong> ' + callerInfo.uri + '</p>' +
            '<p><strong>来电者:</strong> ' + callerInfo.name + '</p>' +
            '<div class="trunk-actions">' +
            '<button onclick="dispatcherControl.acceptTrunkCall()" class="btn-accept">应答</button>' +
            '<button onclick="dispatcherControl.rejectTrunkCall()" class="btn-reject">拒绝</button>' +
            '</div>' +
            '</div>';

        var container = $('#dispatcher-alerts');
        if (container.length) { container.append(html).show(); }
    };

    // 显示自动中继提示
    DispatcherControl.prototype.showAutoTrunkPrompt = function(callerInfo) {
        var self = this;
        
        var html = '<div class="trunk-incoming-call">' +
            '<h3>📞 自动中继呼叫</h3>' +
            '<p><strong>来电号码:</strong> ' + callerInfo.uri + '</p>' +
            '<p>请输入被叫号码：</p>' +
            '<input type="text" id="auto-trunk-target" placeholder="输入目标号码" />' +
            '<div class="trunk-actions">' +
            '<button onclick="dispatcherControl.processAutoTrunk()" class="btn-accept">转接</button>' +
            '<button onclick="dispatcherControl.rejectTrunkCall()" class="btn-reject">拒绝</button>' +
            '</div>' +
            '</div>';

        var container2 = $('#dispatcher-alerts');
        if (container2.length) { container2.append(html).show(); }
    };

    // 接受中继呼叫（人工）
    DispatcherControl.prototype.acceptTrunkCall = function(sessionId) {
        var self = this;
        var sid = sessionId || (this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId) || '';
        if (!sid) {
            DispatcherUtils.alert('未找到中继来电', 'warn');
            return;
        }
        var trunkInfo = this.getCallerInfoBySessionId(sid);
        this.sipClient.acceptById(sid, { audio: true, video: false })
            .then(function(result) {
                self.stopScenePrompt(sid);
                self.cleanupIncomingUI(sid);
                self.trunkCalls.push({
                    callId: result.sessionId,
                    session: result.session,
                    status: 'connected',
                    fromNumber: trunkInfo.uri || trunkInfo.displayNumber || '',
                    timestamp: new Date()
                });
                self.showTrunkBridgeOptions(result.sessionId);
                self.updateUI();
                if (self.renderLinesGrid) self.renderLinesGrid();
            })
            .catch(function(error) {
                DispatcherUtils.alert('接听失败: ' + error.message, 'error');
            });
    };

    DispatcherControl.prototype.showTrunkBridgeOptions = function(trunkSessionId) {
        var html = '<div class="trunk-bridge-panel">' +
            '<h4>中继桥接</h4>' +
            '<p>请输入要桥接的目标号码：</p>' +
            '<input type="text" id="trunk-bridge-target" placeholder="输入目标号码" />' +
            '<div class="trunk-actions">' +
            '<button onclick="dispatcherControl.bridgeTrunkCall(\'' + trunkSessionId + '\')" class="btn-primary">桥接</button>' +
            '<button onclick="dispatcherControl.holdTrunkCall(\'' + trunkSessionId + '\')" class="btn-warning">保留</button>' +
            '<button onclick="dispatcherControl.hangupTrunk(\'' + trunkSessionId + '\')" class="btn-danger">挂断</button>' +
            '</div>' +
            '</div>';

        $('#dispatcher-trunk-panel').html(html).show();
    };

    // 桥接中继呼叫
    DispatcherControl.prototype.bridgeTrunkCall = function(trunkSessionId) {
        var self = this;
        var targetNumber = $('#trunk-bridge-target').val();
        
        if (!targetNumber) {
            DispatcherUtils.alert('请输入目标号码', 'warn');
            return;
        }

        // 拨打目标号码
        this.sipClient.makeCall('sip:' + targetNumber + '@' + this.getServerHost(), { audio: true, video: false })
            .then(function(result) {
                console.log('已呼叫目标号码:', targetNumber);
                
                // 等待目标接通后，转接中继呼叫
                setTimeout(function() {
                    self.sipClient.transfer('sip:' + targetNumber + '@' + self.getServerHost(), trunkSessionId)
                        .then(function() {
                            console.log('中继桥接成功');
                            DispatcherUtils.alert('桥接成功，调度员已退出');
                            
                            // 更新中继状态
                            self.trunkCalls = self.trunkCalls.filter(function(call) {
                                return call.callId !== trunkSessionId;
                            });
                            
                            self.updateUI();
                        })
                        .catch(function(error) {
                            console.error('桥接失败:', error);
                            DispatcherUtils.alert('桥接失败: ' + error.message, 'error');
                        });
                }, 3000); // 等待3秒确保目标接通
            })
            .catch(function(error) {
                console.error('呼叫目标失败:', error);
                DispatcherUtils.alert('呼叫目标失败: ' + error.message, 'error');
            });
    };

    // 保留中继呼叫
    DispatcherControl.prototype.holdTrunkCall = function(sessionId) {
        var self = this;
        
        this.sipClient.hold(sessionId)
            .then(function() {
                console.log('中继呼叫已保留');
                DispatcherUtils.alert('呼叫已保留');
                
                // 更新状态
                self.trunkCalls.forEach(function(call) {
                    if (call.callId === sessionId) {
                        call.status = 'held';
                    }
                });
                
                self.updateUI();
            })
            .catch(function(error) {
                console.error('保留失败:', error);
                DispatcherUtils.alert('保留失败: ' + error.message, 'error');
            });
    };

    // 处理自动中继
    DispatcherControl.prototype.processAutoTrunk = function(sessionId) {
        var self = this;
        var sid = sessionId || (this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId) || '';
        var targetFieldId = sid ? ('#auto-trunk-target-' + sid) : '#auto-trunk-target';
        var targetNumber = $(targetFieldId).val() || $('#auto-trunk-target').val();
        if (!sid) {
            DispatcherUtils.alert('未找到自动中继来电', 'warn');
            return;
        }
        if (!targetNumber) {
            DispatcherUtils.alert('请输入目标号码', 'warn');
            return;
        }
        this.sipClient.acceptById(sid, { audio: true, video: false })
            .then(function(result) {
                self.stopScenePrompt(sid);
                self.cleanupIncomingUI(sid);
                return self.sipClient.transfer('sip:' + targetNumber + '@' + self.getServerHost(), result.sessionId);
            })
            .then(function() {
                DispatcherUtils.alert('自动转接成功');
                self.updateUI();
                if (self.renderLinesGrid) self.renderLinesGrid();
            })
            .catch(function(error) {
                DispatcherUtils.alert('自动中继失败: ' + error.message, 'error');
            });
    };

    DispatcherControl.prototype.rejectTrunkCall = function(sessionId) {
        var self = this;
        var sid = sessionId || (this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId) || '';
        if (!sid) {
            return;
        }
        this.sipClient.rejectById(sid)
            .then(function() {
                self.stopScenePrompt(sid);
                self.cleanupIncomingUI(sid);
                self.updateUI();
                if (self.renderLinesGrid) self.renderLinesGrid();
            })
            .catch(function(error) {
                console.error('reject trunk call failed:', error);
            });
    };

    DispatcherControl.prototype.hangupTrunk = function(sessionId) {
        var self = this;
        
        this.sipClient.hangup(sessionId)
            .then(function() {
                console.log('中继呼叫已挂断');
                self.trunkCalls = self.trunkCalls.filter(function(call) {
                    return call.callId !== sessionId;
                });
                $('#dispatcher-trunk-panel').hide();
                self.updateUI();
            })
            .catch(function(error) {
                console.error('挂断失败:', error);
            });
    };

    // 处理普通来电
    DispatcherControl.prototype.handleNormalIncomingCall = function(callerInfo) { /* deprecated */ };

    DispatcherControl.prototype.queueIncomingCall = function(callerInfo) {
        var info = this.normalizeIncomingCallerInfo(callerInfo);
        var sid = info.sessionId || (this.sipClient && this.sipClient.incomingSession && this.sipClient.incomingSession._customId) || 'unknown';
        info.sessionId = sid;
        if (info.isEmergency) {
            this.emergencyPending[sid] = true;
            this.rememberEmergencyCall(sid, info);
        }
        var cfg = this.getIncomingSceneConfig(info.scene);
        var html = this.buildIncomingCardHtml(info);
        var container = $('#dispatcher-alerts');
        if (!container.length) return;
        var existed = $('#incoming-' + sid);
        if (existed.length) {
            existed.remove();
        }
        if (cfg.priority >= 70) {
            container.prepend(html).show();
        } else {
            container.append(html).show();
        }
        try {
            if (info.isEmergency && this.sipClient && this.sipClient.unlockAudioPlayback) {
                this.sipClient.unlockAudioPlayback();
            }
        } catch (e) {}
        if (info.isEmergency) {
            this.requestEmergencyAlarmTrigger(sid, info, { silent: false });
        }
    };

    DispatcherControl.prototype.acceptIncomingById = function(sessionId) {
        try {
            var card = $('#incoming-'+sessionId);
            var self = this;
	            this.sipClient.acceptById(sessionId, { audio: true, video: false })
	                .then(function(){
	                    self.stopScenePrompt(sessionId);
	                    self.cleanupIncomingUI(sessionId);
	                    if (self.emergencyPending[sessionId]) {
	                        try { self.startEmergencyRecording(sessionId); } catch(e) {}
	                        delete self.emergencyPending[sessionId];
	                        self.emergencySessions[sessionId] = true;
                            try { self.clearEmergencyAlarmForSession(sessionId, { status: 'answered', reason: 'answered', acknowledge: true, silent: true, allowRepeat: true }); } catch (e) {}
	                    }
	                })
                .catch(function(err){ DispatcherUtils.alert('接听失败: '+(err && err.message ? err.message : err), 'error'); });
        } catch(e) { console.error(e); }
    };

    DispatcherControl.prototype.rejectIncomingById = function(sessionId) {
        try {
            var card = $('#incoming-'+sessionId);
            var self = this;
            this.sipClient.rejectById(sessionId)
                .then(function(){
                    self.stopScenePrompt(sessionId);
                    self.cleanupIncomingUI(sessionId);
                    try { self.clearEmergencyAlarmForSession(sessionId, { status: 'rejected', reason: 'rejected', acknowledge: false, silent: true, allowRepeat: true, cleanupMetaOnComplete: true }); } catch (e) {}
                })
                .catch(function(err){ console.error(err); });
        } catch(e) { console.error(e); }
    };

    DispatcherControl.prototype.preemptAndAccept = function(emergencySessionId) {
        var sessions = this.sipClient.getActiveSessions ? this.sipClient.getActiveSessions() : [];
        var victimId = null;
        for (var i=0;i<sessions.length;i++) {
            var s = sessions[i].session;
            var id = sessions[i].sessionId;
            var meta = null;
            try { meta = this.sipClient && this.sipClient.getSessionMeta ? this.sipClient.getSessionMeta(s) : null; } catch(e) {}
            if (s && !(meta && meta.isEmergency) && s._callType !== 'emergency' && s.isEstablished && s.isEstablished()) { victimId = id; break; }
        }
        if (!victimId && sessions.length>0) { victimId = sessions[0].sessionId; }
        var self = this;
        var proceed = function(){ self.acceptIncomingById(emergencySessionId); };
        if (victimId) {
            this.sipClient.hangup(victimId).then(proceed).catch(proceed);
        } else {
            proceed();
        }
    };

	    DispatcherControl.prototype.startEmergencyRecording = function() {
	        try {
	            var sessionId = arguments[0] || '';
	            this.requestSessionRecording(sessionId);
	        } catch(e) {}
	    };

    DispatcherControl.prototype.acceptCall = function() {
        var self = this;
        
        this.sipClient.acceptIncomingCall({ audio: true, video: false })
            .then(function() {
                var container = $('#dispatcher-alerts');
                if (container.find('.incoming-call, .trunk-incoming-call').length === 0) { container.hide(); }
                self.updateUI();
            })
            .catch(function(error) {
                DispatcherUtils.alert('接听失败: ' + error.message, 'error');
            });
    };

    // 拒绝来电
    DispatcherControl.prototype.rejectCall = function() {
        var self = this;
        
        this.sipClient.rejectIncomingCall()
            .then(function() {
                var container = $('#dispatcher-alerts');
                if (container.find('.incoming-call, .trunk-incoming-call').length === 0) { container.hide(); }
                self.updateUI();
            })
            .catch(function(error) {
                console.error('拒绝失败:', error);
            });
    };

    // ============ 组呼全呼功能 ============

    // 发起组呼
    DispatcherControl.prototype.startGroupCall = function(groupId) {
        var self = this;
        
        if (!this.callGroups[groupId]) {
            DispatcherUtils.alert('组不存在', 'error');
            return;
        }

        var group = this.callGroups[groupId];
        if (!group.extensions || group.extensions.length === 0) {
            DispatcherUtils.alert('该组没有成员', 'warn');
            return;
        }

        console.log('发起组呼:', group.name, group.extensions);

        this.groupCallActive = true;
        this.groupCallSessions = [];

        var serverHost = this.getServerHost();
        var targets = group.extensions.map(function(ext) {
            return 'sip:' + ext + '@' + serverHost;
        });

        // 批量拨打
        this.sipClient.makeCallBatch(targets, { audio: true, video: false })
            .then(function(results) {
                console.log('组呼已发起，等待成员接听');
                self.groupCallSessions = results;
                self.groupCallStartTime = new Date();
                
                // 显示组呼控制面板
                self.showGroupCallPanel(group.name, results);
                
                // 启动计时器
                self.startGroupCallTimer();
                
                self.updateUI();
            })
            .catch(function(error) {
                console.error('组呼失败:', error);
                DispatcherUtils.alert('组呼失败: ' + error.message, 'error');
                self.groupCallActive = false;
            });
    };

    // 使用指定分机列表发起组呼
    // 使用会议桥模式发起组呼
    DispatcherControl.prototype.startGroupCallWithExtensions = function(extensions) {
        var self = this;
        
        if (!this.sipClient || !this.sipClient.isRegistered) {
            DispatcherUtils.alert('请先注册SIP', 'warn');
            return;
        }
        
        if (extensions.length === 0) {
            DispatcherUtils.alert('请至少选择一个分机', 'warn');
            return;
        }
        if (this.config && this.config.authUser) {
            var beforeCount = extensions.length;
            var authUserStr = String(this.config.authUser);
            extensions = extensions.filter(function(e){ return String(e) !== authUserStr; });
            if (extensions.length < beforeCount) {
                console.log('批量呼叫: 已排除调度员自身分机 ' + authUserStr);
            }
        }
        
        console.log('[Time 0] 开始发起组呼（会议桥模式）:', new Date().toISOString(), extensions);
        
        // 步骤1：创建会议室
        $.ajax({
            url: 'dispatcher_conference_api.php',
            type: 'POST',
            data: { action: 'create_conference' },
            dataType: 'json',
            success: function(response) {
                console.log('[创建会议室响应] >', response);
                if (response.success) {
                    self.conferenceRoom = response.conference_room;
                    console.log('[Time 1] 会议室已创建 (inline mode):', self.conferenceRoom, 'context:', response.context, new Date().toISOString());
                    
                    // *** 关键修改：使用 inline 方式，无需等待 dialplan 生效 ***
                    console.log('[Time 2] 立即加入会议（无需等待）:', new Date().toISOString());
                    
                    // 调度员加入会议（不阻塞后续流程）
                    var dispatcherJoinPromise = self.joinConference(response.conference_room, response.context);
                    
                    dispatcherJoinPromise.then(function() {
                        console.log('[Time 3] ✅ 调度员已加入会议:', new Date().toISOString());
                    }).catch(function(error) {
                        console.error('调度员加入会议失败:', error);
                        // 不阻止邀请流程
                    });
                    
                    // 并行：立即开始邀请分机（不等待调度员）
                    console.log('[Time 4] 开始邀请分机:', new Date().toISOString());
                    self.inviteExtensionsToConference(response.conference_room, extensions);
                    
                    // 显示组呼状态栏和轮询
                    self.showGroupCallStatus(extensions.length);
                    self.startPollingConferenceMembers(response.conference_room);
                } else {
                    console.log('[ 创建会议室失败：success=false ] >', response.error);
                    // 提供更详细的错误信息
                    var errorMsg = '创建会议室失败';
                    if (response.error) {
                        errorMsg += ': ' + response.error;
                        if (response.error.indexOf('domain_name') !== -1) {
                            errorMsg += '\n\n可能原因：\n- 域名配置问题\n- 数据库连接问题\n- 权限不足';
                        } else if (response.error.indexOf('Database') !== -1) {
                            errorMsg += '\n\n可能原因：\n- 数据库连接失败\n- 数据库服务未运行';
                        }
                    } else {
                        errorMsg += ': 未知错误';
                    }
                    DispatcherUtils.alert(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('[ 创建会议室失败：error ] >', error);
                console.log('[ HTTP状态 ] >', xhr.status);
                console.log('[ 响应文本 ] >', xhr.responseText);
                
                // 提供更详细的错误信息
                var errorMsg = '创建会议室失败: ' + error;
                if (xhr.status === 0) {
                    errorMsg += '\n\n可能原因：\n- 网络连接问题\n- 服务器未响应';
                } else if (xhr.status === 500) {
                    errorMsg += '\n\n可能原因：\n- 服务器内部错误\n- 数据库连接问题';
                } else if (xhr.status === 403) {
                    errorMsg += '\n\n可能原因：\n- 权限不足\n- 会话已过期';
                }
                
                DispatcherUtils.alert(errorMsg, 'error');
                console.error('创建会议室失败:', error);
            }
        });
    };

    // 调度员加入会议
    // *** 修复：调度员以moderator身份加入，确保有权限且音频正常 ***
    DispatcherControl.prototype.joinConference = function(conferenceRoom, context) {
        var self = this;
        var serverHost = this.getServerHost();
        
        // 使用返回的context，如果没有则使用默认
        var targetContext = context || 'default';
        
        // 返回Promise
        return new Promise(function(resolve, reject) {
            console.log('调度员准备加入会议 (inline mode):', conferenceRoom, 'context:', targetContext);
            
            // *** 关键修改：使用 inline 方式，无需 reloadxml 和 dialplan 检查 ***
            // 使用 FreeSWITCH originate 呼叫调度员，确保 WebRTC 客户端也能正常加入
            console.log('使用 FreeSWITCH originate 呼叫调度员加入会议...');
            
            // 获取当前调度员分机号（从 DispatcherControl 的 config 中获取）
            var dispatcherExt = self.config.authUser || self.config.uri.split(':')[1].split('@')[0];
            
            // 设置自动接听标记（类似插入讲话）
            try { window.__autoAnswerBargeNext = true; } catch (e) {}
            try {
                if (self.sipClient && self.sipClient.unlockAudioPlayback) {
                    self.sipClient.unlockAudioPlayback();
                }
            } catch (e) {}
            
            // 调用后端API让FreeSWITCH呼叫调度员
            $.ajax({
                url: 'dispatcher_conference_api.php',
                type: 'POST',
                data: {
                    action: 'dispatcher_join_conference',
                    conference_room: conferenceRoom,
                    extension: dispatcherExt
                },
                dataType: 'json',
                success: function(response) {
                    console.log('调度员加入会议响应:', response);
                    
                    if (response.success) {
                        // 设置状态
                        self.groupCallActive = true;
                        self.groupCallStartTime = Date.now();
                        self.conferenceParticipants = [];
                        
                        // 显示状态栏
                        $('#group-call-status').show();
                        $('#group-call-status-text').text('组呼进行中 - 正在邀请参与者...');
                        
                        // 启动计时器
                        self.startGroupCallTimer();
                        
                        console.log('✅ 调度员已加入会议（通过 FreeSWITCH originate + inline）');
                        resolve(response);
                    } else {
                        console.error('❌ 调度员加入会议失败:', response.error);
                        DispatcherUtils.alert('调度员加入会议失败: ' + (response.error || '未知错误'), 'error');
                        reject(new Error(response.error || '加入会议失败'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ 调度员加入会议请求失败:', error);
                    DispatcherUtils.alert('调度员加入会议请求失败: ' + error, 'error');
                    reject(new Error('请求失败: ' + error));
                }
            });
        });
    };

    // 显示组呼状态栏
    DispatcherControl.prototype.showGroupCallStatus = function(participantCount) {
        var self = this;
        var statusBar = document.getElementById('group-call-status');
        if (statusBar) {
            var statusText = document.getElementById('group-call-status-text');
            if (statusText) {
                statusText.textContent = '组呼进行中 - ' + participantCount + ' 个分机';
            }
            statusBar.style.display = 'block';
            
            // 启动计时器
            if (!this.timers.groupCall) {
                var startTime = Date.now();
                this.timers.groupCall = setInterval(function() {
                    var elapsed = Math.floor((Date.now() - startTime) / 1000);
                    var minutes = Math.floor(elapsed / 60);
                    var seconds = elapsed % 60;
                    if (statusText) {
                        statusText.textContent = '组呼进行中 - ' + participantCount + ' 个分机 (' + 
                            (minutes < 10 ? '0' : '') + minutes + ':' + 
                            (seconds < 10 ? '0' : '') + seconds + ')';
                    }
                }, 1000);
            }
        }
    };

    // 邀请分机到会议
    // *** 增强错误处理和日志 ***
    DispatcherControl.prototype.inviteExtensionsToConference = function(conferenceRoom, extensions) {
        var self = this;
        var invited = 0;
        var failed = 0;
        var total = extensions.length;
        
        console.log('📞 开始邀请', total, '个分机/号码加入会议', conferenceRoom);
        
        extensions.forEach(function(ext, index) {
            // 延迟邀请，避免并发过高
            setTimeout(function() {
                $.ajax({
                    url: 'dispatcher_conference_api.php',
                    type: 'POST',
                    data: {
                        action: 'invite_to_conference',
                        conference_room: conferenceRoom,
                        extension: ext
                    },
                    dataType: 'json',
                    success: function(response) {
                        invited++;
                        if (response.success) {
                            console.log('[Time 5] ✓ 成功邀请:', ext, new Date().toISOString());
                        } else {
                            failed++;
                            console.error('✗ 邀请失败:', ext, '原因:', response.error || response);
                        }
                        
                        // 更新状态
                        $('#group-call-status-text').text(
                            '组呼进行中 - 已邀请 ' + invited + '/' + total + 
                            (failed > 0 ? ' (失败:' + failed + ')' : '')
                        );
                        
                        // 所有邀请完成后，开始轮询成员列表
                        if (invited === total) {
                            console.log('📋 所有邀请完成 - 成功:', (invited - failed), '失败:', failed);
                            self.startPollingConferenceMembers();
                        }
                    },
                    error: function(xhr, status, error) {
                        invited++;
                        failed++;
                        console.error('✗ 邀请请求失败:', ext, 'HTTP错误:', error, 'Status:', xhr.status);
                        
                        // 尝试解析错误响应
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            console.error('  错误详情:', errorResponse);
                        } catch(e) {
                            console.error('  响应文本:', xhr.responseText);
                        }
                        
                        // 即使失败也要检查是否完成
                        if (invited === total) {
                            console.log('📋 所有邀请完成 - 成功:', (invited - failed), '失败:', failed);
                            self.startPollingConferenceMembers();
                        }
                    }
                });
            }, index * 50); // 优化：减少延迟到50ms，加快呼叫速度
        });
    };

    // 轮询会议成员列表
    DispatcherControl.prototype.startPollingConferenceMembers = function() {
        var self = this;
        
        if (self.memberPollInterval) {
            clearInterval(self.memberPollInterval);
        }
        
        self.memberPollInterval = setInterval(function() {
            if (!self.conferenceRoom || !self.groupCallActive) {
                clearInterval(self.memberPollInterval);
                return;
            }
            
            $.ajax({
                url: 'dispatcher_conference_api.php',
                type: 'GET',
                data: {
                    action: 'get_conference_members',
                    conference_room: self.conferenceRoom
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        self.conferenceParticipants = response.members;
                        var count = response.members.length - 1; // 减去调度员自己
                        if (count < 0) count = 0;
                        $('#group-call-status-text').text('组呼进行中 - ' + count + '人已接入');
                    }
                },
                error: function(error) {
                    console.error('获取会议成员失败:', error);
                }
            });
        }, 2000); // 每2秒更新一次
    };

    // 启动组呼计时器
    DispatcherControl.prototype.startGroupCallTimer = function() {
        var self = this;
        
        if (this.timers.groupCall) {
            clearInterval(this.timers.groupCall);
        }
        
        this.timers.groupCall = setInterval(function() {
            if (!self.groupCallActive) {
                clearInterval(self.timers.groupCall);
                return;
            }
            
            var duration = Math.floor((new Date() - self.groupCallStartTime) / 1000);
            var minutes = Math.floor(duration / 60);
            var seconds = duration % 60;
            var timeStr = minutes + '分' + (seconds < 10 ? '0' : '') + seconds + '秒';
            
            $('#group-call-duration').text(timeStr);
        }, 1000);
    };

    // 发起全呼
    // 全呼 - 使用会议桥模式（与组呼统一）
    DispatcherControl.prototype.startBroadcastCall = function() {
        var self = this;
        
        // 获取所有在线分机（异步）
            this.getAllExtensions(function(extensions) {
            if (extensions.length === 0) {
                DispatcherUtils.alert('没有可用的分机', 'warn');
                return;
            }
            
            console.log('发起全呼（会议桥模式）:', extensions);
            
            // 使用与组呼相同的会议桥逻辑
            self.startGroupCallWithExtensions(extensions);
        });
    };

    // 显示组呼控制面板
    DispatcherControl.prototype.showGroupCallPanel = function(groupName, sessions) {
        var html = '<div class="group-call-panel">' +
            '<h3>📢 组呼进行中 - ' + groupName + '</h3>' +
            '<div style="display: flex; justify-content: space-between; margin-bottom: 12px;">' +
            '<p style="margin: 0;">已接通成员: <span id="group-call-count">0</span>/' + sessions.length + '</p>' +
            '<p style="margin: 0;">通话时长: <span id="group-call-duration">0分00秒</span></p>' +
            '</div>' +
            '<div class="group-call-controls">' +
            '<button onclick="dispatcherControl.toggleGroupCallMute()" class="btn-mute" id="group-mute-btn">静音调度员</button>' +
            '<button onclick="dispatcherControl.endGroupCall()" class="btn-danger">结束组呼</button>' +
            '</div>' +
            '<div class="group-call-members" id="group-call-members"></div>' +
            '</div>';

        $('#dispatcher-group-call-panel').html(html).show();
        
        // 更新成员列表
        this.updateGroupCallMembers(sessions);
    };

    // 更新组呼成员列表
    DispatcherControl.prototype.updateGroupCallMembers = function(sessions) {
        var self = this;
        var html = '<ul>';
        var connectedCount = 0;

        sessions.forEach(function(result, index) {
            var session = result.session;
            var status = session.isEstablished() ? '已接通' : '呼叫中';
            if (session.isEstablished()) {
                connectedCount++;
            }

            html += '<li>' +
                '<span class="member-number">' + (index + 1) + '. </span>' +
                '<span class="member-status">' + status + '</span>' +
                '<button onclick="dispatcherControl.muteGroupMember(\'' + result.sessionId + '\')" class="btn-sm">禁言</button>' +
                '<button onclick="dispatcherControl.unmuteGroupMember(\'' + result.sessionId + '\')" class="btn-sm">发言</button>' +
                '</li>';
        });

        html += '</ul>';
        $('#group-call-members').html(html);
        $('#group-call-count').text(connectedCount);
    };

    // 切换调度员静音状态
    DispatcherControl.prototype.toggleGroupCallMute = function() {
        if (this.sipClient.isMuted) {
            // 全部取消静音
            for (var sessionId in this.sipClient.sessions) {
                if (this.sipClient.sessions.hasOwnProperty(sessionId)) {
                    this.sipClient.unmuteSession(sessionId);
                }
            }
            this.sipClient.isMuted = false;
            $('#group-mute-btn').text('静音调度员');
        } else {
            // 全部静音（调度员单向讲话）
            for (var sessionId in this.sipClient.sessions) {
                if (this.sipClient.sessions.hasOwnProperty(sessionId)) {
                    this.sipClient.muteSession(sessionId);
                }
            }
            this.sipClient.isMuted = true;
            $('#group-mute-btn').text('取消静音');
        }
    };

    // 禁止某成员发言
    DispatcherControl.prototype.muteGroupMember = function(sessionId) {
        this.sipClient.muteSession(sessionId);
        console.log('成员已禁言:', sessionId);
        DispatcherUtils.alert('该成员已被禁言');
    };

    // 允许某成员发言
    DispatcherControl.prototype.unmuteGroupMember = function(sessionId) {
        this.sipClient.unmuteSession(sessionId);
        console.log('成员可以发言:', sessionId);
        DispatcherUtils.alert('该成员可以发言');
    };

    // 结束组呼
    DispatcherControl.prototype.endGroupCall = function() {
        var self = this;
        
        if (confirm('确定要结束组呼吗？')) {
            // 计算通话时长
            var duration = self.groupCallStartTime ? 
                Math.floor((new Date() - self.groupCallStartTime) / 1000) : 0;
            
            // 记录日志
            if (typeof logCallEnd === 'function') {
                logCallEnd('group', {
                    duration: duration,
                    participants: self.conferenceParticipants.map(function(p) { 
                        return p.caller_id_number; 
                    }),
                    status: 'completed'
                });
            }
            
            // 停止轮询
            if (self.memberPollInterval) {
                clearInterval(self.memberPollInterval);
                self.memberPollInterval = null;
            }
            
            // 清除计时器
            if (self.timers.groupCall) {
                clearInterval(self.timers.groupCall);
                self.timers.groupCall = null;
            }
            
            // 结束会议（踢出所有人）
            if (self.conferenceRoom) {
                $.ajax({
                    url: 'dispatcher_conference_api.php',
                    type: 'POST',
                    data: {
                        action: 'end_conference',
                        conference_room: self.conferenceRoom
                    },
                    success: function() {
                        console.log('会议已结束');
                    },
                    error: function(error) {
                        console.error('结束会议失败:', error);
                    }
                });
            }
            
            // 挂断自己的连接
            this.sipClient.hangupAll()
                .then(function() {
                    console.log('组呼已结束');
                })
                .catch(function(error) {
                    console.error('挂断失败:', error);
                });
            
            // 重置状态
            self.groupCallActive = false;
            self.conferenceRoom = null;
            self.conferenceParticipants = [];
            self.groupCallSessions = [];
            self.groupCallStartTime = null;
            $('#dispatcher-group-call-panel').hide();
            $('#group-call-status').hide();
            self.updateUI();
        }
    };

    // ============ 多方会议功能 ============

    // 发起会议
	DispatcherControl.prototype.startConference = function(participants) {
        if (!participants || participants.length < 2) {
            DispatcherUtils.alert('至少需要2个参与者', 'warn');
            return;
        }
		// 统一走会议桥流程，确保有 conference_room、member_id 等
		this.startGroupCallWithExtensions(participants);
	};

    // 启动会议计时器
    DispatcherControl.prototype.startConferenceTimer = function() {
        var self = this;
        
        if (this.timers.conference) {
            clearInterval(this.timers.conference);
        }
        
        this.timers.conference = setInterval(function() {
            if (!self.conferenceActive) {
                clearInterval(self.timers.conference);
                return;
            }
            
            var duration = Math.floor((new Date() - self.conferenceStartTime) / 1000);
            var minutes = Math.floor(duration / 60);
            var seconds = duration % 60;
            var timeStr = minutes + '分' + (seconds < 10 ? '0' : '') + seconds + '秒';
            
            $('#conference-duration').text(timeStr);
        }, 1000);
    };

    // 显示会议控制面板
    DispatcherControl.prototype.showConferencePanel = function() {
        var html = '<div class="conference-panel">' +
            '<h3>🎤 多方会议进行中</h3>' +
            '<div style="display: flex; justify-content: space-between; margin-bottom: 12px;">' +
            '<p style="margin: 0;">参与人数: <span id="conference-count">' + this.conferenceParticipants.length + '</span></p>' +
            '<p style="margin: 0;">会议时长: <span id="conference-duration">0分00秒</span></p>' +
            '</div>' +
            '<div class="conference-controls">' +
            '<button onclick="dispatcherControl.addConferenceParticipant()" class="btn-primary">添加参与者</button>' +
            '<button onclick="dispatcherControl.endConference()" class="btn-danger">结束会议</button>' +
            '</div>' +
            '<div class="conference-participants" id="conference-participants"></div>' +
            '</div>';

        $('#dispatcher-conference-panel').html(html).show();
        
        // 更新参与者列表
        this.updateConferenceParticipants();
    };

    // 更新会议参与者列表
    DispatcherControl.prototype.updateConferenceParticipants = function() {
        var self = this;
        var html = '<ul>';

        this.conferenceParticipants.forEach(function(participant) {
            var status = participant.session.isEstablished() ? '已加入' : '呼叫中';
            var muteBtn = participant.muted ? 
                '<button onclick="dispatcherControl.unmuteSpeaker(\'' + participant.sessionId + '\')" class="btn-sm">允许发言</button>' :
                '<button onclick="dispatcherControl.muteSpeaker(\'' + participant.sessionId + '\')" class="btn-sm">禁止发言</button>';

            html += '<li>' +
                '<span class="participant-ext">' + participant.extension + '</span> ' +
                '<span class="participant-status">' + status + '</span> ' +
                muteBtn +
                '<button onclick="dispatcherControl.removeConferenceParticipant(\'' + participant.sessionId + '\')" class="btn-sm btn-danger">移除</button>' +
                '</li>';
        });

        html += '</ul>';
        $('#conference-participants').html(html);
    };

    // 禁止发言
    DispatcherControl.prototype.muteSpeaker = function(sessionId) {
        var self = this;
        
        this.sipClient.muteSession(sessionId);
        
        this.conferenceParticipants.forEach(function(p) {
            if (p.sessionId === sessionId) {
                p.muted = true;
            }
        });
        
        this.updateConferenceParticipants();
        console.log('参与者已被禁言:', sessionId);
    };

    // 允许发言
    DispatcherControl.prototype.unmuteSpeaker = function(sessionId) {
        var self = this;
        
        this.sipClient.unmuteSession(sessionId);
        
        this.conferenceParticipants.forEach(function(p) {
            if (p.sessionId === sessionId) {
                p.muted = false;
            }
        });
        
        this.updateConferenceParticipants();
        console.log('参与者可以发言:', sessionId);
    };

    // 移除会议参与者
    DispatcherControl.prototype.removeConferenceParticipant = function(sessionId) {
        var self = this;
        
        if (confirm('确定要移除该参与者吗？')) {
            this.sipClient.hangup(sessionId)
                .then(function() {
                    self.conferenceParticipants = self.conferenceParticipants.filter(function(p) {
                        return p.sessionId !== sessionId;
                    });
                    
                    $('#conference-count').text(self.conferenceParticipants.length);
                    self.updateConferenceParticipants();
                    console.log('参与者已移除');
                })
                .catch(function(error) {
                    console.error('移除参与者失败:', error);
                });
        }
    };

    // 添加会议参与者
    DispatcherControl.prototype.addConferenceParticipant = function() {
        var self = this;
        var extension = prompt('请输入要添加的分机号码:');
        
        if (!extension) {
            return;
        }

        var serverHost = this.getServerHost();
        var target = 'sip:' + extension + '@' + serverHost;

        this.sipClient.makeCall(target, { audio: true, video: false })
            .then(function(result) {
                console.log('已呼叫新参与者:', extension);
                
                self.conferenceParticipants.push({
                    extension: extension,
                    sessionId: result.sessionId,
                    session: result.session,
                    muted: false,
                    joinTime: new Date()
                });
                
                $('#conference-count').text(self.conferenceParticipants.length);
                self.updateConferenceParticipants();
            })
            .catch(function(error) {
                console.error('添加参与者失败:', error);
                DispatcherUtils.alert('添加失败: ' + error.message, 'error');
            });
    };

	// 结束会议（会议桥模式下同时结束服务器侧会议）
	DispatcherControl.prototype.endConference = function() {
		var self = this;
		if (!confirm('确定要结束会议吗？')) return;
		var finalizeConferenceEnd = function() {
			var duration = self.conferenceStartTime ? Math.floor((new Date() - self.conferenceStartTime) / 1000) : 0;
			if (typeof logCallEnd === 'function') {
				var participantsList = Array.isArray(self.conferenceParticipants)
					? self.conferenceParticipants.map(function(p) { return p.extension || p.caller_id_number; })
					: [];
				logCallEnd('conference', { duration: duration, participants: participantsList, status: 'completed' });
			}
			if (self.memberPollInterval) {
				clearInterval(self.memberPollInterval);
				self.memberPollInterval = null;
			}
			if (self.timers.conference) {
				clearInterval(self.timers.conference);
				self.timers.conference = null;
			}
			self.conferenceRoom = null;
			self.sipClient.hangupAll()
				.then(function() {
					self.conferenceActive = false;
					self.conferenceParticipants = [];
					self.conferenceStartTime = null;
					$('#dispatcher-conference-panel').hide();
					$('#conference-status').hide();
					self.updateUI();
				})
				.catch(function(error) {
					console.error('结束会议失败:', error);
					DispatcherUtils.alert('本地会话挂断失败: ' + (error && error.message ? error.message : error), 'error');
				});
		};
		if (!self.conferenceRoom) {
			finalizeConferenceEnd();
			return;
		}
		$.ajax({
			url: 'dispatcher_conference_api.php',
			type: 'POST',
			dataType: 'json',
			data: { action: 'end_conference', conference_room: self.conferenceRoom }
		}).done(function(response) {
			if (!response || !response.success) {
				DispatcherUtils.alert((response && response.error) || '服务器侧会议结束失败，请检查 ESL 连接', 'error');
				return;
			}
			finalizeConferenceEnd();
		}).fail(function(xhr) {
			var message = '结束会议请求失败';
			if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
				message = xhr.responseJSON.error;
			}
			DispatcherUtils.alert(message, 'error');
		});
	};

    // 静音会议参与者
    DispatcherControl.prototype.muteConferenceParticipant = function(extension) {
        var participant = this.conferenceParticipants.find(function(p) {
            return p.extension === extension;
        });
        
        if (participant && participant.session) {
            this.sipClient.muteSession(participant.session._customId);
            participant.muted = true;
            console.log('静音参与者:', extension);
        }
    };

    // 取消静音会议参与者
    DispatcherControl.prototype.unmuteConferenceParticipant = function(extension) {
        var participant = this.conferenceParticipants.find(function(p) {
            return p.extension === extension;
        });
        
        if (participant && participant.session) {
            this.sipClient.unmuteSession(participant.session._customId);
            participant.muted = false;
            console.log('取消静音参与者:', extension);
        }
    };

    // 踢出会议参与者
    DispatcherControl.prototype.kickConferenceParticipant = function(extension) {
        var self = this;
        var index = this.conferenceParticipants.findIndex(function(p) {
            return p.extension === extension;
        });
        
        if (index !== -1) {
            var participant = this.conferenceParticipants[index];
            if (participant.session) {
                participant.session.terminate();
            }
            this.conferenceParticipants.splice(index, 1);
            console.log('踢出参与者:', extension);
        }
    };

    // 全部静音
    DispatcherControl.prototype.muteAllConferenceParticipants = function() {
        var self = this;
        this.conferenceParticipants.forEach(function(p) {
            if (p.session) {
                self.sipClient.muteSession(p.session._customId);
                p.muted = true;
            }
        });
        console.log('全部参与者已静音');
    };

    // 全部取消静音
    DispatcherControl.prototype.unmuteAllConferenceParticipants = function() {
        var self = this;
        this.conferenceParticipants.forEach(function(p) {
            if (p.session) {
                self.sipClient.unmuteSession(p.session._customId);
                p.muted = false;
            }
        });
        console.log('全部参与者已取消静音');
    };

    // ============ 分组管理 ============

    // 添加呼叫组
    DispatcherControl.prototype.addCallGroup = function(groupId, groupName) {
        this.callGroups[groupId] = {
            name: groupName,
            extensions: []
        };
        this.saveCallGroups();
        this.updateUI();
    };

    // 删除呼叫组
    DispatcherControl.prototype.deleteCallGroup = function(groupId) {
        if (groupId === 'default') {
            DispatcherUtils.alert('不能删除默认组', 'error');
            return;
        }
        
        if (confirm('确定要删除该组吗？')) {
            delete this.callGroups[groupId];
            this.saveCallGroups();
            this.updateUI();
        }
    };

    // 添加分机到组
    DispatcherControl.prototype.addExtensionToGroup = function(groupId, extension) {
        if (!this.callGroups[groupId]) {
            return;
        }
        
        if (this.callGroups[groupId].extensions.indexOf(extension) === -1) {
            this.callGroups[groupId].extensions.push(extension);
            this.saveCallGroups();
            this.updateUI();
        }
    };

    // 从组中移除分机
    DispatcherControl.prototype.removeExtensionFromGroup = function(groupId, extension) {
        if (!this.callGroups[groupId]) {
            return;
        }
        
        var index = this.callGroups[groupId].extensions.indexOf(extension);
        if (index > -1) {
            this.callGroups[groupId].extensions.splice(index, 1);
            this.saveCallGroups();
            this.updateUI();
        }
    };

    // ============ 辅助方法 ============

    // 获取服务器主机
    DispatcherControl.prototype.getServerHost = function() {
        return this.parseHostFromUri(this.config.uri);
    };

    // 从 URI 解析主机
    DispatcherControl.prototype.parseHostFromUri = function(uri) {
        var match = uri.match(/@([^;:>]+)/);
        return match ? match[1] : 'localhost';
    };

    // 获取所有分机（从页面或服务器获取）
    DispatcherControl.prototype.getAllExtensions = function(callback) {
        var extensions = [];
        
        // 从现有的操作面板获取所有在线分机号
        // content.php中使用的CSS类是 .op_ext，div的id就是分机号
        $('div.op_ext').each(function() {
            var ext = $(this).attr('id');
            if (ext && ext.match(/^\d+$/)) { // 确保是数字分机号
                extensions.push(ext);
            }
        });
        
        console.log('从页面获取到分机:', extensions);
        
        // 如果页面上没有分机，从服务器获取
        if (extensions.length === 0) {
            $.ajax({
                url: 'dispatcher_api.php?action=get_extensions',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        var exts = response.data.map(function(ext) {
                            return ext.extension;
                        });
                        if (callback) callback(exts);
                    }
                },
                error: function() {
                    console.warn('从服务器获取分机失败');
                    if (callback) callback([]);
                }
            });
        } else {
            if (callback) {
                callback(extensions);
            } else {
                return extensions;
            }
        }
        
        return extensions;
    };

    // 更新UI
    DispatcherControl.prototype.updateUI = function() {
        // 更新注册状态
        if (this.sipClient.isRegistered) {
            $('#dispatcher-status').html('<span class="status-online">●</span> 已注册').removeClass('offline').addClass('online');
            $('#dispatcher-register-btn').prop('disabled', true);
            $('#dispatcher-unregister-btn').prop('disabled', false);
        } else {
            $('#dispatcher-status').html('<span class="status-offline">●</span> 未注册').removeClass('online').addClass('offline');
            $('#dispatcher-register-btn').prop('disabled', false);
            $('#dispatcher-unregister-btn').prop('disabled', true);
        }

        var sessionCount = Object.keys(this.sipClient.sessions).length;
        $('#dispatcher-call-count').text(sessionCount);
        if (typeof this.rehydrateAlertsFromSessions === 'function') {
            this.rehydrateAlertsFromSessions();
        }

        // 更新组呼状态
        if (this.groupCallActive) {
            $('#group-call-indicator').show();
        } else {
            $('#group-call-indicator').hide();
        }

        // 更新会议状态
        if (this.conferenceActive) {
            $('#conference-indicator').show();
        } else {
            $('#conference-indicator').hide();
        }
        this.renderEntityStatusPanel();
    };

    DispatcherControl.prototype.rehydrateAlertsFromSessions = function() {
        var container = $('#dispatcher-alerts');
        if (!container.length) return;
        var incomingIds = (this.sipClient.getIncomingSessions && this.sipClient.getIncomingSessions()) || [];
        for (var i = 0; i < incomingIds.length; i++) {
            var sid = incomingIds[i];
            if ($('#incoming-' + sid).length !== 0) {
                continue;
            }
            var callerInfo = this.getCallerInfoBySessionId(sid);
            this.startScenePrompt(callerInfo);
            this.queueIncomingCall(callerInfo);
        }
        if (incomingIds.length > 0) {
            container.show();
        } else {
            container.hide();
        }
    };

    DispatcherControl.prototype.cleanupIncomingUI = function(sessionId){
        try{
            this.stopScenePrompt(sessionId);
            var card = $('#incoming-'+sessionId);
            if (card.length) { card.remove(); }
            var container = $('#dispatcher-alerts');
            if (container.length){ if (container.find('.incoming-call, .trunk-incoming-call').length === 0) { container.hide(); } }
        }catch(e){}
    }

    // 切换自动中继模式
    DispatcherControl.prototype.toggleAutoTrunkMode = function() {
        this.autoTrunkMode = !this.autoTrunkMode;
        
        if (this.autoTrunkMode) {
            $('#auto-trunk-btn').addClass('active').text('自动中继: 开启');
            console.log('自动中继模式已开启');
        } else {
            $('#auto-trunk-btn').removeClass('active').text('自动中继: 关闭');
            console.log('自动中继模式已关闭');
        }
    };

    DispatcherControl.prototype.extractCallFailureCode = function(data) {
        try {
            var error = data && data.error ? data.error : null;
            var code = (error && ((error.response && error.response.status_code) || error.status_code || error.statusCode)) || 0;
            code = parseInt(code, 10);
            return isNaN(code) ? 0 : code;
        } catch (e) {
            return 0;
        }
    };

    DispatcherControl.prototype.classifyCallFailure = function(data, fallbackMessage) {
        var session = data && data.session ? data.session : null;
        var error = data && data.error ? data.error : null;
        var code = this.extractCallFailureCode(data);
        var direction = session && session.direction ? session.direction : '';
        var established = !!(session && session.isEstablished && session.isEstablished());
        var cause = '';

        try {
            cause = String((error && (error.cause || error.message)) || '').toLowerCase();
        } catch (e) {}

        var cancelLikeCode = code === 480 || code === 486 || code === 487 || code === 603;
        var cancelLikeCause = /cancel|reject|busy|bye|terminated|hangup/.test(cause);
        if (direction === 'incoming' && !established && (cancelLikeCode || cancelLikeCause)) {
            var message = '来电已结束';
            if (code === 486 || cause.indexOf('busy') !== -1 || cause.indexOf('reject') !== -1) {
                message = '来电已拒绝';
            } else if (code === 487 || cause.indexOf('cancel') !== -1) {
                message = '来电已取消';
            } else if (code === 480 || code === 603 || cause.indexOf('bye') !== -1 || cause.indexOf('hangup') !== -1) {
                message = '对方已挂断';
            }
            return {
                code: code,
                status: 'ended',
                message: message,
                benign: true
            };
        }

        return {
            code: code,
            status: 'failed',
            message: fallbackMessage || '通话失败',
            benign: false
        };
    };

    // 统一的通话状态更新方法
    DispatcherControl.prototype.updateCallStatus = function(status, sessionId, message) {
        var self = this;
        
        switch(status) {
            case 'calling':
                console.log('📞 呼叫中:', message);
                break;
                
            case 'connected':
                console.log('✅ 通话已连接:', message);
                break;
                
            case 'ended':
                console.log('📞 通话已结束:', message);
                // 清理UI状态
                this.clearCallUI(sessionId);
                break;
                
            case 'failed':
                console.error('❌ 通话失败:', message);
                // 清理UI状态并显示错误
                this.clearCallUI(sessionId);
                this.showCallError(message);
                break;
        }
    };

    // 清理通话UI状态
    DispatcherControl.prototype.clearCallUI = function(sessionId) {
        // 清理直接呼叫状态
        if (sessionId) {
            var directCallStatus = $('#direct-call-status-' + sessionId);
            if (directCallStatus.length > 0) {
                directCallStatus.remove();
                console.log('已清除直接呼叫状态栏:', sessionId);
            }
        } else {
            // 如果没有sessionId，尝试查找所有直接呼叫状态栏并清理
            $('.call-status-bar').each(function() {
                var statusId = $(this).attr('id');
                if (statusId && statusId.startsWith('direct-call-status-')) {
                    $(this).remove();
                    console.log('已清除直接呼叫状态栏:', statusId);
                }
            });
        }
        
        // 清理组呼状态
        if (this.groupCallSessions && this.groupCallSessions.length === 0) {
            $('#group-call-status').hide();
            if (this.timers.groupCall) {
                clearInterval(this.timers.groupCall);
                this.timers.groupCall = null;
            }
        }
        
        // 清理会议状态
        if (this.conferenceParticipants && this.conferenceParticipants.length === 0) {
            $('#conference-status').hide();
            if (this.timers.conference) {
                clearInterval(this.timers.conference);
                this.timers.conference = null;
            }
        }
    };

    // 显示通话错误
    DispatcherControl.prototype.showCallError = function(message) {
        // 可以使用更友好的错误提示方式
        if (typeof toastr !== 'undefined') {
            toastr.error(message);
        } else {
            if (window.DispatcherUtils && typeof DispatcherUtils.alert === 'function') { DispatcherUtils.alert(message, 'error') }
        }
    };

    DispatcherControl.prototype.startEmergencyCall = function(type, targets, emergencyUuid) {
        var self = this;
        if (!this.sipClient || !this.sipClient.isRegistered) {
            if (window.DispatcherUtils && typeof DispatcherUtils.alert === 'function') { DispatcherUtils.alert('请先注册SIP', 'warn'); }
            return;
        }
        if (!targets || !targets.length) {
            return;
        }
        var serverHost = this.getServerHost();
        targets.forEach(function(ext) {
            var target = 'sip:' + ext + '@' + serverHost;
            self.sipClient.makeCall(target, { audio: true, video: false, emergency: true, emergencyUuid: emergencyUuid })
                .then(function(result) {
                    var session = result && result.session;
                    var sessionId = result && result.sessionId;
                    if (session && sessionId) {
                        self.emergencySessions[sessionId] = true;
                        if (typeof self.monitorEmergencyCall === 'function') {
                            // 将目标分机号一并传入，便于在事件回调中更新急呼面板状态
                            self.monitorEmergencyCall(session, emergencyUuid, ext);
                        }
                        // 网页调度端作为主叫发起急呼时，不在本端播放蜂鸣报警，仅记录占用关系
                        try {
                            if (window.DispatcherUtils && DispatcherUtils.ResourceManager && self.config && self.config.authUser) {
                                DispatcherUtils.ResourceManager.setEmergencyOwner(sessionId, self.config.authUser);
                            }
                        } catch (e) {}
                    }
                })
                .catch(function(error) {
                    console.error('发起急呼失败:', error);
                    if (typeof window.updateEmergencyCallStatus === 'function') {
                        window.updateEmergencyCallStatus(ext, 'failed');
                    }
                    $.post('dispatcher_api.php', {
                        action: 'log_emergency_call',
                        emergency_uuid: emergencyUuid,
                        status: 'failed'
                    });
                });
        });
    };


    // 添加急呼状态监控
    DispatcherControl.prototype.monitorEmergencyCall = function(session, emergencyUuid, targetExt) {
        var self = this;
        
	        session.on('accepted', function(e) {
	            console.log('🚨 急呼已接听', e);
	            try { self.startEmergencyRecording(session._customId || ''); } catch (recordError) {}
	            // 记录接听状态
	            $.post('dispatcher_api.php', {
	                action: 'log_emergency_call',
                emergency_uuid: emergencyUuid,
                call_uuid: session._customId,
                status: 'answered'
            });
            
            // 更新网页状态
            self.updateCallStatus('connected', session._customId, '急呼已接听');
            // 急呼一旦被接听，立即停止本地蜂鸣/语音提示
            try {
                if (window.DispatcherUtils && DispatcherUtils.ResourceManager) {
                    DispatcherUtils.ResourceManager.stopEmergencyTone(session._customId);
                }
            } catch (e) {}

            // 同步更新急呼面板中对应分机的状态
            try {
                if (targetExt && typeof window.updateEmergencyCallStatus === 'function') {
                    window.updateEmergencyCallStatus(targetExt, 'answered');
                }
            } catch (e) {}
        });
        
        session.on('confirmed', function(e) {
            console.log('🚨 急呼已确认', e);
            // 记录确认状态
            $.post('dispatcher_api.php', {
                action: 'log_emergency_call',
                emergency_uuid: emergencyUuid,
                call_uuid: session._customId,
                status: 'confirmed'
            });
            // 双保险：在 confirmed 阶段再次尝试停止蜂鸣器
            try {
                if (window.DispatcherUtils && DispatcherUtils.ResourceManager) {
                    DispatcherUtils.ResourceManager.stopEmergencyTone(session._customId);
                }
            } catch (e) {}
        });
        
        session.on('ended', function(e) {
            console.log('🚨 急呼已结束', e);
            // 记录结束状态
            $.post('dispatcher_api.php', {
                action: 'log_emergency_call',
                emergency_uuid: emergencyUuid,
                status: 'completed'
            });
            
            // 更新网页状态
            self.updateCallStatus('ended', session._customId, '急呼已结束');
            
            // 隐藏急呼状态栏
            if (typeof window.hideEmergencyStatus === 'function') {
                window.hideEmergencyStatus();
            }

            // 会话结束后，确保停止蜂鸣并释放占用标记
            try {
                if (window.DispatcherUtils && DispatcherUtils.ResourceManager) {
                    DispatcherUtils.ResourceManager.stopEmergencyTone(session._customId);
                    DispatcherUtils.ResourceManager.releaseEmergencyOwner(session._customId);
                }
            } catch (e) {}
        });
        
        session.on('failed', function(e) {
            console.log('🚨 急呼失败', e);
            // 记录失败状态
            $.post('dispatcher_api.php', {
                action: 'log_emergency_call',
                emergency_uuid: emergencyUuid,
                status: 'failed'
            });
            
            // 更新网页状态
            self.updateCallStatus('failed', session._customId, '急呼失败');
            
            // 隐藏急呼状态栏
            if (typeof window.hideEmergencyStatus === 'function') {
                window.hideEmergencyStatus();
            }

            // 失败场景同样需要停止蜂鸣并释放占用
            try {
                if (window.DispatcherUtils && DispatcherUtils.ResourceManager) {
                    DispatcherUtils.ResourceManager.stopEmergencyTone(session._customId);
                    DispatcherUtils.ResourceManager.releaseEmergencyOwner(session._customId);
                }
            } catch (e) {}

            // 失败时也同步更新急呼面板状态
            try {
                if (targetExt && typeof window.updateEmergencyCallStatus === 'function') {
                    window.updateEmergencyCallStatus(targetExt, 'failed');
                }
            } catch (e) {}
        });
    };

    // 导出到全局
    window.DispatcherControl = DispatcherControl;

})(window, jQuery);
window.DispatcherControl.prototype.handleSipError = function(code, sessionId, context){
        try{
            var c = parseInt(code || 0, 10)
            if (c === 480){
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyTone(sessionId) }catch(e){}
                try{ if (this.sipClient && this.sipClient.hangupById) this.sipClient.hangupById(sessionId).catch(function(){}) }catch(e){}
                try{ if (window.DispatcherUtils) DispatcherUtils.toast('主叫挂断 (480)', 'warn') }catch(e){}
            } else if (c === 503){
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyToneAll() }catch(e){}
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.closeAllVoiceSessions() }catch(e){}
                try{ this.onUnregistered() }catch(e){}
                try{ if (window.DispatcherUtils) DispatcherUtils.alert('网络中断 (503)', 'error') }catch(e){}
            } else if (c === 408){
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyTone(sessionId) }catch(e){}
                try{ if (window.DispatcherUtils) DispatcherUtils.toast('紧急呼叫超时 (408)', 'warn') }catch(e){}
            } else if (c === 487){
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyTone(sessionId) }catch(e){}
                try{ this.cleanupIncomingUI(sessionId) }catch(e){}
                try{ if (window.DispatcherUtils) DispatcherUtils.toast('请求终止 (487)', 'warn') }catch(e){}
            } else if (c >= 400 && c < 600){
                try{ if (window.DispatcherUtils && DispatcherUtils.ResourceManager) DispatcherUtils.ResourceManager.stopEmergencyTone(sessionId) }catch(e){}
                try{ if (window.DispatcherUtils) DispatcherUtils.toast('SIP错误 '+c, 'error') }catch(e){}
            }
            try{ if (window.dispatcherLogger && typeof dispatcherLogger.logError === 'function'){ dispatcherLogger.logError('sip_error', { code:c||null, sessionId:sessionId||'', context:context||'' }) } }catch(e){}
        }catch(e){}
    };
