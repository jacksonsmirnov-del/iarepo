// Registers the iarepo service worker (enables install / offline shell).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* non-fatal */ });
  });
}
