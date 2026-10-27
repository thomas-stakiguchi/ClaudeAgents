import { useEffect } from 'react';
import { copySel, deleteSel, paste, selectAll, startEdit } from './store/actions';
import { getState, loadFlows, redo, setState, undo } from './store/flowStore';
import { Canvas } from './components/Canvas';
import { SidebarLeft } from './components/SidebarLeft';
import { SidebarRight } from './components/SidebarRight';
import { saveWithMessage, Toolbar } from './components/Toolbar';

export function App() {
  useEffect(() => {
    loadFlows().catch((e) => {
      console.error(e);
      setState({ saveState: 'error', flash: 'サーバーに繋がりません（npm run dev で起動していますか？）' });
    });
  }, []);

  useEffect(() => {
    const onKey = (ev: KeyboardEvent) => {
      const el = document.activeElement as HTMLElement | null;
      const inField = !!el && /INPUT|TEXTAREA|SELECT/.test(el.tagName);
      if (ev.key === 'Escape') {
        if (inField) el!.blur();
        else if (!getState().connectSrc) setState({ sel: null });
        setState({ connectSrc: null, confirmDel: null });
        return;
      }
      const mod = ev.ctrlKey || ev.metaKey;
      // Ctrl+S は入力中でも保存（ブラウザの「ページを保存」は出さない）
      if (mod && (ev.key === 's' || ev.key === 'S')) {
        ev.preventDefault();
        void saveWithMessage();
        return;
      }
      if (inField) return; // 入力欄の中ではブラウザ標準の取り消しを使う
      if (mod && (ev.key === 'z' || ev.key === 'Z')) {
        ev.preventDefault();
        if (ev.shiftKey) redo();
        else undo();
        return;
      }
      if (mod && (ev.key === 'y' || ev.key === 'Y')) {
        ev.preventDefault();
        redo();
        return;
      }
      if (mod && (ev.key === 'c' || ev.key === 'C')) {
        if (copySel()) ev.preventDefault();
        return;
      }
      if (mod && (ev.key === 'v' || ev.key === 'V')) {
        ev.preventDefault();
        paste();
        return;
      }
      if (mod && (ev.key === 'a' || ev.key === 'A')) {
        ev.preventDefault();
        selectAll();
        return;
      }
      if (mod) return;
      const cur = getState().sel;
      // ボタンなどを選んでいるときの Enter は、そのボタンのために残す
      const onPage = !el || el === document.body;
      if ((ev.key === 'Enter' || ev.key === 'F2') && cur?.kind === 'node' && onPage) {
        ev.preventDefault();
        startEdit(cur.id);
        return;
      }
      if (ev.key === 'Delete' || ev.key === 'Backspace') {
        ev.preventDefault();
        deleteSel();
      } else if (ev.key === 'v' || ev.key === 'V') {
        setState({ tool: 'select', connectSrc: null });
      } else if (ev.key === 'c' || ev.key === 'C') {
        // 部品を選んでいれば、そこを元にして接続を始める
        const { sel } = getState();
        setState({ tool: 'connect', connectSrc: sel?.kind === 'node' ? sel.id : null });
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  return (
    <>
      <Toolbar />
      <div className="main">
        <SidebarLeft />
        <Canvas />
        <SidebarRight />
      </div>
    </>
  );
}
