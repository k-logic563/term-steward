import { test, expect } from '../fixtures/taxonomy.fixture';
import { readE2EState } from '../helpers/wordpress';
import { TaxonomyListPage } from '../pages/taxonomy-list.page';

test('E2E-001 / MT-003：管理画面の基本表示', async ({ page }) => {
  const taxonomy = new TaxonomyListPage(page);
  await taxonomy.open();

  const tabs = page.locator('.nav-tab-wrapper');
  for (const tab of ['Category', 'Tag', 'Operation plan', 'Operation history']) {
    await expect(tabs.getByRole('link', { name: new RegExp(`^${tab}`) })).toBeVisible();
  }
  await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('E2E-002 / MT-007, MT-011：検索とリセット', async ({ page }) => {
  const taxonomy = new TaxonomyListPage(page);
  await taxonomy.open();
  const before = readE2EState();

  await taxonomy.toggleSearch();
  await taxonomy.search('E2E-CAT-RENAME');
  await expect(taxonomy.row('E2E-CAT-RENAME')).toBeVisible();
  await expect(page.locator('.term-steward-inventory-table tbody tr')).toHaveCount(1);

  await taxonomy.toggleSearch();
  await taxonomy.resetSearch();
  await expect(taxonomy.row('E2E-CAT-RENAME')).toBeVisible();
  await expect(taxonomy.row('E2E-CAT-UNRELATED')).toBeVisible();
  expect(readE2EState()).toEqual(before);
});

test('E2E-003 / MT-025：入力検証', async ({ page }) => {
  const taxonomy = new TaxonomyListPage(page);
  await taxonomy.open();
  await taxonomy.toggleActions();
  await taxonomy.addToPlan();
  await expect(page.getByRole('alert').filter({ hasText: 'Select a category or tag to process' })).toBeVisible();

  await taxonomy.selectTerm('E2E-CAT-RENAME');
  await taxonomy.chooseAction('Rename');
  await taxonomy.enterName('');
  await taxonomy.addToPlan();
  await expect(page.getByLabel('New name')).toHaveAttribute('aria-invalid', 'true');

  const state = readE2EState();
  expect(state.terms.cat_rename.name).toBe('E2E-CAT-RENAME');
  expect(state.persistence.items).toBe(0);
  expect(state.persistence.journals).toBe(0);
});
