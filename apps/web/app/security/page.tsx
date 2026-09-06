"use client";

import { useEffect, useState } from "react";
import { client } from "@/lib/api";

export default function SecurityPage() {
  const [enabled, setEnabled] = useState(false);
  const [reason, setReason] = useState("");
  const [draftReason, setDraftReason] = useState("");
  const [error, setError] = useState("");
  const [msg, setMsg] = useState("");

  async function load() {
    try {
      const k = await client.killSwitch();
      setEnabled(k.enabled);
      setReason(k.reason || "");
      setDraftReason(k.reason || "");
    } catch (e) {
      setError(e instanceof Error ? e.message : "error");
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function toggle(next: boolean) {
    setError("");
    setMsg("");
    try {
      const k = await client.setKillSwitch(next, draftReason || (next ? "manual halt" : "cleared"));
      setEnabled(k.enabled);
      setMsg(next ? "تم تفعيل مفتاح الإيقاف" : "تم إلغاء الإيقاف");
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "error");
    }
  }

  return (
    <div>
      <h1>الأمان — Kill Switch</h1>
      <div className="card">
        <p>
          الحالة العالمية:{" "}
          {enabled ? (
            <span className="badge danger">مفعّل (إيقاف)</span>
          ) : (
            <span className="badge ok">غير مفعّل</span>
          )}
        </p>
        {reason && <p className="muted">السبب الحالي: {reason}</p>}
        <div className="form-row">
          <label htmlFor="reason">سبب التغيير</label>
          <input
            id="reason"
            value={draftReason}
            onChange={(e) => setDraftReason(e.target.value)}
          />
        </div>
        <div style={{ display: "flex", gap: "0.5rem", flexWrap: "wrap" }}>
          <button className="danger" type="button" onClick={() => toggle(true)}>
            تفعيل الإيقاف
          </button>
          <button className="secondary" type="button" onClick={() => toggle(false)}>
            إلغاء الإيقاف
          </button>
        </div>
        {error && <p className="error">{error}</p>}
        {msg && <p className="muted">{msg}</p>}
        <p className="muted">يتطلب مالك المنصة (Platform Owner).</p>
      </div>
      <p>
        <span className="badge warn">NOT IMPLEMENTED</span> Org/site-scoped kill switches ·
        policy engine · capability registry
      </p>
    </div>
  );
}
