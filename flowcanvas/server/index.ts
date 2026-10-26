import express from 'express';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sampleFlow } from '../src/model/sample';
import { uid, type Flow, type FlowSummary } from '../src/model/types';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DATA_DIR = path.join(ROOT, 'data');
const DIST_DIR = path.join(ROOT, 'dist');
const PORT = Number(process.env.PORT) || 3001;
const HOST = '127.0.0.1'; // localhost のみで待ち受け

const ID_RE = /^[A-Za-z0-9_-]{1,64}$/;
const fileOf = (id: string) => path.join(DATA_DIR, `${id}.json`);

async function readFlow(id: string): Promise<Flow | null> {
  try {
    return JSON.parse(await fs.readFile(fileOf(id), 'utf8')) as Flow;
  } catch {
    return null;
  }
}

async function writeFlow(flow: Flow) {
  // 一時ファイルに書いてから置き換える（書き込み途中で壊れないように）
  const tmp = fileOf(flow.id) + '.tmp';
  await fs.writeFile(tmp, JSON.stringify(flow, null, 2));
  await fs.rename(tmp, fileOf(flow.id));
}

async function listFlows(): Promise<FlowSummary[]> {
  const files = (await fs.readdir(DATA_DIR)).filter((f) => f.endsWith('.json'));
  const out: FlowSummary[] = [];
  for (const f of files) {
    const flow = await readFlow(f.slice(0, -5));
    if (flow) out.push({ id: flow.id, title: flow.title, updatedAt: flow.updatedAt });
  }
  return out.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
}

function isFlowBody(b: unknown): b is Omit<Flow, 'id' | 'updatedAt'> {
  const o = b as Flow;
  return !!o && typeof o === 'object' && Array.isArray(o.lanes) && Array.isArray(o.nodes) && Array.isArray(o.edges);
}

async function init() {
  await fs.mkdir(DATA_DIR, { recursive: true });
  if ((await listFlows()).length === 0) await writeFlow(sampleFlow());
}

const app = express();
app.use(express.json({ limit: '20mb' }));

app.param('id', (req, res, next, id: string) => {
  if (!ID_RE.test(id)) return void res.status(400).json({ error: 'invalid id' });
  next();
});

app.get('/api/flows', async (_req, res) => {
  res.json(await listFlows());
});

app.get('/api/flows/:id', async (req, res) => {
  const flow = await readFlow(req.params.id);
  if (!flow) return void res.status(404).json({ error: 'not found' });
  res.json(flow);
});

app.put('/api/flows/:id', async (req, res) => {
  if (!isFlowBody(req.body)) return void res.status(400).json({ error: 'invalid flow' });
  const flow: Flow = { ...req.body, title: req.body.title ?? '', id: req.params.id, updatedAt: new Date().toISOString() };
  await writeFlow(flow);
  res.json({ id: flow.id, title: flow.title, updatedAt: flow.updatedAt });
});

app.post('/api/flows', async (req, res) => {
  const id = uid();
  const body = isFlowBody(req.body) ? req.body : sampleFlow(id);
  const flow: Flow = { ...body, title: body.title ?? '無題', id, updatedAt: new Date().toISOString() };
  await writeFlow(flow);
  res.status(201).json(flow);
});

app.delete('/api/flows/:id', async (req, res) => {
  await fs.rm(fileOf(req.params.id), { force: true });
  res.status(204).end();
});

// npm run build 後は dist も配信する
app.use(express.static(DIST_DIR));
app.get(/^(?!\/api\/).*/, (_req, res, next) => {
  res.sendFile(path.join(DIST_DIR, 'index.html'), (err) => err && next());
});

init().then(() => {
  app.listen(PORT, HOST, () => console.log(`FlowCanvas API: http://${HOST}:${PORT}`));
});
