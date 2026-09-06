"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { client } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [totp, setTotp] = useState("");
  const [needTotp, setNeedTotp] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      await client.login(email, password, totp || undefined);
      router.push("/");
    } catch (err) {
      const msg = err instanceof Error ? err.message : "فشل الدخول";
      if (msg === "totp_required") {
        setNeedTotp(true);
        setError("أدخل رمز المصادقة الثنائية");
      } else {
        setError(msg);
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      <h1>تسجيل الدخول</h1>
      <p className="muted">Login — Arabic-first RTL. Session cookie HttpOnly via API.</p>
      <form className="card" onSubmit={onSubmit} style={{ maxWidth: 420 }}>
        <div className="form-row">
          <label htmlFor="email">البريد الإلكتروني</label>
          <input
            id="email"
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>
        <div className="form-row">
          <label htmlFor="password">كلمة المرور</label>
          <input
            id="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </div>
        {(needTotp || totp) && (
          <div className="form-row">
            <label htmlFor="totp">رمز 2FA</label>
            <input
              id="totp"
              inputMode="numeric"
              value={totp}
              onChange={(e) => setTotp(e.target.value)}
            />
          </div>
        )}
        {error && <p className="error">{error}</p>}
        <button type="submit" disabled={loading}>
          {loading ? "…" : "دخول"}
        </button>
      </form>
      <p className="muted">
        لإنشاء المالك الأول استخدم{" "}
        <code>POST /auth/register</code> على الـ API (انظر README).
      </p>
    </div>
  );
}
