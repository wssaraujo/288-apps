const express = require('express');
const fs = require('fs');
const os = require('os');
const path = require('path');

const app = express();
const port = process.env.PORT || 8080;
const startedAt = Date.now();

let requests = 0;
const failUntil = { live: 0, ready: 0 };

const podIp = () =>
  Object.values(os.networkInterfaces()).flat()
    .find((i) => i && i.family === 'IPv4' && !i.internal)?.address || null;

const namespace = () => {
  try {
    return fs.readFileSync('/var/run/secrets/kubernetes.io/serviceaccount/namespace', 'utf8').trim();
  } catch {
    return process.env.POD_NAMESPACE || process.env.OPENSHIFT_BUILD_NAMESPACE || null;
  }
};

// APP_* variables, with secret-looking names masked.
const appEnv = () => Object.fromEntries(
  Object.entries(process.env)
    .filter(([k]) => k.startsWith('APP_'))
    .map(([k, v]) => [k, /SECRET|PASSWORD|TOKEN|KEY/i.test(k) ? '••••••••' : v]),
);

const probes = () => {
  const now = Date.now();
  return { live: now >= failUntil.live, ready: now >= failUntil.ready };
};

const clampSeconds = (v, def) => Math.min(Math.max(parseInt(v ?? def, 10) || 0, 0), 300);

app.use((req, res, next) => { requests++; next(); });
app.use(express.static(path.join(__dirname, 'public')));

app.get('/api/info', (req, res) => {
  const e = process.env;
  res.set('Cache-Control', 'no-store').json({
    stack: 'Node.js',
    framework: 'Express',
    runtime: process.version,
    accent: e.APP_COLOR || '#3c873a',
    message: e.APP_MESSAGE || 'Hello from Node.js on OpenShift!',
    pod: { hostname: os.hostname(), ip: podIp(), namespace: namespace(), node: e.NODE_NAME || null },
    build: {
      name: e.OPENSHIFT_BUILD_NAME || null,
      source: e.OPENSHIFT_BUILD_SOURCE || null,
      reference: e.OPENSHIFT_BUILD_REFERENCE || null,
      commit: e.OPENSHIFT_BUILD_COMMIT || null,
    },
    process: {
      pid: process.pid,
      platform: `${os.platform()} ${os.arch()}`,
      cpus: os.cpus().length,
      memoryMB: Math.round(process.memoryUsage().rss / 1048576),
      uptimeSeconds: Math.floor((Date.now() - startedAt) / 1000),
    },
    request: {
      method: req.method,
      host: req.headers.host || null,
      clientIp: req.socket.remoteAddress,
      forwardedFor: req.headers['x-forwarded-for'] || null,
      forwardedProto: req.headers['x-forwarded-proto'] || null,
      userAgent: req.headers['user-agent'] || null,
    },
    requests,
    probes: probes(),
    env: appEnv(),
    time: new Date().toISOString(),
  });
});

const probe = (name) => (req, res) => {
  const ok = probes()[name];
  res.status(ok ? 200 : 503).json({ status: ok ? 'UP' : 'DOWN' });
};
app.get('/health', probe('live'));
app.get('/ready', probe('ready'));

app.post('/api/fail/:probe(live|ready)', (req, res) => {
  const seconds = clampSeconds(req.query.seconds, 60);
  failUntil[req.params.probe] = Date.now() + seconds * 1000;
  console.log(`${req.params.probe} probe failing for ${seconds}s`);
  res.json({ probe: req.params.probe, seconds, probes: probes() });
});

// Busy-loop in short slices so the event loop (and the probes) stay responsive.
app.post('/api/stress', (req, res) => {
  const seconds = Math.min(clampSeconds(req.query.seconds, 30), 60);
  const end = Date.now() + seconds * 1000;
  console.log(`Burning CPU for ${seconds}s`);
  const burn = () => {
    const sliceEnd = Math.min(Date.now() + 50, end);
    while (Date.now() < sliceEnd) Math.sqrt(Math.random());
    if (Date.now() < end) setImmediate(burn);
    else res.json({ seconds, pod: os.hostname() });
  };
  burn();
});

app.listen(port, '0.0.0.0', () => {
  console.log(`Server listening on port ${port}`);
});
