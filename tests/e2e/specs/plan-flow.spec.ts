import { test, expect } from '../fixtures/taxonomy.fixture';
import type { Page } from '@playwright/test';
import { operationCount, readE2EState } from '../helpers/wordpress';
import { HistoryPage } from '../pages/history.page';
import { PlanBoardPage } from '../pages/plan-board.page';
import { PreviewModal } from '../pages/preview-modal.page';
import { TaxonomyListPage } from '../pages/taxonomy-list.page';

async function createRename(page: Page, newName: string) {
  const taxonomy = new TaxonomyListPage(page);
  await taxonomy.open();
  await taxonomy.selectTerm('E2E-CAT-RENAME');
  await taxonomy.toggleActions();
  await taxonomy.chooseAction('Rename');
  await taxonomy.enterName(newName);
  await taxonomy.addToPlan();
  return taxonomy;
}

test('E2E-004 / MT-017, MT-030, MT-033：名称変更計画とキャンセル', async ({ page }) => {
  await createRename(page, 'E2E-CAT-RENAMED');
  const board = new PlanBoardPage(page);
  const modal = new PreviewModal(page);
  await board.open();
  await board.expectDraft('E2E-CAT-RENAME', 'E2E-CAT-RENAMED');
  await board.preview();
  await modal.expectOpen('E2E-CAT-RENAME');
  await modal.cancel();

  expect(readE2EState().terms.cat_rename.name).toBe('E2E-CAT-RENAME');
  await page.locator('.nav-tab-wrapper').getByRole('link', { name: /^Category/ }).click();
  await page.reload();
  await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('E2E-005 / MT-037：名称変更の実行', async ({ page }) => {
  await createRename(page, 'E2E-CAT-RENAMED');
  const board = new PlanBoardPage(page);
  const modal = new PreviewModal(page);
  await board.open();
  await board.preview();
  await modal.expectOpen('E2E-CAT-RENAME');
  await modal.execute();

  const notice = page.locator('.term-steward-plan-notice');
  await expect(notice).toHaveCSS('display', 'flex');
  await expect(notice).toHaveCSS('align-items', 'center');
  await expect(notice).toHaveCSS('border-left-width', '4px');
  const singleLineLayout = await notice.evaluate((element) => {
    const paragraph = element.querySelector('p');
    if (!paragraph) throw new Error('The plan notice paragraph is missing.');
    const outer = element.getBoundingClientRect();
    const inner = paragraph.getBoundingClientRect();
    return {
      top: inner.top - outer.top,
      bottom: outer.bottom - inner.bottom,
      overflows: element.scrollWidth > element.clientWidth || paragraph.scrollWidth > paragraph.clientWidth,
    };
  });
  expect(Math.abs(singleLineLayout.top - singleLineLayout.bottom)).toBeLessThanOrEqual(1);
  expect(singleLineLayout.overflows).toBe(false);

  await page.setViewportSize({ width: 320, height: 720 });
  await notice.locator('p').evaluate((paragraph) => {
    paragraph.textContent = '操作が完了しました。長い日本語の通知でも文字が欠けたり重なったりせず、通知枠の内側で自然に複数行へ折り返されます。'.repeat(3);
  });
  const narrowLayout = await notice.evaluate((element) => {
    const paragraph = element.querySelector('p');
    if (!paragraph) throw new Error('The plan notice paragraph is missing.');
    const outer = element.getBoundingClientRect();
    const inner = paragraph.getBoundingClientRect();
    return {
      top: inner.top - outer.top,
      bottom: outer.bottom - inner.bottom,
      lines: inner.height / Number.parseFloat(getComputedStyle(paragraph).lineHeight),
      overflows: element.scrollWidth > element.clientWidth || paragraph.scrollWidth > paragraph.clientWidth,
    };
  });
  expect(narrowLayout.lines).toBeGreaterThan(1);
  expect(Math.abs(narrowLayout.top - narrowLayout.bottom)).toBeLessThanOrEqual(1);
  expect(narrowLayout.overflows).toBe(false);

  const state = readE2EState();
  expect(state.terms.cat_rename).toMatchObject({
    exists: true,
    name: 'E2E-CAT-RENAMED',
    slug: 'e2e-cat-rename',
    taxonomy: 'category',
  });
  expect(operationCount(state, 'completed')).toBe(1);
  expect(state.persistence.items).toBe(1);
  expect(state.persistence.journals).toBe(1);
  expect(state.persistence.duplicate_items).toBe(0);
  expect(state.persistence.duplicate_journals).toBe(0);

  const history = new HistoryPage(page);
  await history.open();
  await history.expectLatest('Rename');
});

test('E2E-006 / MT-019, MT-039, MT-043：タグ統合', async ({ page }) => {
  const taxonomy = new TaxonomyListPage(page);
  await taxonomy.open();
  await taxonomy.openTags();
  await taxonomy.selectTerm('E2E-TAG-MERGE-SOURCE');
  await taxonomy.toggleActions();
  await taxonomy.chooseAction('Merge');
  await taxonomy.chooseDestination('E2E-TAG-MERGE-TARGET');
  await taxonomy.addToPlan();

  const board = new PlanBoardPage(page);
  const modal = new PreviewModal(page);
  await board.open();
  await board.preview();
  await modal.expectOpen('E2E-TAG-MERGE-SOURCE');
  await modal.execute();

  const state = readE2EState();
  expect(state.posts.pub_01.relationships?.post_tag).toEqual([
    'E2E-TAG-MERGE-TARGET',
    'E2E-TAG-RENAME',
    'E2E-TAG-UNRELATED',
  ]);
  expect(state.posts.pub_02.relationships?.post_tag).toEqual([
    'E2E-TAG-MERGE-TARGET',
    'E2E-TAG-UNRELATED',
  ]);
  expect(state.posts.draft_01).toMatchObject({ status: 'draft' });
  expect(state.posts.draft_01.relationships?.post_tag).toEqual(['E2E-TAG-MERGE-SOURCE']);
  expect(state.terms.tag_merge_source.exists).toBe(true);
  expect(operationCount(state, 'completed')).toBe(1);
  expect(state.persistence.duplicate_items).toBe(0);
  expect(state.persistence.duplicate_journals).toBe(0);

  const history = new HistoryPage(page);
  await history.open();
  await history.expectLatest('Merge');
});

test('E2E-007 / MT-041：完全未使用termの削除', async ({ page }) => {
  const taxonomy = new TaxonomyListPage(page);
  await taxonomy.open();
  await taxonomy.openTags();
  await taxonomy.selectTerm('E2E-TAG-UNUSED');
  await taxonomy.toggleActions();
  await taxonomy.chooseAction('Delete');
  await taxonomy.addToPlan();

  const board = new PlanBoardPage(page);
  const modal = new PreviewModal(page);
  await board.open();
  await board.preview();
  await modal.expectOpen('E2E-TAG-UNUSED');
  await modal.execute();

  const state = readE2EState();
  expect(state.terms.tag_unused.exists).toBe(false);
  expect(state.terms.tag_unrelated).toMatchObject({ exists: true, name: 'E2E-TAG-UNRELATED' });
  expect(operationCount(state, 'completed')).toBe(1);
  expect(state.persistence.items).toBe(1);
  expect(state.persistence.duplicate_items).toBe(0);
  expect(state.persistence.duplicate_journals).toBe(0);

  const history = new HistoryPage(page);
  await history.open();
  await history.expectLatest('Delete');
});

test('E2E-008 / MT-055：Undo', async ({ page }) => {
  await createRename(page, 'E2E-CAT-RENAMED');
  const board = new PlanBoardPage(page);
  const modal = new PreviewModal(page);
  await board.open();
  await board.preview();
  await modal.execute();

  const history = new HistoryPage(page);
  await history.open();
  await history.openLatest();
  await history.undo();

  const state = readE2EState();
  expect(state.terms.cat_rename).toMatchObject({
    exists: true,
    name: 'E2E-CAT-RENAME',
    slug: 'e2e-cat-rename',
  });
  expect(state.posts.pub_01.relationships?.category).toContain('E2E-CAT-RENAME');
  expect(state.posts.pub_01.relationships?.category).toContain('E2E-CAT-UNRELATED');
  expect(operationCount(state, 'completed')).toBe(1);
  expect(operationCount(state, 'undone')).toBe(1);
  expect(state.persistence.duplicate_items).toBe(0);
  expect(state.persistence.duplicate_journals).toBe(0);

  await page.reload();
  await expect(page.getByRole('button', { name: 'Undo changes' })).toHaveCount(0);
});
