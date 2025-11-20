/**
 * JsSIP 客户端封装
 * 提供 SIP 注册、呼叫、多方会议等功能
 */
;(function (window) {
  'use strict'

  // JsSIP 客户端类
  function JsSipClient() {
    this.ua = null
    this.sessions = {} // 存储所有活动会话 {sessionId: session}
    this.currentSession = null // 当前主会话
    this.incomingSession = null // 来电会话
    this.conferenceSessions = [] // 会议会话列表
    this.audioElements = {}
    this._micStream = null
    this.incomingSessions = {}
    this.resourceStats = { releases: 0, timeouts: 0, forced: 0, lastOps: [] }
    this.iceServers = [
      { urls: ['stun:stun.l.google.com:19302'] },
      { urls: ['stun:stun1.l.google.com:19302'] },
    ]

    // 状态标志
    this.isRegistered = false
    this.isCalling = false
    this.isCallEstablished = false
    this.isHeld = false
    this.isMuted = false
    this.hasIncomingCall = false
    this.incomingCallerInfo = null

    // 回调函数
    this.callbacks = {
      onRegistered: null,
      onUnregistered: null,
      onRegistrationFailed: null,
      onIncomingCall: null,
      onCallEstablished: null,
      onCallEnded: null,
      onCallFailed: null,
      onCallProgress: null,
    }
  }

  // 计算 WebRTC pcConfig（支持内网/公网自适应）
  JsSipClient.prototype._getPcConfig = function () {
    // 默认禁用 STUN（内网环境更快、更稳定）
    var useStun = true
    try {
      // 允许通过 localStorage 打开回退 STUN（dispatcher_use_stun=1）
      useStun = localStorage.getItem('dispatcher_use_stun') === '1'
      // 若主机非内网地址，自动启用 STUN
      var host = location.hostname || ''
      var isPrivate =
        host === 'localhost' ||
        host === '127.0.0.1' ||
        host.startsWith('10.') ||
        /^192\.168\./.test(host) ||
        /^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(host)
      if (!isPrivate) useStun = true
    } catch (e) {}

    if (useStun) {
      return {
        iceServers: this.iceServers,
      }
    }

    return { iceServers: [] }
  }

  // 设置回调
  JsSipClient.prototype.on = function (event, callback) {
    if (
      this.callbacks.hasOwnProperty(
        'on' + event.charAt(0).toUpperCase() + event.slice(1)
      )
    ) {
      this.callbacks['on' + event.charAt(0).toUpperCase() + event.slice(1)] =
        callback
    }
  }

  // 触发回调
  JsSipClient.prototype.trigger = function (event, data) {
    var callbackName = 'on' + event.charAt(0).toUpperCase() + event.slice(1)
    if (
      this.callbacks[callbackName] &&
      typeof this.callbacks[callbackName] === 'function'
    ) {
      this.callbacks[callbackName](data)
    }
  }

  // 从 URI 解析主机
  JsSipClient.prototype.parseHostFromUri = function (uri) {
    var match = uri.match(/@([^;:>]+)/)
    return match ? match[1] : 'localhost'
  }

  // 注册 SIP
  JsSipClient.prototype.register = function (params) {
    var self = this

    return new Promise(function (resolve, reject) {
      if (self.ua) {
        self.unregister().then(function () {
          self._doRegister(params, resolve, reject)
        })
      } else {
        self._doRegister(params, resolve, reject)
      }
    })
  }

  // 执行注册
  JsSipClient.prototype._doRegister = function (params, resolve, reject) {
    var self = this
    console.log('执行注册', params)
    var host = this.parseHostFromUri(params.uri)

    // 检测是否使用 WSS，并显式设置 via_transport
    var isSecure = params.wsServers.toLowerCase().startsWith('wss://')
    var socketConfig = isSecure ? { via_transport: 'wss' } : {}

    console.log('WebSocket 配置:', {
      wsServers: params.wsServers,
      isSecure: isSecure,
      socketConfig: socketConfig,
    })

    var configuration = {
      sockets: [new JsSIP.WebSocketInterface(params.wsServers, socketConfig)],
      uri: params.uri,
      authorization_user: params.authUser,
      password: params.password,
      display_name: params.displayName || 'Dispatcher',
      session_timers: false,
      register: true,
      pcConfig: self._getPcConfig(),
    }

    console.log('创建 JsSIP UA，配置:', configuration)

    try {
      self.ua = new JsSIP.UA(configuration)
    } catch (error) {
      console.error('创建 UA 失败:', error)
      reject(error)
      return
    }

    // WebSocket 连接事件
    self.ua.on('connected', function (e) {
      console.log('✅ WebSocket 已连接', e)
    })

    self.ua.on('disconnected', function (e) {
      console.log('❌ WebSocket 已断开', e)
      self.isRegistered = false
      try {
        self._releaseAllAudio('ua_disconnected')
      } catch (er) {}
    })

    // 注册事件
    self.ua.on('registered', function (e) {
      console.log('✅ SIP 注册成功', e)
      self.isRegistered = true
      self.trigger('registered', e)
      resolve()
    })

    self.ua.on('unregistered', function (e) {
      console.log('📤 SIP 已注销', e)
      self.isRegistered = false
      self.trigger('unregistered', e)
    })

    self.ua.on('registrationFailed', function (e) {
      console.error('❌ SIP 注册失败', e)
      self.isRegistered = false
      self.trigger('registrationFailed', e)
      reject(
        new Error(
          '注册失败: ' + (e.cause || e.response?.status_code || '未知错误')
        )
      )
    })

    // 来电处理
    self.ua.on('newRTCSession', function (e) {
      var session = e.session
      console.log('📞 收到 RTC 会话', e)

      // 根据来电显示名称标记通话类型
      var callerIdName = session.remote_identity.display_name || ''
      var req = e.request
      var hasEmergencyHeader = false
      try {
        if (req && req.headers) {
          var ai = req.headers['Alert-Info']
          var xe = req.headers['X-Emergency-Call']
          if (
            ai &&
            ai[0] &&
            ai[0].raw &&
            ai[0].raw.indexOf('emergency') !== -1
          ) {
            hasEmergencyHeader = true
          }
          if (xe) {
            hasEmergencyHeader = true
          }
        }
      } catch (eh) {}
      if (
        callerIdName.indexOf('插入讲话') !== -1 ||
        callerIdName.indexOf('ThreeWay') !== -1 ||
        callerIdName.indexOf('Barge') !== -1
      ) {
        session._callType = 'three-way' // 三方通话
      } else if (
        callerIdName.indexOf('组呼会议') !== -1 ||
        callerIdName.indexOf('group-call') !== -1 ||
        callerIdName.indexOf('调度中心') !== -1 ||
        callerIdName.indexOf('dispatch') !== -1
      ) {
        session._callType = 'conference' // 会议/组呼
      } else if (
        callerIdName.indexOf('监听') !== -1 ||
        callerIdName.indexOf('Eavesdrop') !== -1 ||
        callerIdName.indexOf('Listen') !== -1
      ) {
        session._callType = 'eavesdrop' // 监听
      } else if (hasEmergencyHeader) {
        session._callType = 'emergency'
      } else {
        session._callType = 'normal' // 普通通话
      }
      console.log('🏷️ 通话类型:', session._callType, '来电显示:', callerIdName)

      // 处理来电
      if (session.direction === 'incoming') {
        console.log('📞 来电')
        self.incomingSession = session
        self.hasIncomingCall = true

        var remoteIdentity = session.remote_identity
        var callerUri = remoteIdentity?.uri?.toString() || 'Unknown'
        var callerName = remoteIdentity?.display_name || callerUri

        // 为来电分配会话ID并加入会话映射，支持多路来电并行处理
        try {
          if (!session._customId) {
            var sid =
              'session_' +
              Date.now() +
              '_' +
              Math.random().toString(36).substr(2, 9)
            session._customId = sid
          }
          self.sessions[session._customId] = session
          self.incomingSessions[session._customId] = true
        } catch (se) {}

        self.incomingCallerInfo = {
          name: callerName,
          uri: callerUri,
          sessionId: session._customId,
          isEmergency: !!hasEmergencyHeader,
        }

        console.log('来电者:', callerName, callerUri)
        self.trigger('incomingCall', self.incomingCallerInfo)

        // 监听来电的状态变化
        self.setupSessionListeners(session)
      } else {
        // 呼出电话
        console.log('📞 呼出会话')
      }
    })

    // 启动 UA
    console.log('启动 JsSIP UA...')
    self.ua.start()

    // 设置超时
    var timeout = setTimeout(function () {
      console.warn('⏰ 注册超时')
      reject(new Error('注册超时'))
    }, 30000)

    // 清理超时
    self.ua.once('registered', function () {
      clearTimeout(timeout)
    })
    self.ua.once('registrationFailed', function () {
      clearTimeout(timeout)
    })
  }

  // 设置会话监听器
  JsSipClient.prototype.setupSessionListeners = function (session) {
    var self = this

    session.on('peerconnection', function (e) {
      console.log('🔗 PeerConnection 已创建')
      var connection = e.peerconnection

      // 检测来电类型
      var callerIdName = session.remote_identity.display_name || ''
      var isThreeWay =
        callerIdName.indexOf('插入讲话') !== -1 ||
        callerIdName.indexOf('ThreeWay') !== -1 ||
        callerIdName.indexOf('Barge') !== -1
      var isConference =
        callerIdName.indexOf('组呼会议') !== -1 ||
        callerIdName.indexOf('group-call') !== -1 ||
        callerIdName.indexOf('调度中心') !== -1 ||
        callerIdName.indexOf('dispatch') !== -1

      // 只在监听模式时添加 recvonly transceiver
      // 三方通话和会议模式不添加，让 JsSIP 根据 mediaConstraints 自动创建 sendrecv
      // 仅监听模式添加 recvonly；普通/会议/三方保持 sendrecv
      if (session._callType === 'eavesdrop') {
        try {
          if (connection && typeof connection.addTransceiver === 'function') {
            connection.addTransceiver('audio', { direction: 'recvonly' })
            console.log('🎚️ 监听模式：添加 recvonly 音频 transceiver')
          }
        } catch (err) {
          console.warn('添加音频 transceiver 失败:', err)
        }
      }

      // ICE 与连接状态日志，便于诊断无声问题
      try {
        connection.oniceconnectionstatechange = function () {
          console.log('ICE 状态:', connection.iceConnectionState)
          // 失败时尝试一次 ICE 重启
          if (
            connection.iceConnectionState === 'failed' &&
            typeof connection.restartIce === 'function'
          ) {
            try {
              connection.restartIce()
              console.log('🔁 已触发 ICE 重启')
            } catch (reErr) {
              console.warn('ICE 重启失败:', reErr)
            }
          }
        }
        connection.onconnectionstatechange = function () {
          console.log('PeerConnection 状态:', connection.connectionState)
        }
        connection.onsignalingstatechange = function () {
          console.log('Signaling 状态:', connection.signalingState)
        }
        connection.onicegatheringstatechange = function () {
          console.log('ICE Gathering 状态:', connection.iceGatheringState)
        }
        connection.onicecandidate = function (ev) {
          if (ev && ev.candidate) {
            console.log(
              'ICE 候选:',
              ev.candidate.type || ev.candidate.candidate
            )
          } else {
            console.log('ICE 候选收集完成')
          }
        }
      } catch (err) {
        console.warn('绑定连接状态日志失败:', err)
      }

      // 立即设置 ontrack 监听器（在媒体轨道到达前）
      connection.ontrack = function (event) {
        console.log('收到媒体轨道', event.track.kind, event)

        if (event.track.kind === 'audio') {
          var stream = event.streams[0] || new MediaStream([event.track])
          var sessionId = session._customId || 'default'

          // 创建或获取audio元素
          if (!self.audioElements[sessionId]) {
            var audio = document.createElement('audio')
            audio.autoplay = true
            audio.id = 'remote-audio-' + sessionId
            // 确保音频元素有正确的属性
            audio.controls = false
            audio.muted = false // 确保不是静音状态
            audio.volume = 1.0 // 设置最大音量
            // 兼容移动端内联播放，避免系统接管
            audio.playsInline = true
            audio.setAttribute('playsinline', 'true')
            audio.setAttribute('webkit-playsinline', 'true')
            audio.setAttribute('x5-playsinline', 'true')
            document.body.appendChild(audio) // 附加到DOM
            self.audioElements[sessionId] = audio
            console.log('🔊 创建并附加audio元素到DOM:', audio.id)
          }

          var audio = self.audioElements[sessionId]
          audio.srcObject = stream

          // 确保音频元素已加载并尝试播放
          audio.load()

          // 如果用户设置了输出设备，尝试路由到该设备
          try {
            var sinkId = localStorage.getItem(
              'dispatcher_audio_output_device_id'
            )
            if (sinkId && typeof audio.setSinkId === 'function') {
              audio
                .setSinkId(sinkId)
                .then(function () {
                  console.log('🔈 输出设备已设置为:', sinkId)
                })
                .catch(function (err) {
                  console.warn('设置输出设备失败:', err)
                })
            }
          } catch (eSink) {
            console.warn('设置输出设备时异常:', eSink)
          }

          // 使用用户交互来启动音频播放
          var playAudio = function () {
            audio
              .play()
              .then(function () {
                console.log('🔊 音频播放成功')
              })
              .catch(function (err) {
                console.error('播放失败:', err)
                // 尝试创建一个临时的用户交互事件
                var clickEvent = new MouseEvent('click', {
                  bubbles: true,
                  cancelable: true,
                  view: window,
                })
                document.body.dispatchEvent(clickEvent)
              })
          }

          // 尝试立即播放
          playAudio()

          // 如果立即播放失败，尝试延迟播放
          setTimeout(playAudio, 100)
        }
      }
    })

    // 通话进展
    session.on('progress', function (e) {
      console.log('📞 通话进展', e)
      self.trigger('callProgress', e)
    })

    // 通话接受
    session.on('accepted', function (e) {
      console.log('✅ 通话已接受', e)
    })

    // 通话确认（建立）
    session.on('confirmed', function (e) {
      console.log('✅ 通话已建立', e)
      self.isCallEstablished = true
      self.isCalling = true
      self.trigger('callEstablished', { session: session })

      // 检查是否为三方通话或会议，记录 transceiver 信息用于调试
      var callerIdName = session.remote_identity.display_name || ''
      var isThreeWay =
        callerIdName.indexOf('插入讲话') !== -1 ||
        callerIdName.indexOf('ThreeWay') !== -1 ||
        callerIdName.indexOf('Barge') !== -1
      var isConference =
        callerIdName.indexOf('组呼会议') !== -1 ||
        callerIdName.indexOf('group-call') !== -1 ||
        callerIdName.indexOf('调度中心') !== -1 ||
        callerIdName.indexOf('dispatch') !== -1

      if (
        (isThreeWay || isConference) &&
        session.connection &&
        session.connection.getTransceivers
      ) {
        var callMode = isThreeWay ? '三方通话' : '会议'
        console.log('📡 ' + callMode + '已建立，检查 transceiver 状态:')
        var transceivers = session.connection.getTransceivers()
        transceivers.forEach(function (transceiver, idx) {
          if (
            transceiver.receiver &&
            transceiver.receiver.track &&
            transceiver.receiver.track.kind === 'audio'
          ) {
            console.log(
              '  Audio Transceiver #' +
                idx +
                ': direction=' +
                transceiver.direction +
                ', currentDirection=' +
                transceiver.currentDirection
            )
          }
        })

        // 检查发送器状态
        var senders = session.connection.getSenders()
        senders.forEach(function (sender, idx) {
          if (sender.track && sender.track.kind === 'audio') {
            console.log(
              '  Audio Sender #' +
                idx +
                ': track.enabled=' +
                sender.track.enabled +
                ', track.muted=' +
                sender.track.muted
            )
          }
        })
      }

      // confirmed时也尝试绑定（双重保险）
      if (session.connection && !session.connection.ontrack) {
        self.bindMedia(session)
      }

      // 确保音频流在通话建立后也能正确处理
      if (session.connection && session.connection.getReceivers) {
        var receivers = session.connection.getReceivers()
        if (receivers.length > 0) {
          console.log('🔊 检查音频接收器:', receivers.length)
          var audioTracks = []
          receivers.forEach(function (receiver) {
            if (receiver.track && receiver.track.kind === 'audio') {
              console.log('🎵 找到音频轨道:', receiver.track)

              // 确保音频轨道已启用
              if (receiver.track.enabled === false) {
                receiver.track.enabled = true
                console.log('🔊 已启用音频轨道')
              }
              audioTracks.push(receiver.track)
            }
          })

          // 如果 ontrack 未及时触发，则直接用 receivers 组装并播放远端音频
          if (audioTracks.length > 0) {
            var sessionId = session._customId || 'default'
            if (!self.audioElements[sessionId]) {
              var audio = document.createElement('audio')
              audio.autoplay = true
              audio.id = 'remote-audio-' + sessionId
              audio.controls = false
              audio.muted = false
              audio.volume = 1.0
              // 兼容移动端内联播放
              audio.playsInline = true
              audio.setAttribute('playsinline', 'true')
              audio.setAttribute('webkit-playsinline', 'true')
              audio.setAttribute('x5-playsinline', 'true')
              document.body.appendChild(audio)
              self.audioElements[sessionId] = audio
              console.log('🔊(receivers) 创建audio元素:', audio.id)
            }
            var audioEl = self.audioElements[sessionId]
            var stream = new MediaStream(audioTracks)
            audioEl.srcObject = stream
            audioEl.load()

            // 如果用户设置了输出设备，尝试路由到该设备
            try {
              var sinkId = localStorage.getItem(
                'dispatcher_audio_output_device_id'
              )
              if (sinkId && typeof audioEl.setSinkId === 'function') {
                audioEl
                  .setSinkId(sinkId)
                  .then(function () {
                    console.log('🔈 输出设备已设置为:', sinkId)
                  })
                  .catch(function (err) {
                    console.warn('设置输出设备失败:', err)
                  })
              }
            } catch (eSink) {
              console.warn('设置输出设备时异常:', eSink)
            }

            var play = function () {
              audioEl
                .play()
                .then(function () {
                  console.log('🔊(receivers) 音频播放成功')
                })
                .catch(function (err) {
                  console.error('(receivers) 播放失败:', err)
                })
            }
            play()
            setTimeout(play, 100)
          }
        }
      }

      // 检查发送器（本地音频）
      if (session.connection && session.connection.getSenders) {
        var senders = session.connection.getSenders()
        if (senders.length > 0) {
          console.log('🎤 检查音频发送器:', senders.length)
          senders.forEach(function (sender) {
            if (sender.track && sender.track.kind === 'audio') {
              console.log('🎤 找到本地音频轨道:', sender.track)

              // 确保本地音频轨道已启用
              if (sender.track.enabled === false) {
                sender.track.enabled = true
                console.log('🔊 已启用本地音频轨道')
              }
            }
          })
        }
      }
    })

    // 通话结束
    session.on('ended', function (e) {
      var sid = session && session._customId
      if (sid && self.sessions[sid]) {
        delete self.sessions[sid]
      }
      if (sid && self.incomingSessions[sid]) {
        delete self.incomingSessions[sid]
      }
      try {
        self._cleanupSessionMedia(session)
      } catch (er) {}
      self.endCall(session)
      self.trigger('callEnded', { session: session })
    })

    // 通话失败
    session.on('failed', function (e) {
      var sid = session && session._customId
      if (sid && self.sessions[sid]) {
        delete self.sessions[sid]
      }
      if (sid && self.incomingSessions[sid]) {
        delete self.incomingSessions[sid]
      }
      try {
        self._cleanupSessionMedia(session)
      } catch (er) {}
      self.endCall(session)
      self.trigger('callFailed', { session: session, error: e })
    })

    // Hold 状态
    session.on('hold', function (e) {
      console.log('📞 通话保持', e)
      if (e.originator === 'local') {
        self.isHeld = true
      }
    })

    session.on('unhold', function (e) {
      console.log('📞 通话恢复', e)
      if (e.originator === 'local') {
        self.isHeld = false
      }
    })

    // Mute 状态
    session.on('muted', function (e) {
      console.log('🔇 已静音', e)
    })

    session.on('unmuted', function (e) {
      console.log('🔊 已取消静音', e)
    })
  }

  // 绑定媒体流
  JsSipClient.prototype.bindMedia = function (session) {
    var self = this
    var connection = session.connection

    if (!connection) {
      console.warn('⚠️ 没有 RTCPeerConnection')
      return
    }

    // 处理远程音频流
    connection.ontrack = function (event) {
      console.log('📡 收到媒体轨道', event.track.kind, event)

      if (event.track.kind === 'audio') {
        var stream = event.streams.length
          ? event.streams[0]
          : new MediaStream([event.track])
        var sessionId = session._customId || 'default'

        // 创建或获取audio元素
        if (!self.audioElements[sessionId]) {
          var audio = document.createElement('audio')
          audio.autoplay = true
          audio.id = 'remote-audio-' + sessionId
          // 确保音频元素有正确的属性
          audio.controls = false
          audio.muted = false // 确保不是静音状态
          audio.volume = 1.0 // 设置最大音量
          document.body.appendChild(audio) // 附加到DOM
          self.audioElements[sessionId] = audio
          console.log('🔊 创建并附加audio元素到DOM:', audio.id)
        }

        var audio = self.audioElements[sessionId]
        audio.srcObject = stream

        // 确保音频元素已加载并尝试播放
        audio.load()

        // 使用用户交互来启动音频播放
        var playAudio = function () {
          audio
            .play()
            .then(function () {
              console.log('🔊 音频播放成功')
            })
            .catch(function (err) {
              console.error('播放失败:', err)
              // 尝试创建一个临时的用户交互事件
              var clickEvent = new MouseEvent('click', {
                bubbles: true,
                cancelable: true,
                view: window,
              })
              document.body.dispatchEvent(clickEvent)
            })
        }

        // 尝试立即播放
        playAudio()

        // 如果立即播放失败，尝试延迟播放
        setTimeout(playAudio, 100)
      }
    }
  }

  // 注销
  JsSipClient.prototype.unregister = function () {
    var self = this
    console.log('开始注销 JsSIP...')

    return new Promise(function (resolve) {
      try {
        // 终止所有会话
        for (var sessionId in self.sessions) {
          if (self.sessions.hasOwnProperty(sessionId)) {
            self.sessions[sessionId].terminate()
          }
        }
        self.sessions = {}

        if (self.currentSession) {
          self.currentSession.terminate()
          self.currentSession = null
        }

        if (self.incomingSession) {
          self.incomingSession.terminate()
          self.incomingSession = null
        }

        if (self.ua) {
          if (self.ua.isRegistered()) {
            self.ua.unregister()
          }
          self.ua.stop()
          self.ua = null
        }

        self.isRegistered = false
        self.isCalling = false
        self.isCallEstablished = false
        self.isHeld = false
        self.isMuted = false
        self.hasIncomingCall = false
        self.incomingCallerInfo = null

        console.log('✅ JsSIP 注销完成')
        resolve()
      } catch (error) {
        console.error('注销时发生错误:', error)
        resolve()
      }
    })
  }

  // 检查媒体权限
  JsSipClient.prototype.checkMediaPermissions = function () {
    var self = this

    return new Promise(function (resolve, reject) {
      // 检查是否支持getUserMedia
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        reject(new Error('您的浏览器不支持音频访问'))
        return
      }

      // 检查权限状态
      navigator.permissions
        .query({ name: 'microphone' })
        .then(function (permissionStatus) {
          if (permissionStatus.state === 'denied') {
            reject(
              new Error('麦克风权限被拒绝，请在浏览器设置中允许麦克风访问')
            )
          } else if (permissionStatus.state === 'prompt') {
            // 权限未决定，将在getUserMedia时提示
            resolve()
          } else {
            // 权限已授予
            resolve()
          }
        })
        .catch(function (error) {
          // 某些浏览器不支持permissions API，直接尝试getUserMedia
          resolve()
        })
    })
  }

  // 请求媒体权限
  JsSipClient.prototype.requestMediaAccess = function () {
    var self = this

    return navigator.mediaDevices
      .getUserMedia({ audio: true, video: false })
      .then(function (stream) {
        // 立即停止流，只是为了获取权限
        stream.getTracks().forEach(function (track) {
          track.stop()
        })
        return Promise.resolve()
      })
      .catch(function (error) {
        console.error('获取媒体权限失败:', error)
        return Promise.reject(error)
      })
  }

  // 解锁浏览器音频自动播放限制（在用户手势触发的调用流程中调用）
  JsSipClient.prototype.unlockAudioPlayback = function () {
    try {
      if (this._audioUnlocked) return
      var AudioContext = window.AudioContext || window.webkitAudioContext
      if (AudioContext) {
        if (!this._audioCtx) this._audioCtx = new AudioContext()
        if (this._audioCtx.state === 'suspended') {
          this._audioCtx
            .resume()
            .then(function () {
              console.log('🔓 AudioContext 已恢复')
            })
            .catch(function (err) {
              console.warn('恢复 AudioContext 失败:', err)
            })
        }
      }
      this._audioUnlocked = true
    } catch (e) {
      console.warn('解锁音频播放时异常:', e)
    }
  }

  // 拨打电话
  JsSipClient.prototype.makeCall = function (target, options) {
    var self = this
    options = options || {}

    // 尝试在用户手势链路内解锁自动播放
    try {
      self.unlockAudioPlayback()
    } catch (e) {}

    if (!this.ua) {
      return Promise.reject(new Error('UA 未就绪'))
    }

    if (!this.ua.isRegistered()) {
      return Promise.reject(new Error('未注册，无法拨打电话'))
    }

    // 检查和请求媒体权限
    return this.checkMediaPermissions()
      .then(function () {
        return self.getOrCreateMicStream()
      })
      .then(function (stream) {
        // 权限获取成功，继续原有逻辑
        console.log('📞 开始拨打电话到:', target, '选项:', options)

        var callOptions = {
          mediaConstraints: {
            audio: true,
            video: false,
          },
          rtcOfferConstraints: {
            offerToReceiveAudio: true,
            offerToReceiveVideo: false,
          },
          pcConfig: self._getPcConfig(),
          mediaStream: stream.clone(),  // ✅ 使用克隆而不是原始流
        }

        // 添加急呼特殊处理
        if (options.emergency) {
          // 添加急呼相关的SIP头
          callOptions.extraHeaders = [
            'X-Emergency-Call: true',
            'Alert-Info: <http://fusionpbx.com>;info=emergency;answer-after=15',
          ]

          console.log('🚨 发起急呼到:', target)
        }

        return new Promise(function (resolve, reject) {
          try {
            var session = self.ua.call(target, callOptions)
            var sessionId =
              'session_' +
              Date.now() +
              '_' +
              Math.random().toString(36).substr(2, 9)

            self.sessions[sessionId] = session
            session._customId = sessionId

            if (!self.currentSession) {
              self.currentSession = session
            }

            self.isCalling = true

            console.log('📤 呼叫已发起', session)

            // 设置监听器
            self.setupSessionListeners(session)

            resolve({ session: session, sessionId: sessionId })
          } catch (error) {
            console.error('❌ 拨打电话失败:', error)
            self.isCalling = false
            reject(error)
          }
        })
      })
      .catch(function (error) {
        // 权限获取失败，提供用户友好的错误信息
        var errorMessage = error.message || '未知错误'
        if (error.name === 'NotAllowedError') {
          errorMessage =
            '麦克风权限被拒绝。请点击地址栏左侧的麦克风图标，选择"允许"，然后重试。'
        } else if (error.name === 'NotFoundError') {
          errorMessage = '未检测到麦克风设备。请确保麦克风已连接并正常工作。'
        } else if (error.name === 'NotReadableError') {
          errorMessage =
            '麦克风被其他应用程序占用。请关闭其他使用麦克风的应用程序，然后重试。'
        }

        return Promise.reject(new Error(errorMessage))
      })
  }

  // 批量拨打电话（用于组呼/全呼）
  JsSipClient.prototype.makeCallBatch = function (targets, options) {
    var self = this
    var promises = []

    targets.forEach(function (target) {
      promises.push(self.makeCall(target, options))
    })

    return Promise.all(promises)
  }

  // 挂断电话
  JsSipClient.prototype.hangup = function (sessionId) {
    var self = this
    console.log('挂断电话', sessionId)

    return new Promise(function (resolve) {
      try {
        if (sessionId && self.sessions[sessionId]) {
          self.sessions[sessionId].terminate()
          delete self.sessions[sessionId]
        } else if (self.currentSession) {
          self.currentSession.terminate()
          self.currentSession = null
        }

        if (self.incomingSession) {
          self.incomingSession.terminate()
          self.incomingSession = null
        }

        self.endCall()

        // 清理音频元素
        if (sessionId && self.audioElements[sessionId]) {
          var audio = self.audioElements[sessionId]
          audio.pause()
          audio.srcObject = null
          if (audio.parentNode) {
            audio.parentNode.removeChild(audio)
          }
          delete self.audioElements[sessionId]
          console.log('🗑️ 已清理audio元素:', sessionId)
        }

        resolve()
      } catch (error) {
        console.error('挂断时发生错误:', error)
        resolve()
      }
    })
  }

  // 批量挂断（用于结束组呼/会议）
  JsSipClient.prototype.hangupAll = function () {
    var self = this
    var promises = []

    for (var sessionId in self.sessions) {
      if (self.sessions.hasOwnProperty(sessionId)) {
        promises.push(self.hangup(sessionId))
      }
    }

    return Promise.all(promises).then(function () {
      try {
        self.releaseMicStreamIfIdle()
      } catch (e) {}
    })
  }

  JsSipClient.prototype.closeAllSessions = function () {
    return this.hangupAll()
  }

  // 按通话类型挂断（用于模式切换）
  JsSipClient.prototype.hangupByType = function (callType) {
    var self = this
    var promises = []
    var hangupCount = 0

    console.log('🔍 查找并挂断类型为 "' + callType + '" 的通话...')

    for (var sessionId in self.sessions) {
      if (self.sessions.hasOwnProperty(sessionId)) {
        var session = self.sessions[sessionId]
        if (session._callType === callType) {
          console.log('  ✂️ 挂断会话:', sessionId, '类型:', session._callType)
          promises.push(self.hangup(sessionId))
          hangupCount++
        }
      }
    }

    console.log(
      '📊 共挂断 ' + hangupCount + ' 个 "' + callType + '" 类型的通话'
    )
    return Promise.all(promises)
  }

  // 清理通话状态
  JsSipClient.prototype.endCall = function (session) {
    if (session && session._customId) {
      delete this.sessions[session._customId]
    }

    // 如果没有活动会话了，重置状态
    if (Object.keys(this.sessions).length === 0) {
      this.isCalling = false
      this.isCallEstablished = false
      this.isHeld = false
      this.isMuted = false
    }

    this.hasIncomingCall = false
    this.incomingCallerInfo = null
    this.currentSession = null
    this.incomingSession = null
    this.releaseMicStreamIfIdle()
  }

  // 接听来电
  JsSipClient.prototype.acceptIncomingCall = function (options) {
    var self = this
    options = options || {}

    // 尝试在用户手势链路内解锁自动播放
    try {
      self.unlockAudioPlayback()
    } catch (e) {}

    if (!this.incomingSession) {
      return Promise.reject(new Error('没有来电可接听'))
    }

    console.log('接听来电', options)

    var needAudio = options.audio !== false
    var callOptions = {
      mediaConstraints: {
        audio: needAudio,
        video: options.video || false,
      },
      pcConfig: self._getPcConfig(),
    }

    // 若需要发送本地语音，确保获取到实时麦克风流并注入到 answer 选项
    var ensureMicStream = function () {
      if (!needAudio) return Promise.resolve(null)
      return self.getOrCreateMicStream()
    }

    return ensureMicStream()
      .then(function (stream) {
        if (stream) {
          try {
            callOptions.mediaStream = stream.clone()  // ✅ 使用克隆而不是原始流
          } catch (e) {}
        }
        return new Promise(function (resolve, reject) {
          try {
            self.incomingSession.answer(callOptions)
            var sessionId = self.incomingSession._customId
            if (sessionId && !self.sessions[sessionId]) {
              self.sessions[sessionId] = self.incomingSession
            }
            if (sessionId && self.incomingSessions[sessionId]) {
              delete self.incomingSessions[sessionId]
            }
            self.currentSession = self.incomingSession
            self.hasIncomingCall = false
            self.isCalling = true
            resolve({ session: self.incomingSession, sessionId: sessionId })
          } catch (error) {
            self.hasIncomingCall = false
            self.incomingCallerInfo = null
            self.incomingSession = null
            reject(error)
          }
        })
      })
      .catch(function (error) {
        // 权限或设备问题
        var msg = (error && (error.message || error.name)) || '无法获取麦克风'
        if (error && error.name === 'NotAllowedError') {
          msg = '麦克风权限被拒绝。请允许麦克风访问后重试。'
        } else if (error && error.name === 'NotFoundError') {
          msg = '未检测到麦克风设备。请连接设备后重试。'
        }
        return Promise.reject(new Error(msg))
      })
  }

  // 拒绝来电
  JsSipClient.prototype.rejectIncomingCall = function () {
    var self = this

    if (!this.incomingSession) {
      return Promise.reject(new Error('没有来电可拒绝'))
    }

    console.log('拒绝来电')

    return new Promise(function (resolve, reject) {
      try {
        self.incomingSession.terminate()
        resolve()
      } catch (error) {
        console.error('拒绝来电失败:', error)
        reject(error)
      } finally {
        self.hasIncomingCall = false
        self.incomingCallerInfo = null
        var sid = self.incomingSession && self.incomingSession._customId
        if (sid && self.sessions[sid]) {
          delete self.sessions[sid]
        }
        if (sid && self.incomingSessions[sid]) {
          delete self.incomingSessions[sid]
        }
        self.incomingSession = null
      }
    })
  }

  JsSipClient.prototype.acceptById = function (sessionId, options) {
    var self = this
    options = options || {}
    var session = self.sessions[sessionId]
    if (!session) {
      return Promise.reject(new Error('未找到会话'))
    }
    try {
      self.unlockAudioPlayback()
    } catch (e) {}
    var callOptions = { mediaConstraints: { audio: true, video: false } }
    return self.getOrCreateMicStream().then(function (stream) {
      if (stream) {
        try {
          callOptions.mediaStream = stream.clone()  // ✅ 使用克隆而不是原始流
        } catch (e) {}
      }
      return new Promise(function (resolve, reject) {
        try {
          session.answer(callOptions)
          if (self.incomingSessions[sessionId]) {
            delete self.incomingSessions[sessionId]
          }
          if (!self.sessions[sessionId]) {
            self.sessions[sessionId] = session
          }
          self.currentSession = session
          self.hasIncomingCall = false
          self.isCalling = true
          resolve({ session: session, sessionId: sessionId })
        } catch (error) {
          reject(error)
        }
      })
    })
  }

  JsSipClient.prototype.rejectById = function (sessionId) {
    var self = this
    var session = self.sessions[sessionId]
    if (!session) {
      return Promise.reject(new Error('未找到会话'))
    }
    return new Promise(function (resolve, reject) {
      try {
        session.terminate()
        if (self.sessions[sessionId]) {
          delete self.sessions[sessionId]
        }
        if (self.incomingSessions[sessionId]) {
          delete self.incomingSessions[sessionId]
        }
        resolve()
      } catch (error) {
        reject(error)
      }
    })
  }

  JsSipClient.prototype.hangupById = function (sessionId) {
    var self = this
    var session = self.sessions[sessionId]
    if (!session) {
      return Promise.reject(new Error('未找到会话'))
    }
    return new Promise(function (resolve, reject) {
      try {
        session.terminate()
        if (self.sessions[sessionId]) {
          delete self.sessions[sessionId]
        }
        if (self.incomingSessions[sessionId]) {
          delete self.incomingSessions[sessionId]
        }
        if (
          self.currentSession &&
          self.currentSession._customId === sessionId
        ) {
          self.currentSession = null
        }
        resolve()
      } catch (error) {
        reject(error)
      }
    }).then(function () {
      self.releaseMicStreamIfIdle()
    })
  }

  // 发送 DTMF
  JsSipClient.prototype.sendDtmf = function (tone, sessionId) {
    var session = sessionId ? this.sessions[sessionId] : this.currentSession

    if (!session) {
      return Promise.reject(new Error('没有活动会话'))
    }

    if (!/^[0-9A-D#*]$/.test(tone)) {
      return Promise.reject(new Error('无效的 DTMF 音调'))
    }

    console.log('发送 DTMF:', tone)

    return new Promise(function (resolve, reject) {
      try {
        session.sendDTMF(tone)
        resolve()
      } catch (error) {
        console.error('发送 DTMF 失败:', error)
        reject(error)
      }
    })
  }

  // 保持通话
  JsSipClient.prototype.hold = function (sessionId) {
    var self = this
    var session = sessionId ? this.sessions[sessionId] : this.currentSession

    if (!session) {
      return Promise.reject(new Error('没有活动会话'))
    }

    console.log('保持通话')

    return new Promise(function (resolve, reject) {
      try {
        session.hold()
        self.isHeld = true
        resolve()
      } catch (error) {
        console.error('保持通话失败:', error)
        reject(error)
      }
    })
  }

  // 恢复通话
  JsSipClient.prototype.unhold = function (sessionId) {
    var self = this
    var session = sessionId ? this.sessions[sessionId] : this.currentSession

    if (!session) {
      return Promise.reject(new Error('没有活动会话'))
    }

    console.log('恢复通话')

    return new Promise(function (resolve, reject) {
      try {
        session.unhold()
        self.isHeld = false
        resolve()
      } catch (error) {
        console.error('恢复通话失败:', error)
        reject(error)
      }
    })
  }

  // 静音
  JsSipClient.prototype.mute = function (sessionId) {
    var self = this
    var session = sessionId ? this.sessions[sessionId] : this.currentSession

    if (!session) {
      return Promise.reject(new Error('没有活动会话'))
    }

    console.log('静音')

    try {
      session.mute({ audio: true, video: false })
      self.isMuted = true
    } catch (error) {
      console.error('静音失败:', error)
      throw error
    }
  }

  // 取消静音
  JsSipClient.prototype.unmute = function (sessionId) {
    var self = this
    var session = sessionId ? this.sessions[sessionId] : this.currentSession

    if (!session) {
      return Promise.reject(new Error('没有活动会话'))
    }

    console.log('取消静音')

    try {
      session.unmute({ audio: true, video: false })
      self.isMuted = false
    } catch (error) {
      console.error('取消静音失败:', error)
      throw error
    }
  }

  // 转接
  JsSipClient.prototype.transfer = function (target, sessionId) {
    var session = sessionId ? this.sessions[sessionId] : this.currentSession

    if (!session) {
      return Promise.reject(new Error('没有活动会话'))
    }

    console.log('转接到:', target)

    return new Promise(function (resolve, reject) {
      try {
        session.refer(target)
        console.log('✅ 转接请求已发送')
        resolve()
      } catch (error) {
        console.error('转接失败:', error)
        reject(error)
      }
    })
  }

  // 获取所有活动会话
  JsSipClient.prototype.getActiveSessions = function () {
    return Object.keys(this.sessions).map(function (sessionId) {
      return {
        sessionId: sessionId,
        session: this.sessions[sessionId],
      }
    }, this)
  }

  JsSipClient.prototype.getIncomingSessions = function () {
    var res = []
    for (var sid in this.incomingSessions) {
      if (this.incomingSessions.hasOwnProperty(sid)) res.push(sid)
    }
    return res
  }

  // 静音特定会话（用于会议控制）
  JsSipClient.prototype.muteSession = function (sessionId) {
    if (this.sessions[sessionId]) {
      try {
        this.sessions[sessionId].mute({ audio: true, video: false })
        console.log('会话静音:', sessionId)
      } catch (error) {
        console.error('会话静音失败:', error)
      }
    }
  }

  // 取消静音特定会话
  JsSipClient.prototype.unmuteSession = function (sessionId) {
    if (this.sessions[sessionId]) {
      try {
        this.sessions[sessionId].unmute({ audio: true, video: false })
        console.log('会话取消静音:', sessionId)
      } catch (error) {
        console.error('会话取消静音失败:', error)
      }
    }
  }

  // 诊断连接
  JsSipClient.prototype.diagnoseConnection = function () {
    console.log('=== JsSIP 连接诊断 ===')
    console.log('📋 基本状态:')
    console.log('  - 注册状态:', this.isRegistered ? '✅ 已注册' : '❌ 未注册')
    console.log('  - 通话状态:', this.isCalling ? '📞 通话中' : '⭕ 空闲')
    console.log('  - 通话已建立:', this.isCallEstablished ? '✅ 是' : '❌ 否')
    console.log(
      '  - 来电状态:',
      this.hasIncomingCall ? '📞 有来电' : '⭕ 无来电'
    )
    console.log('  - 活动会话数:', Object.keys(this.sessions).length)

    if (this.ua) {
      console.log('🌐 UA 信息:')
      console.log('  - UA 状态:', this.ua.isRegistered() ? '已注册' : '未注册')
      console.log('  - UA 已连接:', this.ua.isConnected() ? '是' : '否')
      console.log('  - 配置:', this.ua.configuration)
    } else {
      console.log('🚫 UA: 未初始化')
    }

    console.log('💡 故障排除建议:')
    if (!this.ua) {
      console.log('  - 请先进行 SIP 注册')
    } else if (!this.ua.isConnected()) {
      console.log('  - 检查 WebSocket 服务器地址是否正确')
      console.log('  - 检查网络连接是否正常')
      console.log('  - 检查防火墙设置')
    } else if (!this.isRegistered) {
      console.log('  - 检查 SIP URI 格式是否正确')
      console.log('  - 检查用户名和密码是否正确')
      console.log('  - 检查 SIP 服务器是否允许该用户注册')
    }
    console.log('================')
  }

  // 导出到全局
  window.JsSipClient = JsSipClient
})(window)
// 资源释放与监控
JsSipClient.prototype._cleanupSessionMedia = function (session) {
  try {
    var sid = session && session._customId ? session._customId : null
    if (!sid) return
    try {
      var audio = this.audioElements[sid]
      if (audio) {
        try {
          if (audio.srcObject) {
            var tr = audio.srcObject.getTracks()
            for (var i = 0; i < tr.length; i++) {
              try {
                tr[i].stop()
              } catch (e) {}
            }
          }
        } catch (e) {}
        try {
          audio.pause()
        } catch (e) {}
        try {
          audio.srcObject = null
        } catch (e) {}
        try {
          if (audio.parentNode) {
            audio.parentNode.removeChild(audio)
          }
        } catch (e) {}
        delete this.audioElements[sid]
      }
    } catch (e) {}
    try {
      if (session && session.connection) {
        var con = session.connection
        try {
          var snd = con.getSenders ? con.getSenders() : []
          for (var s = 0; s < snd.length; s++) {
            var t = snd[s].track
            if (t && t.readyState !== 'ended') {
              try {
                t.stop()
              } catch (e) {}
            }
          }
        } catch (e) {}
        try {
          var rcv = con.getReceivers ? con.getReceivers() : []
          for (var r = 0; r < rcv.length; r++) {
            var tt = rcv[r].track
            if (tt && tt.readyState !== 'ended') {
              try {
                tt.stop()
              } catch (e) {}
            }
          }
        } catch (e) {}
      }
    } catch (e) {}
    this.resourceStats.releases++
    this.resourceStats.lastOps.push({ t: Date.now(), sid: sid, op: 'release' })
    var self = this
    setTimeout(function () {
      try {
        var a = self.audioElements[sid]
        if (a) {
          self.resourceStats.timeouts++
          try {
            a.pause()
            a.srcObject = null
            if (a.parentNode) {
              a.parentNode.removeChild(a)
            }
          } catch (e) {}
          delete self.audioElements[sid]
          self.resourceStats.forced++
          self.resourceStats.lastOps.push({
            t: Date.now(),
            sid: sid,
            op: 'force_release',
          })
        }
      } catch (e) {}
    }, 300)
  } catch (e) {}
}

JsSipClient.prototype._releaseAllAudio = function (reason) {
  try {
    var keys = Object.keys(this.audioElements || {})
    for (var i = 0; i < keys.length; i++) {
      var id = keys[i]
      try {
        var audio = this.audioElements[id]
        if (audio) {
          try {
            if (audio.srcObject) {
              var tr = audio.srcObject.getTracks()
              for (var j = 0; j < tr.length; j++) {
                try {
                  tr[j].stop()
                } catch (e) {}
              }
            }
          } catch (e) {}
          try {
            audio.pause()
          } catch (e) {}
          try {
            audio.srcObject = null
          } catch (e) {}
          try {
            if (audio.parentNode) {
              audio.parentNode.removeChild(audio)
            }
          } catch (e) {}
        }
        delete this.audioElements[id]
        this.resourceStats.releases++
        this.resourceStats.lastOps.push({
          t: Date.now(),
          sid: id,
          op: 'release_all',
          reason: reason || '',
        })
      } catch (e) {}
    }
  } catch (e) {}
}

JsSipClient.prototype.getResourceReport = function () {
  try {
    return {
      activeAudio: Object.keys(this.audioElements || {}).length,
      releases: this.resourceStats.releases,
      timeouts: this.resourceStats.timeouts,
      forced: this.resourceStats.forced,
      micActive: !!(
        this._micStream &&
        this._micStream.getTracks &&
        this._micStream.getTracks().length
      ),
      lastOps: this.resourceStats.lastOps.slice(-20),
    }
  } catch (e) {
    return {}
  }
}
// 获取或创建共享麦克风流
JsSipClient.prototype.getOrCreateMicStream = function () {
  var self = this
  if (
    self._micStream &&
    self._micStream.getTracks &&
    self._micStream.getTracks().length
  ) {
    return Promise.resolve(self._micStream)
  }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    return Promise.reject(new Error('浏览器不支持音频访问'))
  }
  return navigator.mediaDevices
    .getUserMedia({ audio: true, video: false })
    .then(function (stream) {
      self._micStream = stream
      return stream
    })
}

// 空闲时释放共享麦克风流
JsSipClient.prototype.releaseMicStreamIfIdle = function () {
  try {
    if (Object.keys(this.sessions).length === 0 && this._micStream) {
      this._micStream.getTracks().forEach(function (t) {
        try {
          t.stop()
        } catch (e) {}
      })
      this._micStream = null
    }
  } catch (e) {}
}
