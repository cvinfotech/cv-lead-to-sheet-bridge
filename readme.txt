=== CV Lead-to-Sheet Bridge ===
Contributors: cvinfotech
Tags: google sheets, webhook, contact form 7, wpforms, gravity forms
Requires at least: 5.0
Tested up to: 7.0.1
Requires PHP: 7.2
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress form leads straight into Google Sheets in real time. No Zapier, no manual field mapping.

== Description ==

CV Lead-to-Sheet Bridge is a lightweight bridge between your WordPress forms and Google Sheets. When a visitor submits a form, the plugin sends the entry to a Google Apps Script Web App you deploy yourself, which writes it into your sheet as a new row, matched to the right columns automatically.

**No Zapier required.** The receiving endpoint is a free Google Apps Script Web App, not a paid automation subscription. No per-task limits, no third-party service holding your lead data.

**Zero-mapping field detection ("Smart-Match").** Name your Google Sheet columns whatever you like, such as Name, Email, or Phone, and the receiving script normalizes and matches incoming form fields to those headers automatically. Capitalization, spacing, and punctuation are ignored, and Contact Form 7's your-* field naming convention is handled on both sides of the match, so a column named "Email" or "Your Email" both work.

**Security-hardened webhooks.** Every request carries a per-site security token, generated automatically and rotatable at any time from the settings screen. Requests without a valid token are rejected before a single row is written, so even if someone discovers your Web App URL, they can't write to your sheet without the token.

= Supported form plugins =

* Contact Form 7
* WPForms (Lite & Pro)
* Gravity Forms
* Elementor Pro Forms

= How it works =

1. A visitor submits a supported form.
2. The plugin normalizes the submitted fields to label/value pairs and sends them, plus the security token, to your configured webhook URL (non-blocking, so the visitor's experience is never delayed).
3. Your Google Apps Script Web App validates the token, matches each field to the closest sheet header using Smart-Match, and appends a new row.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/, or install the zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Settings > CV Lead-to-Sheet and follow the on-screen setup guide to create and deploy the paired Google Apps Script, then paste its Web App URL into the settings field.

Full step-by-step setup instructions with screenshots are shown directly on the plugin's settings screen, and in the GitHub README at https://github.com/cv-infotech/cv-lead-to-sheet-bridge

== Frequently Asked Questions ==

= Which forms are supported? =

Contact Form 7, WPForms (Lite & Pro), Gravity Forms, and Elementor Pro Forms.

= Does this cost anything beyond WordPress and Google Sheets? =

No. The receiving endpoint is a Google Apps Script Web App, which is free to deploy on any Google account.

= Do I need to manually map each form field to a sheet column? =

No. Smart-Match compares normalized field names against your sheet's row-1 headers and fills in whatever matches. Add a header for any field you want captured.

= What happens if a form field has no matching sheet header? =

It's simply not written. Nothing is lost from the submission on the WordPress side, it's only skipped when writing to the sheet.

= Is the webhook URL alone enough for someone to write to my sheet? =

No. Every request must include the security token generated on your settings screen. Requests without a valid token are rejected by the Apps Script before any row is written. You can rotate this token at any time if you suspect it's been exposed.

= Where is my data stored? =

Directly in your own Google Sheet, via a Google Apps Script Web App deployed under your own Google account. No lead data passes through any CV Infotech server or third-party service.

== Screenshots ==

1. A supported contact form on the front end of the site.
2. Settings dashboard: webhook URL, security token, and the generated Apps Script code, ready to copy.
3. Apps Script setup: the generated receiver script, pasted straight into the Sheet's script editor.
4. Deploying the script from the Apps Script editor's Deploy menu.
5. Web app deployment configuration, with access set to "Anyone" and secured by the embedded token.
6. Google Sheets output: real submissions landing in the right columns, Smart-Matched with zero manual mapping.

== Changelog ==

= 1.4.0 =
* Added: submissions are now routed to a Google Sheet tab named after the sending form. A matching tab is created automatically on first use, with headers copied from the first tab, so different forms no longer have to share one sheet.

= 1.3.0 =
* Added Form Source tracking to identify leads from different forms on the same site.

= 1.2.1 =
* Added: uninstall.php now removes the webhook URL and security token from the options table when the plugin is deleted (not just deactivated), including on every site of a multisite network.
* Added: index.php stubs in includes/, assets/, and assets/screenshots/ to prevent directory listing on misconfigured servers.
* Hardened: form-plugin hooks no longer assume the source plugin's classes/fields are fully loaded, avoiding a possible fatal error on unusual load orders.
* Fixed: Screenshots section descriptions now match the bundled screenshot images.
* Updated: tested up to WordPress 7.0.1.

= 1.2.0 =
* Fix: sheet header normalization now matches incoming field-key normalization, so a column named "Your Email" (copied directly from a Contact Form 7 label) matches correctly instead of only "Email" matching.
* Fix: settings-screen logo now loads from the plugin's own bundled asset instead of an external URL.
* Added: Elementor Pro Forms integration.
* Added: Gravity Forms integration, including composite fields (Name, Address) stitched from sub-inputs.
* Improved: file headers and documentation across the codebase.

= 1.1.0 =
* Added: Gravity Forms and Elementor Pro Forms support.

= 1.0.0 =
* Initial release. Contact Form 7 and WPForms support.

== Upgrade Notice ==

= 1.2.1 =
Adds proper uninstall cleanup and hardens the form-plugin hooks. Recommended for all users.

= 1.2.0 =
Fixes a field-matching edge case affecting Contact Form 7 users whose sheet headers are named like "Your Email" instead of "Email." Recommended for all users.
