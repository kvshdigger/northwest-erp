import { test, expect } from '@playwright/test';

test.skip(process.env.PROJECT_PROGRESS_E2E !== '1', 'Requires isolated V2 finance preview.');
test.use({ channel: process.env.PLAYWRIGHT_CHANNEL });

test('keeps Finance creation and viewing inside V2 and labels document lines as Items', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/admin/v2/project-progress');
    await page.locator('input[type=email]').fill(process.env.PROJECT_PROGRESS_EMAIL!);
    await page.locator('input[type=password]').fill(process.env.PROJECT_PROGRESS_PASSWORD!);
    await Promise.all([page.waitForURL(url => !url.pathname.includes('/admin/login')), page.locator('input[type=password]').press('Enter')]);
    await page.goto('/admin/v2/project-progress');
    await page.getByRole('button', {name:'Finance',exact:true}).click();
    await page.getByRole('button', {name:'Create purchase order',exact:true}).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading',{name:'Items',exact:true})).toBeVisible();
    await expect(dialog.getByRole('button',{name:'Add item',exact:true})).toBeVisible();
    await expect(dialog.locator('[name=partner_id]')).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/v2\/project-progress$/);
    await expect(page.getByRole('link',{name:'Manage in ERP',exact:true})).toHaveCount(0);
    await page.setViewportSize({width:390,height:844});
    await expect(dialog.getByRole('button',{name:'Save draft',exact:true})).toBeInViewport();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await dialog.getByRole('button',{name:'Close',exact:true}).click();
    await page.setViewportSize({width:1280,height:900});
    await page.locator('#financeWorkspace [data-section=invoices]').click();
    await page.getByRole('button',{name:'Create customer invoice',exact:true}).click();
    await expect(dialog.locator('[name=journal_id]')).toBeVisible();
    await expect(dialog.locator('[name=account_id]')).toBeVisible();
    if (process.env.FINANCE_SCREENSHOT) await page.screenshot({path:process.env.FINANCE_SCREENSHOT,fullPage:true});
    expect(errors).toEqual([]);
});
