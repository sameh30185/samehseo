"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { client, Site } from "@/lib/api";

export default function SitesPage() {
  const [sites, setSites] = useState<Site[]>([]);
  const [error, setError] = useState("");

  useEffect(() => {
    client
      .sites()
      .then((r) => setSites(r.sites))
      .catch((e) => setError(e instanceof Error ? e.message : "error"));
  }, []);

  return (
    <div>
      <h1>المواقع</h1>
      <p>
        <Link href="/sites/new">+ إضافة موقع</Link>
      </p>
      {error && <p className="error">{error}</p>}
      <div className="card">
        {sites.length === 0 && !error ? (
          <p className="muted">لا مواقع.</p>
        ) : (
          <table>
            <thead>
              <tr>
                <th>الاسم</th>
                <th>URL</th>
                <th>الحالة</th>
              </tr>
            </thead>
            <tbody>
              {sites.map((s) => (
                <tr key={s.id}>
                  <td>{s.name}</td>
                  <td>
                    <a href={s.base_url} target="_blank" rel="noreferrer">
                      {s.base_url}
                    </a>
                  </td>
                  <td>
                    <span className="badge">{s.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <p>
        <span className="badge warn">NOT IMPLEMENTED</span> Pairing connector · Discover
        sync · Inventory
      </p>
    </div>
  );
}
