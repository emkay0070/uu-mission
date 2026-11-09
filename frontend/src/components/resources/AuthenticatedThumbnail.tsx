'use client';

import { useEffect, useState } from 'react';
import { resourcesApi } from '@/lib/api';

/**
 * Renders a protected resource thumbnail.
 *
 * The thumbnail endpoint requires the bearer token, which an `<img src>` cannot
 * send, so the bytes are fetched through the API client and exposed as an
 * object URL. A missing or unauthorised thumbnail renders nothing, leaving the
 * caller's placeholder visible.
 */
export function AuthenticatedThumbnail({
  resourceId,
  alt,
  className,
}: {
  resourceId: number;
  alt: string;
  className?: string;
}) {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;

    setUrl(null);

    resourcesApi
      .thumbnailBlob(resourceId)
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => {
        // The caller shows its own placeholder when no thumbnail is available.
      });

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [resourceId]);

  if (!url) return null;

  return <img src={url} alt={alt} className={className} />;
}
