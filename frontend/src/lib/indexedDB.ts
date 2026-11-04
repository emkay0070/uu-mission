// Simple IndexedDB wrapper for saved phrases

const DB_NAME = 'uu-mission-db';
const DB_VERSION = 2;
const STORE_NAME = 'saved-phrases';

export interface SavedPhrase {
  id: string;
  sourceText: string;
  translatedText: string;
  sourceLanguage: string;
  targetLanguage: string;
  createdAt: Date;
  audioBlob?: Blob | null;
  audioMimeType?: string | null;
}

let db: IDBDatabase | null = null;

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    if (db) {
      resolve(db);
      return;
    }

    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      db = request.result;
      resolve(db);
    };

    request.onupgradeneeded = (event) => {
      const database = (event.target as IDBOpenDBRequest).result;
      const oldVersion = event.oldVersion as number;

      if (!database.objectStoreNames.contains(STORE_NAME)) {
        const store = database.createObjectStore(STORE_NAME, { keyPath: 'id' });
        store.createIndex('createdAt', 'createdAt');
      }
      // For version 2, we just add new fields to the interface
      // Existing records will have null values for audioBlob/audioMimeType
    };
  });
}

export async function savePhrase(phrase: Omit<SavedPhrase, 'id' | 'createdAt'>): Promise<SavedPhrase> {
  const database = await openDB();
  const id = crypto.randomUUID();
  const savedPhrase: SavedPhrase = {
    ...phrase,
    id,
    createdAt: new Date(),
  };

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.add(savedPhrase);

    request.onsuccess = () => resolve(savedPhrase);
    request.onerror = () => reject(request.error);
  });
}

export async function getSavedPhrases(): Promise<SavedPhrase[]> {
  const database = await openDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readonly');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.getAll();

    request.onsuccess = () => resolve(request.result || []);
    request.onerror = () => reject(request.error);
  });
}

export async function deletePhrase(id: string): Promise<void> {
  const database = await openDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.delete(id);

    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

export async function clearSavedPhrases(): Promise<void> {
  const database = await openDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.clear();

    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

export async function updatePhraseWithAudio(id: string, audioBlob: Blob, audioMimeType: string): Promise<void> {
  const database = await openDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const getRequest = store.get(id);

    getRequest.onsuccess = () => {
      const phrase = getRequest.result;
      if (phrase) {
        phrase.audioBlob = audioBlob;
        phrase.audioMimeType = audioMimeType;
        const putRequest = store.put(phrase);
        putRequest.onsuccess = () => resolve();
        putRequest.onerror = () => reject(putRequest.error);
      } else {
        reject(new Error('Phrase not found'));
      }
    };
    getRequest.onerror = () => reject(getRequest.error);
  });
}
