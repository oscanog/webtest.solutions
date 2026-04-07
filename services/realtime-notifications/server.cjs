const crypto = require('crypto')
const http = require('http')
const path = require('path')
const { execFileSync } = require('child_process')
const { WebSocketServer } = require('ws')

function parseBool(value, fallback) {
  if (value === undefined || value === null || value === '') {
    return fallback
  }

  const normalized = String(value).trim().toLowerCase()
  if (['1', 'true', 'yes', 'on'].includes(normalized)) {
    return true
  }
  if (['0', 'false', 'no', 'off'].includes(normalized)) {
    return false
  }
  return fallback
}

function readPhpConfig() {
  const script = path.join(__dirname, 'read-config.php')
  const raw = execFileSync('php', [script], {
    cwd: __dirname,
    encoding: 'utf8',
    env: process.env,
  })
  return JSON.parse(raw.replace(/^\uFEFF/, ''))
}

function loadConfig() {
  const phpConfig = readPhpConfig()
  return {
    enabled: parseBool(process.env.REALTIME_NOTIFICATIONS_ENABLED, phpConfig.enabled !== false),
    host: process.env.REALTIME_NOTIFICATIONS_HOST || phpConfig.host || '127.0.0.1',
    port: Number.parseInt(process.env.REALTIME_NOTIFICATIONS_PORT || phpConfig.port || '8090', 10),
    path: process.env.REALTIME_NOTIFICATIONS_PATH || phpConfig.path || '/ws/notifications',
    internalSharedSecret:
      process.env.REALTIME_NOTIFICATIONS_INTERNAL_SHARED_SECRET ||
      phpConfig.internal_shared_secret ||
      'webtest-realtime-dev-secret',
    socketSecret:
      process.env.REALTIME_NOTIFICATIONS_SOCKET_SECRET ||
      phpConfig.socket_secret ||
      'webtest-realtime-dev-secret',
  }
}

function deriveSecret(namespace, seed) {
  return crypto.createHash('sha256').update(`${namespace}|${seed}`).digest()
}

function base64UrlDecode(value) {
  const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/')
  const padding = normalized.length % 4
  const padded = padding > 0 ? `${normalized}${'='.repeat(4 - padding)}` : normalized
  return Buffer.from(padded, 'base64')
}

function verifySocketToken(token, socketSecret) {
  const parts = String(token || '').split('.')
  if (parts.length !== 3) {
    return null
  }

  const [head, body, signature] = parts
  const expected = crypto.createHmac('sha256', deriveSecret('webtest-realtime', socketSecret)).update(`${head}.${body}`).digest()
  const actual = base64UrlDecode(signature)
  if (actual.length === 0 || actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) {
    return null
  }

  try {
    const payload = JSON.parse(base64UrlDecode(body).toString('utf8'))
    if (!payload || payload.type !== 'socket' || payload.aud !== 'notifications') {
      return null
    }
    if (Number(payload.exp || 0) <= Math.floor(Date.now() / 1000)) {
      return null
    }
    if (Number(payload.sub || 0) <= 0) {
      return null
    }
    return payload
  } catch {
    return null
  }
}

function sendJson(response, statusCode, body) {
  response.writeHead(statusCode, {
    'Content-Type': 'application/json',
    'Cache-Control': 'no-store',
  })
  response.end(JSON.stringify(body))
}

async function readJson(request) {
  const chunks = []
  let total = 0

  for await (const chunk of request) {
    total += chunk.length
    if (total > 1024 * 1024) {
      throw new Error('Payload too large')
    }
    chunks.push(chunk)
  }

  if (chunks.length === 0) {
    return {}
  }

  return JSON.parse(Buffer.concat(chunks).toString('utf8'))
}

const config = loadConfig()
const clientsByUser = new Map()

function log(message) {
  process.stdout.write(`[webtest-realtime] ${message}\n`)
}

function registerClient(userId, socket) {
  const key = String(userId)
  const set = clientsByUser.get(key) || new Set()
  set.add(socket)
  clientsByUser.set(key, set)
}

function unregisterClient(userId, socket) {
  const key = String(userId)
  const set = clientsByUser.get(key)
  if (!set) {
    return
  }

  set.delete(socket)
  if (set.size === 0) {
    clientsByUser.delete(key)
  }
}

function broadcastToUser(userId, payload) {
  const sockets = clientsByUser.get(String(userId))
  if (!sockets || sockets.size === 0) {
    return 0
  }

  const message = JSON.stringify(payload)
  let delivered = 0
  for (const socket of sockets) {
    if (socket.readyState === socket.OPEN) {
      socket.send(message)
      delivered += 1
    }
  }
  return delivered
}

const server = http.createServer(async (request, response) => {
  const host = request.headers.host || `${config.host}:${config.port}`
  const url = new URL(request.url || '/', `http://${host}`)

  if (request.method === 'GET' && url.pathname === '/health') {
    sendJson(response, 200, {
      ok: true,
      data: {
        status: 'ok',
        connections: Array.from(clientsByUser.values()).reduce((sum, sockets) => sum + sockets.size, 0),
      },
    })
    return
  }

  if (request.method === 'POST' && url.pathname === '/internal/publish') {
    const header = String(request.headers.authorization || '')
    if (header !== `Bearer ${config.internalSharedSecret}`) {
      sendJson(response, 401, {
        ok: false,
        error: { code: 'unauthorized', message: 'Invalid realtime shared secret.' },
      })
      return
    }

    try {
      const payload = await readJson(request)
      const recipientUserId = Number(payload.recipient_user_id || 0)
      if (recipientUserId <= 0) {
        sendJson(response, 422, {
          ok: false,
          error: { code: 'invalid_recipient', message: 'recipient_user_id is required.' },
        })
        return
      }

      const delivered = broadcastToUser(recipientUserId, {
        ...payload,
        timestamp: payload.timestamp || new Date().toISOString(),
      })

      sendJson(response, 202, {
        ok: true,
        data: { delivered },
      })
    } catch (error) {
      sendJson(response, 400, {
        ok: false,
        error: {
          code: 'invalid_payload',
          message: error instanceof Error ? error.message : 'Unable to read publish payload.',
        },
      })
    }
    return
  }

  sendJson(response, 404, {
    ok: false,
    error: { code: 'not_found', message: 'Route not found.' },
  })
})

const websocketServer = new WebSocketServer({ noServer: true })

websocketServer.on('connection', (socket, request, payload) => {
  const userId = Number(payload.sub)
  socket.isAlive = true

  registerClient(userId, socket)
  socket.send(
    JSON.stringify({
      type: 'system.connected',
      user_id: userId,
      timestamp: new Date().toISOString(),
    }),
  )

  socket.on('pong', () => {
    socket.isAlive = true
  })

  socket.on('message', (data) => {
    try {
      const body = JSON.parse(String(data))
      if (body?.type === 'ping') {
        socket.send(JSON.stringify({ type: 'pong', timestamp: new Date().toISOString() }))
      }
    } catch {
      // Ignore non-JSON client messages.
    }
  })

  socket.on('close', () => {
    unregisterClient(userId, socket)
  })

  socket.on('error', () => {
    unregisterClient(userId, socket)
  })
})

server.on('upgrade', (request, socket, head) => {
  const host = request.headers.host || `${config.host}:${config.port}`
  const url = new URL(request.url || '/', `http://${host}`)
  if (url.pathname !== config.path) {
    socket.destroy()
    return
  }

  const token = url.searchParams.get('token')
  const payload = verifySocketToken(token, config.socketSecret)
  if (!payload) {
    socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n')
    socket.destroy()
    return
  }

  websocketServer.handleUpgrade(request, socket, head, (websocket) => {
    websocketServer.emit('connection', websocket, request, payload)
  })
})

const heartbeat = setInterval(() => {
  for (const sockets of clientsByUser.values()) {
    for (const socket of sockets) {
      if (!socket.isAlive) {
        socket.terminate()
        continue
      }
      socket.isAlive = false
      socket.ping()
    }
  }
}, 30000)

websocketServer.on('close', () => {
  clearInterval(heartbeat)
})

server.listen(config.port, config.host, () => {
  log(`Listening on http://${config.host}:${config.port}${config.path} (enabled=${config.enabled})`)
})
