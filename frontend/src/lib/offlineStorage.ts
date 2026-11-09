/**
 * IndexedDB storage for offline PDF downloads
 * Stores actual file bytes locally for genuine offline reading
 */

const DB_NAME = 'UU-Mission-Offline';
const DB_VERSION = 1;
const STORE_NAME = 'downloaded-resources';

interface StoredDownload {
  resourceId: number;
  resourceVersionId: number;
  resourceTitle: string;
  fileData: ArrayBuffer;
  fileSize: number;
  mimeType: string;
  downloadedAt: string;
  lastAccessedAt: string;
}

class OfflineStorage {
  private db: IDBDatabase | null = null;

  async init(): Promise<void> {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        this.db = request.result;
        resolve();
      };

      request.onupgradeneeded = (event) => {
        const db = (event.target as IDBOpenDBRequest).result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          const store = db.createObjectStore(STORE_NAME, { keyPath: 'resourceId' });
          store.createIndex('resourceVersionId', 'resourceVersionId', { unique: false });
          store.createIndex('downloadedAt', 'downloadedAt', { unique: false });
        }
      };
    });
  }

  async saveDownload(
    resourceId: number,
    resourceVersionId: number,
    resourceTitle: string,
    fileData: ArrayBuffer,
    mimeType: string
  ): Promise<void> {
    if (!this.db) await this.init();

    const download: StoredDownload = {
      resourceId,
      resourceVersionId,
      resourceTitle,
      fileData,
      fileSize: fileData.byteLength,
      mimeType,
      downloadedAt: new Date().toISOString(),
      lastAccessedAt: new Date().toISOString(),
    };

    return new Promise((resolve, reject) => {
      const transaction = this.db!.transaction([STORE_NAME], 'readwrite');
      const store = transaction.objectStore(STORE_NAME);
      const request = store.put(download);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve();
    });
  }

  async getDownload(resourceId: number): Promise<StoredDownload | null> {
    if (!this.db) await this.init();

    return new Promise((resolve, reject) => {
      const transaction = this.db!.transaction([STORE_NAME], 'readonly');
      const store = transaction.objectStore(STORE_NAME);
      const request = store.get(resourceId);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result || null);
    });
  }

  async getAllDownloads(): Promise<StoredDownload[]> {
    if (!this.db) await this.init();

    return new Promise((resolve, reject) => {
      const transaction = this.db!.transaction([STORE_NAME], 'readonly');
      const store = transaction.objectStore(STORE_NAME);
      const request = store.getAll();

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result || []);
    });
  }

  async updateAccessTime(resourceId: number): Promise<void> {
    if (!this.db) await this.init();

    return new Promise((resolve, reject) => {
      const transaction = this.db!.transaction([STORE_NAME], 'readwrite');
      const store = transaction.objectStore(STORE_NAME);
      const getRequest = store.get(resourceId);

      getRequest.onerror = () => reject(getRequest.error);
      getRequest.onsuccess = () => {
        const data = getRequest.result;
        if (data) {
          data.lastAccessedAt = new Date().toISOString();
          const putRequest = store.put(data);
          putRequest.onerror = () => reject(putRequest.error);
          putRequest.onsuccess = () => resolve();
        } else {
          resolve();
        }
      };
    });
  }

  async deleteDownload(resourceId: number): Promise<void> {
    if (!this.db) await this.init();

    return new Promise((resolve, reject) => {
      const transaction = this.db!.transaction([STORE_NAME], 'readwrite');
      const store = transaction.objectStore(STORE_NAME);
      const request = store.delete(resourceId);

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve();
    });
  }

  async clearAll(): Promise<void> {
    if (!this.db) await this.init();

    return new Promise((resolve, reject) => {
      const transaction = this.db!.transaction([STORE_NAME], 'readwrite');
      const store = transaction.objectStore(STORE_NAME);
      const request = store.clear();

      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve();
    });
  }

  async isDownloaded(resourceId: number): Promise<boolean> {
    const download = await this.getDownload(resourceId);
    return download !== null;
  }

  async getStorageSize(): Promise<number> {
    const downloads = await this.getAllDownloads();
    return downloads.reduce((total, download) => total + download.fileSize, 0);
  }
}

export const offlineStorage = new OfflineStorage();
