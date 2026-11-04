'use client';

import { WifiOff, RefreshCw } from 'lucide-react';

export default function OfflinePage() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-900 text-white p-4">
      <div className="text-center max-w-md">
        <div className="mx-auto w-16 h-16 bg-slate-800 rounded-full flex items-center justify-center mb-6">
          <WifiOff className="w-8 h-8 text-slate-400" />
        </div>
        <h1 className="text-2xl font-bold mb-2">You're Offline</h1>
        <p className="text-slate-400 mb-6">
          Check your internet connection to continue translating. Saved phrases are available offline.
        </p>
        <button
          onClick={() => window.location.reload()}
          className="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-medium transition-colors"
        >
          <RefreshCw className="w-4 h-4" />
          Retry
        </button>
      </div>
    </div>
  );
}
