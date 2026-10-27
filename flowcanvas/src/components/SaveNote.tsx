import { useEffect, useRef, useState } from 'react';
import { saveAskFile, saveFlowFile } from '../export/fileSave';
import { flash, getState, setState, useStore } from '../store/flowStore';

/** 保存の後に出す小さな案内。「ファイルにも残す」をすすめる */
export function SaveNote() {
  const open = useStore((s) => s.saveNote);
  const [never, setNever] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  const close = () => {
    if (never) {
      saveAskFile(false);
      setState({ askFile: false });
      flash('次からは聞きません。「…」メニューで元に戻せます');
    }
    setState({ saveNote: false });
  };
  const closeRef = useRef(close);
  closeRef.current = close;

  useEffect(() => {
    if (!open) return;
    setNever(false);
    box.current?.querySelector<HTMLElement>('button')?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && (e.stopPropagation(), closeRef.current());
    const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && closeRef.current();
    window.addEventListener('keydown', onKey, true);
    window.addEventListener('pointerdown', onDown, true);
    return () => {
      window.removeEventListener('keydown', onKey, true);
      window.removeEventListener('pointerdown', onDown, true);
    };
  }, [open]);

  if (!open) return null;

  const toFile = async () => {
    const flow = getState().flow;
    if (!flow) return;
    try {
      const name = await saveFlowFile(flow);
      if (!name) return; // 保存先選びをやめた
      close();
      flash(`ファイルに保存しました：${name}`);
    } catch {
      flash('ファイルに保存できませんでした');
    }
  };

  return (
    <div className="save-note" role="dialog" aria-label="保存しました" ref={box}>
      <p className="save-note-title">✓ 保存しました</p>
      <p className="hint">ファイルにも残しておくと、PC を替えたときや誤って消したときに「読み込み」で戻せます。</p>
      <div className="save-note-actions">
        <button className="btn primary sm" onClick={() => void toFile()}>ファイルにも保存</button>
        <button className="btn sm" onClick={close}>今回はしない</button>
      </div>
      <label className="save-note-never">
        <input type="checkbox" checked={never} onChange={(e) => setNever(e.target.checked)} />
        次から聞かない
      </label>
    </div>
  );
}
