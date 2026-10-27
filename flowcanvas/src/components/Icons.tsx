import type { NodeType } from '../model/types';

/*
 * 部品のアイコン。色は直接書いておく（画像書き出しでも同じ見た目になるように）。
 * Excel は製品ロゴではなく「緑の表計算ファイル」として描いている。
 */

function FileBase({ band, text }: { band: string; text: string }) {
  return (
    <>
      <path d="M8 2h22l10 10v34a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#fff" stroke="#9aa0a8" strokeWidth="1.5" />
      <path d="M30 2v8a2 2 0 0 0 2 2h8" fill="none" stroke="#9aa0a8" strokeWidth="1.5" />
      <rect x="1" y="26" width="34" height="14" rx="2" fill={band} />
      <text x="18" y="36.5" textAnchor="middle" fontSize="9" fontWeight="700" fill="#fff" fontFamily="system-ui, sans-serif">
        {text}
      </text>
    </>
  );
}

export function ExcelIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 44 50" aria-hidden="true">
      <FileBase band="#1D7A45" text="XLSX" />
      {/* 表のマス目 */}
      <g stroke="#1D7A45" strokeWidth="1">
        <rect x="12" y="10" width="20" height="12" fill="#E3F2E8" />
        <line x1="12" y1="14" x2="32" y2="14" />
        <line x1="12" y1="18" x2="32" y2="18" />
        <line x1="19" y1="10" x2="19" y2="22" />
        <line x1="25.5" y1="10" x2="25.5" y2="22" />
      </g>
    </svg>
  );
}

export function CsvIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 44 50" aria-hidden="true">
      <FileBase band="#4D6A8A" text="CSV" />
      {/* カンマ区切りの行 */}
      <g fill="#4D6A8A">
        {[11, 15, 19].map((y) => (
          <g key={y}>
            <rect x="11" y={y} width="6" height="2" rx="1" />
            <rect x="19" y={y} width="6" height="2" rx="1" />
            <rect x="27" y={y} width="6" height="2" rx="1" />
          </g>
        ))}
      </g>
    </svg>
  );
}

export function PcIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 50 44" aria-hidden="true">
      <rect x="3" y="3" width="44" height="28" rx="3" fill="#2B3440" />
      <rect x="6" y="6" width="38" height="22" rx="1.5" fill="#7FB2F0" />
      <path d="M21 31h8l2 7H19z" fill="#5A626D" />
      <rect x="13" y="38" width="24" height="3" rx="1.5" fill="#5A626D" />
    </svg>
  );
}

export function MailIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 50 44" aria-hidden="true">
      <rect x="3" y="7" width="44" height="30" rx="3" fill="#FFF7E6" stroke="#D9822B" strokeWidth="2" />
      <path d="M4 9l21 16L46 9" fill="none" stroke="#D9822B" strokeWidth="2" strokeLinejoin="round" />
    </svg>
  );
}

export function DocIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 44 50" aria-hidden="true">
      {/* 下の辺が波打った紙（フローチャートの「書類」の形） */}
      <path d="M7 3h30v40c-5 4-10-3-15 0s-10 4-15 0z" fill="#fff" stroke="#8A6D3B" strokeWidth="1.6" />
      <g stroke="#8A6D3B" strokeWidth="1.6" strokeLinecap="round">
        <line x1="13" y1="12" x2="31" y2="12" />
        <line x1="13" y1="19" x2="31" y2="19" />
        <line x1="13" y1="26" x2="31" y2="26" />
        <line x1="13" y1="33" x2="24" y2="33" />
      </g>
    </svg>
  );
}

export function PersonIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 44 44" aria-hidden="true">
      <circle cx="22" cy="14" r="8" fill="#6B7FA8" />
      <path d="M7 40c0-9 7-15 15-15s15 6 15 15z" fill="#6B7FA8" />
    </svg>
  );
}

export function ClockIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 44 44" aria-hidden="true">
      <circle cx="22" cy="22" r="18" fill="#fff" stroke="#5A626D" strokeWidth="2.5" />
      <g stroke="#5A626D" strokeWidth="2.5" strokeLinecap="round">
        <line x1="22" y1="22" x2="22" y2="11" />
        <line x1="22" y1="22" x2="30" y2="26" />
      </g>
      <circle cx="22" cy="22" r="2" fill="#5A626D" />
    </svg>
  );
}

export function NoteIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 44 44" aria-hidden="true">
      <path d="M5 5h34v24L29 39H5z" fill="#FFE98A" stroke="#C9A227" strokeWidth="1.5" />
      <path d="M39 29H29v10" fill="#F2D35B" stroke="#C9A227" strokeWidth="1.5" />
    </svg>
  );
}

export function NodeIcon({ type, size }: { type: NodeType; size?: number }) {
  if (type === 'excel') return <ExcelIcon size={size} />;
  if (type === 'csv') return <CsvIcon size={size} />;
  if (type === 'pc') return <PcIcon size={size} />;
  if (type === 'mail') return <MailIcon size={size} />;
  if (type === 'doc') return <DocIcon size={size} />;
  if (type === 'person') return <PersonIcon size={size} />;
  if (type === 'clock') return <ClockIcon size={size} />;
  if (type === 'note') return <NoteIcon size={size} />;
  return null;
}
