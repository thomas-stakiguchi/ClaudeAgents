import { useState } from 'react';
import { readCsvSteps, readWorkbook } from '../import/table';
import { tableToFlow, type ImportResult } from '../model/importTable';
import type { Flow } from '../model/types';
import { download } from '../export/text';
import { flash, importAsNewFlow, importFlow } from '../store/flowStore';
import { canvasApi } from './Canvas';
import { Modal } from './Modal';

type Ok = Extract<ImportResult, { ok: true }>;

// Excel を読む部品は大きいので、使うときだけ読み込む
const loadExcel = async () => (await import('exceljs')).default;

export function ImportModal({ onClose }: { onClose: () => void }) {
  const [tab, setTab] = useState<'excel' | 'json'>('excel');
  return (
    <Modal title="読み込み" onClose={onClose}>
      <div className="tabs" role="tablist" aria-label="読み込むもの">
        <button role="tab" aria-selected={tab === 'excel'} className={`tab${tab === 'excel' ? ' on' : ''}`} onClick={() => setTab('excel')}>
          Excel・CSV から作る
        </button>
        <button role="tab" aria-selected={tab === 'json'} className={`tab${tab === 'json' ? ' on' : ''}`} onClick={() => setTab('json')}>
          JSON
        </button>
      </div>
      {tab === 'excel' ? <ExcelPane onClose={onClose} /> : <JsonPane onClose={onClose} />}
    </Modal>
  );
}

function ExcelPane({ onClose }: { onClose: () => void }) {
  const [file, setFile] = useState<string>('');
  const [title, setTitle] = useState('');
  const [res, setRes] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState(false);

  const onFile = async (f: File | undefined) => {
    if (!f) return;
    setBusy(true);
    setRes(null);
    try {
      const name = f.name.toLowerCase();
      let r: ImportResult;
      if (name.endsWith('.csv')) {
        r = tableToFlow(readCsvSteps(await f.text()));
      } else if (name.endsWith('.xlsx')) {
        const ExcelJS = await loadExcel();
        const wb = new ExcelJS.Workbook();
        await wb.xlsx.load(await f.arrayBuffer());
        const t = readWorkbook(wb);
        r = tableToFlow(t.steps, t.lanes);
      } else {
        r = { ok: false, errors: ['読み込めるのは .xlsx と .csv です（.xls は Excel で .xlsx に保存し直してください）。'] };
      }
      setFile(f.name);
      setTitle(f.name.replace(/\.[^.]+$/, ''));
      setRes(r);
    } catch (e) {
      setRes({ ok: false, errors: ['ファイルを読めませんでした：' + (e instanceof Error ? e.message : String(e))] });
    } finally {
      setBusy(false);
    }
  };

  const make = async (r: Ok) => {
    onClose();
    await importAsNewFlow({ title: title || '取り込んだ図', lanes: r.lanes, nodes: r.nodes, edges: r.edges });
    flash(r.warnings.length ? `図を作りました（注意 ${r.warnings.length} 件は読み込みの画面に出ていたものです）` : '図を作りました');
  };

  const getTemplate = async () => {
    const [ExcelJS, { buildTemplate }] = await Promise.all([loadExcel(), import('../import/template')]);
    const buf = await buildTemplate(ExcelJS.Workbook).xlsx.writeBuffer();
    const url = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    download(url, '業務フロー_取り込みひな形.xlsx');
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  };

  const counts = (r: Ok) => {
    const a = r.lanes.filter((l) => l.kind === 'actor').length;
    return `担当者 ${a}・システム ${r.lanes.length - a}・部品 ${r.nodes.length}・線 ${r.edges.length}`;
  };

  return (
    <div className="pane">
      <p className="lead">
        「誰が・何をする・誰（何）に対して」を1行ずつ書いた表から、新しい図を作ります。今の図は変わりません。
      </p>
      <div className="row-actions">
        <label className="file-pick btn primary">
          ファイルを選ぶ（.xlsx・.csv）
          <input type="file" accept=".xlsx,.csv" onChange={(e) => void onFile(e.target.files?.[0])} data-autofocus />
        </label>
        <button className="btn" onClick={() => void getTemplate()}>ひな形をダウンロード</button>
      </div>
      {busy && <p className="hint" role="status">読み込んでいます…</p>}
      {res && !res.ok && (
        <div className="result ng" role="alert">
          <b>{file ? `「${file}」から図を作れませんでした` : '読み込めませんでした'}</b>
          <ul>{res.errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
          <span className="hint">表を直して、もう一度ファイルを選んでください。</span>
        </div>
      )}
      {res && res.ok && (
        <div className="result ok" role="status">
          <b>「{file}」を読み込みました</b>
          <span>{counts(res)}</span>
          {res.warnings.length > 0 && (
            <ul className="warn-list">{res.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul>
          )}
          <label className="field">
            <span>図のタイトル</span>
            <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <div className="actions">
            <button className="btn primary" onClick={() => void make(res)}>図を作る</button>
          </div>
        </div>
      )}
      <details className="help">
        <summary>表の書き方</summary>
        <table className="guide-table">
          <tbody>
            <tr><th>列</th><th>書くこと</th></tr>
            <tr><td>No</td><td>作業の番号（必須）</td></tr>
            <tr><td>誰が</td><td>作業をする人・部署・システム（必須）</td></tr>
            <tr><td>何をする</td><td>作業の名前（必須）</td></tr>
            <tr><td>形</td><td>作業（空なら作業）・判断・開始・終了・メール・書類・人・時計・付せん・データ・Excel・CSV・PC</td></tr>
            <tr><td>誰・何に対して</td><td>相手。システムなら「受け渡すもの」をデータとして点線でつなぐ</td></tr>
            <tr><td>受け渡すもの</td><td>やり取りするデータや書類</td></tr>
            <tr><td>向き</td><td>相手がシステムのとき：渡す／受け取る</td></tr>
            <tr><td>次のNo</td><td>次の作業。分岐は「4, 5」。空なら次の行。終わりなら「なし」</td></tr>
            <tr><td>条件</td><td>分岐の条件（次のNo と同じ順）</td></tr>
          </tbody>
        </table>
        <p className="hint">Excel では「枠」シートに担当者とシステムを並べたい順に書けます（なくても動きます）。</p>
      </details>
    </div>
  );
}

function JsonPane({ onClose }: { onClose: () => void }) {
  const [text, setText] = useState('');
  const [asNew, setAsNew] = useState(true);
  const apply = () => {
    try {
      const d = JSON.parse(text) as Flow;
      if (!Array.isArray(d.lanes) || !Array.isArray(d.nodes)) throw new Error();
      onClose();
      if (asNew) {
        void importAsNewFlow(d); // 新しい図を開くと全体が見える倍率になる
        return;
      }
      importFlow(d);
      requestAnimationFrame(() => canvasApi.fit());
    } catch {
      flash('JSONの形式が違います');
    }
  };
  return (
    <div className="pane">
      <p className="lead">「書き出し」→「テキスト」で保存した JSON を読み込みます。</p>
      <input type="file" accept=".json,application/json" onChange={async (e) => setText((await e.target.files?.[0]?.text()) ?? '')} />
      <textarea id="m-text" value={text} onChange={(e) => setText(e.target.value)} placeholder="ここに JSON を貼り付けてもかまいません" />
      <label className="check-row">
        <input type="checkbox" checked={asNew} onChange={(e) => setAsNew(e.target.checked)} />
        新しい図として読み込む（外すと今の図を置き換えます。Ctrl+Z で戻せます）
      </label>
      <div className="actions">
        <button className="btn primary" onClick={apply}>読み込む</button>
      </div>
    </div>
  );
}
