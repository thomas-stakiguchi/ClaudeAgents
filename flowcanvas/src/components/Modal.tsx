import { useEffect, useRef } from 'react';

/** 画面の上に重ねて出す小窓。開いたら中へ入力が移り、Esc か外側のクリックで閉じる */
export function Modal({
  title,
  onClose,
  wide,
  children,
}: {
  title: string;
  onClose: () => void;
  wide?: boolean;
  children: React.ReactNode;
}) {
  const box = useRef<HTMLDivElement>(null);
  const close = useRef(onClose);
  close.current = onClose;
  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    const b = box.current;
    (b?.querySelector<HTMLElement>('[data-autofocus]') ?? b?.querySelector<HTMLElement>('button, textarea, input'))?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        close.current();
      }
      // Tab で小窓の外へ出ないようにする
      if (e.key === 'Tab' && box.current) {
        const f = [...box.current.querySelectorAll<HTMLElement>('button, input, textarea, select, a[href]')].filter((x) => !x.hasAttribute('disabled'));
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) (e.preventDefault(), last.focus());
        else if (!e.shiftKey && document.activeElement === last) (e.preventDefault(), first.focus());
      }
    };
    window.addEventListener('keydown', onKey, true);
    return () => {
      window.removeEventListener('keydown', onKey, true);
      opener?.focus?.(); // 閉じたら開いたボタンへ戻す
    };
  }, []);
  return (
    <div className="modal" onPointerDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`box${wide ? ' wide' : ''}`} role="dialog" aria-modal="true" aria-label={title} ref={box}>
        <div className="modal-head">
          <h2>{title}</h2>
          <button className="icon ghost" aria-label="閉じる" onClick={onClose}>
            ✕
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
