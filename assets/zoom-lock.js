(() => {
  const LOCKED_VIEWPORT = 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover';

  const lockViewport = () => {
    let viewport = document.querySelector('meta[name="viewport"]');
    if (!viewport) {
      viewport = document.createElement('meta');
      viewport.name = 'viewport';
      document.head.appendChild(viewport);
    }
    viewport.setAttribute('content', LOCKED_VIEWPORT);
  };

  const injectIOSInputFix = () => {
    const style = document.createElement('style');
    style.textContent = `
      @supports (-webkit-touch-callout: none) {
        input,
        select,
        textarea {
          font-size: 16px !important;
        }
      }
    `;
    document.head.appendChild(style);
  };

  const blockKeyboardAndWheelZoom = () => {
    window.addEventListener('wheel', (event) => {
      if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
      }
    }, { passive: false });

    window.addEventListener('keydown', (event) => {
      const key = event.key;
      const zoomKeys = ['+', '=', '-', '_', '0'];
      if ((event.ctrlKey || event.metaKey) && zoomKeys.includes(key)) {
        event.preventDefault();
      }
    });
  };

  const blockTouchZoom = () => {
    ['gesturestart', 'gesturechange', 'gestureend'].forEach((gestureEvent) => {
      window.addEventListener(gestureEvent, (event) => {
        event.preventDefault();
      }, { passive: false });
    });

    let lastTouchEnd = 0;
    window.addEventListener('touchend', (event) => {
      const now = Date.now();
      if (now - lastTouchEnd <= 300) {
        event.preventDefault();
      }
      lastTouchEnd = now;
    }, { passive: false });
  };

  lockViewport();
  injectIOSInputFix();
  blockKeyboardAndWheelZoom();
  blockTouchZoom();
})();
