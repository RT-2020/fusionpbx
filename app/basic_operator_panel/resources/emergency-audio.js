;(function(window){
  'use strict'
  function EmergencyAudioService(){
    this.muted=false
    this.alerts={}
    this.logs=[]
    this.maxVolume=1.0
    this.voiceText='紧急来电，请立即处理'
    this.audioSrc=(window.dispatcherEmergencyAudio&&window.dispatcherEmergencyAudio.src)||'app/basic_operator_panel/resources/sounds/emergency_alert.mp3'
    this.ctx=null
    this._loadLogs()
  }
  EmergencyAudioService.prototype._unlock=function(){
    try{
      if(this.ctx){ if(this.ctx.state==='suspended'){ this.ctx.resume() }; return }
      var shared=null
      try{ if(window.dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient._audioCtx){ shared=dispatcherControl.sipClient._audioCtx } }catch(_){ }
      if(shared){ this.ctx=shared; if(this.ctx.state==='suspended'){ this.ctx.resume() }; return }
      var C=window.AudioContext||window.webkitAudioContext
      if(C){ this.ctx=new C(); if(this.ctx.state==='suspended'){ this.ctx.resume() } }
    }catch(e){}
  }
  EmergencyAudioService.prototype._log=function(type,sessionId,extra){
    var item={id:'ea_'+Date.now()+'_'+Math.random().toString(36).slice(2,7),type:type,sessionId:sessionId||'',ts:Date.now(),extra:extra||{}}
    this.logs.push(item)
    if(this.logs.length>2000){this.logs=this.logs.slice(-2000)}
    try{localStorage.setItem('dispatcher_emergency_audio_logs',JSON.stringify(this.logs))}catch(e){}
  }
  EmergencyAudioService.prototype._loadLogs=function(){
    try{var s=localStorage.getItem('dispatcher_emergency_audio_logs');if(s){this.logs=JSON.parse(s)||[]}}catch(e){}
  }
  EmergencyAudioService.prototype.setMuted=function(flag){
    this.muted=!!flag
    if(this.muted){for(var k in this.alerts){this._stopOne(k)}}
    this._log('mute_toggle','',{'muted':this.muted})
  }
  EmergencyAudioService.prototype.test=function(){
    return this.startAlert('test_'+Date.now(),{test:true}).then(this.stopAlert.bind(this))
  }
  EmergencyAudioService.prototype.startAlert=function(sessionId,opts){
    var self=this
    opts=opts||{}
    if(this.muted){return Promise.resolve()}
    this._unlock()
    var entry=this.alerts[sessionId]
    if(entry&&entry.active){return Promise.resolve()}
    entry={active:true,els:[],speech:false}
    this.alerts[sessionId]=entry
    this._log('start',sessionId,opts)
    var playFile=function(){
      try{
        var a=document.createElement('audio')
        a.src=self.audioSrc
        a.loop=true
        a.autoplay=true
        a.volume=self.maxVolume
        a.muted=false
        a.playsInline=true
        a.setAttribute('playsinline','true')
        a.setAttribute('webkit-playsinline','true')
        document.body.appendChild(a)
        entry.els.push(a)
        return a.play().catch(function(){})
      }catch(e){return Promise.resolve()}
    }
    var speak=function(){
      try{
        if(!('speechSynthesis'in window)&&!('webkitSpeechSynthesis'in window))return Promise.resolve()
        var u=new SpeechSynthesisUtterance(self.voiceText)
        u.lang='zh-CN'
        u.rate=1
        u.pitch=1
        entry.speech=true
        var loop=function(){if(!entry.active)return;window.speechSynthesis.speak(u);setTimeout(loop,2500)}
        loop()
      }catch(e){}
      return Promise.resolve()
    }
    var tone=function(){
      try{
        if(!self.ctx) return
        var o=self.ctx.createOscillator()
        var g=self.ctx.createGain()
        o.type='sine';o.frequency.value=880
        g.gain.value=0.2
        o.connect(g);g.connect(self.ctx.destination)
        o.start()
        entry.els.push({osc:o,g:g})
        var pat=[200,150,200,600]
        var i=0
        var seq=function(){if(!entry.active){try{o.stop()}catch(e){};return}o.frequency.value=(i%2===0)?880:1200;setTimeout(function(){i=(i+1)%pat.length;seq()},pat[i])}
        seq()
      }catch(e){}
      return Promise.resolve()
    }
    return playFile().then(speak).then(tone)
  }
  EmergencyAudioService.prototype._stopOne=function(sessionId){
    var entry=this.alerts[sessionId]
    if(!entry) return
    entry.active=false
    for(var i=0;i<entry.els.length;i++){
      var el=entry.els[i]
      try{
        if(el.tagName==='AUDIO'){
          el.pause();el.src='';if(el.parentNode){el.parentNode.removeChild(el)}
        }else if(el.osc){try{el.osc.stop()}catch(e){};try{el.g.disconnect()}catch(e){}}
      }catch(e){}
    }
    entry.els=[]
  }
  EmergencyAudioService.prototype.stopAlert=function(sessionId){
    this._stopOne(sessionId)
    delete this.alerts[sessionId]
    this._log('stop',sessionId,{})
    try{ if (window.speechSynthesis && window.speechSynthesis.cancel) { window.speechSynthesis.cancel() } }catch(e){}
    try{ var self=this; setTimeout(function(){ self._stopOne(sessionId) },300) }catch(e){}
  }
  EmergencyAudioService.prototype.stopAll=function(){
    for(var k in this.alerts){this._stopOne(k);delete this.alerts[k]}
    this._log('stop_all','',{})
    try{ if (window.speechSynthesis && window.speechSynthesis.cancel) { window.speechSynthesis.cancel() } }catch(e){}
  }
  window.EmergencyAudioService=EmergencyAudioService
})(window)
