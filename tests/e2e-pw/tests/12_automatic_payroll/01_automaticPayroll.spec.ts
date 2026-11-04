import { test, expect, Page } from '@playwright/test';

test.skip(process.env.PROJECT_PROGRESS_E2E !== '1', 'Requires isolated Malaysia payroll preview and independent reviewer.');
test.use({ channel: process.env.PLAYWRIGHT_CHANNEL });

async function login(page: Page, email: string) {
    await page.goto('/admin/v2/project-progress');
    await page.locator('input[type=email]').fill(email);
    await page.locator('input[type=password]').fill(process.env.PROJECT_PROGRESS_PASSWORD!);
    await Promise.all([page.waitForURL(url => !url.pathname.includes('/admin/login')), page.locator('input[type=password]').press('Enter')]);
    await page.goto('/admin/v2/project-progress');
    await expect(page.locator('#connectionStatus')).toContainText('Connected ·');
    await page.getByRole('button', { name: 'Employees & HR', exact: true }).click();
}

test('loads saved employee salary and allowances and calculates a period without manual pay fields', async ({ page }) => {
    test.setTimeout(360000);
    const suffix = Date.now().toString(), employee = 'Auto Payroll ' + suffix, profile = 'Auto profile ' + suffix;
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, process.env.PROJECT_PROGRESS_EMAIL!);
    await page.getByRole('button', { name: 'Create employee', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.locator('[name=name]').fill(employee);
    await dialog.getByRole('button', { name: 'Save employee', exact: true }).click();
    await expect(dialog).toBeHidden();
    await page.getByRole('textbox', { name: 'Search employee directory', exact: true }).fill(employee);
    await page.locator('#peopleWorkspace').getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('button', { name: 'View profile', exact: true }).click();
    await dialog.getByRole('button', { name: 'Salary & payroll profile', exact: true }).click();
    await dialog.getByRole('button', { name: 'New compensation version', exact: true }).click();
    await dialog.locator('[name=title]').fill(profile);
    await dialog.locator('[name=effective_from]').fill('2026-10-01');
    await dialog.locator('[name=effective_to]').fill('2026-12-31');
    await dialog.locator('[name=monthly_base]').fill('3100.00');
    await dialog.locator('[name=employment_start]').fill('2026-10-19');
    await dialog.locator('[name=date_of_birth]').fill('1990-03-02');
    for (const [key, value] of Object.entries({ citizenship: 'malaysian', epf_category: 'standard', socso_category: 'first', eis_prior_contribution: 'yes', skbbk_status: 'opted_out', tax_residency: 'resident', tax_scheme: 'standard', tax_category: '1', disabled_person: 'no', disabled_spouse: 'no' })) await dialog.locator('[name=' + key + ']').selectOption(value);
    await dialog.locator('[name=opening_month]').fill('9');
    await dialog.locator('[name=declaration]').fill('Synthetic no prior income / verified opt-out / ordinary wages');
    await dialog.getByRole('button', { name: 'Add allowance', exact: true }).click();
    await dialog.locator('[data-allowance] [data-label]').fill('Housing allowance');
    await dialog.locator('[data-allowance] [data-amount]').fill('310.00');
    await dialog.locator('[data-allowance] [data-classification]').fill('Ordinary cash housing allowance, all wage bases apply');
    await dialog.getByRole('button', { name: 'Save record', exact: true }).click();
    await expect(dialog.getByRole('button', { name: 'New compensation version', exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Close', exact: true }).first().click();

    await page.getByRole('button', { name: 'Payroll & payslips', exact: true }).click();
    await page.getByRole('button', { name: 'New payroll draft', exact: true }).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.locator('[name=base_pay]')).toHaveCount(0);
    await expect(dialog.locator('[data-value]')).toHaveCount(0);
    await dialog.locator('[name=employee_id]').selectOption({ label: employee });
    await dialog.locator('[name=period_from]').fill('2026-10-19');
    await dialog.locator('[name=period_to]').fill('2026-10-31');
    await dialog.locator('[name=pay_date]').fill('2026-10-31');
    await dialog.getByRole('button', { name: 'Calculate preview', exact: true }).click();
    await expect(dialog).toContainText('Eligible days: 13 / 31 calendar days');
    await expect(dialog).toContainText('Gross for period: 1430.00 MYR');
    await expect(dialog).toContainText('Housing allowance');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(dialog.getByRole('button', { name: 'Save calculated draft', exact: true })).toBeInViewport();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    if (process.env.AUTOMATIC_PAYROLL_SCREENSHOT) await page.screenshot({ path: process.env.AUTOMATIC_PAYROLL_SCREENSHOT });
    await dialog.getByRole('button', { name: 'Save calculated draft', exact: true }).click();
    await expect(dialog).toBeHidden();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.reload();
    await expect(page.locator('#connectionStatus')).toContainText('Connected ·');
    await page.getByRole('button', { name: 'Employees & HR', exact: true }).click();
    await page.getByRole('button', { name: 'Payroll & payslips', exact: true }).click();
    await page.locator('#peopleWorkspace tbody tr').filter({ hasText: employee }).getByRole('button', { name: 'View', exact: true }).click();
    await expect(dialog).toContainText('Gross for period: 1430.00 MYR');
    expect(errors).toEqual([]);

});
