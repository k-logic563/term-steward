import { expect, type Page } from '@playwright/test';

export class HistoryPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.getByRole('link', { name: /^Operation history/ }).click();
    await expect(this.page.getByRole('heading', { name: 'Operation history', level: 2 })).toBeVisible();
  }

  async expectLatest(action: string, status = 'Completed'): Promise<void> {
    const first = this.page.locator('.term-steward-history-table tbody tr').first();
    await expect(first).toContainText(action);
    await expect(first).toContainText(status);
  }

  async openLatest(): Promise<void> {
    await this.page.locator('.term-steward-history-table tbody tr').first().getByRole('link', { name: 'Details' }).click();
    await expect(this.page.getByRole('heading', { name: 'Operation details' })).toBeVisible();
  }

  async undo(): Promise<void> {
    await this.page.getByRole('button', { name: 'Undo changes' }).click();
    const dialog = this.page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Undo preview');
    await dialog.getByRole('button', { name: 'Undo' }).click();
    await expect(dialog).toContainText('Undo result', { timeout: 60_000 });
  }
}
