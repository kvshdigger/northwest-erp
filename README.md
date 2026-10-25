# Northwest ERP

Northwest ERP is a customized ERP workspace based on the open-source [Aureus ERP project](https://github.com/aureuserp/aureuserp). The goal is to bring project delivery, finance, HR, purchasing and sales workflows into one shared application for the Northwest team.

<p align="center">
  <strong>Projects · Finance · HR · Purchasing · Sales</strong>
</p>

## Current status

This repository is being prepared for a non-production pilot. The source has not yet been built, installed, or deployed in this environment. Module folders exist in the upstream-based code, but their workflows, access controls, and reporting still need testing before production use.

## Technology

- Laravel 13 and PHP 8.3+
- FilamentPHP 5 and Livewire 4
- MySQL 8+, MariaDB 10.6+, or PostgreSQL 16+
- Node.js 18+ and npm for front-end assets

## Local development

1. Clone this repository and enter the project directory:

   ```bash
   git clone https://github.com/kvshdigger/northwest-erp.git
   cd northwest-erp
   ```

2. Install PHP dependencies:

   ```bash
   composer install
   ```

3. Configure a local `.env` file. The `.env.example` defaults use the Northwest ERP name, Malaysia timezone and MYR currency. Set database credentials and generate a unique application key for each environment.

4. Install the ERP against a disposable development database:

   ```bash
   php artisan erp:install
   ```

5. Start the local development server:

   ```bash
   php artisan serve
   ```

Use the administrator credentials configured during the local installation. Never reuse development credentials in a shared or production environment.

## Planned pilot scope

- Projects: project records, milestones, status and progress visibility
- Finance: financial records and reporting, subject to workflow validation
- HR: employee records and leave-related workflows, subject to validation
- Purchasing: purchase requests and purchase orders, subject to validation
- Sales: customer and sales records, subject to validation

The existence of a module does not mean its end-to-end workflow is ready. Each module and its role permissions must be tested using sample data before real company data is imported.

## Deployment and security

- Use a separate test environment and database before production.
- Keep `APP_DEBUG=false` in production and use HTTPS.
- Use unique, strong administrator credentials; do not deploy with example/default passwords.
- Configure persistent database and file storage, automated backups, and a tested restore procedure.
- Review role permissions and confirm that users can only access the records intended for them.
- Do not commit `.env` files, passwords, API keys, or other secrets.
- The production Docker setup is still under review. Do not deploy it as-is until its image build, admin credential handling, database persistence, and recovery process have been hardened and tested.

## Branding and localization

The default app name is **Northwest ERP**. The sample environment uses `Asia/Kuala_Lumpur` and `MYR`. The Northwest ERP wordmark and favicon are included in `public/images/`.

## Upstream and license

This project is a separate customization. The original [Aureus ERP repository](https://github.com/aureuserp/aureuserp) has not been modified by this work. Preserve the upstream project's MIT license and required attribution notices in accordance with the repository's `LICENSE` file.
