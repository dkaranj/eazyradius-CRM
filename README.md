# Eazyradius CRM

Native Android app (Kotlin + Jetpack Compose, no WebView) for PHPNuxBill with three sides in one app:

| Role | Who | Screens |
|------|-----|---------|
| Admin | `tbl_users` (Admin, SuperAdmin, Agent, Sales...) | Dashboard (money + open leads, tickets, installs, unassigned work), customer search + recharge, **Work** tab (leads, support, installs, assign to technicians), payments, **Users** tab (SuperAdmin/Admin) |
| Technician | `tbl_users` whose `user_type` is in `mobile_api_tech_types` (default `Technician,Tech`), or any Admin using the "technician view" switch | Home (my open leads / tickets / installs) and Work tab with only the items assigned to them |
| Client | `tbl_customers` | Wallet + active plan, plans grid (buy with wallet), payment history |

The role is detected automatically at login.

## 1. Install the server plugin

Copy `server/system/plugin/mobile_api.php` to `system/plugin/mobile_api.php` on your PHPNuxBill panel.
It creates its own `tbl_mobile_tokens` table on first request. Test it:

    https://YOUR-PANEL/?_route=plugin/mobile_api&a=ping   ->  {"ok":true,"version":"1.0"}

Optional settings (add rows to `tbl_appconfig`, setting / value):

- `mobile_api_tech_types`    e.g. `Technician,Tech` (the first one is the role the app assigns)
- `mobile_api_tech_statuses` statuses a technician may set from the app (default `pending,in_progress`)

Completing an installation is deliberately NOT possible from the app, so the Installations
plugin's M-Pesa payment gate cannot be bypassed.

## 2. Build the APK on GitHub

1. Create a GitHub repo and push this folder to `main`.
2. Open the **Actions** tab -> **Build APK** runs automatically (or press *Run workflow*).
3. Download `EazyradiusCRM-apk` from the run's Artifacts, unzip, install `EazyradiusCRM.apk`.
4. Tag a release (`git tag v1.0.0 && git push --tags`) to get the APK attached to a GitHub Release.

## 3. Use it

Open the app, enter your panel address (e.g. `billing.example.com`), username and password.

## Staff management (Users tab)

- **SuperAdmin** can add users and assign any role (SuperAdmin, Admin, Agent, Sales, Report, Technician), reset passwords, enable/disable and delete accounts.
- **Admin** can do the same for non-admin roles only.
- Nobody can change their own role/status or delete themselves, and the last active SuperAdmin cannot be removed or demoted.
- Changing a user's role, status or password signs them out of the app so the change takes effect immediately.

## Work: leads, support tickets, installations

The Work tab (admin) and Home/Work tabs (technician) cover three lists. Admins see everything, can
filter by status or technician, add leads/tickets, and **assign** any item to a technician. Technicians
only see items assigned to them and can call, navigate and change status.

The plugin auto-detects your existing tables (`tbl_leads`, `tbl_tickets`, `tbl_installations`, ...) and
their column names. If a leads/tickets table does not exist it creates `tbl_mobile_leads` /
`tbl_mobile_tickets`. Override detection with `tbl_appconfig` rows:

- `mobile_api_table_lead`, `mobile_api_table_ticket`, `mobile_api_table_install`  (table names)
- `mobile_api_statuses_lead`, `mobile_api_statuses_ticket`  (comma separated, first = default for new items)

Installations cannot be created or completed from the app, so the Installations plugin's M-Pesa payment gate stays in charge.
Assignment needs an assignee column (`assigned_to`, `technician_id`, ...); if a table has none the app says so.

## Theme

Everyone (admin, technician, client, and even the login screen) has a **Theme** button. It opens a screen with a live preview where you can:

- pick **Follow phone / Light / Dark**;
- pick a preset (Teal, Ocean, Forest, Sunset, Royal, Rose, Slate, Gold);
- change the **accent, header, header text, footer, footer text, screen background, grid cards and text** colours with a colour picker (sliders, hex code or quick swatches), or leave any of them on **Auto**;
- set **corner roundness** and **text size**.

Anything left on Auto is worked out from the other choices (for example text and cards follow the background), and a warning appears if a combination is hard to read. The choice is saved on the phone, so it survives restarts and logouts and is personal to each device.

## Network, usage, audit and offline

- **Support tickets**: when creating a ticket you now search and pick the client (name, username or phone), choose a category and Low/Medium/High priority. The ticket is saved in the same format the website uses.
- **ONUs tab** (module "ONU monitoring"): live online/offline and signal from GenieACS (address and login are read from the TR-069 plugin settings), filter by offline or weak signal, open an ONU for history, alerts and a Reboot button. If GenieACS cannot be reached it shows the last readings the website recorded.
- **Usage tab** (module "Data usage and FUP"): today and month totals, top users, who is throttled, FUP rules, and a per-customer view. Clients get their own **Usage** tab with their FUP progress and a 14-day chart.
- **Audit tab** (module "Audit log"): who did what and when (logins, failed logins, tickets, status changes, recharges, user and access changes, clocking, leave, ONU reboots, refused actions), plus the website's HR audit entries. Turn it on per role on the Access screen.
- **Offline mode**: every screen keeps its last data and shows it with a banner when there is no connection. New tickets and leads, status changes, clock in/out and leave requests are saved on the phone and sent automatically when it is back online (each is applied once, even if the connection drops mid-send). Clocking offline records the time you actually pressed the button. Anything the server refuses later is listed under Review.

Re-upload `server/system/plugin/mobile_api.php` after updating; the two new tables are created automatically.
