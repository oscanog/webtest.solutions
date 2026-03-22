import { APIRequestContext, APIResponse, expect } from "@playwright/test";

export type ApiEnvelope<T> =
  | { ok: true; data: T }
  | { ok: false; error: { code: string; message: string; details?: unknown } };

export type JsonValue = null | boolean | number | string | JsonValue[] | { [key: string]: JsonValue };

export async function parseJson<T>(res: APIResponse): Promise<T> {
  const text = await res.text();
  try {
    return JSON.parse(text) as T;
  } catch {
    throw new Error(`Expected JSON response. Status=${res.status()} Body=${text}`);
  }
}

export function expectApiSuccess<T>(body: ApiEnvelope<T>): asserts body is { ok: true; data: T } {
  expect(body.ok).toBe(true);
  if (!body.ok) {
    throw new Error(`Expected ok=true but got ${JSON.stringify(body.error)}`);
  }
}

export function bearerHeader(accessToken: string): Record<string, string> {
  return { Authorization: `Bearer ${accessToken}` };
}

export async function apiGet<T>(
  request: APIRequestContext,
  url: string,
  headers?: Record<string, string>
): Promise<{ res: APIResponse; body: T }> {
  const res = await request.get(url, headers ? { headers } : undefined);
  const body = await parseJson<T>(res);
  return { res, body };
}

export async function apiPostJson<T>(
  request: APIRequestContext,
  url: string,
  payload: unknown,
  headers?: Record<string, string>
): Promise<{ res: APIResponse; body: T }> {
  const res = await request.post(url, {
    data: payload,
    headers,
  });
  const body = await parseJson<T>(res);
  return { res, body };
}

export async function apiPutJson<T>(
  request: APIRequestContext,
  url: string,
  payload: unknown,
  headers?: Record<string, string>
): Promise<{ res: APIResponse; body: T }> {
  const res = await request.fetch(url, {
    method: "PUT",
    data: payload,
    headers,
  });
  const body = await parseJson<T>(res);
  return { res, body };
}

export async function apiPatchJson<T>(
  request: APIRequestContext,
  url: string,
  payload: unknown,
  headers?: Record<string, string>
): Promise<{ res: APIResponse; body: T }> {
  const res = await request.fetch(url, {
    method: "PATCH",
    data: payload,
    headers,
  });
  const body = await parseJson<T>(res);
  return { res, body };
}

export async function apiDeleteJson<T>(
  request: APIRequestContext,
  url: string,
  payload?: unknown,
  headers?: Record<string, string>
): Promise<{ res: APIResponse; body: T }> {
  const init: {
    method: string;
    headers?: Record<string, string>;
    data?: unknown;
  } = {
    method: "DELETE",
    headers,
  };
  if (payload !== undefined) {
    init.data = payload;
  }

  const res = await request.fetch(url, init);
  const body = await parseJson<T>(res);
  return { res, body };
}
