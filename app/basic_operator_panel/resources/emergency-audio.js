;(function(window) {
  'use strict'

  function EmergencyAudioService() {
    this.muted = false
    this.alerts = {}
    this.logs = []
    this.maxVolume = 1.0
    this.voiceText = '\u7d27\u6025\u547c\u53eb\uff0c\u8bf7\u7acb\u5373\u5904\u7f6e'
    this.enableSpeech = false
    this.speechIntervalMs = 10000
    this.audioSrc = (window.dispatcherEmergencyAudio && window.dispatcherEmergencyAudio.src) || 'app/basic_operator_panel/resources/sounds/emergency_alert.mp3'
    this.ctx = null
    this._loadLogs()
  }

  EmergencyAudioService.prototype._unlock = function() {
    try {
      if (this.ctx) {
        if (this.ctx.state === 'suspended' && this.ctx.resume) this.ctx.resume()
        return
      }

      var shared = null
      try {
        if (window.dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient._audioCtx) {
          shared = dispatcherControl.sipClient._audioCtx
        }
      } catch (_) {}

      if (shared) {
        this.ctx = shared
        if (this.ctx.state === 'suspended' && this.ctx.resume) this.ctx.resume()
        return
      }

      var AudioContext = window.AudioContext || window.webkitAudioContext
      if (AudioContext) {
        this.ctx = new AudioContext()
        if (this.ctx.state === 'suspended' && this.ctx.resume) this.ctx.resume()
      }
    } catch (e) {}
  }

  EmergencyAudioService.prototype._log = function(type, sessionId, extra) {
    var item = {
      id: 'ea_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
      type: type,
      sessionId: sessionId || '',
      ts: Date.now(),
      extra: extra || {}
    }
    this.logs.push(item)
    if (this.logs.length > 2000) this.logs = this.logs.slice(-2000)
    try {
      localStorage.setItem('dispatcher_emergency_audio_logs', JSON.stringify(this.logs))
    } catch (e) {}
  }

  EmergencyAudioService.prototype._loadLogs = function() {
    try {
      var stored = localStorage.getItem('dispatcher_emergency_audio_logs')
      if (stored) this.logs = JSON.parse(stored) || []
    } catch (e) {}
  }

  EmergencyAudioService.prototype.setMuted = function(flag) {
    this.muted = !!flag
    if (this.muted) {
      for (var key in this.alerts) this._stopOne(key)
    }
    this._log('mute_toggle', '', { muted: this.muted })
  }

  EmergencyAudioService.prototype.test = function() {
    var self = this
    var sessionId = 'test_' + Date.now()
    return this.startAlert(sessionId, { test: true }).then(function() {
      return new Promise(function(resolve) {
        setTimeout(function() {
          self.stopAlert(sessionId)
          resolve()
        }, 1800)
      })
    })
  }

  EmergencyAudioService.prototype._createAudioElement = function(entry) {
    if (!this.audioSrc) return Promise.resolve(false)
    try {
      var audio = document.createElement('audio')
      audio.src = this.audioSrc
      audio.loop = true
      audio.autoplay = true
      audio.volume = Math.min(1, this.maxVolume)
      audio.muted = false
      audio.preload = 'auto'
      audio.playsInline = true
      audio.setAttribute('playsinline', 'true')
      audio.setAttribute('webkit-playsinline', 'true')
      document.body.appendChild(audio)
      return audio.play().then(function() {
        entry.media.push(audio)
        return true
      }).catch(function() {
        try {
          audio.pause()
          audio.src = ''
          if (audio.parentNode) audio.parentNode.removeChild(audio)
        } catch (e) {}
        return false
      })
    } catch (e) {
      return Promise.resolve(false)
    }
  }

  EmergencyAudioService.prototype._scheduleToneLayer = function(entry, start, frequency, duration, options) {
    if (!this.ctx || !isFinite(frequency) || frequency <= 0) return

    var cfg = options || {}
    var end = start + duration
    var oscillator = this.ctx.createOscillator()
    var gain = this.ctx.createGain()
    oscillator.type = cfg.waveform || 'triangle'
    oscillator.frequency.setValueAtTime(frequency, start)
    if (typeof cfg.detune === 'number') oscillator.detune.setValueAtTime(cfg.detune, start)

    var attack = Math.max(0.006, cfg.attack || 0.012)
    var release = Math.max(0.05, cfg.release || 0.12)
    var peak = Math.max(0.0001, cfg.gain || 0.08)
    gain.gain.setValueAtTime(0.0001, start)
    gain.gain.exponentialRampToValueAtTime(peak, start + attack)
    gain.gain.exponentialRampToValueAtTime(0.0001, Math.max(start + attack + 0.02, end - release))

    oscillator.connect(gain)
    gain.connect(this.ctx.destination)
    oscillator.start(start)
    oscillator.stop(end + 0.04)
    entry.nodes.push({ osc: oscillator, gain: gain })
  }

  EmergencyAudioService.prototype._scheduleChord = function(entry, start, tones, duration, options) {
    if (!tones || !tones.length) return

    var cfg = options || {}
    var mainGain = (typeof cfg.mainGain === 'number' ? cfg.mainGain : 0.095) / Math.max(tones.length, 1)
    var overlayGain = (typeof cfg.overlayGain === 'number' ? cfg.overlayGain : 0.024) / Math.max(tones.length, 1)

    for (var i = 0; i < tones.length; i++) {
      var frequency = tones[i]
      this._scheduleToneLayer(entry, start, frequency, duration, {
        waveform: cfg.waveform || 'triangle',
        gain: mainGain,
        attack: cfg.attack,
        release: cfg.release,
        detune: cfg.detune && cfg.detune.length ? cfg.detune[i % cfg.detune.length] : 0
      })
      if (overlayGain > 0) {
        this._scheduleToneLayer(entry, start, frequency * 2, duration, {
          waveform: cfg.overlayWaveform || 'sine',
          gain: overlayGain,
          attack: cfg.attack,
          release: cfg.release
        })
      }
    }
  }

  EmergencyAudioService.prototype._scheduleEmergencyCycle = function(entry) {
    if (!entry.active || !this.ctx) return

    var self = this
    var start = this.ctx.currentTime + 0.03
    var pattern = [
      { tones: [784, 1175], duration: 0.28, gapAfter: 0.10 },
      { tones: [784, 1175], duration: 0.34, gapAfter: 0.18 },
      { tones: [988, 1480], duration: 0.28, gapAfter: 0.10 },
      { tones: [988, 1480], duration: 0.36, gapAfter: 1.08 }
    ]
    var cursor = 0

    for (var i = 0; i < pattern.length; i++) {
      var step = pattern[i]
      self._scheduleChord(entry, start + cursor, step.tones, step.duration, {
        waveform: 'triangle',
        overlayWaveform: 'sine',
        mainGain: i < 2 ? 0.18 : 0.20,
        overlayGain: i < 2 ? 0.04 : 0.05,
        attack: 0.008,
        release: 0.08,
        detune: [0, 4]
      })
      cursor += step.duration + (step.gapAfter || 0)
    }

    entry.timers.push(setTimeout(function() {
      self._scheduleEmergencyCycle(entry)
    }, Math.max(2200, Math.round(cursor * 1000))))
  }

  EmergencyAudioService.prototype._startSpeechLoop = function(entry) {
    if (!this.enableSpeech) return
    if (!('speechSynthesis' in window) && !('webkitSpeechSynthesis' in window)) return

    var self = this
    var speakOnce = function() {
      if (!entry.active) return
      try {
        var utterance = new SpeechSynthesisUtterance(self.voiceText)
        utterance.lang = 'zh-CN'
        utterance.rate = 0.92
        utterance.pitch = 0.9
        utterance.volume = Math.min(1, self.maxVolume)
        window.speechSynthesis.speak(utterance)
      } catch (e) {}
      entry.timers.push(setTimeout(speakOnce, self.speechIntervalMs))
    }

    speakOnce()
  }

  EmergencyAudioService.prototype.startAlert = function(sessionId, opts) {
    var self = this
    var options = opts || {}
    if (this.muted) return Promise.resolve()

    this._unlock()
    var entry = this.alerts[sessionId]
    if (entry && entry.active) return Promise.resolve()

    entry = {
      active: true,
      media: [],
      nodes: [],
      timers: []
    }
    this.alerts[sessionId] = entry
    this._log('start', sessionId, options)

    self._scheduleEmergencyCycle(entry)

    var filePromise = options.useFileAlert === true
      ? this._createAudioElement(entry)
      : Promise.resolve(false)

    return filePromise.then(function() {
      if (options.enableSpeech === true) self.enableSpeech = true
      self._startSpeechLoop(entry)
    })
  }

  EmergencyAudioService.prototype._stopOne = function(sessionId) {
    var entry = this.alerts[sessionId]
    if (!entry) return

    entry.active = false

    while (entry.timers.length) {
      try {
        clearTimeout(entry.timers.pop())
      } catch (e) {}
    }

    for (var i = 0; i < entry.media.length; i++) {
      var media = entry.media[i]
      try {
        media.pause()
        media.src = ''
        if (media.parentNode) media.parentNode.removeChild(media)
      } catch (e) {}
    }
    entry.media = []

    for (var j = 0; j < entry.nodes.length; j++) {
      var node = entry.nodes[j]
      try {
        if (node.osc) node.osc.stop()
      } catch (e) {}
      try {
        if (node.gain) node.gain.disconnect()
      } catch (e) {}
    }
    entry.nodes = []
  }

  EmergencyAudioService.prototype.stopAlert = function(sessionId) {
    this._stopOne(sessionId)
    delete this.alerts[sessionId]
    this._log('stop', sessionId, {})
    try {
      if (window.speechSynthesis && window.speechSynthesis.cancel) window.speechSynthesis.cancel()
    } catch (e) {}
  }

  EmergencyAudioService.prototype.stopAll = function() {
    for (var key in this.alerts) {
      this._stopOne(key)
      delete this.alerts[key]
    }
    this._log('stop_all', '', {})
    try {
      if (window.speechSynthesis && window.speechSynthesis.cancel) window.speechSynthesis.cancel()
    } catch (e) {}
  }

  window.EmergencyAudioService = EmergencyAudioService
})(window)
