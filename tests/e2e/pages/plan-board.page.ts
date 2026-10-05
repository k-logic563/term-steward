import { expect, type Page } from '@playwright/test';

export class PlanBoardPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.getByRole('link', { name: /^Operation plan/ }).click();
    await expect(this.page.getByRole('heading', { name: 'Operation plan', level: 2 })).toBeVisible();
  }

  async expectDraft(target: string, change?: string): Promise<void> {
    const row = this.page.getByRole('row').filter({ hasText: target });
    await expect(row).toBeVisible();
    if (change) await expect(row).toContainText(change);
  }

  async preview(): Promise<void> {
    await this.page.getByRole('button', { name: 'Review changes' }).click();
  }

  async remove(target: string): Promise<void> {
    await this.page.getByRole('button', { name: new RegExp(`Remove .*${target}.* from the operation plan`) }).click();
  }
}
