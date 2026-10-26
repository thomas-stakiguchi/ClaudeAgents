import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { clampNode, laneTops, nodeRect, portPoint, worldWidth, contentRight, type Port } from '../model/geometry';
import { LANE_H, nodeSize, type Node } from '../model/types';
import { connect } from '../store/actions';
import { beginGesture, editSilent, getState, setState, useStore, type View } from '../store/flowStore';
import { EdgeLayer } from './EdgeLayer';
import { LaneLayer } from './Lane';
import { NodeLayer } from './NodeView';

const K_MIN = 0.25, K_MAX = 2.5;
const clampK = (k: number) => Math.min(K_MAX, Math.max(K_MIN, k));

/** ツールバーから呼ぶための口（全体表示・画像書き出し） */
export const canvasApi = {
  fit: () => {},
  world: null as HTMLDivElement | null,
};

type Drag =
  | { type: 'pan'; sx: number; sy: number; vx: number; vy: number; moved: boolean }
  | { type: 'node'; id: string; ox: number; oy: number; sx: number; sy: number; moved: boolean }
  | { type: 'link'; sx: number; sy: number; moved: boolean };

export function Canvas() {
  const flow = useStore((s) => s.flow);
  const sel = useStore((s) => s.sel);
  const tool = useStore((s) => s.tool);
  const connectSrc = useStore((s) => s.connectSrc);
  const view = useStore((s) => s.view);
  const exporting = useStore((s) => s.exporting);
  const flashMsg = useStore((s) => s.flash);

  const canvasRef = useRef<HTMLDivElement>(null);
  const worldRef = useRef<HTMLDivElement>(null);
  const drag = useRef<Drag | null>(null);
  const [mouse, setMouse] = useState<[number, number] | null>(null);
  const [dragging, setDragging] = useState(false);

  const lanes = flow?.lanes, nodes = flow?.nodes;
  const tops = useMemo(() => (flow ? laneTops(flow) : new Map<string, number>()), [lanes]); // eslint-disable-line react-hooks/exhaustive-deps
  const width = useMemo(() => (flow ? worldWidth(flow) : 0), [nodes]); // eslint-disable-line react-hooks/exhaustive-deps
  const height = Math.max(1, lanes?.length ?? 0) * LANE_H;

  const toWorld = (cx: number, cy: number): [number, number] => {
    const r = canvasRef.current!.getBoundingClientRect();
    const v = getState().view;
    return [(cx - r.left - v.x) / v.k, (cy - r.top - v.y) / v.k];
  };

  const fit = useCallback(() => {
    const f = getState().flow;
    const el = canvasRef.current;
    if (!f || !el) return;
    const r = el.getBoundingClientRect();
    const W = contentRight(f) + 40, H = Math.max(1, f.lanes.length) * LANE_H;
    const k = Math.min(2, Math.max(K_MIN, Math.min((r.width - 40) / W, (r.height - 40) / H)));
    setState({ view: { x: 20, y: 20, k } });
  }, []);

  useEffect(() => {
    canvasApi.fit = fit;
    canvasApi.world = worldRef.current;
  }, [fit]);

  // 図を開いたら全体表示
  const flowId = flow?.id;
  useEffect(() => {
    if (flowId) fit();
  }, [flowId, fit]);

  // ホイール：上下移動、Ctrl+ホイール：拡大縮小（preventDefault のため passive:false で登録）
  useEffect(() => {
    const el = canvasRef.current!;
    const onWheel = (ev: WheelEvent) => {
      ev.preventDefault();
      const v = getState().view;
      if (ev.ctrlKey || ev.metaKey) {
        const r = el.getBoundingClientRect();
        const px = ev.clientX - r.left, py = ev.clientY - r.top;
        const k = clampK(v.k * (ev.deltaY < 0 ? 1.1 : 0.9));
        setState({ view: { x: px - ((px - v.x) * k) / v.k, y: py - ((py - v.y) * k) / v.k, k } });
      } else {
        setState({ view: { ...v, x: v.x - ev.deltaX, y: v.y - ev.deltaY } });
      }
    };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, []);

  // 接続をやめたら仮の線を消す
  useEffect(() => {
    if (!connectSrc) setMouse(null);
  }, [connectSrc]);

  const onPointerDown = (ev: React.PointerEvent) => {
    if (ev.button !== 0) return;
    const t = ev.target as Element;
    const port = t.closest('.port');
    const nodeEl = t.closest<HTMLElement>('.nwrap');
    const hit = t.closest<SVGPathElement>('path.hit');
    const head = t.closest<HTMLElement>('.lane .head');
    const s = getState();
    const capture = () => canvasRef.current!.setPointerCapture(ev.pointerId);

    // 接続中：相手の部品をクリックで確定
    if (s.connectSrc && nodeEl) {
      const id = nodeEl.dataset.id!;
      if (id !== s.connectSrc) connect(s.connectSrc, id);
      return;
    }
    // ポートをクリック → 線が追従
    if (port && nodeEl) {
      setState({ connectSrc: nodeEl.dataset.id!, sel: null });
      setMouse(toWorld(ev.clientX, ev.clientY));
      drag.current = { type: 'link', sx: ev.clientX, sy: ev.clientY, moved: false };
      capture();
      return;
    }
    if (nodeEl) {
      const id = nodeEl.dataset.id!;
      if (s.tool === 'connect') {
        setState({ connectSrc: id });
        return;
      }
      const n = s.flow!.nodes.find((x) => x.id === id)!;
      const [wx, wy] = toWorld(ev.clientX, ev.clientY);
      drag.current = { type: 'node', id, ox: wx - n.x, oy: wy - ((tops.get(n.lane) ?? 0) + n.ly), sx: ev.clientX, sy: ev.clientY, moved: false };
      setState({ sel: { kind: 'node', id } });
      capture();
      return;
    }
    if (hit) return void setState({ sel: { kind: 'edge', id: hit.dataset.id! }, connectSrc: null });
    if (head) return void setState({ sel: { kind: 'lane', id: head.dataset.lane! }, connectSrc: null });
    drag.current = { type: 'pan', sx: ev.clientX, sy: ev.clientY, vx: s.view.x, vy: s.view.y, moved: false };
    capture();
  };

  const onPointerMove = (ev: React.PointerEvent) => {
    if (getState().connectSrc) setMouse(toWorld(ev.clientX, ev.clientY));
    const d = drag.current;
    if (!d) return;
    const far = Math.abs(ev.clientX - d.sx) + Math.abs(ev.clientY - d.sy) > 3;
    if (d.type === 'pan') {
      if (!d.moved && !far) return;
      d.moved = true;
      const v = getState().view;
      setState({ view: { ...v, x: d.vx + ev.clientX - d.sx, y: d.vy + ev.clientY - d.sy } });
      return;
    }
    if (d.type === 'link') {
      if (far) d.moved = true;
      return;
    }
    if (!d.moved) {
      if (!far) return;
      d.moved = true;
      beginGesture();
      setDragging(true);
    }
    const [wx, wy] = toWorld(ev.clientX, ev.clientY);
    editSilent((f) => {
      const n = f.nodes.find((x) => x.id === d.id);
      if (!n) return;
      n.x = Math.round((wx - d.ox) / 10) * 10; // 10px グリッドにスナップ
      const absY = wy - d.oy;
      const li = Math.max(0, Math.min(f.lanes.length - 1, Math.floor((absY + nodeSize(n.type)[1] / 2) / LANE_H)));
      n.lane = f.lanes[li].id;
      n.ly = Math.round((absY - li * LANE_H) / 10) * 10;
      clampNode(n);
    });
  };

  const onPointerUp = (ev: React.PointerEvent) => {
    const d = drag.current;
    drag.current = null;
    setDragging(false);
    if (!d) return;
    if (d.type === 'pan' && !d.moved) setState({ sel: null, connectSrc: null, confirmDel: null });
    if (d.type === 'link' && d.moved) {
      // ポートからドラッグして部品の上で離しても繋がる
      const el = document.elementFromPoint(ev.clientX, ev.clientY)?.closest<HTMLElement>('.nwrap');
      const src = getState().connectSrc;
      if (el && src && el.dataset.id !== src) connect(src, el.dataset.id!);
    }
  };

  const onDoubleClick = (ev: React.MouseEvent) => {
    const t = ev.target as Element;
    const nodeEl = t.closest<HTMLElement>('.nwrap');
    const head = t.closest<HTMLElement>('.lane .head');
    const target = nodeEl ? '#p-label' : head ? '#p-lane-name' : null;
    if (nodeEl) setState({ sel: { kind: 'node', id: nodeEl.dataset.id! } });
    else if (head) setState({ sel: { kind: 'lane', id: head.dataset.lane! } });
    if (target)
      requestAnimationFrame(() => {
        const el = document.querySelector<HTMLInputElement>(target);
        el?.focus();
        el?.select();
      });
  };

  // 接続中の仮の線：元の部品の、マウスに一番近い辺の中央から
  let pending: string | null = null;
  if (connectSrc && mouse && flow) {
    const src = flow.nodes.find((n) => n.id === connectSrc);
    if (src) pending = pendingPath(src, tops.get(src.lane) ?? 0, mouse);
  }

  const showSel = !exporting;
  const cls = ['canvas', tool === 'connect' && 'connect', connectSrc && 'linking', dragging && 'dragging'].filter(Boolean).join(' ');

  return (
    <div
      className={cls}
      ref={canvasRef}
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
      onDoubleClick={onDoubleClick}
    >
      <div className={`world${exporting ? ' exporting' : ''}`} ref={worldRef} style={{ transform: viewTransform(view) }}>
        {flow && (
          <>
            <LaneLayer lanes={flow.lanes} width={width} selId={showSel && sel?.kind === 'lane' ? sel.id : null} />
            <EdgeLayer
              edges={flow.edges}
              nodes={flow.nodes}
              tops={tops}
              selId={showSel && sel?.kind === 'edge' ? sel.id : null}
              width={width}
              height={height}
              pending={showSel ? pending : null}
            />
            <NodeLayer nodes={flow.nodes} tops={tops} selId={showSel && sel?.kind === 'node' ? sel.id : null} srcId={showSel ? connectSrc : null} />
          </>
        )}
      </div>
      {flashMsg && <div className="toast">{flashMsg}</div>}
    </div>
  );
}

const viewTransform = (v: View) => `translate(${v.x}px,${v.y}px) scale(${v.k})`;

function pendingPath(src: Node, top: number, [mx, my]: [number, number]) {
  const r = nodeRect(src, top);
  const ports: Port[] = ['top', 'right', 'bottom', 'left'];
  let best = portPoint(r, 'right'), bd = Infinity;
  for (const p of ports) {
    const pt = portPoint(r, p);
    const dd = (pt[0] - mx) ** 2 + (pt[1] - my) ** 2;
    if (dd < bd) {
      bd = dd;
      best = pt;
    }
  }
  return `M${best[0]} ${best[1]}L${mx} ${my}`;
}
