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

## Alerts, Customer 360 and site visits

- **Notifications**: the phone checks for news every 30 seconds while the app is open and about every 15 minutes in the background (no Google services or Firebase needed). You are alerted when work is assigned to you, when a new ticket or lead needs assigning (people who can assign), when leave is requested or decided, and for ONU alerts and FUP throttling (roles with those sections). Allow notifications when asked. Some phones need "battery: unrestricted" for the background checks.
- **Customer 360**: tap a customer (Customers tab), or the **Client** button on a ticket/lead. One screen with Call / WhatsApp / SMS, plan and expiry, wallet, linked ONU health, usage and FUP, tickets and payments. Local numbers (07...) are converted to +254 for WhatsApp.
- **Visit button** on every ticket, lead and installation: **I've arrived** and **Job finished** stamp your GPS position and time (and show how far you are from the customer's saved location), and **Take a photo** attaches a geotagged picture. Check-ins work offline and are sent later with their real time; photos need a connection. Photos are stored in `system/uploads/mobile/` on the server (closed to direct web access).

The server plugin needs `system/uploads` to be writable by the web server for photos.

## SLA and Store

- **SLA clocks** start when the ticket or installation is created: installation 3 days, support ticket 1 day, relocation 2 days (a ticket or job whose category, title or notes mention "relocation"). Past its SLA the card turns red with a "PAST SLA" label, moves to the top of the list, and the assignee (and managers who see everything) get one alert. Resolved/closed tickets and completed, cancelled, failed or awaiting-payment installations stop the clock. Change the hours in the PHPNuxBill settings table with `mobile_sla_install_h`, `mobile_sla_ticket_h`, `mobile_sla_relocation_h`. A "Relocation" support category is added automatically.
- **Store tab** (module "Store: stock, requests, usage"): uses the same tables as the website Store plugin (stock is the sum of tbl_store_moves; holder 0 is the warehouse). Everyone with the module can see stock, send a **New request** (kits fill quantities in one tap) and record **Used on a job** from what they hold (also added to the installation's item list). People with "Can assign work" also approve or reject requests (with an editable quantity, checked against warehouse stock) and receive new stock. New requests alert managers, decisions alert the technician. Requests and usage can be made offline.

## Installation payments

**Install pay** tab (module "Installation payments"): every installation with what is due, paid and owed, the account number the customer pays to (the job number), and its payment history. People with "Can assign work" can claim an M-Pesa payment by transaction code (the amount is taken from the payment your paybill received, and a code can only be used once; payments sent to the job number are suggested automatically), record cash, or skip payment with a required reason. Everything is written to the same tables as the website plugin and to the audit log, and the technician is alerted when the job is paid. Completing the installation and activating the package stay in the website Installations plugin, so the payment gate and router step are never bypassed.

## Reports

**Reports** tab (module "Reports: revenue, expiring, team"): pick Today, 7 days, This month or Last month to see revenue (with a per-day chart, payment methods and top plans), installation fees collected, new / active / lapsed customers, who expires in the next 7 days (with Call / WhatsApp / SMS and a ready renewal message), and team performance (installations done, tickets resolved, average time, items past SLA). **Export** buttons produce CSV files (revenue, expiring, installations, tickets) and open the phone's share sheet, so they go straight to Google Sheets, Excel, WhatsApp or email.

## Customer extras, ticket comments and security

**Customers (client side)**
- *Top up (M-Pesa)* on Home: sends an STK push to the paybill (account reference = username). The website's existing C2B confirmation credits the account; the app only shows the result. Needs `mpesa_consumer_key/secret/business_code/pass_key` in the PHPNuxBill settings.
- *Support* tab: raise a ticket, follow the conversation, reply. Tickets land in the normal Support list for staff.
- *Outage notices* appear as a banner on Home and arrive as a notification.
- *My Wi-Fi*: change Wi-Fi name and/or password through GenieACS (the customer must be linked to an ONU/router; one change per 5 minutes).

**Staff**
- *Notices* module (tick it on the Access screen): publish outage, warning or info notices, optionally auto-expiring.
- *Comments* button on tickets: conversation with the customer plus internal notes customers never see. Works offline (queued).

**Security**
- Settings (header): app lock with fingerprint, face or phone PIN, auto-lock delay, optional screenshot blocking.
- Settings also lists signed-in devices with remote sign-out. Admins can log a user out of every phone from Users (lost phone).
- Login is throttled after repeated wrong passwords (8 per username or 30 per IP in 15 minutes).

Re-upload `server/system/plugin/mobile_api.php`; new tables are created automatically.

## Loyalty points, referrals and manual activation

**Rewards (customers)**: *Rewards and referrals* on Home. Customers earn points when they **renew on time** (default: 10 points per KSh 100 paid, still counts up to 1 day after the plan ended), redeem points into their wallet (default 100 points = KSh 10, minimum 100), and share a personal referral code. A referral pays the referrer (default 200 points) and the new customer (default 100 points) when the new customer makes their first payment of at least KSh 50. A code can be applied before the first payment, or set by staff.
- Points are worked out from the website's own transactions table, so payments made on the website, by M-Pesa C2B or by staff all count. Only payments made after the feature is first used earn points. Installation fees, wallet top-ups and free manual activations never earn points, and each payment can only earn once.
- **Rewards tab** for staff (module "Loyalty points and referrals"): totals, the rules (SuperAdmin/Admin can change them or pause the programme), find a customer, add or remove points with a reason, set a customer's referrer.

**Manual activation (outage)**: **Activation** tab (module "Manual activation (outage)"). Choose one customer, or everyone whose plan ended in the last N hours; choose the days to add (1 to 30) and the speed (any enabled plan, shown with its speed, or keep each customer's own plan); a reason is required. You review the list, then confirm. Days are added from now or from the end of the current plan, whichever is later. It goes through the billing engine at zero price (so routers are updated and the transaction is recorded as "Manual Activation"), is listed in Recent activations and the audit log, and the customer is notified. Limit 500 customers per run.

Re-upload `server/system/plugin/mobile_api.php`, tick **Loyalty points and referrals** and **Manual activation (outage)** on the Access screen for the people who should see them.

## Fibre and splitter map

**Fibre map** tab (module "Fibre and splitter map"). It uses the points of the website's FTTH Mapping plugin (OLT, ODC, ODP, pole, router in `tbl_ftth_assets`, so both places show the same points) and adds which point feeds which, and by which cable (`tbl_mobile_net`), plus which splitter each ONU hangs on (`tbl_mobile_onu_feed`).
- **Map** (OpenStreetMap, no key needed): coloured dots for every point and ONU, lines for the cables, tap anything for details. Red = every ONU below is offline, amber = some offline or weak signal, green = fine, grey = unknown or empty.
- **Network**: the same thing as a tree (OLT > ODC > ODP) with offline counts per branch.
- **Faults**: worked out from live GenieACS status. When all ONUs below a point go offline while the point that feeds it still has working ONUs, the fault is shown as the cable feeding it or its splitter, with the affected customers. A single offline ONU on a healthy splitter is shown as a drop cable or ONU problem. Several weak signals on one branch point to a connector, bend or splice. Staff can also mark a point as a fault by hand.
- Tapping a point or an ONU shows the path from the OLT down, with the cable and port at each hop, and a plain-language verdict. Customer 360 shows "Fed by" for the customer's ONU.
- Add or edit points (with "Use my location"), say what feeds them (feeding point, cable, cores, length, splitter ratio, port), and place ONUs on splitters. People with "Can create" can edit; deleting is blocked while anything is fed from, or placed on, a point. Edits are in the audit log.
- Start by adding or fixing the feeder chain on a few OLTs, then place their ONUs: fault detection only knows about ONUs that are placed.
