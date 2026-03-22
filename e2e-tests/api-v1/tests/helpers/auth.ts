import { APIRequestContext, expect } from "@playwright/test";
import { AccountKey, cfg } from "../../src/config";
import { ApiEnvelope, apiPostJson, bearerHeader, expectApiSuccess } from "./client";

type LoginResponse = {
  user: {
    id: number;
    username: string;
    email: string;
    role: string;
  };
  active_org_id: number;
  tokens: {
    token_type: string;
    access_token: string;
    access_expires_in: number;
    refresh_token: string;
    refresh_expires_in: number;
  };
};

export type RoleSession = {
  key: AccountKey;
  userId: number;
  email: string;
  accessToken: string;
  refreshToken: string;
  activeOrgId: number;
};

export async function loginRole(
  request: APIRequestContext,
  key: AccountKey,
  activeOrgId = cfg.orgId
): Promise<RoleSession> {
  const account = cfg.accounts[key];
  const { res, body } = await apiPostJson<ApiEnvelope<LoginResponse>>(
    request,
    `${cfg.apiBasePath}/auth/login`,
    {
      email: account.email,
      password: account.password,
      active_org_id: activeOrgId,
    }
  );

  expect(res.status()).toBe(200);
  expectApiSuccess(body);
  expect(body.data.tokens.access_token.length).toBeGreaterThan(20);

  return {
    key,
    userId: account.userId,
    email: account.email,
    accessToken: body.data.tokens.access_token,
    refreshToken: body.data.tokens.refresh_token,
    activeOrgId: body.data.active_org_id,
  };
}

export function authHeaders(session: RoleSession): Record<string, string> {
  return bearerHeader(session.accessToken);
}
