'use client';

import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';

/**
 * The return link at the top of a Site Operations detail page.
 *
 * Both record detail pages (team and attendance register) need the same
 * affordance, so it lives here rather than being copied into each page. It is
 * intentionally workspace-specific: the destination always points back into
 * Site Operations, not to a generic app-wide parent.
 */
export function SiteOpsBackLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      href={href}
      className="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200 transition-colors"
    >
      <ArrowLeft className="w-4 h-4" /> {label}
    </Link>
  );
}
