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
