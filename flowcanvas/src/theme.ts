// テーマ：auto は OS の設定に従う。選んだ値はブラウザに覚えておく
export type Theme = 'auto' | 'light' | 'dark';

const KEY = 'flowcanvas.theme';

export function loadTheme(): Theme {
  try {
    const v = localStorage.getItem(KEY);
    if (v === 'light' || v === 'dark') return v;
  } catch {
    /* 使えない環境では auto */
  }
  return 'auto';
}

export function applyTheme(t: Theme) {
  // CSS は :root[data-theme="dark"] / :root:not([data-theme="light"]) で切り替わる
  if (t === 'auto') document.documentElement.removeAttribute('data-theme');
  else document.documentElement.setAttribute('data-theme', t);
  try {
    if (t === 'auto') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, t);
  } catch {
    /* noop */
  }
}
