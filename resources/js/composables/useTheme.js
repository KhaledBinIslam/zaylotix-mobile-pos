const STORAGE_KEY = 'zaylotix_theme';

/**
 * Light/dark theme toggle. Three states: 'light', 'dark', or no stored
 * value at all (follows the OS/browser preference — see the
 * `prefers-color-scheme` block in app.css). Setting an explicit value
 * writes a `data-theme` attribute on <html>, which app.css's
 * `:root[data-theme="dark"]` / `:not([data-theme="light"])` selectors key
 * off of. A tiny inline script in app.blade.php's <head> applies a stored
 * explicit choice before first paint (so reloading on dark never flashes
 * light first); this module is what the in-app toggle calls afterward.
 */
export function getTheme() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function setTheme(value) {
    const root = document.documentElement;

    if (value === 'light' || value === 'dark') {
        root.setAttribute('data-theme', value);
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // private/incognito — applies for this page view, just won't persist
        }
    } else {
        root.removeAttribute('data-theme');
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch {
            // see above
        }
    }
}

/** What's actually showing right now, resolving the "follow OS" case to a concrete value — for a toggle UI that needs to show which one is active. */
export function resolvedTheme() {
    const stored = getTheme();
    if (stored === 'light' || stored === 'dark') return stored;

    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function toggleTheme() {
    setTheme(resolvedTheme() === 'dark' ? 'light' : 'dark');
}
