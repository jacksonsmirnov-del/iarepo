// ================================================================
// assets/js/track.js — Beacon de visitas y atención
//
// Se engancha con atributos data-* en su propia etiqueta, sin globales ni JS
// inline en la página:
//   <script src="/assets/js/track.js" data-resource-id="42" data-surface="detail" defer></script>
//
// ── QUÉ MANDA Y QUÉ NO ────────────────────────────────────────
// Manda: id del recurso, un identificador aleatorio del navegador, segundos
// con la pestaña VISIBLE y si el foco llegó a entrar en el iframe.
// No manda —ni puede— nada de lo que pasa DENTRO del recurso: el iframe va con
// sandbox="allow-scripts" SIN allow-same-origin, así que el navegador impide
// leer su interior. Es una barrera del navegador, no una promesa nuestra.
//
// ── POR QUÉ localStorage Y NO LA IP ───────────────────────────
// Deduplicar por IP habría colapsado un aula entera en un único visitante:
// los alumnos de un colegio salen por el NAT del centro y, con los equipos del
// aula, hasta el User-Agent coincide. Justo el caso que motivó todo esto.
// El identificador lo genera el navegador, no sale de este dominio, y el
// servidor sólo guarda su hash con una sal que caduca a los 2 días.
//
// ── TODO ESTO ES OPCIONAL POR DISEÑO ──────────────────────────
// Si localStorage no está disponible (modo privado restrictivo, políticas de
// empresa, el usuario lo bloqueó), el módulo se calla y no pasa nada más: la
// página funciona igual y la visita simplemente no se cuenta. Medir no puede
// ser un requisito para usar el sitio.
// ================================================================

(function () {
  'use strict';

  var el = document.currentScript;
  if (!el) return;

  var resourceId = parseInt(el.getAttribute('data-resource-id'), 10);
  if (!resourceId) return;

  var surface = el.getAttribute('data-surface') === 'viewer' ? 'viewer' : 'detail';
  var ENDPOINT = '/api/track.php';

  // ── Identificador del navegador ───────────────────────────────
  // 32 hex: el mismo formato que valida api/track.php. crypto.getRandomValues
  // está en todo navegador con https desde hace años; si no estuviera,
  // preferimos no contar a inventar un identificador predecible.
  var KEY = 'iarepo_vid';
  var vid = null;
  try {
    vid = localStorage.getItem(KEY);
    if (!vid || !/^[a-f0-9]{32}$/.test(vid)) {
      if (!window.crypto || !window.crypto.getRandomValues) return;
      var buf = new Uint8Array(16);
      window.crypto.getRandomValues(buf);
      vid = Array.prototype.map.call(buf, function (b) {
        return ('0' + b.toString(16)).slice(-2);
      }).join('');
      localStorage.setItem(KEY, vid);
    }
  } catch (e) {
    // Almacenamiento bloqueado: se abandona en silencio, nunca se rompe la página.
    return;
  }

  // ── Tiempo de atención ────────────────────────────────────────
  // Sólo cuenta con la pestaña visible. Sin esto, una pestaña olvidada de
  // fondo produciría "3 horas de atención" y el dato dejaría de significar
  // nada — que es exactamente el fallo que este módulo viene a arreglar en
  // otra escala.
  var engaged = 0;         // segundos acumulados, ya redondeados
  var since = null;        // marca de cuándo empezó el tramo visible actual
  var interacted = false;
  var lastSent = -1;       // evita beacons idénticos consecutivos

  function now() { return Date.now(); }

  function startClock() {
    if (since === null) since = now();
  }

  function stopClock() {
    if (since !== null) {
      engaged += Math.round((now() - since) / 1000);
      since = null;
    }
  }

  function currentEngaged() {
    return engaged + (since !== null ? Math.round((now() - since) / 1000) : 0);
  }

  // ── Detección de interacción real ─────────────────────────────
  // El truco clásico: cuando el foco sale de la ventana, si el elemento activo
  // es un iframe es que el usuario ha pinchado DENTRO del recurso. Es lo único
  // que distingue "lo miró" de "lo usó", y lo único observable desde fuera de
  // un iframe con sandbox.
  window.addEventListener('blur', function () {
    var a = document.activeElement;
    if (a && a.tagName === 'IFRAME') {
      interacted = true;
      send();
    }
  });

  // ── Aviso de "esta persona SÍ usó el recurso" ─────────────────
  //
  // Umbral: tiempo activo suficiente Y foco dentro del iframe. Tiene que
  // coincidir con IAREPO_FEEDBACK_MIN_SECS de api/feedback.php, que es quien
  // manda: si divergen, se enseñaría una pregunta que el servidor va a
  // rechazar.
  //
  // ── POR QUÉ UN EVENTO Y NO PINTAR EL PROMPT AQUÍ ──────────────
  // Este módulo mide; la interfaz la decide cada página. Con un evento, la
  // ficha se suscribe y el visor puede ignorarlo, sin que track.js tenga que
  // saber nada de botones ni de traducciones.
  //
  // ── POR QUÉ EL UMBRAL, Y NO PREGUNTAR A TODO EL MUNDO ─────────
  // Preguntar al que abre y se va en 8 segundos contamina la muestra y
  // molesta. Preguntando sólo a quien de verdad lo usó, sube la tasa de
  // respuesta y el dato significa algo. api/feedback.php lo comprueba otra vez
  // contra la base de datos: esto de aquí sólo evita ofrecer lo que se va a
  // rechazar.
  var MIN_SECS = 180;
  var notified = false;

  function maybeNotifyEngaged() {
    if (notified || !interacted || currentEngaged() < MIN_SECS) return;
    notified = true;
    try {
      document.dispatchEvent(new CustomEvent('iarepo:engaged', {
        detail: { resourceId: resourceId, vid: vid }
      }));
    } catch (e) {
      // Un navegador sin CustomEvent simplemente no ve el prompt. Nunca rompe.
    }
  }

  // ── Envío ─────────────────────────────────────────────────────
  function send() {
    maybeNotifyEngaged();
    var secs = currentEngaged();

    // Nada nuevo que contar: ni el tiempo avanzó ni apareció interacción.
    // Ahorra peticiones en pestañas quietas sin perder ningún dato, porque el
    // servidor aplica GREATEST y un envío perdido lo recupera el siguiente.
    if (secs === lastSent && !interacted) return;
    lastSent = secs;

    var payload = JSON.stringify({
      resource_id: resourceId,
      vid: vid,
      surface: surface,
      engaged_secs: secs,
      interacted: interacted ? 1 : 0
    });

    try {
      // sendBeacon es el único envío que el navegador garantiza durante la
      // descarga de la página. Va como text/plain, que evita el preflight CORS;
      // api/track.php lee el cuerpo con json_body(), al que el Content-Type le
      // da igual.
      if (navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, payload)) return;

      // Reserva para navegadores sin sendBeacon. keepalive permite que la
      // petición sobreviva a la navegación.
      fetch(ENDPOINT, {
        method: 'POST',
        body: payload,
        keepalive: true,
        headers: { 'Content-Type': 'application/json' }
      }).catch(function () {});
    } catch (e) {
      // Un fallo midiendo jamás puede afectar a quien está usando el recurso.
    }
  }

  // ── Ciclo de vida ─────────────────────────────────────────────

  // Primer envío inmediato: registra la visita aunque la persona se marche al
  // segundo siguiente. Ese caso —abrir y largarse— es justo el que hay que
  // poder distinguir del uso real.
  startClock();
  send();

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      stopClock();
      send();
    } else {
      startClock();
    }
  });

  // pagehide cubre el cierre y la navegación; en iOS es más fiable que unload.
  window.addEventListener('pagehide', function () {
    stopClock();
    send();
  });

  // Latido de reserva. visibilitychange y pagehide cubren casi todo, pero una
  // pestaña que el sistema mata sin avisar no dispara ninguno de los dos. Cada
  // 60 s se consolida lo acumulado hasta el tope, de modo que como mucho se
  // pierde el último minuto.
  var beats = 0;
  var timer = setInterval(function () {
    if (++beats > 120) { clearInterval(timer); return; }  // 2 h, el mismo tope que el servidor
    if (document.visibilityState === 'visible') send();
  }, 60000);

  // Comprobación aparte del envío, cada 15 s. Sin esto, el aviso de "ya ha
  // usado el recurso" sólo podría dispararse en un beacon —es decir, hasta 60 s
  // tarde— y el prompt aparecería mucho después del momento natural.
  var gate = setInterval(function () {
    if (notified) { clearInterval(gate); return; }
    if (document.visibilityState === 'visible') maybeNotifyEngaged();
  }, 15000);
})();
