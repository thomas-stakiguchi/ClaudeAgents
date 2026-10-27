import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { clampNode, laneHeight, laneIndexAt, laneTops, nodeRect, portPoint, totalHeight, worldWidth, contentRight, type Port } from '../model/geometry';
import { LANE_H, nodeSize, type Node, type Side } from '../model/types';
import { connect, selectNodes, setLaneHeight, startEdit } from '../store/actions';
import { beginGesture, editSilent, getState, selectedNodeIds, setState, useStore, type View } from '../store/flowStore';
import { EdgeLayer } from './EdgeLayer';
import { ExportHeader } from './ExportHeader';
import { LaneLayer } from './Lane';
import { NodeLayer } from './NodeView';

const K_MIN = 0.25, K_MAX = 2.5;
const clampK = (k: number) => Math.min(K_MAX, Math.max(K_MIN, k));

/** ツールバーから呼ぶための口（全体表示・画像書き出し） */
export const canvasApi = {
  fit: (_mode?: 'all' | 'open') => {},
  world: null as HTMLDivElement | null,
};

type Drag =
  | { type: 'pan'; sx: number; sy: number; vx: number; vy: number; moved: boolean }
  | { type: 'node'; starts: { id: string; x: number; y: number }[]; wx: number; wy: number; sx: number; sy: number; moved: boolean }
  | { type: 'link'; sx: number; sy: number; moved: boolean }
  | { type: 'laneh'; id: string; h0: number; sx: number; sy: number; moved: boolean }
  | { type: 'box'; wx: number; wy: number; sx: number; sy: number; add: boolean; moved: boolean };

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
  const [box, setBox] = useState<[number, number, number, number] | null>(null); // 範囲選択の四角（図の座標）
  const fromSide = useRef<Side | undefined>(undefined); // ポートから引き始めた辺
  const editing = useStore((s) => s.editing);

  const lanes = flow?.lanes, nodes = flow?.nodes;
  const tops = useMemo(() => (flow ? laneTops(flow) : new Map<string, number>()), [lanes]); // eslint-disable-line react-hooks/exhaustive-deps
  const width = useMemo(() => (flow ? worldWidth(flow) : 0), [nodes]); // eslint-disable-line react-hooks/exhaustive-deps
  const height = lanes?.length ? totalHeight({ lanes }) : LANE_H;

  const toWorld = (cx: number, cy: number): [number, number] => {
    const r = canvasRef.current!.getBoundingClientRect();
    const v = getState().view;
    return [(cx - r.left - v.x) / v.k, (cy - r.top - v.y) / v.k];
  };

  /**
   * all：図の全体が入る倍率（全体表示ボタン）
   * open：図を開いたとき。全体が入る倍率を目安にするが、文字が読めないほど小さくはせず（70%まで）、
   *       空の図などで大きくなりすぎないようにする（100%まで）
   */
  const fit = useCallback((mode: 'all' | 'open' = 'all') => {
    const f = getState().flow;
    const el = canvasRef.current;
    if (!f || !el) return;
    const r = el.getBoundingClientRect();
    const W = contentRight(f) + 40, H = f.lanes.length ? totalHeight(f) : LANE_H;
    const whole = Math.min((r.width - 40) / W, (r.height - 40) / H);
    const k = mode === 'open' ? Math.min(1, Math.max(0.7, whole)) : Math.min(2, Math.max(K_MIN, whole));
    setState({ view: { x: 20, y: 20, k } });
  }, []);

  useEffect(() => {
    canvasApi.fit = fit;
    canvasApi.world = worldRef.current;
  }, [fit]);

  // 図を開いたら全体表示
  const flowId = flow?.id;
  useEffect(() => {
    if (flowId) fit('open');
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
    const resize = t.closest<HTMLElement>('.lane-resize');
    const s = getState();
    const capture = () => canvasRef.current!.setPointerCapture(ev.pointerId);

    if (t.closest('.lane-size')) return; // 枠の「広く／狭く」ボタンはクリックで動く
    // 枠の下の境目：ドラッグで高さを変える
    if (resize && !nodeEl && !port) {
      const lane = s.flow!.lanes.find((l) => l.id === resize.dataset.lane)!;
      drag.current = { type: 'laneh', id: lane.id, h0: laneHeight(lane), sx: ev.clientX, sy: ev.clientY, moved: false };
      capture();
      return;
    }

    // 接続中：相手の部品をクリックで確定
    if (s.connectSrc && nodeEl) {
      const id = nodeEl.dataset.id!;
      if (id !== s.connectSrc) connect(s.connectSrc, id, { fromSide: fromSide.current });
      fromSide.current = undefined;
      return;
    }
    // ポートをクリック → 線が追従
    if (port && nodeEl) {
      fromSide.current = (port as HTMLElement).dataset.port as Side;
      setState({ connectSrc: nodeEl.dataset.id!, sel: null });
      setMouse(toWorld(ev.clientX, ev.clientY));
      drag.current = { type: 'link', sx: ev.clientX, sy: ev.clientY, moved: false };
      capture();
      return;
    }
    if (nodeEl) {
      const id = nodeEl.dataset.id!;
      if (s.tool === 'connect') {
        fromSide.current = undefined;
        setState({ connectSrc: id });
        return;
      }
      if (s.editing === id) return; // 名前を書いている最中
      // Shift／Ctrl+クリック：選択に足す・外す
      if (ev.shiftKey || ev.ctrlKey || ev.metaKey) {
        selectNodes([id], true);
        return;
      }
      // 複数選んでいる中の1つをつかんだら、まとめて動かす
      const cur = selectedNodeIds(s.sel);
      const ids = cur.includes(id) ? cur : [id];
      if (!cur.includes(id)) setState({ sel: { kind: 'node', id } });
      const [wx, wy] = toWorld(ev.clientX, ev.clientY);
      const starts = s.flow!.nodes.filter((n) => ids.includes(n.id)).map((n) => ({ id: n.id, x: n.x, y: (tops.get(n.lane) ?? 0) + n.ly }));
      drag.current = { type: 'node', starts, wx, wy, sx: ev.clientX, sy: ev.clientY, moved: false };
      capture();
      return;
    }
    if (hit) return void setState({ sel: { kind: 'edge', id: hit.dataset.id! }, connectSrc: null });
    if (head) return void setState({ sel: { kind: 'lane', id: head.dataset.lane! }, connectSrc: null });
    // Shift+背景ドラッグ：範囲選択。ふつうの背景ドラッグ：画面を動かす
    if (ev.shiftKey) {
      const [wx, wy] = toWorld(ev.clientX, ev.clientY);
      drag.current = { type: 'box', wx, wy, sx: ev.clientX, sy: ev.clientY, add: ev.ctrlKey || ev.metaKey, moved: false };
    } else {
      drag.current = { type: 'pan', sx: ev.clientX, sy: ev.clientY, vx: s.view.x, vy: s.view.y, moved: false };
    }
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
    if (d.type === 'laneh') {
      if (!d.moved) {
        if (!far) return;
        d.moved = true;
        beginGesture();
      }
      setLaneHeight(d.id, d.h0 + (ev.clientY - d.sy) / getState().view.k, { silent: true });
      return;
    }
    if (d.type === 'box') {
      if (!d.moved && !far) return;
      d.moved = true;
      const [wx, wy] = toWorld(ev.clientX, ev.clientY);
      setBox([Math.min(d.wx, wx), Math.min(d.wy, wy), Math.abs(wx - d.wx), Math.abs(wy - d.wy)]);
      return;
    }
    if (!d.moved) {
      if (!far) return;
      d.moved = true;
      beginGesture();
      setDragging(true);
    }
    const [wx, wy] = toWorld(ev.clientX, ev.clientY);
    const dx = Math.round((wx - d.wx) / 10) * 10, dy = Math.round((wy - d.wy) / 10) * 10; // 10px グリッドにスナップ
    editSilent((f) => {
      const tp = laneTops(f);
      for (const st of d.starts) {
        const n = f.nodes.find((x) => x.id === st.id);
        if (!n) continue;
        n.x = st.x + dx;
        const absY = st.y + dy;
        const lane = f.lanes[laneIndexAt(f.lanes, absY + nodeSize(n.type)[1] / 2)];
        n.lane = lane.id;
        n.ly = absY - (tp.get(lane.id) ?? 0);
        clampNode(n, f.lanes);
      }
    });
  };

  const onPointerUp = (ev: React.PointerEvent) => {
    const d = drag.current;
    drag.current = null;
    setDragging(false);
    if (!d) return;
    if (d.type === 'pan' && !d.moved) {
      // 背景をクリックしただけなら、その場所の枠（レーン）を選ぶ。枠の外なら選択を外す
      const f = getState().flow;
      const [wx, wy] = toWorld(ev.clientX, ev.clientY);
      const inside = f && f.lanes.length && wx >= 0 && wx <= width && wy >= 0 && wy < totalHeight(f);
      const lane = inside ? f.lanes[laneIndexAt(f.lanes, wy)] : undefined;
      setState({ sel: lane ? { kind: 'lane', id: lane.id } : null, connectSrc: null, confirmDel: null });
    }
    if (d.type === 'box') {
      setBox(null);
      const f = getState().flow;
      if (!d.moved || !f) return;
      const [wx, wy] = toWorld(ev.clientX, ev.clientY);
      const x0 = Math.min(d.wx, wx), x1 = Math.max(d.wx, wx), y0 = Math.min(d.wy, wy), y1 = Math.max(d.wy, wy);
      // 四角に少しでもかかった部品を選ぶ
      const hits = f.nodes.filter((n) => {
        const r = nodeRect(n, tops.get(n.lane) ?? 0);
        return r.x < x1 && r.x + r.w > x0 && r.y < y1 && r.y + r.h > y0;
      });
      selectNodes(hits.map((n) => n.id), d.add);
      return;
    }
    if (d.type === 'link' && d.moved) {
      // ポートからドラッグして部品の上で離しても繋がる
      const el = document.elementFromPoint(ev.clientX, ev.clientY)?.closest<HTMLElement>('.nwrap');
      const src = getState().connectSrc;
      if (el && src && el.dataset.id !== src) connect(src, el.dataset.id!, { fromSide: fromSide.current });
    }
  };

  const onDoubleClick = (ev: React.MouseEvent) => {
    // pointerdown でキャンバスがポインターを掴むので、ev.target はキャンバス自体になる。
    // 実際にマウスの下にある要素を見る
    const t = document.elementFromPoint(ev.clientX, ev.clientY) ?? (ev.target as Element);
    if (t.closest('.lane-size, .lane-resize')) return; // 「広く」を続けて押したときに名前の入力が始まらないように
    const nodeEl = t.closest<HTMLElement>('.nwrap');
    const head = t.closest<HTMLElement>('.lane .head');
    const hit = t.closest<SVGPathElement>('path.hit');
    if (nodeEl) return startEdit(nodeEl.dataset.id!); // 部品の上でそのまま名前を書く
    const target = hit ? '#p-elabel' : head ? '#p-lane-name' : null;
    if (hit) setState({ sel: { kind: 'edge', id: hit.dataset.id! } });
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
  const selIds = useMemo(() => new Set(selectedNodeIds(sel)), [sel]);
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
      <div
        className={`world${exporting ? ' exporting' : ''}${exporting && exporting.plain ? ' plain' : ''}`}
        ref={worldRef}
        style={{ transform: viewTransform(view), width: exporting ? width : undefined }}
      >
        {flow && exporting && exporting.header && <ExportHeader flow={flow} />}
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
            <NodeLayer nodes={flow.nodes} tops={tops} selIds={showSel ? selIds : NO_IDS} srcId={showSel ? connectSrc : null} editingId={editing} />
            {box && <div className="selbox" style={{ left: box[0], top: box[1], width: box[2], height: box[3] }} />}
          </>
        )}
      </div>
      {flashMsg && <div className="toast">{flashMsg}</div>}
    </div>
  );
}

const NO_IDS = new Set<string>();
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
