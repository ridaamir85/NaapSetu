# NaapSetu

A personalized tailoring platform for custom stitching of customers’ unstitched fabric.

## Local setup

Requirements: PHP 8.1 or newer with PDO MySQL enabled, and MySQL 8.0 or newer.

1. From the repository root, create the local database configuration and edit it with your local MySQL username and password. `config/database.php` is ignored by Git.

   ```bash
   cp config/database.example.php config/database.php
   nano config/database.php
   ```

2. For a **new, empty database**, load the complete schema. It creates the `naapsetu` database and all tables.

   ```bash
   sudo mysql < database/schema.sql
   ```

   For an existing database created before account activation was added, apply the migration once, and only if `users.is_active` is not already present:

   ```bash
   sudo mysql naapsetu < database/migrations/001_add_user_active_status.sql
   ```

   For a database that already has the account activation migration, apply the new request details migration once so customer fabric choices and notes can be saved:

   ```bash
   sudo mysql naapsetu < database/migrations/002_add_request_fabric_and_notes.sql
   ```

3. Seed or refresh the local demo records. This command is safe to rerun: it updates the fixed demo accounts and base profiles, creates missing related rows, and keeps existing quotation/order progress without duplicating requests or quotations. It resets the three demo passwords and reactivates those demo accounts.

   ```bash
   php database/seed_demo.php
   ```

   Run the command a second time to check that it is repeatable before starting the walkthrough.

4. Start the local PHP server from the repository root:

   ```bash
   php -S 127.0.0.1:8000 -t public
   ```

   Open <http://127.0.0.1:8000>.

## Local demo accounts

These fixed credentials are for local demonstration only. Do not reuse them in a deployed environment.

| Role | Email | Password |
| --- | --- | --- |
| Admin | `demo.admin@naapsetu.test` | `DemoAdmin!2026` |
| Customer | `demo.customer@naapsetu.test` | `DemoCustomer!2026` |
| Tailor | `demo.tailor@naapsetu.test` | `DemoTailor!2026` |

The seed creates a Lahore tailor shop and a customer measurement and style profile with an initial `sent` quotation. It intentionally does **not** create an order: the customer must accept the quote first.

The current schema has no separate service publication flag. Services in `tailor_services` are listed to customers, so the seeded service is immediately visible in **Browse tailors**.

## Repeatable end-to-end demo

1. Sign in as the demo customer and open **My requests and quotations**. Accept the seeded quotation.
2. Sign in as the demo tailor, open **Manage orders**, record the fabric details, then move the order through `cutting`, `stitching`, `ready`, and `delivered`, one step at a time.
3. Sign in as the demo customer, open **My feedback and alterations**, submit a fit rating and an alteration request for the delivered order.
4. Sign in as the demo tailor and move the alteration from `open` to `in progress`, then to `done`.
5. Sign in as the demo customer and confirm the order status history is visible in **My orders**.

The seed script never marks the demo quotation as accepted or creates an order. After the customer accepts the quote, later runs preserve the resulting order and workflow status.

## Password recovery in the local project

The login page links to administrator-assisted recovery guidance. Email reset links are not implemented in this version. The project owner must verify the requester before resetting an account.

In the Ubuntu project terminal, run:

```bash
cd /var/www/naapsetu
php database/reset_password.php
```

Enter the registered email, then enter and confirm a new password. Password input is hidden. The command updates only that account's password hash; it preserves the role, activation status, profiles and orders. It does not create accounts, send email or terminate existing sessions. The tool requires an interactive Linux terminal and the local database configuration. Never place credentials or reset passwords in Git commits.

## Account security and administrator-assisted recovery

For existing Ubuntu installations, apply the password change migration **once** before pulling this feature into service:

```bash
sudo mysql naapsetu < database/migrations/003_add_password_change_requirement.sql
```

The initial schema already includes `users.must_change_password`; do not apply the migration to a fresh database created with the updated `schema.sql`.

Customer, tailor, and administrator accounts have a **Change password** navigation link. To recover a forgotten password, the project owner must verify the account owner **outside the website** before running:

```bash
php database/reset_password.php
```

The CLI tool generates a random temporary password, displays it once, and sets `must_change_password=1`. Share the temporary password privately with the verified user. After login the user must enter the temporary password and choose a different private password (12–72 characters) before accessing their dashboard. Never send passwords through a public channel or commit real credentials.

Automated reset emails are not implemented. Test the forced-change flow with a **non-demo test account**; `database/seed_demo.php` may overwrite the fixed demo accounts' passwords when rerun.

## Password recovery request form (migration 004)

For an existing Ubuntu database apply this migration **once** (after migration 003):

```bash
sudo mysql naapsetu < database/migrations/004_add_password_recovery_requests.sql
```

The public `/password-recovery.php` form accepts an email and displays a generic confirmation whether or not that email is registered. A registered active user gets at most one pending recovery request. Administrators see pending requests at `/admin/password-recovery.php`.

Before using `php database/reset_password.php`, an administrator must independently verify the requester's identity. The CLI tool creates a temporary password and forces a private change at login. The admin marks the request resolved only after completing the offline steps. The public form neither sends email nor automatically changes passwords. For a public deployment, add request-rate limits and abuse monitoring in front of this endpoint.

## In-app activity notifications (migration 005)

For an existing Ubuntu database, apply the next migration once after 004:

```bash
sudo mysql naapsetu < database/migrations/005_add_notification_reads.sql
```

The customer, tailor and admin sidebars show unread counts on **relevant** sections and an overall Notifications inbox. Events are derived from the existing owned business records (stitching requests, quotations, order history, feedback, alterations and admin recovery requests), and a per-user read receipt is recorded only when **View update** is clicked. This is an in-app activity system, not browser push notifications or email. No external service is required. For a production-scale site, add pagination and improve event timestamps for alterations.
