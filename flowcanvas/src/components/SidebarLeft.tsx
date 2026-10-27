import { useState } from 'react';
import type { EdgeKind, LaneKind, NodeType, Route } from '../model/types';
import { addLane, addNode, deleteLane, moveLane, runSentence, targetLane } from '../store/actions';
import { setState, useStore } from '../store/flowStore';
import { DRAG_TYPE } from './Canvas';
import { NodeIcon } from './Icons';

type Part = { type: NodeType; title: string; mark?: string; sub: string; wide?: boolean };

const BASIC: Part[] = [
  { type: 'start', mark: '●', title: '開始', sub: 'フローの起点' },
  { type: 'end', mark: '◉', title: '終了', sub: 'フローの終点' },
  { type: 'task', mark: '▭', title: '作業', sub: '担当者がやること' },
  { type: 'decision', mark: '◇', title: '判断', sub: '分岐・承認' },
  { type: 'data', mark: '⌸', title: 'データ', sub: 'システム内のレコード' },
  { type: 'note', title: '付せん', sub: '補足メモ' },
];
const ICONS: Part[] = [
  { type: 'excel', title: 'Excel', sub: '表計算' },
  { type: 'csv', title: 'CSV', sub: '一覧表' },
  { type: 'pc', title: 'PC', sub: '端末・画面' },
  { type: 'mail', title: 'メール', sub: '通知・送付' },
  { type: 'doc', title: '書類', sub: '帳票・紙' },
  { type: 'person', title: '人', sub: '顧客など' },
  { type: 'clock', title: '時計', sub: '定期・待ち' },
];

/* 開け閉めの状態はブラウザに覚えておく */
function useOpen(id: string, def: boolean): [boolean, () => void] {
  const key = 'flowcanvas.open.' + id;
  const [open, setOpen] = useState(() => {
    try {
      const v = localStorage.getItem(key);
      return v === null ? def : v === '1';
    } catch {
      return def;
    }
  });
  const toggle = () =>
    setOpen((o) => {
      try {
        localStorage.setItem(key, o ? '0' : '1');
      } catch {
        /* 覚えられない環境では無視 */
      }
      return !o;
    });
  return [open, toggle];
}

/** 左パネルの1つのまとまり（クリックで開け閉め） */
function Section({ id, badge, title, def, children }: { id: string; badge: string; title: string; def: boolean; children: React.ReactNode }) {
  const [open, toggle] = useOpen(id, def);
  return (
    <section className={`card acc${open ? ' open' : ''}`}>
      <h2>
        <button className="acc-head" aria-expanded={open} aria-controls={`acc-${id}`} onClick={toggle}>
          <span className="badge" aria-hidden="true">{badge}</span>
          <span className="acc-title">{title}</span>
          <span className="chev" aria-hidden="true">{open ? '▾' : '▸'}</span>
        </button>
      </h2>
      {open && (
        <div className="acc-body" id={`acc-${id}`}>
          {children}
        </div>
      )}
    </section>
  );
}

/** まとまりの中の小さい開け閉め（部品の「基本」「アイコン」） */
function Sub({ id, title, def, children }: { id: string; title: string; def: boolean; children: React.ReactNode }) {
  const [open, toggle] = useOpen(id, def);
  return (
    <div className="sub">
      <button className="sub-head" aria-expanded={open} onClick={toggle}>
        <span>{title}</span>
        <span className="chev" aria-hidden="true">{open ? '▾' : '▸'}</span>
      </button>
      {open && children}
    </div>
  );
}

function LaneList({ kind }: { kind: LaneKind }) {
  const lanes = useStore((s) => s.flow?.lanes);
  const selId = useStore((s) => (s.sel?.kind === 'lane' ? s.sel.id : null));
  const ls = lanes?.filter((l) => l.kind === kind) ?? [];
  const select = (id: string) => setState({ sel: { kind: 'lane', id } });
  return (
    <div className="lane-group">
      <div className="lane-group-head">
        <span>{kind === 'actor' ? '担当者（人・部署）' : 'システム'}</span>
        <button className="link sm" onClick={() => addLane(kind)}>＋ 追加</button>
      </div>
      {!ls.length && <p className="hint">まだありません</p>}
      <ul className="lane-rows">
        {ls.map((l, i) => (
          <li key={l.id} className={`lane-row ${kind}${kind === 'actor' ? ` tint-${i % 3}` : ''}${l.id === selId ? ' sel' : ''}`}>
            <button
              className="nm"
              onClick={() => select(l.id)}
              onDoubleClick={() => {
                select(l.id);
                requestAnimationFrame(() => {
                  const el = document.querySelector<HTMLInputElement>('#p-lane-name');
                  el?.focus();
                  el?.select();
                });
              }}
            >
              {l.name}
            </button>
            <span className="ops">
              <button className="icon sm ghost" title="上へ" aria-label={`${l.name}を上へ`} onClick={() => moveLane(l.id, -1)}>↑</button>
              <button className="icon sm ghost" title="下へ" aria-label={`${l.name}を下へ`} onClick={() => moveLane(l.id, 1)}>↓</button>
              <button className="icon sm ghost" title="削除" aria-label={`${l.name}の枠を削除`} onClick={() => deleteLane(l.id)}>×</button>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function PartButton({ p, icon }: { p: Part; icon?: boolean }) {
  return (
    <button
      type="button"
      className="pal"
      aria-label={`${p.title}を置く（${p.sub}）`}
      title={`${p.sub}。クリックで置く／キャンバスへドラッグして好きな所に置く`}
      draggable
      onDragStart={(e) => {
        e.dataTransfer.setData(DRAG_TYPE, p.type);
        e.dataTransfer.effectAllowed = 'copy';
      }}
      onClick={() => addNode(p.type)}
    >
      {icon || !p.mark ? <NodeIcon type={p.type} size={24} /> : <span className="mark" aria-hidden="true">{p.mark}</span>}
      <span className="pal-name">{p.title}</span>
    </button>
  );
}

/** どの枠に置かれるかを先に見せる（1行だけ） */
function PlaceHint() {
  const flow = useStore((s) => s.flow);
  const sel = useStore((s) => s.sel);
  if (!flow) return null;
  const task = targetLane(flow, sel, 'task');
  const name = flow.lanes.find((l) => l.id === task?.id)?.name;
  return (
    <p className="place" role="status">
      {task ? (
        <>
          置く先：<b>{name}</b>
          {!task.chosen && '（枠をクリックで変更）'}
        </>
      ) : (
        '先に枠を作ってください'
      )}
    </p>
  );
}

function LineTools() {
  const tool = useStore((s) => s.tool);
  const edgeKind = useStore((s) => s.edgeKind);
  const edgeRoute = useStore((s) => s.edgeRoute);
  return (
    <>
      <div className="seg" role="group" aria-label="道具">
        <button aria-pressed={tool === 'select'} className={tool === 'select' ? 'on' : ''} onClick={() => setState({ tool: 'select', connectSrc: null })}>
          選択・移動
        </button>
        <button aria-pressed={tool === 'connect'} className={tool === 'connect' ? 'on' : ''} onClick={() => setState({ tool: 'connect', connectSrc: null })}>
          接続
        </button>
      </div>
      <label className="field">
        <span>これから引く線</span>
        <select value={edgeKind} onChange={(e) => setState({ edgeKind: e.target.value as EdgeKind })}>
          <option value="flow">業務の流れ（実線）</option>
          <option value="data">データの流れ（点線）</option>
        </select>
      </label>
      <label className="field">
        <span>線の形</span>
        <select aria-label="これから引く線の形" value={edgeRoute} onChange={(e) => setState({ edgeRoute: e.target.value as Route })}>
          <option value="curve">曲線</option>
          <option value="ortho">直角（カギ線）</option>
        </select>
      </label>
    </>
  );
}

function Sentence() {
  const [text, setText] = useState('');
  const [msg, setMsg] = useState<{ ok: boolean; message: string } | null>(null);
  const run = () => {
    if (!text.trim()) return;
    const r = runSentence(text);
    setMsg(r);
    if (r.ok) setText('');
  };
  return (
    <div className="nl">
      <textarea
        id="nl-text"
        aria-label="文章でつなぐ"
        value={text}
        placeholder="例：作業とデータを点線で繋ぐ。線は赤。"
        onChange={(e) => {
          setText(e.target.value);
          if (msg && !msg.ok) setMsg(null); // 書き直したらエラーは消す
        }}
        onKeyDown={(e) => {
          // Enter で実行、Shift+Enter で改行（変換確定の Enter は除く）
          if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            run();
          }
        }}
      />
      <div className="nl-foot">
        <button className="link sm" onClick={() => setState({ modal: 'guide' })}>書き方</button>
        <button className="btn primary sm" onClick={run}>つなぐ</button>
      </div>
      {msg && (
        <p className={`msg ${msg.ok ? 'ok' : 'ng'}`} role={msg.ok ? 'status' : 'alert'}>
          {msg.message}
        </p>
      )}
    </div>
  );
}

export function SidebarLeft() {
  return (
    <aside className="side left" aria-label="図を作る道具">
      <Section id="lanes" badge="1" title="枠を作る" def>
        <LaneList kind="actor" />
        <LaneList kind="system" />
      </Section>
      <Section id="parts" badge="2" title="部品を置く" def>
        <Sub id="parts-basic" title="基本" def>
          <div className="palette">
            {BASIC.map((p) => (
              <PartButton key={p.type} p={p} />
            ))}
          </div>
        </Sub>
        <Sub id="parts-icons" title="アイコン（Excel・メール・人 など）" def={false}>
          <div className="palette">
            {ICONS.map((p) => (
              <PartButton key={p.type} p={p} icon />
            ))}
          </div>
        </Sub>
        <PlaceHint />
      </Section>
      <Section id="lines" badge="3" title="線でつなぐ" def={false}>
        <LineTools />
      </Section>
      <Section id="nl" badge="✎" title="文章でつなぐ" def={false}>
        <Sentence />
      </Section>
    </aside>
  );
}
