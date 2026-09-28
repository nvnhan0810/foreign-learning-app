import { ensureHostPermissionForApi } from './api-permissions';
import { apiOrigin } from './config';
import { getSettings, saveAuth } from './storage';

interface SsoExchangeResponse {
  token?: string;
  email?: string;
  name?: string;
  message?: string;
}

/**
 * Chrome extension SSO — same flow as mobile:
 * 1) launchWebAuthFlow → /api/auth/sso/redirect (IdP: nvnhan0810.com / dev)
 * 2) callback with code + state
 * 3) POST /api/auth/sso/exchange → Sanctum token
 */
export async function loginWithSso(): Promise<void> {
  const settings = await getSettings();
  const perm = await ensureHostPermissionForApi(settings.apiBaseUrl);
  if (!perm.ok) {
    throw new Error(perm.message ?? 'No permission to access the API.');
  }

  const redirectUri = chrome.identity.getRedirectURL();
  const startUrl =
    `${apiOrigin(settings.apiBaseUrl)}/api/auth/sso/redirect?` +
    `redirect_uri=${encodeURIComponent(redirectUri)}`;

  const responseUrl = await chrome.identity.launchWebAuthFlow({
    url: startUrl,
    interactive: true,
  });

  if (!responseUrl) {
    throw new Error('Sign-in was cancelled.');
  }

  const parsed = new URL(responseUrl);
  const error = parsed.searchParams.get('error');
  if (error) {
    throw new Error(error);
  }

  const code = parsed.searchParams.get('code');
  const state = parsed.searchParams.get('state');
  if (!code || !state) {
    throw new Error('No authorization code received from SSO.');
  }

  const exchangeRes = await fetch(`${settings.apiBaseUrl}/auth/sso/exchange`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      code,
      state,
      redirect_uri: redirectUri,
    }),
  });

  const body = (await exchangeRes.json().catch(() => ({}))) as SsoExchangeResponse;

  if (!exchangeRes.ok) {
    throw new Error(body.message ?? 'SSO sign-in failed.');
  }

  if (!body.token) {
    throw new Error('No token received from server.');
  }

  await saveAuth({
    token: body.token,
    email: body.email ?? null,
    userName: body.name ?? null,
  });
}
