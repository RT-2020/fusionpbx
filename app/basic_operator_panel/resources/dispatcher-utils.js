;(function(window, $){
  'use strict'

  function DispatcherUtils(){ }

  // 命令构造器
  DispatcherUtils.prototype.getTransferCmd = function(uuid, destination){
    return 'exec.php?cmd=uuid_transfer&uuid=' + encodeURIComponent(uuid) + '&destination=' + encodeURIComponent(destination)
  }
  DispatcherUtils.prototype.getOriginateCmd = function(source, destination){
    return 'exec.php?cmd=originate&source=' + encodeURIComponent(source) + '&destination=' + encodeURIComponent(destination)
  }
  DispatcherUtils.prototype.getEavesdropCmd = function(ext, chan_uuid, destination, mode){
    var m = mode || 'listen'
    return 'exec.php?cmd=uuid_eavesdrop&ext=' + encodeURIComponent(ext) + '&chan_uuid=' + encodeURIComponent(chan_uuid) + '&destination=' + encodeURIComponent(destination) + '&mode=' + encodeURIComponent(m)
  }
  DispatcherUtils.prototype.getRecordCmd = function(uuid){
    return 'exec.php?cmd=uuid_record&uuid=' + encodeURIComponent(uuid)
  }

  // 统一 AJAX GET/POST
  DispatcherUtils.prototype.ajaxGetJson = function(url, data){
    return $.ajax({ url:url, type:'GET', data:data||{}, dataType:'json' })
  }
  DispatcherUtils.prototype.ajaxPost = function(url, data){
    return $.ajax({ url:url, type:'POST', data:data||{} })
  }

  // 统一发送命令并反馈
  DispatcherUtils.prototype.sendCmd = function(url, callback){
    var xmlhttp = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP')
    xmlhttp.onreadystatechange = function(){
      if (xmlhttp.readyState === 4){
        try {
          var toast = document.getElementById('dispatcher-lines-toast')
          if (toast){
            var ok = xmlhttp.responseText && xmlhttp.responseText.indexOf('-ERR') === -1 && xmlhttp.responseText.indexOf('access denied') === -1
            toast.style.background = ok ? '#2e7d32' : '#b71c1c'
            toast.textContent = ok ? '操作成功 (Success)' : '操作失败 (Failed)'
            $(toast).stop(true,true).fadeIn(300, function(){ setTimeout(function(){ $(toast).fadeOut(300) }, 1500) })
          }
        } catch(e){}
        if (callback && typeof callback === 'function') callback(xmlhttp.responseText, xmlhttp.status)
      }
    }
    xmlhttp.open('GET', url, true)
    xmlhttp.send(null)
  }

  DispatcherUtils.prototype.modal = function(opts){
    var o = opts || {}
    var overlay = document.getElementById('dispatcher-modal-overlay')
    if (!overlay){
      overlay = document.createElement('div')
      overlay.id = 'dispatcher-modal-overlay'
      overlay.style.position = 'fixed'
      overlay.style.left = '0'
      overlay.style.top = '0'
      overlay.style.right = '0'
      overlay.style.bottom = '0'
      overlay.style.background = 'rgba(0,0,0,0.4)'
      overlay.style.zIndex = '9998'
      overlay.style.display = 'none'
      document.body.appendChild(overlay)
    }
    var modal = document.getElementById('dispatcher-generic-modal')
    if (!modal){
      modal = document.createElement('div')
      modal.id = 'dispatcher-generic-modal'
      modal.style.position = 'fixed'
      modal.style.left = '50%'
      modal.style.top = '50%'
      modal.style.transform = 'translate(-50%, -50%)'
      modal.style.maxWidth = '90vw'
      modal.style.width = '400px'
      modal.style.boxSizing = 'border-box'
      modal.style.background = '#fff'
      modal.style.borderRadius = '8px'
      modal.style.boxShadow = '0 8px 24px rgba(0,0,0,0.2)'
      modal.style.zIndex = '9999'
      modal.style.display = 'none'
      modal.style.padding = '16px'
      var title = document.createElement('div')
      title.id = 'dispatcher-generic-modal-title'
      title.style.fontSize = '16px'
      title.style.fontWeight = '600'
      title.style.marginBottom = '8px'
      modal.appendChild(title)
      var msg = document.createElement('div')
      msg.id = 'dispatcher-generic-modal-message'
      msg.style.fontSize = '14px'
      msg.style.lineHeight = '1.6'
      msg.style.wordBreak = 'break-word'
      msg.style.marginBottom = '12px'
      modal.appendChild(msg)
      var actions = document.createElement('div')
      actions.id = 'dispatcher-generic-modal-actions'
      actions.style.display = 'flex'
      actions.style.justifyContent = 'flex-end'
      actions.style.gap = '8px'
      modal.appendChild(actions)
      document.body.appendChild(modal)
    }
    var titleEl = document.getElementById('dispatcher-generic-modal-title')
    var msgEl = document.getElementById('dispatcher-generic-modal-message')
    var actionsEl = document.getElementById('dispatcher-generic-modal-actions')
    titleEl.textContent = o.title || (o.type === 'error' ? '错误' : '提示')
    msgEl.textContent = o.message || ''
    while (actionsEl.firstChild) actionsEl.removeChild(actionsEl.firstChild)
    var btns = o.actions && Array.isArray(o.actions) ? o.actions : [{ text:'知道了', type:'primary' }]
    btns.forEach(function(b){
      var btn = document.createElement('button')
      btn.textContent = b.text || '确定'
      btn.style.padding = '8px 14px'
      btn.style.borderRadius = '4px'
      btn.style.border = '1px solid #ddd'
      btn.style.cursor = 'pointer'
      btn.style.background = (b.type === 'primary') ? '#2e7d32' : (b.type === 'warn' ? '#e67e22' : '#f5f5f5')
      btn.style.color = (b.type === 'primary') ? '#fff' : '#333'
      btn.onclick = function(){ try{ if (typeof b.onClick === 'function') b.onClick() }catch(e){}; overlay.style.display='none'; modal.style.display='none' }
      actionsEl.appendChild(btn)
    })
    overlay.onclick = function(){ overlay.style.display='none'; modal.style.display='none' }
    overlay.style.display = 'block'
    modal.style.display = 'block'
  }

  DispatcherUtils.prototype.alert = function(message, type){
    var t = type || 'info'
    this.modal({ title: t==='error'?'错误':'提示', message: message, type: t, actions: [{ text:'知道了', type:'primary' }] })
  }

  DispatcherUtils.prototype.showEmergencyEndConfirm = function(message){
    var overlay = document.getElementById('dispatcher-emergency-overlay')
    if (!overlay){
      overlay = document.createElement('div')
      overlay.id = 'dispatcher-emergency-overlay'
      overlay.style.position = 'fixed'
      overlay.style.left = '0'
      overlay.style.top = '0'
      overlay.style.right = '0'
      overlay.style.bottom = '0'
      overlay.style.background = 'rgba(0,0,0,0.4)'
      overlay.style.zIndex = '9998'
      overlay.style.display = 'none'
      document.body.appendChild(overlay)
    }
    var modal = document.getElementById('dispatcher-emergency-end-modal')
    if (!modal){
      modal = document.createElement('div')
      modal.id = 'dispatcher-emergency-end-modal'
      modal.style.position = 'fixed'
      modal.style.left = '50%'
      modal.style.top = '50%'
      modal.style.transform = 'translate(-50%, -50%)'
      modal.style.maxWidth = '90vw'
      modal.style.width = '420px'
      modal.style.boxSizing = 'border-box'
      modal.style.background = '#fff'
      modal.style.borderRadius = '8px'
      modal.style.boxShadow = '0 8px 24px rgba(0,0,0,0.2)'
      modal.style.zIndex = '9999'
      modal.style.display = 'none'
      modal.style.padding = '16px'
      var title = document.createElement('div')
      title.id = 'dispatcher-emergency-end-title'
      title.style.fontSize = '16px'
      title.style.fontWeight = '600'
      title.style.marginBottom = '8px'
      modal.appendChild(title)
      var msg = document.createElement('div')
      msg.id = 'dispatcher-emergency-end-message'
      msg.style.fontSize = '14px'
      msg.style.lineHeight = '1.6'
      msg.style.wordBreak = 'break-word'
      msg.style.marginBottom = '12px'
      modal.appendChild(msg)
      var actions = document.createElement('div')
      actions.id = 'dispatcher-emergency-end-actions'
      actions.style.display = 'flex'
      actions.style.justifyContent = 'flex-end'
      actions.style.gap = '8px'
      var ok = document.createElement('button')
      ok.textContent = '知道了'
      ok.style.padding = '8px 14px'
      ok.style.borderRadius = '4px'
      ok.style.border = '1px solid #2e7d32'
      ok.style.cursor = 'pointer'
      ok.style.background = '#2e7d32'
      ok.style.color = '#fff'
      ok.onclick = function(){ overlay.style.display='none'; modal.style.display='none' }
      actions.appendChild(ok)
      modal.appendChild(actions)
      document.body.appendChild(modal)
    }
    var titleEl = document.getElementById('dispatcher-emergency-end-title')
    var msgEl = document.getElementById('dispatcher-emergency-end-message')
    titleEl.textContent = '紧急呼叫结束'
    msgEl.textContent = message || '紧急呼叫已结束，所有相关资源已释放'
    overlay.onclick = function(){ overlay.style.display='none'; modal.style.display='none' }
    overlay.style.display = 'block'
    modal.style.display = 'block'
  }

  // Toast
  DispatcherUtils.prototype.toast = function(text, type){
    var el = document.getElementById('dispatcher-lines-toast')
    if (!el) return
    var bg = type==='error' ? '#b71c1c' : (type==='warn' ? '#e67e22' : '#2e7d32')
    el.style.background = bg
    el.textContent = text
    $(el).stop(true,true).fadeIn(300, function(){ setTimeout(function(){ $(el).fadeOut(300) }, 1500) })
  }

  DispatcherUtils.prototype.ResourceManager = {
    stopEmergencyTone: function(sessionId){
      try{
        var ea = window.emergencyAudio
        if (!ea) return
        if (sessionId && ea.alerts && ea.alerts[sessionId]) { ea.stopAlert(sessionId) }
        try{ if (window.speechSynthesis && window.speechSynthesis.cancel) window.speechSynthesis.cancel() }catch(e){}
      }catch(e){}
    },
    stopEmergencyToneAll: function(){
      try{
        var ea = window.emergencyAudio
        if (ea && typeof ea.stopAll === 'function') ea.stopAll()
        try{ if (window.speechSynthesis && window.speechSynthesis.cancel) window.speechSynthesis.cancel() }catch(e){}
        try{
          var audios = document.querySelectorAll('audio')
          audios.forEach(function(a){ if ((a.src||'').indexOf('emergency_alert.mp3')!==-1){ try{ a.pause(); a.src=''; if (a.parentNode) a.parentNode.removeChild(a) }catch(e){} } })
        }catch(e){}
      }catch(e){}
    },
    closeAllVoiceSessions: function(){
      try{
        var sc = window.dispatcherControl && window.dispatcherControl.sipClient
        if (!sc) return
        if (typeof sc.closeAllSessions === 'function') sc.closeAllSessions(); else if (typeof sc.hangupAll === 'function') sc.hangupAll()
      }catch(e){}
    },
    destroyUiPopups: function(){
      try{
        var overlay = document.querySelectorAll('.modal-overlay')
        overlay.forEach(function(n){ if (n && n.parentNode) n.parentNode.removeChild(n) })
      }catch(e){}
      try{
        var modals = document.querySelectorAll('[id$="-modal"], .modal')
        modals.forEach(function(m){ if (m && m.style) m.style.display='none' })
      }catch(e){}
      try{
        var og = document.getElementById('dispatcher-modal-overlay'); if (og && og.parentNode) og.parentNode.removeChild(og)
        var gm = document.getElementById('dispatcher-generic-modal'); if (gm && gm.parentNode) gm.parentNode.removeChild(gm)
      }catch(e){}
      try{
        var audios = document.querySelectorAll('audio')
        audios.forEach(function(a){ try{ a.pause(); a.src=''; if (a.parentNode) a.parentNode.removeChild(a) }catch(e){} })
      }catch(e){}
    },
    verifyReleased: function(){
      var active = 0
      var alerts = 0
      var modalCount = 0
      var overlayCount = 0
      var audioCount = 0
      try{
        var sc = window.dispatcherControl && window.dispatcherControl.sipClient
        if (sc && typeof sc.getActiveSessions === 'function') active = (sc.getActiveSessions() || []).length
      }catch(e){}
      try{
        var ea = window.emergencyAudio
        if (ea && ea.alerts) alerts = Object.keys(ea.alerts).length
      }catch(e){}
      try{ modalCount = document.querySelectorAll('[id$="-modal"], .modal').length }catch(e){}
      try{ overlayCount = document.querySelectorAll('.modal-overlay, #dispatcher-modal-overlay').length }catch(e){}
      try{ audioCount = document.querySelectorAll('audio').length }catch(e){}
      return { activeSessions: active, emergencyAlerts: alerts, modals: modalCount, overlays: overlayCount, audioNodes: audioCount }
    },
    occupancy: { table: {}, ttl: 60000 },
    setEmergencyOwner: function(sessionId, ownerId){ try{ this.occupancy.table[sessionId] = { owner: ownerId||'', ts: Date.now() } }catch(e){} },
    releaseEmergencyOwner: function(sessionId){ try{ delete this.occupancy.table[sessionId] }catch(e){} },
    pruneEmergencyOwners: function(){ try{ var now=Date.now(); var ttl=this.occupancy.ttl; var t=this.occupancy.table; for (var k in t){ if (t[k] && (now - t[k].ts) > ttl){ delete t[k] } } }catch(e){} },
    getEmergencyStatus: function(){ try{ this.pruneEmergencyOwners(); var ea = window.emergencyAudio; var keys = ea && ea.alerts ? Object.keys(ea.alerts) : []; return { alerts: keys, owners: this.occupancy.table } }catch(e){ return { alerts: [], owners: {} } } }
  }

  // 节流/防抖
  DispatcherUtils.prototype.throttle = function(fn, wait){
    var last = 0, timer = null
    return function(){
      var now = Date.now(), args = arguments, ctx = this
      if (now - last >= wait){ last = now; fn.apply(ctx, args) }
      else if (!timer){ timer = setTimeout(function(){ last = Date.now(); timer = null; fn.apply(ctx, args) }, wait - (now - last)) }
    }
  }
  DispatcherUtils.prototype.debounce = function(fn, wait){
    var t = null
    return function(){ var args = arguments, ctx = this; clearTimeout(t); t = setTimeout(function(){ fn.apply(ctx, args) }, wait) }
  }

  window.DispatcherUtils = new DispatcherUtils()
})(window, jQuery)