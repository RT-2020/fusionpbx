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
            self.emit('unregistered');
        });
        
        this.sipClient.on('registrationFailed', function(error) {
            console.error('调度员注册失败', error);
            self.emit('registrationFailed', error);
        });

        this.sipClient.on('incomingCall', function(callerInfo) {
            self.onIncomingCall(callerInfo);
        });

        this.sipClient.on('callEstablished', function(data) {
            self.onCallEstablished(data);
        });

        this.sipClient.on('callEnded', function(data) {
            self.onCallEnded(data);
        });

        this.sipClient.on('callFailed', function(data) {
            self.onCallFailed(data);
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

    // 事件回调
    DispatcherControl.prototype.onRegistered = function() {
        console.log('调度员已注册');
        this.updateUI();
        // 触发注册成功事件
        this.emit('registered');
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
    DispatcherControl.prototype.startGroupCallWithExtensions = function(extensions) {
        var self = this;
        
        if (!this.sipClient || !this.sipClient.isRegistered) {
            alert('请先注册SIP');
            return;
        }
        
        if (extensions.length === 0) {
            alert('分机列表为空');
            return;
        }
        
        this.groupCallActive = true;
        this.groupCallSessions = [];
        this.groupCallStartTime = Date.now();
        
        console.log('发起组呼，呼叫分机:', extensions);
        
        var serverHost = this.getServerHost();
        
        extensions.forEach(function(extension) {
            var target = 'sip:' + extension + '@' + serverHost;
            
            self.sipClient.makeCall(target, { audio: true, video: false })
                .then(function(session) {
                    self.groupCallSessions.push({
                        extension: extension,
                        session: session,
                        status: 'calling'
                    });
                    console.log('组呼：呼叫分机', extension);
                })
                .catch(function(error) {
                    console.error('组呼：呼叫分机失败', extension, error);
                });
        });
        
        this.startGroupCallTimer();
        this.updateUI();
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
    DispatcherControl.prototype.startBroadcastCall = function() {
        var self = this;
        
        // 获取所有在线分机
        this.getAllExtensions(function(allExtensions) {
            if (allExtensions.length === 0) {
                alert('没有可用的分机');
                return;
            }

            console.log('发起全呼，目标数量:', allExtensions.length);

            self.groupCallActive = true;
            self.groupCallSessions = [];

            var serverHost = self.getServerHost();
            var targets = allExtensions.map(function(ext) {
                return 'sip:' + ext + '@' + serverHost;
            });

            // 批量拨打
            self.sipClient.makeCallBatch(targets, { audio: true, video: false })
                .then(function(results) {
                    console.log('全呼已发起');
                    self.groupCallSessions = results;
                    
                    // 显示全呼控制面板
                    self.showGroupCallPanel('全体成员', results);
                    self.updateUI();
                })
                .catch(function(error) {
                    console.error('全呼失败:', error);
                    alert('全呼失败: ' + error.message);
                    self.groupCallActive = false;
                });
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
                    participants: self.groupCallSessions.map(function(s) { 
                        return s.sessionId; 
                    }),
                    status: 'completed'
                });
            }
            
            // 清除计时器
            if (self.timers.groupCall) {
                clearInterval(self.timers.groupCall);
                self.timers.groupCall = null;
            }
            
            this.sipClient.hangupAll()
                .then(function() {
                    console.log('组呼已结束');
                    self.groupCallActive = false;
                    self.groupCallSessions = [];
                    self.groupCallStartTime = null;
                    $('#dispatcher-group-call-panel').hide();
                    self.updateUI();
                })
                .catch(function(error) {
                    console.error('结束组呼失败:', error);
                });
        }
    };

    // ============ 多方会议功能 ============

    // 发起会议
    DispatcherControl.prototype.startConference = function(participants) {
        var self = this;
        
        if (!participants || participants.length < 2) {
            alert('至少需要2个参与者');
            return;
        }

        console.log('发起会议，参与者:', participants);

        this.conferenceActive = true;
        this.conferenceParticipants = [];

        var serverHost = this.getServerHost();
        var targets = participants.map(function(ext) {
            return 'sip:' + ext + '@' + serverHost;
        });

        // 批量拨打
        this.sipClient.makeCallBatch(targets, { audio: true, video: false })
            .then(function(results) {
                console.log('会议已发起');
                
                self.conferenceStartTime = new Date();
                
                results.forEach(function(result, index) {
                    self.conferenceParticipants.push({
                        extension: participants[index],
                        sessionId: result.sessionId,
                        session: result.session,
                        muted: false,
                        joinTime: new Date()
                    });
                });
                
                // 显示会议控制面板
                self.showConferencePanel();
                
                // 启动计时器
                self.startConferenceTimer();
                
                self.updateUI();
            })
            .catch(function(error) {
                console.error('会议发起失败:', error);
                alert('会议发起失败: ' + error.message);
                self.conferenceActive = false;
            });
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

    // 结束会议
    DispatcherControl.prototype.endConference = function() {
        var self = this;
        
        if (confirm('确定要结束会议吗？')) {
            // 计算会议时长
            var duration = self.conferenceStartTime ? 
                Math.floor((new Date() - self.conferenceStartTime) / 1000) : 0;
            
            // 记录日志
            if (typeof logCallEnd === 'function') {
                logCallEnd('conference', {
                    duration: duration,
                    participants: self.conferenceParticipants.map(function(p) { 
                        return p.extension; 
                    }),
                    status: 'completed'
                });
            }
            
            // 清除计时器
            if (self.timers.conference) {
                clearInterval(self.timers.conference);
                self.timers.conference = null;
            }
            
            this.sipClient.hangupAll()
                .then(function() {
                    console.log('会议已结束');
                    self.conferenceActive = false;
                    self.conferenceParticipants = [];
                    self.conferenceStartTime = null;
                    $('#dispatcher-conference-panel').hide();
                    self.updateUI();
                })
                .catch(function(error) {
                    console.error('结束会议失败:', error);
                });
        }
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

    // 导出到全局
    window.DispatcherControl = DispatcherControl;

})(window, jQuery);

