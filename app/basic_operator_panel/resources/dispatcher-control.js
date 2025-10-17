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
            self.updateCallStatus('ended', data.sessionId, '通话已结束');
            self.onCallEnded(data);
        });

        this.sipClient.on('callFailed', function(data) {
            var errorMessage = '通话失败';
            if (data.error && data.error.message) {
                errorMessage = data.error.message;
            }
            self.updateCallStatus('failed', data.sessionId, errorMessage);
            self.onCallFailed(data);
        });

        this.sipClient.on('callEstablished', function(data) {
            self.updateCallStatus('connected', data.sessionId, '通话已建立');
        });

        this.sipClient.on('incomingCall', function(data) {
            // 若设置了一次性自动接听标记（用于 three-way 插入讲话支路），自动接听并清理标记
            try {
                if (window.__autoAnswerBargeNext === true) {
                    console.log('检测到自动接听标记，准备自动接听插入支路');
                    console.log('当前 incomingSession:', self.sipClient.incomingSession);
                    window.__autoAnswerBargeNext = false;
                    if (self.sipClient && self.sipClient.unlockAudioPlayback) {
                        self.sipClient.unlockAudioPlayback();
                    }
                    self.sipClient.acceptIncomingCall({ audio: true, video: false })
                        .then(function() {
                            console.log('自动接听插入支路成功');
                            // 自动接听成功后不弹提示
                            self.updateUI();
                        })
                        .catch(function(err){
                            console.error('自动接听失败:', err);
                            // 回退到正常来电处理
                            self.onIncomingCall(data);
                        });
                    return;
                }
            } catch (e) { /* 忽略 */ }
            self.onIncomingCall(data);
        });

        this.sipClient.on('callProgress', function(data) {
            self.onCallProgress(data);
        });

        // 加载保存的配置
        this.loadConfig();
        
        // 加载呼叫分组
        this.loadCallGroups();
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
                        self.config = response.data;
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
        // 触发注册成功事件
        this.emit('registered');
    };

    DispatcherControl.prototype.onUnregistered = function() {
        console.log('调度员已注销');
        this.emit('unregistered');
    };

    DispatcherControl.prototype.onRegistrationFailed = function(error) {
        console.error('调度员注册失败', error);
        this.emit('registrationFailed', error);
    };

    DispatcherControl.prototype.onIncomingCall = function(callerInfo) {
        console.log('收到来电:', callerInfo);
        
        // 检查是否是中继呼叫（根据号码前缀判断，可配置）
        if (this.isTrunkNumber(callerInfo.uri)) {
            this.handleTrunkIncomingCall(callerInfo);
        } else {
            this.handleNormalIncomingCall(callerInfo);
        }
        
        this.updateUI();
    };

    DispatcherControl.prototype.onCallEstablished = function(data) {
        console.log('通话已建立:', data);
        this.updateUI();
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
    };

    DispatcherControl.prototype.onCallFailed = function(data) {
        console.error('通话失败:', data);
        this.updateUI();
    };

    DispatcherControl.prototype.onCallProgress = function(data) {
        console.log('通话进度:', data);
        // 可以根据需要处理通话进度事件
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

        $('#dispatcher-alerts').html(html).show();
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

        $('#dispatcher-alerts').html(html).show();
    };

    // 接受中继呼叫（人工）
    DispatcherControl.prototype.acceptTrunkCall = function() {
        var self = this;
        
        this.sipClient.acceptIncomingCall({ audio: true, video: false })
            .then(function(result) {
                console.log('中继呼叫已接听');
                
                // 添加到中继列表
                self.trunkCalls.push({
                    callId: result.sessionId,
                    session: result.session,
                    status: 'connected',
                    fromNumber: self.sipClient.incomingCallerInfo.uri,
                    timestamp: new Date()
                });
                
                // 显示桥接选项
                self.showTrunkBridgeOptions(result.sessionId);
                self.updateUI();
            })
            .catch(function(error) {
                console.error('接听中继呼叫失败:', error);
                alert('接听失败: ' + error.message);
            });
    };

    // 显示桥接选项
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
            alert('请输入目标号码');
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
                            alert('桥接成功，调度员已退出');
                            
                            // 更新中继状态
                            self.trunkCalls = self.trunkCalls.filter(function(call) {
                                return call.callId !== trunkSessionId;
                            });
                            
                            self.updateUI();
                        })
                        .catch(function(error) {
                            console.error('桥接失败:', error);
                            alert('桥接失败: ' + error.message);
                        });
                }, 3000); // 等待3秒确保目标接通
            })
            .catch(function(error) {
                console.error('呼叫目标失败:', error);
                alert('呼叫目标失败: ' + error.message);
            });
    };

    // 保留中继呼叫
    DispatcherControl.prototype.holdTrunkCall = function(sessionId) {
        var self = this;
        
        this.sipClient.hold(sessionId)
            .then(function() {
                console.log('中继呼叫已保留');
                alert('呼叫已保留');
                
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
                alert('保留失败: ' + error.message);
            });
    };

    // 处理自动中继
    DispatcherControl.prototype.processAutoTrunk = function() {
        var self = this;
        var targetNumber = $('#auto-trunk-target').val();
        
        if (!targetNumber) {
            alert('请输入目标号码');
            return;
        }

        // 接听中继来电
        this.sipClient.acceptIncomingCall({ audio: true, video: false })
            .then(function(result) {
                // 立即转接到目标
                return self.sipClient.transfer('sip:' + targetNumber + '@' + self.getServerHost(), result.sessionId);
            })
            .then(function() {
                console.log('自动中继转接成功');
                alert('自动转接成功');
                $('#dispatcher-alerts').hide();
                self.updateUI();
            })
            .catch(function(error) {
                console.error('自动中继失败:', error);
                alert('自动中继失败: ' + error.message);
            });
    };

    // 拒绝中继呼叫
    DispatcherControl.prototype.rejectTrunkCall = function() {
        var self = this;
        
        this.sipClient.rejectIncomingCall()
            .then(function() {
                console.log('已拒绝中继呼叫');
                $('#dispatcher-alerts').hide();
                self.updateUI();
            })
            .catch(function(error) {
                console.error('拒绝失败:', error);
            });
    };

    // 挂断中继呼叫
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
    DispatcherControl.prototype.handleNormalIncomingCall = function(callerInfo) {
        var html = '<div class="incoming-call">' +
            '<h3>🔔 来电</h3>' +
            '<p><strong>来电号码:</strong> ' + callerInfo.uri + '</p>' +
            '<p><strong>来电者:</strong> ' + callerInfo.name + '</p>' +
            '<div class="call-actions">' +
            '<button onclick="dispatcherControl.acceptCall()" class="btn-accept">接听</button>' +
            '<button onclick="dispatcherControl.rejectCall()" class="btn-reject">拒绝</button>' +
            '</div>' +
            '</div>';

        $('#dispatcher-alerts').html(html).show();
    };

    // 接听普通来电
    DispatcherControl.prototype.acceptCall = function() {
        var self = this;
        
        this.sipClient.acceptIncomingCall({ audio: true, video: false })
            .then(function() {
                $('#dispatcher-alerts').hide();
                self.updateUI();
            })
            .catch(function(error) {
                alert('接听失败: ' + error.message);
            });
    };

    // 拒绝来电
    DispatcherControl.prototype.rejectCall = function() {
        var self = this;
        
        this.sipClient.rejectIncomingCall()
            .then(function() {
                $('#dispatcher-alerts').hide();
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
            alert('组不存在');
            return;
        }

        var group = this.callGroups[groupId];
        if (!group.extensions || group.extensions.length === 0) {
            alert('该组没有成员');
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
                alert('组呼失败: ' + error.message);
                self.groupCallActive = false;
            });
    };

    // 使用指定分机列表发起组呼
    // 使用会议桥模式发起组呼
    DispatcherControl.prototype.startGroupCallWithExtensions = function(extensions) {
        var self = this;
        
        if (!this.sipClient || !this.sipClient.isRegistered) {
            alert('请先注册SIP');
            return;
        }
        
        if (extensions.length === 0) {
            alert('请至少选择一个分机');
            return;
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
                    alert(errorMsg);
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
                
                alert(errorMsg);
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
                        alert('调度员加入会议失败: ' + (response.error || '未知错误'));
                        reject(new Error(response.error || '加入会议失败'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ 调度员加入会议请求失败:', error);
                    alert('调度员加入会议请求失败: ' + error);
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
                alert('没有可用的分机');
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
        alert('该成员已被禁言');
    };

    // 允许某成员发言
    DispatcherControl.prototype.unmuteGroupMember = function(sessionId) {
        this.sipClient.unmuteSession(sessionId);
        console.log('成员可以发言:', sessionId);
        alert('该成员可以发言');
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
			alert('至少需要2个参与者');
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
                alert('添加失败: ' + error.message);
            });
    };

	// 结束会议（会议桥模式下同时结束服务器侧会议）
	DispatcherControl.prototype.endConference = function() {
		var self = this;
		if (!confirm('确定要结束会议吗？')) return;
		// 计算会议时长
		var duration = self.conferenceStartTime ? Math.floor((new Date() - self.conferenceStartTime) / 1000) : 0;
		// 记录日志
		if (typeof logCallEnd === 'function') {
			var participantsList = Array.isArray(self.conferenceParticipants)
				? self.conferenceParticipants.map(function(p) { return p.extension || p.caller_id_number; })
				: [];
			logCallEnd('conference', { duration: duration, participants: participantsList, status: 'completed' });
		}
		// 停止轮询
		if (self.memberPollInterval) {
			clearInterval(self.memberPollInterval);
			self.memberPollInterval = null;
		}
		// 清除计时器
		if (self.timers.conference) {
			clearInterval(self.timers.conference);
			self.timers.conference = null;
		}
		// 通知后端结束会议桥
		if (self.conferenceRoom) {
			$.ajax({
				url: 'dispatcher_conference_api.php',
				type: 'POST',
				data: { action: 'end_conference', conference_room: self.conferenceRoom }
			}).always(function() {
				self.conferenceRoom = null;
			});
		}
		// 挂断本地会话
		this.sipClient.hangupAll()
			.then(function() {
				self.conferenceActive = false;
				self.conferenceParticipants = [];
				self.conferenceStartTime = null;
				$('#dispatcher-conference-panel').hide();
				$('#conference-status').hide();
				self.updateUI();
			})
			.catch(function(error) { console.error('结束会议失败:', error); });
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
            alert('不能删除默认组');
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

        // 更新通话状态
        var sessionCount = Object.keys(this.sipClient.sessions).length;
        $('#dispatcher-call-count').text(sessionCount);

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
    };

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
            alert(message);
        }
    };

    // 导出到全局
    window.DispatcherControl = DispatcherControl;

})(window, jQuery);

