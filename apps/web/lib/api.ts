const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8080";

export type Site = {
  id: string;
  organization_id: string;
  name: string;
  base_url: string;
  status: string;
  created_at: string;
  updated_at: string;
};

async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_URL}${path}`, {
    ...init,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(init.headers || {}),
    },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error((data as { error?: string }).error || `HTTP ${res.status}`);
  }
  return data as T;
}

export const client = {
  login: (email: string, password: string, totp_code?: string) =>
    api<{ ok: boolean }>("/auth/login", {
      method: "POST",
      body: JSON.stringify({ email, password, totp_code }),
    }),
  me: () => api<Record<string, unknown>>("/me"),
  sites: () => api<{ sites: Site[] }>("/sites"),
  createSite: (name: string, base_url: string) =>
    api<Site>("/sites", {
      method: "POST",
      body: JSON.stringify({ name, base_url }),
    }),
  killSwitch: () =>
    api<{ enabled: boolean; reason: string; scope: string }>("/security/kill-switch"),
  setKillSwitch: (enabled: boolean, reason: string) =>
    api<{ enabled: boolean }>("/security/kill-switch", {
      method: "POST",
      body: JSON.stringify({ enabled, reason }),
    }),
};

export { API_URL };
