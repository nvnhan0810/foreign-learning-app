import { test, expect, type Page } from '@playwright/test';

const words = Array.from({ length: 12 }, (_, i) => ({
  id: i + 1,
  word: ['companion', 'curious', 'journey', 'discover', 'fluent', 'learn'][i % 6],
  phonetic: '/kəmˈpænjən/',
  meanings: [
    {
      part_of_speech: 'noun',
      definition: 'A person who shares your experiences and helps you learn something new.',
    },
  ],
  examples: [],
}));

async function mockPages(page: Page): Promise<void> {
  await page.route('**/*', async (route) => {
    const request = route.request();
    if (request.headers()['x-inertia'] !== 'true') return route.continue();
    const url = new URL(request.url());
    let component = 'Vocab/Index';
    let props: Record<string, unknown> = { items: words };
    if (url.pathname === '/home/lookup' || url.pathname === '/lookup') {
      component = 'Learn/Index';
      props = {
        result: null,
        word: '',
        saved: false,
        savedVocabularyId: null,
        draft: false,
        queryWord: '',
        lang: null,
        embed: url.pathname === '/lookup',
      };
    }
    if (url.pathname === '/home/puzzle/wordle') {
      component = 'Puzzle/Wordle';
      props = {
        puzzle: {
          vocabulary_id: 1,
          word_length: 5,
          max_guesses: 6,
          keyboard_letters: { a: 1, p: 2, l: 1, e: 1, r: 1, s: 1 },
        },
        guesses: [],
        startedAt: null,
      };
    }
    if (url.pathname === '/listening/9' || url.pathname === '/media/1/listening/start') {
      component = 'Listening/Show';
      props = {
        assessment: { id: 9, title: 'Listening practice', type: 'quiz' },
        questions: [
          { id: 10, prompt: 'Which word describes a companion?', options: ['Friend', 'Window'] },
        ],
        mediaItem: null,
        result: null,
      };
    }
    if (url.pathname === '/home/media/1') {
      component = 'Media/Show';
      props = {
        sessionOptions: [
          {
            type: 'quiz',
            title: 'Quick Quiz',
            question_count: 5,
            time_limit_minutes: 10,
            available: true,
            bank_count: 30,
            bank_status: 'ready',
          },
          {
            type: 'test',
            title: 'Listening Test',
            question_count: 10,
            time_limit_minutes: 20,
            available: true,
            bank_count: 30,
            bank_status: 'ready',
          },
          {
            type: 'exam',
            title: 'Listening Exam',
            question_count: 20,
            time_limit_minutes: 45,
            available: true,
            bank_count: 30,
            bank_status: 'ready',
          },
        ],
        item: {
          id: 1,
          title: 'A listening journey',
          difficulty: 'intermediate',
          type: 'audio',
          url: 'https://example.com/lesson.mp3',
          transcript: 'Learning a language is a journey.\n'.repeat(60),
          transcript_segments: [],
        },
      };
    }
    await route.fulfill({
      contentType: 'application/json',
      headers: { 'X-Inertia': 'true', Vary: 'X-Inertia' },
      body: JSON.stringify({
        component,
        props: {
          ...props,
          auth: { user: { id: 1, name: 'Learner', email: 'learner@example.com' } },
          flash: {},
          errors: {},
          isFlcApp: false,
        },
        url:
          url.pathname === '/media/1/listening/start' ? '/listening/9' : url.pathname + url.search,
        version: 'test',
        clearHistory: false,
        encryptHistory: false,
        rescuedProps: [],
        flash: {},
        rememberedState: {},
      }),
    });
  });
}

for (const width of [390, 768, 1024, 1440]) {
  test(`vocabulary layout and navigation at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    await mockPages(page);
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/home/vocab');
    await expect(page.locator('.vocab-card')).toHaveCount(12);
    const layout = await page.locator('[data-vocab-list]').evaluate((element) => ({
      columns: getComputedStyle(element).gridTemplateColumns.split(' ').length,
      overflow: document.documentElement.scrollWidth > window.innerWidth,
    }));
    expect(layout.overflow).toBe(false);
    if (width >= 768) expect(layout.columns).toBe(width >= 1280 ? 3 : 2);
    const nav = await page.locator('.user-nav').boundingBox();
    expect(nav).not.toBeNull();
    if (width >= 1024) {
      expect(nav!.x).toBe(0);
      expect(nav!.width).toBe(216);
    } else {
      expect(nav!.y + nav!.height).toBeCloseTo(900, 0);
    }
    await page.getByPlaceholder('Search saved words...').fill('curious');
    await expect(page.locator('.vocab-card')).toHaveCount(2);
    await page.getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(page.locator('.vocab-card')).toHaveCount(12);
    expect(errors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath(`vocabulary-${width}.png`), fullPage: true });
  });
  test(`media transcript remains scrollable at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    await mockPages(page);
    await page.goto('/home/media/1');
    await expect(page.getByRole('heading', { name: 'A listening journey' })).toBeVisible();
    await expect(page.locator('.transcript-panel')).toBeVisible();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    const transcript = await page.locator('.transcript-scroll-panel').boundingBox();
    const nav = await page.locator('.user-nav').boundingBox();
    expect(transcript).not.toBeNull();
    if (width < 1024) expect(transcript!.y + transcript!.height).toBeLessThanOrEqual(nav!.y + 1);
    await page.screenshot({ path: testInfo.outputPath(`media-${width}.png`), fullPage: true });
  });
}

test('mobile embed omits navigation and game keyboard supports input', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockPages(page);
  await page.goto('/lookup');
  await expect(page.locator('.lookup-embed')).toBeVisible();
  await expect(page.locator('.user-nav')).toHaveCount(0);
  await page.goto('/home/puzzle/wordle');
  await expect(page.locator('.wordle-board')).toBeVisible();
  await expect(page.locator('.user-nav')).toHaveCount(0);
  await page.keyboard.type('apple');
  await expect(page.locator('.wordle-tile').filter({ hasText: 'A' }).first()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
});

test('listening practice selects a session and opens the question flow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockPages(page);
  await page.goto('/home/media/1');
  await page.getByRole('button', { name: 'Practice', exact: true }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  const request = page.waitForRequest(
    (req) => req.url().includes('/media/1/listening/start') && req.method() === 'POST',
  );
  await page.getByRole('button', { name: /Quick Quiz · 5 questions/ }).click();
  expect((await request).postDataJSON()).toEqual({ type: 'quiz' });
  await expect(page.getByText('Which word describes a companion?')).toBeVisible();
  await expect(page.getByLabel('Friend')).toBeVisible();
});
