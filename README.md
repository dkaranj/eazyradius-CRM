# Eazyradius CRM

Native Android app (Kotlin + Jetpack Compose, no WebView) for PHPNuxBill with three sides in one app:

| Role | Who | Screens |
|------|-----|---------|
| Admin | `tbl_users` (Admin, SuperAdmin, Agent, Sales...) | Dashboard stat grid, customer search + recharge, payments, **Users** tab (SuperAdmin/Admin) |
| Technician | `tbl_users` whose `user_type` is in `mobile_api_tech_types` (default `Technician,Tech`), or any Admin using the "technician view" switch | Installation jobs grid, call / navigate, status updates |
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
