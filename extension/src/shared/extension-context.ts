export class ExtensionContextError extends Error {
  constructor(message = 'The extension was updated. Please reload the page (F5).') {
    super(message);
    this.name = 'ExtensionContextError';
  }
}

export function isExtensionContextValid(): boolean {
  try {
    return typeof chrome.runtime?.id === 'string';
  } catch {
    return false;
  }
}

export function assertExtensionContext(): void {
  if (!isExtensionContextValid()) {
    throw new ExtensionContextError();
  }
}

function isContextInvalidatedError(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error);
  return message.includes('Extension context invalidated');
}

function rethrowIfInvalidated(error: unknown): never {
  if (isContextInvalidatedError(error)) {
    throw new ExtensionContextError();
  }
  throw error;
}

export async function storageLocalGet<T extends Record<string, unknown>>(
  keys: string | string[] | null
): Promise<T> {
  assertExtensionContext();
  try {
    return (await chrome.storage.local.get(keys)) as T;
  } catch (error) {
    rethrowIfInvalidated(error);
  }
}

export async function storageLocalSet(items: Record<string, unknown>): Promise<void> {
  assertExtensionContext();
  try {
    await chrome.storage.local.set(items);
  } catch (error) {
    rethrowIfInvalidated(error);
  }
}

export async function storageLocalRemove(keys: string | string[]): Promise<void> {
  assertExtensionContext();
  try {
    await chrome.storage.local.remove(keys);
  } catch (error) {
    rethrowIfInvalidated(error);
  }
}

export function runtimeGetURL(path: string): string {
  assertExtensionContext();
  try {
    return chrome.runtime.getURL(path);
  } catch (error) {
    rethrowIfInvalidated(error);
  }
}

export function runtimeSendMessage(message: unknown): void {
  if (!isExtensionContextValid()) return;
  try {
    chrome.runtime.sendMessage(message);
  } catch {
    // Extension reloaded — ignore
  }
}
