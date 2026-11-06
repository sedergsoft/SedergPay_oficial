'use strict';

/**
 * Ballerine KYC mock for local development.
 *
 * Emulates the small part of Ballerine that SEDERG_PAY consumes:
 *   POST /api/v1/external/workflows/run   -> starts a collection workflow
 *   GET  /?workflowRuntimeId=...          -> collection UI
 *
 * The collection UI never touches customer data. It only lets a developer emit
 * signed provider events so the real webhook, idempotency and admin-review paths
 * can be exercised end to end inside Docker.
 *
 * No third-party dependencies: it runs on the stock node:20-alpine image.
 */

const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const config = {
  port: intEnv('PORT', 3000),
  apiKey: env('KYC_MOCK_API_KEY', 'local-mock-api-key'),
  authScheme: env('KYC_MOCK_AUTH_SCHEME', 'Api-Key'),
  workflowId: env('KYC_MOCK_WORKFLOW_ID', 'kyc-manual-review'),
  webhookUrl: env('KYC_MOCK_WEBHOOK_URL', 'http://backend:8080/api/v1/kyc/webhooks/ballerine'),
  webhookSecret: env('KYC_MOCK_WEBHOOK_SECRET', ''),
  publicUrl: env('KYC_MOCK_PUBLIC_URL', 'http://localhost:5174').replace(/\/+$/, ''),
  appUrl: env('KYC_MOCK_APP_URL', 'http://localhost:5173').replace(/\/+$/, ''),
  webhookTimeoutMs: intEnv('KYC_MOCK_WEBHOOK_TIMEOUT_MS', 10000),
  maxSessions: intEnv('KYC_MOCK_MAX_SESSIONS', 200),
};

const MAX_BODY_BYTES = 256 * 1024;
const ID_PATTERN = /^[A-Za-z0-9._:-]{1,190}$/;

/** @type {Map<string, object>} */
const sessions = new Map();

const ACTIONS = {
  pending_documents: {
    state: 'pending_documents',
    eventName: 'workflow.state_changed',
    label: 'Documentos solicitados',
  },
  in_progress: {
    state: 'in_progress',
    eventName: 'workflow.state_changed',
    label: 'Verificación en curso',
  },
  pending_review: {
    state: 'pending_review',
    eventName: 'workflow.completed',
    label: 'Enviado a revisión',
  },
  approved: {
    state: 'approved',
    eventName: 'workflow.completed',
    label: 'Proveedor aprueba',
    decision: true,
  },
  rejected: {
    state: 'rejected',
    eventName: 'workflow.completed',
    label: 'Proveedor rechaza',
    decision: true,
  },
  revision: {
    state: 'revision',
    eventName: 'workflow.completed',
    label: 'Solicitar nueva documentación',
    decision: true,
  },
  expired: {
    state: 'expired',
    eventName: 'workflow.expired',
    label: 'Sesión expirada',
  },
  failed: {
    state: 'failed',
    eventName: 'workflow.failed',
    label: 'Error del proveedor',
  },
  unknown_state: {
    state: 'provider_state_unknown',
    eventName: 'workflow.state_changed',
    label: 'Estado desconocido (cuarentena)',
  },
};

const uiHtml = fs.readFileSync(path.join(__dirname, 'ui.html'), 'utf8');

const server = http.createServer((req, res) => {
  handle(req, res).catch((error) => {
    console.error('[kyc-mock] unhandled error:', error);
    if (!res.headersSent) {
      sendJson(res, 500, { error: 'internal_error' });
    } else {
      res.end();
    }
  });
});

server.listen(config.port, '0.0.0.0', () => {
  console.log(`[kyc-mock] listening on :${config.port}`);
  console.log(`[kyc-mock] webhook target: ${config.webhookUrl}`);
  console.log(`[kyc-mock] webhook secret configured: ${config.webhookSecret ? 'yes' : 'NO'}`);
  if (!config.webhookSecret) {
    console.warn('[kyc-mock] KYC_MOCK_WEBHOOK_SECRET is empty; events will not be delivered.');
  }
});

async function handle(req, res) {
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const pathname = url.pathname;

  if (req.method === 'GET' && (pathname === '/' || pathname === '/index.html')) {
    res.writeHead(200, {
      'Content-Type': 'text/html; charset=utf-8',
      'Cache-Control': 'no-store',
      'X-Content-Type-Options': 'nosniff',
      'Referrer-Policy': 'no-referrer',
    });
    res.end(uiHtml);
    return;
  }

  if (req.method === 'GET' && pathname === '/api/health') {
    sendJson(res, 200, {
      ok: true,
      service: 'ballerine-kyc-mock',
      workflow_id: config.workflowId,
      webhook_url: config.webhookUrl,
      webhook_secret_configured: config.webhookSecret !== '',
      public_url: config.publicUrl,
      app_url: config.appUrl,
      sessions: sessions.size,
    });
    return;
  }

  if (req.method === 'POST' && pathname === '/api/v1/external/workflows/run') {
    await runWorkflow(req, res);
    return;
  }

  if (req.method === 'GET' && pathname === '/api/sessions') {
    sendJson(res, 200, { sessions: listSessions() });
    return;
  }

  if (req.method === 'POST' && pathname === '/api/sessions/demo') {
    const session = createSession({
      workflowId: config.workflowId,
      casePublicId: null,
      userPublicId: null,
      countryCode: null,
      locale: 'es',
      returnUrl: config.appUrl,
      applicantName: 'Sesión de demostración',
      adopted: true,
    });
    sendJson(res, 201, { session: publicSession(session) });
    return;
  }

  const sessionMatch = pathname.match(/^\/api\/sessions\/([^/]+)(\/[a-z]+)?$/);
  if (sessionMatch) {
    const id = decodeURIComponent(sessionMatch[1]);
    const suffix = sessionMatch[2] || '';

    if (!ID_PATTERN.test(id)) {
      sendJson(res, 400, { error: 'invalid_session_id' });
      return;
    }

    if (req.method === 'GET' && suffix === '') {
      const session = sessions.get(id);
      if (!session) {
        sendJson(res, 404, { error: 'session_not_found', runtime_id: id, can_adopt: true });
        return;
      }
      sendJson(res, 200, { session: publicSession(session) });
      return;
    }

    if (req.method === 'POST' && suffix === '/adopt') {
      let session = sessions.get(id);
      if (!session) {
        session = createSession({
          workflowId: config.workflowId,
          casePublicId: null,
          userPublicId: null,
          countryCode: null,
          locale: 'es',
          returnUrl: config.appUrl,
          applicantName: 'Sesión recuperada',
          adopted: true,
          runtimeId: id,
        });
      }
      sendJson(res, 200, { session: publicSession(session) });
      return;
    }

    if (req.method === 'POST' && suffix === '/events') {
      await emitEvent(req, res, id);
      return;
    }

    sendJson(res, 405, { error: 'method_not_allowed' });
    return;
  }

  sendJson(res, 404, { error: 'not_found' });
}

async function runWorkflow(req, res) {
  if (!authorized(req)) {
    sendJson(res, 401, { error: 'unauthorized' });
    return;
  }

  let body;
  try {
    body = await readJson(req);
  } catch (error) {
    sendJson(res, error.statusCode || 400, { error: error.code || 'invalid_body' });
    return;
  }

  if (body === null || typeof body !== 'object' || Array.isArray(body)) {
    sendJson(res, 400, { error: 'body_must_be_an_object' });
    return;
  }

  const workflowId = typeof body.workflowId === 'string' ? body.workflowId.trim() : '';
  if (workflowId === '') {
    sendJson(res, 422, { error: 'workflowId is required' });
    return;
  }
  if (workflowId !== config.workflowId) {
    sendJson(res, 404, {
      error: 'unknown_workflow',
      message: `This mock only knows '${config.workflowId}'.`,
    });
    return;
  }

  const context = isPlainObject(body.context) ? body.context : {};
  const entity = isPlainObject(context.entity) ? context.entity : {};
  const entityData = isPlainObject(entity.data) ? entity.data : {};
  const metadata = isPlainObject(context.metadata) ? context.metadata : {};

  const session = createSession({
    workflowId,
    runtimeId: undefined,
    casePublicId: stringOrNull(metadata.sedergCasePublicId),
    userPublicId: stringOrNull(metadata.sedergUserPublicId),
    countryCode: stringOrNull(metadata.countryCode),
    locale: stringOrNull(metadata.locale) || 'es',
    returnUrl: stringOrNull(metadata.returnUrl) || config.appUrl,
    applicantName: [entityData.firstName, entityData.lastName]
      .filter((value) => typeof value === 'string' && value.trim() !== '')
      .join(' ')
      .trim() || 'Solicitante anónimo',
  });

  sendJson(res, 201, {
    workflowRuntimeId: session.id,
    ballerineEntityId: session.entityId,
    status: 'created',
    collectionUrl: collectionUrl(session.id),
  });
}

async function emitEvent(req, res, sessionId) {
  let session = sessions.get(sessionId);
  if (!session) {
    sendJson(res, 404, { error: 'session_not_found', runtime_id: sessionId, can_adopt: true });
    return;
  }

  let body;
  try {
    body = await readJson(req);
  } catch (error) {
    sendJson(res, error.statusCode || 400, { error: error.code || 'invalid_body' });
    return;
  }

  const action = isPlainObject(body) && typeof body.action === 'string' ? body.action : '';

  if (action === 'resend_last') {
    const last = session.events[session.events.length - 1];
    if (!last) {
      sendJson(res, 409, { error: 'no_events_to_resend' });
      return;
    }
    const delivery = await deliver(last.rawBody);
    last.deliveries.push(delivery);
    last.lastDelivery = delivery;
    session.updatedAt = new Date().toISOString();
    sendJson(res, 200, {
      ok: delivery.ok,
      duplicate: delivery.body && delivery.body.duplicate === true,
      event: summarizeEvent(last),
      session: publicSession(session),
    });
    return;
  }

  const definition = ACTIONS[action];
  if (!definition) {
    sendJson(res, 422, {
      error: 'unknown_action',
      actions: Object.keys(ACTIONS),
    });
    return;
  }

  const event = buildEvent(session, definition);
  const delivery = await deliver(event.rawBody);

  const record = {
    id: event.payload.id,
    action,
    label: definition.label,
    state: definition.state,
    eventName: definition.eventName,
    timestamp: event.payload.timestamp,
    rawBody: event.rawBody,
    deliveries: [delivery],
    lastDelivery: delivery,
  };
  session.events.push(record);
  session.status = definition.state;
  session.updatedAt = new Date().toISOString();

  sendJson(res, 200, {
    ok: delivery.ok,
    event: summarizeEvent(record),
    session: publicSession(session),
  });
}

function buildEvent(session, definition) {
  const payload = {
    id: crypto.randomUUID(),
    eventName: definition.eventName,
    workflowRuntimeId: session.id,
    workflowDefinitionId: session.workflowId,
    ballerineEntityId: session.entityId,
    correlationId: crypto.randomUUID(),
    environment: 'mock',
    timestamp: new Date().toISOString(),
    state: definition.state,
  };

  if (definition.decision) {
    const documentStatus = definition.state;
    payload.data = {
      decision: { status: documentStatus },
      documents: [
        { id: 'mock-identity-document', type: 'identity', decision: { status: documentStatus } },
        { id: 'mock-address-document', type: 'address', decision: { status: documentStatus } },
      ],
    };
  } else {
    payload.data = { documents: [] };
  }

  return { payload, rawBody: JSON.stringify(payload) };
}

async function deliver(rawBody) {
  const sentAt = new Date().toISOString();

  if (!config.webhookSecret) {
    return {
      ok: false,
      httpStatus: 0,
      sentAt,
      error: 'KYC_MOCK_WEBHOOK_SECRET is empty; configure it to deliver events.',
      body: null,
    };
  }

  const signature = crypto.createHmac('sha256', config.webhookSecret).update(rawBody).digest('hex');

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.webhookTimeoutMs);
  try {
    const response = await fetch(config.webhookUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Authorization': config.webhookSecret,
        'X-Ballerine-Signature': `sha256=${signature}`,
        'User-Agent': 'sederg-ballerine-kyc-mock/1.0',
      },
      body: rawBody,
      signal: controller.signal,
    });
    const text = await response.text();
    let parsed = null;
    if (text !== '') {
      try {
        parsed = JSON.parse(text);
      } catch {
        parsed = text.slice(0, 1000);
      }
    }

    return {
      ok: response.ok,
      httpStatus: response.status,
      sentAt,
      body: parsed,
      error: response.ok ? null : `HTTP ${response.status}`,
    };
  } catch (error) {
    return {
      ok: false,
      httpStatus: 0,
      sentAt,
      error: error && error.name === 'AbortError' ? 'webhook request timed out' : String(error),
      body: null,
    };
  } finally {
    clearTimeout(timer);
  }
}

function createSession(input) {
  const id = input.runtimeId || crypto.randomUUID();
  const now = new Date().toISOString();
  const session = {
    id,
    entityId: input.entityId || crypto.randomUUID(),
    workflowId: input.workflowId,
    casePublicId: input.casePublicId || null,
    userPublicId: input.userPublicId || null,
    countryCode: input.countryCode || null,
    locale: input.locale || 'es',
    returnUrl: input.returnUrl || config.appUrl,
    applicantName: input.applicantName || 'Solicitante anónimo',
    adopted: input.adopted === true,
    status: 'created',
    createdAt: now,
    updatedAt: now,
    events: [],
  };

  sessions.set(id, session);
  evictOldSessions();

  return session;
}

function evictOldSessions() {
  while (sessions.size > config.maxSessions) {
    const oldest = sessions.keys().next().value;
    if (oldest === undefined) {
      return;
    }
    sessions.delete(oldest);
  }
}

function listSessions() {
  return Array.from(sessions.values())
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt))
    .slice(0, 50)
    .map(publicSession);
}

function publicSession(session) {
  return {
    runtime_id: session.id,
    entity_id: session.entityId,
    workflow_id: session.workflowId,
    case_public_id: session.casePublicId,
    user_public_id: session.userPublicId,
    country_code: session.countryCode,
    locale: session.locale,
    return_url: session.returnUrl,
    applicant_name: session.applicantName,
    adopted: session.adopted,
    status: session.status,
    created_at: session.createdAt,
    updated_at: session.updatedAt,
    collection_url: collectionUrl(session.id),
    events: session.events.map(summarizeEvent),
  };
}

function summarizeEvent(event) {
  return {
    id: event.id,
    action: event.action,
    label: event.label,
    state: event.state,
    event_name: event.eventName,
    timestamp: event.timestamp,
    delivery: event.lastDelivery
      ? {
          ok: event.lastDelivery.ok,
          http_status: event.lastDelivery.httpStatus,
          error: event.lastDelivery.error,
          body: event.lastDelivery.body,
          sent_at: event.lastDelivery.sentAt,
          attempts: event.deliveries.length,
        }
      : null,
  };
}

function collectionUrl(runtimeId) {
  return `${config.publicUrl}/?workflowRuntimeId=${encodeURIComponent(runtimeId)}`;
}

function authorized(req) {
  const header = String(req.headers.authorization || '').trim();
  if (header === '') {
    return false;
  }
  const expected = `${config.authScheme} ${config.apiKey}`;
  return safeEqual(header, expected) || safeEqual(header, config.apiKey);
}

function safeEqual(a, b) {
  const left = Buffer.from(String(a));
  const right = Buffer.from(String(b));
  return left.length === right.length && crypto.timingSafeEqual(left, right);
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];

    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        const error = new Error('body too large');
        error.statusCode = 413;
        error.code = 'body_too_large';
        reject(error);
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });

    req.on('end', () => {
      if (size === 0) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        const error = new Error('invalid json');
        error.statusCode = 400;
        error.code = 'invalid_json';
        reject(error);
      }
    });

    req.on('error', (error) => reject(error));
  });
}

function sendJson(res, status, payload) {
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  res.end(body);
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function stringOrNull(value) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function env(name, fallback) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;
}

function intEnv(name, fallback) {
  const value = env(name, '');
  if (value === '' || !/^\d+$/.test(value)) {
    return fallback;
  }
  return Number.parseInt(value, 10);
}

function shutdown(signal) {
  console.log(`[kyc-mock] received ${signal}, shutting down`);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
