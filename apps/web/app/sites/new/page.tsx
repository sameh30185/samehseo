"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { client } from "@/lib/api";

export default function AddSitePage() {
  const router = useRouter();
  const [name, setName] = useState("");
  const [baseUrl, setBaseUrl] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      await client.createSite(name, baseUrl);
      router.push("/sites");
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      <h1>إضافة موقع</h1>
      <form className="card" onSubmit={onSubmit} style={{ maxWidth: 480 }}>
        <div className="form-row">
          <label htmlFor="name">اسم الموقع</label>
          <input id="name" value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="form-row">
          <label htmlFor="url">Base URL</label>
          <input
            id="url"
            placeholder="https://example.com"
            value={baseUrl}
            onChange={(e) => setBaseUrl(e.target.value)}
            required
          />
        </div>
        {error && <p className="error">{error}</p>}
        <button type="submit" disabled={loading}>
          حفظ
        </button>
      </form>
    </div>
  );
}
