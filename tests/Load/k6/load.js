// k6 driver of the load tests (tests/Load/LoadRunner.php writes the environment): an open model,
// one constant-arrival-rate executor per phase, so a slow endpoint gets more concurrent requests
// instead of fewer (no coordinated omission). Every request is a synchronous ingress call
// (POST /<service>[/<key>]/<handler>) that returns once the invocation completed.
//
// Environment:
//   LOAD_INGRESS_URL  Restate's ingress, e.g. http://127.0.0.1:18080
//   LOAD_PATH         the target path; {key} is replaced with a key in [0, LOAD_KEYS)
//   LOAD_KEYS         number of distinct virtual-object keys (default 1000)
//   LOAD_BODY         the JSON request body
//   LOAD_PHASES       JSON list of {name, rate, duration, startTime?}: rate per second,
//                     durations as k6 durations ("20s")
//   LOAD_TIMEOUT      request timeout ("10s"): a timed-out call is counted, the invocation goes on
//   LOAD_MAX_VUS      most concurrent requests k6 may have open (default 2000)
//   LOAD_SUMMARY      where to write the JSON summary the runner reads
import http from 'k6/http';
import { Counter } from 'k6/metrics';

const ingress = __ENV.LOAD_INGRESS_URL;
const path = __ENV.LOAD_PATH;
const keys = parseInt(__ENV.LOAD_KEYS || '1000', 10);
const body = __ENV.LOAD_BODY || '""';
const timeout = __ENV.LOAD_TIMEOUT || '10s';
const maxVus = parseInt(__ENV.LOAD_MAX_VUS || '2000', 10);
const phases = JSON.parse(__ENV.LOAD_PHASES || '[]');

const timeouts = new Counter('load_timeouts');
const failures = new Counter('load_failures');
const completed = new Counter('load_completed');

const scenarios = {};
const thresholds = {};
for (const phase of phases) {
  scenarios[phase.name] = {
    executor: 'constant-arrival-rate',
    rate: phase.rate,
    timeUnit: '1s',
    duration: phase.duration,
    startTime: phase.startTime || '0s',
    // Enough VUs up front for about 5 s of latency: k6 starts more only slowly, and an arrival it
    // has no VU for is dropped (the runner fails a run that drops more than 1%).
    preAllocatedVUs: Math.min(maxVus, phase.rate * 5 + 20),
    maxVUs: maxVus,
    gracefulStop: timeout,
  };
  // A threshold on a tagged sub-metric is what makes k6 report it in the summary; these never fail.
  thresholds[`http_req_duration{scenario:${phase.name}}`] = ['max>=0'];
  thresholds[`load_completed{scenario:${phase.name}}`] = ['count>=0'];
  thresholds[`load_failures{scenario:${phase.name}}`] = ['count>=0'];
  thresholds[`load_timeouts{scenario:${phase.name}}`] = ['count>=0'];
  thresholds[`dropped_iterations{scenario:${phase.name}}`] = ['count>=0'];
}

export const options = {
  scenarios,
  thresholds,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(99)', 'max', 'count'],
  discardResponseBodies: true,
};

export default function () {
  const target = path.replace('{key}', `key-${Math.floor(Math.random() * keys)}`);
  const response = http.post(`${ingress}${target}`, body, {
    headers: { 'content-type': 'application/json' },
    timeout,
  });
  if (response.status === 200) {
    completed.add(1);
  } else if (response.error_code === 1050) {
    timeouts.add(1);
  } else {
    failures.add(1);
  }
}

export function handleSummary(data) {
  return {
    [__ENV.LOAD_SUMMARY]: JSON.stringify(data),
    stdout: `k6: ${data.metrics.iterations ? data.metrics.iterations.values.count : 0} requests\n`,
  };
}
