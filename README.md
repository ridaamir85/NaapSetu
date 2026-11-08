<div align="center">
  <h1><img src="public/assets/images/naapsetu-emblem.svg" alt="NaapSetu scissors emblem" width="56" height="56"> NaapSetu</h1>
  <p><strong>Custom stitching. Made simple.</strong></p>
  <p>A web-based platform for custom tailoring requests, digital measurements, quotations, and order tracking.</p>
  <p><strong>PHP · MySQL · HTML · CSS · JavaScript · Apache / LAMP</strong></p>
</div>

---

## About the project

**NaapSetu** connects customers with tailors through a structured custom-stitching workflow. Customers can maintain reusable measurement and style profiles, describe the garments they want stitched, share a reference image, review a tailor's quotation, and follow order progress. Tailors manage their specializations, incoming requests, fabric arrangements, orders, and alterations.

NaapSetu is **not a ready-made clothing store**. It focuses on made-to-measure orders, clear customer–tailor communication, and progress from quotation to delivery.

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

## How it works

1. **Choose a tailor** — Browse shops offering men's or women's tailoring.
2. **Share your requirements** — Describe the garment, choose measurements and add styling or fabric preferences.
3. **Review a quotation** — The tailor proposes a price and expected completion date; the customer decides whether to accept.
4. **Arrange fabric and stitch** — The tailor arranges fabric and records its details before cutting.
5. **Track the order** — Follow progress through to delivery.
6. **Give feedback** — Share fit feedback or request an alteration after delivery.

**Order milestones:** Confirmed → Fabric Arranged → Cutting → Stitching → Ready → Delivered

*Fabric Arranged* is a recorded milestone; the core order status advances from Confirmed to Cutting once fabric has been arranged. Customers can upload optional styling reference images, stored privately and available only to authorized users.

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

The schema creates the `naapsetu` database and the **15 core project tables**.

For existing installations, review the applicable one-time scripts in `database/migrations/`. The current schema already includes the latest tables and columns, so do not reapply those migrations to a fresh installation.

### 3. Reference image storage

Styling reference images are stored in a protected, writable `storage/request-images/` directory outside the public web root. Configure permissions for the PHP web-server user and allow uploads up to 5 MB. Do not expose this directory directly through Apache.

### 4. Run the application

For simple local development, from the repository root:

```bash
php -S 127.0.0.1:8000 -t public
```

Open **http://127.0.0.1:8000** in your browser.

For Apache, configure a virtual host whose `DocumentRoot` points to the repository's `public/` directory (for example, `http://naapsetu.local/`).

### 5. Optional local test data

An optional utility, `database/seed_demo.php`, is available to generate sample accounts and records for local development. It is **not required** to use NaapSetu. Running it may reset the passwords of its sample accounts; use it only in a disposable development environment.

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
