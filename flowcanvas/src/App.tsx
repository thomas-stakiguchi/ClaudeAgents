import { useEffect } from 'react';
import { deleteSel } from './store/actions';
import { getState, loadFlows, redo, setState, undo } from './store/flowStore';
import { Canvas } from './components/Canvas';
import { SidebarLeft } from './components/SidebarLeft';
import { SidebarRight } from './components/SidebarRight';
import { Toolbar } from './components/Toolbar';

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
      if (inField) return; // 入力欄の中ではブラウザ標準の取り消しを使う
      const mod = ev.ctrlKey || ev.metaKey;
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
      if (mod) return;
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
