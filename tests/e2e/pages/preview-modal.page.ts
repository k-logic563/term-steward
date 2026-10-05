import { expect, type Page } from '@playwright/test';

export class PreviewModal {
  constructor(private readonly page: Page) {}

  dialog() {
    return this.page.getByRole('dialog');
  }

  async expectOpen(target?: string): Promise<void> {
    await expect(this.dialog()).toBeVisible();
    await expect(this.dialog()).toContainText('Change preview');
    if (target) await expect(this.dialog()).toContainText(target);
  }

  async cancel(): Promise<void> {
    await this.dialog().getByRole('button', { name: 'Cancel' }).click();
    await expect(this.dialog()).toBeHidden();
  }

  async execute(): Promise<void> {
    await this.dialog().getByRole('button', { name: 'Execute', exact: true }).click();
    await expect(this.page.getByText('Processing completed.', { exact: true })).toBeVisible({ timeout: 60_000 });
  }
}
