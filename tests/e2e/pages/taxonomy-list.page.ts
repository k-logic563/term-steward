import { expect, type Page } from '@playwright/test';

export class TaxonomyListPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.goto('/wp-admin/tools.php?page=term-steward');
    await expect(this.page.getByRole('heading', { name: 'Term Steward', level: 1 })).toBeVisible();
  }

  async openCategories(): Promise<void> {
    await this.page.locator('.nav-tab-wrapper').getByRole('link', { name: /^Category/ }).click();
  }

  async openTags(): Promise<void> {
    await this.page.locator('.nav-tab-wrapper').getByRole('link', { name: /^Tag/ }).click();
  }

  async toggleSearch(): Promise<void> {
    await this.page.getByText('Search panel', { exact: true }).click();
  }

  async search(name: string): Promise<void> {
    await this.page.getByRole('searchbox', { name: 'Keyword' }).fill(name);
    await this.page.getByRole('button', { name: 'Apply conditions' }).click();
  }

  async resetSearch(): Promise<void> {
    await this.page.getByRole('link', { name: 'Reset conditions' }).click();
  }

  async selectTerm(name: string): Promise<void> {
    await this.page.getByRole('checkbox', { name: `Select ${name}`, exact: true }).check();
  }

  async toggleActions(): Promise<void> {
    await this.page.getByText('Action panel', { exact: true }).click();
  }

  async chooseAction(name: 'Rename' | 'Merge' | 'Delete'): Promise<void> {
    await this.page.getByRole('radio', { name }).check();
  }

  async enterName(name: string): Promise<void> {
    await this.page.getByLabel('New name').fill(name);
  }

  async chooseDestination(name: string): Promise<void> {
    await this.page.getByLabel('Merge destination').selectOption({ label: name });
  }

  async addToPlan(): Promise<void> {
    await this.page.getByRole('button', { name: 'Add to plan' }).click();
  }

  row(name: string) {
    return this.page.getByRole('row').filter({ hasText: name });
  }
}
