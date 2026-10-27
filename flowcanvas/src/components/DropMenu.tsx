import { useEffect, useRef, useState } from 'react';

export type MenuItem =
  | { kind?: 'item'; label: string; onSelect: () => void; danger?: boolean }
  | { kind: 'check'; label: string; checked: boolean; onToggle: () => void }
  | { kind: 'radio'; label: string; checked: boolean; onSelect: () => void }
  | { kind: 'sep' }
  | { kind: 'note'; label: string };

/** ボタン＋メニュー。矢印キーで移動、Enter で実行、Esc で閉じる（プルダウンと違い、動かしただけでは実行しない） */
export function DropMenu({
  label,
  ariaLabel,
  items,
  disabled,
  className = 'btn',
  align = 'right',
}: {
  label: React.ReactNode;
  ariaLabel?: string;
  items: MenuItem[];
  disabled?: boolean;
  className?: string;
  align?: 'left' | 'right';
}) {
  const [open, setOpen] = useState(false);
  const wrap = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    wrap.current?.querySelector<HTMLButtonElement>('[role^=menuitem]')?.focus();
    const onDown = (e: PointerEvent) => {
      if (!wrap.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [open]);

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape' && open) {
      e.stopPropagation();
      setOpen(false);
      wrap.current?.querySelector<HTMLButtonElement>('.menu-btn')?.focus();
      return;
    }
    if (e.key === 'Tab' && open) setOpen(false);
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    const list = [...(wrap.current?.querySelectorAll<HTMLButtonElement>('[role^=menuitem]') ?? [])];
    const i = list.indexOf(document.activeElement as HTMLButtonElement);
    list[(i + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length]?.focus();
  };

  return (
    <div className="menu-wrap" ref={wrap} onKeyDown={onKey}>
      <button
        className={`${className} menu-btn`}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => setOpen((o) => !o)}
      >
        {label}
      </button>
      {open && (
        <div className={`menu ${align}`} role="menu">
          {items.map((it, i) => {
            if (it.kind === 'sep') return <div key={i} className="menu-sep" role="separator" />;
            if (it.kind === 'note') return <div key={i} className="menu-note">{it.label}</div>;
            if (it.kind === 'check')
              return (
                <button key={i} role="menuitemcheckbox" aria-checked={it.checked} onClick={it.onToggle}>
                  <span className="check" aria-hidden="true">{it.checked ? '✓' : ''}</span>
                  {it.label}
                </button>
              );
            if (it.kind === 'radio')
              return (
                <button key={i} role="menuitemradio" aria-checked={it.checked} onClick={() => (it.onSelect(), setOpen(false))}>
                  <span className="check" aria-hidden="true">{it.checked ? '●' : ''}</span>
                  {it.label}
                </button>
              );
            return (
              <button key={i} role="menuitem" className={it.danger ? 'danger' : undefined} onClick={() => (setOpen(false), it.onSelect())}>
                <span className="check" aria-hidden="true" />
                {it.label}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
