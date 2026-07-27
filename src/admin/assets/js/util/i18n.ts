/**
 * The WordPress i18n runtime, taken off `window` rather than imported from
 * `@wordpress/i18n`, so esbuild does not bundle a second copy of it. The global is
 * guaranteed by the 'wp-i18n' script dependency declared at every enqueue site.
 *
 * Declared here once and imported, never re-destructured per module: two
 * `const { __ } = window.wp.i18n` statements in one bundle collide, and esbuild renames
 * one of them to `__2` -- which `wp i18n make-pot` does not recognise as a translation
 * call, so those strings would silently drop out of the catalog.
 */
export const { __, _n, sprintf } = window.wp.i18n;
