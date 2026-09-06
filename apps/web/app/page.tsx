"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { client, Site } from "@/lib/api";

export default function CommandCenterPage() {
  const [sites, setSites] = useState<Site[] | null>(null);
  const [error, setError] = useState("");
  const [me, setMe] = useState<Record<string, unknown> | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const m = await client.me();
        setMe(m);
        const s = await client.sites();
        setSites(s.sites);
      } catch (e) {
        setError(e instanceof Error ? e.message : "فشل التحميل");
        setSites([]);
      }
    })();
  }, []);

  return (
    <div>
      <h1>مركز القيادة</h1>
      <p className="muted">
        نظام تشغيل SEO متعدد المواقع — بدون أرقام وهمية. الوحدات غير المبنية مُعلَّمة صراحةً.
      </p>

      <div className="grid cols-2">
        <div className="card">
          <h2>الحساب</h2>
          {me ? (
            <ul>
              <li>{String(me.email)}</li>
              <li>
                مالك المنصة:{" "}
                {me.is_platform_owner ? (
                  <span className="badge ok">نعم</span>
                ) : (
                  <span className="badge">لا</span>
                )}
              </li>
              <li>
                2FA:{" "}
                {me.totp_enabled ? (
                  <span className="badge ok">مفعّل</span>
                ) : (
                  <span className="badge warn">غير مفعّل</span>
                )}
              </li>
            </ul>
          ) : error ? (
            <p>
              غير مسجّل الدخول. <Link href="/login">تسجيل الدخول</Link>
            </p>
          ) : (
            <p className="muted">جاري التحميل…</p>
          )}
        </div>

        <div className="card">
          <h2>المواقع</h2>
          {sites === null ? (
            <p className="muted">جاري التحميل…</p>
          ) : sites.length === 0 ? (
            <p>
              لا مواقع بعد. <Link href="/sites/new">أضف موقعاً</Link>
            </p>
          ) : (
            <ul>
              {sites.map((s) => (
                <li key={s.id}>
                  <Link href={`/sites`}>{s.name}</Link>{" "}
                  <span className="badge">{s.status}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="card">
        <h2>وحدات غير مُنفَّذة بعد</h2>
        <p>
          <span className="badge warn">NOT IMPLEMENTED</span> المهام · العقود · المعاينة ·
          الموافقة · التنفيذ · التحقق · التراجع · المصنع · الذكاء · القوى العاملة
        </p>
        <p className="muted">
          EN: Missions, Action Contracts, Preview, Approval, Execute, Verify, Rollback,
          Page Factory, Growth Intelligence, AI Workforce — NOT IMPLEMENTED in this MVP
          skeleton.
        </p>
      </div>

      {error && !me ? null : error ? <p className="error">{error}</p> : null}
    </div>
  );
}
