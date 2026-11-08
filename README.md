<div align="center">
  <img src="public/assets/images/naapsetu-emblem.svg" alt="NaapSetu scissors emblem" width="96" height="96">
  <h1>NaapSetu</h1>
  <p><strong>Custom stitching. Made simple.</strong></p>
  <p>A web-based platform for custom tailoring requests, digital measurements, quotations, and order tracking.</p>
  <p><strong>PHP · MySQL · HTML · CSS · JavaScript · Apache / LAMP</strong></p>
</div>

---

## About the project

**NaapSetu** connects customers with tailors through a structured custom-stitching workflow. Customers can maintain reusable measurement and style profiles, describe the garments they want stitched, share a reference image, review a tailor's quotation, and follow order progress. Tailors manage their specializations, incoming requests, fabric arrangements, orders, and alterations.

NaapSetu is **not a ready-made clothing store**. The platform focuses on made-to-measure stitching, clear customer–tailor communication, and managing the journey from quotation to delivery.

## Key features

| Customer | Tailor | Administrator |
| --- | --- | --- |
| Register, sign in, and manage account password | Maintain shop profile and capacity | View and manage users and tailors |
| Save measurement profiles and versioned values | Offer men's and/or women's tailoring | Review service and account information |
| Save styling preferences | Review requests and permitted measurements | Manage available administrative controls |
| Browse tailors and request stitching | See garment details and private reference images | |
| Specify fabric preferences and upload an optional styling reference | Quote a price and expected completion date | |
| Accept, decline, or request a quotation revision | Arrange fabric and retain an internal fabric record | |
| Follow order milestones and history | Update orders through the stitching stages | |
| Share/revoke measurement access | Respond to alteration requests | |
| Submit fit feedback and alterations | See actionable sidebar activity counts | |

### Tailoring workflow

```text
Customer selects a tailor
          ↓
Describes the garment, shares measurements and preferences
          ↓
Tailor reviews the request and sends a quotation
          ↓
Customer accepts the quotation → Order is created
          ↓
Tailor arranges and records the fabric
          ↓
Confirmed → Fabric Arranged → Cutting → Stitching → Ready → Delivered
          ↓
Customer can provide fit feedback or request an alteration
```

For new requests, the **tailor arranges the fabric**. The customer may specify a preferred type, colour, and additional notes; fabric quantity is an internal tailor record. Customers can optionally attach a JPG, PNG, or WebP styling-reference image (up to 5 MB). Access to uploaded references is checked by the application, and files are stored outside the public web directory.

The sidebar numbers represent **pending workflow actions**, not a separate unread-notification system.

## Technology stack

| Layer | Technology |
| --- | --- |
| Frontend | HTML5, CSS3, JavaScript |
| Backend | PHP 8.1+ |
| Database | MySQL 8.0+ with PDO |
| Web server | Apache (LAMP) or PHP's built-in development server |
| Development environment | Ubuntu Linux |
| Version control | Git and GitHub |

The interface uses the **Midnight & Brass** design language: navy, ivory, and brass/gold, with the Scissors Emblem as its logo and favicon.

## Project structure

```text
NaapSetu/
├── config/                 # Local database configuration example
├── database/
│   ├── schema.sql          # Complete schema for a fresh installation
│   ├── migrations/         # Updates for existing installations
│   └── seed_demo.php       # Optional local demonstration data
├── includes/               # Authentication, database and shared PHP views
├── public/                 # Apache document root
│   ├── admin/
│   ├── customer/
│   ├── tailor/
│   ├── assets/
│   │   ├── css/
│   │   ├── images/
│   │   └── js/
│   ├── favicon.svg
│   └── index.php
└── storage/request-images/ # Private uploads; create locally, outside public/
```

## Local installation

**Prerequisites:** PHP 8.1 or newer with PDO MySQL enabled, MySQL 8.0 or newer, and an Apache/PHP environment. Commands below assume Ubuntu.

### 1. Get the project and configure the database

```bash
git clone https://github.com/ridaamir85/NaapSetu.git
cd NaapSetu
cp config/database.example.php config/database.php
nano config/database.php
```

Update `config/database.php` with your local MySQL connection settings. It is excluded from version control; never commit credentials.

### 2. Initialize a fresh database

For a **new, empty installation**, run the complete schema:

```bash
sudo mysql < database/schema.sql
```

The schema creates the `naapsetu` database and its **15 core tables** for users, profiles, measurements, permissions, requests, quotations, orders, fabric, feedback, and alterations.

**Important:** The SQL migrations are for upgrading *older existing databases*. Do not run the historical migrations after importing the current fresh schema; they may attempt to add columns that already exist.

For older installations, inspect which migrations have already been applied before running any of these one-time updates:

| Migration | Purpose |
| --- | --- |
| `001_add_user_active_status.sql` | Account activation |
| `002_add_request_fabric_and_notes.sql` | Early fabric and request notes |
| `003_add_password_change_requirement.sql` | Password-change requirement |
| `006_tailoring_specializations_and_request_details.sql` | Tailoring specializations, garment descriptions, fabric preferences and image references |
| `007_remove_password_recovery.sql` | Remove discontinued password-recovery data |
| `008_remove_notification_reads.sql` | Remove obsolete notification-read data |

Migration numbers `004` and `005` are intentionally absent from the current repository because those discontinued features were removed.

### 3. Prepare private image storage

Create a writable directory **outside** the public document root. For an installation at `/var/www/naapsetu`:

```bash
sudo install -d -o www-data -g www-data -m 0750 /var/www/naapsetu/storage/request-images
```

Adjust the directory to match your actual repository location and the account running PHP. Ensure PHP upload settings permit 5 MB reference images. Do **not** expose `storage/` directly through Apache.

### 4. Run the application

For simple local development, from the repository root:

```bash
php -S 127.0.0.1:8000 -t public
```

Open **http://127.0.0.1:8000** in your browser.

For Apache, configure a virtual host whose `DocumentRoot` points to the repository's `public/` directory (for example, `http://naapsetu.local/`).

### 5. Optional demonstration data

```bash
php database/seed_demo.php
```

This creates or refreshes local demo users and sample records. The script can be rerun, but **resets the seeded account passwords** and reactivates those accounts; use it only in a development environment.

| Role | Email | Password |
| --- | --- | --- |
| Admin | `demo.admin@naapsetu.test` | `DemoAdmin!2026` |
| Customer | `demo.customer@naapsetu.test` | `DemoCustomer!2026` |
| Tailor | `demo.tailor@naapsetu.test` | `DemoTailor!2026` |

> These accounts are strictly for local testing. Never use demo passwords in a public deployment.

## Suggested demonstration

1. Sign in as a customer; browse tailors and view a saved measurement profile.
2. Create a stitching request with garment details, fabric preferences, and an optional reference image.
3. Sign in as the chosen tailor; review the request, authorized measurements, and image; send a quotation.
4. Return as the customer; accept the quotation to create an order.
5. As the tailor, confirm fabric arrangement and move the order through Cutting, Stitching, Ready, and Delivered.
6. As the customer, inspect the order timeline and submit fit feedback or an alteration request.
7. As the tailor, review and update the alteration.

## Design and usability

The customer, tailor, and administrator interfaces share a consistent **Midnight & Brass** theme. The UI emphasizes clear form labels, visible order milestones, recognizable workflow statuses, keyboard focus, and straightforward navigation.

## Scope and future enhancements

The current application covers the core academic project workflow: authentication, measurement management, tailor discovery, stitching requests, quotations, orders, fabric records, fit feedback, and alterations.

Possible future enhancements include verified email-based account recovery and delivery/logistics integrations. These are **not presented as implemented features**.

---

<div align="center">
  <img src="public/assets/images/naapsetu-emblem.svg" alt="" width="32" height="32">
  <br>
  <strong>NaapSetu</strong><br>
  <sub>Made-to-measure stitching, thoughtfully managed.</sub>
</div>
