export type ThemeMode = 'light' | 'dark' | 'system';

export function resolveTheme(mode: ThemeMode): 'light' | 'dark' {
  if (mode === 'dark') return 'dark';
  if (mode === 'light') return 'light';
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function applyTheme(mode: ThemeMode, root: HTMLElement = document.documentElement): void {
  root.dataset.theme = resolveTheme(mode);
}

export function watchSystemTheme(onChange: () => void): void {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onChange);
}

export function bindThemeToggleButtons(
  getMode: () => ThemeMode,
  setMode: (mode: ThemeMode) => void | Promise<void>
): void {
  const syncActive = () => {
    const mode = getMode();
    document.querySelectorAll<HTMLButtonElement>('[data-theme-choice]').forEach((button) => {
      button.classList.toggle('active', button.dataset.themeChoice === mode);
    });
  };

  document.querySelectorAll<HTMLButtonElement>('[data-theme-choice]').forEach((button) => {
    button.addEventListener('click', () => {
      const choice = button.dataset.themeChoice as ThemeMode | undefined;
      if (!choice) return;
      void Promise.resolve(setMode(choice)).then(syncActive);
    });
  });

  syncActive();
  watchSystemTheme(() => {
    if (getMode() === 'system') syncActive();
  });
}
