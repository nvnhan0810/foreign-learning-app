import type { Page } from '@inertiajs/core';
import { isPage, type PageLoader } from '../application/page-loader';

/** Adapt the Go navigation protocol to the Vue/Inertia presentation client. */
export class HttpPageLoader implements PageLoader {
  async load(url: string): Promise<Page> {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'text/html, application/xhtml+xml',
        'X-Inertia': 'true',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    if (response.status === 409) {
      const destination = response.headers.get('X-Inertia-Location');
      if (destination) {
        const target = new URL(destination, window.location.origin);
        if (target.origin !== window.location.origin) window.location.assign(target.href);
      }
      throw new Error('The session changed. Reload this page to continue.');
    }
    if (!response.ok) throw new Error(`Unable to load FLC (${response.status}).`);
    const page: unknown = await response.json();
    if (!isPage(page)) throw new Error('The server returned an invalid page.');
    // The remaining fields belong to the Inertia wire protocol, isolated in this adapter.
    return page as Page;
  }
}
