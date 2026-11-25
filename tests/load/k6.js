// The load tests (TESTING.md, Load tests): one scenario of tests/load/scenarios.json against Restate
// and the load endpoint of tests/load/compose.yaml, reached on their loopback ports.
//
//   k6 run -e LOAD_SCENARIO=noop [-e LOAD_MODE=full] tests/load/k6.js
//
// setup() waits for both, registers the endpoint, warms up, waits until nothing is in flight and
// starts the endpoint's counts (POST /load-stats). The load is one constant-arrival-rate executor
// per phase: an open model, so a slow endpoint gets more concurrent requests instead of fewer.
// Every request is a synchronous ingress call that returns once the invocation completed.
// teardown() waits until nothing is in flight again and records what the run is for: the
// endpoint's CPU per invocation. The checks are thresholds, so k6 exits 99 when one fails.
//
// Environment: LOAD_SCENARIO; LOAD_MODE quick (default) or full; LOAD_INGRESS_URL, LOAD_ADMIN_URL,
// LOAD_ENDPOINT_URL (compose.yaml's ports by default), LOAD_ENDPOINT_URI (where Restate reaches the
// endpoint: http://endpoint:8080 by default); LOAD_SUMMARY, a file for the JSON result. devenv's
// load profile exports the URLs of the processes it runs.
import http from 'k6/http';
import { sleep } from 'k6';
import { Counter, Gauge, Rate } from 'k6/metrics';

const name = __ENV.LOAD_SCENARIO;
const scenario = JSON.parse(open('./scenarios.json'))[name];
if (!scenario) {
  throw new Error(`Unknown scenario "${name}" (tests/load/scenarios.json).`);
}
const ingress = __ENV.LOAD_INGRESS_URL || 'http://127.0.0.1:18080';
const admin = __ENV.LOAD_ADMIN_URL || 'http://127.0.0.1:19070';
const endpoint = __ENV.LOAD_ENDPOINT_URL || 'http://127.0.0.1:18081';
// Where Restate reaches the endpoint, which k6 registers.
const endpointUri = __ENV.LOAD_ENDPOINT_URI || 'http://endpoint:8080';
const mode = __ENV.LOAD_MODE === 'full' ? 'full' : 'quick';
const phases = scenario[mode];
const warmupSeconds = mode === 'full' ? 30 : 5;
const timeout = `${scenario.timeout || 30}s`;
const json = { headers: { 'content-type': 'application/json' } };

const failed = new Rate('load_failed');
const timedOut = new Counter('load_timeouts');
const invocations = new Gauge('invocations');
const cpu = new Gauge('endpoint_cpu_us_per_invocation');
const attempts = new Gauge('attempts_per_invocation');
const unauthorized = new Counter('endpoint_401');
const duplicates = new Gauge('run_duplicates');
const inFlightNow = new Gauge('in_flight');
const recovery = new Gauge('recovery_seconds');

const scenarios = {};
let loadSeconds = 0;
let arrivals = 0;
for (const phase of phases) {
  scenarios[phase.name] = {
    executor: 'constant-arrival-rate',
    exec: 'load',
    rate: phase.rate,
    timeUnit: '1s',
    duration: `${phase.duration}s`,
    startTime: `${loadSeconds}s`,
    // Enough VUs up front for about 5 s of latency: k6 starts more only slowly, and an arrival it
    // has no VU for is dropped.
    preAllocatedVUs: Math.min(2000, phase.rate * 5 + 20),
    maxVUs: 2000,
    gracefulStop: timeout,
  };
  loadSeconds += phase.duration;
  arrivals += phase.rate * phase.duration;
}
if (scenario.overload) {
  scenarios.monitor = { executor: 'per-vu-iterations', exec: 'monitor', vus: 1, iterations: 1, maxDuration: `${loadSeconds + 300}s` };
}

// What a run must never show, whatever the machine.
const thresholds = { endpoint_401: ['count==0'], invocations: ['value>0'] };
if (scenario.expected_attempts !== null) {
  // HTTP attempts per invocation, once retries are subtracted.
  thresholds.attempts_per_invocation = [`value==${scenario.expected_attempts}`];
}
if (!scenario.overload) {
  thresholds.load_failed = ['rate<=0.001'];
  thresholds.dropped_iterations = [`count<=${Math.floor(arrivals / 100)}`];
}

export const options = {
  scenarios,
  thresholds,
  discardResponseBodies: true,
  setupTimeout: `${warmupSeconds + 600}s`,
  teardownTimeout: '600s',
};

// The scenario's ingress URL, with a random one of its keys.
function target() {
  return `${ingress}${scenario.path.replace('{key}', `key-${Math.floor(Math.random() * (scenario.keys || 1000))}`)}`;
}

// The first row of a query on Restate's admin SQL API, or null.
function query(sql) {
  const response = http.post(`${admin}/query`, JSON.stringify({ query: sql }), {
    headers: { 'content-type': 'application/json', accept: 'application/json' },
    responseType: 'text',
  });
  return response.status === 200 ? JSON.parse(response.body).rows[0] : null;
}

function inFlight() {
  const services = scenario.services.map((service) => `'${service}'`).join(', ');
  const row = query(`SELECT count(*) AS n FROM sys_invocation WHERE target_service_name IN (${services}) AND status <> 'completed'`);
  return row === null ? null : row.n;
}

function waitFor(seconds, ready) {
  const deadline = Date.now() + seconds * 1000;
  while (!ready()) {
    if (Date.now() > deadline) {
      return false;
    }
    sleep(0.5);
  }
  return true;
}

function drain() {
  if (!waitFor(300, () => inFlight() === 0)) {
    console.warn(`${name}: invocations still in flight after 300 s`);
  }
}

export function setup() {
  if (!waitFor(120, () => query('SELECT count(*) AS n FROM sys_invocation') !== null)) {
    throw new Error(`Restate is not ready at ${admin}.`);
  }
  if (!waitFor(60, () => http.get(`${endpoint}/health`).status === 200)) {
    throw new Error(`The endpoint is not ready at ${endpoint}.`);
  }
  // Forced: every run registers the same URI again.
  const registered = http.post(`${admin}/deployments`, JSON.stringify({ uri: endpointUri, use_http_11: true, force: true }), { ...json, responseType: 'text' });
  if (registered.status < 200 || registered.status >= 300) {
    throw new Error(`Registering the endpoint failed: HTTP ${registered.status} ${registered.body}`);
  }

  // Warm-up, 20 calls at a time: OPcache, Restate's partitions, the connections.
  const warmupEnd = Date.now() + warmupSeconds * 1000;
  while (Date.now() < warmupEnd) {
    http.batch(Array.from({ length: 20 }, () => ['POST', target(), scenario.body, json]));
  }
  drain();

  http.post(`${endpoint}/load-stats`);
  // Restate's clock: the run's invocations are the ones created after it.
  return { since: Math.floor(query('SELECT CAST(now() AS BIGINT) / 1000000 AS ms').ms) };
}

export function load() {
  const response = http.post(target(), scenario.body, { ...json, timeout });
  failed.add(response.status !== 200);
  if (response.error_code === 1050) {
    timedOut.add(1);
  }
}

// overload: invocations in flight every half second, their level at the nominal rate (after the
// first second), and the seconds after the overload phase until in-flight is back to that level.
export function monitor() {
  const start = Date.now();
  const nominalEnd = phases[0].duration;
  const overloadEnd = nominalEnd + phases[1].duration;
  let level = 1;
  for (;;) {
    const seconds = (Date.now() - start) / 1000;
    const n = inFlight();
    if (n !== null) {
      inFlightNow.add(n);
      if (seconds >= 1 && seconds < nominalEnd) {
        level = Math.max(level, n);
      }
      if (seconds >= overloadEnd && n <= level) {
        recovery.add(Math.round((seconds - overloadEnd) * 10) / 10);
        return;
      }
    }
    sleep(0.5);
  }
}

export function teardown(data) {
  drain();
  const stats = JSON.parse(http.get(`${endpoint}/load-stats`, { responseType: 'text' }).body);
  const row = query(`SELECT count(*) AS n, coalesce(sum(retry_count), 0) AS retries FROM sys_invocation
    WHERE target_service_name = '${scenario.service}' AND status = 'completed' AND CAST(created_at AS BIGINT) >= ${data.since}`);
  invocations.add(row.n);
  unauthorized.add(stats.status_401);
  duplicates.add(stats.run_duplicates);
  if (row.n > 0) {
    if (stats.cpu_usec !== null) {
      cpu.add(Math.round((stats.cpu_usec / row.n) * 100) / 100);
    }
    attempts.add(Math.round((((stats.attempts[scenario.service] || 0) - row.retries) / row.n) * 1000) / 1000);
  }
}

export function handleSummary(data) {
  const value = (metric, key = 'value') => (data.metrics[metric] ? data.metrics[metric].values[key] : '-');
  let line = `${name} (${mode}): ${value('invocations')} invocations, endpoint CPU ${value('endpoint_cpu_us_per_invocation')} µs/invocation, `
    + `attempts ${value('attempts_per_invocation')}, 401s ${value('endpoint_401', 'count')}, duplicated runs ${value('run_duplicates')}`;
  if (scenario.overload) {
    line += `, timed out ${value('load_timeouts', 'count')}, peak in flight ${value('in_flight', 'max')}, recovery ${value('recovery_seconds')} s`;
  }
  const failures = Object.entries(data.metrics).flatMap(([metric, { thresholds: checks = {} }]) => Object.entries(checks)
    .filter(([, { ok }]) => !ok).map(([check]) => `\n  FAILED ${metric} ${check}`));
  const summary = { stdout: `${line}${failures.join('')}\n` };
  if (__ENV.LOAD_SUMMARY) {
    summary[__ENV.LOAD_SUMMARY] = JSON.stringify({ scenario: name, mode, commit: __ENV.LOAD_COMMIT || null, metrics: data.metrics });
  }
  return summary;
}
