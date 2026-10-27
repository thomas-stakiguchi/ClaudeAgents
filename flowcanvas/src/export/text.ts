import type { Flow } from '../model/types';

export function toJson(flow: Flow) {
  return JSON.stringify(flow, null, 2);
}

export function toMermaid(doc: Flow) {
  const L = ['flowchart LR'];
  const nid: Record<string, string> = {};
  doc.nodes.forEach((n, i) => (nid[n.id] = 'n' + i));
  doc.lanes.forEach((l) => {
    const ns = doc.nodes.filter((n) => n.lane === l.id);
    if (!ns.length) return;
    L.push(`  subgraph ${l.kind === 'system' ? 'sys' : 'act'}_${nid[ns[0].id]} ["${l.kind === 'system' ? '🗄 ' : ''}${l.name}"]`);
    ns.forEach((n) => {
      const lb = n.label.replace(/"/g, "'");
      const sh =
        n.type === 'decision' ? `{"${lb}"}`
        : n.type === 'data' ? `[("${lb}")]`
        : n.type === 'start' || n.type === 'end' ? `(("${lb}"))`
        : n.type === 'excel' || n.type === 'csv' ? `[/"${lb}"/]`
        : n.type === 'pc' ? `["🖥 ${lb}"]`
        : n.type === 'mail' ? `["✉ ${lb}"]`
        : n.type === 'doc' ? `[/"📄 ${lb}"/]`
        : n.type === 'person' ? `(["👤 ${lb}"])`
        : n.type === 'clock' ? `["⏰ ${lb}"]`
        : n.type === 'note' ? `>"📝 ${lb}"]`
        : `["${lb}"]`;
      L.push(`    ${nid[n.id]}${sh}`);
    });
    L.push('  end');
  });
  let li = 0;
  const styles: string[] = [];
  doc.edges.forEach((e) => {
    if (!nid[e.from] || !nid[e.to]) return;
    const lb = e.label ? `|${e.label.replace(/\|/g, '/')}|` : '';
    L.push(`  ${nid[e.from]} ${e.kind === 'data' ? '-.->' : '-->'}${lb} ${nid[e.to]}`);
    if (e.color) styles.push(`  linkStyle ${li} stroke:${e.color}`);
    li++;
  });
  L.push(...styles);
  return L.join('\n');
}

export function toMarkdownTables(doc: Flow) {
  const laneName = (id: string) => doc.lanes.find((l) => l.id === id)?.name ?? '?';
  const node = (id: string) => doc.nodes.find((n) => n.id === id);
  const out = [`# ${doc.title || '業務フロー'}`, '', '## 登場人物・システム', '', '| 区分 | 名前 | 説明 |', '|---|---|---|'];
  doc.lanes.forEach((l) => out.push(`| ${l.kind === 'actor' ? 'Actor' : 'システム'} | ${l.name} | ${l.desc || ''} |`));
  out.push('', '## 業務の流れ', '', '| # | 担当 | 作業 | 次へ | 条件 |', '|---|---|---|---|---|');
  let i = 1;
  doc.edges
    .filter((e) => e.kind === 'flow')
    .forEach((e) => {
      const a = node(e.from);
      if (!a) return;
      out.push(`| ${i++} | ${laneName(a.lane)} | ${a.label} | ${node(e.to)?.label ?? '?'} | ${e.label || ''} |`);
    });
  out.push('', '## データの流れ', '', '| # | 元 | 先 | 内容 |', '|---|---|---|---|');
  i = 1;
  doc.edges
    .filter((e) => e.kind === 'data')
    .forEach((e) => {
      const a = node(e.from), b = node(e.to);
      if (!a || !b) return;
      out.push(`| ${i++} | ${laneName(a.lane)}／${a.label} | ${laneName(b.lane)}／${b.label} | ${e.label || ''} |`);
    });
  return out.join('\n');
}

/** ファイル名：{タイトル}_{YYYYMMDD}.{拡張子} */
export function fileName(title: string, ext: string) {
  const d = new Date();
  const ymd = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  const safe = (title || '無題').replace(/[\\/:*?"<>|]/g, '_').trim();
  return `${safe}_${ymd}.${ext}`;
}

export function download(href: string, name: string) {
  const a = document.createElement('a');
  a.href = href;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
}

export function downloadText(text: string, name: string, mime: string) {
  const url = URL.createObjectURL(new Blob([text], { type: mime }));
  download(url, name);
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
