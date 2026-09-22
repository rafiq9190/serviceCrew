// @ts-check
const { defineConfig } = require( '@playwright/test' );

/**
 * Playwright config for ServiceCrew e2e coverage.
 *
 * No specs exist yet — this repo has no UI/REST surface as of Phase 1a
 * (DB schema + activator/deactivator only). The first real spec lands
 * with the first task that ships a UI or REST endpoint; see
 * tests/e2e/README.md.
 */
module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: 'list',
	use: {
		baseURL: 'http://my-plugin-local.test',
		trace: 'on-first-retry',
	},
} );
