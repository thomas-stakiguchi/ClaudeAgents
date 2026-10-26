import { BASIC_COLORS, STANDARD_COLORS, colorName } from '../model/colors';

type Props = { value?: string; onChange: (hex: string | undefined) => void };

/** Excel の色選びと同じ感覚：自動／標準の色／その他の色 */
export function ColorPicker({ value, onChange }: Props) {
  const cur = value?.toLowerCase();
  const swatch = (c: { name: string; hex: string }) => (
    <button
      key={c.hex}
      type="button"
      className={`swatch${cur === c.hex.toLowerCase() ? ' on' : ''}`}
      style={{ background: c.hex }}
      title={c.name}
      aria-label={c.name}
      onClick={() => onChange(c.hex)}
    />
  );
  return (
    <div className="colorpicker">
      <button type="button" className={`sm auto${!value ? ' on' : ''}`} onClick={() => onChange(undefined)}>
        自動（業務は黒・データは青）
      </button>
      <div className="cp-label">標準の色</div>
      <div className="swatches">{STANDARD_COLORS.map(swatch)}</div>
      <div className="swatches">{BASIC_COLORS.map(swatch)}</div>
      <label className="cp-more">
        その他の色…
        <input type="color" value={value ?? '#000000'} onChange={(e) => onChange(e.target.value.toUpperCase())} />
      </label>
      <div className="cp-label">今の色：{colorName(value)}</div>
    </div>
  );
}
