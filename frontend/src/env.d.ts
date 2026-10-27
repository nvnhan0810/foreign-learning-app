/// <reference types="vite/client" />
import '@inertiajs/core';
import type { User } from './domain/models';
declare module '@inertiajs/core' {
  interface PageProps {
    auth: { user: User | null };
    flash: { success?: string | null; error?: string | null };
    isFlcApp: boolean;
    flcTheme?: string | null;
  }
}
