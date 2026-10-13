// Selection overlay ported from PGHQdev/OpenScreenShot (MIT) — see LICENSES.md. The crop below is our own.

const LOCALSY_SNAPSHOT_MIN_SIDE = 2;

function localsyClamp(value, low, high) {
  return Math.max(low, Math.min(high, value));
}

function localsySnapshotOverlay(frozenFrameDataUrl, viewportWidth, viewportHeight) {
  return new Promise((resolve) => {
    const doc = document;
    const viewportW = viewportWidth || window.innerWidth;
    const viewportH = viewportHeight || window.innerHeight;

    let selection = null;
    let dragMode = null;
    let resizeCorner = null;
    let dragStart = { x: 0, y: 0 };
    let dragOrigin = { x: 0, y: 0, w: 0, h: 0 };
    let finished = false;

    const root = doc.createElement('div');
    root.style.cssText = 'position:fixed;inset:0;z-index:2147483647;pointer-events:none;';

    const backdrop = doc.createElement('img');
    backdrop.draggable = false;
    backdrop.src = frozenFrameDataUrl;
    backdrop.style.cssText =
      'position:absolute;left:0;top:0;width:100%;height:100%;' +
      'user-select:none;-webkit-user-drag:none;pointer-events:none;';
    root.appendChild(backdrop);

    const mask = doc.createElement('div');
    mask.style.cssText =
      'display:none;position:absolute;box-shadow:0 0 0 9999px rgba(0,0,0,0.45);' +
      'border:2px dashed #000;outline:1px solid #fff;outline-offset:1px;box-sizing:border-box;' +
      'pointer-events:none;';
    root.appendChild(mask);

    const layer = doc.createElement('div');
    layer.style.cssText = 'position:absolute;inset:0;cursor:crosshair;pointer-events:auto;';
    root.appendChild(layer);

    const readout = doc.createElement('div');
    readout.style.cssText =
      'display:none;position:absolute;background:rgba(32,33,36,0.92);color:#fff;' +
      'font:500 12px/1.4 Roboto,Arial,sans-serif;padding:4px 8px;border-radius:8px;' +
      'pointer-events:none;white-space:nowrap;';
    root.appendChild(readout);

    const corners = ['nw', 'ne', 'sw', 'se'];
    const handles = [];
    for (const corner of corners) {
      const handleEl = doc.createElement('div');
      const cursor = corner === 'nw' || corner === 'se' ? 'nwse-resize' : 'nesw-resize';
      handleEl.style.cssText =
        'display:none;position:absolute;width:13px;height:13px;background:#fff;' +
        `border:2px solid #000;border-radius:50%;pointer-events:auto;cursor:${cursor};box-sizing:border-box;`;
      handles.push({ el: handleEl, corner });
      root.appendChild(handleEl);
    }

    const bar = doc.createElement('div');
    bar.style.cssText =
      'display:none;position:absolute;gap:8px;align-items:center;pointer-events:auto;' +
      'background:rgba(32,33,36,0.96);padding:8px;border-radius:24px;' +
      'box-shadow:0 4px 16px rgba(0,0,0,0.35);font:500 12px/1 Roboto,Arial,sans-serif;';
    const captureBtn = doc.createElement('button');
    captureBtn.type = 'button';
    captureBtn.textContent = 'Send to Localsy';
    captureBtn.style.cssText =
      'border:none;border-radius:18px;padding:8px 16px;background:#a8c7fa;color:#062e6f;' +
      'font:inherit;cursor:pointer;';
    const cancelBtn = doc.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.cssText =
      'border:none;border-radius:18px;padding:8px 12px;background:transparent;color:#cfcfcf;' +
      'font:inherit;cursor:pointer;';
    bar.appendChild(captureBtn);
    bar.appendChild(cancelBtn);
    root.appendChild(bar);

    const hint = doc.createElement('div');
    hint.textContent = 'Drag to select a region · Enter sends · Esc cancels';
    hint.style.cssText =
      'position:absolute;left:50%;top:16px;transform:translateX(-50%);background:rgba(32,33,36,0.92);' +
      'color:#e8eaed;font:500 12px/1.4 Roboto,Arial,sans-serif;padding:6px 12px;border-radius:16px;' +
      'pointer-events:none;white-space:nowrap;';
    root.appendChild(hint);

    doc.body.appendChild(root);

    const finish = (result) => {
      if (finished) return;
      finished = true;
      doc.removeEventListener('keydown', onKeyDown, true);
      doc.removeEventListener('mousemove', onMouseMove, true);
      doc.removeEventListener('mouseup', onMouseUp, true);
      window.removeEventListener('resize', render);
      root.remove();
      resolve(result);
    };

    const confirmSelection = () => {
      if (selection && selection.w >= LOCALSY_SNAPSHOT_MIN_SIDE && selection.h >= LOCALSY_SNAPSHOT_MIN_SIDE) {
        finish({ x: selection.x, y: selection.y, w: selection.w, h: selection.h });
      }
    };

    const render = () => {
      if (!selection) {
        mask.style.display = 'none';
        readout.style.display = 'none';
        bar.style.display = 'none';
        for (const handle of handles) handle.el.style.display = 'none';
        return;
      }
      const { x, y, w, h } = selection;
      mask.style.display = 'block';
      mask.style.left = `${x}px`;
      mask.style.top = `${y}px`;
      mask.style.width = `${w}px`;
      mask.style.height = `${h}px`;
      for (const handle of handles) {
        handle.el.style.display = 'block';
        handle.el.style.left = `${x + (handle.corner === 'ne' || handle.corner === 'se' ? w : -6)}px`;
        handle.el.style.top = `${y + (handle.corner === 'sw' || handle.corner === 'se' ? h : -6)}px`;
      }
      readout.style.display = 'block';
      readout.textContent = `${Math.round(w)} × ${Math.round(h)}`;
      readout.style.left = `${localsyClamp(x, 0, Math.max(0, viewportW - 90))}px`;
      readout.style.top = `${y - 26 < 0 ? y + h + 6 : y - 26}px`;
      if (dragMode === null) {
        bar.style.display = 'flex';
        bar.style.left = `${localsyClamp(x + w - 190, 6, Math.max(6, viewportW - 190))}px`;
        bar.style.top = `${y + h + 8 + 44 > viewportH ? Math.max(6, y - 52) : y + h + 8}px`;
      } else {
        bar.style.display = 'none';
      }
    };

    const setSelection = (x, y, w, h) => {
      const nx = localsyClamp(w >= 0 ? x : x + w, 0, viewportW);
      const ny = localsyClamp(h >= 0 ? y : y + h, 0, viewportH);
      selection = {
        x: nx,
        y: ny,
        w: Math.min(Math.abs(w), viewportW - nx),
        h: Math.min(Math.abs(h), viewportH - ny),
      };
      render();
    };

    const insideSelection = (point) =>
      selection !== null &&
      point.x >= selection.x &&
      point.x <= selection.x + selection.w &&
      point.y >= selection.y &&
      point.y <= selection.y + selection.h;

    const onMouseDown = (event) => {
      if (event.button !== 0) return;
      const point = { x: event.clientX, y: event.clientY };
      const hit = handles.find((handle) => handle.el === event.target);
      if (hit) {
        dragMode = 'resize';
        resizeCorner = hit.corner;
      } else if (selection && insideSelection(point)) {
        dragMode = 'move';
      } else {
        dragMode = 'create';
        setSelection(point.x, point.y, 0, 0);
      }
      dragStart = point;
      dragOrigin = selection ? { ...selection } : { x: point.x, y: point.y, w: 0, h: 0 };
      event.preventDefault();
      event.stopPropagation();
    };

    const onMouseMove = (event) => {
      if (!dragMode) return;
      const point = { x: event.clientX, y: event.clientY };
      const dx = point.x - dragStart.x;
      const dy = point.y - dragStart.y;
      if (dragMode === 'create') {
        setSelection(dragOrigin.x, dragOrigin.y, point.x - dragOrigin.x, point.y - dragOrigin.y);
      } else if (dragMode === 'move' && selection) {
        selection = {
          x: localsyClamp(dragOrigin.x + dx, 0, viewportW - selection.w),
          y: localsyClamp(dragOrigin.y + dy, 0, viewportH - selection.h),
          w: selection.w,
          h: selection.h,
        };
        render();
      } else if (dragMode === 'resize' && resizeCorner && selection) {
        let { x, y } = dragOrigin;
        let w;
        let h;
        if (resizeCorner === 'nw' || resizeCorner === 'ne') {
          y = localsyClamp(dragOrigin.y + dy, 0, dragOrigin.y + dragOrigin.h - 1);
          h = dragOrigin.y + dragOrigin.h - y;
        } else {
          h = localsyClamp(point.y - dragOrigin.y, 1, viewportH - dragOrigin.y);
        }
        if (resizeCorner === 'nw' || resizeCorner === 'sw') {
          x = localsyClamp(dragOrigin.x + dx, 0, dragOrigin.x + dragOrigin.w - 1);
          w = dragOrigin.x + dragOrigin.w - x;
        } else {
          w = localsyClamp(point.x - dragOrigin.x, 1, viewportW - dragOrigin.x);
        }
        selection = { x, y, w, h };
        render();
      }
      event.preventDefault();
    };

    const onMouseUp = () => {
      dragMode = null;
      resizeCorner = null;
      render();
    };

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        finish(null);
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        event.stopPropagation();
        confirmSelection();
        return;
      }
      if (!selection) return;
      const step = event.shiftKey ? 10 : 1;
      const { w, h } = selection;
      let { x, y } = selection;
      let handled = true;
      if (event.key === 'ArrowLeft') x = localsyClamp(x - step, 0, viewportW - w);
      else if (event.key === 'ArrowRight') x = localsyClamp(x + step, 0, viewportW - w);
      else if (event.key === 'ArrowUp') y = localsyClamp(y - step, 0, viewportH - h);
      else if (event.key === 'ArrowDown') y = localsyClamp(y + step, 0, viewportH - h);
      else handled = false;
      if (handled) {
        event.preventDefault();
        event.stopPropagation();
        selection = { x, y, w, h };
        render();
      }
    };

    const blockScroll = (event) => event.preventDefault();

    bar.addEventListener('mousedown', (event) => event.stopPropagation());
    captureBtn.addEventListener('click', confirmSelection);
    cancelBtn.addEventListener('click', () => finish(null));
    layer.addEventListener('mousedown', onMouseDown);
    for (const handle of handles) handle.el.addEventListener('mousedown', onMouseDown);
    doc.addEventListener('mousemove', onMouseMove, true);
    doc.addEventListener('mouseup', onMouseUp, true);
    doc.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('resize', render);
    root.addEventListener('wheel', blockScroll, { passive: false });
    root.addEventListener('touchmove', blockScroll, { passive: false });
  });
}

function localsyCropCapture(frozenFrameDataUrl, selection) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => {
      const displayWidth = window.innerWidth;
      const displayHeight = window.innerHeight;
      const scaleX = image.naturalWidth / displayWidth;
      const scaleY = image.naturalHeight / displayHeight;
      if (!(scaleX > 0) || !(scaleY > 0)) {
        reject(new Error('The captured frame has no usable size.'));
        return;
      }
      const left = localsyClamp(Math.round(selection.x * scaleX), 0, image.naturalWidth);
      const top = localsyClamp(Math.round(selection.y * scaleY), 0, image.naturalHeight);
      const right = localsyClamp(Math.round((selection.x + selection.w) * scaleX), 0, image.naturalWidth);
      const bottom = localsyClamp(Math.round((selection.y + selection.h) * scaleY), 0, image.naturalHeight);
      const width = right - left;
      const height = bottom - top;
      if (width < 1 || height < 1) {
        reject(new Error('The selected area is outside the captured frame.'));
        return;
      }
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const context = canvas.getContext('2d');
      if (!context) {
        reject(new Error('Could not create a canvas context.'));
        return;
      }
      context.drawImage(image, left, top, width, height, 0, 0, width, height);
      resolve({ dataUrl: canvas.toDataURL('image/png'), width, height });
    };
    image.onerror = () => reject(new Error('Could not decode the captured frame.'));
    image.src = frozenFrameDataUrl;
  });
}
