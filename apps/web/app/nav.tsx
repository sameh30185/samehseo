import Link from "next/link";

export function Nav() {
  return (
    <nav className="nav">
      <span className="brand">SAMEH 12.0</span>
      <Link href="/">مركز القيادة</Link>
      <Link href="/sites">المواقع</Link>
      <Link href="/sites/new">إضافة موقع</Link>
      <Link href="/security">الأمان</Link>
      <Link href="/login">تسجيل الدخول</Link>
    </nav>
  );
}
