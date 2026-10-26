// Excel の「標準の色」と同じ並び
export const STANDARD_COLORS = [
  { name: '濃い赤', hex: '#C00000' },
  { name: '赤', hex: '#FF0000' },
  { name: 'オレンジ', hex: '#FFC000' },
  { name: '黄', hex: '#FFFF00' },
  { name: '薄い緑', hex: '#92D050' },
  { name: '緑', hex: '#00B050' },
  { name: '薄い青', hex: '#00B0F0' },
  { name: '青', hex: '#0070C0' },
  { name: '濃い青', hex: '#002060' },
  { name: '紫', hex: '#7030A0' },
] as const;

export const BASIC_COLORS = [
  { name: '黒', hex: '#000000' },
  { name: '灰色', hex: '#7F7F7F' },
  { name: '白', hex: '#FFFFFF' },
] as const;

/** 文章の中の色の言い方 → 色。長い言葉から順に見る（「濃い赤」を「赤」より先に） */
export const COLOR_WORDS: [string, string][] = (
  [
    ['濃い赤', '#C00000'], ['えんじ', '#C00000'],
    ['濃い青', '#002060'], ['紺', '#002060'], ['ネイビー', '#002060'],
    ['薄い青', '#00B0F0'], ['水色', '#00B0F0'],
    ['薄い緑', '#92D050'], ['黄緑', '#92D050'],
    ['オレンジ', '#FFC000'], ['橙', '#FFC000'],
    ['ピンク', '#FF66CC'], ['茶色', '#996633'], ['茶', '#996633'],
    ['グレー', '#7F7F7F'], ['灰色', '#7F7F7F'], ['灰', '#7F7F7F'],
    ['赤', '#FF0000'], ['青', '#0070C0'], ['緑', '#00B050'], ['黄色', '#FFFF00'], ['黄', '#FFFF00'],
    ['紫', '#7030A0'], ['黒', '#000000'], ['白', '#FFFFFF'],
  ] as [string, string][]
).sort((a, b) => b[0].length - a[0].length);

export function colorName(hex?: string) {
  if (!hex) return '自動';
  const all = [...STANDARD_COLORS, ...BASIC_COLORS];
  return all.find((c) => c.hex.toLowerCase() === hex.toLowerCase())?.name ?? hex;
}
