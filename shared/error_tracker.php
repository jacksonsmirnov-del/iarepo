<?php
// ================================================================
// shared/error_tracker.php — JS error tracking snippet
//
// Incluir en el <head> de cada página pública.
// Captura errores JS no manejados y los envía a /api/log-error.php
// ================================================================
?>
<script>
(function(){
  function send(data){
    try {
      navigator.sendBeacon
        ? navigator.sendBeacon('/api/log-error.php', JSON.stringify(data))
        : fetch('/api/log-error.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data), keepalive:true}).catch(function(){});
    } catch(e){}
  }

  window.addEventListener('error', function(e){
    if (!e.message || e.message === 'Script error.') return;
    send({
      message: String(e.message).slice(0, 500),
      source:  (e.filename || '').split('/').pop().slice(0, 200),
      lineno:  e.lineno || 0,
      page:    location.pathname
    });
  }, true);

  window.addEventListener('unhandledrejection', function(e){
    var msg = e.reason && e.reason.message ? e.reason.message : String(e.reason || 'Unhandled rejection');
    send({
      message: ('Promise: ' + msg).slice(0, 500),
      source:  'promise',
      lineno:  0,
      page:    location.pathname
    });
  });
})();
</script>
