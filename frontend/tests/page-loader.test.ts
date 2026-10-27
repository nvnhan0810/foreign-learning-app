import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isPage } from '../src/application/page-loader.ts';
import { csrfHeaders } from '../src/infrastructure/csrf.ts';

test('rejects malformed bootstrap data before mounting a page', () => {
  for (const payload of [
    null,
    [],
    {},
    { component: 'Learn/Index', props: null, url: '/' },
    { component: 'Auth/Login', props: {}, url: '//evil.example' },
  ]) {
    assert.equal(isPage(payload), false);
  }
  assert.equal(isPage({ component: 'Learn/Index', props: {}, url: '/home/lookup' }), true);
});

test('forwards XSRF cookies with the correct header', () => {
  assert.deepEqual(csrfHeaders('session=abc; XSRF-TOKEN=csrf%3D%3D'), {
    'X-XSRF-TOKEN': 'csrf==',
  });
  assert.deepEqual(csrfHeaders('session=abc'), {});
  assert.deepEqual(csrfHeaders('XSRF-TOKEN=%broken'), {});
});
