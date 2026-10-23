# Deploy Fashion Industries to cPanel

This guide deploys the Laravel app to a subdomain such as `inventory.sapphirelinen.com`. In the examples, replace `inventory` with the subdomain you want. Your hosting provider must support PHP 8.3 or newer and Laravel's required PHP extensions.

## 1. Check hosting and prepare the domain

1. In cPanel, open **Domains** (or **Subdomains**, depending on the host) and create `inventory.sapphirelinen.com`.
2. Set its document root to the Laravel project's `public` directory. For example, if the project is stored at `/home/CPANEL_USER/apps/fashion-industries`, use `/home/CPANEL_USER/apps/fashion-industries/public` as the document root. Keep the project root outside `public_html` whenever cPanel permits it.
3. If the domain's DNS is managed elsewhere, point its DNS record to the hosting provider as instructed by that provider. Wait for DNS to resolve.
4. In **MultiPHP Manager** or the host's PHP selector, assign PHP 8.3 or newer to the subdomain. Enable at least `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, and `xml`. The host may enable some by default.
5. In **SSL/TLS Status**, issue/enable AutoSSL for the subdomain. Confirm `https://inventory.sapphirelinen.com` works before using it as the application URL.

If cPanel only permits a document root inside `public_html`, keep the application code in a private directory outside the web root and arrange for the subdomain document root to contain only the contents of Laravel's `public` directory. In that copy of `index.php`, update the two paths that load `vendor/autoload.php` and `bootstrap/app.php` so they point to the private project directory. Never place `.env`, `vendor`, `storage`, or the application source tree under a publicly accessible document root. Ask the hosting provider how to configure a document root outside `public_html` if this layout is unclear.

## 2. Create the production database

1. In cPanel **MySQL Database Wizard**, create a database and a dedicated database user with a long, unique password.
2. Grant that user **ALL PRIVILEGES** on this database. cPanel usually prefixes database and user names with the account name; copy the complete names exactly.
3. Keep the database name, username, password, and host handy. The host is often `localhost`, but use the value given by the provider.

The app's `.env.example` selects SQLite for local setup. Production should use the MySQL/MariaDB database you created above.

## 3. Upload the application

1. On your development machine, from the project directory, create the production frontend assets:

   ```sh
   npm ci
   npm run build
   ```

2. Upload the project files into a private directory such as `/home/CPANEL_USER/apps/fashion-industries`. Include `app`, `bootstrap`, `config`, `database`, `public` (including the generated `public/build` directory), `resources`, `routes`, `storage`, `artisan`, `composer.json`, and `composer.lock`.
3. Do not upload the local `.env`, `.git`, `node_modules`, or a local `vendor` directory. Do not overwrite production `.env` on later deployments.
4. In cPanel Terminal or SSH, change to the project root. Install production Composer dependencies:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   ```

   If Composer is unavailable on the server, build `vendor` with Composer on a compatible machine using the same PHP platform requirements, then upload it. Ask the host for its recommended Composer workflow; do not run the development dependency install on the production server.

## 4. Configure production environment

Create the `.env` file in the private project root (next to `artisan`) and set values similar to these. Replace all placeholders with private values; do not commit this file or paste secrets into support tickets.

```dotenv
APP_NAME="Fashion Industries"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://inventory.sapphirelinen.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANEL_PREFIX_database
DB_USERNAME=CPANEL_PREFIX_user
DB_PASSWORD="replace-with-database-password"

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=mail.sapphirelinen.com
MAIL_PORT=587
MAIL_USERNAME=your-mailbox@sapphirelinen.com
MAIL_PASSWORD="replace-with-mailbox-password"
MAIL_FROM_ADDRESS=your-mailbox@sapphirelinen.com
MAIL_FROM_NAME="${APP_NAME}"
```

Use the SMTP host, port, username, and encryption settings supplied by your email provider. If mail is not configured yet, leave the application's mail delivery disabled or set it to the host's documented option; the example `.env` uses `log`, which writes messages to logs instead of sending them.

Generate the application key once, after `.env` is in place:

```sh
php artisan key:generate --force
```

Keep the generated `APP_KEY` for the life of this installation. Replacing it can invalidate encrypted application data and sessions.

## 5. Set permissions and initialize the database

From the project root, make sure the PHP account can write to `storage` and `bootstrap/cache`. cPanel's PHP process generally runs as your account; use the host's recommended permissions and avoid `777` permissions.

Run the production migrations:

```sh
php artisan migrate --force
```

Create the initial admin and accountant users and the application's starter settings:

1. Before seeding, add strong, unique credentials to `.env`:

   ```dotenv
   ADMIN_EMAIL=your-admin@sapphirelinen.com
   ADMIN_PASSWORD="a-long-unique-password"
   ACCOUNTANT_EMAIL=your-accountant@sapphirelinen.com
   ACCOUNTANT_PASSWORD="another-long-unique-password"
   ```

2. Seed only this new production database:

   ```sh
   php artisan db:seed --force
   ```

The project seeder creates default roles, accounting data, settings, and these initial accounts. If either password is omitted, the seeder generates a random password and prints it once; supplying the credentials first avoids losing access to that output. Do not run `db:seed` casually on an established production database.

Create the public storage link for uploaded product images:

```sh
php artisan storage:link
```

If the host disallows symlinks, ask the provider for its supported public upload configuration before changing filesystem paths.

## 6. Optimize and check the site

Run these commands after environment and database configuration:

```sh
php artisan optimize
php artisan about
```

Visit `https://inventory.sapphirelinen.com` and sign in with the admin credentials configured above. Confirm the login page, dashboard, a printable invoice, and product image uploads. Check `storage/logs/laravel.log` and cPanel **Errors** if the page shows an error. Keep `APP_DEBUG=false` while troubleshooting production.

If changing `.env` after running `php artisan optimize`, refresh the cached configuration:

```sh
php artisan optimize:clear
php artisan optimize
```

## 7. Scheduled tasks and routine operations

This project's queue connection defaults to `database`. For work that dispatches queued jobs, configure a persistent queue worker using the hosting provider's supported process manager; shared cPanel hosting may not support long-running workers. If the app uses Laravel scheduled tasks, add one cPanel cron job to run every minute (replace paths and PHP binary with the host's actual values):

```cron
* * * * * /usr/local/bin/php /home/CPANEL_USER/apps/fashion-industries/artisan schedule:run >> /dev/null 2>&1
```

Ask the provider for the correct PHP CLI path if `/usr/local/bin/php` is not valid. Back up both the MySQL database and uploaded files in `storage/app/public` regularly, and test restoring a backup. Keep cPanel, PHP, dependencies, and the application patched.

## 8. Updating the deployed application

1. Back up the database and uploaded files.
2. Build fresh frontend assets with `npm ci && npm run build` and upload the changed application files. Preserve production `.env` and user-uploaded files.
3. From the project root, install/update production dependencies and migrate:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   php artisan migrate --force
   php artisan optimize
   ```

4. Visit the site and review Laravel/cPanel logs. Roll back using the backup if the release fails; database migrations may need a specific rollback plan.

## Deployment values for this project

- Framework and runtime: Laravel 13; PHP `^8.3` (PHP 8.3 or newer within the supported range).
- Production database: MySQL or compatible MariaDB through PDO MySQL.
- Frontend assets: Vite; the deployable assets are generated into `public/build` by `npm run build`.
- Writable paths: `storage` and `bootstrap/cache`.
- Public uploads: product images use Laravel's public storage disk, so `php artisan storage:link` is required.
- The README covers local setup. Use this guide for production-specific server, database, mail, and deployment configuration.
