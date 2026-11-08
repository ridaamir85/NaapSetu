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

Tailors select one or both men’s and women’s specializations to be discoverable in **Browse tailors**.

## Repeatable end-to-end demo

1. Sign in as the demo customer and open **My requests and quotations**. Accept the seeded quotation.
2. Sign in as the demo tailor, open **Manage orders**, record the fabric details, then move the order through `cutting`, `stitching`, `ready`, and `delivered`, one step at a time.
3. Sign in as the demo customer, open **My feedback and alterations**, submit a fit rating and an alteration request for the delivered order.
4. Sign in as the demo tailor and move the alteration from `open` to `in progress`, then to `done`.
5. Sign in as the demo customer and confirm the order status history is visible in **My orders**.

The seed script never marks the demo quotation as accepted or creates an order. After the customer accepts the quote, later runs preserve the resulting order and workflow status.

## Account settings and notifications

Customers, tailors and administrators can change their own password in **Change password** after signing in. The current password is required; new passwords are stored as hashes. Account recovery is not included in this version and is listed under **Future enhancements**.

For databases created before this version, apply migration 003 if the `users.must_change_password` field does not exist. Fresh installs from `schema.sql` already include that field. It remains supported so existing accounts that have a pending required password change can finish changing their password normally.

In-app activity notifications use migration `005_add_notification_reads.sql`. If this database already has the table, do not rerun that migration. Per-user read receipts track new requests, quotations, orders, feedback and alterations. Opening one update marks only that update as read. A removed password-recovery notification is no longer generated.

Existing installations that previously enabled password recovery can remove its obsolete table using:

```bash
sudo mysql naapsetu < database/migrations/007_remove_password_recovery.sql
```

That cleanup deletes only password-recovery requests and their read receipts. It does **not** delete users, passwords, orders, service data or other notification records. Make a database backup before any migration. No recovery table is created for new installations.

## Per-item unread markers in work pages
Each relevant customer, tailor, or admin work page displays **Unread updates** above its cards. Every item has its own View update action, using a CSRF-checked POST and a per-user row in notification_reads. The sidebar count drops one at a time; opening a page by itself does not clear notifications. Quotation and alteration cards also show individual New markers and Mark as read buttons. No additional migration is needed beyond 005. Updates appear on page reload (not via web push).

## Tailoring specializations and reference images (migration 006)
Existing Ubuntu installations: apply once after migration 005.

```bash
sudo mysql naapsetu < database/migrations/006_tailoring_specializations_and_request_details.sql
sudo install -d -o www-data -g www-data -m 0750 /var/www/naapsetu/storage/request-images
```

Private styling photos are stored in `storage/request-images/` **outside** the Apache `public/` document root and accessed through an ownership-checked PHP image endpoint. Do not expose the storage directory using an Apache alias. Ensure PHP's upload limits allow 5 MB files. New customers specify men/women specialization, freely describe garments, choose preferred fabric type/colour/notes and optionally upload JPG/PNG/WebP reference photos. Tailor supplies fabric by default and records fabric quantity/date before moving a new confirmed order to cutting. Existing services/orders are retained. Tailors must save their specializations before appearing in new searches.

## Future enhancements

Self-service forgot-password functionality (for example, a verified email reset link), improved delivery logistics, and further user-experience improvements can be planned after the required tailoring workflow is completed and tested.
